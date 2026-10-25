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
 * Tests for the read-only view of STACK's input semantics (#65).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\stack_inputs
 */
final class stack_inputs_test extends \advanced_testcase {
    /**
     * Write an input row the way STACK does.
     *
     * @param int $questionid Question id.
     * @param string $name Input name.
     * @param int $insertstars Value.
     * @return void
     */
    private function add_input(int $questionid, string $name, int $insertstars): void {
        global $DB;

        $DB->insert_record('qtype_stack_inputs', (object) [
            'questionid'  => $questionid,
            'name'        => $name,
            'type'        => 'algebraic',
            'tans'        => '1',
            'boxsize'     => 15,
            'strictsyntax' => 1,
            'insertstars' => $insertstars,
            'syntaxhint'  => '',
            'syntaxattribute' => 0,
            'forbidwords' => '',
            'allowwords'  => '',
            'forbidfloat' => 1,
            'requirelowestterms' => 0,
            'checkanswertype' => 0,
            'mustverify'  => 1,
            'showvalidation' => 1,
            'options'     => '',
        ]);
    }

    /**
     * Every input reports its own value; one question can hold several.
     *
     * @return void
     */
    public function test_each_input_keeps_its_own_setting(): void {
        global $DB;

        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('qtype_stack_inputs')) {
            $this->markTestSkipped('qtype_stack is not installed');
        }

        $questionid = 424242;
        $this->add_input($questionid, 'ans1', 0);
        $this->add_input($questionid, 'ans2', 1);
        $this->add_input($questionid, 'ans3', 5);

        $values = stack_inputs::get_insertstars($questionid);

        $this->assertSame(['ans1' => 0, 'ans2' => 1, 'ans3' => 5], $values);
    }

    /**
     * A question without STACK inputs reports nothing rather than a global default.
     *
     * @return void
     */
    public function test_no_inputs_means_no_display(): void {
        global $DB;

        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('qtype_stack_inputs')) {
            $this->markTestSkipped('qtype_stack is not installed');
        }

        set_config('inputinsertstars', 2, 'qtype_stack');

        $this->assertSame([], stack_inputs::get_insertstars(999999));
        $this->assertSame([], stack_inputs::get_insertstars(0));
    }

    /**
     * The labels are STACK's own, not a parallel set of terms.
     *
     * @return void
     */
    public function test_labels_come_from_stack(): void {
        $this->resetAfterTest();

        if (!get_string_manager()->string_exists('insertstarsno', 'qtype_stack')) {
            $this->markTestSkipped('qtype_stack language strings are not installed');
        }

        $this->assertSame(
            trim(get_string('insertstarsno', 'qtype_stack')),
            stack_inputs::get_insertstars_label(0)
        );
        $this->assertSame(
            trim(get_string('insertstarsspacessinglechar', 'qtype_stack')),
            stack_inputs::get_insertstars_label(5)
        );
    }

    /**
     * A value this plugin does not know is shown, not guessed.
     *
     * @return void
     */
    public function test_unknown_value_is_not_invented(): void {
        $this->resetAfterTest();

        $label = stack_inputs::get_insertstars_label(99);

        $this->assertStringContainsString('99', $label);
    }

    /**
     * Every value STACK offers has a string identifier here.
     *
     * @return void
     */
    public function test_the_value_map_matches_stack(): void {
        $this->assertSame(
            [0, 1, 2, 3, 4, 5, 6, 7],
            array_keys(stack_inputs::INSERT_STARS_STRINGS)
        );
    }

    /**
     * Without the capability there is no edit link, and never for a missing question.
     *
     * @return void
     */
    public function test_no_edit_link_without_a_question(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertNull(stack_inputs::get_edit_url(0, 0));

        // A question id that no longer exists must not reach the question engine: it would
        // answer with a debugging message rather than a decision.
        $this->assertNull(stack_inputs::get_edit_url(999999, 0));
        $this->assertDebuggingNotCalled();
    }

    /**
     * The summary carries what the configuration page renders.
     *
     * @return void
     */
    public function test_summary_shape(): void {
        global $DB;

        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('qtype_stack_inputs')) {
            $this->markTestSkipped('qtype_stack is not installed');
        }

        $questionid = 515151;
        $this->add_input($questionid, 'ans1', 0);

        $summary = stack_inputs::get_semantics_summary($questionid, 0);

        $this->assertArrayHasKey('inputs', $summary);
        $this->assertArrayHasKey('editurl', $summary);
        $this->assertCount(1, $summary['inputs']);
        $this->assertSame('ans1', $summary['inputs'][0]['name']);
        $this->assertSame(0, $summary['inputs'][0]['value']);
        $this->assertNotEmpty($summary['inputs'][0]['label']);
    }
}
