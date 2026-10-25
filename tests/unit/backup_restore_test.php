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
 * Backup, restore and duplication carry the configuration onto the new ids (#88).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_stackmatheditor_plugin
 * @covers     \restore_local_stackmatheditor_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    /** @var \stdClass Source course. */
    private \stdClass $course;

    /** @var \stdClass Source quiz course module. */
    private \stdClass $cm;

    /** @var \stdClass Teacher who made the configuration. */
    private \stdClass $teacher;

    /**
     * A quiz with two STACK questions and configurations at both levels.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        if (!function_exists('quiz_add_quiz_question')) {
            $this->markTestSkipped('this Moodle version adds questions to a quiz differently');
        }
        $CFG->enableavailability = 1;

        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $questions = $generator->get_plugin_generator('core_question');
        $this->course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $category = $questions->create_question_category(['contextid' => \context_course::instance($this->course->id)->id]);

        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        $config = function (?int $qbeid, array $elements) {
            $DB = $GLOBALS['DB'];
            $DB->insert_record(config_manager::TABLE, (object)[
                'cmid'                => $this->cm->id,
                'questionbankentryid' => $qbeid,
                'allowed_elements'    => json_encode($elements),
                'usermodified'        => $this->teacher->id,
                'timecreated'         => 1000,
                'timemodified'        => 2000,
            ]);
        };
        foreach (['Q1', 'Q2'] as $name) {
            $question = $questions->create_question('stack', 'test3', ['category' => $category->id, 'name' => $name]);
            quiz_add_quiz_question($question->id, $quiz, 0, 1);
            $config(config_manager::resolve_qbeid((int) $question->id), ['_enabled' => true, 'marker' => $name]);
        }
        $config(null, ['_enabled' => true, 'marker' => 'quiz']);
        // A row whose entry the quiz does not use (left over from a removed slot).
        $config(987654, ['marker' => 'stale']);
    }

    /**
     * Configurations of a course module, keyed by question name ('quiz' for the default).
     *
     * @param int $cmid Course module id.
     * @return array name => [marker, usermodified]
     */
    private function configs_by_name(int $cmid): array {
        global $DB;
        $result = [];
        foreach ($DB->get_records(config_manager::TABLE, ['cmid' => $cmid]) as $row) {
            $name = 'quiz';
            if ($row->questionbankentryid !== null) {
                $name = $DB->get_field_sql(
                    "SELECT q.name
                       FROM {question_versions} qv
                       JOIN {question} q ON q.id = qv.questionid
                      WHERE qv.questionbankentryid = ?
                   ORDER BY qv.version DESC",
                    [$row->questionbankentryid],
                    IGNORE_MULTIPLE
                ) ?: 'unknown:' . $row->questionbankentryid;
            }
            $this->assertArrayNotHasKey($name, $result, "one row per scope ({$name})");
            $result[$name] = [json_decode($row->allowed_elements, true)['marker'] ?? null, (int) $row->usermodified];
        }
        ksort($result);
        return $result;
    }

    /**
     * Every question row of a course module points at an entry its quiz uses.
     *
     * @param int $cmid Course module id.
     * @return void
     */
    private function assert_rows_point_into_quiz(int $cmid): void {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $used = quiz_helper::load_quiz_qbeids((int) $cm->instance);
        $qbeids = $DB->get_fieldset_select(
            config_manager::TABLE,
            'questionbankentryid',
            'cmid = ? AND questionbankentryid IS NOT NULL',
            [$cmid]
        );
        foreach ($qbeids as $qbeid) {
            $this->assertArrayHasKey((int) $qbeid, $used, "entry {$qbeid} belongs to the restored quiz");
        }
    }

    /**
     * Back up the source course and restore it into a new course.
     *
     * @param bool $users Include users and user data.
     * @return int New course id.
     */
    private function backup_and_restore(bool $users): int {
        global $CFG, $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $backupid = $bc->get_backupid();
        $bc->destroy();

        $path = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);

        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED' . ($users ? 'U' : 'N'), $this->course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($users);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    /**
     * The quiz course module of a restored course.
     *
     * @param int $courseid Course id.
     * @return \stdClass Course module.
     */
    private function restored_cm(int $courseid): \stdClass {
        $cms = get_fast_modinfo($courseid)->get_instances_of('quiz');
        $this->assertCount(1, $cms);
        return get_coursemodule_from_id('quiz', reset($cms)->id, 0, false, MUST_EXIST);
    }

    /**
     * Restore into a new course with users: new cmid, new question entries, same configuration,
     * usermodified kept, the stale row left behind.
     *
     * @return void
     */
    public function test_course_restore_with_users(): void {
        global $DB;
        $before = $this->configs_by_name($this->cm->id);
        $sourceqbeids = $DB->get_fieldset_select(
            config_manager::TABLE,
            'questionbankentryid',
            'cmid = ? AND questionbankentryid IS NOT NULL',
            [$this->cm->id]
        );

        $cm = $this->restored_cm($this->backup_and_restore(true));

        $this->assertNotEquals($this->cm->id, $cm->id);
        $teacher = (int) $this->teacher->id;
        $this->assertSame([
            'Q1'   => ['Q1', $teacher],
            'Q2'   => ['Q2', $teacher],
            'quiz' => ['quiz', $teacher],
        ], $this->configs_by_name($cm->id));
        $this->assert_rows_point_into_quiz($cm->id);
        // New course, new question bank: none of the old entries is referenced.
        $newqbeids = $DB->get_fieldset_select(
            config_manager::TABLE,
            'questionbankentryid',
            'cmid = ? AND questionbankentryid IS NOT NULL',
            [$cm->id]
        );
        $this->assertSame([], array_intersect($sourceqbeids, $newqbeids));
        // The source is untouched.
        $this->assertSame($before, $this->configs_by_name($this->cm->id));
        $this->assertSame([], data_maintenance::find_orphans());
    }

    /**
     * Without user data the configuration travels, the personal reference does not.
     *
     * @return void
     */
    public function test_course_restore_without_users(): void {
        $cm = $this->restored_cm($this->backup_and_restore(false));
        $this->assertSame([
            'Q1'   => ['Q1', 0],
            'Q2'   => ['Q2', 0],
            'quiz' => ['quiz', 0],
        ], $this->configs_by_name($cm->id));
        $this->assert_rows_point_into_quiz($cm->id);
    }

    /**
     * Duplicating the quiz in its course copies the configuration to the new course module.
     *
     * @return void
     */
    public function test_duplicate_module(): void {
        // Moodle 5.2+ duplicates through the course format actions (duplicate_module() is
        // deprecated there); both run the same backup and restore.
        $actions = \core_courseformat\formatactions::cm($this->course);
        if (method_exists($actions, 'duplicate')) {
            $newcm = $actions->duplicate((int) $this->cm->id);
        } else {
            $newcm = duplicate_module($this->course, get_fast_modinfo($this->course)->get_cm($this->cm->id));
        }

        $this->assertNotEquals($this->cm->id, $newcm->id);
        $configs = $this->configs_by_name($newcm->id);
        $this->assertSame(['Q1', 'Q2', 'quiz'], array_keys($configs));
        foreach ($configs as $name => [$marker]) {
            $this->assertSame($name, $marker);
        }
        $this->assert_rows_point_into_quiz($newcm->id);
        // The original keeps its rows, including the stale one, untouched by the duplicate.
        $this->assertCount(4, $GLOBALS['DB']->get_records(config_manager::TABLE, ['cmid' => $this->cm->id]));
    }

    /**
     * Duplicating the whole course (core_course_external::duplicate_course, the "Copy course"
     * path) carries the configuration into the new course, on its own question entries.
     *
     * @return void
     */
    public function test_duplicate_course(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/externallib.php');

        $result = \core_course_external::duplicate_course(
            $this->course->id,
            'Duplicate',
            'DUPLICATE',
            $this->course->category,
            1,
            [['name' => 'users', 'value' => 1]]
        );
        $cm = $this->restored_cm((int) $result['id']);

        $teacher = (int) $this->teacher->id;
        $this->assertSame([
            'Q1'   => ['Q1', $teacher],
            'Q2'   => ['Q2', $teacher],
            'quiz' => ['quiz', $teacher],
        ], $this->configs_by_name($cm->id));
        $this->assert_rows_point_into_quiz($cm->id);
    }
}
