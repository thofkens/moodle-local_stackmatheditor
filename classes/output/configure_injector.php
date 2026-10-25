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
use local_stackmatheditor\quiz_helper;
use local_stackmatheditor\output\page_helper;

/**
 * Injects configure links for STACK questions on quiz pages.
 *
 * On mod-quiz-edit, also supplies a quiz-level configure URL so the JS
 * can insert a "STACK MathQuill-Editor einrichten" option into the
 * quiz navigation selector.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configure_injector {
    /**
     * Inject configuration link data and initialise the AMD module.
     *
     * @param int $cmid Course module ID of the quiz.
     * @return void
     */
    public static function inject(int $cmid): void {
        global $PAGE;

        quiz_helper::dbg(
            'configure_injector: cmid=' . $cmid
                . ' pagetype=' . $PAGE->pagetype
        );

        $configureurl = (new \moodle_url('/local/stackmatheditor/configure.php'))->out(false);
        $linktext = get_string('configure_editor', 'local_stackmatheditor');
        $quizlinktextraw = get_string('configure_quiz_nav', 'local_stackmatheditor');
        $modname = ($PAGE->cm && !empty($PAGE->cm->modname)) ? $PAGE->cm->modname : 'quiz';
        $returnurl = quiz_helper::get_return_url($cmid, $modname);

        $linkdata = self::build_link_data(
            $cmid,
            $configureurl,
            $returnurl,
            $linktext,
            $quizlinktextraw
        );

        if (empty($linkdata)) {
            quiz_helper::dbg('configure_injector: no data, skipping');
            return;
        }

        quiz_helper::dbg('configure_injector: injecting, mode=' . $linkdata['mode']);

        page_helper::inject_json_element('sme-configure-data', $linkdata);

        $PAGE->requires->js_call_amd(
            'local_stackmatheditor/configure_links',
            'init',
            []
        );
    }

    /**
     * Route to the correct data-builder based on the current page type.
     *
     * @param int    $cmid           Course module ID.
     * @param string $configureurl   Base configure URL.
     * @param string $returnurl      Return URL after save.
     * @param string $linktext       Link text for question-level links.
     * @param string $quizlinktext   Link text for quiz-level nav option.
     * @return array Data array for the AMD module, or empty array if not applicable.
     */
    private static function build_link_data(
        int $cmid,
        string $configureurl,
        string $returnurl,
        string $linktext,
        string $quizlinktext
    ): array {
        global $PAGE;

        if (in_array($PAGE->pagetype, ['mod-quiz-attempt', 'mod-quiz-review'])) {
            return self::build_attempt_data($cmid, $configureurl, $returnurl, $linktext);
        }

        if ($PAGE->pagetype === 'mod-quiz-edit') {
            return self::build_edit_data(
                $cmid,
                $configureurl,
                $returnurl,
                $linktext,
                $quizlinktext
            );
        }

        return [];
    }

    /**
     * Build link data for quiz attempt and review pages.
     *
     * @param int    $cmid         Course module ID.
     * @param string $configureurl Base configure URL.
     * @param string $returnurl    Return URL after save.
     * @param string $linktext     Link text for configure anchors.
     * @return array Data array for the AMD module, or empty array if no STACK slots found.
     */
    private static function build_attempt_data(
        int $cmid,
        string $configureurl,
        string $returnurl,
        string $linktext
    ): array {
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if (!$attemptid) {
            return [];
        }

        // Links are built for this quiz's configuration page, so the attempt has to be one of its.
        $stackdata = quiz_helper::load_attempt_stack_slots(
            $attemptid,
            quiz_helper::get_quiz_instance_id($cmid)
        );
        if (empty($stackdata['slotmap'])) {
            return [];
        }

        $slots = [];
        foreach ($stackdata['slotmap'] as $slot => $qid) {
            $slots[$slot] = [
                'questionid' => $qid,
                'qbeid' => $stackdata['qbeids'][$slot] ?? 0,
            ];
        }

        return [
            'mode' => 'attempt',
            'cmid' => $cmid,
            'configureUrl' => $configureurl,
            'returnUrl' => $returnurl,
            'slots' => $slots,
            'linkText' => $linktext,
        ];
    }

    /**
     * Build link data for the quiz edit page.
     *
     * Includes a quiz-level configure URL for the navigation selector.
     *
     * @param int    $cmid          Course module ID.
     * @param string $configureurl  Base configure URL.
     * @param string $returnurl     Return URL after save.
     * @param string $linktext      Link text for question-level anchors.
     * @param string $quizlinktext  Label for the quiz navigation selector option.
     * @return array Data array for the AMD module, or empty array if no STACK questions found.
     */
    private static function build_edit_data(
        int $cmid,
        string $configureurl,
        string $returnurl,
        string $linktext,
        string $quizlinktext
    ): array {
        $instanceid = quiz_helper::get_quiz_instance_id($cmid);
        if (!$instanceid) {
            return [];
        }

        $questions = quiz_helper::load_quiz_stack_questions($instanceid);
        if (empty($questions)) {
            return [];
        }

        // Quiz-level configure URL: cmid only, no qbeid, opens quiz-mode form.
        $quizconfigureurl = (new \moodle_url(
            '/local/stackmatheditor/configure.php',
            ['cmid' => $cmid, 'returnurl' => $returnurl]
        ))->out(false);

        return [
            'mode' => 'edit',
            'cmid' => $cmid,
            'configureUrl' => $configureurl,
            'quizConfigureUrl' => $quizconfigureurl,
            'quizLinkText' => $quizlinktext,
            'returnUrl' => $returnurl,
            'questions' => $questions,
            'linkText' => $linktext,
        ];
    }
}
