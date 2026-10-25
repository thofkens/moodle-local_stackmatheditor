<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_stackmatheditor;

/**
 * Shared helper for quiz/question DB lookups.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_helper {
    /** @var array Per-request cache for attempt slot data. */
    private static array $attemptcache = [];

    /** @var array Per-request cache for quiz question data. */
    private static array $quizcache = [];

    /**
     * Write a developer trace message to the PHP error log.
     *
     * Emitted only when Moodle runs with DEBUG_DEVELOPER; silent on production sites.
     * error_log() keeps the message out of the browser and away from page rendering.
     *
     * @param string $msg Message to log.
     * @return void
     */
    public static function dbg(string $msg): void {
        if (!debugging('', DEBUG_DEVELOPER)) {
            return;
        }
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('[SME-HOOK] ' . $msg);
    }

    /**
     * Return the course module ID for the current page.
     *
     * Reads from $PAGE->cm if available, then falls back to the
     * cmid or id URL parameter.
     *
     * @return int Course module ID, or 0 if not determinable.
     */
    public static function get_cmid(): int {
        global $PAGE;
        if ($PAGE->cm) {
            return (int) $PAGE->cm->id;
        }
        $cmid = optional_param('cmid', 0, PARAM_INT);
        if (!$cmid) {
            $cmid = optional_param('id', 0, PARAM_INT);
        }
        return $cmid;
    }

    /**
     * Return the quiz instance ID for a given course module ID.
     *
     * @param int $cmid Course module ID.
     * @return int Quiz instance ID, or 0 if not found.
     */
    public static function get_quiz_instance_id(int $cmid): int {
        global $PAGE;
        if ($PAGE->cm && (int) $PAGE->cm->id === $cmid) {
            return (int) $PAGE->cm->instance;
        }
        $cm = get_coursemodule_from_id('quiz', $cmid);
        return $cm ? (int) $cm->instance : 0;
    }

    /**
     * Check whether the quiz_slots table has a questionbankentryid column.
     *
     * The column was added in Moodle 4.x. Result is cached per request.
     *
     * @return bool True if the column exists.
     */
    private static function slots_have_qbeid(): bool {
        global $DB;
        static $result = null;
        if ($result !== null) {
            return $result;
        }
        try {
            $cols   = $DB->get_columns('quiz_slots');
            $result = isset($cols['questionbankentryid']);
        } catch (\moodle_exception $e) {
            // The table cannot be read: answer as if the column were not there.
            self::caught($e, 'slots_have_qbeid');
            $result = false;
        }
        return $result;
    }

    /**
     * Load all STACK question records for a quiz instance.
     *
     * Returns an array of associative arrays with keys:
     *   questionid, qbeid, name, slot.
     * Result is cached per request.
     *
     * @param int $quizinstanceid Quiz instance ID (not cmid).
     * @return array List of STACK question data.
     */
    public static function load_quiz_stack_questions(int $quizinstanceid): array {
        if (isset(self::$quizcache[$quizinstanceid])) {
            self::dbg('load_quiz_stack_questions: cache hit quiz=' . $quizinstanceid);
            return self::$quizcache[$quizinstanceid];
        }

        $data = [];
        try {
            $data = self::slots_have_qbeid()
                ? self::load_questions_direct($quizinstanceid)
                : self::load_questions_via_refs($quizinstanceid);
        } catch (\moodle_exception $e) {
            // Unreadable slots or a vanished question: the quiz has no editor-relevant questions.
            self::caught($e, 'load_quiz_stack_questions');
        }

        self::dbg('load_quiz_stack_questions: ' . count($data) . ' STACK questions');
        self::$quizcache[$quizinstanceid] = $data;
        return $data;
    }

    /**
     * Every question bank entry used by a quiz, regardless of question type.
     *
     * The external service needs to know which questions belong to a quiz before it answers
     * anything about them. Unlike load_quiz_stack_questions() this does not filter by question
     * type: the question is "does this quiz use it", not "is it a STACK question".
     *
     * @param int $quizinstanceid Quiz instance id.
     * @return array Question bank entry ids, as a set (qbeid => true).
     */
    public static function load_quiz_qbeids(int $quizinstanceid): array {
        global $DB;

        $qbeids = [];

        try {
            if (self::slots_have_qbeid()) {
                $rows = $DB->get_records(
                    'quiz_slots',
                    ['quizid' => $quizinstanceid],
                    '',
                    'id, questionbankentryid'
                );
                foreach ($rows as $row) {
                    $qbeid = (int) ($row->questionbankentryid ?? 0);
                    if ($qbeid) {
                        $qbeids[$qbeid] = true;
                    }
                }
            } else {
                $sql = "SELECT qr.id, qr.questionbankentryid AS qbeid
                          FROM {quiz_slots} qs
                          JOIN {question_references} qr
                               ON qr.itemid = qs.id
                               AND qr.component = 'mod_quiz'
                               AND qr.questionarea = 'slot'
                         WHERE qs.quizid = :quizid";
                foreach ($DB->get_records_sql($sql, ['quizid' => $quizinstanceid]) as $row) {
                    $qbeid = (int) $row->qbeid;
                    if ($qbeid) {
                        $qbeids[$qbeid] = true;
                    }
                }
            }
        } catch (\moodle_exception $e) {
            // A quiz whose slots cannot be read scopes to nothing, never to everything.
            self::caught($e, 'load_quiz_qbeids');
            return [];
        }

        return $qbeids;
    }

    /**
     * Does a quiz use a question bank entry?
     *
     * The configuration page takes the entry from the request. This check is the IDOR protection
     * of that page: without it a user who may manage one quiz could open the configuration - and
     * with it a rendered preview and the input semantics - of any STACK question on the site.
     *
     * @param int $quizinstanceid Quiz instance id.
     * @param int $qbeid Question bank entry id.
     * @return bool True only if one of the quiz's slots refers to the entry.
     */
    public static function quiz_uses_entry(int $quizinstanceid, int $qbeid): bool {
        if ($quizinstanceid <= 0 || $qbeid <= 0) {
            return false;
        }

        return isset(self::load_quiz_qbeids($quizinstanceid)[$qbeid]);
    }

    /**
     * The STACK question a quiz-level user may configure, or an exception.
     *
     * The configuration page takes the question from the request. Three things have to hold
     * before it may show, evaluate or save anything about it:
     *
     *  1. the question bank entry is one the quiz uses - an entry of another quiz, another course
     *     or no quiz at all gets the same answer as one that does not exist, so the page can be
     *     used neither to view nor to probe questions elsewhere;
     *  2. it is a STACK question;
     *  3. the user may view the question in its own question bank context - managing the quiz is
     *     not the same as being allowed to see every question it uses.
     *
     * @param int $quizinstanceid Quiz instance id (from the course module the page was opened for).
     * @param int $qbeid Question bank entry id from the request, 0 if none.
     * @param int $questionid Question id from the request, 0 if none.
     * @return \stdClass The question record (id, name, qtype, version) with its qbeid.
     * @throws \moodle_exception If any of the three does not hold.
     */
    public static function require_configurable_question(int $quizinstanceid, int $qbeid, int $questionid): \stdClass {
        global $CFG, $DB;

        if ($qbeid <= 0 && $questionid > 0) {
            $qbeid = (int) config_manager::resolve_qbeid($questionid);
        }
        if ($qbeid <= 0 || !self::quiz_uses_entry($quizinstanceid, $qbeid)) {
            throw new \moodle_exception('cannotresolveqbeid', 'local_stackmatheditor');
        }

        $sql = "SELECT q.id, q.name, q.qtype, qv.version
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                 WHERE qv.questionbankentryid = :qbeid
              ORDER BY qv.version DESC";
        $versions = $DB->get_records_sql($sql, ['qbeid' => $qbeid], 0, 1);
        $question = $versions ? reset($versions) : null;
        if (!$question) {
            throw new \moodle_exception('cannotresolveqbeid', 'local_stackmatheditor');
        }
        if ($question->qtype !== 'stack') {
            throw new \moodle_exception('notstackquestion', 'local_stackmatheditor');
        }

        require_once($CFG->libdir . '/questionlib.php');
        question_require_capability_on((int) $question->id, 'view');

        $question->qbeid = $qbeid;
        return $question;
    }

    /**
     * Does an adaptive quiz draw from a question category that holds a STACK question?
     *
     * mod_adaptivequiz has no slots: an instance names question categories in
     * {adaptivequiz_question}, and the questions are whatever those categories contain. The
     * settings navigation offers the configuration link only when one of them is a STACK
     * question; the caller wraps this in a fail-soft boundary, so a defect here only hides the
     * link and is reported through caught().
     *
     * @param int $instanceid Adaptive quiz instance id.
     * @return bool True if at least one question in the instance's categories is a STACK question.
     */
    public static function adaptivequiz_has_stack_questions(int $instanceid): bool {
        global $DB;

        // Without mod_adaptivequiz there is nothing to look at.
        if ($instanceid <= 0 || !$DB->get_manager()->table_exists('adaptivequiz_question')) {
            return false;
        }

        $sql = "SELECT 1
                  FROM {adaptivequiz_question} aq
                  JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = aq.questioncategory
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE aq.instance = :instance
                   AND q.qtype = :qtype";

        return $DB->record_exists_sql($sql, ['instance' => $instanceid, 'qtype' => 'stack']);
    }

    /**
     * Report something a fail-soft catch caught.
     *
     * The plugin catches at two levels (docs/ERROR-HANDLING.md): lookups catch the exceptions
     * Moodle raises for missing or unreadable data (\moodle_exception), and the page hooks catch
     * everything, so that nothing the editor does can break a quiz page for a student. Whatever
     * points at a defect of the code rather than at the data - a PHP \Error (undefined method,
     * type error), a coding_exception, SQL the database rejects - is reported through debugging():
     * visible on a development site and a failure in PHPUnit and Behat, silent on a production
     * site. A missing record or a refused capability stays quiet.
     *
     * @param \Throwable $e What was caught.
     * @param string $where Where it was caught.
     * @return void
     */
    public static function caught(\Throwable $e, string $where): void {
        if (self::is_defect($e)) {
            debugging("local_stackmatheditor: {$where}: " . get_class($e) . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }
        self::dbg("{$where}: " . $e->getMessage());
    }

    /**
     * Whether a caught throwable points at a defect of the code rather than at the data.
     *
     * @param \Throwable $e What was caught.
     * @return bool True for \Error, coding_exception and SQL errors.
     */
    public static function is_defect(\Throwable $e): bool {
        return $e instanceof \Error
            || $e instanceof \coding_exception
            || $e instanceof \dml_read_exception
            || $e instanceof \dml_write_exception
            || $e instanceof \ddl_exception;
    }

    /**
     * Load STACK questions via the quiz_slots.questionbankentryid column.
     *
     * Used on Moodle 4.x where the column is present.
     *
     * @param int $quizid Quiz instance ID.
     * @return array List of STACK question data.
     */
    private static function load_questions_direct(int $quizid): array {
        global $DB;

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot ASC');
        if (empty($slots)) {
            return [];
        }

        // Collect unique qbeids from all slots first.
        $qbeids = [];
        foreach ($slots as $slot) {
            $qbeid = (int) ($slot->questionbankentryid ?? 0);
            if ($qbeid) {
                $qbeids[] = $qbeid;
            }
        }
        if (empty($qbeids)) {
            return [];
        }

        // Bulk-load the latest question version for every qbeid in one query,
        // Eliminating the previous per-slot DB call inside the loop.
        $uniqueqbeids = array_unique($qbeids);
        [$insql, $params] = $DB->get_in_or_equal($uniqueqbeids, SQL_PARAMS_NAMED, 'qbeid');
        $sql = "SELECT qv.questionbankentryid, qv.questionid, q.qtype, q.name
                  FROM {question_versions} qv
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qv.questionbankentryid {$insql}
                   AND qv.version = (
                       SELECT MAX(qv2.version)
                         FROM {question_versions} qv2
                        WHERE qv2.questionbankentryid = qv.questionbankentryid
                   )";
        $qrefs = $DB->get_records_sql($sql, $params);

        // Index by qbeid for O(1) lookup while iterating slots.
        $qrefbyqbeid = [];
        foreach ($qrefs as $qref) {
            $qrefbyqbeid[(int) $qref->questionbankentryid] = $qref;
        }

        $data = [];
        foreach ($slots as $slot) {
            $qbeid = (int) ($slot->questionbankentryid ?? 0);
            if (!$qbeid || !isset($qrefbyqbeid[$qbeid])) {
                continue;
            }
            $qref = $qrefbyqbeid[$qbeid];
            if ($qref->qtype !== 'stack') {
                continue;
            }
            $data[] = [
                'questionid' => (int) $qref->questionid,
                'qbeid'      => $qbeid,
                'name'       => $qref->name,
                'slot'       => (int) $slot->slot,
            ];
        }
        return $data;
    }

    /**
     * Load STACK questions via the question_references table.
     *
     * Used as fallback when quiz_slots lacks questionbankentryid.
     *
     * @param int $quizid Quiz instance ID.
     * @return array List of STACK question data.
     */
    private static function load_questions_via_refs(int $quizid): array {
        global $DB;
        $sql  = "
            SELECT qs.slot AS slotnum,
                   qs.id   AS slotid,
                   qr.questionbankentryid AS qbeid
              FROM {quiz_slots} qs
              JOIN {question_references} qr
                   ON qr.itemid = qs.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
             WHERE qs.quizid = :quizid
          ORDER BY qs.slot ASC";
        $rows = $DB->get_records_sql($sql, ['quizid' => $quizid]);
        if (empty($rows)) {
            return [];
        }

        // Collect unique qbeids before any further queries.
        $qbeids = [];
        foreach ($rows as $row) {
            $qbeid = (int) $row->qbeid;
            if ($qbeid) {
                $qbeids[] = $qbeid;
            }
        }
        if (empty($qbeids)) {
            return [];
        }

        // Bulk-load the latest question version for every qbeid in one query,
        // Eliminating the previous per-row DB call inside the loop.
        $uniqueqbeids = array_unique($qbeids);
        [$insql, $params] = $DB->get_in_or_equal($uniqueqbeids, SQL_PARAMS_NAMED, 'qbeid');
        $versql = "SELECT qv.questionbankentryid, qv.questionid, q.qtype, q.name
                     FROM {question_versions} qv
                     JOIN {question} q ON q.id = qv.questionid
                    WHERE qv.questionbankentryid {$insql}
                      AND qv.version = (
                          SELECT MAX(qv2.version)
                            FROM {question_versions} qv2
                           WHERE qv2.questionbankentryid = qv.questionbankentryid
                      )";
        $qrefs = $DB->get_records_sql($versql, $params);

        // Index by qbeid for O(1) lookup while iterating rows.
        $qrefbyqbeid = [];
        foreach ($qrefs as $qref) {
            $qrefbyqbeid[(int) $qref->questionbankentryid] = $qref;
        }

        $data = [];
        foreach ($rows as $row) {
            $qbeid = (int) $row->qbeid;
            if (!$qbeid || !isset($qrefbyqbeid[$qbeid])) {
                continue;
            }
            $qref = $qrefbyqbeid[$qbeid];
            if ($qref->qtype !== 'stack') {
                continue;
            }
            $data[] = [
                'questionid' => (int) $qref->questionid,
                'qbeid'      => $qbeid,
                'name'       => $qref->name,
                'slot'       => (int) $row->slotnum,
            ];
        }
        return $data;
    }

    /**
     * Load slot-to-STACK-question mapping for a quiz attempt.
     *
     * Returns an array with keys:
     *   slotmap  (slot => questionid),
     *   qbeids   (slot => qbeid),
     *   qbeidmap (qbeid => questionid).
     * Result is cached per request.
     *
     * The attempt id comes from the request. With a quiz instance id the attempt has to belong to
     * that quiz, or the result is empty: the page's own quiz decides which configuration applies,
     * and an attempt id from elsewhere must not mix another quiz's questions into it.
     *
     * @param int $attemptid Quiz attempt ID.
     * @param int $quizinstanceid Quiz the attempt has to belong to; 0 for no check.
     * @return array Slot mapping data.
     */
    public static function load_attempt_stack_slots(int $attemptid, int $quizinstanceid = 0): array {
        $key = $attemptid . ':' . $quizinstanceid;
        if (isset(self::$attemptcache[$key])) {
            return self::$attemptcache[$key];
        }
        $result = ['slotmap' => [], 'qbeids' => [], 'qbeidmap' => []];
        try {
            $result = self::do_load_attempt_slots($attemptid, $quizinstanceid);
        } catch (\moodle_exception $e) {
            // An attempt that cannot be loaded (deleted, another user's): no slot mapping.
            self::caught($e, 'load_attempt_stack_slots');
        }
        self::$attemptcache[$key] = $result;
        return $result;
    }

    /**
     * Internal implementation for load_attempt_stack_slots().
     *
     * @param int $attemptid Quiz attempt ID.
     * @param int $quizinstanceid Quiz the attempt has to belong to; 0 for no check.
     * @return array Slot mapping data.
     */
    private static function do_load_attempt_slots(int $attemptid, int $quizinstanceid = 0): array {
        global $DB;
        $result = ['slotmap' => [], 'qbeids' => [], 'qbeidmap' => []];

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$attempt) {
            return $result;
        }
        if ($quizinstanceid > 0 && (int) $attempt->quiz !== $quizinstanceid) {
            return $result;
        }

        $qas = $DB->get_records(
            'question_attempts',
            ['questionusageid' => $attempt->uniqueid],
            'slot ASC'
        );
        if (empty($qas)) {
            return $result;
        }

        $rawslotmap  = [];
        $questionids = [];
        foreach ($qas as $qa) {
            $qid              = (int) $qa->questionid;
            $slot             = (int) $qa->slot;
            $rawslotmap[$slot] = $qid;
            if (!in_array($qid, $questionids)) {
                $questionids[] = $qid;
            }
        }

        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'qid');
        $questions = $DB->get_records_select('question', "id {$insql}", $params, '', 'id, qtype');

        foreach ($rawslotmap as $slot => $qid) {
            if (!isset($questions[$qid]) || $questions[$qid]->qtype !== 'stack') {
                continue;
            }
            $result['slotmap'][$slot] = $qid;
            $qbeid = config_manager::resolve_qbeid($qid);
            if ($qbeid) {
                $result['qbeids'][$slot] = $qbeid;
                if (!isset($result['qbeidmap'][$qbeid])) {
                    $result['qbeidmap'][$qbeid] = $qid;
                }
            }
        }
        return $result;
    }

    /**
     * Capability that authorises changing the editor configuration of an activity.
     *
     * Configuring is a write action, so it is tied to a write capability of the module context:
     *   - mod_quiz: mod/quiz:manage (edit the quiz);
     *   - mod_adaptivequiz: moodle/course:manageactivities, as that module has no manage
     *     capability of its own. A read capability such as mod/adaptivequiz:viewreport is not
     *     enough: a role that may only see reports must not change the configuration.
     * There is deliberately no capability of the plugin's own (local/stackmatheditor:configure):
     * the module's write capabilities let the roles a site already has keep working.
     *
     * Settings navigation, the configure links on the quiz edit page and configure.php all ask
     * this method, so what is offered and what is allowed cannot drift apart.
     *
     * @param string $modname Module name of the activity.
     * @return string|null Capability name, or null for modules without a configuration UI.
     */
    public static function configure_capability(string $modname): ?string {
        switch ($modname) {
            case 'quiz':
                return 'mod/quiz:manage';
            case 'adaptivequiz':
                return 'moodle/course:manageactivities';
            default:
                return null;
        }
    }

    /**
     * Whether the current user may configure the editor of the given activity: the configure
     * capability of the module and access to it, as configure.php requires.
     *
     * @param int $cmid Course module ID.
     * @return bool True if the user holds the configure capability of that module.
     */
    public static function can_configure(int $cmid): bool {
        if ($cmid <= 0) {
            return false;
        }
        try {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            $capability = $cm ? self::configure_capability($cm->modname) : null;
            if ($capability === null) {
                return false;
            }
            if (!has_capability($capability, \context_module::instance($cmid))) {
                return false;
            }
            // The capability alone is not access: configure.php runs require_login() for the
            // course module, so a link is only offered to someone who can open the activity -
            // in the course, and seeing the module.
            $course = get_course((int) $cm->course);
            if (!can_access_course($course)) {
                return false;
            }
            return get_fast_modinfo($course)->get_cm($cmid)->uservisible;
        } catch (\moodle_exception $e) {
            // A module or course that cannot be read: nobody configures it.
            self::caught($e, 'can_configure');
            return false;
        }
    }

    /**
     * Check whether the current user may configure the given quiz.
     *
     * Alias of can_configure(), kept as part of the public API; it follows can_configure().
     *
     * @param int $cmid Course module ID.
     * @return bool True if the user can configure the activity.
     */
    public static function can_manage_quiz(int $cmid): bool {
        return self::can_configure($cmid);
    }

    /**
     * Return the page a configuration link should lead back to: the current page.
     *
     * The complete URL including all query parameters is kept, so the Back button returns to
     * exactly the calling page (view, edit, review, ...). Without a page URL the module's view
     * page is used.
     *
     * @param int    $cmid    Course module ID.
     * @param string $modname Module name ('quiz' or 'adaptivequiz').
     * @return string Absolute URL string.
     */
    public static function get_return_url(int $cmid, string $modname = 'quiz'): string {
        global $PAGE;
        $fallback = self::get_fallback_return_url($cmid, $modname);
        // Accessing $PAGE->url before set_url() triggers debugging() in Moodle 4.x.
        // The has_set_url() check prevents that in both production and test context.
        if (!$PAGE->has_set_url()) {
            return $fallback;
        }
        try {
            $url = $PAGE->url->out(false);
            return ($url !== '') ? $url : $fallback;
        } catch (\moodle_exception $e) {
            // A page URL that cannot be written out: back to the activity.
            self::caught($e, 'get_return_url');
            return $fallback;
        }
    }

    /**
     * Safe default return target when the calling page is unknown (direct call).
     *
     * @param int    $cmid    Course module ID.
     * @param string $modname Module name ('quiz' or 'adaptivequiz').
     * @return string Absolute URL string of the module's view page.
     */
    public static function get_fallback_return_url(int $cmid, string $modname = 'quiz'): string {
        $path = $modname === 'adaptivequiz' ? '/mod/adaptivequiz/view.php' : '/mod/quiz/view.php';
        return (new \moodle_url($path, ['id' => $cmid]))->out(false);
    }

    /**
     * Validate a requested return URL; fall back to the module's view page.
     *
     * Only URLs on this site are accepted: root-relative paths ("/mod/quiz/edit.php?cmid=5")
     * or absolute URLs below $CFG->wwwroot. External targets ("https://example.org/"),
     * protocol-relative ones ("//example.org/"), script URLs and paths relative to the current
     * directory are rejected, so the Back button can never become an open redirect.
     *
     * @param string $raw     Requested URL (e.g. the returnurl parameter).
     * @param int    $cmid    Course module ID.
     * @param string $modname Module name ('quiz' or 'adaptivequiz').
     * @return string Absolute local URL string.
     */
    public static function resolve_return_url(string $raw, int $cmid, string $modname = 'quiz'): string {
        global $CFG;
        $fallback = self::get_fallback_return_url($cmid, $modname);
        $clean = clean_param(trim($raw, " \n\r\t\v\x00"), PARAM_LOCALURL);
        if ($clean === '') {
            return $fallback;
        }
        $rootrelative = strpos($clean, '/') === 0 && strpos($clean, '//') !== 0;
        $absolute = stripos($clean, $CFG->wwwroot . '/') === 0;
        if (!$rootrelative && !$absolute) {
            return $fallback;
        }
        return (new \moodle_url($clean))->out(false);
    }

    /**
     * Check whether STACK questions exist in a quiz (PHP-backend check for Req. B).
     *
     * @param int $cmid Course module ID.
     * @return bool
     */
    public static function quiz_has_stack_questions(int $cmid): bool {
        $instanceid = self::get_quiz_instance_id($cmid);
        if (!$instanceid) {
            return false;
        }
        $questions = self::load_quiz_stack_questions($instanceid);
        return !empty($questions);
    }
}
