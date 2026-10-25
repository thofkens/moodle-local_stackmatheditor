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

namespace local_stackmatheditor\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as request_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy subsystem implementation for local_stackmatheditor.
 *
 * The plugin stores toolbar configurations per quiz and per question. Those are course data, not
 * personal data - the only personal reference is `usermodified`, the person who last changed a
 * configuration. A deletion request therefore anonymises that reference (usermodified = 0) and
 * keeps the configuration itself: deleting it would destroy a colleague's course setup.
 *
 * Records with cmid > 0 belong to the module context of that course module; the global scope with
 * cmid = 0 has no course module and belongs to the system context.
 *
 * Rows of a deleted course module are removed with it (observer). A row whose module context is
 * gone anyway (an orphan the observer did not catch) cannot be reached through a module context;
 * it is attributed to the user context of usermodified, so that export and deletion still find
 * it. As an orphan configures nothing, deleting the user's data removes it entirely.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, request_provider {
    /**
     * Describe what this plugin stores.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_stackmatheditor', [
            'cmid'                => 'privacy:metadata:cmid',
            'questionbankentryid' => 'privacy:metadata:questionbankentryid',
            'allowed_elements'    => 'privacy:metadata:allowed_elements',
            'usermodified'        => 'privacy:metadata:usermodified',
            'timecreated'         => 'privacy:metadata:timecreated',
            'timemodified'        => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_stackmatheditor');

        return $collection;
    }

    /**
     * Contexts in which the given user last changed a configuration.
     *
     * @param int $userid The user to search for.
     * @return contextlist The contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // One query for all module contexts, joined on the context table (no per-record lookup).
        $sql = "SELECT ctx.id
                  FROM {local_stackmatheditor} sme
                  JOIN {context} ctx ON ctx.instanceid = sme.cmid AND ctx.contextlevel = :modulelevel
                 WHERE sme.usermodified = :userid AND sme.cmid > 0";
        $contextlist->add_from_sql($sql, ['modulelevel' => CONTEXT_MODULE, 'userid' => $userid]);

        // Orphans whose module context is gone are reported in the user's own context.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :userlevel AND ctx.instanceid = :ctxuserid
                       AND EXISTS (SELECT 1
                                     FROM {local_stackmatheditor} sme
                                    WHERE sme.usermodified = :userid
                                          AND " . \local_stackmatheditor\data_maintenance::orphan_condition() . ")";
        $contextlist->add_from_sql($sql, [
            'userlevel' => CONTEXT_USER,
            'ctxuserid' => $userid,
            'userid'    => $userid,
        ] + \local_stackmatheditor\data_maintenance::orphan_params());

        // Global records (cmid = 0) have no course module and belong to the system context.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :systemlevel
                       AND EXISTS (SELECT 1
                                     FROM {local_stackmatheditor} sme
                                    WHERE sme.cmid = 0 AND sme.usermodified = :userid)";
        $contextlist->add_from_sql($sql, ['systemlevel' => CONTEXT_SYSTEM, 'userid' => $userid]);

        return $contextlist;
    }

    /**
     * Users who last changed a configuration in the given context.
     *
     * @param userlist $userlist The userlist containing the list of users.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        [$where, $params] = self::scope_for_context($userlist->get_context());
        if ($where === null) {
            return;
        }

        $userlist->add_from_sql(
            'usermodified',
            "SELECT DISTINCT usermodified
               FROM {local_stackmatheditor}
              WHERE {$where} AND usermodified > 0",
            $params
        );
    }

    /**
     * Export the configurations the given user last changed, per approved context.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            [$where, $params] = self::scope_for_context($context);
            if ($where === null) {
                continue;
            }
            $params['userid'] = $userid;
            $records = $DB->get_records_select(
                'local_stackmatheditor',
                "{$where} AND usermodified = :userid",
                $params,
                'cmid, questionbankentryid'
            );
            if (!$records) {
                continue;
            }

            $column = \local_stackmatheditor\config_manager::get_config_column_public();
            $data = [];
            foreach ($records as $record) {
                $scope = self::scope_name($record);
                $data[] = (object)[
                    // Readable in the language of the export; the key stays machine-readable.
                    'scope'               => get_string('privacy:scope_' . $scope, 'local_stackmatheditor'),
                    'scope_key'           => $scope,
                    'cmid'                => (int) $record->cmid,
                    'questionbankentryid' => $record->questionbankentryid === null
                        ? null
                        : (int) $record->questionbankentryid,
                    // Read through the same column helper the plugin writes with.
                    'allowed_elements'    => $record->{$column} ?? null,
                    'usermodified'        => (int) $record->usermodified,
                    'timecreated'         => transform::datetime($record->timecreated),
                    'timemodified'        => transform::datetime($record->timemodified),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_stackmatheditor')],
                (object)['configurations' => $data]
            );
        }
    }

    /**
     * Anonymise the personal reference of all users in a context; keep the configurations.
     *
     * @param \context $context The specific context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        [$where, $params] = self::scope_for_context($context);
        if ($where === null) {
            return;
        }
        if ($context->contextlevel === CONTEXT_USER) {
            // Orphans configure nothing; the only thing left to them is the personal reference.
            $DB->delete_records_select('local_stackmatheditor', $where, $params);
            return;
        }
        $DB->set_field_select('local_stackmatheditor', 'usermodified', 0, $where, $params);
    }

    /**
     * Anonymise the personal reference of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            [$where, $params] = self::scope_for_context($context);
            if ($where === null) {
                continue;
            }
            $params['userid'] = $userid;
            if ($context->contextlevel === CONTEXT_USER) {
                $DB->delete_records_select('local_stackmatheditor', "{$where} AND usermodified = :userid", $params);
                continue;
            }
            $DB->set_field_select(
                'local_stackmatheditor',
                'usermodified',
                0,
                "{$where} AND usermodified = :userid",
                $params
            );
        }
    }

    /**
     * Anonymise the personal reference of several users in one context (one bulk update).
     *
     * @param approved_userlist $userlist The approved context and user information.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        [$where, $params] = self::scope_for_context($userlist->get_context());
        $userids = $userlist->get_userids();
        if ($where === null || !$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        if ($userlist->get_context()->contextlevel === CONTEXT_USER) {
            $DB->delete_records_select(
                'local_stackmatheditor',
                "{$where} AND usermodified {$insql}",
                array_merge($params, $inparams)
            );
            return;
        }
        $DB->set_field_select(
            'local_stackmatheditor',
            'usermodified',
            0,
            "{$where} AND usermodified {$insql}",
            array_merge($params, $inparams)
        );
    }

    /**
     * SQL condition selecting the records that belong to a context.
     *
     * @param \context $context Context to scope to.
     * @return array [where, params]; where is null for contexts this plugin does not use.
     */
    protected static function scope_for_context(\context $context): array {
        if ($context->contextlevel === CONTEXT_MODULE) {
            return ['cmid = :cmid', ['cmid' => $context->instanceid]];
        }
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            return ['cmid = 0', []];
        }
        if ($context->contextlevel === CONTEXT_USER) {
            // Orphans of this user. The statements using this condition have no table alias.
            $condition = \local_stackmatheditor\data_maintenance::orphan_condition('');
            return [
                "usermodified = :ctxowner AND {$condition}",
                ['ctxowner' => $context->instanceid] + \local_stackmatheditor\data_maintenance::orphan_params(),
            ];
        }
        return [null, []];
    }

    /**
     * Scope key of one configuration record; the export shows it through privacy:scope_<key>.
     *
     * @param \stdClass $record Record of local_stackmatheditor.
     * @return string Scope key: global, quiz or question.
     */
    protected static function scope_name(\stdClass $record): string {
        if ((int) $record->cmid === 0) {
            return 'global';
        }
        return $record->questionbankentryid === null ? 'quiz' : 'question';
    }
}
