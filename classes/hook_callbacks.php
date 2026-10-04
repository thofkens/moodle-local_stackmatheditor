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

use local_stackmatheditor\output\mathjax_injector;
use local_stackmatheditor\output\editor_injector;
use local_stackmatheditor\output\configure_injector;

/**
 * Hook callbacks for local_stackmatheditor.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Pages where the MathQuill editor is injected.
     *
     * Note: mod_adaptivequiz's attempt.php calls
     *   $PAGE->set_url('/mod/adaptivequiz/view.php', ['cmid' => $cm->id])
     * which results in pagetype 'mod-adaptivequiz-view'.  The additional
     * is_adaptivequiz_attempt() guard prevents activation on the plain view page.
     *
     * @var string[]
     */
    private const EDITOR_PAGES = [
        'mod-quiz-attempt',
        'mod-quiz-review',
        'question-preview',
        'question-bank-previewquestion',
        'question-bank-previewquestion-preview',
        'mod-adaptivequiz-view',
    ];

    /**
     * Pages where mod_quiz configure links are injected via JavaScript.
     *
     * mod_adaptivequiz is intentionally absent: its configure link is provided
     * through the standard Moodle settings navigation (extend_settings_navigation
     * in lib.php) and does not require JS injection.
     *
     * @var string[]
     */
    private const CONFIGURE_PAGES = [
        'mod-quiz-edit',
        'mod-quiz-attempt',
        'mod-quiz-review',
    ];

    /**
     * Return true if the plugin could ever be active (mode != 0).
     *
     * Mode 0 is a hard instance-wide disable with no overrides possible,
     * so we can skip all further processing entirely.
     *
     * @return bool
     */
    private static function plugin_could_be_active(): bool {
        return config_manager::get_instance_enabled_mode() !== 0;
    }

    /**
     * Return true if the current page is the mod_adaptivequiz attempt page.
     *
     * mod_adaptivequiz's attempt.php sets the page URL to view.php, resulting
     * in pagetype 'mod-adaptivequiz-view' for both attempt.php and view.php.
     * The two pages are distinguished by the URL parameter used:
     *   attempt.php → ?cmid=<id>
     *   view.php    → ?id=<id>
     *
     * @return bool
     */
    private static function is_adaptivequiz_attempt(): bool {
        global $PAGE;
        if ($PAGE->pagetype !== 'mod-adaptivequiz-view') {
            return false;
        }
        // Attempt.php passes 'cmid'; view.php passes 'id'.
        return optional_param('cmid', 0, PARAM_INT) > 0;
    }

    /**
     * Return true if the current page is one where the editor should run.
     *
     * For mod-adaptivequiz-view, only the actual attempt page qualifies;
     * the plain view page (student overview / teacher report) does not.
     *
     * @return bool
     */
    private static function is_editor_page(): bool {
        global $PAGE;
        if (!in_array($PAGE->pagetype, self::EDITOR_PAGES)) {
            return false;
        }
        if ($PAGE->pagetype === 'mod-adaptivequiz-view') {
            return self::is_adaptivequiz_attempt();
        }
        return true;
    }

    /**
     * Return true if the current page is one where mod_quiz configure links appear.
     *
     * @return bool
     */
    private static function is_configure_page(): bool {
        global $PAGE;
        return in_array($PAGE->pagetype, self::CONFIGURE_PAGES);
    }

    /**
     * Register the MathJax v2 Hub compatibility shim as an early AMD module.
     *
     * local_stackmatheditor/mathjax_shim is registered here — in the earliest
     * available hook — so that its js_call_amd() entry appears before any AMD
     * calls registered by qtype_stack during page-content rendering.  Because
     * the module has zero dependencies and is small, it is typically evaluated
     * by RequireJS before STACK's modules finish loading.
     *
     * A 500-iteration polling loop inside the module provides a safety net for
     * the rare case where MathJax v3 (loaded by filter_mathjaxloader) has not
     * yet replaced window.MathJax when the module first runs.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function before_top_of_body(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $PAGE;

        if (!self::plugin_could_be_active()) {
            return;
        }

        if (!self::is_editor_page()) {
            return;
        }

        $PAGE->requires->js_call_amd('local_stackmatheditor/mathjax_shim', 'install', []);
    }

    /**
     * Main injection: toolbar definitions, editor runtime, and configure links.
     *
     * Editor injection is performed for both mod_quiz and mod_adaptivequiz pages.
     * Configure link injection (via JS) is performed for mod_quiz pages only;
     * mod_adaptivequiz exposes its configure link through the standard Moodle
     * settings navigation (see lib.php → local_stackmatheditor_extend_settings_navigation).
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE;

        if (!self::plugin_could_be_active()) {
            return;
        }

        $iseditor    = self::is_editor_page();
        $isconfigure = self::is_configure_page();

        quiz_helper::dbg(
            'before_footer: page=' . $PAGE->pagetype
            . ' editor=' . ($iseditor ? 'Y' : 'N')
            . ' configure=' . ($isconfigure ? 'Y' : 'N')
        );

        if (!$iseditor && !$isconfigure) {
            return;
        }

        $cmid = quiz_helper::get_cmid();

        // Editor injection (mod_quiz and mod_adaptivequiz).
        if ($iseditor) {
            if (!config_manager::get_effective_enabled($cmid)) {
                quiz_helper::dbg('editor: disabled for cmid=' . $cmid . ', skipping');
            } else {
                try {
                    mathjax_injector::inject();
                    editor_injector::inject($cmid);
                    quiz_helper::dbg('editor injected: cmid=' . $cmid);
                } catch (\Throwable $e) {
                    quiz_helper::dbg('editor injection error: ' . $e->getMessage());
                }
            }
        }

        // Configure links injection (mod_quiz only, via JavaScript).
        // Mod_adaptivequiz configure links are provided by extend_settings_navigation.
        // Always inject configure links when the user can manage the quiz,
        // Regardless of the enabled mode — the configure page handles the toggle.
        if ($isconfigure) {
            try {
                if ($cmid <= 0) {
                    quiz_helper::dbg('configure: no cmid, skipping');
                    return;
                }

                quiz_helper::dbg(
                    'configure guard: can_manage='
                    . (quiz_helper::can_manage_quiz($cmid) ? 'true' : 'false')
                );

                if (quiz_helper::can_manage_quiz($cmid)) {
                    configure_injector::inject($cmid);
                }
            } catch (\Throwable $e) {
                quiz_helper::dbg('configure injection error: ' . $e->getMessage());
            }
        }
    }
}
