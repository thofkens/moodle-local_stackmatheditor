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
 * Restore of the editor configuration of an activity.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the configurations of a quiz or adaptive quiz onto the new ids.
 *
 * Restore, import and duplication all run through here:
 *   - cmid is the new course module, never the one in the backup;
 *   - a question bank entry goes through the restore mapping (new entry, or the existing one the
 *     restore matched); a row whose entry the restored quiz does not use is dropped, so no row
 *     points into another course or at an entry that does not exist;
 *   - usermodified is mapped to the restored user, or 0 when users are not part of the restore.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_stackmatheditor_plugin extends restore_local_plugin {
    /** @var int[] Ids of the rows this restore inserted. */
    protected $restoredids = [];

    /**
     * Paths below the module element.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element(
                'stackmatheditor_config',
                $this->get_pathfor('/stackmatheditor_configs/stackmatheditor_config')
            ),
        ];
    }

    /**
     * One configuration row of the backup.
     *
     * @param array $data Row data.
     * @return void
     */
    public function process_stackmatheditor_config($data) {
        global $DB;
        $data = (object) $data;

        $qbeid = null;
        if ($data->questionbankentryid !== null && $data->questionbankentryid !== '') {
            $old = (int) $data->questionbankentryid;
            $qbeid = (int) $this->get_mappingid('question_bank_entry', $old, $old);
        }
        $userid = (int) $this->get_mappingid('user', (int) $data->usermodified, 0);

        $column = \local_stackmatheditor\config_manager::get_config_column_public();
        $record = (object) [
            'cmid'                => (int) $this->task->get_moduleid(),
            'questionbankentryid' => $qbeid,
            $column                => $data->allowed_elements,
            'usermodified'        => $userid,
            'timecreated'         => (int) $data->timecreated,
            'timemodified'        => (int) $data->timemodified,
        ];
        $this->restoredids[] = (int) $DB->insert_record(\local_stackmatheditor\config_manager::TABLE, $record);
    }

    /**
     * After the whole restore: drop rows whose question the restored activity does not use and
     * keep one row per scope.
     *
     * Runs after the restore because only then are the quiz slots and question references of
     * the new activity in place.
     *
     * @return void
     */
    public function after_restore_module() {
        global $DB;
        if (!$this->restoredids) {
            return;
        }
        $table = \local_stackmatheditor\config_manager::TABLE;
        $cmid = (int) $this->task->get_moduleid();
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);

        [$insql, $params] = $DB->get_in_or_equal($this->restoredids);
        $rows = $DB->get_records_select($table, "id {$insql}", $params);
        $scopes = [];
        foreach ($rows as $row) {
            $qbeid = $row->questionbankentryid === null ? null : (int) $row->questionbankentryid;
            $keep = $cm && (
                $qbeid === null
                || ($cm->modname === 'quiz'
                    && \local_stackmatheditor\quiz_helper::quiz_uses_entry((int) $cm->instance, $qbeid))
            );
            if (!$keep) {
                $DB->delete_records($table, ['id' => $row->id]);
                $this->task->log('local_stackmatheditor: configuration of question bank entry '
                    . ($qbeid ?? 'default') . ' dropped, the restored activity does not use it', backup::LOG_INFO);
                continue;
            }
            $scopes[$qbeid ?? 'default'] = $qbeid;
        }
        foreach ($scopes as $qbeid) {
            \local_stackmatheditor\config_manager::collapse_scope($cmid, $qbeid);
        }
        $this->restoredids = [];
    }
}
