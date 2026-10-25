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
 * Backup, restore and duplication of an adaptive quiz carry its activity configuration.
 *
 * An adaptive quiz is configured per activity only; it has no slots, so the restore keeps the
 * activity row and drops any question row a backup might carry. Runs where mod_adaptivequiz is
 * installed (the main CI installs it in its Moodle 4.5 rows); elsewhere it skips.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_stackmatheditor_plugin
 * @covers     \restore_local_stackmatheditor_plugin
 */
final class backup_restore_adaptivequiz_test extends \advanced_testcase {
    /** @var \stdClass Source course. */
    private \stdClass $course;

    /** @var \stdClass Source adaptive quiz course module. */
    private \stdClass $cm;

    /** @var \stdClass Teacher who made the configuration. */
    private \stdClass $teacher;

    /**
     * An adaptive quiz with a STACK question in its pool and an activity configuration.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        if (!file_exists($CFG->dirroot . '/mod/adaptivequiz/version.php')) {
            $this->markTestSkipped('mod_adaptivequiz is not installed on this site');
        }
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $questions = $generator->get_plugin_generator('core_question');
        $this->course = $generator->create_course();
        $category = $questions->create_question_category(['contextid' => \context_course::instance($this->course->id)->id]);
        $question = $questions->create_question('stack', 'test3', ['category' => $category->id]);
        $instance = $generator->create_module('adaptivequiz', [
            'course' => $this->course->id,
            'questionpool' => [(int) $category->id],
        ]);
        $this->cm = get_coursemodule_from_instance('adaptivequiz', $instance->id);

        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        $insert = function (?int $qbeid, string $marker) use ($DB) {
            $DB->insert_record(config_manager::TABLE, (object) [
                'cmid'                => $this->cm->id,
                'questionbankentryid' => $qbeid,
                'allowed_elements'    => json_encode(['_enabled' => true, 'marker' => $marker]),
                'usermodified'        => $this->teacher->id,
                'timecreated'         => 1000,
                'timemodified'        => 2000,
            ]);
        };
        $insert(null, 'activity');
        // An adaptive quiz is configured per activity only. A question row cannot come from the
        // configuration page; if one is in the table anyway, it must not travel.
        $insert(config_manager::resolve_qbeid((int) $question->id), 'question');
    }

    /**
     * The configuration rows of a course module.
     *
     * @param int $cmid Course module id.
     * @return array [marker, questionbankentryid, usermodified] per row, ordered by marker
     */
    private function rows(int $cmid): array {
        global $DB;
        $result = [];
        foreach ($DB->get_records(config_manager::TABLE, ['cmid' => $cmid]) as $row) {
            $result[] = [
                json_decode($row->allowed_elements, true)['marker'] ?? null,
                $row->questionbankentryid === null ? null : (int) $row->questionbankentryid,
                (int) $row->usermodified,
            ];
        }
        sort($result);
        return $result;
    }

    /**
     * Back up the source course and restore it into a new course.
     *
     * @param bool $users Include users and user data.
     * @return \stdClass The restored adaptive quiz course module.
     */
    private function backup_and_restore(bool $users): \stdClass {
        global $USER;
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

        $courseid = \restore_dbops::create_new_course('Restored', 'AQRESTORED' . ($users ? 'U' : 'N'), $this->course->category);
        $rc = new \restore_controller(
            $backupid,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($users);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $cms = get_fast_modinfo($courseid)->get_instances_of('adaptivequiz');
        $this->assertCount(1, $cms);
        return get_coursemodule_from_id('adaptivequiz', reset($cms)->id, 0, false, MUST_EXIST);
    }

    /**
     * With users: a new course module, the activity configuration with its author, no question
     * row.
     *
     * @return void
     */
    public function test_course_restore_with_users(): void {
        $before = $this->rows($this->cm->id);
        $cm = $this->backup_and_restore(true);

        $this->assertNotEquals($this->cm->id, $cm->id);
        $this->assertSame([['activity', null, (int) $this->teacher->id]], $this->rows($cm->id));
        $this->assertTrue(config_manager::get_effective_enabled((int) $cm->id));
        // The source is untouched.
        $this->assertSame($before, $this->rows($this->cm->id));
        $this->assertSame([], data_maintenance::find_orphans());
        $this->assertSame([], data_maintenance::find_duplicate_scopes());
    }

    /**
     * Without users: the configuration travels, the personal reference does not.
     *
     * @return void
     */
    public function test_course_restore_without_users(): void {
        $cm = $this->backup_and_restore(false);
        $this->assertSame([['activity', null, 0]], $this->rows($cm->id));
    }

    /**
     * Duplicating the activity in its course copies the activity configuration.
     *
     * @return void
     */
    public function test_duplicate_module(): void {
        $actions = \core_courseformat\formatactions::cm($this->course);
        if (method_exists($actions, 'duplicate')) {
            $newcm = $actions->duplicate((int) $this->cm->id);
        } else {
            $newcm = duplicate_module($this->course, get_fast_modinfo($this->course)->get_cm($this->cm->id));
        }

        $this->assertNotEquals($this->cm->id, $newcm->id);
        // Duplication is a backup without user data, so the copy has no author.
        $this->assertSame([['activity', null, 0]], $this->rows((int) $newcm->id));
        // The original keeps both of its rows.
        $this->assertCount(2, $this->rows($this->cm->id));
    }
}
