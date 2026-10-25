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
 * Repair historical data of the configuration table.
 *
 * Two kinds of rows the current code does not produce can exist in the table:
 *   - orphans: rows of course modules that are gone, deleted while the observer that removes the
 *     rows with the course module was not active; they still carry a personal reference
 *     (usermodified);
 *   - duplicate scopes: more than one row per (cmid, questionbankentryid); the runtime reads
 *     the newest one (timemodified, then id), the repair keeps exactly that one.
 *
 * Without --execute the script only reports (dry run). Exit code 0 on success, 2 on bad options.
 *
 * Usage, from the Moodle root (public/ on Moodle 5.1+ is the web root, the CLI path is the same
 * relative to it):
 *   php local/stackmatheditor/cli/repair_config.php [--orphans] [--duplicates] [--execute]
 *
 * With neither --orphans nor --duplicates both repairs are selected.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_stackmatheditor\data_maintenance;

[$options, $unrecognised] = cli_get_params(
    ['orphans' => false, 'duplicates' => false, 'execute' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)), 2);
}

if ($options['help']) {
    cli_writeln('Repair historical rows of local_stackmatheditor.

Options:
  --orphans     Rows of deleted course modules (module context gone).
  --duplicates  More than one row per (cmid, questionbankentryid).
  --execute     Apply the repair. Without it the script only reports.
  -h, --help    Print this help.');
    exit(0);
}

if (!$options['orphans'] && !$options['duplicates']) {
    $options['orphans'] = true;
    $options['duplicates'] = true;
}
$execute = (bool) $options['execute'];

if (!$DB->get_manager()->table_exists(\local_stackmatheditor\config_manager::TABLE)) {
    cli_writeln('The configuration table does not exist - nothing to repair.');
    exit(0);
}

if ($options['orphans']) {
    $orphans = data_maintenance::find_orphans();
    cli_writeln('Orphaned rows (module context gone): ' . count($orphans));
    foreach ($orphans as $row) {
        cli_writeln(sprintf(
            '  id=%d cmid=%d qbeid=%s usermodified=%d',
            $row->id,
            $row->cmid,
            $row->questionbankentryid === null ? 'NULL' : $row->questionbankentryid,
            $row->usermodified
        ));
    }
    if ($execute && $orphans) {
        cli_writeln('  deleted: ' . data_maintenance::delete_orphans());
    }
}

if ($options['duplicates']) {
    $scopes = data_maintenance::find_duplicate_scopes();
    cli_writeln('Scopes with duplicate rows: ' . count($scopes));
    foreach ($scopes as $scope) {
        cli_writeln(sprintf(
            '  cmid=%d qbeid=%s records=%d',
            $scope->cmid,
            $scope->questionbankentryid === null ? 'NULL' : $scope->questionbankentryid,
            $scope->records
        ));
    }
    if ($execute && $scopes) {
        cli_writeln('  deleted: ' . data_maintenance::repair_duplicates());
    }
}

if (!$execute) {
    cli_writeln('Dry run - nothing changed. Run again with --execute to repair.');
}
exit(0);
