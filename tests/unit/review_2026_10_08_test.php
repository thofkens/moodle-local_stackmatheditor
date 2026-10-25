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
 * The findings of the MDL Shield review of 2026-10-08, and the same kind of problem elsewhere.
 *
 * 1. The configuration page accepted any question bank entry from the request.
 * 2. A method called in lib.php did not exist; a catch-all hid the error.
 * 3. A CLI script used cli_writeln() without loading clilib.php.
 * 4. Two files carried the package tag of another plugin.
 *
 * Each has a test of its own here, and each a guard that looks for the same mistake in every
 * file of the plugin, so that the next one is not found by a reviewer either.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\quiz_helper
 */
final class review_2026_10_08_test extends \advanced_testcase {
    /**
     * A course with two quizzes, each using a question of its own.
     *
     * @return array [quiz A, quiz B, qbeid in A, qbeid in B]
     */
    private function two_quizzes(): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if (!function_exists('quiz_add_quiz_question')) {
            $this->markTestSkipped('this Moodle version adds questions to a quiz differently');
        }

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiza = $generator->create_module('quiz', ['course' => $course->id]);
        $quizb = $generator->create_module('quiz', ['course' => $course->id]);

        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category();
        $qa = $questions->create_question('truefalse', null, ['category' => $category->id]);
        $qb = $questions->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($qa->id, $quiza, 0, 1);
        quiz_add_quiz_question($qb->id, $quizb, 0, 1);
        // A quiz whose total is not recomputed refuses to start an attempt.
        foreach ([$quiza, $quizb] as $quiz) {
            \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        }

        return [
            $quiza,
            $quizb,
            (int) config_manager::resolve_qbeid((int) $qa->id),
            (int) config_manager::resolve_qbeid((int) $qb->id),
        ];
    }

    /**
     * Finding 1: a quiz uses its own entries and no others.
     *
     * @return void
     */
    public function test_a_quiz_uses_only_its_own_entries(): void {
        $this->resetAfterTest();

        [$quiza, $quizb, $qbea, $qbeb] = $this->two_quizzes();

        $this->assertTrue(quiz_helper::quiz_uses_entry((int) $quiza->id, $qbea));
        $this->assertTrue(quiz_helper::quiz_uses_entry((int) $quizb->id, $qbeb));
        $this->assertFalse(quiz_helper::quiz_uses_entry((int) $quiza->id, $qbeb), 'another quiz\'s question');
        $this->assertFalse(quiz_helper::quiz_uses_entry((int) $quiza->id, 987654321), 'no such entry');
        $this->assertFalse(quiz_helper::quiz_uses_entry((int) $quiza->id, 0));
        $this->assertFalse(quiz_helper::quiz_uses_entry(0, $qbea));
    }

    /**
     * Finding 1: the page asks before it reads anything about the question.
     *
     * @return void
     */
    public function test_the_configuration_page_checks_the_entry_first(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/stackmatheditor/configure.php');

        $check = strpos($source, 'quiz_helper::require_configurable_question(');
        $this->assertNotFalse($check, 'configure.php must resolve the question through the checked helper');

        // After the login gate, before anything is shown, evaluated or saved.
        $this->assertLessThan($check, strpos($source, 'require_login('));
        foreach (['question_bank::load_question(', 'get_semantics_summary(', '$mform->get_data()'] as $later) {
            $this->assertNotFalse(strpos($source, $later), $later);
            $this->assertLessThan(strpos($source, $later), $check, "the check comes before {$later}");
        }
        // No second, unchecked way to the question.
        $this->assertStringNotContainsString('WHERE qv.questionbankentryid = :qbeid', $source);
    }

    /**
     * An attempt id from the request only counts for its own quiz.
     *
     * @return void
     */
    public function test_an_attempt_counts_only_for_its_own_quiz(): void {
        global $DB;

        $this->resetAfterTest();

        [$quiza, $quizb] = $this->two_quizzes();
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $quizobj = \mod_quiz\quiz_settings::create((int) $quiza->id, (int) $student->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, (int) $student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        // The loader only reports STACK questions; make the true/false question count as one.
        $questionid = (int) $DB->get_field('question_attempts', 'questionid', ['questionusageid' => $quba->get_id()]);
        $DB->set_field('question', 'qtype', 'stack', ['id' => $questionid]);

        $own = quiz_helper::load_attempt_stack_slots((int) $attempt->id, (int) $quiza->id);
        $foreign = quiz_helper::load_attempt_stack_slots((int) $attempt->id, (int) $quizb->id);

        $this->assertSame([1 => $questionid], $own['slotmap']);
        $this->assertSame([], $foreign['slotmap'], 'an attempt of quiz A says nothing for quiz B');
    }

    /**
     * Finding 2: without mod_adaptivequiz there is nothing to find.
     *
     * @return void
     */
    public function test_adaptive_quiz_without_the_module(): void {
        global $DB;

        if ($DB->get_manager()->table_exists('adaptivequiz_question')) {
            $this->markTestSkipped('mod_adaptivequiz is installed on this site');
        }
        $this->assertFalse(quiz_helper::adaptivequiz_has_stack_questions(1));
        $this->assertFalse(quiz_helper::adaptivequiz_has_stack_questions(0));
    }

    /**
     * Finding 2: an adaptive quiz with a STACK question in one of its categories, and one without.
     *
     * mod_adaptivequiz is not installed in CI, so its association table is created here as a
     * temporary table with the module's own definition (instance, questioncategory).
     *
     * @return void
     */
    public function test_adaptive_quiz_categories_are_searched(): void {
        global $DB;

        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        $created = false;
        if (!$dbman->table_exists('adaptivequiz_question')) {
            $table = new \xmldb_table('adaptivequiz_question');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('instance', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('questioncategory', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_temp_table($table);
            $created = true;
        }

        try {
            $questions = $this->getDataGenerator()->get_plugin_generator('core_question');
            $withstack = $questions->create_question_category();
            $without = $questions->create_question_category();
            $stack = $questions->create_question('truefalse', null, ['category' => $withstack->id]);
            $questions->create_question('truefalse', null, ['category' => $without->id]);
            // The query looks at the question type only; the content does not matter.
            $DB->set_field('question', 'qtype', 'stack', ['id' => $stack->id]);

            $DB->insert_record('adaptivequiz_question', (object) ['instance' => 701, 'questioncategory' => $without->id]);
            $this->assertFalse(quiz_helper::adaptivequiz_has_stack_questions(701), 'no STACK question in its category');

            $DB->insert_record('adaptivequiz_question', (object) ['instance' => 701, 'questioncategory' => $withstack->id]);
            $this->assertTrue(quiz_helper::adaptivequiz_has_stack_questions(701), 'a second category with one');
            $this->assertFalse(quiz_helper::adaptivequiz_has_stack_questions(702), 'another instance');
        } finally {
            if ($created) {
                $dbman->drop_table(new \xmldb_table('adaptivequiz_question'));
            }
        }
    }

    /**
     * Finding 2, the general case: a programming error caught by a catch-all is reported.
     *
     * @return void
     */
    public function test_a_caught_programming_error_is_reported(): void {
        quiz_helper::caught(new \Error('Call to undefined method x::y()'), 'test');
        $this->assertDebuggingCalled();

        // A runtime problem - a missing record, a database hiccup - stays quiet.
        quiz_helper::caught(new \moodle_exception('invalidrecord'), 'test');
        $this->assertDebuggingNotCalled();
    }

    /**
     * Finding 2, everywhere: every static call to one of the plugin's classes names a method that
     * exists.
     *
     * The missing method was called inside a catch-all and never failed visibly. This reads every
     * PHP file of the plugin and resolves each Class::method( to the plugin's own classes.
     *
     * @return void
     */
    public function test_every_static_call_to_the_plugin_resolves(): void {
        $missing = [];
        $checked = 0;

        foreach ($this->plugin_php_files() as $relative => $path) {
            $tokens = token_get_all(file_get_contents($path));
            $namespace = '';
            $uses = [];
            $class = null;
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_NAMESPACE) {
                    [$namespace] = $this->name_after($tokens, $i);
                } else if ($token[0] === T_USE && $class === null) {
                    [$name, $alias] = $this->name_after($tokens, $i);
                    $short = $alias ?? substr($name, (int) strrpos('\\' . $name, '\\'));
                    $uses[strtolower($short)] = ltrim($name, '\\');
                } else if ($token[0] === T_CLASS && is_array($tokens[$i + 2] ?? null)) {
                    $class = ltrim($namespace . '\\' . $tokens[$i + 2][1], '\\');
                } else if ($token[0] === T_DOUBLE_COLON) {
                    $before = $tokens[$i - 1];
                    $method = $tokens[$i + 1] ?? null;
                    if (!is_array($before) || !is_array($method) || $method[0] !== T_STRING || ($tokens[$i + 2] ?? null) !== '(') {
                        continue;
                    }
                    $target = $this->resolve_class($before[1], $namespace, $uses, $class);
                    if ($target === null || strpos($target, 'local_stackmatheditor') !== 0) {
                        continue;
                    }
                    $checked++;
                    if (!class_exists($target) && !interface_exists($target)) {
                        $missing[] = "{$relative}:{$method[2]} class {$target}";
                    } else if (!method_exists($target, $method[1])) {
                        $missing[] = "{$relative}:{$method[2]} {$target}::{$method[1]}()";
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'the scan has to find the plugin\'s static calls');
        $this->assertSame([], $missing);
    }

    /**
     * Finding 3, everywhere: a script that uses the cli_* helpers loads them.
     *
     * @return void
     */
    public function test_every_cli_script_loads_clilib(): void {
        $offenders = [];

        foreach ($this->plugin_php_files() as $relative => $path) {
            $source = file_get_contents($path);
            if (strpos($source, "define('CLI_SCRIPT', true)") === false) {
                continue;
            }
            if (
                preg_match('/\bcli_(writeln|write|error|heading|problem|separator|input|get_params|logo)\s*\(/', $source)
                    && strpos($source, '/clilib.php') === false
            ) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'scripts calling cli_* without requiring clilib.php');
    }

    /**
     * Finding 4, everywhere: every package tag names this plugin.
     *
     * @return void
     */
    public function test_every_package_tag_names_this_plugin(): void {
        $wrong = [];

        foreach ($this->plugin_php_files() as $relative => $path) {
            if (preg_match_all('/^\s*\*\s*@package\s+(\S+)/m', file_get_contents($path), $matches)) {
                foreach ($matches[1] as $package) {
                    if ($package !== 'local_stackmatheditor') {
                        $wrong[] = "{$relative}: {$package}";
                    }
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    /**
     * Every PHP file of the plugin, without dependencies installed into it.
     *
     * @return array Relative path => absolute path.
     */
    private function plugin_php_files(): array {
        global $CFG;

        $root = $CFG->dirroot . '/local/stackmatheditor';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || preg_match('#/(node_modules|vendor|\.git)/#', $path)) {
                continue;
            }
            $files[substr($path, strlen($root) + 1)] = $path;
        }
        ksort($files);

        return $files;
    }

    /**
     * The (qualified) name that follows a keyword, up to ";" or "{".
     *
     * @param array $tokens Tokens.
     * @param int $i Index of the keyword.
     * @return array [name, alias or null]
     */
    private function name_after(array $tokens, int $i): array {
        $name = '';
        $alias = null;
        for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
            if (!is_array($tokens[$j])) {
                continue;
            }
            if ($tokens[$j][0] === T_AS) {
                $alias = '';
                continue;
            }
            if (in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if ($alias !== null) {
                    $alias .= $tokens[$j][1];
                } else {
                    $name .= $tokens[$j][1];
                }
            }
        }

        return [$name, $alias];
    }

    /**
     * The fully qualified class a static call refers to.
     *
     * @param string $raw Text before "::".
     * @param string $namespace Current namespace.
     * @param array $uses Imports, lower-case short name => fully qualified name.
     * @param string|null $class Class the call is written in.
     * @return string|null Class name, or null for parent::.
     */
    private function resolve_class(string $raw, string $namespace, array $uses, ?string $class): ?string {
        $lower = strtolower($raw);
        if ($lower === 'self' || $lower === 'static') {
            return $class;
        }
        if ($lower === 'parent') {
            return null;
        }
        if ($raw[0] === '\\') {
            return ltrim($raw, '\\');
        }
        $first = explode('\\', $raw)[0];
        if (isset($uses[strtolower($first)])) {
            return $uses[strtolower($first)] . substr($raw, strlen($first));
        }

        return ltrim($namespace . '\\' . $raw, '\\');
    }
}
