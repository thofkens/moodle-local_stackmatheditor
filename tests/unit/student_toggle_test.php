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
 * The student switch is a subordinate permission (#73).
 *
 * What a level above switched off, no level below switches on again - and without an editor
 * there is no switch at all.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\config_manager::get_effective_student_toggle
 */
final class student_toggle_test extends \advanced_testcase {
    /** @var int Course module id used throughout. */
    private const CMID = 4711;

    /** @var int Question bank entry id used throughout. */
    private const QBEID = 815;

    /**
     * Set the site level.
     *
     * @param int $enabled Instance activation mode.
     * @param bool $toggle Site-wide permission.
     * @return void
     */
    private function site(int $enabled, bool $toggle): void {
        set_config('enabled', $enabled, 'local_stackmatheditor');
        set_config('allowstudenttoggle', $toggle ? 1 : 0, 'local_stackmatheditor');
    }

    /**
     * Store a quiz-level configuration.
     *
     * @param bool|null $enabled Editor on this level, null to leave it unset.
     * @param bool|null $toggle Permission on this level, null to leave it unset.
     * @return void
     */
    private function quiz(?bool $enabled, ?bool $toggle): void {
        $this->setAdminUser();
        $config = [];
        if ($enabled !== null) {
            $config['_enabled'] = (int) $enabled;
        }
        if ($toggle !== null) {
            $config['_allowStudentToggle'] = (int) $toggle;
        }
        config_manager::save_quiz_default(self::CMID, $config);
    }

    /**
     * Store a question-level configuration.
     *
     * @param bool|null $enabled Editor on this level, null to leave it unset.
     * @param bool|null $toggle Permission on this level, null to leave it unset.
     * @return void
     */
    private function question(?bool $enabled, ?bool $toggle): void {
        $this->setAdminUser();
        $config = [];
        if ($enabled !== null) {
            $config['_enabled'] = (int) $enabled;
        }
        if ($toggle !== null) {
            $config['_allowStudentToggle'] = (int) $toggle;
        }
        config_manager::save_config(self::CMID, self::QBEID, $config);
    }

    /**
     * The effective permission for the question level.
     *
     * @return bool
     */
    private function effective(): bool {
        return config_manager::get_effective_student_toggle(self::CMID, self::QBEID);
    }

    /**
     * The site level decides on its own when nothing below says anything.
     *
     * @return void
     */
    public function test_site_level(): void {
        $this->resetAfterTest();

        $this->site(1, true);
        $this->assertTrue($this->effective(), 'editor on, permission on');

        $this->site(1, false);
        $this->assertFalse($this->effective(), 'permission off at the top');

        $this->site(0, true);
        $this->assertFalse($this->effective(), 'no editor, no switch');

        $this->site(0, false);
        $this->assertFalse($this->effective());
    }

    /**
     * The whole matrix from the issue, level by level.
     *
     * One row differs from the table in #73 on purpose. The issue assumes that a quiz that
     * switches the editor off is a hard upper bound for the question below it. This plugin has
     * never worked that way: instance modes 2 and 3 exist precisely so that a question can
     * decide for itself, and the configuration page offers that to teachers. Changing it would
     * be a different feature, and a silent one. So the editor keeps its own contract, the
     * student switch follows the issue, and the two are tested separately.
     *
     * @return void
     */
    public function test_the_cross_level_matrix(): void {
        $this->resetAfterTest();

        // System editor, system toggle, quiz editor, quiz toggle, question editor,
        // question toggle, expected editor, expected switch.
        $cases = [
            [1, true, true, true, true, true, true, true],
            [1, false, true, true, true, true, true, false],
            [1, true, true, false, true, true, true, false],
            [1, true, true, true, true, false, true, false],
            // The instance mode is the one hard bound: mode 0 is "off, no override".
            [0, true, true, true, true, true, false, false],
            // The quiz says off, the question says on: the question decides about the editor,
            // and the switch follows the editor.
            [1, true, false, true, true, true, true, true],
            [1, true, true, true, false, true, false, false],
        ];

        foreach ($cases as $index => $case) {
            [$sysenabled, $systoggle, $quizenabled, $quiztoggle,
                $qenabled, $qtoggle, $editor, $switch] = $case;

            $this->site($sysenabled === 0 ? 0 : 3, $systoggle);
            $this->quiz($quizenabled, $quiztoggle);
            $this->question($qenabled, $qtoggle);

            $this->assertSame(
                $editor,
                config_manager::get_effective_enabled(self::CMID, self::QBEID),
                "case $index: editor"
            );
            $this->assertSame($switch, $this->effective(), "case $index: student switch");
        }
    }

    /**
     * A quiz that switches the editor off decides for every question that says nothing.
     *
     * @return void
     */
    public function test_a_quiz_without_the_editor(): void {
        $this->resetAfterTest();

        $this->site(3, true);
        $this->quiz(false, true);
        $this->question(null, null);

        $this->assertFalse(config_manager::get_effective_enabled(self::CMID, self::QBEID));
        $this->assertFalse($this->effective(), 'no editor in this quiz, so no switch either');
    }

    /**
     * A lower level cannot take a permission back.
     *
     * @return void
     */
    public function test_a_lower_level_cannot_grant_what_is_taken_away(): void {
        $this->resetAfterTest();

        // The site says no, everything below says yes.
        $this->site(3, false);
        $this->quiz(true, true);
        $this->question(true, true);
        $this->assertFalse($this->effective(), 'the site said no');

        // The quiz says no, the question says yes.
        $this->site(3, true);
        $this->quiz(true, false);
        $this->question(true, true);
        $this->assertFalse($this->effective(), 'the quiz said no');
    }

    /**
     * No editor on a level means no switch, whatever that level stored.
     *
     * @return void
     */
    public function test_no_editor_means_no_switch(): void {
        $this->resetAfterTest();

        $this->site(3, true);
        $this->quiz(true, true);
        $this->question(false, true);

        $this->assertFalse(config_manager::get_effective_enabled(self::CMID, self::QBEID));
        $this->assertFalse($this->effective());
    }

    /**
     * An installation that never had the setting keeps the switch.
     *
     * @return void
     */
    public function test_the_upgrade_default(): void {
        $this->resetAfterTest();

        unset_config('allowstudenttoggle', 'local_stackmatheditor');
        set_config('enabled', 1, 'local_stackmatheditor');

        $this->assertTrue(
            config_manager::get_instance_student_toggle(),
            'an upgrade must not quietly take the switch away'
        );
        $this->assertTrue($this->effective());
    }
}
