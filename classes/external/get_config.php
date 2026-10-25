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

namespace local_stackmatheditor\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_stackmatheditor\config_manager;
use local_stackmatheditor\quiz_helper;

/**
 * External API: retrieve MathQuill toolbar configuration for a set of questions.
 *
 * Intended for future AJAX use (e.g. lazy-loading per-question config on the
 * quiz attempt page). Currently the editor_injector injects all configs server-
 * side; this endpoint provides a fallback for dynamic scenarios.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_config extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(
                PARAM_INT,
                'Course module ID of the quiz'
            ),
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Question ID')
            ),
        ]);
    }

    /**
     * Return toolbar configs for the given question IDs within a quiz.
     *
     * The caller has to prove three things before anything is answered: the course module
     * is a quiz, the user may view it, and each question asked about is used by that quiz.
     * Question ids that fail the last test are dropped from the answer - fail closed - rather
     * than resolved against the whole question bank.
     *
     * Each remaining question ID is resolved to its question bank entry ID, then the full config
     * lookup chain (question → quiz-default → instance-default) is applied via
     * config_manager::get_configs().
     *
     * @param int   $cmid        Course module ID of the quiz.
     * @param int[] $questionids List of question IDs (version-specific).
     * @return array List of {questionid, config (JSON string)} objects.
     */
    public static function execute(int $cmid, array $questionids): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'questionids' => $questionids]
        );

        // The course module must really be a quiz: a context id alone says nothing about what
        // the module is.
        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);

        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // A valid context is not an authorisation. Being able to see the quiz is the minimum this
        // endpoint requires.
        require_capability('mod/quiz:view', $context);

        // And a valid question id does not prove the question belongs to this quiz. Only the
        // entries the quiz actually uses may be answered for; anything else is dropped rather
        // than resolved globally.
        $allowed = quiz_helper::load_quiz_qbeids((int) $cm->instance);

        // Resolve question IDs to question bank entry IDs.
        $qbeids     = [];
        $qbeidmap   = [];
        foreach ($params['questionids'] as $qid) {
            $qbeid = config_manager::resolve_qbeid($qid);
            if ($qbeid && isset($allowed[$qbeid])) {
                $qbeids[]          = $qbeid;
                $qbeidmap[$qbeid]  = $qid;
            }
        }

        $configs = config_manager::get_configs(
            $params['cmid'],
            $qbeids,
            $qbeidmap
        );

        $result = [];
        foreach ($qbeidmap as $qbeid => $qid) {
            $result[] = [
                'questionid' => (int) $qid,
                'config'     => json_encode(
                    $configs[$qbeid] ?? [],
                    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
                ),
            ];
        }
        return $result;
    }

    /**
     * Describe the return value of execute().
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'questionid' => new external_value(
                    PARAM_INT,
                    'Question ID'
                ),
                'config'     => new external_value(
                    PARAM_RAW,
                    'JSON-encoded toolbar configuration'
                ),
            ])
        );
    }
}
