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

/**
 * CLI: regenerate tests/jest/fixtures/buttons.json from the toolbar definitions (#34).
 *
 * The catalogue is what the Jest contract test reads: every button the toolbar offers, with the
 * LaTeX it writes. No language strings are exported - a button's contract is its template, not
 * its label, and keeping strings out makes the fixture stable across languages.
 *
 * Run from the Moodle root after adding or changing a button:
 *   php local/stackmatheditor/tests/jest/export_buttons.php
 * tests/unit/button_fixture_test.php fails until the fixture has been regenerated.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');

$file = __DIR__ . '/fixtures/buttons.json';
$json = json_encode(
    \local_stackmatheditor\definitions::export_button_catalogue(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
file_put_contents($file, $json);
echo "Written: {$file}\n";
