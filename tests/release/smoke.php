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
 * Smoke test of a site the release archive was installed into (release-artefact.yml).
 *
 * Not the unit tests again - the paths a site uses, on the code that came out of the archive:
 * a real quiz with a real STACK question gets a quiz default and a question configuration; the
 * course is backed up and restored into a new course, and the configuration arrives there on the
 * new ids; deleting the original quiz removes its configuration (observer); afterwards there is
 * no orphan and no duplicate scope.
 *
 * Usage: php smoke.php /path/to/moodle
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This script runs from a checkout outside the Moodle tree and is told where Moodle is, so the
// usual literal config.php path cannot be used; the sniff that expects it is off for this file.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
define('CLI_SCRIPT', true);

$moodle = realpath($argv[1] ?? '') ?: ($argv[1] ?? '');
$config = is_file($moodle . '/config.php') ? $moodle . '/config.php' : $moodle . '/../config.php';
require($config);
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use local_stackmatheditor\config_manager;
use local_stackmatheditor\data_maintenance;

$failures = 0;

/**
 * Report one check.
 *
 * @param bool $ok Whether it holds.
 * @param string $what What was checked.
 * @return void
 */
function check(bool $ok, string $what): void {
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failures++;
    }
}

$admin = get_admin();
\core\session\manager::set_user($admin);
set_config('coursebinenable', 0, 'tool_recyclebin');
$gen = new testing_data_generator();

// A course, a quiz and a STACK question from the plugin's own fixture (part of the archive).
$course = $gen->create_course(['shortname' => 'SMESMOKE' . time(), 'fullname' => 'Release smoke']);
$quiz = $gen->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
$cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
$coursecontext = context_course::instance($course->id);
$bankhelper = '\\core_question\\local\\bank\\question_bank_helper';
if (method_exists($bankhelper, 'get_default_open_instance_system_type')) {
    $bank = $bankhelper::get_default_open_instance_system_type($course, true);
    $category = question_get_default_category(context_module::instance($bank->id)->id, true);
} else {
    $category = question_make_default_categories([$coursecontext]);
}
$fixture = $CFG->dirroot . '/local/stackmatheditor/tests/fixtures/stack_algebraic.xml';
check(is_file($fixture), 'the archive ships the STACK fixture');
$format = new qformat_xml();
$format->setCategory($category);
$format->setContexts([context::instance_by_id($category->contextid)]);
$format->setCourse($course);
$format->setFilename($fixture);
$format->setMatchgrades('nearest');
$format->setStoponerror(true);
ob_start();
$imported = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
ob_end_clean();
check($imported && !empty($format->questionids), 'a STACK question imports');
$questionid = (int) reset($format->questionids);
quiz_add_quiz_question($questionid, $quiz, 0, 1);
$qbeid = (int) config_manager::resolve_qbeid($questionid);

// Configuration through the plugin's write path.
config_manager::save_quiz_default((int) $cm->id, ['_enabled' => true, 'marker' => 'quiz']);
config_manager::save_config((int) $cm->id, $qbeid, ['_enabled' => false, 'marker' => 'question']);
$read = config_manager::get_configs((int) $cm->id, [$qbeid]);
check(($read[$qbeid]['marker'] ?? '') === 'question', 'the question configuration reads back');
// Mode 3 (on by default, a quiz or question may switch it off) lets the override show.
$mode = get_config('local_stackmatheditor', 'enabled');
set_config('enabled', 3, 'local_stackmatheditor');
check(!config_manager::get_effective_enabled((int) $cm->id, $qbeid), 'the question override applies');
set_config('enabled', $mode, 'local_stackmatheditor');

// Backup and restore into a new course.
$bc = new backup_controller(
    backup::TYPE_1COURSE,
    $course->id,
    backup::FORMAT_MOODLE,
    backup::INTERACTIVE_NO,
    backup::MODE_GENERAL,
    $admin->id
);
$bc->get_plan()->get_setting('users')->set_value(true);
$bc->execute_plan();
$file = $bc->get_results()['backup_destination'];
$backupid = $bc->get_backupid();
$bc->destroy();
$file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupid));
$newcourseid = restore_dbops::create_new_course('Release smoke restored', 'SMESMOKER' . time(), $course->category);
$rc = new restore_controller(
    $backupid,
    $newcourseid,
    backup::INTERACTIVE_NO,
    backup::MODE_GENERAL,
    $admin->id,
    backup::TARGET_NEW_COURSE
);
$rc->execute_precheck();
$rc->execute_plan();
$rc->destroy();
$newcms = get_fast_modinfo($newcourseid)->get_instances_of('quiz');
check(count($newcms) === 1, 'the restored course has the quiz');
$newcm = reset($newcms);
$rows = $DB->get_records(config_manager::TABLE, ['cmid' => $newcm->id]);
check(count($rows) === 2, 'quiz default and question configuration are restored');
$used = local_stackmatheditor\quiz_helper::load_quiz_qbeids((int) $newcm->instance);
$mapped = true;
foreach ($rows as $row) {
    if ($row->questionbankentryid !== null && !isset($used[(int) $row->questionbankentryid])) {
        $mapped = false;
    }
}
check($mapped, 'the restored question configuration points at the restored quiz\'s entry');

// Deleting the original quiz removes its configuration.
course_delete_module((int) $cm->id);
check($DB->count_records(config_manager::TABLE, ['cmid' => $cm->id]) === 0, 'deleting the quiz removes its configuration');

// The invariants.
check(data_maintenance::find_orphans() === [], 'no orphaned configuration');
check(data_maintenance::find_duplicate_scopes() === [], 'one row per scope');

// Clean up what the smoke created.
delete_course($newcourseid, false);
delete_course($course, false);
check(data_maintenance::find_orphans() === [], 'deleting the courses leaves no orphan');

echo $failures ? "{$failures} check(s) failed.\n" : "Release smoke passed.\n";
exit($failures ? 1 : 0);
