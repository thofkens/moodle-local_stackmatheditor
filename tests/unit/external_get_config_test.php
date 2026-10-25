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

use local_stackmatheditor\external\get_config;

/**
 * Authorisation tests for the external service (#67, regression of #14).
 *
 * A valid Moodle context is not an authorisation, and a valid question id does not prove that
 * the question belongs to the quiz that was asked about. Both were missing from the runtime
 * path; these tests fail if either disappears again.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\external\get_config
 */
final class external_get_config_test extends \advanced_testcase {
    /**
     * Course, quiz and an enrolled student.
     *
     * @return array [course, quiz cm, user]
     */
    private function make_quiz(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        return [$course, $cm, $user];
    }

    /**
     * A question id nobody knows is dropped, not resolved against the whole bank.
     *
     * @return void
     */
    public function test_unknown_question_is_dropped(): void {
        $this->resetAfterTest();
        [, $cm, $user] = $this->make_quiz();
        $this->setUser($user);

        $result = get_config::execute((int) $cm->id, [987654321]);

        $this->assertSame([], $result);
    }

    /**
     * A question that belongs to another quiz must not be answered for.
     *
     * @return void
     */
    public function test_a_question_from_another_quiz_is_not_answered_for(): void {
        global $CFG;

        $this->resetAfterTest();

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if (!function_exists('quiz_add_quiz_question')) {
            $this->markTestSkipped('this Moodle version adds questions to a quiz differently');
        }

        [$course, $cma, $user] = $this->make_quiz();
        $generator = $this->getDataGenerator();

        // A second quiz in the same course, with a question of its own.
        $quizb = $generator->create_module('quiz', ['course' => $course->id]);
        $cmb = get_coursemodule_from_instance('quiz', $quizb->id, $course->id, false, MUST_EXIST);

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
        ]);
        quiz_add_quiz_question($question->id, $quizb, 0);

        $qbeid = config_manager::resolve_qbeid((int) $question->id);
        $this->assertNotEmpty($qbeid, 'the question must have a bank entry');

        // The question is in quiz B ...
        $this->assertArrayHasKey(
            (int) $qbeid,
            quiz_helper::load_quiz_qbeids((int) $cmb->instance)
        );
        // ... and not in quiz A.
        $this->assertArrayNotHasKey(
            (int) $qbeid,
            quiz_helper::load_quiz_qbeids((int) $cma->instance)
        );

        $this->setUser($user);

        // Asking quiz A about quiz B's question answers nothing.
        $this->assertSame([], get_config::execute((int) $cma->id, [(int) $question->id]));

        // The same question through its own quiz is in scope and is answered for.
        $own = get_config::execute((int) $cmb->id, [(int) $question->id]);
        $this->assertCount(1, $own);
        $this->assertSame((int) $question->id, $own[0]['questionid']);
        $this->assertJson($own[0]['config']);
    }

    /**
     * Without the capability there is no answer at all.
     *
     * Moodle can refuse in two ways here, and both are correct: prohibiting mod/quiz:view also
     * makes the activity inaccessible, so require_login() may object before require_capability()
     * is reached. The test asserts what matters - no data comes back - and accepts either.
     *
     * @return void
     */
    public function test_without_the_capability(): void {
        $this->resetAfterTest();
        [, $cm, $user] = $this->make_quiz();

        $context = \context_module::instance($cm->id);
        $role = $this->getDataGenerator()->create_role();
        role_assign($role, $user->id, $context->id);
        assign_capability('mod/quiz:view', CAP_PROHIBIT, $role, $context->id, true);

        $this->setUser($user);

        try {
            get_config::execute((int) $cm->id, []);
            $this->fail('the service answered a user who may not view the quiz');
        } catch (\moodle_exception $e) {
            $this->assertTrue(
                $e instanceof \required_capability_exception
                    || str_contains(get_class($e), 'require_login'),
                'expected an access error, got ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * The capability check is in the executable path (#67, regression of #14).
     *
     * #14 was closed after require_capability() had been added, and it disappeared again. A
     * behavioural test cannot see that on its own: prohibiting the capability also blocks the
     * login check, so the service would look correct even without the line. This asserts the
     * line is there, and that it comes after validate_context().
     *
     * @return void
     */
    public function test_the_capability_check_is_in_the_code(): void {
        global $CFG;

        $source = file_get_contents(
            $CFG->dirroot . '/local/stackmatheditor/classes/external/get_config.php'
        );
        $this->assertNotFalse($source);

        $validate = strpos($source, 'self::validate_context(');
        $capability = strpos($source, "require_capability('mod/quiz:view'");

        $this->assertNotFalse($validate, 'validate_context() is missing');
        $this->assertNotFalse($capability, 'require_capability() is missing - this is #14 again');
        $this->assertGreaterThan(
            $validate,
            $capability,
            'require_capability() must come after validate_context()'
        );
    }

    /**
     * A course module that is not a quiz is refused.
     *
     * @return void
     */
    public function test_a_course_module_that_is_not_a_quiz(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $this->expectException(\dml_missing_record_exception::class);
        get_config::execute((int) $forum->cmid, []);
    }

    /**
     * A course module id that does not exist is refused.
     *
     * @return void
     */
    public function test_a_course_module_that_does_not_exist(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\dml_missing_record_exception::class);
        get_config::execute(987654321, []);
    }

    /**
     * A quiz without slots scopes to nothing rather than to everything.
     *
     * @return void
     */
    public function test_an_empty_quiz_scopes_to_nothing(): void {
        $this->resetAfterTest();
        [, $cm] = $this->make_quiz();

        $this->assertSame([], quiz_helper::load_quiz_qbeids((int) $cm->instance));
    }
}
