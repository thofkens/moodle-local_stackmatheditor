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
 * Configuration must not travel between quizzes (#68).
 *
 * The same question bank entry can be used in several quizzes. A configuration made in one of
 * them is a statement about that quiz, not about the question everywhere. Only the explicit
 * global default (cmid = 0) crosses quizzes.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\config_manager
 */
final class config_cross_quiz_test extends \advanced_testcase {
    /** @var int Question bank entry used by both quizzes. */
    private const QBEID = 4242;

    /** @var int Course module id of quiz A. */
    private const CMID_A = 100;

    /** @var int Course module id of quiz B. */
    private const CMID_B = 200;

    /**
     * Write one configuration record the way save_config() does.
     *
     * @param int $cmid Course module id, 0 for the global default.
     * @param int|null $qbeid Question bank entry id, null for a quiz default.
     * @param array $config Configuration.
     * @return void
     */
    private function write_config(int $cmid, ?int $qbeid, array $config): void {
        // The save API records who last changed a configuration.
        $this->setAdminUser();
        config_manager::save_config($cmid, $qbeid ?? 0, $config);
    }

    /**
     * A question configuration in quiz A must not reach quiz B.
     *
     * @return void
     */
    public function test_a_configuration_does_not_travel_to_another_quiz(): void {
        $this->resetAfterTest();

        $this->write_config(self::CMID_A, self::QBEID, ['geometry' => 1]);

        $inquiza = config_manager::get_config(self::CMID_A, self::QBEID);
        $this->assertSame(1, (int) ($inquiza['geometry'] ?? 0), 'quiz A keeps its own setting');

        $inquizb = config_manager::get_config(self::CMID_B, self::QBEID);
        $this->assertSame(
            (int) (config_manager::get_instance_base_config()['geometry'] ?? 0),
            (int) ($inquizb['geometry'] ?? 0),
            'quiz B must fall back to the instance default, not to quiz A'
        );
    }

    /**
     * The explicit global default is inherited by every quiz.
     *
     * @return void
     */
    public function test_the_global_default_is_inherited(): void {
        $this->resetAfterTest();

        $this->write_config(0, self::QBEID, ['geometry' => 1]);

        foreach ([self::CMID_A, self::CMID_B] as $cmid) {
            $config = config_manager::get_config($cmid, self::QBEID);
            $this->assertSame(
                1,
                (int) ($config['geometry'] ?? 0),
                "the global default must reach quiz $cmid"
            );
        }
    }

    /**
     * An exact configuration overrides the global default, in that quiz only.
     *
     * @return void
     */
    public function test_an_exact_configuration_wins(): void {
        $this->resetAfterTest();

        $this->write_config(0, self::QBEID, ['geometry' => 1]);
        $this->write_config(self::CMID_B, self::QBEID, ['geometry' => 0]);

        $this->assertSame(
            0,
            (int) (config_manager::get_config(self::CMID_B, self::QBEID)['geometry'] ?? 1),
            'the question configuration of quiz B wins there'
        );
        $this->assertSame(
            1,
            (int) (config_manager::get_config(self::CMID_A, self::QBEID)['geometry'] ?? 0),
            'and does not affect quiz A, which still sees the global default'
        );
    }

    /**
     * Single and batch lookup answer the same question the same way.
     *
     * @return void
     */
    public function test_batch_and_single_lookup_agree(): void {
        $this->resetAfterTest();

        $this->write_config(self::CMID_A, self::QBEID, ['geometry' => 1]);

        $single = config_manager::get_config(self::CMID_B, self::QBEID);
        $batch = config_manager::get_configs(self::CMID_B, [self::QBEID]);

        $this->assertArrayHasKey(self::QBEID, $batch);
        $this->assertSame(
            (int) ($single['geometry'] ?? 0),
            (int) ($batch[self::QBEID]['geometry'] ?? 0),
            'batch and single lookup must use the same hierarchy'
        );
    }

    /**
     * The batch path inherits the global default as well.
     *
     * @return void
     */
    public function test_batch_inherits_the_global_default(): void {
        $this->resetAfterTest();

        $this->write_config(0, self::QBEID, ['geometry' => 1]);

        $batch = config_manager::get_configs(self::CMID_B, [self::QBEID]);

        $this->assertSame(1, (int) ($batch[self::QBEID]['geometry'] ?? 0));
    }

    /**
     * A quiz default applies to every question of that quiz and to no other quiz.
     *
     * @return void
     */
    public function test_a_quiz_default_stays_in_its_quiz(): void {
        $this->resetAfterTest();

        $this->setAdminUser();
        config_manager::save_quiz_default(self::CMID_A, ['geometry' => 1]);

        $this->assertSame(
            1,
            (int) (config_manager::get_config(self::CMID_A, self::QBEID)['geometry'] ?? 0)
        );
        $this->assertSame(
            (int) (config_manager::get_instance_base_config()['geometry'] ?? 0),
            (int) (config_manager::get_config(self::CMID_B, self::QBEID)['geometry'] ?? 0)
        );
    }
}
