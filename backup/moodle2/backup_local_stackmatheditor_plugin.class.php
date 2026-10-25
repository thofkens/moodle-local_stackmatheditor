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
 * Backup of the editor configuration of an activity.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the configurations of a quiz or adaptive quiz to its module backup.
 *
 * The rows are written below the module element: the quiz-level default (questionbankentryid
 * empty) and one row per configured question bank entry. The ids are the ones of the source site;
 * the restore maps them to the new course module and question bank entries.
 *
 * usermodified is a personal reference and travels only with user data; without it the restored
 * configuration is anonymous (usermodified = 0).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_stackmatheditor_plugin extends backup_local_plugin {
    /**
     * Structure attached to the module element.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $configs = new backup_nested_element('stackmatheditor_configs');
        $config = new backup_nested_element('stackmatheditor_config', ['id'], [
            'questionbankentryid', 'allowed_elements', 'usermodified', 'timecreated', 'timemodified',
        ]);

        $plugin->add_child($wrapper);
        $wrapper->add_child($configs);
        $configs->add_child($config);

        $userinfo = (bool) $this->get_setting_value('userinfo');
        $column = \local_stackmatheditor\config_manager::get_config_column_public();
        $usersql = $userinfo ? 'usermodified' : '0 AS usermodified';
        $config->set_source_sql(
            "SELECT id, questionbankentryid, {$column} AS allowed_elements, {$usersql},
                    timecreated, timemodified
               FROM {" . \local_stackmatheditor\config_manager::TABLE . "}
              WHERE cmid = ?
           ORDER BY id",
            [backup::VAR_MODID]
        );
        if ($userinfo) {
            $config->annotate_ids('user', 'usermodified');
        }

        return $plugin;
    }
}
