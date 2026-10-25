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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_stackmatheditor\privacy\provider;

/**
 * Configuration rows follow the course-module lifecycle and stay reachable for privacy (#85).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\observer
 * @covers     \local_stackmatheditor\data_maintenance
 * @covers     \local_stackmatheditor\privacy\provider
 */
final class config_lifecycle_test extends \advanced_testcase {
    /**
     * Insert one configuration record.
     *
     * @param int $cmid Course module id.
     * @param int|null $qbeid Question bank entry id or null.
     * @param int $userid usermodified.
     * @return int Record id.
     */
    private function write_record(int $cmid, ?int $qbeid, int $userid): int {
        global $DB;
        return (int) $DB->insert_record(config_manager::TABLE, (object)[
            'cmid'                => $cmid,
            'questionbankentryid' => $qbeid,
            'allowed_elements'    => '{"basic_operators":true}',
            'usermodified'        => $userid,
            'timecreated'         => time(),
            'timemodified'        => time(),
        ]);
    }

    /**
     * A quiz in a course.
     *
     * @param \stdClass $course Course.
     * @return \stdClass Course module.
     */
    private function create_quiz(\stdClass $course): \stdClass {
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        return get_coursemodule_from_instance('quiz', $quiz->id);
    }

    /**
     * Deleting one quiz removes its rows and only its rows.
     *
     * @return void
     */
    public function test_course_module_delete_removes_rows(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        set_config('coursebinenable', 0, 'tool_recyclebin');

        $course = $this->getDataGenerator()->create_course();
        $cm1 = $this->create_quiz($course);
        $cm2 = $this->create_quiz($course);
        $user = $this->getDataGenerator()->create_user();
        $this->write_record($cm1->id, null, $user->id);
        $this->write_record($cm1->id, 11, $user->id);
        $this->write_record($cm2->id, 11, $user->id);
        $this->write_record(0, null, $user->id);

        $before = provider::get_contexts_for_userid($user->id)->get_contextids();
        $this->assertContainsEquals(\context_module::instance($cm1->id)->id, $before);

        // Moodle 5.2+ deletes through the course format actions (course_delete_module() is
        // deprecated there); both end in the same core deletion and event.
        $actions = \core_courseformat\formatactions::cm($course);
        if (method_exists($actions, 'delete')) {
            $actions->delete((int) $cm1->id);
        } else {
            course_delete_module($cm1->id);
        }

        $this->assertSame(0, $DB->count_records(config_manager::TABLE, ['cmid' => $cm1->id]));
        $this->assertSame(1, $DB->count_records(config_manager::TABLE, ['cmid' => $cm2->id]));
        $this->assertSame(1, $DB->count_records(config_manager::TABLE, ['cmid' => 0]));
        $this->assertSame([], data_maintenance::find_orphans());
    }

    /**
     * Deleting a course (no per-module event) removes the rows of all its course modules.
     *
     * @return void
     */
    public function test_course_delete_removes_rows(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('categorybinenable', 0, 'tool_recyclebin');

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $cm1 = $this->create_quiz($course);
        $cm2 = $this->create_quiz($course);
        $keep = $this->create_quiz($other);
        $user = $this->getDataGenerator()->create_user();
        $this->write_record($cm1->id, null, $user->id);
        $this->write_record($cm2->id, 5, $user->id);
        $this->write_record($keep->id, 5, $user->id);

        delete_course($course, false);

        $this->assertSame(0, $DB->count_records_select(
            config_manager::TABLE,
            'cmid IN (?, ?)',
            [$cm1->id, $cm2->id]
        ));
        $this->assertSame(1, $DB->count_records(config_manager::TABLE, ['cmid' => $keep->id]));
    }

    /**
     * A historical orphan is reported in the user context, exported, and deleted for the user.
     *
     * @return void
     */
    public function test_orphan_is_reachable_by_privacy(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->create_quiz($course);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->write_record($cm->id, null, $user->id);
        // Rows of a course module that no longer exists (created before the observer existed).
        $orphanid = $this->write_record(999999, 7, $user->id);
        $this->write_record(999999, null, $other->id);

        $this->assertCount(2, data_maintenance::find_orphans());
        $usercontext = \context_user::instance($user->id);
        $contexts = provider::get_contexts_for_userid($user->id)->get_contextids();
        $this->assertContainsEquals($usercontext->id, $contexts);
        $this->assertContainsEquals(\context_module::instance($cm->id)->id, $contexts);

        $userlist = new userlist($usercontext, 'local_stackmatheditor');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $user->id], array_map('intval', $userlist->get_userids()));

        $approved = new approved_contextlist($user, 'local_stackmatheditor', [$usercontext->id]);
        provider::export_user_data($approved);
        $data = writer::with_context($usercontext)->get_data([get_string('pluginname', 'local_stackmatheditor')]);
        $this->assertCount(1, $data->configurations);
        $this->assertSame(999999, $data->configurations[0]->cmid);

        provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists(config_manager::TABLE, ['id' => $orphanid]));
        // The other user's orphan and the live configuration are untouched.
        $this->assertSame(1, $DB->count_records(config_manager::TABLE, ['cmid' => 999999]));
        $this->assertSame(1, $DB->count_records(config_manager::TABLE, ['cmid' => $cm->id]));

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($other->id),
            'local_stackmatheditor',
            [$other->id]
        ));
        $this->assertSame([], data_maintenance::find_orphans());
    }

    /**
     * Deleting a user context's data in bulk removes only that user's orphans.
     *
     * @return void
     */
    public function test_delete_all_users_in_user_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->write_record(999998, null, $user->id);
        $this->write_record(999998, 3, $other->id);

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));

        $remaining = data_maintenance::find_orphans();
        $this->assertCount(1, $remaining);
        $this->assertSame((int) $other->id, (int) reset($remaining)->usermodified);
    }

    /**
     * The repair removes historical orphans and leaves live and legacy rows alone.
     *
     * @return void
     */
    public function test_delete_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->create_quiz($course);
        $this->write_record($cm->id, null, 2);
        $this->write_record(0, 4, 2);
        $this->write_record(999997, null, 2);
        $this->write_record(999997, 4, 2);

        $this->assertSame(2, data_maintenance::delete_orphans());
        $this->assertSame(0, data_maintenance::delete_orphans());
        $this->assertSame(2, $DB->count_records(config_manager::TABLE));
    }

    /**
     * The observers are registered in db/events.php.
     *
     * @return void
     */
    public function test_observers_registered(): void {
        $observers = [];
        foreach (\core\event\manager::get_all_observers() as $event => $list) {
            foreach ($list as $observer) {
                if ($observer->plugintype === 'local' && $observer->plugin === 'stackmatheditor') {
                    $observers[] = ltrim($event, '\\');
                }
            }
        }
        sort($observers);
        $this->assertSame([
            'core\event\course_content_deleted',
            'core\event\course_module_deleted',
        ], $observers);
    }
}
