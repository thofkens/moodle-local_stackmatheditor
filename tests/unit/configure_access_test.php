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
 * Which question the configuration page may open (#84).
 *
 * The page takes the question from the request. These cases are the issue's regression list:
 * own question allowed; another quiz's question, another course's question, an unknown entry and a
 * question id that resolves to a foreign entry refused alike; a user without the right to view the
 * question refused although the question is in their quiz.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\quiz_helper::require_configurable_question
 */
final class configure_access_test extends \advanced_testcase {
    /** @var \stdClass Quiz the page is opened for. */
    private \stdClass $quiz;

    /** @var array Name => [question id, qbeid]. */
    private array $questions = [];

    /** @var \stdClass Editing teacher of the quiz's course. */
    private \stdClass $teacher;

    /** @var \stdClass The quiz's course. */
    private \stdClass $course;

    /**
     * Two courses, three quizzes, STACK questions in the course banks.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if (!function_exists('quiz_add_quiz_question')) {
            $this->markTestSkipped('this Moodle version adds questions to a quiz differently');
        }

        // Saving a STACK question goes through the editor's draft areas, which need a user.
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $questions = $generator->get_plugin_generator('core_question');

        $this->course = $generator->create_course();
        $other = $generator->create_course();
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $neighbour = $generator->create_module('quiz', ['course' => $this->course->id]);
        $foreign = $generator->create_module('quiz', ['course' => $other->id]);

        $own = $questions->create_question_category(['contextid' => \context_course::instance($this->course->id)->id]);
        $far = $questions->create_question_category(['contextid' => \context_course::instance($other->id)->id]);

        $make = function (string $name, int $category, \stdClass $quiz, string $qtype = 'stack') use ($questions) {
            $question = $qtype === 'stack'
                ? $questions->create_question('stack', 'test3', ['category' => $category])
                : $questions->create_question($qtype, null, ['category' => $category]);
            quiz_add_quiz_question($question->id, $quiz, 0, 1);
            $this->questions[$name] = [(int) $question->id, (int) config_manager::resolve_qbeid((int) $question->id)];
        };
        $make('own', (int) $own->id, $this->quiz);
        $make('ownnotstack', (int) $own->id, $this->quiz, 'truefalse');
        $make('neighbour', (int) $own->id, $neighbour);
        $make('foreign', (int) $far->id, $foreign);

        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * Call the helper for the quiz the page is opened for.
     *
     * @param int $qbeid Entry from the request.
     * @param int $questionid Question id from the request.
     * @return \stdClass The question.
     */
    private function open(int $qbeid, int $questionid = 0): \stdClass {
        return quiz_helper::require_configurable_question((int) $this->quiz->id, $qbeid, $questionid);
    }

    /**
     * The quiz's own STACK question opens, by entry and by question id.
     *
     * @return void
     */
    public function test_own_question_is_allowed(): void {
        [$id, $qbeid] = $this->questions['own'];

        $this->assertSame($id, (int) $this->open($qbeid)->id);
        $this->assertSame($qbeid, (int) $this->open(0, $id)->qbeid);
    }

    /**
     * Every way to a question outside the quiz ends in the same error.
     *
     * @return void
     */
    public function test_questions_outside_the_quiz_are_refused_alike(): void {
        $cases = [
            'another quiz in the same course' => [$this->questions['neighbour'][1], 0],
            'a quiz in another course' => [$this->questions['foreign'][1], 0],
            'an entry that does not exist' => [987654321, 0],
            'a question id resolving to a foreign entry' => [0, $this->questions['foreign'][0]],
            'nothing at all' => [0, 0],
        ];
        foreach ($cases as $case => [$qbeid, $questionid]) {
            try {
                $this->open($qbeid, $questionid);
                $this->fail("{$case}: must be refused");
            } catch (\moodle_exception $e) {
                $this->assertSame('cannotresolveqbeid', $e->errorcode, $case);
            }
        }
    }

    /**
     * A question of the quiz that is not a STACK question is not configured.
     *
     * @return void
     */
    public function test_a_question_of_another_type_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('notstackquestion', 'local_stackmatheditor'));
        $this->open($this->questions['ownnotstack'][1]);
    }

    /**
     * Managing the quiz is not enough: the user has to be allowed to view the question.
     *
     * @return void
     */
    public function test_without_the_right_to_view_the_question_it_is_refused(): void {
        global $DB;

        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $context = \context_course::instance($this->course->id);
        assign_capability('moodle/question:viewall', CAP_PROHIBIT, $role, $context->id, true);
        assign_capability('moodle/question:viewmine', CAP_PROHIBIT, $role, $context->id, true);

        // Still allowed to manage the quiz ...
        $cm = get_coursemodule_from_instance('quiz', $this->quiz->id, $this->course->id, false, MUST_EXIST);
        $this->assertTrue(has_capability('mod/quiz:manage', \context_module::instance($cm->id)));

        // ... but not to see this question.
        try {
            $this->open($this->questions['own'][1]);
            $this->fail('a question the user may not view must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }
}
