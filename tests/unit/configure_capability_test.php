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
 * Configuring is authorised by one write capability per module, everywhere (#86, #90).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\quiz_helper::configure_capability
 * @covers     \local_stackmatheditor\quiz_helper::can_configure
 * @covers     ::local_stackmatheditor_extend_settings_navigation
 */
final class configure_capability_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private $course;

    /** @var \stdClass Quiz course module. */
    private $cm;

    /**
     * A course with one quiz.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $quiz->id);
    }

    /**
     * A user enrolled with the given role.
     *
     * @param string $role Role shortname.
     * @return \stdClass User.
     */
    private function enrol(string $role): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $role);
        return $user;
    }

    /**
     * The mapping: quiz and adaptive quiz have a write capability, other modules none.
     *
     * @return void
     */
    public function test_capability_mapping(): void {
        $this->assertSame('mod/quiz:manage', quiz_helper::configure_capability('quiz'));
        $this->assertSame('moodle/course:manageactivities', quiz_helper::configure_capability('adaptivequiz'));
        $this->assertNull(quiz_helper::configure_capability('forum'));
        $this->assertNull(quiz_helper::configure_capability(''));
    }

    /**
     * Both capabilities are write capabilities of module context or above, granted to editing
     * teachers and managers and not to non-editing teachers.
     *
     * @return void
     */
    public function test_capabilities_are_write_capabilities(): void {
        foreach (['quiz', 'adaptivequiz'] as $modname) {
            $capability = quiz_helper::configure_capability($modname);
            $info = get_capability_info($capability);
            $this->assertNotEmpty($info, $capability);
            $this->assertSame('write', $info->captype, $capability);
            $this->assertLessThanOrEqual(CONTEXT_MODULE, (int) $info->contextlevel, $capability);
            $this->assertSame(CAP_ALLOW, get_default_capabilities('editingteacher')[$capability] ?? null, $capability);
            $this->assertSame(CAP_ALLOW, get_default_capabilities('manager')[$capability] ?? null, $capability);
            $this->assertArrayNotHasKey($capability, get_default_capabilities('teacher'), $capability);
            $this->assertArrayNotHasKey($capability, get_default_capabilities('student'), $capability);
        }
    }

    /**
     * Editing teacher may, non-editing teacher (who can see the reports) may not, a prohibit on
     * the module takes the right away again, and the capability without access to the activity
     * (not enrolled, or the activity hidden) is not enough.
     *
     * @return void
     */
    public function test_can_configure_by_role(): void {
        $context = \context_module::instance($this->cm->id);

        $this->setUser($this->enrol('editingteacher'));
        $this->assertTrue(quiz_helper::can_configure((int) $this->cm->id));

        $teacher = $this->enrol('teacher');
        $this->setUser($teacher);
        $this->assertTrue(has_capability('mod/quiz:viewreports', $context), 'precondition: report access');
        $this->assertFalse(quiz_helper::can_configure((int) $this->cm->id));

        $editor = $this->enrol('editingteacher');
        $roleid = (int) $this->getDataGenerator()->create_role();
        $this->getDataGenerator()->role_assign($roleid, $editor->id, $context->id);
        assign_capability('mod/quiz:manage', CAP_PROHIBIT, $roleid, $context->id, true);
        $this->setUser($editor);
        $this->assertFalse(quiz_helper::can_configure((int) $this->cm->id));

        // The capability without access to the activity: not enrolled in the course.
        $outsider = $this->getDataGenerator()->create_user();
        $managerole = (int) $this->getDataGenerator()->create_role();
        assign_capability('mod/quiz:manage', CAP_ALLOW, $managerole, $context->id, true);
        $this->getDataGenerator()->role_assign($managerole, $outsider->id, $context->id);
        $this->setUser($outsider);
        $this->assertTrue(has_capability('mod/quiz:manage', $context), 'precondition: capability');
        $this->assertFalse(quiz_helper::can_configure((int) $this->cm->id), 'no access to the course');

        // Enrolled with the capability, but the activity is hidden from them.
        $student = $this->enrol('student');
        $this->getDataGenerator()->role_assign($managerole, $student->id, $context->id);
        set_coursemodule_visible((int) $this->cm->id, 0);
        $this->setUser($student);
        $this->assertTrue(has_capability('mod/quiz:manage', $context), 'precondition: capability');
        $this->assertFalse(quiz_helper::can_configure((int) $this->cm->id), 'hidden activity');
        set_coursemodule_visible((int) $this->cm->id, 1);
        $this->assertTrue(quiz_helper::can_configure((int) $this->cm->id), 'visible again');

        $this->setAdminUser();
        $this->assertFalse(quiz_helper::can_configure(0));
        $this->assertFalse(quiz_helper::can_configure(PHP_INT_MAX));
        $this->assertTrue(quiz_helper::can_manage_quiz((int) $this->cm->id), 'the old name follows');
    }

    /**
     * Build the settings navigation of the quiz page as the current user.
     *
     * The page becomes the global $PAGE: the callback and other plugins' callbacks read it.
     *
     * @return \settings_navigation Initialised settings navigation.
     */
    private function settings_navigation(): \settings_navigation {
        global $PAGE;
        $PAGE = new \moodle_page();
        $page = $PAGE;
        $page->set_url('/mod/quiz/view.php', ['id' => $this->cm->id]);
        $page->set_cm($this->cm, $this->course);
        $page->set_context(\context_module::instance($this->cm->id));
        $page->set_pagelayout('incourse');
        $nav = new \settings_navigation($page);
        $nav->initialise();
        return $nav;
    }

    /**
     * The real navigation callback: the link exists exactly for users who may configure.
     *
     * @return void
     */
    public function test_navigation_link_follows_capability(): void {
        set_config('enabled', 3, 'local_stackmatheditor');

        $this->setUser($this->enrol('editingteacher'));
        $nav = $this->settings_navigation();
        $node = $nav->find('stackmatheditor_configure', \navigation_node::TYPE_SETTING);
        $this->assertNotFalse($node, 'editing teacher sees the link');
        $this->assertTrue($node->action->compare(
            new \moodle_url('/local/stackmatheditor/configure.php'),
            URL_MATCH_BASE
        ));
        $this->assertEquals($this->cm->id, $node->action->get_param('cmid'));

        $this->setUser($this->enrol('teacher'));
        $nav = $this->settings_navigation();
        $this->assertFalse(
            $nav->find('stackmatheditor_configure', \navigation_node::TYPE_SETTING),
            'non-editing teacher does not'
        );
    }

    /**
     * Navigation and configuration page take the capability from the same helper; the report
     * capability is gone from both.
     *
     * @return void
     */
    public function test_navigation_and_page_share_the_check(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/stackmatheditor/';
        foreach (['lib.php', 'configure.php'] as $file) {
            $source = file_get_contents($root . $file);
            $this->assertStringContainsString('quiz_helper::configure_capability(', $source, $file);
            $this->assertStringNotContainsString('adaptivequiz:viewreport', $source, $file);
            $this->assertStringNotContainsString("'mod/quiz:manage'", $source, $file);
        }
        $hooks = file_get_contents($root . 'classes/hook_callbacks.php');
        $this->assertStringContainsString('quiz_helper::can_configure(', $hooks);
    }

    /**
     * Build the settings navigation of any module page as the current user.
     *
     * @param \stdClass $cm Course module.
     * @return \settings_navigation Initialised settings navigation.
     */
    private function module_navigation(\stdClass $cm): \settings_navigation {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
        $PAGE->set_cm($cm, $this->course);
        $PAGE->set_context(\context_module::instance($cm->id));
        $PAGE->set_pagelayout('incourse');
        $nav = new \settings_navigation($PAGE);
        $nav->initialise();
        return $nav;
    }

    /**
     * mod_adaptivequiz, end to end through the real callback (#86, #90): the link appears for an
     * editing teacher when the question pool holds a STACK question, not without one, and not
     * for a role that may only see the reports.
     *
     * Runs where mod_adaptivequiz is installed (the CI job for Moodle 4.5 installs it); elsewhere
     * it skips with that reason.
     *
     * @return void
     */
    public function test_adaptivequiz_navigation_link(): void {
        global $CFG;
        if (!file_exists($CFG->dirroot . '/mod/adaptivequiz/version.php')) {
            $this->markTestSkipped('mod_adaptivequiz is not installed on this site');
        }
        set_config('enabled', 3, 'local_stackmatheditor');

        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $questions = $generator->get_plugin_generator('core_question');
        $context = \context_course::instance($this->course->id);
        $withstack = $questions->create_question_category(['contextid' => $context->id]);
        $without = $questions->create_question_category(['contextid' => $context->id]);
        $questions->create_question('stack', 'test3', ['category' => $withstack->id]);
        $questions->create_question('truefalse', null, ['category' => $without->id]);

        $make = function (array $pool): \stdClass {
            $instance = $this->getDataGenerator()->create_module('adaptivequiz', [
                'course' => $this->course->id,
                'questionpool' => $pool,
            ]);
            return get_coursemodule_from_instance('adaptivequiz', $instance->id);
        };
        $stackcm = $make([(int) $withstack->id]);
        $plaincm = $make([(int) $without->id]);
        $this->assertTrue(quiz_helper::adaptivequiz_has_stack_questions((int) $stackcm->instance));
        $this->assertFalse(quiz_helper::adaptivequiz_has_stack_questions((int) $plaincm->instance));

        $teacher = $this->enrol('editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(quiz_helper::can_configure((int) $stackcm->id));
        $node = $this->module_navigation($stackcm)->find('stackmatheditor_configure', \navigation_node::TYPE_SETTING);
        $this->assertNotFalse($node, 'editing teacher, STACK question in the pool: link');
        $this->assertEquals($stackcm->id, $node->action->get_param('cmid'));
        $this->assertFalse(
            $this->module_navigation($plaincm)->find('stackmatheditor_configure', \navigation_node::TYPE_SETTING),
            'no STACK question in the pool: no link'
        );

        // A role that may see the reports but not manage activities.
        $reporter = $this->enrol('student');
        $roleid = (int) $generator->create_role();
        $modulecontext = \context_module::instance($stackcm->id);
        assign_capability('mod/adaptivequiz:viewreport', CAP_ALLOW, $roleid, $modulecontext->id, true);
        $generator->role_assign($roleid, $reporter->id, $modulecontext->id);
        $this->setUser($reporter);
        $this->assertTrue(has_capability('mod/adaptivequiz:viewreport', $modulecontext), 'precondition');
        $this->assertFalse(quiz_helper::can_configure((int) $stackcm->id));
        $this->assertFalse(
            $this->module_navigation($stackcm)->find('stackmatheditor_configure', \navigation_node::TYPE_SETTING),
            'report access alone: no link'
        );
    }
}
