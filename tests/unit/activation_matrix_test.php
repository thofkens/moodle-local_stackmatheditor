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
 * The whole activation matrix, system x quiz x question (#80, #81).
 *
 * #80 reported that a question could not be switched on while its quiz stayed off. The
 * resolution in config_manager was already right; the page-level gate in front of it was not,
 * and asked only about the quiz. This covers both ends: every cell of the matrix, and the gate
 * that decides whether the runtime reaches the page at all.
 *
 * Not the same semantics as the student switch in #73. There, any level may revoke. Here, the
 * most specific statement wins - which is what "can be enabled per quiz or question" means.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\config_manager::get_effective_enabled
 * @covers     \local_stackmatheditor\config_manager::page_may_need_editor
 */
final class activation_matrix_test extends \advanced_testcase {
    /** @var int Course module id used throughout. */
    private const CMID = 909;

    /** @var int Question bank entry id used throughout. */
    private const QBEID = 707;

    /**
     * Set up one cell of the matrix.
     *
     * @param int $mode Instance activation mode.
     * @param bool|null $quiz Quiz override, null for none.
     * @param bool|null $question Question override, null for none.
     * @return void
     */
    private function arrange(int $mode, ?bool $quiz, ?bool $question): void {
        $this->setAdminUser();
        set_config('enabled', $mode, 'local_stackmatheditor');

        if ($quiz !== null) {
            config_manager::save_quiz_default(self::CMID, ['_enabled' => (int) $quiz]);
        }
        if ($question !== null) {
            config_manager::save_config(self::CMID, self::QBEID, ['_enabled' => (int) $question]);
        }
    }

    /**
     * Mode 2: off by default, and both lower levels may speak.
     *
     * @return array
     */
    public static function mode_two_provider(): array {
        return [
            'nothing set'            => [null, null, false],
            'question off'           => [null, false, false],
            'question on'            => [null, true, true],
            'quiz off'               => [false, null, false],
            'quiz off, question off' => [false, false, false],
            // Issue #80 itself: the question must say yes where the quiz says no.
            'quiz off, question on'  => [false, true, true],
            'quiz on'                => [true, null, true],
            'quiz on, question off'  => [true, false, false],
            'quiz on, question on'   => [true, true, true],
        ];
    }

    /**
     * Mode 2 resolves as the issue's table says.
     *
     * @dataProvider mode_two_provider
     * @param bool|null $quiz Quiz override.
     * @param bool|null $question Question override.
     * @param bool $expected Expected result.
     * @return void
     */
    public function test_mode_two(?bool $quiz, ?bool $question, bool $expected): void {
        $this->resetAfterTest();
        $this->arrange(2, $quiz, $question);

        $this->assertSame(
            $expected,
            config_manager::get_effective_enabled(self::CMID, self::QBEID)
        );
    }

    /**
     * Mode 3: on by default, same overrides.
     *
     * @return array
     */
    public static function mode_three_provider(): array {
        return [
            'nothing set'            => [null, null, true],
            'question off'           => [null, false, false],
            'question on'            => [null, true, true],
            'quiz off'               => [false, null, false],
            'quiz off, question off' => [false, false, false],
            'quiz off, question on'  => [false, true, true],
            'quiz on'                => [true, null, true],
            'quiz on, question off'  => [true, false, false],
            'quiz on, question on'   => [true, true, true],
        ];
    }

    /**
     * Mode 3 resolves as the issue's table says.
     *
     * @dataProvider mode_three_provider
     * @param bool|null $quiz Quiz override.
     * @param bool|null $question Question override.
     * @param bool $expected Expected result.
     * @return void
     */
    public function test_mode_three(?bool $quiz, ?bool $question, bool $expected): void {
        $this->resetAfterTest();
        $this->arrange(3, $quiz, $question);

        $this->assertSame(
            $expected,
            config_manager::get_effective_enabled(self::CMID, self::QBEID)
        );
    }

    /**
     * Modes 0 and 1 ignore everything below them.
     *
     * @return void
     */
    public function test_the_absolute_modes(): void {
        $this->resetAfterTest();

        foreach ([null, false, true] as $quiz) {
            foreach ([null, false, true] as $question) {
                $this->arrange(0, $quiz, $question);
                $this->assertFalse(
                    config_manager::get_effective_enabled(self::CMID, self::QBEID),
                    'mode 0 is off whatever anyone else says'
                );

                $this->arrange(1, $quiz, $question);
                $this->assertTrue(
                    config_manager::get_effective_enabled(self::CMID, self::QBEID),
                    'mode 1 is on whatever anyone else says'
                );
            }
        }
    }

    /**
     * The page gate only refuses what is off everywhere.
     *
     * This is the fix for #80: with mode 2 and a quiz that stays off, the runtime still has to
     * reach the page, or the question override can never be evaluated.
     *
     * @return void
     */
    public function test_the_page_gate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('enabled', 0, 'local_stackmatheditor');
        $this->assertFalse(config_manager::page_may_need_editor());

        foreach ([1, 2, 3] as $mode) {
            set_config('enabled', $mode, 'local_stackmatheditor');
            $this->assertTrue(
                config_manager::page_may_need_editor(),
                "mode $mode must let the runtime load"
            );
        }

        // Specifically the reported case: quiz off, mode 2.
        set_config('enabled', 2, 'local_stackmatheditor');
        config_manager::save_quiz_default(self::CMID, ['_enabled' => 0]);

        $this->assertTrue(
            config_manager::page_may_need_editor(),
            'a quiz that is off must not stop the runtime from loading'
        );
        $this->assertFalse(
            config_manager::get_effective_enabled(self::CMID),
            'while the quiz itself still has no editor'
        );
    }

    /**
     * A page without per-question configuration follows the quiz value.
     *
     * An adaptive quiz has no question-level UI, so the runtime default is what decides there.
     * The fix for #80 must not turn that into "on".
     *
     * @return void
     */
    public function test_the_runtime_default_for_pages_without_overrides(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('enabled', 2, 'local_stackmatheditor');
        $this->assertFalse(config_manager::get_effective_enabled(self::CMID));

        config_manager::save_quiz_default(self::CMID, ['_enabled' => 1]);
        $this->assertTrue(config_manager::get_effective_enabled(self::CMID));

        set_config('enabled', 3, 'local_stackmatheditor');
        config_manager::save_quiz_default(self::CMID, ['_enabled' => 0]);
        $this->assertFalse(config_manager::get_effective_enabled(self::CMID));
    }
}
