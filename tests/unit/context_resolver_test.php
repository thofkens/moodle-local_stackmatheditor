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
 * Tests for the page and context decisions of #50.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\context_resolver
 */
final class context_resolver_test extends \advanced_testcase {
    /**
     * The contexts that worked before still do.
     *
     * @return void
     */
    public function test_existing_contexts_are_kept(): void {
        foreach (
            [
            'mod-quiz-attempt',
            'mod-quiz-review',
            'question-preview',
            'question-bank-previewquestion',
            'mod-adaptivequiz-view',
            ] as $pagetype
        ) {
            $this->assertTrue(
                context_resolver::supports_page($pagetype),
                "$pagetype must keep working"
            );
        }
    }

    /**
     * The contexts the issue asks for are supported.
     *
     * @return void
     */
    public function test_new_contexts(): void {
        foreach (
            [
            'mod-capquiz-view',
            'mod-capquiz-attempt',
            'filter-embedquestion-showquestion',
            'mod-studentquiz-view',
            ] as $pagetype
        ) {
            $this->assertTrue(context_resolver::supports_page($pagetype), $pagetype);
        }
    }

    /**
     * An unrelated page does not load the editor.
     *
     * @return void
     */
    public function test_unrelated_pages(): void {
        foreach (['site-index', 'mod-forum-view', 'admin-search', 'mod-lesson-view'] as $pagetype) {
            $this->assertFalse(context_resolver::supports_page($pagetype), $pagetype);
        }
    }

    /**
     * An administrator can add a module without a code change.
     *
     * @return void
     */
    public function test_extra_page_types(): void {
        $this->resetAfterTest();

        $this->assertFalse(context_resolver::supports_page('mod-myactivity-attempt'));

        set_config(
            'extrapagetypes',
            "mod-myactivity-attempt\n mod-other-view ",
            'local_stackmatheditor'
        );

        $this->assertTrue(context_resolver::supports_page('mod-myactivity-attempt'));
        $this->assertTrue(context_resolver::supports_page('mod-other-view'));
        $this->assertFalse(context_resolver::supports_page('mod-third-view'));
    }

    /**
     * Nonsense in the setting is ignored rather than turned into a page type.
     *
     * @return void
     */
    public function test_extra_page_types_are_validated(): void {
        $this->resetAfterTest();

        set_config('extrapagetypes', "*\n<script>\n../etc/passwd", 'local_stackmatheditor');

        $this->assertSame([], context_resolver::get_extra_page_types());
        $this->assertFalse(context_resolver::supports_page('*'));
    }

    /**
     * Configuration stays with the modules whose context can be resolved.
     *
     * @return void
     */
    public function test_configuration_is_module_specific(): void {
        $this->assertTrue(context_resolver::can_resolve_question_context('quiz'));
        $this->assertTrue(context_resolver::can_resolve_question_context('adaptivequiz'));
        $this->assertFalse(context_resolver::can_resolve_question_context('capquiz'));
        $this->assertFalse(context_resolver::can_resolve_question_context(''));

        // Runtime and configuration are separate decisions: CAPQuiz gets the editor without a
        // configuration page of its own.
        $this->assertTrue(context_resolver::supports_page('mod-capquiz-view'));
        $this->assertFalse(context_resolver::has_configuration_ui('capquiz'));
    }
}
