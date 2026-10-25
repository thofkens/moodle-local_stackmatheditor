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
 * Are the CAS packages a toolbar group needs available in this question?
 *
 * A verified mapping says an operation is supported in principle. It does not say the
 * function exists in the question at hand: `grad` needs Maxima's vect package, and a contrib
 * library has to be included by the question. Both are decisions of the question author, and
 * this class only reads them.
 *
 * It never executes CAS code, never writes question variables and never loads anything. The
 * truth lives in the STACK question; nothing is cached alongside it.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dependency_resolver {
    /** @var string Loaded by STACK itself, available in every question. */
    public const TYPE_CORE = 'stack_core';

    /** @var string Included by the question with stack_include_contrib(). */
    public const TYPE_CONTRIB = 'stack_contrib';

    /** @var string A Maxima share package, loaded with load(). */
    public const TYPE_SHARE = 'maxima_share';

    /** @var string Requirement met. */
    public const SATISFIED = 'satisfied';

    /** @var string Requirement not met: the package is not loaded in this question. */
    public const MISSING = 'missing';

    /** @var string The question, or its variables, could not be read. */
    public const UNKNOWN = 'unknown';

    /**
     * Remove everything that is not a statement.
     *
     * Maxima comments are /* ... *\/ and may span lines. After this, whitespace is collapsed so
     * that the patterns below do not have to care about formatting.
     *
     * @param string $source Question variables.
     * @return string Normalised source.
     */
    public static function normalise(string $source): string {
        // Comments first: a load() inside a comment is not a load().
        $source = preg_replace('#/\*.*?\*/#s', ' ', $source) ?? '';

        return trim(preg_replace('/\s+/', ' ', $source) ?? '');
    }

    /**
     * Is a package loaded by this source?
     *
     * Recognises the call with either quote style and with whitespace anywhere a Maxima parser
     * would allow it. Deliberately not a full parser: this is feature detection, and anything it
     * cannot read counts as not loaded rather than as loaded.
     *
     * @param string $source Question variables.
     * @param string $function Maxima function, load or stack_include_contrib.
     * @param string $package Package name.
     * @return bool True when the call is there.
     */
    public static function has_call(string $source, string $function, string $package): bool {
        $normalised = self::normalise($source);
        $pattern = '/(?:^|[^A-Za-z0-9_])'
            . preg_quote($function, '/')
            . '\s*\(\s*(["\'])'
            . preg_quote($package, '/')
            . '\1\s*\)/i';

        return (bool) preg_match($pattern, $normalised);
    }

    /**
     * Read the question variables of a STACK question.
     *
     * @param int $questionid Question id.
     * @return string|null The variables, or null when they cannot be read.
     */
    public static function get_question_variables(int $questionid): ?string {
        global $DB;

        if ($questionid <= 0) {
            return null;
        }

        if (!$DB->get_manager()->table_exists('qtype_stack_options')) {
            return null;
        }

        $value = $DB->get_field(
            'qtype_stack_options',
            'questionvariables',
            ['questionid' => $questionid],
            IGNORE_MISSING
        );

        return $value === false ? null : (string) $value;
    }

    /**
     * Status of one requirement.
     *
     * @param array $requirement Requirement with 'type' and 'package'.
     * @param string|null $variables Question variables, or null when unknown.
     * @return array Requirement plus 'status' and 'instruction'.
     */
    public static function check_requirement(array $requirement, ?string $variables): array {
        $type = $requirement['type'] ?? '';
        $package = $requirement['package'] ?? '';
        $instruction = '';
        $status = self::UNKNOWN;

        if ($type === self::TYPE_CORE) {
            // Loaded by stackmaxima.mac: available wherever STACK itself is.
            $status = self::SATISFIED;
        } else if ($variables === null) {
            // Fail-safe: an unreadable question is not a question with the package.
            $status = self::UNKNOWN;
        } else if ($type === self::TYPE_CONTRIB) {
            $instruction = 'stack_include_contrib("' . $package . '");';
            $status = self::has_call($variables, 'stack_include_contrib', $package)
                ? self::SATISFIED
                : self::MISSING;
        } else if ($type === self::TYPE_SHARE) {
            $instruction = 'load("' . $package . '");';
            $status = self::has_call($variables, 'load', $package)
                ? self::SATISFIED
                : self::MISSING;
        }

        return [
            'type'        => $type,
            'package'     => $package,
            'status'      => $status,
            'satisfied'   => $status === self::SATISFIED,
            'instruction' => $instruction,
        ];
    }

    /**
     * Status of a whole group.
     *
     * All requirements have to be met; a group without requirements is always available.
     *
     * @param string $group Group key.
     * @param array $requires Requirements of the group.
     * @param string|null $variables Question variables, or null when unknown.
     * @return array Status object: group, available, unknown and the checked requirements.
     */
    public static function check_group(string $group, array $requires, ?string $variables): array {
        $requirements = [];
        $available = true;
        $unknown = false;

        foreach ($requires as $requirement) {
            $checked = self::check_requirement($requirement, $variables);
            $requirements[] = $checked;
            if (!$checked['satisfied']) {
                $available = false;
            }
            if ($checked['status'] === self::UNKNOWN) {
                $unknown = true;
            }
        }

        return [
            'group'        => $group,
            'available'    => $available,
            'unknown'      => $unknown,
            'requirements' => $requirements,
        ];
    }

    /**
     * Status of every group that declares requirements.
     *
     * @param int $questionid Question id, or 0 when it cannot be determined.
     * @return array Group key => status object. Groups without requirements are not listed.
     */
    public static function get_group_status(int $questionid): array {
        $variables = self::get_question_variables($questionid);
        $status = [];

        foreach (definitions::get_element_groups() as $key => $group) {
            $requires = $group['requires'] ?? [];
            if (!$requires) {
                continue;
            }
            $status[$key] = self::check_group($key, $requires, $variables);
        }

        return $status;
    }

    /**
     * Group keys that must not be rendered for a learner.
     *
     * Missing and unknown both hide the group: a learner never sees a button the question cannot
     * execute, and never sees why.
     *
     * @param int $questionid Question id, or 0 when it cannot be determined.
     * @return array Group keys to hide.
     */
    public static function get_unavailable_groups(int $questionid): array {
        $hidden = [];

        foreach (self::get_group_status($questionid) as $key => $status) {
            if (!$status['available']) {
                $hidden[] = $key;
            }
        }

        return $hidden;
    }
}
