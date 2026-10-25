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
 * Upgrade script for local_stackmatheditor.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin from an older version.
 *
 * @param int $oldversion The version the plugin is upgrading from.
 * @return bool True on success.
 */
function xmldb_local_stackmatheditor_upgrade(int $oldversion): bool {
    global $DB;

    // A fresh install comes from db/install.xml.

    if ($oldversion < 2026100800) {
        // Data the versions before 2026100800 could leave behind, brought to the state a fresh
        // install of this version keeps by itself. The step is written against the
        // table, not against the plugin's classes, so a later change to them cannot change what
        // this step did. Running it twice changes nothing the second time.
        $table = 'local_stackmatheditor';

        // 1. Rows of course modules that no longer exist. Before 2026100800 nothing removed them
        // when a quiz or a course was deleted; they configure nothing and still name the user
        // who last changed them.
        $orphans = $DB->get_fieldset_sql(
            "SELECT sme.id
               FROM {" . $table . "} sme
              WHERE sme.cmid > 0
                    AND NOT EXISTS (SELECT 1
                                      FROM {context} ctx
                                     WHERE ctx.contextlevel = :modulelevel
                                           AND ctx.instanceid = sme.cmid)",
            ['modulelevel' => CONTEXT_MODULE]
        );
        foreach (array_chunk($orphans, 500) as $chunk) {
            $DB->delete_records_list($table, 'id', $chunk);
        }

        // 2. More than one row for one scope (cmid, questionbankentryid). Every read returns the
        // newest row (timemodified, then id); that is the one kept, so nothing a site shows
        // changes.
        // Read completely before deleting: some drivers do not allow writing to a table while a
        // recordset over it is open.
        $scopes = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('cmid', "'/'", 'COALESCE(questionbankentryid, 0)') . " AS scopekey,
                    cmid, questionbankentryid
               FROM {" . $table . "}
           GROUP BY cmid, questionbankentryid
             HAVING COUNT(1) > 1"
        );
        foreach ($scopes as $scope) {
            if ($scope->questionbankentryid === null) {
                $where = 'cmid = :cmid AND questionbankentryid IS NULL';
                $params = ['cmid' => $scope->cmid];
            } else {
                $where = 'cmid = :cmid AND questionbankentryid = :qbeid';
                $params = ['cmid' => $scope->cmid, 'qbeid' => $scope->questionbankentryid];
            }
            $ids = $DB->get_fieldset_sql(
                "SELECT id FROM {" . $table . "} WHERE {$where} ORDER BY timemodified DESC, id DESC",
                $params
            );
            $surplus = array_slice($ids, 1);
            if ($surplus) {
                $DB->delete_records_list($table, 'id', $surplus);
            }
        }

        upgrade_plugin_savepoint(true, 2026100800, 'local', 'stackmatheditor');
    }

    return true;
}
