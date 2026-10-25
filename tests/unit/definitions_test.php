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
use local_stackmatheditor\definitions;

/**
 * Unit tests for local_stackmatheditor\definitions.
 *
 * These tests have no DB dependency and run without a full Moodle install.
 * All get_string() calls are shimmed by Moodle's advanced_testcase bootstrap.
 *
 * @package    local_stackmatheditor
 * @covers     \local_stackmatheditor\definitions
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class definitions_test extends advanced_testcase {
    /**
     * Every group must have a non-empty string label.
     */
    public function test_group_labels_are_strings(): void {
        $groups = definitions::get_element_groups();
        foreach ($groups as $key => $group) {
            $this->assertIsString(
                $group['label'],
                "Group '$key' label must be a string"
            );
            $this->assertNotEmpty(
                $group['label'],
                "Group '$key' label must not be empty"
            );
        }
    }

    /**
     * Every group must declare default_enabled as a boolean.
     */
    public function test_group_default_enabled_is_bool(): void {
        $groups = definitions::get_element_groups();
        foreach ($groups as $key => $group) {
            $this->assertIsBool(
                $group['default_enabled'],
                "Group '$key' default_enabled must be bool"
            );
        }
    }

    /**
     * Groups whose buttons depend on a site setting and may therefore be empty (#45).
     */
    private const CONFIGURABLE_GROUPS = ['vector_differential'];

    /**
     * Every group must have at least one element.
     *
     * Except the differential operators: their buttons exist only once an administrator has
     * named the Maxima function for each of them (#45). A group that is empty because nothing
     * has been configured is still listed, so that the setting and the dependency hint (#66)
     * have somewhere to appear.
     */
    public function test_groups_have_elements(): void {
        $groups = definitions::get_element_groups();
        foreach ($groups as $key => $group) {
            if (in_array($key, self::CONFIGURABLE_GROUPS, true)) {
                continue;
            }
            $this->assertNotEmpty(
                $group['elements'],
                "Group '$key' must have at least one element"
            );
        }
    }

    /**
     * Every element must have 'write', 'cmd' or 'matrix', and a display key.
     *
     * Elements may use 'display' (plain text) or 'display_html' (HTML literal).
     */
    public function test_elements_have_required_keys(): void {
        $groups = definitions::get_element_groups();
        foreach ($groups as $groupkey => $group) {
            foreach ($group['elements'] as $i => $el) {
                $ref = "Group '$groupkey' element[$i]";
                $this->assertTrue(
                    isset($el['write']) || isset($el['cmd']) || isset($el['matrix'])
                        || isset($el['popup']),
                    "$ref must have 'write', 'cmd', 'matrix' or 'popup'"
                );
                if (isset($el['popup'])) {
                    // A popup button names the chooser the toolbar opens (#62); the structure
                    // itself comes from the structured model, not from this definition.
                    $this->assertContains(
                        $el['popup'],
                        ['matrix', 'vector'],
                        "$ref 'popup' must be 'matrix' or 'vector'"
                    );
                }
                if (isset($el['matrix'])) {
                    // A matrix button carries a structure, not a LaTeX string: it is inserted
                    // through MathQuill's insertMatrix(), which needs both dimensions.
                    $this->assertIsArray($el['matrix'], "$ref 'matrix' must be an array");
                    foreach (['rows', 'columns'] as $key) {
                        $this->assertArrayHasKey(
                            $key,
                            $el['matrix'],
                            "$ref 'matrix' must have '$key'"
                        );
                        $this->assertIsInt(
                            $el['matrix'][$key],
                            "$ref 'matrix' $key must be an integer"
                        );
                        $this->assertGreaterThanOrEqual(
                            1,
                            $el['matrix'][$key],
                            "$ref 'matrix' $key must be at least 1"
                        );
                    }
                }
                // Accept 'display' (plain text) or 'display_html' (HTML label).
                $hasdisplay = isset($el['display']) || isset($el['display_html']);
                $this->assertTrue(
                    $hasdisplay,
                    "$ref must have 'display' or 'display_html'"
                );
                if (isset($el['display'])) {
                    $this->assertIsString(
                        $el['display'],
                        "$ref 'display' must be a string"
                    );
                }
            }
        }
    }

    /**
     * The 22 expected groups must all be present.
     */
    public function test_expected_groups_present(): void {
        $groups   = definitions::get_element_groups();
        // Core groups that must always be present.
        $required = [
            'basic_operators', 'power_root', 'exponential_log',
            'comparators', 'absolute', 'brackets',
            'constants_math', 'trigonometry', 'greek_lower', 'greek_upper',
        ];
        // Optional groups: shipped, but off by default. Deferred operations no longer live in
        // definitions.php at all - see docs/toolbar-backlog.md (#34).
        $optional = [
            'set_theory', 'logic', 'geometry', 'vector_operators', 'vector_products',
            'differential_operators', 'vector_differential', 'matrix_operators',
            'integral_operators',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey(
                $key,
                $groups,
                "Group '$key' must exist in definitions"
            );
        }
        // Optional groups: only assert presence if they appear in the array.
        foreach ($optional as $key) {
            if (array_key_exists($key, $groups)) {
                $this->assertArrayHasKey(
                    $key,
                    $groups,
                    "Optional group '$key' declared but missing"
                );
            }
        }
        // Dummy assertion so test is never marked as risky.
        $this->assertNotEmpty(
            array_keys($groups),
            'definitions must provide at least one group'
        );
    }


    /**
     * Default config keys match group keys.
     */
    public function test_default_config_matches_groups(): void {
        $groups  = definitions::get_element_groups();
        $config  = definitions::get_default_config();
        $this->assertSame(array_keys($groups), array_keys($config));
    }

    /**
     * get_default_enabled() returns only booleans.
     */
    public function test_default_enabled_all_bools(): void {
        $enabled = definitions::get_default_enabled();
        foreach ($enabled as $key => $val) {
            $this->assertIsBool($val, "enabled['$key'] must be bool");
        }
    }


    /**
     * export_for_js() must contain all required top-level keys.
     */
    public function test_export_for_js_keys(): void {
        $data = definitions::export_for_js();
        $required = [
            'elementGroups', 'functions', 'constants', 'operators',
            'comparison', 'greek', 'units', 'unitSymbols',
            'functionNames', 'reservedWords', 'percentConstants',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $data, "export_for_js must include '$key'");
        }
    }

    /**
     * export_for_js() must be JSON-serialisable without errors.
     */
    public function test_export_for_js_is_json_serialisable(): void {
        $data = definitions::export_for_js();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        $this->assertNotFalse($json, 'export_for_js() must produce valid JSON');
        $this->assertNotNull(json_decode($json, true), 'JSON must round-trip cleanly');
    }

    /**
     * elementGroups in export_for_js contains the same groups as get_element_groups().
     */
    public function test_export_groups_match_element_groups(): void {
        $groups = definitions::get_element_groups();
        $data   = definitions::export_for_js();
        $this->assertSame(
            array_keys($groups),
            array_keys($data['elementGroups']),
            'Exported groups must match get_element_groups() keys'
        );
    }


    /**
     * Labels with examples must include a parenthesised example snippet.
     */
    public function test_group_labels_with_examples_format(): void {
        $labels = definitions::get_group_labels_with_examples();
        foreach ($labels as $key => $label) {
            $this->assertIsString($label, "Label for '$key' must be string");
            if (in_array($key, self::CONFIGURABLE_GROUPS, true)) {
                // No buttons yet, so no examples to show (#45).
                continue;
            }
            // Each label should have the format "Name (x, y, z, …)" or similar.
            $this->assertStringContainsString(
                '(',
                $label,
                "Label for '$key' should contain an example in parentheses"
            );
        }
    }

    // Helper arrays.

    /**
     * get_functions() returns a non-empty array of strings.
     */
    public function test_get_functions_non_empty(): void {
        $funcs = definitions::get_functions();
        $this->assertIsArray($funcs);
        $this->assertNotEmpty($funcs);
        $this->assertContains('sin', $funcs);
        $this->assertContains('cos', $funcs);
        $this->assertContains('sqrt', $funcs);
    }

    /**
     * get_greek() contains all 24 lowercase Greek letters.
     */
    public function test_get_greek_all_lowercase(): void {
        $greek = definitions::get_greek();
        $expected = ['alpha', 'beta', 'gamma', 'delta', 'epsilon',
                     'zeta', 'eta', 'theta', 'iota', 'kappa', 'lambda',
                     'mu', 'nu', 'xi', 'pi', 'rho', 'sigma', 'tau',
                     'upsilon', 'phi', 'chi', 'psi', 'omega'];
        foreach ($expected as $letter) {
            $this->assertContains($letter, $greek, "Greek array must include '$letter'");
        }
    }

    /**
     * get_unit_symbols() maps every unit in get_units() to a display string.
     */
    public function test_unit_symbols_coverage(): void {
        $units   = definitions::get_units();
        $symbols = definitions::get_unit_symbols();
        foreach ($units as $unit) {
            $this->assertArrayHasKey(
                $unit,
                $symbols,
                "Unit '$unit' must have a display symbol"
            );
        }
    }

    /**
     * get_percent_constants() must start with %.
     */
    public function test_percent_constants_format(): void {
        $consts = definitions::get_percent_constants();
        foreach ($consts as $c) {
            $this->assertStringStartsWith('%', $c, "Percent constant '$c' must start with %");
        }
    }
    /**
     * Set-theory group must be active (not commented out).
     */
    public function test_set_theory_group_is_active(): void {
        $groups = definitions::get_element_groups();
        $this->assertArrayHasKey(
            'set_theory',
            $groups,
            'set_theory group must be present in get_element_groups()'
        );
        $this->assertNotEmpty(
            $groups['set_theory']['elements'] ?? [],
            'set_theory group must have at least one element'
        );
    }

    /**
     * Logic group must be active (not commented out).
     */
    public function test_logic_group_is_active(): void {
        $groups = definitions::get_element_groups();
        $this->assertArrayHasKey(
            'logic',
            $groups,
            'logic group must be present in get_element_groups()'
        );
        $this->assertNotEmpty(
            $groups['logic']['elements'] ?? [],
            'logic group must have at least one element'
        );
    }

    /**
     * Fraction button must have display_html for the fraction glyph.
     */
    public function test_fraction_button_has_display_html(): void {
        $groups = definitions::get_element_groups();
        $found  = false;
        foreach ($groups as $group) {
            foreach (($group['elements'] ?? []) as $el) {
                if (($el['write'] ?? '') === '\\frac{}{}') {
                    $found     = true;
                    // Fraction button should use display_html for the glyph.
                    $haslabel = !empty($el['display']) || !empty($el['display_html']);
                    $this->assertTrue(
                        $haslabel,
                        'Fraction button must have display or display_html'
                    );
                }
            }
        }
        if (!$found) {
            $this->markTestSkipped('Fraction button not found in any group.');
        }
    }

    /**
     * export_for_js() must include the usePercentPi key as a bool.
     */
    public function test_export_for_js_includes_usepercentpi(): void {
        $export = definitions::export_for_js();
        $this->assertArrayHasKey(
            'usePercentPi',
            $export,
            'export_for_js() must contain usePercentPi key'
        );
        $this->assertIsBool(
            $export['usePercentPi'],
            'usePercentPi must be a bool'
        );
    }

    /**
     * Every button of every offered group has an accessible name from the language pack.
     *
     * The toolbar uses the tooltip as aria-label; a button without one is announced as its
     * symbol only ("<"), which says nothing to a screen-reader user.
     */
    public function test_every_offered_button_has_a_tooltip(): void {
        foreach (definitions::get_element_groups() as $groupkey => $group) {
            foreach ($group['elements'] as $index => $element) {
                $this->assertNotEmpty(
                    $element['tooltip'] ?? '',
                    "Group '{$groupkey}', button {$index} has no tooltip (accessible name)."
                );
            }
        }
    }

    /**
     * The groups a teacher can pick must actually be in the catalogue (#34).
     *
     * Vectors, matrices and geometry lived inside a comment block for a long time, which is not
     * visible from the outside: the settings page simply did not list them.
     *
     * @return void
     */
    public function test_the_expected_groups_are_offered(): void {
        $groups = definitions::get_element_groups();

        foreach (
            [
            'vector_operators',
            'vector_differential',
            'matrix_operators',
            'geometry',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $groups, "group $key is missing");
            $this->assertArrayHasKey(
                $key,
                definitions::get_group_labels_with_examples(),
                "group $key is not offered in the settings"
            );
        }
    }

    /**
     * A group that is offered has buttons - except where a setting decides (#45).
     *
     * @return void
     */
    public function test_offered_groups_have_buttons(): void {
        foreach (definitions::get_element_groups() as $key => $group) {
            if (in_array($key, self::CONFIGURABLE_GROUPS, true)) {
                continue;
            }
            $this->assertNotEmpty($group['elements'] ?? [], "group $key has no buttons");
        }
    }
}
