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
 * The upgrade step to 2026100800: orphans and duplicate scopes go, everything else stays, and a
 * second run changes nothing (#85, #87).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_stackmatheditor_upgrade
 */
final class upgrade_step_test extends \advanced_testcase {
    /**
     * Insert one raw row.
     *
     * @param int $cmid Course module id.
     * @param int|null $qbeid Question bank entry id or null.
     * @param int $time timemodified.
     * @return int Record id.
     */
    private function row(int $cmid, ?int $qbeid, int $time): int {
        global $DB;
        return (int) $DB->insert_record(config_manager::TABLE, (object)[
            'cmid' => $cmid,
            'questionbankentryid' => $qbeid,
            'allowed_elements' => '{"_enabled":true}',
            'usermodified' => 2,
            'timecreated' => $time,
            'timemodified' => $time,
        ]);
    }

    /**
     * Data a previous release leaves behind is repaired; the result equals a fresh install's
     * invariants, and running the step again is harmless.
     *
     * @return void
     */
    public function test_upgrade_repairs_and_is_idempotent(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/stackmatheditor/db/upgrade.php');
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;

        $default = $this->row($cmid, null, 100);
        $olddefault = $this->row($cmid, null, 50);
        $tieloser = $this->row($cmid, 11, 200);
        $tiewinner = $this->row($cmid, 11, 200);
        $global = $this->row(0, 12, 100);
        $globaldupe = $this->row(0, 12, 90);
        $orphan = $this->row(987654, null, 100);

        // The site is on the previous release; the step's savepoint moves it to 2026100800.
        set_config('version', 2026100700, 'local_stackmatheditor');
        $this->assertTrue(xmldb_local_stackmatheditor_upgrade(2026100700));
        $this->assertSame('2026100800', (string) get_config('local_stackmatheditor', 'version'));

        $remaining = array_map('intval', $DB->get_fieldset_select(config_manager::TABLE, 'id', '1 = 1'));
        sort($remaining);
        $expected = [$default, $tiewinner, $global];
        sort($expected);
        $this->assertSame($expected, $remaining);
        $this->assertSame([], data_maintenance::find_orphans());
        $this->assertSame([], data_maintenance::find_duplicate_scopes());
        $this->assertFalse($DB->record_exists(config_manager::TABLE, ['id' => $olddefault]));
        $this->assertFalse($DB->record_exists(config_manager::TABLE, ['id' => $tieloser]));
        $this->assertFalse($DB->record_exists(config_manager::TABLE, ['id' => $globaldupe]));
        $this->assertFalse($DB->record_exists(config_manager::TABLE, ['id' => $orphan]));

        // A second run (or an upgrade from a version that never had the problem) changes nothing.
        set_config('version', 2026100700, 'local_stackmatheditor');
        $this->assertTrue(xmldb_local_stackmatheditor_upgrade(2026100700));
        $again = array_map('intval', $DB->get_fieldset_select(config_manager::TABLE, 'id', '1 = 1'));
        sort($again);
        $this->assertSame($expected, $again);
        $this->assertTrue(xmldb_local_stackmatheditor_upgrade(2026100800));
    }
}
