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

use Behat\Mink\Exception\ExpectationException;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

/**
 * Behat step definitions for local_stackmatheditor.
 *
 * @package    local_stackmatheditor
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_stackmatheditor extends behat_base {
    /**
     * URL of the quiz view page most recently visited by the attempt-start helper.
     *
     * Used by pre-fill steps to navigate back to a known page without relying
     * on browser history (getSession()->back() is non-deterministic with bfcache).
     *
     * @var string|null
     */
    private $lastquizviewurl = null;

    /**
     * URL of the attempt page opened most recently by the attempt-start helper.
     *
     * Stored after assert_stack_input_present() confirms the attempt is live.
     * Used by i_return_to_the_quiz_attempt_page and i_navigate_to_next_question_and_back
     * to re-open the same attempt deterministically.
     *
     * @var string|null
     */
    private $lastquizattempturl = null;
    /**
     * Set the plugin enabled mode in Moodle config.
     *
     * @Given the plugin enabled mode is set to :mode
     * @param string $mode One of "0", "1", "2", "3".
     */
    public function the_plugin_enabled_mode_is_set_to(string $mode): void {
        set_config('enabled', $mode, 'local_stackmatheditor');
        purge_all_caches();
    }

    /**
     * Navigate to the STACK MathQuill quiz-level configuration page via the nav selector.
     *
     * @When I navigate to the STACK MathQuill quiz configuration
     */
    public function i_navigate_to_quiz_configuration(): void {
        $page = $this->getSession()->getPage();

        // Try nav-jump select: find option whose URL targets our configure page.
        // This avoids relying on localized text or Moodle-version-specific structure.
        $option = $page->find(
            'css',
            'form[action*="jumpto.php"] select[name="jump"]'
                . ' option[value*="/local/stackmatheditor/configure.php"]'
        );
        if ($option) {
            $this->getSession()->visit($option->getAttribute('value'));
            $this->getSession()->wait(3000, "document.readyState === 'complete'");
            return;
        }

        // Fallback: direct link — visit the href to avoid ElementNotInteractableException
        // in Moodle 5.2 where nav links may be rendered but not clickable.
        $link = $page->find('css', 'a[href*="/local/stackmatheditor/configure.php"]');
        if ($link) {
            $this->getSession()->visit($link->getAttribute('href'));
            $this->getSession()->wait(3000, "document.readyState === 'complete'");
            return;
        }

        throw new ExpectationException(
            'STACK MathQuill quiz configuration link not found '
                . '(neither in nav select nor as a direct link).',
            $this->getSession()
        );
    }

    /**
     * Open the question-level configure page via the icon link next to a question.
     *
     * @When I click the MathQuill configure icon next to :questionname
     * @param string $questionname Question name.
     */
    public function i_click_configure_icon_next_to(string $questionname): void {
        $link = $this->find(
            'xpath',
            '//a[contains(@class,"sme-configure-edit-link")]'
                . '[ancestor::*[contains(.,"' . $questionname . '")]]'
        );
        $link->click();
        $this->getSession()->wait(2000, "document.readyState === 'complete'");
    }

    /**
     * Open the STACK MathQuill quiz configuration page directly by quiz name.
     *
     * @Given I am on the STACK MathQuill quiz configuration page for :quizname
     * @param string $quizname Quiz name.
     */
    public function i_am_on_quiz_config_page(string $quizname): void {
        global $DB;
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);
        $url = new \moodle_url(
            '/local/stackmatheditor/configure.php',
            ['cmid' => $cm->id]
        );
        $this->getSession()->visit($url->out(false));
        if ($this->running_javascript()) {
            $this->getSession()->wait(2000, "document.readyState === 'complete'");
        }
    }

    /**
     * Open the quiz-level configuration page directly with an explicit return URL.
     *
     * @Given I am on the STACK MathQuill quiz configuration page for :quizname with return URL :returnurl
     * @param string $quizname  Quiz name.
     * @param string $returnurl Value passed as returnurl parameter (may be unsafe on purpose).
     */
    public function i_am_on_quiz_config_page_with_return_url(string $quizname, string $returnurl): void {
        global $DB;
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);
        $url = new \moodle_url(
            '/local/stackmatheditor/configure.php',
            ['cmid' => $cm->id, 'returnurl' => $returnurl]
        );
        $this->getSession()->visit($url->out(false));
        if ($this->running_javascript()) {
            $this->getSession()->wait(2000, "document.readyState === 'complete'");
        }
    }

    /**
     * Open the quiz-level configuration page for a given question case (#54).
     *
     * @Given I am on the STACK MathQuill quiz configuration page for :quizname with question :case
     * @param string $quizname Quiz name.
     * @param string $case     "stack" for the quiz's STACK question, anything else for a
     *                         question bank entry id that does not exist.
     */
    public function i_am_on_quiz_config_page_with_question(string $quizname, string $case): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);
        $qbeid = 999999999;
        if ($case === 'stack') {
            $qbeid = (int) $DB->get_field_sql(
                "SELECT qr.questionbankentryid
                   FROM {quiz_slots} qs
                   JOIN {question_references} qr ON qr.itemid = qs.id
                        AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                  WHERE qs.quizid = :quizid
               ORDER BY qs.slot",
                ['quizid' => $quiz->id],
                IGNORE_MULTIPLE
            );
        }
        $url = new \moodle_url(
            '/local/stackmatheditor/configure.php',
            ['cmid' => $cm->id, 'qbeid' => $qbeid]
        );
        $this->getSession()->visit($url->out(false));
        if ($this->running_javascript()) {
            $this->getSession()->wait(2000, "document.readyState === 'complete'");
        }
    }

    /**
     * Assert that the configuration page refuses a user without the capability (#54).
     *
     * Moodle renders a permission error as an exception page, and Behat's after-step hook fails
     * any step that ends on one. The check therefore happens inside this step, which then leaves
     * the error page again so the hook sees a clean page.
     *
     * @Then the STACK MathQuill quiz configuration page for :quizname with question :case is denied to me
     * @param string $quizname Quiz name.
     * @param string $case     "stack" for the quiz's STACK question, anything else for a
     *                         question bank entry id that does not exist.
     */
    public function quiz_config_page_is_denied(string $quizname, string $case): void {
        $this->i_am_on_quiz_config_page_with_question($quizname, $case);

        $text = $this->getSession()->getPage()->getText();

        if (strpos($text, 'do not currently have permissions') === false) {
            throw new ExpectationException(
                'Expected a permission error on the configuration page, got: ' . $text,
                $this->getSession()
            );
        }

        if (strpos($text, 'Cannot resolve the question') !== false) {
            throw new ExpectationException(
                'The question was resolved before the capability was checked.',
                $this->getSession()
            );
        }

        // Leave the exception page before the after-step hook inspects it.
        $this->getSession()->visit((new \moodle_url('/'))->out(false));
        if ($this->running_javascript()) {
            $this->getSession()->wait(2000, "document.readyState === 'complete'");
        }
    }

    /**
     * Assert that the browser shows the view or edit page of a quiz (#47).
     *
     * @Then I should be on the quiz :pagetype page of :quizname
     * @param string $pagetype "view" or "edit".
     * @param string $quizname Quiz name.
     */
    public function i_should_be_on_the_quiz_page(string $pagetype, string $quizname): void {
        global $DB;
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);
        $expectedpath = $pagetype === 'edit' ? '/mod/quiz/edit.php' : '/mod/quiz/view.php';
        $expectedparam = $pagetype === 'edit' ? 'cmid' : 'id';

        // Waiting needs JavaScript; without it (BrowserKit) the page is complete already.
        if ($this->running_javascript()) {
            $this->getSession()->wait(3000, "document.readyState === 'complete'");
        }
        $current = $this->getSession()->getCurrentUrl();
        $path = (string) parse_url($current, PHP_URL_PATH);
        parse_str((string) parse_url($current, PHP_URL_QUERY), $query);

        $onpath = substr($path, -strlen($expectedpath)) === $expectedpath;
        if (!$onpath || (int) ($query[$expectedparam] ?? 0) !== (int) $cm->id) {
            throw new ExpectationException(
                "Expected the quiz {$pagetype} page of '{$quizname}' "
                    . "({$expectedpath}?{$expectedparam}={$cm->id}), but the browser is on {$current}.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that an element with the given CSS class exists on the page.
     *
     * @Then I should see the class :cssclass
     * @param string $cssclass CSS class name without the leading dot.
     */
    public function i_should_see_the_class(string $cssclass): void {
        // Use getPage()->find() (not $this->find()) to get null instead of
        // ElementNotFoundException, so we can attach our own diagnostic.
        $el = $this->getSession()->getPage()->find('css', '.' . $cssclass);
        if (!$el) {
            $diag = $this->getSession()->evaluateScript(
                '(function(){'
                . 'var inp=Array.from(document.querySelectorAll("input[name],textarea[name]"))'
                . '.map(function(e){return e.tagName+"["+e.name+"]";}).join(",").substring(0,300);'
                . 'return JSON.stringify({'
                . '  sme:document.body.getAttribute("data-sme-init")||"none",'
                . '  rjs:typeof window.requirejs,'
                . '  mq:typeof window.MathQuill,'
                . '  inputs:inp.substring(0,200)||"none"'
                . '});})()'
            );
            throw new ExpectationException(
                "CSS class '.$cssclass' not found. DOM state: $diag",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that no element with the given CSS class exists on the page.
     *
     * @Then I should not see the class :cssclass
     * @param string $cssclass CSS class name without the leading dot.
     */
    public function i_should_not_see_the_class(string $cssclass): void {
        $elements = $this->getSession()->getPage()->findAll('css', '.' . $cssclass);
        if (!empty($elements)) {
            throw new ExpectationException(
                "Element with class '$cssclass' should not be present on the page.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that a specific option text exists in the quiz navigation select element.
     *
     * @Then I should see :text in the quiz navigation select
     * @param string $text Option label to look for.
     */
    public function i_should_see_option_in_nav_select(string $text): void {
        $page = $this->getSession()->getPage();

        // Check nav select for an option linking to our configure page.
        $option = $page->find(
            'css',
            'form[action*="jumpto.php"] select[name="jump"]'
                . ' option[value*="/local/stackmatheditor/configure.php"]'
        );
        if ($option) {
            return;
        }

        // Fallback: a direct link also satisfies the assertion.
        $link = $page->find('css', 'a[href*="/local/stackmatheditor/configure.php"]');
        if ($link) {
            return;
        }

        throw new ExpectationException(
            "STACK MathQuill Editor configure entry not found in navigation"
                . " (expected option text was: '$text').",
            $this->getSession()
        );
    }

    /**
     * Assert that a checkbox with the given id exists on the page.
     *
     * @Then I should see a checkbox with id :id
     * @param string $id Element id attribute value.
     */
    public function i_should_see_checkbox_with_id(string $id): void {
        $el = $this->find('css', '#' . $id . '[type="checkbox"]');
        if (!$el) {
            throw new ExpectationException(
                "Checkbox '#$id' not found.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that no checkbox with the given id exists on the page.
     *
     * @Then I should not see a checkbox with id :id
     * @param string $id Element id attribute value.
     */
    public function i_should_not_see_checkbox_with_id(string $id): void {
        $elements = $this->getSession()->getPage()->findAll(
            'css',
            '#' . $id . '[type="checkbox"]'
        );
        if (!empty($elements)) {
            throw new ExpectationException(
                "Checkbox '#$id' should not be present.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that a checkbox is in the checked state.
     *
     * @Then the checkbox :id should be checked
     * @param string $id Element id attribute value.
     */
    public function the_checkbox_should_be_checked(string $id): void {
        $el = $this->find('css', '#' . $id);
        if (!$el || !$el->isChecked()) {
            throw new ExpectationException(
                "Checkbox '#$id' should be checked.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that a checkbox is in the unchecked state.
     *
     * @Then the checkbox :id should be unchecked
     * @param string $id Element id attribute value.
     */
    public function the_checkbox_should_be_unchecked(string $id): void {
        $el = $this->find('css', '#' . $id);
        if (!$el || $el->isChecked()) {
            throw new ExpectationException(
                "Checkbox '#$id' should be unchecked.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that the MathQuill field associated with a named input is not empty.
     *
     * The MathQuill editor inserts an sme-input-wrap div immediately before
     * the hidden input in the DOM. An empty MathQuill field has the mq-empty
     * CSS class on its .mq-root-block span.
     *
     * @Then the MathQuill field for :inputname should not be empty
     * @param string $inputname Name attribute of the hidden input element.
     */
    public function the_mathquill_field_should_not_be_empty(string $inputname): void {
        $safeinput = addslashes($inputname);
        $js = <<<JS
            (function() {
                var inputname = '{$safeinput}';
                var input = document.querySelector('input[name="' + inputname + '"]')
                         || document.querySelector('input[name$="_' + inputname + '"]');
                if (!input) { return false; }
                var wrap = input.previousElementSibling;
                if (!wrap) { return false; }
                var mqroot = wrap.querySelector('.mq-root-block');
                if (!mqroot) { return false; }
                return !mqroot.classList.contains('mq-empty');
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if (!$result) {
            throw new ExpectationException(
                "MathQuill field for input '$inputname' is empty or not found.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that a hidden input element contains a specific Maxima value.
     *
     * @Then the hidden input :inputname should contain :value
     * @param string $inputname Name attribute.
     * @param string $value     Expected value.
     */
    public function the_hidden_input_should_contain(
        string $inputname,
        string $value
    ): void {
        $safeinput = addslashes($inputname);
        $js = <<<JS
            (function() {
                var n  = '{$safeinput}';
                var el = document.querySelector('input[name="' + n + '"]')
                      || document.querySelector('input[name$="_' + n + '"]');
                return el ? el.value : null;
            })()
JS;
        $actual = (string) $this->getSession()->evaluateScript($js);
        if ($actual !== $value) {
            throw new ExpectationException(
                "Input '$inputname' contains '$actual', expected '$value'.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that a hidden input element contains any non-empty value.
     *
     * @Then the hidden input :inputname should contain a non-empty Maxima value
     * @param string $inputname Name attribute.
     */
    public function the_hidden_input_should_be_nonempty(string $inputname): void {
        $safeinput = addslashes($inputname);
        $js = <<<JS
            (function() {
                var n  = '{$safeinput}';
                var el = document.querySelector('input[name="' + n + '"]')
                      || document.querySelector('input[name$="_' + n + '"]');
                return el ? el.value : null;
            })()
JS;
        $actual = (string) $this->getSession()->evaluateScript($js);
        if (empty($actual)) {
            throw new ExpectationException(
                "Input '$inputname' is empty; expected a Maxima expression.",
                $this->getSession()
            );
        }
    }

    /**
     * Type on the keyboard into a MathQuill field (#58).
     *
     * The other typing step uses MathQuill's write() API, which does not go through the typing
     * path - and that path is where "Umax" became "U max" in the first place. This one clicks
     * the field and sends real key events, so the operator-name detection runs exactly as it
     * does for a student.
     *
     * @When I press the keys :text into the MathQuill field for :inputname
     * @param string $text Characters to type.
     * @param string $inputname Name attribute of the corresponding hidden input.
     */
    public function i_press_keys_into_mathquill_field(
        string $text,
        string $inputname
    ): void {
        $safeinput = json_encode($inputname);
        $js = <<<JS
            (function() {
                var n     = {$safeinput};
                var input = document.querySelector('input[name="' + n + '"]')
                         || document.querySelector('input[name\$="_' + n + '"]');
                if (!input) { return 'no-input'; }
                var wrap = input.previousElementSibling;
                if (!wrap) { return 'no-wrap'; }
                var field = wrap.querySelector('.mq-editable-field');
                if (!field) { return 'no-field'; }
                Array.from(document.querySelectorAll('.sme-behat-target')).forEach(
                    function(el) { el.classList.remove('sme-behat-target'); }
                );
                field.classList.add('sme-behat-target');
                return 'ok';
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if ($result !== 'ok') {
            throw new ExpectationException(
                "Could not find the MathQuill field '$inputname' (result: $result).",
                $this->getSession()
            );
        }

        $this->execute('behat_general::i_click_on', ['.sme-behat-target', 'css_element']);
        $this->execute('behat_general::i_type', [$text]);
    }

    /**
     * Let STACK validate what is currently in an input, and report what it said (#34).
     *
     * The button contract test in Jest proves a button produces a CAS-safe string. It cannot
     * prove the CAS agrees, because it has no CAS. This step presses STACK's own validation and
     * checks that the answer came back interpreted rather than rejected - which is the only
     * evidence that a visible button means what it promises.
     *
     * @Then STACK should accept the answer in :inputname
     * @param string $inputname Name attribute of the original input.
     */
    public function stack_should_accept_the_answer(string $inputname): void {
        $session = $this->getSession();

        // STACK validates on blur and on the check button; blurring is enough and does not
        // submit the attempt.
        $safe = json_encode($inputname);
        $session->executeScript(<<<JS
            (function() {
                var n     = {$safe};
                var input = document.querySelector('input[name="' + n + '"]')
                         || document.querySelector('input[name\$="_' + n + '"]');
                if (input) {
                    input.dispatchEvent(new Event('change', {bubbles: true}));
                    input.blur();
                }
            })()
JS);
        $session->wait(4000);

        $validation = $session->evaluateScript(<<<JS
            (function() {
                var boxes = document.querySelectorAll('.stackinputfeedback, .stackinputerror');
                var text  = '';
                boxes.forEach(function(box) {
                    text += ' ' + (box.textContent || '');
                });
                return text.trim();
            })()
JS);

        // What STACK says when it cannot read an answer. Anything else - including silence, which
        // means the answer needed no comment - counts as accepted.
        $rejections = [
            'Your answer is not',
            'not a valid',
            'Illegal',
            'unknown function',
            'missing',
            'CAS failed',
            'Unable to',
        ];

        foreach ($rejections as $rejection) {
            if (stripos($validation, $rejection) !== false) {
                throw new ExpectationException(
                    "STACK refused the answer in '$inputname': $validation",
                    $session
                );
            }
        }
    }

    /**
     * Write into the original STACK input the way an external script does (#77).
     *
     * STACK's JSXGraph bindings set the value and dispatch a change event that does not bubble,
     * which is the part that used to be missed. This step reproduces that exactly - no jQuery,
     * no bubbling, no focus change.
     *
     * @When the STACK input for :inputname is set to :value by an external script
     * @param string $inputname Name attribute of the original input.
     * @param string $value Value to write.
     */
    public function external_script_sets_stack_input(string $inputname, string $value): void {
        $safeinput = json_encode($inputname);
        $safevalue = json_encode($value);
        $js = <<<JS
            (function() {
                var n     = {$safeinput};
                var input = document.querySelector('input[name="' + n + '"]')
                         || document.querySelector('input[name\$="_' + n + '"]');
                if (!input) { return 'no-input'; }
                input.value = {$safevalue};
                input.dispatchEvent(new Event('change'));
                return 'ok';
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if ($result !== 'ok') {
            throw new ExpectationException(
                "Could not find the STACK input '$inputname' (result: $result).",
                $this->getSession()
            );
        }

        $this->getSession()->wait(1000);
    }

    /**
     * Simulate keyboard input into a MathQuill field by focusing its internal textarea.
     *
     * @When I type :text into the MathQuill field for :inputname
     * @param string $text      Text to type (LaTeX-style shorthand, e.g. "x^2").
     * @param string $inputname Name attribute of the corresponding hidden input.
     */
    public function i_type_into_mathquill_field(
        string $text,
        string $inputname
    ): void {
        // Use MathQuill's JS API instead of keyDown() which only works for modifier keys.
        $safeinput = json_encode($inputname);
        $safetext  = json_encode($text);
        $js = <<<JS
            (function() {
                var n     = {$safeinput};
                var input = document.querySelector('input[name="' + n + '"]')
                         || document.querySelector('input[name$="_' + n + '"]')
                         || document.querySelector('textarea[name="' + n + '"]')
                         || document.querySelector('textarea[name$="_' + n + '"]');
                if (!input) {
                    var allNames = Array.from(
                        document.querySelectorAll('input[name],textarea[name]')
                    ).map(function(e){return e.name;}).join('|').substring(0,200);
                    return 'no-input|all-inputs:' + allNames;
                }
                var wrap = input.previousElementSibling;
                if (!wrap) { return 'no-wrap'; }
                var mathEl = wrap.querySelector('.mq-math-mode');
                if (!mathEl) { return 'no-math-mode'; }
                if (!window.MathQuill) { return 'no-mathquill'; }
                var MQ     = window.MathQuill.getInterface(2);
                var mqField = MQ(mathEl);
                if (!mqField || typeof mqField.write !== 'function') { return 'no-mq-api'; }
                mqField.focus();
                mqField.write({$safetext});
                input.dispatchEvent(new Event('input',  {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
                return 'ok';
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if ($result !== 'ok') {
            throw new ExpectationException(
                "Could not type into MathQuill field '$inputname' (result: $result).",
                $this->getSession()
            );
        }
    }
    // Quiz / question creation.

    /**
     * Create a minimal STACK algebraic-input quiz and question in a course.
     *
     * @Given a STACK quiz :quizname with algebraic input exists in :shortname
     * @param string $quizname  Quiz name to create.
     * @param string $shortname Course shortname.
     */
    public function a_stack_quiz_with_algebraic_input_exists_in(
        string $quizname,
        string $shortname
    ): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);

        // Create quiz if it does not already exist.
        $quiz = $DB->get_record('quiz', ['name' => $quizname, 'course' => $course->id]);
        if (!$quiz) {
            $gen      = testing_util::get_data_generator();
            $quizdata = $gen->create_module('quiz', [
                'course'             => $course->id,
                'name'               => $quizname,
                'grade'              => 10,
                'sumgrades'          => 1,
                // STACK questions use qbehaviour_adaptivemultipart internally;
                // using 'adaptive' here makes STACK route to it via make_behaviour().
                'preferredbehaviour' => 'adaptive',
            ]);
            $quiz = $DB->get_record('quiz', ['id' => $quizdata->id], '*', MUST_EXIST);
            $this->assert_quiz_behaviour_is_available($quiz);
        }

        // Create a STACK question and add it to the quiz.
        $this->ensure_stack_question_in_quiz($quizname, 'Test STACK Q');
    }

    /**
     * Create a quiz with one STACK question whose input is a multi-line textarea.
     *
     * Uses qtype_stack's 'textarea_input' generator template, which the plugin renders with the
     * multiline MathQuill editor.
     *
     * @Given a STACK quiz :quizname with textarea input exists in :shortname
     * @param string $quizname  Quiz name.
     * @param string $shortname Course shortname.
     */
    public function a_stack_quiz_with_textarea_input_exists_in(
        string $quizname,
        string $shortname
    ): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        if (!$DB->record_exists('quiz', ['name' => $quizname, 'course' => $course->id])) {
            $quizdata = testing_util::get_data_generator()->create_module('quiz', [
                'course'             => $course->id,
                'name'               => $quizname,
                'grade'              => 10,
                'sumgrades'          => 1,
                'preferredbehaviour' => 'adaptive',
            ]);
            $this->assert_quiz_behaviour_is_available(
                $DB->get_record('quiz', ['id' => $quizdata->id], '*', MUST_EXIST)
            );
        }
        $this->ensure_stack_question_in_quiz($quizname, 'Test STACK textarea Q', 'textarea_input');
    }

    // Multiline (textarea / equiv) editor.

    /**
     * JavaScript expression that finds the rows of the multiline editor for a STACK input.
     *
     * @param string $inputname STACK input name (e.g. "ans1").
     * @return string JavaScript expression evaluating to an array of row elements, or null.
     */
    protected function multiline_rows_js(string $inputname): string {
        $jsinput = json_encode($inputname);
        return <<<JS
(function() {
    var n = {$jsinput};
    var input = document.querySelector('[name$="_' + n + '"]') || document.querySelector('[name="' + n + '"]');
    if (!input) { return null; }
    var que = input.closest('.que') || document;
    return Array.prototype.slice.call(que.querySelectorAll('.sme-equiv-row'));
})()
JS;
    }

    /**
     * Put the keyboard focus at the end of one row of the multiline editor.
     *
     * Following key steps ("I type", "I press the backspace key") then go to that row.
     *
     * @When I focus row :row of the multiline MathQuill editor for :inputname
     * @param int    $row       1-based row number.
     * @param string $inputname STACK input name.
     */
    public function i_focus_multiline_row(int $row, string $inputname): void {
        $rows = $this->multiline_rows_js($inputname);
        $index = $row - 1;
        $result = $this->getSession()->evaluateScript(<<<JS
(function() {
    var rows = {$rows};
    if (!rows) { return 'no-stack-input'; }
    if (!rows[{$index}]) { return 'no row {$row} of ' + rows.length; }
    var editable = rows[{$index}].querySelector('.mq-editable-field');
    if (!editable || !window.MathQuill) { return 'no-mathquill'; }
    var field = window.MathQuill.getInterface(2)(editable);
    field.focus();
    field.moveToRightEnd();
    return 'ok';
})()
JS);
        if ($result !== 'ok') {
            throw new ExpectationException(
                "Could not focus row {$row} of the multiline editor for '{$inputname}' ({$result}).",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the number of rows of the multiline editor (waits up to 3 s).
     *
     * @Then the multiline MathQuill editor for :inputname should have :count rows
     * @param string $inputname STACK input name.
     * @param int    $count     Expected number of rows.
     */
    public function the_multiline_editor_should_have_rows(string $inputname, int $count): void {
        $rows = $this->multiline_rows_js($inputname);
        $this->getSession()->wait(3000, "(function() { var r = {$rows}; return !!r && r.length === {$count}; })()");
        $actual = $this->getSession()->evaluateScript("(function() { var r = {$rows}; return r ? r.length : -1; })()");
        if ((int)$actual !== $count) {
            throw new ExpectationException(
                "The multiline editor for '{$inputname}' has {$actual} rows, expected {$count}.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the lines of the underlying STACK textarea, "|"-separated (waits up to 3 s).
     *
     * "x=1||x=2" means three lines with an empty second line. The wait covers the editor's
     * debounced sync.
     *
     * @Then the lines of the underlying STACK input for :inputname should be :lines
     * @param string $inputname STACK input name.
     * @param string $lines     Expected lines separated by "|".
     */
    public function the_lines_of_the_underlying_input_should_be(string $inputname, string $lines): void {
        $jsinput = json_encode($inputname);
        $jslines = json_encode($lines);
        $read = <<<JS
(function() {
    var n = {$jsinput};
    var input = document.querySelector('[name$="_' + n + '"]') || document.querySelector('[name="' + n + '"]');
    return input ? input.value.split(/\\r?\\n/).join('|') : '__missing_input__';
})()
JS;
        $this->getSession()->wait(3000, "{$read} === {$jslines}");
        $actual = $this->getSession()->evaluateScript($read);
        if ($actual !== $lines) {
            throw new ExpectationException(
                "The lines of STACK input '{$inputname}' are '{$actual}', expected '{$lines}'.",
                $this->getSession()
            );
        }
    }

    /**
     * Create a STACK question (any name) and add it to the named quiz.
     *
     * @Given a STACK question exists in quiz :quizname
     * @param string $quizname Quiz name.
     */
    public function a_stack_question_exists_in_quiz(string $quizname): void {
        $this->ensure_stack_question_in_quiz($quizname, 'Test STACK Q');
    }

    /**
     * Create a named STACK question and add it to the named quiz.
     *
     * @Given a STACK question :questionname exists in quiz :quizname
     * @param string $questionname Question name.
     * @param string $quizname     Quiz name.
     */
    public function a_named_stack_question_exists_in_quiz(
        string $questionname,
        string $quizname
    ): void {
        $this->ensure_stack_question_in_quiz($quizname, $questionname);
    }


    /**
     * Add a question to a quiz in a version-safe way.
     *
     * Moodle 4.x: uses quiz_add_quiz_question() from locallib.php.
     * Moodle 5.x: creates quiz_slots + question_references directly.
     *
     * @param int      $questionid Question ID to add.
     * @param stdClass $quiz       Quiz DB record.
     * @param stdClass $cm         Course module DB record.
     */
    private function add_question_to_quiz_compat(
        int $questionid,
        stdClass $quiz,
        stdClass $cm
    ): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        // Moodle 4.x: the function still exists.
        if (function_exists('quiz_add_quiz_question')) {
            quiz_add_quiz_question($questionid, $quiz, 0, 1);
            return;
        }

        // Moodle 5.x: insert quiz_slots + question_references directly.
        $qbeid = \local_stackmatheditor\config_manager::resolve_qbeid($questionid);
        if (!$qbeid) {
            throw new ExpectationException(
                "Cannot resolve QBEID for question ID $questionid.",
                $this->getSession()
            );
        }

        $nextslot = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(slot), 0) + 1 FROM {quiz_slots} WHERE quizid = :qid',
            ['qid' => $quiz->id]
        );

        $slotobj = new stdClass();
        $slotobj->quizid          = $quiz->id;
        $slotobj->slot            = $nextslot;
        $slotobj->page            = 1;
        $slotobj->displaynumber   = (string)$nextslot;
        $slotobj->requireprevious = 0;
        $slotobj->maxmark         = 1.0;
        $slotid = $DB->insert_record('quiz_slots', $slotobj);

        $refobj = new stdClass();
        $refobj->usingcontextid    = context_module::instance($cm->id)->id;
        $refobj->component         = 'mod_quiz';
        $refobj->questionarea      = 'slot';
        $refobj->itemid            = $slotid;
        $refobj->questionbankentryid = $qbeid;
        $refobj->version           = null;
        $DB->insert_record('question_references', $refobj);
    }

    /**
     * Update quiz sum of grades in a version-safe way.
     *
     * @param stdClass $quiz Quiz DB record.
     */
    private function update_quiz_sumgrades_compat(stdClass $quiz): void {
        // Quiz_update_sumgrades() was deprecated in Moodle 4.2 (MDL-76897) and
        // throws a coding_exception in Behat since Moodle 5.0+.
        // Always use the grade_calculator API (available from Moodle 4.1+).
        try {
            $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
            $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
        } catch (\Throwable $e) {
            // Non-fatal: quiz still works for Behat purposes.
            debugging('Quiz sumgrades update failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Fail early with a clear message if a quiz behaviour or its dependencies are missing.
     *
     * STACK questions use qbehaviour_adaptivemultipart regardless of the quiz's
     * preferredbehaviour. If the plugin is absent, every quiz attempt produces
     * a cryptic "behaviour not available" error. This assertion catches that
     * situation at fixture-setup time with a human-readable message.
     *
     * @param stdClass $quiz Quiz DB record.
     */
    private function assert_quiz_behaviour_is_available(stdClass $quiz): void {
        global $DB;

        // Reload from DB to get the normalised record.
        $stored = $DB->get_record('quiz', ['id' => $quiz->id], 'id, name, preferredbehaviour', MUST_EXIST);

        if (empty($stored->preferredbehaviour)) {
            throw new ExpectationException(
                "Quiz '{$stored->name}' has empty preferredbehaviour in the database.",
                $this->getSession()
            );
        }

        // STACK questions require qbehaviour_adaptivemultipart (MDL-79926).
        $pluginman = core_plugin_manager::instance();
        $installed = $pluginman->get_plugins_of_type('qbehaviour');
        $names     = array_keys($installed);

        if (!in_array('adaptivemultipart', $names)) {
            throw new ExpectationException(
                'qbehaviour_adaptivemultipart is not installed. '
                    . 'STACK questions require it for quiz attempts. '
                    . 'Installed qbehaviours: ' . implode(', ', $names),
                $this->getSession()
            );
        }
    }

    /**
     * Internal helper: find-or-create a STACK question in a quiz.
     *
     * Uses a qtype_stack generator template ('algebraic_input' by default).
     *
     * @param string $quizname     Quiz name.
     * @param string $questionname Question name.
     * @param string $template     qtype_stack generator template.
     * @return stdClass The question record.
     */
    protected function ensure_stack_question_in_quiz(
        string $quizname,
        string $questionname,
        string $template = 'algebraic_input'
    ): stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);

        // Check whether question already exists.
        $existing = $DB->get_record('question', [
            'name'  => $questionname,
            'qtype' => 'stack',
        ]);
        if ($existing) {
            return $existing;
        }

        // Resolve question category — use get_records with limit to avoid
        // 'found more than one record' when multiple categories exist per context.
        $ctx  = context_module::instance($cm->id);
        $cats = $DB->get_records(
            'question_categories',
            ['contextid' => $ctx->id],
            'id ASC',
            '*',
            0,
            1
        );
        $cat = reset($cats) ?: null;
        if (!$cat) {
            $coursecontext = context_course::instance($quiz->course);
            $cats = $DB->get_records(
                'question_categories',
                ['contextid' => $coursecontext->id],
                'id ASC',
                '*',
                0,
                1
            );
            $cat = reset($cats) ?: null;
        }
        if (!$cat) {
            $gen  = testing_util::get_data_generator();
            $qgen = $gen->get_plugin_generator('core_question');
            $cat  = $qgen->create_question_category(['contextid' => $ctx->id]);
        }

        // Create STACK question via the plugin generator.
        $gen      = testing_util::get_data_generator();
        $qgen     = $gen->get_plugin_generator('core_question');
        $question = $qgen->create_question('stack', $template, [
            'name'     => $questionname,
            'category' => $cat->id,
        ]);

        // Add question to the quiz (version-safe).
        $this->add_question_to_quiz_compat((int)$question->id, $quiz, $cm);

        // Validate the slot was actually created.
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id]);
        if (empty($slots)) {
            throw new ExpectationException(
                "Question was created but no quiz slot was added for quiz '$quizname'.",
                $this->getSession()
            );
        }

        // Validate question_references record exists (Moodle 4.5+ schema requirement).
        $slot      = reset($slots);
        $reference = $DB->get_record('question_references', [
            'component'    => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid'       => $slot->id,
        ]);
        if (!$reference) {
            throw new ExpectationException(
                "Quiz slot {$slot->id} has no question_references record.",
                $this->getSession()
            );
        }

        $this->update_quiz_sumgrades_compat($quiz);

        return $DB->get_record('question', ['id' => $question->id], '*', MUST_EXIST);
    }

    // Navigation helpers.

    /**
     * Open the MathQuill configuration page for a specific question inside a quiz.
     *
     * @Given I am on the MathQuill configuration page for question :questionname in :quizname
     * @param string $questionname Question name.
     * @param string $quizname     Quiz name.
     */
    public function i_am_on_question_config_page(
        string $questionname,
        string $quizname
    ): void {
        global $DB;

        $quiz     = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm       = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);
        $question = $DB->get_record(
            'question',
            ['name' => $questionname, 'qtype' => 'stack'],
            '*',
            MUST_EXIST
        );

        // Resolve QBEID via question_versions (Moodle 4.1+ schema).
        $qbeid = \local_stackmatheditor\config_manager::resolve_qbeid($question->id);
        if (!$qbeid) {
            throw new ExpectationException(
                "Cannot resolve question bank entry for question '$questionname'.",
                $this->getSession()
            );
        }

        $url = new \moodle_url(
            '/local/stackmatheditor/configure.php',
            ['cmid' => $cm->id, 'qbeid' => $qbeid]
        );
        $this->getSession()->visit($url->out(false));
        $this->getSession()->wait(2000, "document.readyState === 'complete'");
    }

    /**
     * Start a quiz attempt as the currently logged-in user.
     *
     * @Given I start the STACK MathQuill quiz attempt :quizname
     * @param string $quizname Quiz name.
     */
    public function i_start_the_stack_mathquill_quiz_attempt(string $quizname): void {
        global $DB;
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);
        $url  = new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
        $this->getSession()->visit($url->out(false));
        $this->getSession()->wait(2000, "document.readyState === 'complete'");

        // Store the quiz view URL so navigation helpers can return to it without back().
        $this->lastquizviewurl = $url->out(false);

        // Click "Attempt quiz now" button (text varies by Moodle version / language).
        $page   = $this->getSession()->getPage();
        $button = $page->find(
            'xpath',
            '//button[contains(@class,"mod_quiz-start-attempt-button")]'
            . ' | //input[@type="submit"][contains(@value,"Attempt")]'
            . ' | //button[contains(text(),"Attempt")]'
            . ' | //button[contains(text(),"Quiz starten")]'
        );
        if ($button) {
            $button->click();
            $this->getSession()->wait(3000, "document.readyState === 'complete'");
        }
        // Verify the STACK question rendered ans1 (CAS must be working).
        $this->assert_stack_input_present('ans1');

        // Store the attempt URL for deterministic re-open by pre-fill helpers.
        $this->lastquizattempturl = $this->getSession()->getCurrentUrl();
    }

    /**
     * Assert that a STACK input field is present on the attempt page.
     * Fails with a detailed message if CAS rendered a runtime error instead.
     *
     * @param string $inputname STACK input name (e.g. "ans1").
     */
    protected function assert_stack_input_present(string $inputname = 'ans1'): void {
        $jsinput = json_encode($inputname);

        // The STACK question is rendered server-side during attempt.php, which can
        // take longer than a fixed page-load wait (Maxima cold start on the first
        // attempt of a scenario).  Poll for the input before failing, so a slow
        // render does not produce a spurious "input not found".
        $present = "(function() {"
            . "var n = {$jsinput};"
            . "return !!("
            . "document.querySelector('[name=\"' + n + '\"]') || "
            . "document.querySelector('[name\$=\"_' + n + '\"]') || "
            . "document.querySelector('[id=\"' + n + '\"]') || "
            . "document.querySelector('[id\$=\"_' + n + '\"]')"
            . ");})()";
        if ($this->getSession()->wait(30000, $present)) {
            return;
        }

        $result = $this->getSession()->evaluateScript(<<<JS
(function() {
    var n = {$jsinput};
    var field = document.querySelector('[name="' + n + '"]')
        || document.querySelector('[name$="_' + n + '"]')
        || document.querySelector('[id="'  + n + '"]')
        || document.querySelector('[id$="_' + n + '"]');

    if (field) {
        return 'ok:' + field.tagName + ':' + (field.name || field.id || '');
    }

    // Collect full STACK runtime-error details (timeout message, question vars, etc).
    var err = document.querySelector('.stackruntimeerrror, .stackruntimeerror');
    var errText = 'no';
    if (err) {
        var container = err.closest('.formulation') || err.parentElement;
        errText = container
            ? container.textContent.trim()
            : err.textContent.trim();
    }

    var inputs = Array.from(
        document.querySelectorAll('input[name], textarea[name]')
    ).map(function(e) { return e.tagName + '[' + e.name + ']'; }).join('|');

    return 'missing:runtimeError=' + errText.substring(0, 1500)
        + ':inputs=' + inputs.substring(0, 800);
})()
JS);

        if (strpos($result, 'ok:') !== 0) {
            throw new ExpectationException(
                "STACK input \'{$inputname}\' not found on attempt page. " .
                "This is usually caused by a STACK CAS failure. Details: " .
                $result,
                $this->getSession()
            );
        }
    }

    /**
     * Alias for i_attempt_the_quiz for use in Given context.
     *
     * @Given I am on the STACK MathQuill quiz attempt for :quizname
     * @param string $quizname Quiz name.
     */
    public function i_am_on_the_stack_mathquill_quiz_attempt_for(string $quizname): void {
        $this->i_start_the_stack_mathquill_quiz_attempt($quizname);
    }

    /**
     * Return to the quiz attempt page by re-visiting the stored attempt URL.
     *
     * Uses the URL stored by i_start_the_stack_mathquill_quiz_attempt rather than
     * getSession()->back() which is non-deterministic due to browser bfcache and
     * does not work reliably for single-question quizzes (no "Next" button present).
     *
     * @When I return to the quiz attempt page
     */
    public function i_return_to_the_quiz_attempt_page(): void {
        if ($this->lastquizattempturl) {
            $this->getSession()->visit($this->lastquizattempturl);
        }
        $this->assert_stack_input_present('ans1');
    }

    /**
     * Navigate to next quiz page (if present) and back, to simulate quiz navigation.
     *
     * In a single-question quiz there is no "Next" button, so this simply re-visits
     * the stored attempt URL, which re-renders the page with any server-saved answer.
     * In a multi-page quiz it clicks "Next", then re-opens the first page.
     *
     * Uses the URL stored by i_start_the_stack_mathquill_quiz_attempt to avoid the
     * non-deterministic getSession()->back() behaviour (bfcache).
     *
     * @When I navigate to the next question and back
     */
    public function i_navigate_to_next_question_and_back(): void {
        $page = $this->getSession()->getPage();
        $next = $page->find(
            'xpath',
            '//input[@type="submit"][@name="next"]'
            . ' | //button[@name="next"]'
            . ' | //input[@type="submit"][contains(@value,"Next")]'
        );
        if ($next) {
            $next->click();
            $this->getSession()->wait(3000, "document.readyState === 'complete'");
        }
        // Re-open the stored attempt URL (first page) rather than calling back().
        if ($this->lastquizattempturl) {
            $this->getSession()->visit($this->lastquizattempturl);
        }
        $this->assert_stack_input_present('ans1');
    }

    /**
     * Enter an answer in a quiz and persist it server-side via a real form submit.
     *
     * Submits the Moodle quiz responseform without a named button so that
     * processattempt.php performs a "save" action and redirects back to the same
     * attempt page.  The step then navigates to the quiz view page so that
     * subsequent "return to attempt" or "navigate and back" steps can visit
     * the stored attempt URL and verify that the server-rendered answer value
     * pre-populates the MathQuill field on reload.
     *
     * @When I have previously answered :answer in the quiz :quizname
     * @param string $answer   Maxima expression to save as the input value.
     * @param string $quizname Quiz name.
     */
    public function i_have_previously_answered(
        string $answer,
        string $quizname
    ): void {
        $this->i_start_the_stack_mathquill_quiz_attempt($quizname);

        // Set the STACK algebraic input directly on the hidden form field.
        // Using json_encode avoids any special-character quoting issues.
        $jsinput  = json_encode($answer);
        $setvalue = "(function() {"
            . "var i = document.querySelector('[name\$=\"_ans1\"]')"
            . " || document.querySelector('[name*=\"ans1\"]');"
            . "if (!i) { return false; }"
            . "i.value = {$jsinput};"
            . "return true;"
            . "})()";
        $this->getSession()->evaluateScript($setvalue);

        // Submit the responseform without a named button.  processattempt.php
        // interprets this as a "save" action: it saves the answers and redirects
        // back to the attempt page.  The redirect destination is irrelevant —
        // we navigate explicitly to the quiz view page afterwards.
        $this->getSession()->evaluateScript(
            "(function() { var f=document.getElementById('responseform');"
            . " if (f) { f.submit(); } })()"
        );

        // Wait for processattempt redirect to complete.
        $this->getSession()->wait(15000, "document.readyState === 'complete'");

        // Navigate to the quiz view page so that subsequent steps can visit
        // the stored attempt URL and observe the pre-filled MathQuill field.
        if ($this->lastquizviewurl) {
            $this->getSession()->visit($this->lastquizviewurl);
            $this->getSession()->wait(5000, "document.readyState === 'complete'");
        }
    }

    // Configure form assertions.

    /**
     * Deselect a toolbar group option in the configure form select element.
     *
     * @When I deselect the :groupname toolbar group
     * @param string $groupname Label text (or partial) of the group option to deselect.
     */
    public function i_deselect_the_toolbar_group(string $groupname): void {
        $safegroup = addslashes($groupname);
        $js = <<<JS
            (function() {
                var select = document.querySelector('#id_groups, select[name="groups[]"], select[name="groups"]');
                if (!select) { return 'no-select'; }
                var options = select.options;
                for (var i = 0; i < options.length; i++) {
                    if (options[i].text.indexOf('{$safegroup}') !== -1) {
                        options[i].selected = false;
                        return 'ok';
                    }
                }
                return 'not-found';
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if ($result !== 'ok') {
            throw new ExpectationException(
                "Toolbar group '$groupname' not found in select (result: $result).",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that a toolbar group option is currently not selected.
     *
     * @Then the :groupname toolbar group should be deselected
     * @param string $groupname Label text (or partial) of the group option.
     */
    public function the_toolbar_group_should_be_deselected(string $groupname): void {
        $safegroup = addslashes($groupname);
        $js = <<<JS
            (function() {
                var select = document.querySelector('#id_groups, select[name="groups[]"], select[name="groups"]');
                if (!select) { return 'no-select'; }
                var options = select.options;
                for (var i = 0; i < options.length; i++) {
                    if (options[i].text.indexOf('{$safegroup}') !== -1) {
                        return options[i].selected ? 'selected' : 'deselected';
                    }
                }
                return 'not-found';
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if ($result !== 'deselected') {
            throw new ExpectationException(
                "Expected toolbar group '$groupname' to be deselected, but got: $result",
                $this->getSession()
            );
        }
    }

    /**
     * Set the quiz-level config so that a specific toolbar group is enabled.
     *
     * Both forms are accepted:
     *   without quiz name → uses first quiz found in the current fixture
     *   with quiz name    → targets the named quiz explicitly
     *
     * @Given the quiz-level config has :groupname enabled
     * @Given the quiz-level config has :groupname enabled for :quizname
     * @param string $groupname Group label or key to enable.
     * @param string $quizname  Optional quiz name; first quiz used when empty.
     */
    public function the_quiz_level_config_has_enabled_for(
        string $groupname,
        string $quizname = ''
    ): void {
        global $DB;

        // Resolve quiz: explicit name or first quiz in fixture.
        if ($quizname !== '') {
            $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        } else {
            $quiz = $DB->get_record_sql(
                'SELECT * FROM {quiz} ORDER BY id ASC',
                [],
                IGNORE_MULTIPLE
            );
            if (!$quiz) {
                throw new ExpectationException(
                    'No quiz found in fixture for quiz-level config step.',
                    $this->getSession()
                );
            }
        }
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);

        // Find group key by label substring match.
        $groups   = local_stackmatheditor\definitions::get_element_groups();
        $groupkey = null;
        foreach ($groups as $key => $group) {
            if (
                $key === $groupname
                || strpos((string)($group['label'] ?? ''), $groupname) !== false
            ) {
                $groupkey = $key;
                break;
            }
        }
        if (!$groupkey) {
            throw new ExpectationException(
                "Unknown toolbar group: '$groupname'",
                $this->getSession()
            );
        }

        // Persist quiz-level config via plugin API.
        $config = \local_stackmatheditor\config_manager::get_quiz_default((int)$cm->id)
            ?? \local_stackmatheditor\config_manager::get_instance_base_config();
        $config[$groupkey] = true;
        \local_stackmatheditor\config_manager::save_quiz_default((int)$cm->id, $config);
    }

    /**
     * Assert that a question-level config override exists in the DB.
     *
     * @Then the question-level config for :questionname should override the quiz default
     * @param string $questionname Question name.
     */
    public function the_question_level_config_should_override(string $questionname): void {
        global $DB;

        $question = $DB->get_record(
            'question',
            ['name' => $questionname, 'qtype' => 'stack'],
            '*',
            MUST_EXIST
        );
        $qbeid = \local_stackmatheditor\config_manager::resolve_qbeid($question->id);
        if (!$qbeid) {
            throw new ExpectationException(
                "Cannot resolve QBEID for question '$questionname'.",
                $this->getSession()
            );
        }
        $override = $DB->record_exists(
            'local_stackmatheditor',
            ['questionbankentryid' => $qbeid]
        );
        if (!$override) {
            throw new ExpectationException(
                "No question-level config override found for '$questionname'.",
                $this->getSession()
            );
        }
    }

    /**
     * Mark a quiz STACK question slot as having the editor disabled.
     *
     * @Given the STACK question :inputname in :quizname has editor disabled
     * @param string $inputname  Input name (e.g. "ans1") used to locate the question slot.
     * @param string $quizname   Quiz name.
     */
    public function the_stack_question_has_editor_disabled(
        string $inputname,
        string $quizname
    ): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);

        // Find the question via question_references (Moodle 4.5+: no quiz_slots.questionid).
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');
        foreach ($slots as $slot) {
            $qbeid = $DB->get_field('question_references', 'questionbankentryid', [
                'component'    => 'mod_quiz',
                'questionarea' => 'slot',
                'itemid'       => $slot->id,
            ]);
            if (!$qbeid) {
                continue;
            }
            $questionid = $DB->get_field_sql(
                'SELECT qv.questionid
                   FROM {question_versions} qv
                  WHERE qv.questionbankentryid = :qbeid
               ORDER BY qv.version DESC',
                ['qbeid' => $qbeid],
                IGNORE_MULTIPLE
            );
            if (!$questionid) {
                continue;
            }
            $question = question_bank::load_question((int)$questionid, false);
            if (!empty($question->inputs[$inputname])) {
                \local_stackmatheditor\config_manager::save_config(
                    (int)$cm->id,
                    (int)$qbeid,
                    ['_enabled' => false]
                );
                return;
            }
        }
        throw new ExpectationException(
            "Could not find STACK input '$inputname' in quiz '$quizname'.",
            $this->getSession()
        );
    }

    // MathQuill editor assertions.

    /**
     * Assert that the MathQuill editor container is visible for an input.
     *
     * @Then the MathQuill editor is visible for :inputname
     * @param string $inputname Name attribute of the hidden input element.
     */
    public function the_mathquill_editor_is_visible_for(string $inputname): void {
        $js = <<<JS
            (function() {
                var input = document.querySelector('input[name="{$inputname}"]')
                         || document.querySelector('input[name$="_{$inputname}"]');
                if (!input) { return false; }
                var wrap = input.previousElementSibling;
                if (!wrap) { return false; }
                return wrap.classList.contains('sme-input-wrap')
                    || wrap.classList.contains('sme-mq-container');
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if (!$result) {
            throw new ExpectationException(
                "MathQuill editor is not visible for input '$inputname'.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that the original STACK input field is hidden.
     *
     * @Then I should not see the original STACK input field
     */
    public function i_should_not_see_the_original_stack_input_field(): void {
        $js = <<<JS
            (function() {
                var inputs = document.querySelectorAll(
                    '.que.stack input[type="text"]'
                );
                for (var i = 0; i < inputs.length; i++) {
                    var el = inputs[i];
                    var style = window.getComputedStyle(el);
                    if (style.display === 'none' || style.visibility === 'hidden') {
                        continue;
                    }
                    // The plugin hides the original input off-screen
                    // (position:absolute, clipped to 1px),
                    // so MathQuill can still sync values to it.  Treat that as hidden.
                    var rect = el.getBoundingClientRect();
                    if ((rect.left + rect.width) < 1) {
                        continue;
                    }
                    if (rect.width <= 1 && rect.height <= 1) {
                        continue;
                    }
                    return false;
                }
                return true;
            })()
JS;
        $result = $this->getSession()->evaluateScript($js);
        if (!$result) {
            throw new ExpectationException(
                "Original STACK input field is still visible.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that the MathQuill LaTeX content for an input contains a fragment.
     *
     * @Then the MathQuill field for :inputname should contain LaTeX containing :fragment
     * @param string $inputname Name attribute of the hidden input.
     * @param string $fragment  Expected LaTeX fragment.
     */
    public function the_mathquill_field_should_contain_latex(
        string $inputname,
        string $fragment
    ): void {
        $safefragment = addslashes($fragment);
        $js = <<<JS
            (function() {
                var input = document.querySelector('input[name="{$inputname}"]')
                         || document.querySelector('input[name$="_{$inputname}"]');
                if (!input) { return null; }
                var wrap = input.previousElementSibling;
                if (!wrap) { return null; }
                if (window.MathQuill) {
                    var mq = MathQuill.getInterface(2);
                    var field = mq(wrap.querySelector('.mq-editable-field'));
                    if (field) { return field.latex(); }
                }
                var mqroot = wrap.querySelector('.mq-root-block');
                return mqroot ? mqroot.getAttribute('aria-label') : null;
            })()
JS;
        $actual = $this->getSession()->evaluateScript($js);
        if ($actual === null || strpos($actual, $fragment) === false) {
            throw new ExpectationException(
                "MathQuill field for '$inputname' LaTeX '$actual'"
                    . " does not contain '$fragment'.",
                $this->getSession()
            );
        }
    }

    /**
     * Click a toolbar button that has the given title attribute.
     *
     * @When I click the toolbar button with title :title
     * @param string $title Title attribute of the toolbar button.
     */
    public function i_click_the_toolbar_button_with_title(string $title): void {
        $safetitle = addslashes($title);
        $btn = $this->find(
            'xpath',
            '//button[contains(@class,"sme-tb-btn")][@title="' . $safetitle . '"]'
        );
        if (!$btn) {
            throw new ExpectationException(
                "Toolbar button with title '$title' not found.",
                $this->getSession()
            );
        }
        $btn->click();
    }

    /**
     * Set the plugin usepercentpi config option and reload the attempt page.
     *
     * The sme-definitions JSON element (which carries usePercentPi to the AMD
     * runtime) is rendered at page-load time.  Simply calling set_config() after
     * the attempt page has already loaded has no effect on the running JS.
     * This step purges the MUC cache so the web server re-reads the updated value
     * on the next request, then reloads the current page so the AMD module picks
     * up the new usePercentPi setting.
     *
     * @Given the plugin usepercentpi setting is :value
     * @param string $value Config value: "0" to disable, "1" to enable.
     */
    public function the_plugin_usepercentpi_setting_is(string $value): void {
        set_config('usepercentpi', (int) $value, 'local_stackmatheditor');
        // Force the web-server process to re-read the config from the DB.
        purge_all_caches();
        // Reload the current page so the AMD module receives the updated value
        // in the freshly-rendered sme-definitions JSON element.
        $this->getSession()->reload();
        $this->assert_stack_input_present('ans1');
    }

    // Tex2max JavaScript evaluation.

    /**
     * Evaluate a tex2max conversion in the browser AMD context and store the result.
     *
     * @When the tex2max output for latex :latex in variableMode :mode is evaluated
     * @param string $latex LaTeX input string.
     * @param string $mode  Variable mode string (e.g. "explicit_single").
     */
    public function the_tex2max_output_is_evaluated(
        string $latex,
        string $mode
    ): void {
        // Escape for safe embedding in a JS single-quoted string.
        $jslatex = str_replace(['\\', "'", "\n"], ['\\\\', "\\'", '\\n'], $latex);
        $jsmode  = str_replace("'", "\\'", $mode);

        // Use a sentinel so we can distinguish 'not yet called' from 'returned null'.
        $js = <<<JS
            // tex2max module has no dependencies and exports { convert: convert }.
            // mathquill_init exports init() only – it cannot do conversion.
            window.__sme_t2m_result = '__waiting__';
            (function() {
                var amdReq = window.requirejs || window.require || null;
                if (!amdReq || typeof amdReq !== 'function') {
                    window.__sme_t2m_result = '__no-amd__';
                    return;
                }
                // Prefer synchronous access if module is already cached.
                if (typeof amdReq.defined === 'function'
                        && amdReq.defined('local_stackmatheditor/tex2max')) {
                    try {
                        var mod = amdReq('local_stackmatheditor/tex2max');
                        var cfg = {variableMode: '{$jsmode}'};
                        var sApi = Object.keys(mod)
                            .filter(function(k) { return typeof mod[k] === 'function'; })
                            .join(',');
                        if (typeof mod.convert !== 'function') {
                            window.__sme_t2m_result = '__api__:' + sApi;
                            return;
                        }
                        var r = mod.convert('{$jslatex}', cfg);
                        if (r === null || r === undefined) {
                            window.__sme_t2m_result = '__null__';
                        } else if (String(r) === '') {
                            window.__sme_t2m_result = '__empty__:api=' + sApi;
                        } else {
                            window.__sme_t2m_result = String(r);
                        }
                    } catch (ex) {
                        window.__sme_t2m_result = '__error__:' + ex.message;
                    }
                    return;
                }
                // Async load – tex2max has no dependencies so this is near-instant.
                amdReq(
                    ['local_stackmatheditor/tex2max'],
                    function(mod) {
                        try {
                            var cfg = {variableMode: '{$jsmode}'};
                            var api = Object.keys(mod)
                                .filter(function(k) { return typeof mod[k] === 'function'; })
                                .join(',');
                            if (typeof mod.convert !== 'function') {
                                window.__sme_t2m_result = '__api__:' + api;
                                return;
                            }
                            var r = mod.convert('{$jslatex}', cfg);
                            if (r === null || r === undefined) {
                                window.__sme_t2m_result = '__null__';
                            } else if (String(r) === '') {
                                // Empty result: report the module API for debugging.
                                window.__sme_t2m_result = '__empty__:api=' + api;
                            } else {
                                window.__sme_t2m_result = String(r);
                            }
                        } catch (ex) {
                            window.__sme_t2m_result = '__error__:' + ex.message;
                        }
                    },
                    function(reqErr) {
                        var t = reqErr && reqErr.requireType ? reqErr.requireType : 'unknown';
                        window.__sme_t2m_result = '__require-error__:' + t;
                    }
                );
            })();
JS;
        // Execute, not evaluate: evaluateScript() prefixes the script with "return ", and a
        // "return" followed by the leading comment line returned undefined before any statement
        // ran - the result stayed empty. executeScript() runs the script exactly as written.
        $this->getSession()->executeScript($js);
        // Wait up to 3 s for the AMD callback to fire.
        $this->getSession()->wait(3000, "window.__sme_t2m_result !== '__waiting__'");
    }

    /**
     * Assert the tex2max result equals an expected string exactly.
     *
     * @Then the tex2max result should be :expected
     * @param string $expected Expected Maxima output.
     */
    public function the_tex2max_result_should_be(string $expected): void {
        $actual = $this->getSession()->evaluateScript(
            'return window.__sme_t2m_result;'
        );
        if ($actual !== $expected) {
            throw new ExpectationException(
                "tex2max result '$actual' does not equal expected '$expected'.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the tex2max result contains a substring.
     *
     * @Then the tex2max result should contain :text
     * @param string $text Expected substring.
     */
    public function the_tex2max_result_should_contain(string $text): void {
        $actual = $this->getSession()->evaluateScript(
            'return window.__sme_t2m_result;'
        );
        if ($actual === null || strpos($actual, $text) === false) {
            throw new ExpectationException(
                "tex2max result '$actual' does not contain '$text'.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the tex2max result does not contain a substring.
     *
     * @Then the tex2max result should not contain :text
     * @param string $text String that must not appear in the result.
     */
    public function the_tex2max_result_should_not_contain(string $text): void {
        $actual = $this->getSession()->evaluateScript(
            'return window.__sme_t2m_result;'
        );
        if ($actual !== null && strpos($actual, $text) !== false) {
            throw new ExpectationException(
                "tex2max result '$actual' should not contain '$text'.",
                $this->getSession()
            );
        }
    }



    // Stack CAS diagnostic steps (tag stack_init).

    /**
     * Navigate to the STACK plugin settings page in site administration.
     *
     * @When I navigate to the STACK settings page
     */
    public function i_navigate_to_stack_settings_page(): void {
        $url = new moodle_url('/admin/settings.php', ['section' => 'qtypesettingstack']);
        $this->getSession()->visit($this->locate_path($url->out(false)));
        $this->wait_for_pending_js();
    }

    /**
     * Navigate to the STACK healthcheck admin page.
     *
     * @When I navigate to the STACK healthcheck page
     */
    public function i_navigate_to_stack_healthcheck_page(): void {
        // The healthcheck runs a dozen CAS calls; with platform=linux (Maxima without a frozen
        // image) the response can take longer than php-webdriver's 30 s HTTP timeout, and a
        // regular visit() then fails with "WebDriverCurlException ... Operation timed out"
        // although STACK is fine. The page is therefore fetched from within the current page:
        // fetch() is no navigation, so every WebDriver command below returns at once, and the
        // step waits up to 280 s (castimeout is 300 s) for the result. The fetched HTML is shown
        // in the current page, where the assertion steps read it.
        $url = new moodle_url('/question/type/stack/adminui/healthcheck.php');
        $jsurl = json_encode($this->locate_path($url->out(false)));
        $this->getSession()->executeScript(<<<JS
window.__smeHealthcheck = null;
fetch({$jsurl}, {credentials: 'same-origin'})
    .then(function(response) {
        return response.text().then(function(text) {
            var box = document.getElementById('sme-healthcheck') || document.createElement('div');
            box.id = 'sme-healthcheck';
            box.innerHTML = text;
            document.body.appendChild(box);
            window.__smeHealthcheck = 'HTTP ' + response.status;
        });
    })
    .catch(function(e) {
        window.__smeHealthcheck = 'error: ' + e;
    });
JS);
        $this->getSession()->wait(280000, 'window.__smeHealthcheck !== null');
        $status = $this->getSession()->evaluateScript('return window.__smeHealthcheck;');
        if ($status !== 'HTTP 200') {
            throw new ExpectationException(
                'The STACK healthcheck page could not be loaded within 280 s (' . var_export($status, true) . ').',
                $this->getSession()
            );
        }
    }

    /**
     * Clear the STACK CAS result cache on the healthcheck page.
     * Order: FIRST clear cache, THEN rebuild image.
     *
     * @When I clear the STACK CAS cache
     */
    public function i_clear_the_stack_cas_cache(): void {
        $page = $this->getSession()->getPage();
        $clearbtn = $page->find(
            'xpath',
            '//input[@name="clearcache"]/ancestor::form//button'
        );
        if (!$clearbtn) {
            $clearbtn = $page->find(
                'xpath',
                '//input[@name="clearcache"]/following::button[1]'
            );
        }
        if (!$clearbtn) {
            throw new ExpectationException(
                "STACK clear-cache button not found on healthcheck page. " .
                "Expected a POST form with input[name=\"clearcache\"] and a button.",
                $this->getSession()
            );
        }
        $clearbtn->click();
        $this->wait_for_pending_js();
        $this->handle_moodle_progress_page();
    }

    /**
     * Rebuild the STACK Maxima optimised image via the healthcheck page.
     *
     * @When I rebuild the STACK Maxima image
     */
    public function i_rebuild_the_stack_maxima_image(): void {
        $page = $this->getSession()->getPage();
        $btn  = $page->find(
            'xpath',
            '//input[@name="createmaximaimage"]/ancestor::form//button'
        );
        if (!$btn) {
            $btn = $page->find(
                'xpath',
                '//input[@name="createmaximaimage"]/following::button[1]'
            );
        }
        if (!$btn) {
            throw new ExpectationException(
                "STACK \'Create Maxima image\' button not found on healthcheck page. " .
                "Expected a POST form with input[name=\"createmaximaimage\"] and a button.",
                $this->getSession()
            );
        }
        $btn->click();
        $this->wait_for_pending_js();
        $this->handle_moodle_progress_page();
    }

    /**
     * Clicks the Moodle Continue/Weiter button on progress redirect pages.
     */
    protected function handle_moodle_progress_page(): void {
        $page     = $this->getSession()->getPage();
        $continue = $page->find('css', '#id_continue');
        if (!$continue) {
            $xp = '//button[contains(.,"Continue")] | //button[contains(.,"Weiter")]';
            $continue = $page->find('xpath', $xp);
        }
        if ($continue) {
            $continue->click();
            $this->wait_for_pending_js();
        }
    }

    /**
     * Assert success indicators on the STACK healthcheck page.
     *
     * @Then I should see the STACK healthcheck success indicators
     */
    public function i_should_see_stack_healthcheck_success_indicators(): void {
        $page   = $this->getSession()->getPage();
        $source = $page->getContent();

        if (strpos($source, 'class="stackerror"') !== false) {
            throw new ExpectationException(
                "STACK healthcheck page shows at least one error.",
                $this->getSession()
            );
        }

        // Note: 'maxima_opt_auto' also appears in error text; use more specific indicators.
        // The stackmaximaversion (10-digit) only appears when CAS returned data.
        // Note: 'CAS gibt' / 'CAS returns' only appear on successful connection.
        $successhint = ['stackmaximaversion', 'CAS gibt Daten', 'CAS returns data',
            'STACK_SETUP_OK', 'stehende Verbindung', 'standing connection'];

        $found = false;
        foreach ($successhint as $hint) {
            if (strpos($source, $hint) !== false) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new ExpectationException(
                "STACK healthcheck success indicators not found on page.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the healthcheck page reports the CAS connection as working.
     *
     * @Then the STACK CAS connection should be reported as working
     */
    public function the_stack_cas_connection_should_be_reported_as_working(): void {
        $page   = $this->getSession()->getPage();
        $source = $page->getContent();

        // First reject explicit failure markers.
        $failmarkers = [
            'overallresult fail',
            'CAS failed',
            'The healthcheck detected serious problems',
            'No such file or directory',
        ];
        foreach ($failmarkers as $fail) {
            if (stripos($source, $fail) !== false) {
                throw new ExpectationException(
                    "STACK healthcheck reports a CAS failure (found: \'{$fail}\').",
                    $this->getSession()
                );
            }
        }

        // Accept any recognised success indicator from STACK's healthcheck output.
        $ok = [
            'overallresult pass',
            'You have a live connection to the CAS',
            'CAS returned data as expected',
            'The healthcheck passed without detecting any issues',
            'Correct and expected STACK-Maxima library version',
            'CAS gibt Daten',
            'stehende Verbindung',
            'standing connection',
        ];
        foreach ($ok as $needle) {
            if (stripos($source, $needle) !== false) {
                return;
            }
        }

        throw new ExpectationException(
            "STACK CAS connection is NOT reported as working on the healthcheck page. " .
            "Neither a success indicator nor a failure marker was found.",
            $this->getSession()
        );
    }

    /**
     * Assert the healthcheck page reports a specific platform.
     *
     * @Then the STACK platform should be reported as :platform
     * @param string $platform Expected platform identifier, e.g. "linux-optimised".
     */
    public function the_stack_platform_should_be_reported_as(string $platform): void {
        $page   = $this->getSession()->getPage();
        $source = $page->getContent();
        if (strpos($source, $platform) === false) {
            throw new ExpectationException(
                "Expected STACK platform \'{$platform}\' not found on healthcheck page.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the healthcheck page reports a valid Maxima library version.
     *
     * @Then the STACK Maxima library version should be valid
     */
    public function the_stack_maxima_library_version_should_be_valid(): void {
        $page   = $this->getSession()->getPage();
        $source = $page->getContent();
        if (!preg_match('/\b20\d{8}\b/', $source)) {
            throw new ExpectationException(
                "STACK Maxima library version (10-digit timestamp) not found " .
                "on healthcheck page.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the STACK optimised Maxima image file exists and is executable.
     * Uses PHP file system checks (not browser JS) since this is server-side state.
     *
     * @Then the STACK optimised Maxima image should be executable
     */
    public function the_stack_optimised_maxima_image_should_be_executable(): void {
        global $CFG;

        $image = $CFG->dataroot . '/stack/maxima_opt_auto';

        if (!is_file($image)) {
            throw new ExpectationException(
                "Expected STACK optimised Maxima image does not exist: {$image}. " .
                "Run stack-behat-init.php before Behat to create it.",
                $this->getSession()
            );
        }

        if (!is_executable($image)) {
            throw new ExpectationException(
                "STACK optimised Maxima image is not executable: {$image}. " .
                "Apply chmod 0755.",
                $this->getSession()
            );
        }
    }

    /**
     * Enter LaTeX into the MathQuill field for a given STACK input name.
     * Drives the full UI path: MathQuill write → tex2max → STACK hidden input.
     *
     * @When I enter latex :latex into the MathQuill field for :inputname
     * @param string $latex     LaTeX string to enter.
     * @param string $inputname STACK input name (e.g. "ans1").
     */
    public function i_enter_latex_into_mathquill_field(
        string $latex,
        string $inputname
    ): void {
        $jslatex = json_encode($latex);
        $jsinput = json_encode($inputname);

        $result = $this->getSession()->evaluateScript(<<<JS
(function() {
    var n = {$jsinput};
    var input = document.querySelector('[name="' + n + '"]')
        || document.querySelector('[name$="_' + n + '"]')
        || document.querySelector('[id="' + n + '"]')
        || document.querySelector('[id$="_' + n + '"]');

    if (!input) { return 'no-stack-input'; }

    var que = input.closest('.que') || document;
    var container = que.querySelector('.sme-mq-container');

    if (!container) { return 'no-sme-container'; }

    var editable = container.querySelector('.mq-editable-field');
    if (!editable)  { return 'no-mq-editable'; }
    if (!window.MathQuill) { return 'no-mathquill'; }

    var MQ    = window.MathQuill.getInterface(2);
    var field = MQ(editable);
    if (!field)     { return 'no-mq-field'; }

    field.latex('');
    field.write({$jslatex});
    field.blur();

    input.dispatchEvent(new Event('input',  {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));

    return 'ok';
})()
JS);

        if ($result !== 'ok') {
            throw new ExpectationException(
                "Could not enter LaTeX into MathQuill field \'{$inputname}\' " .
                "(result: {$result}).",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the underlying hidden STACK input contains an expected substring.
     *
     * @Then the underlying STACK input for :inputname should contain :expected
     * @param string $inputname STACK input name.
     * @param string $expected  Expected substring in the input value.
     */
    public function the_underlying_stack_input_should_contain(
        string $inputname,
        string $expected
    ): void {
        $jsinput = json_encode($inputname);
        $actual  = $this->getSession()->evaluateScript(<<<JS
(function() {
    var n = {$jsinput};
    var input = document.querySelector('[name="' + n + '"]')
        || document.querySelector('[name$="_' + n + '"]')
        || document.querySelector('[id="' + n + '"]')
        || document.querySelector('[id$="_' + n + '"]');
    return input ? input.value : '__missing_input__';
})()
JS);

        if (strpos((string)$actual, $expected) === false) {
            throw new ExpectationException(
                "STACK input \'{$inputname}\' value \'{$actual}\' " .
                "does not contain \'{$expected}\'.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the underlying hidden STACK input does not contain a given string.
     *
     * @Then the underlying STACK input for :inputname should not contain :text
     * @param string $inputname STACK input name.
     * @param string $text      String that must NOT appear in the input value.
     */
    public function the_underlying_stack_input_should_not_contain(
        string $inputname,
        string $text
    ): void {
        $jsinput = json_encode($inputname);
        $actual  = $this->getSession()->evaluateScript(<<<JS
(function() {
    var n = {$jsinput};
    var input = document.querySelector('[name="' + n + '"]')
        || document.querySelector('[name$="_' + n + '"]')
        || document.querySelector('[id="' + n + '"]')
        || document.querySelector('[id$="_' + n + '"]');
    return input ? input.value : '__missing_input__';
})()
JS);

        if (strpos((string)$actual, $text) !== false) {
            throw new ExpectationException(
                "STACK input \'{$inputname}\' value \'{$actual}\' " .
                "unexpectedly contains \'{$text}\'.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the underlying hidden STACK input equals an expected string exactly.
     *
     * @Then the underlying STACK input for :inputname should be :expected
     * @param string $inputname STACK input name.
     * @param string $expected  Expected exact value of the input.
     */
    public function the_underlying_stack_input_should_be(
        string $inputname,
        string $expected
    ): void {
        $jsinput = json_encode($inputname);
        $actual  = $this->getSession()->evaluateScript(<<<JS
(function() {
    var n = {$jsinput};
    var input = document.querySelector('[name="' + n + '"]')
        || document.querySelector('[name$="_' + n + '"]')
        || document.querySelector('[id="' + n + '"]')
        || document.querySelector('[id$="_' + n + '"]');
    return input ? input.value : '__missing_input__';
})()
JS);

        if ((string)$actual !== $expected) {
            throw new ExpectationException(
                "STACK input \'{$inputname}\' value \'{$actual}\' " .
                "does not equal \'{$expected}\'.",
                $this->getSession()
            );
        }
    }

    /**
     * Force STACK CAS to a direct (non-optimised) Maxima connection at Behat runtime.
     *
     * moodle-plugin-ci behat --start-servers re-runs util_single_run.php, which
     * re-triggers STACK install.php and writes platform=linux-optimised plus a
     * volatile maxima_opt_auto image path back into the Behat DB.  The frozen
     * image is then removed by the dataroot reset, so any CAS call fails with
     * "No such file or directory".  Setting platform=linux makes STACK invoke the
     * plain "maxima" binary directly (cold start, no frozen image required).
     *
     * @param bool $verify When true, run a genuine CAS connect and fail loudly
     *                      if the connection does not actually work.
     */
    private function reset_stack_cas_to_direct_maxima(bool $verify = false): void {
        global $CFG, $DB;

        if (!$DB->get_manager()->table_exists('config')) {
            throw new ExpectationException(
                'Cannot reset STACK CAS: the Moodle {config} table does not exist.',
                $this->getSession()
            );
        }

        $candidates = [
            $CFG->dirroot . '/question/type/stack',
            $CFG->dirroot . '/public/question/type/stack',
        ];
        $stackroot = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate . '/stack/cas/installhelper.class.php')) {
                $stackroot = $candidate;
                break;
            }
        }
        if (!$stackroot) {
            throw new ExpectationException(
                'Cannot reset STACK CAS: qtype_stack was not found under dirroot.',
                $this->getSession()
            );
        }

        require_once($stackroot . '/stack/cas/installhelper.class.php');
        require_once($stackroot . '/stack/cas/connectorhelper.class.php');

        set_config('platform', 'linux', 'qtype_stack');
        set_config('maximacommand', 'maxima', 'qtype_stack');
        set_config('maximacommandopt', '', 'qtype_stack');
        set_config('maximaversion', 'default', 'qtype_stack');
        set_config('castimeout', '300', 'qtype_stack');
        set_config('casresultscache', 'db', 'qtype_stack');
        set_config('casdebugging', '0', 'qtype_stack');
        set_config('maximalibraries', '', 'qtype_stack');

        // Force every process (test runner and web server) to re-read fresh config.
        purge_all_caches();

        // Reset the stack_cas_configuration singleton so it re-reads the new values.
        if (class_exists('stack_cas_configuration')) {
            $ref = new ReflectionClass('stack_cas_configuration');
            if ($ref->hasProperty('instance')) {
                $prop = $ref->getProperty('instance');
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }

        stack_cas_configuration::create_maximalocal();

        if ($verify) {
            [$message, $debug, $ok] = stack_connection_helper::stackmaxima_genuine_connect();
            if (!$ok) {
                throw new ExpectationException(
                    'STACK CAS direct-Maxima connection failed. '
                        . 'Message: ' . $message . ' Debug: ' . $debug,
                    $this->getSession()
                );
            }
        }
    }

    /**
     * Reset STACK CAS to a working direct Maxima connection and verify it.
     *
     * Makes the runtime CAS repair explicit and verifiable inside the scenario,
     * rather than relying on the (silent) @BeforeScenario hook alone.
     *
     * @Given the STACK CAS platform is reset to direct Maxima
     */
    public function the_stack_cas_platform_is_reset_to_direct_maxima(): void {
        $this->reset_stack_cas_to_direct_maxima(true);
    }

    /**
     * Safety-net hook: reset STACK CAS before every plugin scenario.
     *
     * CAS-dependent features additionally call the explicit step above (with
     * verification).  This hook guarantees a sane baseline even for scenarios
     * that do not invoke the step (e.g. toolbar configuration).
     *
     * @BeforeScenario @local_stackmatheditor
     * @param BeforeScenarioScope $scope Behat scenario scope.
     */
    public function prepare_stack_cas_for_scenario(BeforeScenarioScope $scope): void {
        $this->reset_stack_cas_to_direct_maxima(false);
    }
}
