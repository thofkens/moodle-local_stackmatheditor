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
 * Privacy provider: contexts, export, and anonymisation instead of deletion (#55).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\privacy\provider
 */
final class privacy_provider_test extends \advanced_testcase {
    /** @var \stdClass First quiz course module. */
    private $cm1;

    /** @var \stdClass Second quiz course module. */
    private $cm2;

    /** @var \stdClass Teacher who configured things in both quizzes. */
    private $teacher;

    /** @var \stdClass Another teacher, configured only in the second quiz. */
    private $other;

    /**
     * Two quizzes with configurations of two users, plus one legacy record with cmid = 0.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->cm1 = get_coursemodule_from_instance(
            'quiz',
            $generator->create_module('quiz', ['course' => $course->id])->id
        );
        $this->cm2 = get_coursemodule_from_instance(
            'quiz',
            $generator->create_module('quiz', ['course' => $course->id])->id
        );
        $this->teacher = $generator->create_user();
        $this->other = $generator->create_user();

        $this->write_record($this->cm1->id, null, $this->teacher->id);
        $this->write_record($this->cm1->id, 4711, $this->teacher->id);
        $this->write_record($this->cm2->id, null, $this->teacher->id);
        $this->write_record($this->cm2->id, null, $this->other->id);
        $this->write_record(0, null, $this->teacher->id);
    }

    /**
     * Insert one configuration record.
     *
     * @param int $cmid Course module id (0 for the legacy scope).
     * @param int|null $qbeid Question bank entry id, or null for a quiz-level configuration.
     * @param int $userid User who last modified it.
     * @return int Record id.
     */
    private function write_record(int $cmid, ?int $qbeid, int $userid): int {
        global $DB;
        return (int) $DB->insert_record('local_stackmatheditor', (object)[
            'cmid'                => $cmid,
            'questionbankentryid' => $qbeid,
            'allowed_elements'    => '{"basic_operators":true}',
            'usermodified'        => $userid,
            'timecreated'         => time(),
            'timemodified'        => time(),
        ]);
    }

    /**
     * Value of usermodified for one scope.
     *
     * @param int $cmid Course module id.
     * @return int[] usermodified values, sorted.
     */
    private function usermodified_for(int $cmid): array {
        global $DB;
        $values = $DB->get_fieldset_select('local_stackmatheditor', 'usermodified', 'cmid = ?', [$cmid]);
        sort($values);
        return array_map('intval', $values);
    }

    /**
     * Both module contexts and the system context are reported for the teacher.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $contexts = provider::get_contexts_for_userid($this->teacher->id)->get_contextids();
        sort($contexts);
        $expected = [
            \context_module::instance($this->cm1->id)->id,
            \context_module::instance($this->cm2->id)->id,
            \context_system::instance()->id,
        ];
        sort($expected);
        $this->assertSame($expected, array_map('intval', $contexts));

        $contexts = provider::get_contexts_for_userid($this->other->id)->get_contextids();
        $this->assertSame([\context_module::instance($this->cm2->id)->id], array_map('intval', $contexts));
    }

    /**
     * Only the users of that context are reported; other context levels stay empty.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $list = new userlist(\context_module::instance($this->cm2->id), 'local_stackmatheditor');
        provider::get_users_in_context($list);
        $users = array_map('intval', $list->get_userids());
        sort($users);
        $expected = [(int) $this->teacher->id, (int) $this->other->id];
        sort($expected);
        $this->assertSame($expected, $users);

        $list = new userlist(\context_course::instance($this->cm1->course), 'local_stackmatheditor');
        provider::get_users_in_context($list);
        $this->assertSame([], $list->get_userids());
    }

    /**
     * The export contains the user's own configurations only, with their scope.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $context = \context_module::instance($this->cm1->id);
        provider::export_user_data(new approved_contextlist(
            $this->teacher,
            'local_stackmatheditor',
            [$context->id]
        ));

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('pluginname', 'local_stackmatheditor')]);
        $scopes = array_map(function ($item) {
            return $item->scope_key;
        }, $data->configurations);
        sort($scopes);
        $this->assertSame(['question', 'quiz'], $scopes);
        // The readable scope comes from the language pack, not from the code (#92).
        foreach ($data->configurations as $item) {
            $this->assertSame(get_string('privacy:scope_' . $item->scope_key, 'local_stackmatheditor'), $item->scope);
        }

        // The other teacher's configuration in cm2 is not part of this export.
        $context2 = \context_module::instance($this->cm2->id);
        provider::export_user_data(new approved_contextlist(
            $this->other,
            'local_stackmatheditor',
            [$context2->id]
        ));
        $data = writer::with_context($context2)->get_data([get_string('pluginname', 'local_stackmatheditor')]);
        $this->assertCount(1, $data->configurations);
        $this->assertSame((int) $this->other->id, $data->configurations[0]->usermodified);
    }

    /**
     * Deleting one user anonymises only that user's records, in the approved context only.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $before = $DB->count_records('local_stackmatheditor');

        provider::delete_data_for_user(new approved_contextlist(
            $this->teacher,
            'local_stackmatheditor',
            [\context_module::instance($this->cm2->id)->id]
        ));

        $this->assertSame($before, $DB->count_records('local_stackmatheditor'), 'No configuration may be deleted.');
        $this->assertSame([0, (int) $this->other->id], $this->usermodified_for($this->cm2->id));
        // Untouched contexts keep the reference.
        $this->assertSame(
            [(int) $this->teacher->id, (int) $this->teacher->id],
            $this->usermodified_for($this->cm1->id)
        );
        $this->assertSame([(int) $this->teacher->id], $this->usermodified_for(0));
    }

    /**
     * Deleting a whole context anonymises every user there, keeping the configurations.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $before = $DB->count_records('local_stackmatheditor');

        provider::delete_data_for_all_users_in_context(\context_module::instance($this->cm2->id));
        $this->assertSame($before, $DB->count_records('local_stackmatheditor'));
        $this->assertSame([0, 0], $this->usermodified_for($this->cm2->id));

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertSame([0], $this->usermodified_for(0));

        // A context level this plugin does not use changes nothing.
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->cm1->course));
        $this->assertSame(
            [(int) $this->teacher->id, (int) $this->teacher->id],
            $this->usermodified_for($this->cm1->id)
        );
    }

    /**
     * Bulk deletion anonymises the approved users in that context only.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $before = $DB->count_records('local_stackmatheditor');

        provider::delete_data_for_users(new approved_userlist(
            \context_module::instance($this->cm2->id),
            'local_stackmatheditor',
            [$this->teacher->id, $this->other->id]
        ));

        $this->assertSame($before, $DB->count_records('local_stackmatheditor'));
        $this->assertSame([0, 0], $this->usermodified_for($this->cm2->id));
        $this->assertSame(
            [(int) $this->teacher->id, (int) $this->teacher->id],
            $this->usermodified_for($this->cm1->id)
        );
    }
}
