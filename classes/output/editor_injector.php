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

namespace local_stackmatheditor\output;

use local_stackmatheditor\config_manager;
use local_stackmatheditor\definitions;
use local_stackmatheditor\quiz_helper;
use local_stackmatheditor\output\page_helper;

/**
 * Injects MathQuill editor runtime data.
 *
 * Computes a per-slot enabled map (slotEnabled) and passes it to the
 * JS runtime so input_fields.js / textarea_fields.js can skip disabled slots.
 *
 * For mod_adaptivequiz, per-slot configuration is not available (only one
 * question is shown at a time and there is no per-question override UI).
 * The runtime is injected with empty slot maps; all inputs fall back to
 * the quiz-level default (or instance default) via instanceDefaults.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editor_injector {
    /**
     * Inject MathQuill CSS, init call, and runtime JSON.
     *
     * @param int $cmid Course module ID.
     * @return void
     */
    public static function inject(int $cmid): void {
        global $PAGE;

        $plugindir = __DIR__ . '/../../thirdparty/mathquill/';
        $jsfile    = file_exists($plugindir . 'mathquill.min.js')
            ? 'mathquill.min.js'
            : 'mathquill.js';

        $mqjsurl  = (new \moodle_url(
            '/local/stackmatheditor/thirdparty/mathquill/' . $jsfile
        ))->out(false);
        $mqcssurl = (new \moodle_url(
            '/local/stackmatheditor/thirdparty/mathquill/mathquill.css'
        ))->out(false);

        // Resolve per-slot configs.
        // For mod_adaptivequiz pages this returns [] — the JS falls back to instanceDefaults.
        $slotconfigs  = self::resolve_slot_configs($cmid);
        $slotvarmodes = self::build_variable_modes($slotconfigs);

        // Build per-slot enabled map.
        $slotenabled = self::build_slot_enabled($slotconfigs);

        $instancevarmode = config_manager::get_instance_variable_mode();

        // For mod_adaptivequiz: use the quiz-level default as instanceDefaults so
        // The JS runtime picks up any quiz-level customisation even though the
        // per-slot maps are empty.
        $instancedefaults = self::resolve_instance_defaults($cmid);

        // AMD init call.
        $PAGE->requires->js_call_amd(
            'local_stackmatheditor/mathquill_init',
            'init',
            [[
                'mathquillJsUrl'  => $mqjsurl,
                'mathquillCssUrl' => $mqcssurl,
                'cmid'            => $cmid,
                'variableMode'    => $instancevarmode,
            ]]
        );

        // Runtime JSON element.
        page_helper::inject_json_element('sme-runtime', [
            'slotConfigs'      => !empty($slotconfigs) ? $slotconfigs : new \stdClass(),
            'slotVarModes'     => !empty($slotvarmodes) ? $slotvarmodes : new \stdClass(),
            'slotEnabled'      => !empty($slotenabled) ? $slotenabled : new \stdClass(),
            'instanceDefaults' => $instancedefaults,
        ]);
    }

    /**
     * Resolve the instance defaults to pass to the JS runtime.
     *
     * For mod_quiz and question preview pages the standard instance defaults
     * are used; per-slot configs override them.
     *
     * For mod_adaptivequiz there are no per-slot configs, so the quiz-level
     * default (if one has been saved) is promoted to instanceDefaults.  This
     * ensures any quiz-level toolbar customisation is honoured.
     *
     * @param int $cmid Course module ID.
     * @return array Instance defaults config array.
     */
    private static function resolve_instance_defaults(int $cmid): array {
        global $PAGE;

        if ($PAGE->pagetype === 'mod-adaptivequiz-view' && $cmid > 0) {
            $quizdefault = config_manager::get_quiz_default($cmid);
            if ($quizdefault !== null) {
                return $quizdefault;
            }
        }

        return config_manager::get_instance_defaults();
    }

    /**
     * Resolve slot configs depending on page type.
     *
     * Returns an empty array for mod_adaptivequiz: there is no per-slot
     * configuration UI, and the JS runtime falls back to instanceDefaults for
     * every input it finds.
     *
     * @param int $cmid Course module ID.
     * @return array Slot => config.
     */
    private static function resolve_slot_configs(int $cmid): array {
        global $PAGE;

        if (in_array($PAGE->pagetype, ['mod-quiz-attempt', 'mod-quiz-review'])) {
            return self::resolve_attempt_configs($cmid);
        }
        if (in_array($PAGE->pagetype, ['question-preview', 'question-bank-previewquestion', 'question-bank-previewquestion-preview'])) {
            return self::resolve_preview_configs($cmid);
        }
        // Mod-adaptivequiz-view and any future page types: no per-slot config.
        return [];
    }

    /**
     * Resolve configs from a quiz attempt.
     *
     * @param int $cmid Course module ID.
     * @return array Slot => config.
     */
    private static function resolve_attempt_configs(int $cmid): array {
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if (!$attemptid) {
            return [];
        }

        $stackdata = quiz_helper::load_attempt_stack_slots($attemptid);
        if (empty($stackdata['slotmap'])) {
            return [];
        }

        $slotconfigs = [];

        if (!empty($stackdata['qbeids'])) {
            $qbeids  = array_values(array_unique($stackdata['qbeids']));
            $configs = config_manager::get_configs($cmid, $qbeids, $stackdata['qbeidmap']);
            foreach ($stackdata['qbeids'] as $slot => $qbeid) {
                $slotconfigs[$slot] = $configs[$qbeid]
                    ?? config_manager::get_instance_defaults();
            }
        }

        // Fallback for slots without qbeid.
        foreach ($stackdata['slotmap'] as $slot => $qid) {
            if (!isset($slotconfigs[$slot])) {
                $slotconfigs[$slot] = config_manager::get_config($cmid, 0, $qid);
            }
        }

        return $slotconfigs;
    }

    /**
     * Resolve configs for question preview.
     *
     * @param int $cmid Course module ID.
     * @return array Slot => config.
     */
    private static function resolve_preview_configs(int $cmid): array {
        $questionid = optional_param('id', 0, PARAM_INT);
        if (!$questionid) {
            return [];
        }
        $qbeid = config_manager::resolve_qbeid($questionid);
        if (!$qbeid) {
            return [];
        }
        return [1 => config_manager::get_config($cmid, $qbeid)];
    }

    /**
     * Build variable modes from slot configs.
     *
     * @param array $slotconfigs Slot => config.
     * @return array Slot => mode string.
     */
    private static function build_variable_modes(array $slotconfigs): array {
        $modes   = [];
        $default = config_manager::get_instance_variable_mode();
        foreach ($slotconfigs as $slot => $config) {
            $modes[$slot] = definitions::normalise_implicit_mode(
                (string) ($config['_variableMode'] ?? $default)
            );
        }
        return $modes;
    }

    /**
     * Build per-slot enabled map.
     *
     * For each slot, determines whether MathQuill should be activated:
     *   - Instance mode 0: always false
     *   - Instance mode 1: always true
     *   - Instance mode 2: false unless _enabled=true in config
     *   - Instance mode 3: true unless _enabled=false in config
     *
     * The _enabled value in $slotconfigs already reflects the full
     * lookup chain (question-level → quiz-level → instance defaults).
     *
     * For mod_adaptivequiz, $slotconfigs is always empty so this returns
     * an empty array.  The JS runtime does not find a slot entry and
     * therefore does not suppress any input field.
     *
     * @param array $slotconfigs Slot => merged config array.
     * @return array Slot => bool (true = activate MathQuill).
     */
    private static function build_slot_enabled(array $slotconfigs): array {
        $instancemode = config_manager::get_instance_enabled_mode();
        $enabled      = [];

        foreach ($slotconfigs as $slot => $cfg) {
            if ($instancemode === 0) {
                $enabled[$slot] = false;
            } else if ($instancemode === 1) {
                $enabled[$slot] = true;
            } else {
                // Modes 2 and 3: honour _enabled flag if present.
                if (array_key_exists('_enabled', $cfg)) {
                    $enabled[$slot] = (bool) $cfg['_enabled'];
                } else {
                    // No explicit setting: mode 3 defaults to on, mode 2 to off.
                    $enabled[$slot] = ($instancemode === 3);
                }
            }
        }

        return $enabled;
    }
}
