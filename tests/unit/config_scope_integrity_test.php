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
 * One configuration row per scope, deterministic reads and repair of old duplicates (#87).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\config_manager
 * @covers     \local_stackmatheditor\data_maintenance
 */
final class config_scope_integrity_test extends \advanced_testcase {
    /**
     * Insert one raw row, bypassing the application write path (simulates historical data).
     *
     * @param int $cmid Course module id.
     * @param int|null $qbeid Question bank entry id or null.
     * @param array $config Stored configuration.
     * @param int $timemodified Modification time.
     * @return int Record id.
     */
    private function raw(int $cmid, ?int $qbeid, array $config, int $timemodified): int {
        global $DB;
        return (int) $DB->insert_record(config_manager::TABLE, (object)[
            'cmid'                => $cmid,
            'questionbankentryid' => $qbeid,
            'allowed_elements'    => json_encode($config),
            'usermodified'        => 2,
            'timecreated'         => $timemodified,
            'timemodified'        => $timemodified,
        ]);
    }

    /**
     * Repeated saves keep exactly one row per question scope and per quiz-default scope.
     *
     * @return void
     */
    public function test_saves_keep_one_row_per_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        for ($i = 0; $i < 3; $i++) {
            config_manager::save_config(500, 77, ['_enabled' => (bool) ($i % 2)]);
            config_manager::save_quiz_default(500, ['_enabled' => (bool) ($i % 2)]);
        }
        $this->assertSame(1, $DB->count_records(config_manager::TABLE, ['cmid' => 500, 'questionbankentryid' => 77]));
        $this->assertSame(1, $DB->count_records_select(
            config_manager::TABLE,
            'cmid = ? AND questionbankentryid IS NULL',
            [500]
        ));
        $this->assertSame([], data_maintenance::find_duplicate_scopes());
    }

    /**
     * Two rows with the same timemodified: single and batch read pick the higher id, and so does
     * the repair, so reading before and after the repair gives the same configuration.
     *
     * @return void
     */
    public function test_tie_is_broken_by_id_everywhere(): void {
        global $DB;
        $this->resetAfterTest();
        $time = time() - 100;
        $this->raw(600, 88, ['basic_operators' => false, 'onlyold' => true], $time);
        $newer = $this->raw(600, 88, ['basic_operators' => true], $time);

        $single = config_manager::get_config(600, 88);
        $batch = config_manager::get_configs(600, [88])[88];
        $this->assertTrue($single['basic_operators']);
        $this->assertSame($single, $batch);
        // The older duplicate is not merged into the read.
        $this->assertArrayNotHasKey('onlyold', $batch);

        $this->assertSame(1, data_maintenance::repair_duplicates());
        $this->assertSame([$newer], array_map('intval', array_keys(
            $DB->get_records(config_manager::TABLE, ['cmid' => 600, 'questionbankentryid' => 88])
        )));
        $this->assertSame($single, config_manager::get_config(600, 88));
    }

    /**
     * Duplicate quiz defaults (NULL qbeid) are found and repaired too; other scopes stay.
     *
     * @return void
     */
    public function test_repair_handles_null_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $this->raw(700, null, ['_enabled' => false], 100);
        $keep = $this->raw(700, null, ['_enabled' => true], 200);
        $this->raw(700, 1, ['_enabled' => true], 100);

        $scopes = data_maintenance::find_duplicate_scopes();
        $this->assertCount(1, $scopes);
        $this->assertNull($scopes[0]->questionbankentryid);
        $this->assertSame(2, $scopes[0]->records);

        $this->assertSame(1, data_maintenance::repair_duplicates());
        $this->assertTrue($DB->record_exists(config_manager::TABLE, ['id' => $keep]));
        $this->assertSame(2, $DB->count_records(config_manager::TABLE, ['cmid' => 700]));
    }

    /**
     * A save onto a scope with historical duplicates updates the row reads return and drops the rest.
     *
     * @return void
     */
    public function test_save_collapses_historical_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->raw(800, 9, ['a' => 1], 100);
        $winner = $this->raw(800, 9, ['a' => 2], 100);

        config_manager::save_config(800, 9, ['_enabled' => true]);

        $rows = $DB->get_records(config_manager::TABLE, ['cmid' => 800, 'questionbankentryid' => 9]);
        $this->assertSame([$winner], array_map('intval', array_keys($rows)));
    }

    /**
     * A writer waits for the scope lock and gives up with an exception instead of writing a
     * second row while another writer holds it.
     *
     * Runs with the database lock factory: it is the factory available on every database, and,
     * unlike PostgreSQL advisory locks, it is not re-entrant within one process, so the held lock
     * blocks the writer in this single-process test exactly as it blocks a parallel request.
     *
     * @return void
     */
    public function test_writer_respects_scope_lock(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        set_config('locktimeout', 1, 'local_stackmatheditor');

        $holder = \core\lock\lock_config::get_lock_factory('local_stackmatheditor');
        $lock = $holder->get_lock(config_manager::scope_lock_key(900, 5), 0);
        $this->assertNotFalse($lock);
        try {
            config_manager::save_config(900, 5, ['_enabled' => true]);
            $this->fail('The writer must not pass a held scope lock.');
        } catch (\moodle_exception $e) {
            $this->assertSame('locktimeout', $e->errorcode);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, $DB->count_records(config_manager::TABLE, ['cmid' => 900]));

        // Once the lock is free the same write goes through.
        config_manager::save_config(900, 5, ['_enabled' => true]);
        $this->assertSame(1, $DB->count_records(config_manager::TABLE, ['cmid' => 900]));
    }
}
