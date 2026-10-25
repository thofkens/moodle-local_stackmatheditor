<?php
// phpcs:ignoreFile
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI tool: lightweight Mustache template tag-balance check.
 *
 * Usage: php mustache_check.php <templates-directory>
 *
 * Counts opening {{#section}} / {{^inverted}} vs closing {{/name}} tags.
 * Note: this is a heuristic check only — full validation (HTML,
 * variable contracts) requires moodle-plugin-ci mustache in CI.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true); // phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState

// Script entry point — no Moodle bootstrap needed for file-level checks.
if ($argc < 2) {
    echo "Usage: php mustache_check.php <templates-directory>\n";
    exit(1);
}
$dir = rtrim($argv[1], '/');
$files = glob($dir . '/*.mustache');
if (empty($files)) {
    echo "No .mustache files found in $dir\n";
    exit(0);
}
$errors = 0;
foreach ($files as $file) {
    $content = file_get_contents($file);
    // Strip Mustache comments {{! ... }} before counting.
    $stripped = preg_replace('/\{\{![\s\S]*?\}\}/', '', $content);
    $open  = preg_match_all('/\{\{[#^]/', $stripped, $m);
    $close = preg_match_all('/\{\{\//', $stripped, $m);
    $name  = basename($file);
    if ($open !== $close) {
        echo "WARN unbalanced tags ($open open, $close close): $name\n";
        $errors++;
    } else {
        echo "OK: $name\n";
    }
}
exit($errors > 0 ? 1 : 0);
