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
 * Lifecycle and integrity maintenance of the configuration table.
 *
 * Configuration rows with cmid > 0 belong to exactly one course module. When that course module
 * is deleted, its rows are meaningless and still carry a personal reference (usermodified), so they
 * are removed together with the course module. Rows whose module context no longer exists are
 * called orphans; they can stem from course deletions, which remove course modules without a
 * per-module event, or from sites on which the observer was not yet active.
 *
 * The same class repairs duplicate scope rows: one row per (cmid, questionbankentryid) is
 * kept by the application rather than a unique index (docs/DATA-INTEGRITY.md), and this class
 * restores that invariant on existing data. The upgrade to 2026100800 runs both repairs once.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_maintenance {
    /**
     * SQL condition selecting rows whose module context no longer exists.
     *
     * @param string $alias Alias of the configuration table in the query; an empty string refers
     *                      to the table by name, for statements without alias (DELETE, UPDATE).
     * @return string SQL fragment; needs the parameters of orphan_params().
     */
    public static function orphan_condition(string $alias = 'sme'): string {
        $table = $alias === '' ? '{' . config_manager::TABLE . '}' : $alias;
        return "{$table}.cmid > 0
                AND NOT EXISTS (SELECT 1
                                  FROM {context} octx
                                 WHERE octx.contextlevel = :orphanmodulelevel
                                       AND octx.instanceid = {$table}.cmid)";
    }

    /**
     * Parameters of orphan_condition().
     *
     * @return array Named parameters.
     */
    public static function orphan_params(): array {
        return ['orphanmodulelevel' => CONTEXT_MODULE];
    }

    /**
     * Delete all configuration rows of one course module.
     *
     * @param int $cmid Course module id; 0 (the global scope) is never touched.
     * @return int Number of deleted rows.
     */
    public static function delete_for_cmid(int $cmid): int {
        global $DB;
        if ($cmid <= 0) {
            return 0;
        }
        $count = $DB->count_records(config_manager::TABLE, ['cmid' => $cmid]);
        if ($count) {
            $DB->delete_records(config_manager::TABLE, ['cmid' => $cmid]);
        }
        return $count;
    }

    /**
     * Rows whose module context no longer exists.
     *
     * @return \stdClass[] Records keyed by id (id, cmid, questionbankentryid, usermodified).
     */
    public static function find_orphans(): array {
        global $DB;
        $sql = "SELECT sme.id, sme.cmid, sme.questionbankentryid, sme.usermodified
                  FROM {" . config_manager::TABLE . "} sme
                 WHERE " . self::orphan_condition() . "
              ORDER BY sme.cmid, sme.id";
        return $DB->get_records_sql($sql, self::orphan_params());
    }

    /**
     * Delete all rows whose module context no longer exists.
     *
     * @return int Number of deleted rows.
     */
    public static function delete_orphans(): int {
        global $DB;
        $ids = array_keys(self::find_orphans());
        if (!$ids) {
            return 0;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $DB->delete_records_list(config_manager::TABLE, 'id', $chunk);
        }
        return count($ids);
    }

    /**
     * Scopes (cmid, questionbankentryid) that hold more than one row.
     *
     * @return \stdClass[] Objects with cmid, questionbankentryid (null for quiz defaults), records.
     */
    public static function find_duplicate_scopes(): array {
        global $DB;
        $sql = "SELECT cmid, questionbankentryid, COUNT(1) AS records
                  FROM {" . config_manager::TABLE . "}
              GROUP BY cmid, questionbankentryid
                HAVING COUNT(1) > 1
              ORDER BY cmid, questionbankentryid";
        $result = [];
        foreach ($DB->get_recordset_sql($sql) as $row) {
            $result[] = (object)[
                'cmid'                => (int) $row->cmid,
                'questionbankentryid' => $row->questionbankentryid === null ? null : (int) $row->questionbankentryid,
                'records'             => (int) $row->records,
            ];
        }
        return $result;
    }

    /**
     * Reduce every duplicated scope to the row the runtime reads (newest timemodified, then id).
     *
     * @return int Number of deleted rows.
     */
    public static function repair_duplicates(): int {
        $deleted = 0;
        foreach (self::find_duplicate_scopes() as $scope) {
            $deleted += config_manager::collapse_scope($scope->cmid, $scope->questionbankentryid);
        }
        return $deleted;
    }
}
