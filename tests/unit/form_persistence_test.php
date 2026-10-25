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
 * What a configuration form stores, for inherit, on and off (#81, #73).
 *
 * The form always submits a value, pre-filled with what the level inherits. These cases pin down
 * which submissions become stored overrides and which keep the level inheriting - the difference
 * between "saved the toolbar groups" and "switched the editor on for this question".
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\config_manager::activation_to_store
 * @covers     \local_stackmatheditor\config_manager::student_toggle_to_store
 * @covers     \local_stackmatheditor\config_manager::get_own_config
 */
final class form_persistence_test extends \advanced_testcase {
    /**
     * Activation: what is stored for each combination.
     *
     * @return array
     */
    public static function activation_provider(): array {
        return [
            // Mode, existing, inherited, submitted, expected.
            'never saved, unchanged, mode 2'      => [2, null, false, false, null],
            'never saved, unchanged, mode 3'      => [3, null, true, true, null],
            'never saved, switched on, mode 2'    => [2, null, false, true, true],
            'never saved, switched off, mode 3'   => [3, null, true, false, false],
            'stored on stays on'                  => [2, true, false, true, true],
            'stored off stays off'                => [3, false, true, false, false],
            'stored on, explicitly switched off'  => [2, true, false, false, false],
            'stored off, now equal to inherited'  => [2, false, false, false, false],
            'field absent keeps what was stored'  => [2, true, false, null, true],
            'field absent, nothing stored'        => [3, null, true, null, null],
            'mode 0 stores nothing'               => [0, null, false, true, null],
            'mode 1 stores nothing'               => [1, null, true, false, null],
        ];
    }

    /**
     * Only a submission that says something becomes an override.
     *
     * @dataProvider activation_provider
     * @param int $mode Instance mode.
     * @param bool|null $existing Stored value.
     * @param bool $inherited Inherited value.
     * @param bool|null $submitted Submitted value.
     * @param bool|null $expected Value to store.
     * @return void
     */
    public function test_activation(
        int $mode,
        ?bool $existing,
        bool $inherited,
        ?bool $submitted,
        ?bool $expected
    ): void {
        $this->assertSame(
            $expected,
            config_manager::activation_to_store($mode, $existing, $inherited, $submitted)
        );
    }

    /**
     * Saving other settings does not change how a question inherits.
     *
     * The scenario behind #81's item: a question that has never been configured follows its
     * quiz. Saving its toolbar groups must leave it following the quiz, so that a later change
     * at quiz level still reaches it.
     *
     * @return void
     */
    public function test_saving_the_groups_keeps_the_question_inheriting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 2, 'local_stackmatheditor');

        config_manager::save_quiz_default(55, ['_enabled' => 1]);

        // The form shows "on", inherited; the teacher only changes groups and saves.
        $store = config_manager::activation_to_store(2, null, true, true);
        $elements = ['basic_operators' => 1];
        if ($store !== null) {
            $elements['_enabled'] = $store;
        }
        config_manager::save_config(55, 66, $elements);

        // The quiz is switched off afterwards: the question follows, because it never had a
        // value of its own.
        config_manager::save_quiz_default(55, ['_enabled' => 0]);
        $this->assertFalse(config_manager::get_effective_enabled(55, 66));
    }

    /**
     * A level's own values are not the merged ones (#81).
     *
     * The case above hands activation_to_store() a null by hand. The configuration page handed
     * it the merged configuration instead, in which a question that only inherits "on" has
     * "_enabled" set - and froze it on the next save. What the page has to ask is this.
     *
     * @return void
     */
    public function test_a_level_knows_what_it_stored_itself(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 2, 'local_stackmatheditor');

        $this->assertNull(config_manager::get_own_config(55), 'no quiz record yet');
        $this->assertNull(config_manager::get_own_config(55, 66), 'no question record yet');

        config_manager::save_quiz_default(55, ['_enabled' => 1, '_maxStructuredDimension' => 7]);

        // What applies to the question includes the quiz values ...
        $merged = config_manager::get_config(55, 66);
        $this->assertTrue((bool) $merged['_enabled']);
        // ... what the question stored itself does not.
        $this->assertNull(config_manager::get_own_config(55, 66));
        $this->assertSame(7, config_manager::get_own_config(55)['_maxStructuredDimension']);

        config_manager::save_config(55, 66, ['basic_operators' => 1]);
        $own = config_manager::get_own_config(55, 66);
        $this->assertArrayNotHasKey('_enabled', $own);
        $this->assertArrayNotHasKey('_maxStructuredDimension', $own);

        // Another quiz is another context.
        $this->assertNull(config_manager::get_own_config(56, 66));
    }

    /**
     * The configuration page decides what to store from the level's own values (#81).
     *
     * @return void
     */
    public function test_the_page_asks_the_level_itself(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/stackmatheditor/configure.php');

        $this->assertStringContainsString('config_manager::get_own_config(', $source);
        foreach (['_enabled', '_allowStudentToggle', '_maxStructuredDimension'] as $key) {
            $this->assertStringContainsString("isset(\$own['{$key}'])", $source, $key);
        }
        // The merged configuration must not come back as the "existing" value.
        $this->assertStringNotContainsString("isset(\$config['_enabled']) ? (bool)", $source);
        $this->assertStringNotContainsString("isset(\$config['_allowStudentToggle']) ? (bool)", $source);
    }

    /**
     * The switch: a pre-filled checkbox that was not touched is not an override (#73).
     *
     * @return void
     */
    public function test_an_untouched_switch_keeps_inheriting(): void {
        // Nothing stored, the form shows what is inherited, saved unchanged: nothing stored.
        $this->assertNull(config_manager::student_toggle_to_store(null, true, true, true));
        $this->assertNull(config_manager::student_toggle_to_store(null, true, false, false));
        // Changed against what is inherited: stored.
        $this->assertFalse(config_manager::student_toggle_to_store(null, true, false, true));
        $this->assertTrue(config_manager::student_toggle_to_store(null, true, true, false));
        // A value of its own stays its own, even when it now equals the inherited one.
        $this->assertTrue(config_manager::student_toggle_to_store(true, true, true, true));
        $this->assertFalse(config_manager::student_toggle_to_store(false, true, false, false));
    }

    /**
     * The switch: an absent field keeps the author's choice.
     *
     * @return void
     */
    public function test_student_switch_survives_a_disabled_editor(): void {
        // Editor on, allowed: stored.
        $this->assertTrue(config_manager::student_toggle_to_store(null, true, true));
        // Editor on, not allowed: stored.
        $this->assertFalse(config_manager::student_toggle_to_store(true, true, false));
        // Editor off: the checkbox is disabled and not submitted; the stored choice stays.
        $this->assertTrue(config_manager::student_toggle_to_store(true, false, null));
        // Editor off, even when something was submitted: the stored choice stays.
        $this->assertTrue(config_manager::student_toggle_to_store(true, false, false));
        // Nothing stored, editor off: still nothing.
        $this->assertNull(config_manager::student_toggle_to_store(null, false, null));
    }

    /**
     * Switching the editor back on restores the switch (#73, item 17).
     *
     * @return void
     */
    public function test_reenabling_the_editor_restores_the_switch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 3, 'local_stackmatheditor');
        set_config('allowstudenttoggle', 1, 'local_stackmatheditor');

        // Allowed with the editor on.
        config_manager::save_quiz_default(77, ['_enabled' => 1, '_allowStudentToggle' => 1]);
        $this->assertTrue(config_manager::get_effective_student_toggle(77));

        // The editor is switched off; the form does not submit the disabled checkbox.
        $toggle = config_manager::student_toggle_to_store(true, false, null);
        config_manager::save_quiz_default(77, ['_enabled' => 0, '_allowStudentToggle' => (int) $toggle]);
        $this->assertFalse(config_manager::get_effective_student_toggle(77), 'no editor, no switch');

        // And back on: the author's choice is still there.
        config_manager::save_quiz_default(77, ['_enabled' => 1, '_allowStudentToggle' => (int) $toggle]);
        $this->assertTrue(config_manager::get_effective_student_toggle(77));
    }

    /**
     * Preview is defined: without a quiz there is only the instance mode (#81, item 27).
     *
     * A question preview has no course module, so no quiz or question override can apply. It
     * follows the instance mode: 0 and 2 show no editor, 1 and 3 do.
     *
     * @return void
     */
    public function test_preview_follows_the_instance_mode(): void {
        $this->resetAfterTest();

        $expected = [0 => false, 1 => true, 2 => false, 3 => true];
        foreach ($expected as $mode => $editor) {
            set_config('enabled', $mode, 'local_stackmatheditor');
            $this->assertSame(
                $editor,
                config_manager::get_effective_enabled(0, 0),
                "preview in mode $mode"
            );
        }
    }
}
