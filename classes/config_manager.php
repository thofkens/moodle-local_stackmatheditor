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
 * Manages per-quiz per-question toolbar configuration.
 *
 * Lookup priority for get_config():
 *   1. Exact:      cmid + qbeid          (question-level)
 *   2. Quiz-def.:  cmid + qbeid IS NULL  (quiz-level default)
 *   3. Global:     cmid=0 + qbeid        (cross-quiz question default)
 *   4. questionid: questionid column, same quiz or global only
 *                  (records keyed by question id instead of question bank entry)
 *   5. Instance defaults                 (settings.php)
 *
 * Quiz-level defaults are stored with questionbankentryid = NULL.
 * Question-level configs use a concrete questionbankentryid integer.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_manager {
    /** @var string Database table name. */
    public const TABLE = 'local_stackmatheditor';

    /** @var string Total read order of a scope: newest first, id breaks ties within a second. */
    public const READ_ORDER = 'timemodified DESC, id DESC';

    /** @var int Seconds a writer waits for the scope lock (config locktimeout overrides it). */
    private const LOCK_TIMEOUT = 10;


    // Instance-level helpers.


    /**
     * Returns instance-wide default enabled state for all element groups.
     *
     * @return array Group key => bool.
     */
    public static function get_instance_defaults(): array {
        $groups = definitions::get_element_groups();

        $enabledstr = get_config('local_stackmatheditor', 'default_groups');
        if ($enabledstr !== false && $enabledstr !== '') {
            $enabled = array_map('trim', explode(',', $enabledstr));
            $result  = [];
            foreach ($groups as $key => $group) {
                $result[$key] = in_array($key, $enabled);
            }
            return $result;
        }

        $hasoldformat = false;
        $result       = [];
        foreach ($groups as $key => $group) {
            $setting = get_config('local_stackmatheditor', 'default_' . $key);
            if ($setting !== false) {
                $hasoldformat  = true;
                $result[$key]  = (bool) $setting;
            } else {
                $result[$key]  = $group['default_enabled'];
            }
        }
        if ($hasoldformat) {
            return $result;
        }

        return definitions::get_default_enabled();
    }


    /**
     * Returns the full instance-level base config used for inheritance.
     *
     * This includes the toolbar defaults plus non-toolbar keys that must also
     * inherit cleanly across instance → quiz → question levels.
     *
     * @return array
     */
    public static function get_instance_base_config(): array {
        $config = self::get_instance_defaults();
        $config['_variableMode'] = self::get_instance_variable_mode();
        return $config;
    }

    /**
     * Returns instance-wide implicit multiplication mode.
     *
     * @return string Normalised implicit multiplication mode.
     */
    public static function get_instance_variable_mode(): string {
        // STACK is the sole source of truth for implicit multiplication: it stores "insert stars"
        // per input, and the converter always hands STACK what was typed. Any other mode found in
        // a stored configuration is ignored rather than migrated, so this behaviour does not
        // depend on an upgrade step having run.
        return definitions::IMPLICIT_STACK;
    }

    /**
     * Returns the instance-wide enabled mode (0-3).
     *
     * 0 = disabled globally, no override
     * 1 = enabled globally, no override
     * 2 = default off, quiz/question may enable
     * 3 = default on,  quiz/question may disable
     *
     * @return int
     */
    public static function get_instance_enabled_mode(): int {
        $val = get_config('local_stackmatheditor', 'enabled');
        $int = (int) $val;
        if ($int < 0 || $int > 3) {
            return 1; // Safe default: enabled.
        }
        return $int;
    }


    // DB column helpers.


    /**
     * Detect which column stores the JSON config.
     *
     * @return string Column name.
     */
    private static function get_config_column(): string {
        global $DB;
        static $col = null;
        if ($col !== null) {
            return $col;
        }
        $columns = $DB->get_columns(self::TABLE);
        $col     = isset($columns['allowed_elements']) ? 'allowed_elements' : 'config';
        return $col;
    }

    /**
     * Public accessor for the config column name (for debug display).
     *
     * @return string Column name.
     */
    public static function get_config_column_public(): string {
        return self::get_config_column();
    }


    // Low-level DB fetch.


    /**
     * Safe single-record fetch: returns the newest matching record.
     *
     * The order is total (timemodified has second resolution, id breaks the tie), so the record a
     * read returns is the same one collapse_scope() keeps.
     *
     * @param string $where SQL WHERE clause.
     * @param array  $params Query parameters.
     * @return \stdClass|null
     */
    private static function get_one(string $where, array $params): ?\stdClass {
        global $DB;
        $sql     = "SELECT * FROM {" . self::TABLE . "} WHERE {$where}"
                 . " ORDER BY " . self::READ_ORDER;
        $records = $DB->get_records_sql($sql, $params, 0, 1);
        return $records ? reset($records) : null;
    }


    // Qbeid resolution.


    /**
     * Resolve any question ID to its question bank entry ID.
     *
     * @param int $questionid
     * @return int|null
     */
    public static function resolve_qbeid(int $questionid): ?int {
        global $DB;
        $sql     = "SELECT qbe.id
                      FROM {question_bank_entries} qbe
                      JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                     WHERE qv.questionid = :questionid
                  ORDER BY qv.version DESC";
        $records = $DB->get_records_sql($sql, ['questionid' => $questionid], 0, 1);
        $record  = reset($records);
        return $record ? (int) $record->id : null;
    }

    /**
     * Ensure we have a qbeid.
     *
     * @param int $qbeid
     * @param int $questionid
     * @return int|null
     */
    public static function ensure_qbeid(int $qbeid, int $questionid = 0): ?int {
        if ($qbeid > 0) {
            return $qbeid;
        }
        if ($questionid > 0) {
            return self::resolve_qbeid($questionid);
        }
        return null;
    }


    // Decode helpers.


    /**
     * Decode a raw config JSON string.
     *
     * IMPORTANT: do not merge defaults here. Effective inheritance must be
     * resolved across instance → quiz → question by layering partial records.
     * Merging defaults at this stage would make an early match shadow higher
     * and lower precedence sources for keys it does not even contain.
     *
     * @param string|null $json
     * @return array|null
     */
    private static function decode_raw_config(?string $json): ?array {
        if (empty($json)) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Merge a raw config layer into an effective config.
     *
     * @param array $base
     * @param array|null $layer
     * @return array
     */
    private static function merge_config_layer(array $base, ?array $layer): array {
        if ($layer === null) {
            return $base;
        }
        return array_merge($base, $layer);
    }


    // Public read API.


    /**
     * Load effective config for a quiz + question.
     *
     * Lookup order:
     *   1. cmid + qbeid          (question-level)
     *   2. cmid + NULL           (quiz-level default)
     *   3. cmid=0 + qbeid        (global question default)
     *   4. questionid field      (records keyed by question id, same quiz or global only)
     *   5. instance defaults     (settings.php)
     *
     * There is deliberately no "any qbeid" layer: the same question bank entry used in another
     * quiz is another context, and only the explicit global default crosses quizzes.
     *
     * @param int $cmid       Course module ID (0 for global).
     * @param int $qbeid      Question bank entry ID (0 to auto-resolve).
     * @param int $questionid Question ID (for resolving qbeid and the questionid lookup).
     * @return array Merged config array.
     */
    public static function get_config(
        int $cmid,
        int $qbeid = 0,
        int $questionid = 0
    ): array {
        global $DB;

        $col    = self::get_config_column();
        $result = self::get_instance_base_config();

        $qbeid = self::ensure_qbeid($qbeid, $questionid) ?? 0;

        // Lowest-priority questionid/global fallbacks first. Both are scoped: the same question
        // bank entry in another quiz is a different context, and a configuration made there must
        // not leak into this one. Crossing quizzes is what the explicit global default
        // (cmid = 0) is for.
        if ($questionid > 0) {
            $columns = $DB->get_columns(self::TABLE);
            if (isset($columns['questionid'])) {
                $rec = self::get_one(
                    "questionid = :qid AND questionid > 0 AND (cmid = 0 OR cmid = :cmid)",
                    ['qid' => $questionid, 'cmid' => $cmid]
                );
                if ($rec) {
                    $result = self::merge_config_layer(
                        $result,
                        self::decode_raw_config($rec->$col)
                    );
                }
            }
        }

        if ($qbeid > 0) {
            $rec = self::get_one(
                "cmid = 0 AND questionbankentryid = :qbeid",
                ['qbeid' => $qbeid]
            );
            if ($rec) {
                $result = self::merge_config_layer(
                    $result,
                    self::decode_raw_config($rec->$col)
                );
            }
        }

        // Quiz-level defaults override instance/global fallbacks.
        if ($cmid > 0) {
            $rec = self::get_one(
                "cmid = :cmid AND questionbankentryid IS NULL",
                ['cmid' => $cmid]
            );
            if ($rec) {
                $result = self::merge_config_layer(
                    $result,
                    self::decode_raw_config($rec->$col)
                );
            }
        }

        // Most specific question-level config wins last.
        if ($cmid > 0 && $qbeid > 0) {
            $rec = self::get_one(
                "cmid = :cmid AND questionbankentryid = :qbeid",
                ['cmid' => $cmid, 'qbeid' => $qbeid]
            );
            if ($rec) {
                $result = self::merge_config_layer(
                    $result,
                    self::decode_raw_config($rec->$col)
                );
            }
        }

        return $result;
    }

    /**
     * What one level has stored itself - nothing inherited, nothing merged.
     *
     * get_config() answers "what applies here"; a form that decides what to store needs "what
     * did this level say". Asking the merged configuration instead would make a question that
     * only inherits "on" from its quiz look as if it had chosen it, and the next save - of the
     * toolbar groups alone - would freeze that into an override.
     *
     * @param int $cmid Course module ID.
     * @param int $qbeid Question bank entry ID, 0 for the quiz level.
     * @return array|null The level's own stored values, null when it has no record.
     */
    public static function get_own_config(int $cmid, int $qbeid = 0): ?array {
        $col = self::get_config_column();

        if ($qbeid > 0) {
            $rec = self::get_one(
                "cmid = :cmid AND questionbankentryid = :qbeid",
                ['cmid' => $cmid, 'qbeid' => $qbeid]
            );
        } else {
            $rec = self::get_one(
                "cmid = :cmid AND questionbankentryid IS NULL",
                ['cmid' => $cmid]
            );
        }

        return $rec ? self::decode_raw_config($rec->$col) : null;
    }

    /**
     * Load the quiz-level default config for a given cmid.
     * Returns null if no quiz-level record exists yet.
     *
     * @param int $cmid
     * @return array|null Decoded config or null.
     */
    public static function get_quiz_default(int $cmid): ?array {
        $col = self::get_config_column();

        $rec = self::get_one(
            "cmid = :cmid AND questionbankentryid IS NULL",
            ['cmid' => $cmid]
        );
        if (!$rec) {
            return null;
        }

        return self::merge_config_layer(
            self::get_instance_base_config(),
            self::decode_raw_config($rec->$col)
        );
    }

    /**
     * Batch-load configs for multiple questions in one quiz.
     *
     * @param int   $cmid
     * @param array $qbeids
     * @param array $questionids Optional qbeid => questionid map for the questionid layer.
     * @return array Map of qbeid => config.
     */
    public static function get_configs(
        int $cmid,
        array $qbeids,
        array $questionids = []
    ): array {
        global $DB;
        $col      = self::get_config_column();
        $configs  = [];
        $base     = self::get_instance_base_config();
        $columns  = null;

        $qbeids = array_values(array_unique(array_filter($qbeids)));
        foreach ($qbeids as $qbeid) {
            $configs[$qbeid] = $base;
        }
        if (empty($qbeids)) {
            return $configs;
        }

        // 1. Questionid layer (lowest fallback).
        // Preload all matching records in a single bulk query to avoid N+1.
        if (!empty($questionids)) {
            $columns = $DB->get_columns(self::TABLE);
            if (isset($columns['questionid'])) {
                $legacyqids = array_values($questionids);
                [$legacyinsql, $legacyparams] = $DB->get_in_or_equal(
                    $legacyqids,
                    SQL_PARAMS_NAMED,
                    'lqid'
                );
                $legacyparams['lcmid'] = $cmid;
                $legacyrecs = $DB->get_records_select(
                    self::TABLE,
                    "questionid {$legacyinsql} AND questionid > 0"
                        . " AND (cmid = 0 OR cmid = :lcmid)",
                    $legacyparams,
                    self::READ_ORDER
                );
                // Index by questionid for O(1) lookup; the newest record per questionid wins.
                $legacybyqid = [];
                foreach ($legacyrecs as $rec) {
                    $legacybyqid[(int) $rec->questionid] ??= $rec;
                }
                foreach ($qbeids as $qbeid) {
                    if (!isset($questionids[$qbeid])) {
                        continue;
                    }
                    $qid = (int) $questionids[$qbeid];
                    if (isset($legacybyqid[$qid])) {
                        $configs[$qbeid] = self::merge_config_layer(
                            $configs[$qbeid],
                            self::decode_raw_config($legacybyqid[$qid]->$col)
                        );
                    }
                }
            }
        }

        // 2. Global question defaults (cmid=0 + qbeid). Only these cross quizzes; records of
        // other quizzes are never read, so single and batch lookup follow the same hierarchy.
        [$insql, $params] = $DB->get_in_or_equal($qbeids, SQL_PARAMS_NAMED);
        $params['cmid'] = 0;
        $records = $DB->get_records_select(
            self::TABLE,
            "cmid = :cmid AND questionbankentryid {$insql}",
            $params,
            self::READ_ORDER
        );
        foreach (self::newest_per_qbeid($records) as $qbeid => $rec) {
            if (isset($configs[$qbeid])) {
                $configs[$qbeid] = self::merge_config_layer(
                    $configs[$qbeid],
                    self::decode_raw_config($rec->$col)
                );
            }
        }

        // 3. Quiz-level default overrides lower layers for all slots.
        if ($cmid > 0) {
            $quizrec = self::get_one(
                "cmid = :cmid AND questionbankentryid IS NULL",
                ['cmid' => $cmid]
            );
            if ($quizrec) {
                $quizlayer = self::decode_raw_config($quizrec->$col);
                foreach ($configs as $qbeid => $cfg) {
                    $configs[$qbeid] = self::merge_config_layer($cfg, $quizlayer);
                }
            }
        }

        // 4. Exact question-level configs win last.
        if ($cmid > 0) {
            [$insql, $params] = $DB->get_in_or_equal($qbeids, SQL_PARAMS_NAMED);
            $params['cmid'] = $cmid;
            $records = $DB->get_records_select(
                self::TABLE,
                "cmid = :cmid AND questionbankentryid {$insql}",
                $params,
                self::READ_ORDER
            );
            foreach (self::newest_per_qbeid($records) as $qbeid => $rec) {
                if (isset($configs[$qbeid])) {
                    $configs[$qbeid] = self::merge_config_layer(
                        $configs[$qbeid],
                        self::decode_raw_config($rec->$col)
                    );
                }
            }
        }

        return $configs;
    }


    // Public write API.


    /**
     * Keep only the first (newest) record per question bank entry of a READ_ORDER result.
     *
     * Without a unique index a scope may hold more than one row; merging all of them would mix
     * an older configuration into the newer one. The runtime reads exactly one row per scope.
     *
     * @param \stdClass[] $records Records ordered by READ_ORDER.
     * @return \stdClass[] Records keyed by question bank entry id.
     */
    private static function newest_per_qbeid(array $records): array {
        $result = [];
        foreach ($records as $rec) {
            $result[(int) $rec->questionbankentryid] ??= $rec;
        }
        return $result;
    }

    /**
     * Save question-level config (cmid + qbeid).
     *
     * @param int   $cmid
     * @param int   $qbeid      Question bank entry ID (>0).
     * @param array $elements   Config array.
     * @param int   $questionid Optional questionid to resolve qbeid.
     * @throws \moodle_exception If qbeid cannot be determined.
     */
    public static function save_config(
        int $cmid,
        int $qbeid,
        array $elements,
        int $questionid = 0
    ): void {
        global $DB, $USER;

        $qbeid = self::ensure_qbeid($qbeid, $questionid);
        if (!$qbeid) {
            throw new \moodle_exception('cannotresolveqbeid', 'local_stackmatheditor');
        }

        self::upsert_record($cmid, $qbeid, $elements, $USER->id);
    }

    /**
     * Save quiz-level default config (cmid, questionbankentryid IS NULL).
     *
     * @param int   $cmid
     * @param array $elements Config array.
     */
    public static function save_quiz_default(int $cmid, array $elements): void {
        global $USER;
        self::upsert_record($cmid, null, $elements, $USER->id);
    }

    /**
     * Internal upsert. Handles both question-level (qbeid int) and quiz-level (qbeid null) records.
     *
     * The scope is made unique by the application, not by a unique index (decision recorded in
     * docs/DATA-INTEGRITY.md): questionbankentryid is NULL for the quiz-level default, and
     * databases disagree on whether NULLs collide in a unique index - PostgreSQL and MariaDB
     * would let any number of quiz defaults through, which is the case that matters. Instead:
     *   - writers of the same scope are serialised with the Moodle lock API;
     *   - read, write and the removal of surplus rows run in one database transaction;
     *   - after the write the scope is collapsed to the row every read returns, which also repairs
     *     duplicates already in the table or caused by a lock factory that does not serialise.
     *
     * @param int      $cmid
     * @param int|null $qbeid  null for quiz-level default.
     * @param array    $elements
     * @param int      $userid
     * @throws \moodle_exception When the scope lock cannot be obtained in time.
     */
    private static function upsert_record(
        int $cmid,
        ?int $qbeid,
        array $elements,
        int $userid
    ): void {
        global $DB;

        $col  = self::get_config_column();
        $json = json_encode($elements, JSON_THROW_ON_ERROR);

        $factory  = \core\lock\lock_config::get_lock_factory('local_stackmatheditor');
        $resource = self::scope_lock_key($cmid, $qbeid);
        $timeout  = (int) (get_config('local_stackmatheditor', 'locktimeout') ?: self::LOCK_TIMEOUT);
        $lock     = $factory->get_lock($resource, max(1, $timeout));
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'moodle');
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            [$where, $params] = self::scope_condition($cmid, $qbeid);
            $records = $DB->get_records_select(self::TABLE, $where, $params, self::READ_ORDER, '*', 0, 1);
            $now = time();

            if ($records) {
                $keep               = reset($records);
                $keep->$col         = $json;
                $keep->usermodified = $userid;
                $keep->timemodified = max($now, (int) $keep->timemodified);
                $DB->update_record(self::TABLE, $keep);
            } else {
                $record                      = new \stdClass();
                $record->cmid                = $cmid;
                $record->questionbankentryid = $qbeid; // Null for quiz-level defaults.
                $record->$col                = $json;
                $record->usermodified        = $userid;
                $record->timecreated         = $now;
                $record->timemodified        = $now;
                $DB->insert_record(self::TABLE, $record);
            }

            self::collapse_scope($cmid, $qbeid);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Reduce one scope to the row every read returns and delete the others.
     *
     * @param int      $cmid  Course module id (0 = global scope).
     * @param int|null $qbeid Question bank entry id, null for the quiz-level default.
     * @return int Number of deleted rows.
     */
    public static function collapse_scope(int $cmid, ?int $qbeid): int {
        global $DB;
        [$where, $params] = self::scope_condition($cmid, $qbeid);
        $ids = $DB->get_fieldset_sql(
            "SELECT id FROM {" . self::TABLE . "} WHERE {$where} ORDER BY " . self::READ_ORDER,
            $params
        );
        $surplus = array_slice($ids, 1);
        if ($surplus) {
            $DB->delete_records_list(self::TABLE, 'id', $surplus);
        }
        return count($surplus);
    }

    /**
     * SQL condition for one scope.
     *
     * @param int      $cmid  Course module id.
     * @param int|null $qbeid Question bank entry id, null for the quiz-level default.
     * @return array [where, params]
     */
    private static function scope_condition(int $cmid, ?int $qbeid): array {
        if ($qbeid === null) {
            return ['cmid = :cmid AND questionbankentryid IS NULL', ['cmid' => $cmid]];
        }
        return ['cmid = :cmid AND questionbankentryid = :qbeid', ['cmid' => $cmid, 'qbeid' => $qbeid]];
    }

    /**
     * Lock resource name of one scope.
     *
     * @param int      $cmid  Course module id.
     * @param int|null $qbeid Question bank entry id, null for the quiz-level default.
     * @return string Resource key.
     */
    public static function scope_lock_key(int $cmid, ?int $qbeid): string {
        return 'scope_' . $cmid . '_' . ($qbeid === null ? 'default' : $qbeid);
    }


    // Enabled-flag helpers.


    /**
     * Determine whether the editor is effectively enabled for a given context.
     *
     * Mode semantics:
     *   0 = always off,  no override possible
     *   1 = always on,   no override possible
     *   2 = default off, quiz/question _enabled=true activates it
     *   3 = default on,  quiz/question _enabled=false deactivates it
     *
     * When $cmid=0 and $qbeid=0, only the instance mode is considered.
     *
     * @param int $cmid   Course module ID (0 = ignore quiz/question level).
     * @param int $qbeid  Question bank entry ID (0 = ignore question level).
     * @return bool
     */
    public static function get_effective_enabled(
        int $cmid = 0,
        int $qbeid = 0
    ): bool {
        $mode = self::get_instance_enabled_mode();

        if ($mode === 0) {
            return false;
        }
        if ($mode === 1) {
            return true;
        }

        // Mode 2 or 3 – check quiz then question level.
        $defaultenabled = ($mode === 3);

        // Load the most specific applicable config.
        if ($cmid > 0 && $qbeid > 0) {
            $cfg = self::get_config($cmid, $qbeid);
        } else if ($cmid > 0) {
            $cfg = self::get_quiz_default($cmid) ?? [];
        } else {
            return $defaultenabled;
        }

        if (isset($cfg['_enabled'])) {
            return (bool) $cfg['_enabled'];
        }

        return $defaultenabled;
    }

    /**
     * What to store for the activation of one level when its form is saved.
     *
     * A form always submits a value, because the checkbox is pre-filled with what the level
     * inherits. Storing that value unconditionally would turn every save - even one that only
     * changed the toolbar groups - into an explicit override, and the level would silently stop
     * following the one above it. So the value is stored only when it says something: when it
     * differs from what is inherited, or when the level already had an explicit value of its own.
     *
     * @param int $mode Instance activation mode.
     * @param bool|null $existing The level's stored value, null when it has none.
     * @param bool $inherited What the level gets without a value of its own.
     * @param bool|null $submitted What the form sent, null when the field was absent.
     * @return bool|null Value to store, or null to keep inheriting.
     */
    public static function activation_to_store(
        int $mode,
        ?bool $existing,
        bool $inherited,
        ?bool $submitted
    ): ?bool {
        // Modes 0 and 1 have no overrides at all; nothing below them is stored.
        if ($mode !== 2 && $mode !== 3) {
            return null;
        }

        if ($submitted === null) {
            return $existing;
        }

        if ($existing === null && $submitted === $inherited) {
            return null;
        }

        return $submitted;
    }

    /**
     * What to store for the student switch of one level when its form is saved.
     *
     * While the editor is off at a level the checkbox is disabled, and a disabled field is not
     * submitted. Reading that absence as "not allowed" would overwrite the author's choice with
     * 0, and switching the editor back on would not bring the switch back. The stored choice is
     * kept instead; get_effective_student_toggle() already refuses the switch wherever the
     * editor is off, so keeping it changes nothing until the editor returns.
     *
     * @param bool|null $existing The level's stored value, null when it has none.
     * @param bool $editorenabled Whether the editor is on at this level after the save.
     * @param bool|null $submitted What the form sent, null when the field was absent.
     * @param bool|null $inherited What the level gets without a value of its own, when known.
     * @return bool|null Value to store, or null to keep inheriting.
     */
    public static function student_toggle_to_store(
        ?bool $existing,
        bool $editorenabled,
        ?bool $submitted,
        ?bool $inherited = null
    ): ?bool {
        if (!$editorenabled || $submitted === null) {
            return $existing;
        }

        // As with the activation: a pre-filled checkbox that was not touched says nothing, and
        // storing it would stop the level from following the one above.
        if ($existing === null && $inherited !== null && $submitted === $inherited) {
            return null;
        }

        return $submitted;
    }

    /**
     * May any question on this page need the editor?
     *
     * get_effective_enabled($cmid) is not a page-level gate: it answers for the quiz and knows
     * nothing about the questions in it. With "off by default, can be enabled per quiz or
     * question", a quiz that stays off would keep the runtime from loading at all - and a
     * question switched on explicitly would never get the chance to say so.
     *
     * So this gate only decides what is true for the whole page. Mode 0 is off everywhere and
     * nothing below can change it; everything else loads the runtime and lets each slot decide.
     *
     * @return bool True when the runtime has to be loaded.
     */
    public static function page_may_need_editor(): bool {
        return self::get_instance_enabled_mode() !== 0;
    }

    /**
     * May students switch the editor off and on here?
     *
     * A subordinate permission: it is only ever asked when the editor is enabled at all, and any
     * level that says no is final. Lower levels may take the permission away, never give it back
     * - which is why this is an AND across the levels and not the usual "most specific wins".
     *
     * @param int $cmid Course module ID (0 = ignore quiz/question level).
     * @param int $qbeid Question bank entry ID (0 = ignore question level).
     * @return bool True when the switch may be rendered.
     */
    public static function get_effective_student_toggle(
        int $cmid = 0,
        int $qbeid = 0
    ): bool {
        // No editor, no switch. This is the hard upper bound of the whole feature.
        if (!self::get_effective_enabled($cmid, $qbeid)) {
            return false;
        }

        if (!self::get_instance_student_toggle()) {
            return false;
        }

        if ($cmid > 0) {
            $quiz = self::get_quiz_default($cmid) ?? [];
            if (isset($quiz['_allowStudentToggle']) && !$quiz['_allowStudentToggle']) {
                return false;
            }
        }

        if ($cmid > 0 && $qbeid > 0) {
            $question = self::get_config($cmid, $qbeid);
            if (isset($question['_allowStudentToggle']) && !$question['_allowStudentToggle']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Site-wide permission for the student switch.
     *
     * Defaults to true, so that a site that has never saved this setting keeps offering the
     * switch; an upgrade must not quietly take it away.
     *
     * @return bool
     */
    public static function get_instance_student_toggle(): bool {
        $value = get_config('local_stackmatheditor', 'allowstudenttoggle');

        return $value === false ? true : (bool) (int) $value;
    }

    /**
     * Largest structure the choosers offer here.
     *
     * Ordinary inheritance, unlike the student switch: the most specific value wins, and a level
     * that says nothing passes the question up. 0, null and an empty string are not dimensions -
     * they mean "nothing stored here".
     *
     * @param int $cmid Course module ID (0 = ignore quiz/question level).
     * @param int $qbeid Question bank entry ID (0 = ignore question level).
     * @return int Effective maximum, always within the allowed range.
     */
    public static function get_effective_max_dimension(int $cmid = 0, int $qbeid = 0): int {
        if ($cmid > 0 && $qbeid > 0) {
            $question = self::get_config($cmid, $qbeid);
            $value = definitions::clean_max_dimension($question['_maxStructuredDimension'] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        if ($cmid > 0) {
            $quiz = self::get_quiz_default($cmid) ?? [];
            $value = definitions::clean_max_dimension($quiz['_maxStructuredDimension'] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return definitions::get_instance_max_dimension();
    }
}
