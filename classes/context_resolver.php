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
 * Which pages may host an editable STACK input.
 *
 * The editor extends STACK questions, not a module name. Whether a page carries a STACK input is
 * decided in the browser, where the rendered input either exists or does not; this class only
 * decides where the small bootstrap is worth loading, and which of those pages the plugin can
 * also resolve a question-bank context for.
 *
 * Two levels, deliberately separate:
 *
 *  - runtime: any page in the list below gets the editor, with per-question settings where they
 *    can be resolved and with the site defaults where they cannot. A module the plugin has never
 *    heard of is still usable.
 *  - configuration: resolving a course module, a question-bank entry, a capability and a return
 *    URL stays module specific and is only claimed for the modules that have been checked.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_resolver {
    /**
     * Page types that are known to render question-engine output.
     *
     * mod_adaptivequiz is the odd one: its attempt.php sets the page URL to view.php, so both
     * pages share the type and are told apart by the cmid parameter.
     */
    public const KNOWN_PAGE_TYPES = [
        'mod-quiz-attempt',
        'mod-quiz-review',
        'question-preview',
        'question-bank-previewquestion',
        'question-bank-previewquestion-preview',
        'mod-adaptivequiz-view',
        'mod-capquiz-view',
        'mod-capquiz-attempt',
        'mod-studentquiz-view',
        'filter-embedquestion-showquestion',
    ];

    /**
     * Modules whose question-bank context the plugin can resolve.
     *
     * Everything else runs with the site defaults rather than not at all.
     */
    public const RESOLVABLE_MODULES = ['quiz', 'adaptivequiz'];

    /**
     * Page types an administrator added for a module this plugin does not know.
     *
     * A new question-engine consumer should not need a code change to be usable.
     *
     * @return array Page types, lowercased and trimmed.
     */
    public static function get_extra_page_types(): array {
        $raw = (string) get_config('local_stackmatheditor', 'extrapagetypes');
        $types = [];

        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $type) {
            $type = strtolower(trim($type));
            if (preg_match('/^[a-z0-9_-]+$/', $type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Return true when the editor should be loaded on this page type.
     *
     * @param string $pagetype Moodle page type.
     * @return bool
     */
    public static function supports_page(string $pagetype): bool {
        if (in_array($pagetype, self::KNOWN_PAGE_TYPES, true)) {
            return true;
        }

        return in_array(strtolower($pagetype), self::get_extra_page_types(), true);
    }

    /**
     * Return true when per-question configuration can be resolved for this module.
     *
     * @param string $modname Module name, or an empty string outside an activity.
     * @return bool
     */
    public static function can_resolve_question_context(string $modname): bool {
        return in_array($modname, self::RESOLVABLE_MODULES, true);
    }

    /**
     * Return true when this module has a configuration page of its own.
     *
     * @param string $modname Module name.
     * @return bool
     */
    public static function has_configuration_ui(string $modname): bool {
        return self::can_resolve_question_context($modname);
    }

    /**
     * Return true when the configuration page offers a configuration per question for this module.
     *
     * mod_quiz has slots with a fixed question each, so a question can be configured within the
     * quiz. mod_adaptivequiz draws its questions from categories at attempt time; it has one
     * configuration for the whole activity. configure.php and the README support table follow
     * this method.
     *
     * @param string $modname Module name.
     * @return bool
     */
    public static function supports_question_configuration(string $modname): bool {
        return $modname === 'quiz';
    }
}
