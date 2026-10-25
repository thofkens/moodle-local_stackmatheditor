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
 * Tests for the CAS package detection of #66.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\dependency_resolver
 */
final class dependency_resolver_test extends \advanced_testcase {
    /**
     * Every spelling of load() that Maxima accepts is one load.
     *
     * @return void
     */
    public function test_share_package_syntax_variants(): void {
        foreach (
            [
            'load("vect");',
            'load ( "vect" );',
            "load('vect');",
            "a:1;\nload(\"vect\");\nf:x^2;",
            "  load(\"vect\")  ;  ",
            ] as $variables
        ) {
            $this->assertTrue(
                dependency_resolver::has_call($variables, 'load', 'vect'),
                "not recognised: $variables"
            );
        }
    }

    /**
     * The same for the contrib include.
     *
     * @return void
     */
    public function test_contrib_syntax_variants(): void {
        foreach (
            [
            'stack_include_contrib("vectorgeometry.mac");',
            'stack_include_contrib ( "vectorgeometry.mac" );',
            "stack_include_contrib('vectorgeometry.mac');",
            ] as $variables
        ) {
            $this->assertTrue(
                dependency_resolver::has_call(
                    $variables,
                    'stack_include_contrib',
                    'vectorgeometry.mac'
                ),
                "not recognised: $variables"
            );
        }
    }

    /**
     * What must not count as a load.
     *
     * @return void
     */
    public function test_what_is_not_a_load(): void {
        $cases = [
            'a:1;',
            'f:x^2+y^2;',
            '/* load("vect"); */ a:1;',
            "/*\n load(\"vect\");\n*/",
            'load("vectorgeometry.mac");',
            'myload("vect");',
            'load("vect2");',
            '',
        ];

        foreach ($cases as $variables) {
            $this->assertFalse(
                dependency_resolver::has_call($variables, 'load', 'vect'),
                "wrongly recognised: $variables"
            );
        }
    }

    /**
     * A package name that is a prefix of another must not match it.
     *
     * @return void
     */
    public function test_package_names_are_not_prefixes(): void {
        $this->assertFalse(
            dependency_resolver::has_call('load("vectors");', 'load', 'vect')
        );
        $this->assertTrue(
            dependency_resolver::has_call('load("vectors");', 'load', 'vectors')
        );
    }

    /**
     * STACK core is available without the question doing anything.
     *
     * @return void
     */
    public function test_core_needs_no_load(): void {
        $requirement = ['type' => dependency_resolver::TYPE_CORE, 'package' => 'geometry.mac'];

        $checked = dependency_resolver::check_requirement($requirement, 'a:1;');

        $this->assertTrue($checked['satisfied']);
        $this->assertSame(dependency_resolver::SATISFIED, $checked['status']);
    }

    /**
     * A missing requirement says what to add.
     *
     * @return void
     */
    public function test_a_missing_requirement_carries_its_instruction(): void {
        $share = dependency_resolver::check_requirement(
            ['type' => dependency_resolver::TYPE_SHARE, 'package' => 'vect'],
            'f:x^2;'
        );
        $this->assertFalse($share['satisfied']);
        $this->assertSame('load("vect");', $share['instruction']);

        $contrib = dependency_resolver::check_requirement(
            ['type' => dependency_resolver::TYPE_CONTRIB, 'package' => 'vectorgeometry.mac'],
            'f:x^2;'
        );
        $this->assertFalse($contrib['satisfied']);
        $this->assertSame(
            'stack_include_contrib("vectorgeometry.mac");',
            $contrib['instruction']
        );
    }

    /**
     * An unreadable question is not a question with the package.
     *
     * @return void
     */
    public function test_unknown_is_not_available(): void {
        $checked = dependency_resolver::check_requirement(
            ['type' => dependency_resolver::TYPE_SHARE, 'package' => 'vect'],
            null
        );

        $this->assertSame(dependency_resolver::UNKNOWN, $checked['status']);
        $this->assertFalse($checked['satisfied']);

        $group = dependency_resolver::check_group(
            'vector_differential',
            [['type' => dependency_resolver::TYPE_SHARE, 'package' => 'vect']],
            null
        );
        $this->assertFalse($group['available']);
        $this->assertTrue($group['unknown']);
    }

    /**
     * Several requirements are an AND.
     *
     * @return void
     */
    public function test_several_requirements(): void {
        $requires = [
            ['type' => dependency_resolver::TYPE_CONTRIB, 'package' => 'vectorgeometry.mac'],
            ['type' => dependency_resolver::TYPE_SHARE, 'package' => 'vect'],
        ];

        $half = dependency_resolver::check_group('g', $requires, 'load("vect");');
        $this->assertFalse($half['available']);

        $both = dependency_resolver::check_group(
            'g',
            $requires,
            'stack_include_contrib("vectorgeometry.mac"); load("vect");'
        );
        $this->assertTrue($both['available']);
    }

    /**
     * A group without requirements is never in the way.
     *
     * @return void
     */
    public function test_a_group_without_requirements(): void {
        $group = dependency_resolver::check_group('basic', [], null);

        $this->assertTrue($group['available']);
        $this->assertFalse($group['unknown']);
        $this->assertSame([], $group['requirements']);
    }

    /**
     * The declared dependencies of the toolbar are well formed.
     *
     * @return void
     */
    public function test_declared_dependencies(): void {
        $types = [
            dependency_resolver::TYPE_CORE,
            dependency_resolver::TYPE_CONTRIB,
            dependency_resolver::TYPE_SHARE,
        ];
        $seen = 0;

        foreach (definitions::get_element_groups() as $key => $group) {
            foreach ($group['requires'] ?? [] as $requirement) {
                $seen++;
                $this->assertArrayHasKey('type', $requirement, "$key");
                $this->assertArrayHasKey('package', $requirement, "$key");
                $this->assertContains($requirement['type'], $types, "$key");
                $this->assertNotSame('', $requirement['package'], "$key");
            }
        }

        $this->assertGreaterThan(0, $seen, 'at least one group declares a dependency');
    }

    /**
     * The group with the differential operators needs Maxima's vect.
     *
     * @return void
     */
    public function test_vector_differential_declares_vect(): void {
        $groups = definitions::get_element_groups();

        $this->assertArrayHasKey('vector_differential', $groups);
        $this->assertSame(
            [['type' => dependency_resolver::TYPE_SHARE, 'package' => 'vect']],
            $groups['vector_differential']['requires']
        );
    }

    /**
     * Without a question there is no status, and the fail-safe applies.
     *
     * @return void
     */
    public function test_no_question(): void {
        $this->resetAfterTest();

        $this->assertNull(dependency_resolver::get_question_variables(0));

        $status = dependency_resolver::get_group_status(0);
        $this->assertArrayHasKey('vector_differential', $status);
        $this->assertFalse($status['vector_differential']['available']);
        $this->assertContains('vector_differential', dependency_resolver::get_unavailable_groups(0));

        // Core-only groups stay available even without a question.
        $this->assertArrayHasKey('geometry', $status);
        $this->assertTrue($status['geometry']['available']);
        $this->assertNotContains('geometry', dependency_resolver::get_unavailable_groups(0));
    }
}
