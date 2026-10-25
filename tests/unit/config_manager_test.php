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

namespace local_stackmatheditor\unit;

use advanced_testcase;
use local_stackmatheditor\config_manager;
use local_stackmatheditor\definitions;

/**
 * Unit tests for local_stackmatheditor\config_manager.
 *
 * Tests that can run without DB use data providers and in-memory logic.
 * Tests needing DB are tagged with "local_stackmatheditor_db" group and
 * require Moodle's test DB infrastructure (run via grunt/phpunit).
 *
 * @package    local_stackmatheditor
 * @covers     \local_stackmatheditor\config_manager
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_manager_test extends advanced_testcase {
    /**
     * ensure_qbeid with a concrete qbeid returns it unchanged.
     */
    public function test_ensure_qbeid_passthrough(): void {
        $result = config_manager::ensure_qbeid(42, 0);
        $this->assertSame(42, $result);
    }

    /**
     * ensure_qbeid with qbeid=0 and no questionid returns null.
     */
    public function test_ensure_qbeid_both_zero(): void {
        $result = config_manager::ensure_qbeid(0, 0);
        $this->assertNull($result);
    }


    /**
     * Test get_instance_enabled_mode() returns the correct integer for each stored value.
     *
     * @dataProvider enabled_mode_provider
     * @param string $stored  Stored config value.
     * @param int    $expected Expected return value.
     * @return void
     */
    public function test_get_instance_enabled_mode(string $stored, int $expected): void {
        $this->resetAfterTest();
        set_config('enabled', $stored, 'local_stackmatheditor');
        $mode = config_manager::get_instance_enabled_mode();
        $this->assertSame($expected, $mode);
    }

    /**
     * Data provider for test_get_instance_enabled_mode.
     *
     * @return array<string, array{string, int}>
     */
    public static function enabled_mode_provider(): array {
        return [
            'mode 0 (disabled)' => ['0', 0],
            'mode 1 (enabled)'  => ['1', 1],
            'mode 2 (default off, override)' => ['2', 2],
            'mode 3 (default on, override)'  => ['3', 3],
            'invalid negative'  => ['-1', 1], // Safe fallback.
            'invalid high'      => ['99', 1], // Safe fallback.
        ];
    }


    /**
     * Implicit multiplication is STACK's decision, not the editor's (#65).
     *
     * The editor used to keep a setting of its own. It no longer does: whatever an older version
     * stored, the converter hands STACK what was typed.
     *
     * @return void
     */
    public function test_variable_mode_is_always_stack(): void {
        $this->resetAfterTest();

        $this->assertSame(
            definitions::IMPLICIT_STACK,
            config_manager::get_instance_variable_mode()
        );

        foreach (['explicit_single', 'explicit_multi', 'space_single', 'nonsense', ''] as $stored) {
            set_config('variablemode', $stored, 'local_stackmatheditor');
            $this->assertSame(
                definitions::IMPLICIT_STACK,
                config_manager::get_instance_variable_mode(),
                "a stored value ($stored) must not bring the setting back"
            );
        }

        $base = config_manager::get_instance_base_config();
        $this->assertSame(definitions::IMPLICIT_STACK, $base['_variableMode']);
    }


    /**
     * Instance defaults contain all group keys as boolean values.
     */
    public function test_instance_defaults_structure(): void {
        $this->resetAfterTest();
        $defaults = config_manager::get_instance_defaults();
        $groups   = definitions::get_element_groups();

        $this->assertSame(
            array_keys($groups),
            array_keys($defaults),
            'Instance defaults must cover exactly the same keys as element groups'
        );

        foreach ($defaults as $key => $val) {
            $this->assertIsBool($val, "Default for '$key' must be bool");
        }
    }

    /**
     * When default_groups config is set, it overrides the hardcoded defaults.
     *
     * Uses only groups that are guaranteed to exist in definitions to avoid
     * test failures when optional groups (e.g. logic) are disabled.
     */
    public function test_instance_defaults_from_new_format(): void {
        $this->resetAfterTest();

        $groups = definitions::get_element_groups();

        // Pick one group that is enabled by default and one that is disabled.
        // Fall back gracefully if a group is absent (e.g. commented out).
        $enabled  = isset($groups['brackets']) ? 'brackets' : array_key_first($groups);
        $disabled = null;
        foreach ($groups as $key => $group) {
            if (!$group['default_enabled'] && $key !== $enabled) {
                $disabled = $key;
                break;
            }
        }

        set_config('default_groups', $enabled, 'local_stackmatheditor');
        $defaults = config_manager::get_instance_defaults();

        $this->assertTrue($defaults[$enabled], "$enabled must be enabled");

        if ($disabled !== null) {
            $this->assertFalse($defaults[$disabled], "$disabled must be disabled");
        }
    }


    /**
     * Test get_effective_enabled() returns the correct bool for each instance mode.
     *
     * @dataProvider effective_enabled_provider
     * @param int  $mode     Instance enabled mode (0-3).
     * @param bool $expected Expected return value.
     * @return void
     */
    public function test_get_effective_enabled_instance_only(
        int $mode,
        bool $expected
    ): void {
        $this->resetAfterTest();
        set_config('enabled', (string) $mode, 'local_stackmatheditor');
        // No cmid/qbeid → instance-level decision only.
        $result = config_manager::get_effective_enabled(0, 0);
        $this->assertSame(
            $expected,
            $result,
            "Mode $mode without context should return " . ($expected ? 'true' : 'false')
        );
    }

    /**
     * Data provider for test_get_effective_enabled_instance_only.
     *
     * @return array<string, array{int, bool}>
     */
    public static function effective_enabled_provider(): array {
        return [
            'mode 0 always off' => [0, false],
            'mode 1 always on'  => [1, true],
            'mode 2 default off with no override' => [2, false],
            'mode 3 default on with no override'  => [3, true],
        ];
    }


    /**
     * save_config() and get_config() round-trip.
     *
     * "local_stackmatheditor_db" group
     */
    public function test_save_and_get_config_roundtrip(): void {
        $this->resetAfterTest();

        // We need a real quiz CM to test with — use a dummy cmid.
        // The table has no FK constraint on cmid.
        $cmid  = 999901;
        $qbeid = 888801;

        $elements = [
            'basic_operators'  => true,
            'trigonometry'     => false,
            '_variableMode'    => definitions::VAR_MULTI,
        ];

        config_manager::save_config($cmid, $qbeid, $elements);
        $loaded = config_manager::get_config($cmid, $qbeid);

        $this->assertTrue($loaded['basic_operators'], 'basic_operators must be true after save');
        $this->assertFalse($loaded['trigonometry'], 'trigonometry must be false after save');
        $this->assertSame(
            definitions::VAR_MULTI,
            $loaded['_variableMode'],
            '_variableMode must be persisted'
        );
    }

    /**
     * save_quiz_default() stores a NULL-qbeid record.
     *
     * "local_stackmatheditor_db" group
     */
    public function test_save_and_get_quiz_default(): void {
        $this->resetAfterTest();
        $cmid = 999902;

        $elements = ['logic' => true, '_enabled' => false];
        config_manager::save_quiz_default($cmid, $elements);

        $loaded = config_manager::get_quiz_default($cmid);
        $this->assertNotNull($loaded, 'Quiz default must exist after save');
        $this->assertTrue($loaded['logic'], 'logic must be true');
        $this->assertFalse($loaded['_enabled'], '_enabled must be false');
    }

    /**
     * get_config() follows the priority chain:
     * question-level → quiz-level default → instance default.
     *
     * "local_stackmatheditor_db" group
     */
    public function test_config_priority_chain(): void {
        $this->resetAfterTest();
        $cmid  = 999903;
        $qbeid = 888803;

        // Instance default: all off.
        set_config('default_groups', '', 'local_stackmatheditor');

        // Quiz default: brackets on.
        config_manager::save_quiz_default($cmid, ['brackets' => true]);

        // No question-level config yet → should get quiz default.
        $config = config_manager::get_config($cmid, $qbeid);
        $this->assertTrue($config['brackets'], 'brackets must be inherited from quiz default');

        // Question-level: override brackets off.
        config_manager::save_config($cmid, $qbeid, ['brackets' => false]);
        $config = config_manager::get_config($cmid, $qbeid);
        $this->assertFalse($config['brackets'], 'brackets must be overridden at question level');
    }

    /**
     * save_config() cleans up duplicate records (idempotent).
     *
     * "local_stackmatheditor_db" group
     */
    public function test_save_config_deduplication(): void {
        global $DB;
        $this->resetAfterTest();
        $cmid  = 999904;
        $qbeid = 888804;

        config_manager::save_config($cmid, $qbeid, ['trigonometry' => true]);
        config_manager::save_config($cmid, $qbeid, ['trigonometry' => false]);

        $count = $DB->count_records(
            'local_stackmatheditor',
            ['cmid' => $cmid, 'questionbankentryid' => $qbeid]
        );
        $this->assertSame(
            1,
            $count,
            'Only one record must exist after double-save (deduplication)'
        );
    }

    /**
     * get_effective_enabled() honours _enabled from quiz-level in mode 2.
     *
     * "local_stackmatheditor_db" group
     */
    public function test_effective_enabled_quiz_override_mode2(): void {
        $this->resetAfterTest();
        set_config('enabled', '2', 'local_stackmatheditor');

        $cmid = 999905;
        // Mode 2 = default off. Quiz turns it on via _enabled=true.
        config_manager::save_quiz_default($cmid, ['_enabled' => true]);

        $result = config_manager::get_effective_enabled($cmid);
        $this->assertTrue($result, 'Quiz-level _enabled=true must activate editor in mode 2');
    }

    /**
     * get_effective_enabled() honours _enabled from question-level in mode 3.
     *
     * "local_stackmatheditor_db" group
     */
    public function test_effective_enabled_question_override_mode3(): void {
        $this->resetAfterTest();
        set_config('enabled', '3', 'local_stackmatheditor');

        $cmid  = 999906;
        $qbeid = 888806;
        // Mode 3 = default on. Question turns it off via _enabled=false.
        config_manager::save_config($cmid, $qbeid, ['_enabled' => false]);

        $result = config_manager::get_effective_enabled($cmid, $qbeid);
        $this->assertFalse($result, 'Question-level _enabled=false must deactivate editor in mode 3');
    }
}
