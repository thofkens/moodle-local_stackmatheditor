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
 * Report configuration records that the old lookup could have mixed between quizzes.
 *
 * Until 2026091502 the lookup had a layer that matched a question bank entry in any quiz, and a
 * second one on the old questionid column that was equally unscoped. Both are gone. This script
 * says what an installation actually holds, so that an upgrade does not have to guess:
 *
 *   - duplicates per (cmid, questionbankentryid), which the application deduplicates on write
 *     but the database does not forbid;
 *   - records of the old questionid kind, where that column still exists;
 *   - question bank entries configured in more than one quiz, which is legitimate and was the
 *     situation in which the removed layer leaked.
 *
 * Read-only. It changes nothing and recommends nothing it cannot back up.
 *
 * Usage, from the Moodle root:
 *   php local/stackmatheditor/cli/diagnose_legacy_config.php
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
// The cli_writeln() helper and the other cli_* functions are not part of Moodle's bootstrap.
require_once($CFG->libdir . '/clilib.php');

$table = \local_stackmatheditor\config_manager::TABLE;

if (!$DB->get_manager()->table_exists($table)) {
    cli_writeln("The table {$table} does not exist - nothing to diagnose.");
    exit(0);
}

$total = $DB->count_records($table);
cli_writeln("Configuration records: {$total}");

// 1. Duplicates the database does not forbid.
$sql = "SELECT cmid, questionbankentryid, COUNT(*) AS records
          FROM {" . $table . "}
      GROUP BY cmid, questionbankentryid
        HAVING COUNT(*) > 1";
$duplicates = $DB->get_records_sql($sql);

if ($duplicates) {
    cli_writeln('');
    cli_writeln('Duplicates per (cmid, questionbankentryid):');
    foreach ($duplicates as $row) {
        cli_writeln(sprintf(
            '  cmid=%d qbeid=%s records=%d',
            $row->cmid,
            $row->questionbankentryid === null ? 'NULL' : $row->questionbankentryid,
            $row->records
        ));
    }
    cli_writeln('  These are ambiguous: which one wins depends on the order the database');
    cli_writeln('  returns them. Decide per pair which record is the intended one.');
} else {
    cli_writeln('Duplicates per (cmid, questionbankentryid): none');
}

// 2. Records of the old questionid kind, where that column is still there.
$columns = $DB->get_columns($table);

if (isset($columns['questionid'])) {
    $legacy = $DB->count_records_select($table, 'questionid > 0');
    cli_writeln("Records using the old questionid column: {$legacy}");
    if ($legacy) {
        cli_writeln('  These are only read for the quiz they belong to, or as a global');
        cli_writeln('  default (cmid = 0). Nothing else reaches them.');
    }
} else {
    cli_writeln('Records using the old questionid column: the column does not exist here');
}

// 3. Question bank entries used in more than one quiz. Legitimate, and the situation in which
// the removed layer leaked one quiz's configuration into another.
$sql = "SELECT questionbankentryid, COUNT(DISTINCT cmid) AS quizzes
          FROM {" . $table . "}
         WHERE questionbankentryid IS NOT NULL AND cmid > 0
      GROUP BY questionbankentryid
        HAVING COUNT(DISTINCT cmid) > 1";
$shared = $DB->get_records_sql($sql);

cli_writeln('Question bank entries configured in more than one quiz: ' . count($shared));
foreach ($shared as $row) {
    cli_writeln("  qbeid={$row->questionbankentryid} in {$row->quizzes} quizzes");
}

cli_writeln('');
if (!$duplicates && !$shared) {
    cli_writeln('Nothing to migrate: no ambiguous records, no configuration shared between');
    cli_writeln('quizzes. The scoped lookup behaves exactly as the previous one did here.');
} else {
    cli_writeln('Since 2026091502 a configuration is only read in the quiz it was made for,');
    cli_writeln('plus the explicit global default (cmid = 0). Where the entries above differ');
    cli_writeln('between quizzes, each quiz now keeps its own - which is the point.');
}
