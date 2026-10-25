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
 * The button catalogue the contract test reads must be the catalogue that ships (#34).
 *
 * The Jest test checks every button's template against the converter. It reads a fixture,
 * because Jest cannot call PHP. This test is what keeps the fixture honest: add a button and
 * forget to regenerate, and it fails here rather than passing silently over there.
 *
 * Regenerate with:
 *   php local/stackmatheditor/tests/jest/export_buttons.php
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\definitions::export_button_catalogue
 */
final class button_fixture_test extends \advanced_testcase {
    /**
     * The fixture matches the current definitions.
     *
     * @return void
     */
    public function test_the_fixture_is_current(): void {
        global $CFG;

        $path = $CFG->dirroot . '/local/stackmatheditor/tests/jest/fixtures/buttons.json';
        if (!is_dir($CFG->dirroot . '/local/stackmatheditor/tests/jest')) {
            // The release archive leaves tests/jest out (.gitattributes); the repository has it.
            $this->markTestSkipped('tests/jest is not part of the release archive');
        }
        $fixture = json_decode(file_get_contents($path), true);

        $this->assertIsArray($fixture, 'the button fixture must be readable JSON');
        $this->assertSame(
            definitions::export_button_catalogue(),
            $fixture,
            'the button fixture is stale - run tests/jest/export_buttons.php'
        );
    }

    /**
     * Every button in the catalogue carries what a contract needs.
     *
     * @return void
     */
    public function test_every_button_is_complete(): void {
        foreach (definitions::export_button_catalogue() as $button) {
            $where = $button['group'] . ' / ' . $button['display'];

            $this->assertContains(
                $button['kind'],
                ['write', 'cmd', 'popup', 'matrix'],
                "$where has no usable kind"
            );
            $this->assertNotSame('', $button['template'], "$where has no template");
        }
    }

    /**
     * A template must not contain a LaTeX line break.
     *
     * The norm button wrote \\left\\\\| for a while, which is a line break followed by a pipe,
     * not a double bar. It reached the CAS as abs(\\) and was found by the contract test.
     *
     * @return void
     */
    public function test_no_template_contains_a_line_break(): void {
        foreach (definitions::export_button_catalogue() as $button) {
            if ($button['kind'] !== 'write' && $button['kind'] !== 'cmd') {
                continue;
            }
            $this->assertStringNotContainsString(
                '\\\\',
                $button['template'],
                $button['group'] . ' / ' . $button['display']
                    . ' contains a LaTeX line break in its template'
            );
        }
    }
}
