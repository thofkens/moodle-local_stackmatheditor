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
 * Guards the Jest definitions fixture against drift from the PHP definitions.
 *
 * The Jest suites test tex2max/max2tex with tests/jest/fixtures/definitions.json, an export of
 * definitions::export_for_js(). If a definition list changes and the fixture does not, the Jest
 * tests would silently keep testing the old behaviour. This test fails in that case.
 *
 * To regenerate the fixture after a deliberate change, run from the Moodle root:
 *   php local/stackmatheditor/tests/jest/export_definitions.php
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\definitions::export_for_js
 */
final class jest_fixture_test extends \advanced_testcase {
    /** @var string[] Keys of export_for_js() the converters read (keep in sync with export_definitions.php). */
    private const KEYS = [
        'functions',
        'constants',
        'greek',
        'units',
        'functionNames',
        'reservedWords',
        'percentConstants',
    ];

    /**
     * The fixture must equal the converter-relevant part of export_for_js().
     *
     * @return void
     */
    public function test_fixture_matches_definitions(): void {
        $file = __DIR__ . '/../jest/fixtures/definitions.json';
        if (!is_dir(__DIR__ . '/../jest')) {
            // The release archive leaves tests/jest out (.gitattributes); the repository has it.
            $this->markTestSkipped('tests/jest is not part of the release archive');
        }
        $this->assertFileExists($file);
        $fixture = json_decode(file_get_contents($file), true);
        $this->assertIsArray($fixture, 'The Jest definitions fixture is not valid JSON.');

        $exported = definitions::export_for_js();
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $fixture, "Fixture lacks '{$key}' - regenerate it.");
            $this->assertSame(
                $exported[$key],
                $fixture[$key],
                "Fixture key '{$key}' differs from definitions::export_for_js() - regenerate it."
            );
        }
    }
}
