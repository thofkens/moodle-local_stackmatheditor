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
 * Every group of visible buttons has a CAS scenario behind it (#34).
 *
 * The Jest contract test proves a button produces a CAS-safe string; it has no CAS, so it cannot
 * prove the CAS agrees. `tests/behat/cas_contract.feature` does that against a real STACK, and
 * this test is the gate that keeps the two in step: add a group of visible buttons without a
 * scenario and CI says so, rather than the gap being noticed a release later.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\definitions::export_button_catalogue
 */
final class cas_contract_test extends \advanced_testcase {
    /**
     * Groups whose buttons carry no mathematical contract of their own.
     *
     * Brackets and the decorations produce grouping, not an operation; the Greek letters are
     * covered by one scenario for the whole alphabet, because the mapping is the same rule
     * twenty-four times.
     */
    private const NO_OWN_CONTRACT = [
        'brackets',
        'greek_upper',
    ];

    /**
     * Which group each scenario in the feature file stands for.
     *
     * The feature reads as prose, so the mapping lives here rather than in a comment there.
     */
    private const SCENARIOS = [
        'basic_operators'        => 'Basic arithmetic',
        'power_root'             => 'Powers and roots',
        'exponential_log'        => 'Exponential and logarithm',
        'trigonometry'           => 'Trigonometry',
        'absolute'               => 'Absolute value',
        'comparators'            => 'Comparators',
        'set_theory'             => 'Set theory',
        'logic'                  => 'Logic',
        'matrix_operators'       => 'Matrices',
        'vector_operators'       => 'Column vectors',
        'vector_products'        => 'Column vectors',
        'geometry'               => 'Geometry with points as lists',
        'greek_lower'            => 'Greek letters',
        'constants_math'         => 'Mathematical constants',
        'integral_operators'     => 'Integrals',
        'differential_operators' => 'Derivatives',
    ];

    /**
     * The feature file, as text.
     *
     * @return string
     */
    private function feature(): string {
        global $CFG;

        $path = $CFG->dirroot . '/local/stackmatheditor/tests/behat/cas_contract.feature';
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * Every group that ships buttons is represented by a scenario.
     *
     * @return void
     */
    public function test_every_group_has_a_cas_scenario(): void {
        $feature = $this->feature();
        $groups = [];

        foreach (definitions::export_button_catalogue() as $button) {
            $groups[$button['group']] = true;
        }

        foreach (array_keys($groups) as $group) {
            if (in_array($group, self::NO_OWN_CONTRACT, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $group,
                self::SCENARIOS,
                "The group '$group' has visible buttons but no CAS scenario. Add one to "
                    . "tests/behat/cas_contract.feature and name it here, or list the group in "
                    . "NO_OWN_CONTRACT with a reason."
            );

            $this->assertStringContainsString(
                'Scenario: ' . self::SCENARIOS[$group],
                $feature,
                "The scenario named for '$group' is not in the feature file."
            );
        }
    }

    /**
     * Every scenario named here is actually in the feature file.
     *
     * @return void
     */
    public function test_no_scenario_is_claimed_that_does_not_exist(): void {
        $feature = $this->feature();

        foreach (array_unique(array_values(self::SCENARIOS)) as $scenario) {
            $this->assertStringContainsString('Scenario: ' . $scenario, $feature);
        }
    }

    /**
     * Every scenario asks STACK, rather than only checking the conversion.
     *
     * @return void
     */
    public function test_every_scenario_asks_the_cas(): void {
        $feature = $this->feature();
        $scenarios = substr_count($feature, 'Scenario:');
        $questions = substr_count($feature, 'STACK should accept the answer in');

        $this->assertGreaterThan(10, $scenarios, 'the feature must cover the toolbar');
        $this->assertSame(
            $scenarios,
            $questions,
            'a scenario in this file that does not ask STACK proves nothing about the contract'
        );
    }

    /**
     * The groups without a contract of their own are named, not forgotten.
     *
     * @return void
     */
    public function test_the_exceptions_are_explicit(): void {
        foreach (self::NO_OWN_CONTRACT as $group) {
            $this->assertArrayNotHasKey(
                $group,
                self::SCENARIOS,
                "'$group' cannot be both an exception and a scenario"
            );
        }
    }
}
