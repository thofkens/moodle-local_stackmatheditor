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
 * The chooser limit is inherited, most specific value first (#76).
 *
 * Unlike the student switch, this is ordinary inheritance: a level that says nothing passes the
 * question up, and the most specific answer wins.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\config_manager::get_effective_max_dimension
 */
final class max_dimension_test extends \advanced_testcase {
    /** @var int Course module id used throughout. */
    private const CMID = 5150;

    /** @var int Question bank entry id used throughout. */
    private const QBEID = 2323;

    /**
     * Store a level, or nothing when the value is null.
     *
     * @param int $cmid Course module id.
     * @param int $qbeid Question bank entry id, 0 for the quiz level.
     * @param int|null $value Dimension, or null to store no value at all.
     * @return void
     */
    private function store(int $cmid, int $qbeid, ?int $value): void {
        $this->setAdminUser();
        $config = ['matrix_operators' => 1];
        if ($value !== null) {
            $config['_maxStructuredDimension'] = $value;
        }

        if ($qbeid > 0) {
            config_manager::save_config($cmid, $qbeid, $config);
        } else {
            config_manager::save_quiz_default($cmid, $config);
        }
    }

    /**
     * The whole matrix from the issue.
     *
     * @return void
     */
    public function test_the_cross_level_matrix(): void {
        $this->resetAfterTest();

        // Plugin, quiz, question, expected.
        $cases = [
            [5, null, null, 5],
            [5, 7, null, 7],
            [5, 7, 3, 3],
            [8, null, 4, 4],
            [6, 6, 6, 6],
        ];

        foreach ($cases as $index => $case) {
            [$plugin, $quiz, $question, $expected] = $case;

            set_config('maxstructureddimension', $plugin, 'local_stackmatheditor');
            $this->store(self::CMID, 0, $quiz);
            $this->store(self::CMID, self::QBEID, $question);

            $this->assertSame(
                $expected,
                config_manager::get_effective_max_dimension(self::CMID, self::QBEID),
                "case $index"
            );
        }
    }

    /**
     * Without configuration anywhere, the value the plugin always had.
     *
     * @return void
     */
    public function test_the_upgrade_default(): void {
        $this->resetAfterTest();

        unset_config('maxstructureddimension', 'local_stackmatheditor');

        $this->assertSame(5, definitions::DEFAULT_STRUCTURED_DIMENSION);
        $this->assertSame(5, definitions::get_instance_max_dimension());
        $this->assertSame(5, config_manager::get_effective_max_dimension(0, 0));
    }

    /**
     * Nothing stored is not a dimension of zero.
     *
     * @return void
     */
    public function test_empty_values_mean_inherit(): void {
        foreach ([null, '', 0, '0', false] as $empty) {
            $this->assertNull(
                definitions::clean_max_dimension($empty),
                'an empty value must not be read as a dimension'
            );
        }
    }

    /**
     * A value outside the range is brought back into it rather than dropped.
     *
     * @return void
     */
    public function test_values_are_clamped(): void {
        // A positive value below the minimum is an intention: clamp it up. A negative one is
        // not a dimension at all, so it means "nothing stored here" like an empty field.
        $this->assertSame(2, definitions::clean_max_dimension(1));
        $this->assertNull(definitions::clean_max_dimension(-4));
        $this->assertSame(20, definitions::clean_max_dimension(99));
        $this->assertSame(7, definitions::clean_max_dimension('7'));
        $this->assertSame(7, definitions::clean_max_dimension(7.9));
    }

    /**
     * An invalid site setting falls back, it does not disable the choosers.
     *
     * @return void
     */
    public function test_an_invalid_site_value(): void {
        $this->resetAfterTest();

        set_config('maxstructureddimension', 'seven', 'local_stackmatheditor');
        $this->assertSame(5, definitions::get_instance_max_dimension());

        set_config('maxstructureddimension', 0, 'local_stackmatheditor');
        $this->assertSame(5, definitions::get_instance_max_dimension());

        set_config('maxstructureddimension', 99, 'local_stackmatheditor');
        $this->assertSame(20, definitions::get_instance_max_dimension());
    }

    /**
     * What a teacher types is checked, not quietly corrected (#76).
     *
     * @return void
     */
    public function test_form_input_is_validated(): void {
        // Acceptable.
        $this->assertNull(definitions::validate_max_dimension_input(''));
        $this->assertNull(definitions::validate_max_dimension_input('  '));
        $this->assertNull(definitions::validate_max_dimension_input('2'));
        $this->assertNull(definitions::validate_max_dimension_input('20'));
        $this->assertNull(definitions::validate_max_dimension_input('7'));

        // Not a number.
        $this->assertSame(
            'error_maxdimension_number',
            definitions::validate_max_dimension_input('seven')
        );
        $this->assertSame(
            'error_maxdimension_number',
            definitions::validate_max_dimension_input('3.5')
        );
        $this->assertSame(
            'error_maxdimension_number',
            definitions::validate_max_dimension_input('-3')
        );

        // A number, but not one the plugin can honour. Clamping it silently would tell the
        // author their input was taken as given.
        $this->assertSame(
            'error_maxdimension_range',
            definitions::validate_max_dimension_input('1')
        );
        $this->assertSame(
            'error_maxdimension_range',
            definitions::validate_max_dimension_input('40')
        );
    }

    /**
     * Reading a stored value stays generous, and that is not a contradiction.
     *
     * @return void
     */
    public function test_reading_is_generous_where_writing_is_strict(): void {
        // Typing 40 is refused; finding 40 in the database is honoured as far as possible,
        // because something once meant it and the chooser has to show something.
        $this->assertSame(
            'error_maxdimension_range',
            definitions::validate_max_dimension_input('40')
        );
        $this->assertSame(20, definitions::clean_max_dimension(40));
    }

    /**
     * The setting only means something with a structured group.
     *
     * @return void
     */
    public function test_the_dependency_on_the_groups(): void {
        $this->assertFalse(definitions::has_structured_groups([]));
        $this->assertFalse(definitions::has_structured_groups([
            'matrix_operators' => 0,
            'vector_operators' => 0,
        ]));
        $this->assertTrue(definitions::has_structured_groups(['matrix_operators' => 1]));
        $this->assertTrue(definitions::has_structured_groups(['vector_operators' => 1]));
        $this->assertTrue(definitions::has_structured_groups([
            'matrix_operators' => 1,
            'vector_operators' => 1,
        ]));
    }

    /**
     * A stored value survives the groups being switched off.
     *
     * @return void
     */
    public function test_a_stored_value_is_not_lost(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        config_manager::save_quiz_default(self::CMID, [
            'matrix_operators' => 1,
            '_maxStructuredDimension' => 9,
        ]);
        config_manager::save_quiz_default(self::CMID, [
            'matrix_operators' => 0,
            'vector_operators' => 0,
            '_maxStructuredDimension' => 9,
        ]);

        $stored = config_manager::get_quiz_default(self::CMID);
        $this->assertSame(9, (int) $stored['_maxStructuredDimension']);
        $this->assertFalse(definitions::has_structured_groups($stored));
    }
}
