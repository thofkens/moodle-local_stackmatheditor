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

/**
 * CLI seed for the local_stackmatheditor browser and load tests (disposable test sites only).
 *
 * Verifies that the plugin and qtype_stack are installed, then creates - idempotently - a course
 * with a teacher, students and two quizzes whose STACK questions are imported from the Moodle XML
 * fixtures in tests/fixtures (no PHPUnit generator needed):
 * - "SME Settings Quiz": two algebraic questions on one page (settings matrix);
 * - "SME Load Quiz": eight algebraic and two textarea questions on one page (load tests and
 *   many editors on one page).
 * Prints the values as shell "export" lines, so the CI runner and the make targets can source them.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->libdir . '/questionlib.php');

$pluginmanager = core_plugin_manager::instance();
foreach (['local_stackmatheditor', 'qtype_stack'] as $component) {
    $info = $pluginmanager->get_plugin_info($component);
    if ($info === null || empty($info->versiondb)) {
        fwrite(STDERR, "{$component} is not installed on this site - run the Moodle upgrade first.\n");
        exit(1);
    }
}

// Enrolment notifications would try to send mail on a site without a mail setup.
$CFG->noemailever = true;

// STACK validates every imported question with the CAS and marks it broken when Maxima cannot be
// reached - students then see "unexpected internal error" and no editor. A freshly installed
// site has no working CAS configuration yet, so set it up and prove the connection first.
require_once($CFG->dirroot . '/question/type/stack/stack/cas/installhelper.class.php');
require_once($CFG->dirroot . '/question/type/stack/stack/cas/connectorhelper.class.php');
if (get_config('qtype_stack', 'platform') !== 'linux' || !get_config('qtype_stack', 'maximacommand')) {
    set_config('platform', 'linux', 'qtype_stack');
    set_config('maximacommand', 'maxima', 'qtype_stack');
    set_config('maximaversion', 'default', 'qtype_stack');
    set_config('casresultscache', 'db', 'qtype_stack');
    set_config('castimeout', '30', 'qtype_stack');
    purge_all_caches();
}
stack_cas_configuration::create_maximalocal();
[$casmessage, $casdebug, $casok] = stack_connection_helper::stackmaxima_genuine_connect();
if (!$casok) {
    fwrite(STDERR, "STACK cannot reach Maxima - the imported questions would be broken.\n{$casmessage}\n{$casdebug}\n");
    exit(1);
}

$students = (int) (getenv('SME_STUDENTS') ?: 20);
$password = 'Test!2345';
$gen = new testing_data_generator();

/**
 * Import one question from a Moodle XML fixture into a category.
 *
 * @param string $file Fixture file name in tests/fixtures.
 * @param stdClass $category Question category.
 * @param stdClass $course Course.
 * @param string $name Question name.
 * @return int Question id.
 */
function local_stackmatheditor_seed_import(string $file, stdClass $category, stdClass $course, string $name): int {
    global $DB;
    $xml = file_get_contents(__DIR__ . '/../fixtures/' . $file);
    $xml = preg_replace('~<name>\s*<text>[^<]*</text>~', '<name><text>' . $name . '</text>', $xml, 1);
    $tmp = make_request_directory() . '/' . $file;
    file_put_contents($tmp, $xml);
    $format = new qformat_xml();
    $format->setCategory($category);
    // The context of the category: the course on Moodle 4.5, the question bank module on 5.0+.
    $format->setContexts([context::instance_by_id($category->contextid)]);
    $format->setCourse($course);
    $format->setFilename($tmp);
    $format->setMatchgrades('nearest');
    $format->setStoponerror(true);
    ob_start();
    $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
    ob_end_clean();
    if (!$ok || empty($format->questionids)) {
        throw new moodle_exception('Import of ' . $file . ' failed.');
    }
    $questionid = (int) reset($format->questionids);
    if ($DB->get_field('qtype_stack_options', 'isbroken', ['questionid' => $questionid])) {
        throw new moodle_exception('STACK marked the imported question from ' . $file . ' as broken.');
    }
    return $questionid;
}

/**
 * Seed a quiz that only one spec needs, without letting it break the others.
 *
 * @param testing_data_generator $gen Data generator.
 * @param stdClass $course Course.
 * @param stdClass $category Question category.
 * @param string $name Quiz name.
 * @param array $fixtures Question fixtures.
 * @return int Course module id, or 0 when the quiz could not be built.
 */
function local_stackmatheditor_seed_optional_quiz(
    testing_data_generator $gen,
    stdClass $course,
    stdClass $category,
    string $name,
    array $fixtures
): int {
    try {
        return local_stackmatheditor_seed_quiz($gen, $course, $category, $name, $fixtures);
    } catch (Throwable $e) {
        fwrite(STDERR, "WARNING: could not seed '{$name}' from " . implode(', ', $fixtures)
            . ': ' . $e->getMessage() . "\n");
        return 0;
    }
}

/**
 * Create a quiz with the given questions on one page, unless it exists already.
 *
 * @param testing_data_generator $gen Generator.
 * @param stdClass $course Course.
 * @param stdClass $category Question category.
 * @param string $name Quiz name.
 * @param string[] $fixtures Fixture per question.
 * @return int Course module id.
 */
function local_stackmatheditor_seed_quiz(
    testing_data_generator $gen,
    stdClass $course,
    stdClass $category,
    string $name,
    array $fixtures
): int {
    global $DB;
    $quiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => $name]);
    if (!$quiz) {
        $created = $gen->create_module('quiz', [
            'course' => $course->id,
            'name' => $name,
            'preferredbehaviour' => 'adaptive',
            'questionsperpage' => 0,
            'grade' => 10,
        ]);
        $quiz = $DB->get_record('quiz', ['id' => $created->id], '*', MUST_EXIST);
        foreach ($fixtures as $i => $fixture) {
            $qid = local_stackmatheditor_seed_import($fixture, $category, $course, $name . ' Q' . ($i + 1));
            quiz_add_quiz_question($qid, $quiz, 1, 1);
        }
    }
    // Every question worth one mark, and the quiz total recomputed - otherwise no attempt can start.
    $DB->set_field('quiz_slots', 'maxmark', 1, ['quizid' => $quiz->id]);
    \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    return (int) get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST)->id;
}

$course = $DB->get_record('course', ['shortname' => 'SMETEST']);
if (!$course) {
    $course = $gen->create_course(['shortname' => 'SMETEST', 'fullname' => 'STACK MathQuill Editor tests']);
}
$context = context_course::instance($course->id);
$bankhelper = '\\core_question\\local\\bank\\question_bank_helper';
if (method_exists($bankhelper, 'get_default_open_instance_system_type')) {
    // Moodle 5.0+: there are no course-level question banks any more; questions live in a
    // question bank module of the course. question_make_default_categories() no longer works.
    $bank = $bankhelper::get_default_open_instance_system_type($course, true);
    $category = question_get_default_category(context_module::instance($bank->id)->id, true);
} else {
    $category = question_make_default_categories([$context]);
}

$users = ['sme_teacher' => 'editingteacher'];
for ($i = 1; $i <= $students; $i++) {
    $users[sprintf('sme_student%02d', $i)] = 'student';
}
foreach ($users as $username => $role) {
    $user = $DB->get_record('user', ['username' => $username]);
    if (!$user) {
        $user = $gen->create_user(['username' => $username, 'password' => $password,
            'firstname' => $username, 'lastname' => 'SME']);
    }
    $gen->enrol_user($user->id, $course->id, $role);
}

$settingscm = local_stackmatheditor_seed_quiz(
    $gen,
    $course,
    $category,
    'SME Settings Quiz',
    ['stack_algebraic.xml', 'stack_algebraic.xml']
);
$loadcm = local_stackmatheditor_seed_quiz(
    $gen,
    $course,
    $category,
    'SME Load Quiz',
    array_merge(array_fill(0, 8, 'stack_algebraic.xml'), array_fill(0, 2, 'stack_textarea.xml'))
);

// The question the bidirectional sync was reported with (#77): four algebraic inputs bound to
// four JSXGraph sliders. Its own quiz, because a slider question is slow to instantiate and the
// other suites have no use for it.
// The quizzes below serve one spec each. If one of them cannot be built - a fixture STACK will not
// import, say - that spec has nothing to test, but every other workflow still needs its site:
// the failure is reported on stderr, the id becomes 0, and the spec skips with a reason.
$jsxgraphcm = local_stackmatheditor_seed_optional_quiz(
    $gen,
    $course,
    $category,
    'SME JSXGraph Quiz',
    ['stack_jsxgraph.xml']
);

// A units input (#77, item 18) next to an algebraic one, so a test can compare the two on one
// page. Its own quiz: the load quiz has a fixed shape the performance suite depends on.
$unitscm = local_stackmatheditor_seed_optional_quiz(
    $gen,
    $course,
    $category,
    'SME Units Quiz',
    ['stack_units.xml', 'stack_algebraic.xml']
);

// Warm STACK's CAS result cache: instantiate every question once now. Otherwise the first
// attempts of all simulated students start at the same moment, each instantiating ten STACK
// questions with a fresh Maxima process, and the herd runs into the CAS timeout on a small CI
// runner - the load test would then measure the cold CAS, not the page and the plugin.
$started = microtime(true);
$questionids = $DB->get_fieldset_sql(
    "SELECT qv.questionid
       FROM {quiz_slots} qs
       JOIN {quiz} q ON q.id = qs.quizid
       JOIN {question_references} qr ON qr.itemid = qs.id
            AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
       JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
      WHERE q.course = :courseid",
    ['courseid' => $course->id]
);
$quba = question_engine::make_questions_usage_by_activity('local_stackmatheditor', $context);
$quba->set_preferred_behaviour('adaptive');
foreach (array_unique($questionids) as $questionid) {
    $quba->add_question(question_bank::load_question($questionid));
}
$quba->start_all_questions();
fwrite(STDERR, sprintf(
    "STACK CAS cache warmed for %d questions in %.1f s.\n",
    count(array_unique($questionids)),
    microtime(true) - $started
));

// Question bank entry ids of the settings quiz, in slot order (question-level configuration).
$qbeids = $DB->get_fieldset_sql(
    "SELECT qr.questionbankentryid
       FROM {quiz_slots} qs
       JOIN {question_references} qr ON qr.itemid = qs.id
            AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
      WHERE qs.quizid = :quizid
   ORDER BY qs.slot",
    ['quizid' => get_coursemodule_from_id('quiz', $settingscm, 0, false, MUST_EXIST)->instance]
);

// A right-to-left language for rtl.spec.js: a minimal pack of only its langconfig.php, which is
// what makes Moodle render a page right to left (dir="rtl", the flipped theme CSS). Every string
// falls back to English. Created only where no real Hebrew pack is installed.
$rtlpack = $CFG->dataroot . '/lang/he';
if (!is_dir($CFG->dirroot . '/lang/he') && !is_file($rtlpack . '/langconfig.php')) {
    make_writable_directory($rtlpack);
    file_put_contents($rtlpack . '/langconfig.php', implode("\n", [
        '<?php',
        '$string[\'thisdirection\'] = \'rtl\';',
        '$string[\'thislanguage\'] = \'Hebrew (SME RTL test)\';',
        '$string[\'thislanguageint\'] = \'Hebrew\';',
        '$string[\'parentlanguage\'] = \'\';',
        '',
    ]));
    get_string_manager()->reset_caches();
}

// Moodle 5.3+ (theme_boost colour modes): offer the modes, so the dark mode smoke test can switch
// to dark as a user would (#93). The site default stays light; nothing else changes.
if (class_exists('\\theme_boost\\colour_mode')) {
    set_config('enablecolourmodes', 1, 'theme_boost');
}

// Option --all-groups switches every toolbar group on site-wide. A fresh site offers the default
// selection only, and a run that has to hear the matrix chooser (NVDA, #69) needs it on the page.
if (in_array('--all-groups', $argv ?? [], true)) {
    set_config(
        'default_groups',
        implode(',', array_keys(\local_stackmatheditor\definitions::get_element_groups())),
        'local_stackmatheditor'
    );
    // The NVDA run also has to hear the student switch; it is on by default, this makes the
    // promise explicit instead of depending on the default (#89).
    set_config('allowstudenttoggle', 1, 'local_stackmatheditor');
}

// Option --reset removes every quiz- and question-level configuration of the settings quiz, so each
// browser test starts from the admin settings alone.
if (in_array('--reset', $argv ?? [], true)) {
    $DB->delete_records('local_stackmatheditor', ['cmid' => $settingscm]);
    echo "reset cmid {$settingscm}\n";
    exit(0);
}

$exports = [
    'SME_BASE_URL' => $CFG->wwwroot,
    'SME_SETTINGS_QBE1' => $qbeids[0] ?? 0,
    'SME_SETTINGS_QBE2' => $qbeids[1] ?? 0,
    'SME_COURSE_ID' => $course->id,
    'SME_SETTINGS_CMID' => $settingscm,
    'SME_LOAD_CMID' => $loadcm,
    'SME_JSXGRAPH_CMID' => $jsxgraphcm,
    'SME_UNITS_CMID' => $unitscm,
    'SME_USER_PASS' => $password,
    'SME_STUDENTS' => $students,
];
foreach ($exports as $key => $value) {
    echo "export {$key}='{$value}'\n";
}
