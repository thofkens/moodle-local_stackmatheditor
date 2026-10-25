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
 * Toolbar element definitions for STACK MathEditor.
 *
 * Each group has:
 *   - label:           Human-readable group name (resolved via get_string()).
 *   - default_enabled: Whether the group is enabled by default.
 *   - elements:        Array of button definitions.
 *
 * Each element has:
 *   - display:  Symbol or text shown on the button face.
 *   - tooltip:  Descriptive hover text (get_string() for natural language).
 *               Omit for buttons where display alone is self-explanatory
 *               (e.g. sin, cos, +, −).
 *   - label:    LaTeX string used as rendered button face when it contains
 *               a backslash. Plain symbols are redundant if display is set.
 *   - write:    MathQuill write() command (template with cursor placeholders).
 *   - cmd:      MathQuill cmd() command (inserts a single symbol/command).
 *
 * Tooltip priority in toolbar.js: tooltip > display > label > command.
 * Always set tooltip when display alone would not describe the button clearly.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class definitions {
    /** Short stored form of IMPLICIT_EXPLICIT_SINGLE (single-character variables). */
    const VAR_SINGLE = 'single';

    /** Short stored form of IMPLICIT_EXPLICIT_MULTI (multi-character variables). */
    const VAR_MULTI = 'multi';

    /** Explicit multiplication, assume single-character variables. */
    const IMPLICIT_EXPLICIT_SINGLE = 'explicit_single';

    /** Explicit multiplication, assume multi-character variables. */
    const IMPLICIT_EXPLICIT_MULTI = 'explicit_multi';

    /** Space separation, assume single-character variables. */
    const IMPLICIT_SPACE_SINGLE = 'space_single';

    /** Space separation, assume multi-character variables. */
    const IMPLICIT_SPACE_MULTI = 'space_multi';

    /** Leave untouched; STACK handles implicit multiplication. */
    const IMPLICIT_STACK = 'stack';

    /**
     * Normalise stored implicit multiplication mode values.
     *
     * Also accepts the short forms 'single' and 'multi', which map to the explicit modes.
     *
     * @param string $mode Raw mode.
     * @return string Normalised mode.
     */
    public static function normalise_implicit_mode(string $mode): string {
        switch ($mode) {
            case self::VAR_SINGLE:
                return self::IMPLICIT_EXPLICIT_SINGLE;
            case self::VAR_MULTI:
                return self::IMPLICIT_EXPLICIT_MULTI;
            case self::IMPLICIT_EXPLICIT_SINGLE:
            case self::IMPLICIT_EXPLICIT_MULTI:
            case self::IMPLICIT_SPACE_SINGLE:
            case self::IMPLICIT_SPACE_MULTI:
            case self::IMPLICIT_STACK:
                return $mode;
            default:
                return self::IMPLICIT_STACK;
        }
    }

    /**
     * Return all element group definitions.
     *
     * Group labels and button tooltips containing natural language are resolved
     * through Moodle's get_string() so they are delivered in the current user
     * language. Pure mathematical symbols used as button faces stay as literals.
     *
     * @return array Associative array keyed by group identifier.
     */
    public static function get_element_groups(): array {
        $p = 'local_stackmatheditor';

        return [

            // 1. Basic arithmetic.
            'basic_operators' => [
                'label'           => get_string('group_basic_operators', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => '+', 'write' => '+',
                        'tooltip' => get_string('btn_plus', $p)],
                    ['display' => '−', 'write' => '-',
                        'tooltip' => get_string('btn_minus', $p)],
                    ['display' => '·', 'cmd'   => '\\cdot',
                        'tooltip' => get_string('btn_cdot', $p)],
                    ['display' => '÷', 'cmd'   => '\\div',
                        'tooltip' => get_string('btn_div', $p)],
                    ['label'        => '\\frac{a}{b}', 'cmd' => '\\frac',
                        'display_html'  => '<sup>a</sup>&#x2044;<sub>b</sub>',
                        'tooltip'       => get_string('btn_fraction', $p)],
                    ['display' => '%', 'write' => '\\%',
                        'tooltip' => get_string('btn_percent', $p)],
                ],
            ],

            // 2. Powers and roots.
            'power_root' => [
                'label'           => get_string('group_power_root', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => "x\u{207F}", 'cmd' => '^',
                        'tooltip' => get_string('btn_power', $p)],
                    ['display' => "\u{221A}", 'write' => '\\sqrt{}', 'left' => 1,
                        'tooltip' => get_string('btn_sqrt', $p)],
                    ['display' => "ⁿ\u{221A}", 'cmd' => '\\nthroot',
                        'tooltip' => get_string('btn_nthroot', $p)],
                ],
            ],

            // 3. Exponential and logarithm.
            'exponential_log' => [
                'label'           => get_string('group_exponential_log', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => 'exp', 'write' => '\\exp\\left(\\right)', 'left' => 1,
                        'tooltip' => get_string('btn_exp', $p)],
                    ['display' => 'log', 'write' => '\\log\\left(\\right)', 'left' => 1,
                        'tooltip' => get_string('btn_log', $p)],
                    ['display' => 'ln', 'write' => '\\ln\\left(\\right)', 'left' => 1,
                        'tooltip' => get_string('btn_ln', $p)],
                ],
            ],

            // 4. Comparison operators.
            'comparators' => [
                'label'           => get_string('group_comparators', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => '=', 'write' => '=',
                        'tooltip' => get_string('btn_equal', $p)],
                    ['display' => '≠', 'cmd'   => '\\neq',
                        'tooltip' => get_string('btn_neq', $p)],
                    ['display' => '≈', 'cmd'   => '\\approx',
                        'tooltip' => get_string('btn_approx', $p)],
                    ['display' => '<', 'write' => '<',
                        'tooltip' => get_string('btn_less', $p)],
                    ['display' => '>', 'write' => '>',
                        'tooltip' => get_string('btn_greater', $p)],
                    ['display' => '≤', 'cmd'   => '\\leq',
                        'tooltip' => get_string('btn_leq', $p)],
                    ['display' => '≥', 'cmd'   => '\\geq',
                        'tooltip' => get_string('btn_geq', $p)],
                ],
            ],

            // 5. Absolute value, plus-minus.
            'absolute' => [
                'label'           => get_string('group_absolute', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => '|x|', 'write' => '\\left|\\right|', 'left' => 1,
                        'tooltip' => get_string('btn_abs', $p)],
                    ['display' => '±', 'cmd' => '\\pm',
                        'tooltip' => get_string('btn_pm', $p)],
                    ['display' => '∓', 'cmd' => '\\mp',
                        'tooltip' => get_string('btn_mp', $p)],
                ],
            ],

            // 6. Set theory.
            'set_theory' => [
                'label'           => get_string('group_set_theory', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => '∈', 'cmd'   => '\\in',
                        'tooltip' => get_string('btn_in', $p)],
                    ['display' => '∉', 'cmd'   => '\\notin',
                        'tooltip' => get_string('btn_notin', $p)],
                    ['display' => '∪', 'cmd'   => '\\cup',
                        'tooltip' => get_string('btn_cup', $p)],
                    ['display' => '∩', 'cmd'   => '\\cap',
                        'tooltip' => get_string('btn_cap', $p)],
                    ['display' => '∖', 'cmd'   => '\\setminus',
                        'tooltip' => get_string('btn_setminus', $p)],
                    ['display' => '⊆', 'cmd'   => '\\subseteq',
                        'tooltip' => get_string('btn_subseteq', $p)],
                    ['display' => '⊇', 'cmd'   => '\\supseteq',
                        'tooltip' => get_string('btn_supseteq', $p)],
                    ['display' => '⊂', 'cmd'   => '\\subset',
                        'tooltip' => get_string('btn_subset', $p)],
                    ['display' => '⊃', 'cmd'   => '\\supset',
                        'tooltip' => get_string('btn_supset', $p)],
                ],
            ],

            // 7. Logic.
            'logic' => [
                'label'           => get_string('group_logic', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => '¬', 'cmd' => '\\neg',
                        'tooltip' => get_string('btn_neg', $p)],
                    ['display' => '∧', 'cmd' => '\\land',
                        'tooltip' => get_string('btn_logical_and', $p)],
                    ['display' => '∨', 'cmd' => '\\lor',
                        'tooltip' => get_string('btn_logical_or', $p)],
                    ['display' => '⇒', 'cmd' => '\\Rightarrow',
                        'tooltip' => get_string('btn_implies', $p)],
                    ['display' => '⇐', 'cmd' => '\\Leftarrow',
                        'tooltip' => get_string('btn_impliedby', $p)],
                    ['display' => '⇔', 'cmd' => '\\Leftrightarrow',
                        'tooltip' => get_string('btn_iff', $p)],
                ],
            ],

            // Differential calculus: three structured templates, canonical ∂ (diff(...) does
            // not record d vs. ∂). The operand bracket is part of the template; the cursor
            // starts inside it. The total order is checked against the denominator orders.
            'differential_operators' => [
                'label'           => get_string('group_differential_operators', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => '∂/∂x', 'write' => '\\frac{\\partial}{\\partial x}\\left(\\right)', 'left' => 1,
                        'tooltip' => get_string('btn_partial', $p)],
                    ['display' => '∂ⁿ/∂xⁿ', 'write' => '\\frac{\\partial^{2}}{\\partial x^{2}}\\left(\\right)', 'left' => 1,
                        'tooltip' => get_string('btn_partial_n', $p)],
                    ['display' => '∂²/∂x∂y',
                        'write' => '\\frac{\\partial^{2}}{\\partial x\\partial y}\\left(\\right)', 'left' => 1,
                        'tooltip' => get_string('btn_partial_mixed', $p)],
                ],
            ],

            // Integral calculus: one structured template - limits (leave empty for an
            // indefinite integral), integrand in brackets, atomic variable after d. The cursor
            // starts in the integrand. The contour integral has no STACK semantics and is not
            // offered.
            'integral_operators' => [
                'label'           => get_string('group_integral_operators', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => '∫', 'write' => '\\int_{}^{}\\left(\\right)\\mathrm{d}x', 'left' => 5,
                        'tooltip' => get_string('btn_integral', $p)],
                ],
            ],

            // 8. Brackets.
            'brackets' => [
                'label'           => get_string('group_brackets', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => '( )', 'write' => '\\left(\\right)', 'left' => 1,
                        'tooltip' => get_string('btn_round_brackets', $p)],
                    ['display' => '[ ]', 'write' => '\\left[\\right]', 'left' => 1,
                        'tooltip' => get_string('btn_square_brackets', $p)],
                    ['display' => '{ }', 'write' => '\\left\\{\\right\\}', 'left' => 1,
                        'tooltip' => get_string('btn_curly_brackets', $p)],
                ],
            ],

            // 9. Mathematical constants.
            'constants_math' => [
                'label'           => get_string('group_constants_math', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => 'π', 'cmd'   => '\\pi',
                        'tooltip' => get_string('btn_pi', $p)],
                    ['display' => '∞', 'cmd'   => '\\infty',
                        'tooltip' => get_string('btn_infty', $p)],
                    ['display' => 'e', 'write' => '\\mathrm{e}',
                        'tooltip' => get_string('btn_euler', $p)],
                    ['display' => 'i', 'write' => '\\mathrm{i}',
                        'tooltip' => get_string('btn_imaginary', $p)],
                ],
            ],

            // 11. Geometry.
            'geometry' => [
                'label'           => get_string('group_geometry', $p),
                'default_enabled' => false,
                // Distance, Angle and Length come from STACK's own geometry.mac: core,
                // so the group is available wherever STACK is.
                'requires'        => [
                    ['type' => 'stack_core', 'package' => 'geometry.mac'],
                ],
                'elements'        => array_merge(self::get_geometry_elements($p), [
                    ['label'   => '\\overline{AB}', 'write' => '\\overline{}',
                        'display' => 'AB̅',
                        'tooltip' => get_string('btn_overline', $p)],
                    // The degree sign, the bare angle symbol and the perpendicular sign have no
                    // Maxima counterpart - "90circ", "angle" and "a perp b" are not expressions
                    // The angle itself is among the structured entries above.
                ]),
            ],

            // 12. Trigonometry.
            'trigonometry' => [
                'label'           => get_string('group_trigonometry', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => 'sin', 'write' => '\\sin\\left(\\right)',
                        'tooltip' => get_string('btn_sin', $p)],
                    ['display' => 'cos', 'write' => '\\cos\\left(\\right)',
                        'tooltip' => get_string('btn_cos', $p)],
                    ['display' => 'tan', 'write' => '\\tan\\left(\\right)',
                        'tooltip' => get_string('btn_tan', $p)],
                    ['display' => 'asin', 'write' => '\\arcsin\\left(\\right)',
                        'tooltip' => get_string('btn_arcsin', $p)],
                    ['display' => 'acos', 'write' => '\\arccos\\left(\\right)',
                        'tooltip' => get_string('btn_arccos', $p)],
                    ['display' => 'atan', 'write' => '\\arctan\\left(\\right)',
                        'tooltip' => get_string('btn_arctan', $p)],
                ],
            ],

            // 15. Vectors.
            // 15b. Vector products: their own group, because the cross product needs Maxima's
            // vect package and the arrow, the dot and the norm do not. The group is not rendered
            // where the question does not load that package.
            'vector_products' => [
                'label'           => get_string('group_vector_products', $p),
                'default_enabled' => false,
                'requires'        => [
                    ['type' => 'maxima_share', 'package' => 'vect'],
                ],
                'elements'        => [
                    [
                        'display'  => '×',
                        'semantic' => 'crossproduct',
                        'cmd'      => '\\times',
                        'tooltip'  => get_string('btn_cross', $p),
                    ],
                ],
            ],

            'vector_operators' => [
                'label'           => get_string('group_vector_operators', $p),
                'default_enabled' => false,
                // The cross product is not here: LaTeX's times sign is multiplication, and
                // Maxima needs express(a ~ b) from the vect package for a cross product.
                'elements'        => self::get_vector_elements($p),
            ],

            // 17. Vector differential.
            'vector_differential' => [
                'label'           => get_string('group_vector_differential', $p),
                'default_enabled' => false,
                // The vect package provides grad, div and curl, and they need express().
                'requires'        => [
                    ['type' => 'maxima_share', 'package' => 'vect'],
                ],
                'elements'        => self::get_differential_operator_elements($p),
            ],

            // 18. Matrices.
            'matrix_operators' => [
                'label'           => get_string('group_matrix_operators', $p),
                'default_enabled' => false,
                'elements'        => [
                    [
                        'display' => '[⋮] ▾',
                        'popup'   => 'matrix',
                        'tooltip' => get_string('btn_matrix_popup', $p),
                    ],
                    [
                        'display' => '(a⋮b) ▾',
                        'popup'   => 'vector',
                        'tooltip' => get_string('btn_vector_popup', $p),
                    ],
                    [
                        'display' => 'det',
                        'write'   => '\\det\\left(\\right)',
                        'left'    => 1,
                        'tooltip' => get_string('btn_determinant', $p),
                    ],
                    [
                        'display' => 'ident',
                        'write'   => '\\operatorname{ident}\\left(\\right)',
                        'left'    => 1,
                        'tooltip' => get_string('btn_unity_matrix', $p),
                    ],
                    [
                        'display' => 'Aᵀ',
                        'write'   => '\\operatorname{transpose}\\left(\\right)',
                        'left'    => 1,
                        'tooltip' => get_string('btn_transpose', $p),
                    ],
                    // A* and A-dagger are not here: "A^(*)" is not an expression Maxima
                    // accepts, and there is no verified mapping for them.
                ],
            ],

            // 21. Greek letters (lowercase).
            'greek_lower' => [
                'label'           => get_string('group_greek_lower', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => 'α', 'cmd' => '\\alpha', 'tooltip' => get_string('btn_alpha', $p)],
                    ['display' => 'β', 'cmd' => '\\beta', 'tooltip' => get_string('btn_beta', $p)],
                    ['display' => 'γ', 'cmd' => '\\gamma', 'tooltip' => get_string('btn_gamma', $p)],
                    ['display' => 'δ', 'cmd' => '\\delta', 'tooltip' => get_string('btn_delta', $p)],
                    ['display' => 'ε', 'cmd' => '\\epsilon', 'tooltip' => get_string('btn_epsilon', $p)],
                    ['display' => 'ζ', 'cmd' => '\\zeta', 'tooltip' => get_string('btn_zeta', $p)],
                    ['display' => 'η', 'cmd' => '\\eta', 'tooltip' => get_string('btn_eta', $p)],
                    ['display' => 'θ', 'cmd' => '\\theta', 'tooltip' => get_string('btn_theta', $p)],
                    ['display' => 'ι', 'cmd' => '\\iota', 'tooltip' => get_string('btn_iota', $p)],
                    ['display' => 'κ', 'cmd' => '\\kappa', 'tooltip' => get_string('btn_kappa', $p)],
                    ['display' => 'λ', 'cmd' => '\\lambda', 'tooltip' => get_string('btn_lambda', $p)],
                    ['display' => 'μ', 'cmd' => '\\mu', 'tooltip' => get_string('btn_mu', $p)],
                    ['display' => 'ν', 'cmd' => '\\nu', 'tooltip' => get_string('btn_nu', $p)],
                    ['display' => 'ξ', 'cmd' => '\\xi', 'tooltip' => get_string('btn_xi', $p)],
                    ['display' => 'ο', 'write' => 'o', 'tooltip' => get_string('btn_omicron', $p)],
                    ['display' => 'π', 'cmd' => '\\pi', 'tooltip' => get_string('btn_pi_greek', $p)],
                    ['display' => 'ρ', 'cmd' => '\\rho', 'tooltip' => get_string('btn_rho', $p)],
                    ['display' => 'σ', 'cmd' => '\\sigma', 'tooltip' => get_string('btn_sigma', $p)],
                    ['display' => 'τ', 'cmd' => '\\tau', 'tooltip' => get_string('btn_tau', $p)],
                    ['display' => 'υ', 'cmd' => '\\upsilon', 'tooltip' => get_string('btn_upsilon', $p)],
                    ['display' => 'φ', 'cmd' => '\\phi', 'tooltip' => get_string('btn_phi', $p)],
                    ['display' => 'χ', 'cmd' => '\\chi', 'tooltip' => get_string('btn_chi', $p)],
                    ['display' => 'ψ', 'cmd' => '\\psi', 'tooltip' => get_string('btn_psi', $p)],
                    ['display' => 'ω', 'cmd' => '\\omega', 'tooltip' => get_string('btn_omega', $p)],
                ],
            ],

            // 22. Greek letters (uppercase).
            'greek_upper' => [
                'label'           => get_string('group_greek_upper', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => 'Α', 'write' => 'A', 'tooltip' => get_string('btn_Alpha', $p)],
                    ['display' => 'Β', 'write' => 'B', 'tooltip' => get_string('btn_Beta', $p)],
                    ['display' => 'Γ', 'cmd'   => '\\Gamma', 'tooltip' => get_string('btn_Gamma', $p)],
                    ['display' => 'Δ', 'cmd'   => '\\Delta', 'tooltip' => get_string('btn_Delta', $p)],
                    ['display' => 'Ε', 'write' => 'E', 'tooltip' => get_string('btn_Epsilon', $p)],
                    ['display' => 'Ζ', 'write' => 'Z', 'tooltip' => get_string('btn_Zeta', $p)],
                    ['display' => 'Η', 'write' => 'H', 'tooltip' => get_string('btn_Eta', $p)],
                    ['display' => 'Θ', 'cmd'   => '\\Theta', 'tooltip' => get_string('btn_Theta', $p)],
                    ['display' => 'Ι', 'write' => 'I', 'tooltip' => get_string('btn_Iota', $p)],
                    ['display' => 'Κ', 'write' => 'K', 'tooltip' => get_string('btn_Kappa', $p)],
                    ['display' => 'Λ', 'cmd'   => '\\Lambda', 'tooltip' => get_string('btn_Lambda', $p)],
                    ['display' => 'Μ', 'write' => 'M', 'tooltip' => get_string('btn_Mu', $p)],
                    ['display' => 'Ν', 'write' => 'N', 'tooltip' => get_string('btn_Nu', $p)],
                    ['display' => 'Ξ', 'cmd'   => '\\Xi', 'tooltip' => get_string('btn_Xi', $p)],
                    ['display' => 'Ο', 'write' => 'O', 'tooltip' => get_string('btn_Omicron', $p)],
                    ['display' => 'Π', 'cmd'   => '\\Pi', 'tooltip' => get_string('btn_Pi', $p)],
                    ['display' => 'Ρ', 'write' => 'P', 'tooltip' => get_string('btn_Rho', $p)],
                    ['display' => 'Σ', 'cmd'   => '\\Sigma', 'tooltip' => get_string('btn_Sigma', $p)],
                    ['display' => 'Τ', 'write' => 'T', 'tooltip' => get_string('btn_Tau', $p)],
                    ['display' => 'Υ', 'cmd'   => '\\Upsilon', 'tooltip' => get_string('btn_Upsilon', $p)],
                    ['display' => 'Φ', 'cmd'   => '\\Phi', 'tooltip' => get_string('btn_Phi', $p)],
                    ['display' => 'Χ', 'write' => 'X', 'tooltip' => get_string('btn_Chi', $p)],
                    ['display' => 'Ψ', 'cmd'   => '\\Psi', 'tooltip' => get_string('btn_Psi', $p)],
                    ['display' => 'Ω', 'cmd'   => '\\Omega', 'tooltip' => get_string('btn_Omega', $p)],
                ],
            ],

        ];
    }

    /**
     * Return the default enabled state for each group.
     *
     * @return array Group key => bool.
     */
    public static function get_default_config(): array {
        $defaults = [];
        foreach (self::get_element_groups() as $key => $group) {
            $defaults[$key] = $group['default_enabled'];
        }
        return $defaults;
    }

    /**
     * Return all known function names for tex2max/max2tex conversion.
     *
     * @return array List of function name strings.
     */
    public static function get_functions(): array {
        return [
            'sin', 'cos', 'tan',
            'arcsin', 'arccos', 'arctan',
            'asin', 'acos', 'atan',
            'sinh', 'cosh', 'tanh',
            'exp', 'log', 'ln',
            'sqrt', 'abs',
        ];
    }

    /**
     * Return all known Maxima constant names.
     *
     * @return array List of constant strings.
     */
    public static function get_constants(): array {
        return ['pi', 'inf', 'minf', 'true', 'false'];
    }

    /**
     * Return operator symbols used in tex2max conversion.
     *
     * @return array List of operator strings.
     */
    public static function get_operators(): array {
        return ['*', '/', '+', '-'];
    }

    /**
     * Return comparison operator LaTeX commands.
     *
     * @return array List of LaTeX comparison strings.
     */
    public static function get_comparison(): array {
        return ['=', '\\neq', '\\leq', '\\geq', '<', '>', '\\approx'];
    }

    /**
     * Return Greek letter names used for variable detection.
     *
     * @return array List of Greek letter name strings.
     */
    public static function get_greek(): array {
        return [
            'alpha', 'beta', 'gamma', 'delta',
            'epsilon', 'zeta', 'eta', 'theta',
            'iota', 'kappa', 'lambda', 'mu',
            'nu', 'xi', 'pi', 'rho',
            'sigma', 'tau', 'upsilon', 'phi',
            'chi', 'psi', 'omega',
            'Gamma', 'Delta', 'Theta', 'Lambda',
            'Xi', 'Pi', 'Sigma', 'Upsilon',
            'Phi', 'Psi', 'Omega',
        ];
    }

    /**
     * Return SI and common unit abbreviations for the units input type.
     *
     * @return array List of unit abbreviation strings.
     */
    public static function get_units(): array {
        return [
            'm', 'km', 'cm', 'mm', 'nm', 'um',
            'kg', 'g', 'mg', 'ug',
            's', 'ms', 'us', 'ns', 'min', 'hr',
            'N', 'kN', 'mN',
            'Pa', 'kPa', 'MPa', 'GPa', 'bar', 'mbar',
            'J', 'kJ', 'MJ', 'eV', 'keV', 'MeV',
            'W', 'kW', 'MW',
            'A', 'mA', 'uA',
            'V', 'kV', 'mV',
            'C', 'F', 'uF', 'nF', 'pF',
            'Ohm', 'kOhm', 'MOhm',
            'H', 'mH', 'uH',
            'T', 'mT', 'Hz', 'kHz', 'MHz', 'GHz',
            'mol', 'K', 'cd', 'lm', 'lx',
        ];
    }

    /**
     * Return display symbols for units (maps abbreviation to Unicode symbol).
     *
     * @return array Unit abbreviation => display symbol string.
     */
    public static function get_unit_symbols(): array {
        return [
            'm' => 'm', 'km' => 'km', 'cm' => 'cm',
            'mm' => 'mm', 'nm' => 'nm', 'um' => 'µm',
            'kg' => 'kg', 'g' => 'g', 'mg' => 'mg',
            'ug' => 'µg',
            's' => 's', 'ms' => 'ms', 'us' => 'µs',
            'ns' => 'ns', 'min' => 'min', 'hr' => 'h',
            'N' => 'N', 'kN' => 'kN', 'mN' => 'mN',
            'Pa' => 'Pa', 'kPa' => 'kPa', 'MPa' => 'MPa',
            'GPa' => 'GPa', 'bar' => 'bar', 'mbar' => 'mbar',
            'J' => 'J', 'kJ' => 'kJ', 'MJ' => 'MJ',
            'eV' => 'eV', 'keV' => 'keV', 'MeV' => 'MeV',
            'W' => 'W', 'kW' => 'kW', 'MW' => 'MW',
            'A' => 'A', 'mA' => 'mA', 'uA' => 'µA',
            'V' => 'V', 'kV' => 'kV', 'mV' => 'mV',
            'C' => 'C',
            'F' => 'F', 'uF' => 'µF', 'nF' => 'nF',
            'pF' => 'pF',
            'Ohm' => 'Ω', 'kOhm' => 'kΩ', 'MOhm' => 'MΩ',
            'H' => 'H', 'mH' => 'mH', 'uH' => 'µH',
            'T' => 'T', 'mT' => 'mT',
            'Hz' => 'Hz', 'kHz' => 'kHz', 'MHz' => 'MHz',
            'GHz' => 'GHz',
            'mol' => 'mol', 'K' => 'K',
            'cd' => 'cd', 'lm' => 'lm', 'lx' => 'lx',
        ];
    }

    /**
     * Return function names for LaTeX-to-Maxima detection.
     *
     * @return array List of function name strings.
     */
    public static function get_function_names(): array {
        return [
            'sin', 'cos', 'tan',
            'arcsin', 'arccos', 'arctan',
            'sinh', 'cosh', 'tanh',
            'exp', 'log', 'ln',
            'sqrt', 'abs', 'sgn',
            // Complete-token functions: max(x,y) and min(x,y) are function calls,
            // while max inside Umax or argmax is just part of the identifier.
            'max', 'min',
            // Structural operators: without them, "det" and "norm" are split into single
            // variables by implicit multiplication ("d*e*t").
            'det', 'determinant', 'norm', 'transpose',
        ];
    }

    /**
     * Return reserved Maxima keywords.
     *
     * @return array List of reserved word strings.
     */
    public static function get_reserved_words(): array {
        return [
            'if', 'then', 'else', 'elseif',
            'and', 'or', 'not',
            'true', 'false',
            'do', 'for', 'while', 'unless',
            'thru', 'step', 'in',
            'inf', 'minf',
        ];
    }

    /**
     * Return names treated as percent-prefixed constants in Maxima.
     *
     * @return array List of percent-constant strings (including % prefix).
     */
    public static function get_percent_constants(): array {
        return ['%pi', '%e', '%i', '%phi', '%gamma'];
    }

    /**
     * Language strings the editor needs synchronously (accessible names of its controls).
     *
     * @return array Map string key => text in the current language.
     */
    public static function get_js_strings(): array {
        $keys = [
            'aria_add_line',
            'aria_add_row',
            'aria_formula_input',
            'aria_remove_row',
            'aria_remove_step',
            'resize_confirm',
            'toggle_editor',
            'toggle_editor_off',
            'toggle_editor_on',
        ];
        $strings = [];
        foreach ($keys as $key) {
            $strings[$key] = get_string($key, 'local_stackmatheditor');
        }
        return $strings;
    }

    /**
     * Toolbar entries for vector operations.
     *
     * Only what the converter can map: the arrow is decoration and disappears, the dot is
     * multiplication. The norm needs a Maxima function, so its button appears only once the site
     * has named one - without that, the double bar would become abs(), a different function.
     *
     * @param string $p Plugin component name for get_string().
     * @return array Toolbar elements.
     */
    private static function get_vector_elements(string $p): array {
        $elements = [
            [
                'label'   => '\\vec{v}',
                'write'   => '\\vec{}',
                'display' => 'v⃗',
                'tooltip' => get_string('btn_vec', $p),
            ],
            [
                'display' => '·',
                'cmd'     => '\\cdot',
                'tooltip' => get_string('btn_cdot', $p),
            ],
        ];

        if (self::get_norm_function() !== '') {
            $elements[] = [
                'display' => '‖v‖',
                'write'   => '\\left\\|\\right\\|',
                'left'    => 1,
                'tooltip' => get_string('btn_norm', $p),
            ];
        }

        return $elements;
    }

    /**
     * Toolbar entries for elementary geometry.
     *
     * School notation on the surface, STACK's geometry.mac underneath. Only the constructs with
     * a documented STACK counterpart are offered: a point is a list of coordinates, a distance
     * is Distance(A,B), an angle is Angle(A,B,C). A segment, ray, line, circle or sphere has no
     * STACK type, and this plugin does not invent one.
     *
     * @param string $p Plugin component name for get_string().
     * @return array Toolbar elements.
     */
    private static function get_geometry_elements(string $p): array {
        $separator = self::get_coordinate_separator();

        return [
            [
                'display' => 'P(x' . $separator . 'y)',
                'semantic' => 'point2d',
                'write'   => '\\left(' . $separator . '\\right)',
                'left'    => 3,
                'tooltip' => get_string('btn_point_2d', $p),
            ],
            [
                'display' => 'P(x' . $separator . 'y' . $separator . 'z)',
                'semantic' => 'point3d',
                'write'   => '\\left(' . $separator . $separator . '\\right)',
                'left'    => 5,
                'tooltip' => get_string('btn_point_3d', $p),
            ],
            [
                'display' => 'd(A,B)',
                'semantic' => 'distance',
                'write'   => 'd\\left(,\\right)',
                'left'    => 3,
                'tooltip' => get_string('btn_distance', $p),
            ],
            [
                'display' => "\u{2220}ABC",
                'semantic' => 'angle',
                'write'   => '\\angle ',
                'tooltip' => get_string('btn_angle_object', $p),
            ],
            [
                'display' => '|AB|',
                'semantic' => 'segmentlength',
                'write'   => '\\left|\\overline{}\\right|',
                'left'    => 1,
                'tooltip' => get_string('btn_segment_length', $p),
            ],
        ];
    }

    /**
     * Separator between the coordinates of a point.
     *
     * Display only: it changes what a student sees and types, never the meaning. STACK receives
     * a list either way.
     *
     * @return string One of "|", ";" or ",".
     */
    public static function get_coordinate_separator(): string {
        $value = (string) get_config('local_stackmatheditor', 'coordinateseparator');

        return in_array($value, ['|', ';', ','], true) ? $value : '|';
    }

    /**
     * Toolbar entries for the vector differential operators.
     *
     * A LaTeX symbol is not a CAS operation. Maxima has no gradient, divergence or curl that
     * works everywhere: those functions live in the vect package, need express() and depend on
     * the coordinate system, and whether a given STACK installation provides them - or a question
     * defines its own - cannot be decided here. The CAS name therefore comes from a setting, and
     * an operator without one is not offered at all, rather than producing an expression the CAS
     * will reject.
     *
     * "rot" is the German label for what Maxima calls curl; the UI name never becomes the CAS
     * name.
     *
     * @param string $p Plugin component name for get_string().
     * @return array Toolbar elements, possibly empty.
     */
    private static function get_differential_operator_elements(string $p): array {
        $operators = [
            'gradient' => [
                'display' => 'grad',
                'latex'   => '\\operatorname{grad}',
                'tooltip' => get_string('btn_grad', $p),
            ],
            'divergence' => [
                'display' => 'div',
                'latex'   => '\\operatorname{div}',
                'tooltip' => get_string('btn_div_op', $p),
            ],
            'curl' => [
                'display' => 'rot',
                'latex'   => '\\operatorname{rot}',
                'tooltip' => get_string('btn_rot', $p),
            ],
            'laplacian' => [
                'display' => 'Δ',
                'latex'   => '\\Delta',
                'tooltip' => get_string('btn_laplacian', $p),
            ],
        ];

        $configured = self::get_differential_operators();
        $elements   = [];

        foreach ($operators as $semantic => $operator) {
            if (($configured[$semantic] ?? '') === '') {
                continue;
            }
            $elements[] = [
                'display'  => $operator['display'],
                'semantic' => $semantic,
                // The operand belongs to the template: the cursor lands inside the brackets.
                'write'    => $operator['latex'] . '\\left(\\right)',
                'left'     => 1,
                'tooltip'  => $operator['tooltip'],
            ];
        }

        return $elements;
    }

    /**
     * CAS function names for the differential operators, from the site settings.
     *
     * An empty value means the operator is not available on this site.
     *
     * @return array Semantic id => Maxima function name.
     */
    public static function get_differential_operators(): array {
        $operators = [];

        foreach (['gradient', 'divergence', 'curl', 'laplacian'] as $semantic) {
            $value = trim((string) get_config('local_stackmatheditor', 'diffop' . $semantic));
            $operators[$semantic] = preg_match('/^[a-z_][a-z0-9_]*$/i', $value) ? $value : '';
        }

        return $operators;
    }

    /**
     * Every button the toolbar offers, with the LaTeX it writes.
     *
     * The catalogue behind the contract test: a visible button has to have a mapping, and the
     * mapping is the template - not the label, which is why no language strings are in here.
     *
     * @return array List of buttons with group, display, kind, template and semantic id.
     */
    public static function export_button_catalogue(): array {
        $catalogue = [];

        foreach (self::get_element_groups() as $key => $group) {
            foreach ($group['elements'] ?? [] as $element) {
                if (isset($element['write'])) {
                    $kind = 'write';
                    $template = $element['write'];
                } else if (isset($element['cmd'])) {
                    $kind = 'cmd';
                    $template = $element['cmd'];
                } else if (isset($element['popup'])) {
                    $kind = 'popup';
                    $template = $element['popup'];
                } else if (isset($element['matrix'])) {
                    $kind = 'matrix';
                    $template = json_encode($element['matrix']);
                } else {
                    $kind = 'unknown';
                    $template = '';
                }

                $catalogue[] = [
                    'group'    => $key,
                    'display'  => (string) ($element['display'] ?? ''),
                    'kind'     => $kind,
                    'template' => (string) $template,
                    'semantic' => (string) ($element['semantic'] ?? ''),
                    'left'     => (int) ($element['left'] ?? 0),
                ];
            }
        }

        return $catalogue;
    }

    /**
     * Smallest value the dimension setting accepts.
     */
    public const MIN_STRUCTURED_DIMENSION = 2;

    /**
     * Largest value the dimension setting accepts.
     */
    public const MAX_STRUCTURED_DIMENSION = 20;

    /**
     * Default when nothing is configured anywhere.
     *
     * The value the plugin has always used, so an upgrade changes nothing.
     */
    public const DEFAULT_STRUCTURED_DIMENSION = 5;

    /**
     * Read a stored dimension, or null when there is none.
     *
     * 0, null and '' are not dimensions - they mean the level said nothing, and the question has
     * to be passed up. Anything outside the range is clamped rather than discarded: a value that
     * once was valid should not silently disable a chooser.
     *
     * @param mixed $value Stored value.
     * @return int|null Usable dimension, or null.
     */
    public static function clean_max_dimension($value): ?int {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $number = (int) $value;
        if ($number <= 0) {
            return null;
        }

        return max(
            self::MIN_STRUCTURED_DIMENSION,
            min(self::MAX_STRUCTURED_DIMENSION, $number)
        );
    }

    /**
     * Validate what a teacher typed into the dimension field.
     *
     * Different from clean_max_dimension(), and deliberately so: a stored value is read as
     * generously as possible, because it is already there and something once meant it. A value
     * being typed now is checked strictly - silently turning 40 into 20 would tell the author
     * their input was accepted as given.
     *
     * @param string $raw Raw form input.
     * @return string|null Error string identifier, or null when the value is acceptable.
     */
    public static function validate_max_dimension_input(string $raw): ?string {
        $value = trim($raw);

        if ($value === '') {
            // Empty means inherit, which is always allowed.
            return null;
        }

        if (!preg_match('/^\d+$/', $value)) {
            return 'error_maxdimension_number';
        }

        $number = (int) $value;
        if (
            $number < self::MIN_STRUCTURED_DIMENSION
                || $number > self::MAX_STRUCTURED_DIMENSION
        ) {
            return 'error_maxdimension_range';
        }

        return null;
    }

    /**
     * The site-wide maximum.
     *
     * @return int Dimension between MIN_STRUCTURED_DIMENSION and MAX_STRUCTURED_DIMENSION.
     */
    public static function get_instance_max_dimension(): int {
        $value = self::clean_max_dimension(
            get_config('local_stackmatheditor', 'maxstructureddimension')
        );

        return $value ?? self::DEFAULT_STRUCTURED_DIMENSION;
    }

    /**
     * Do the structured choosers exist in this configuration at all?
     *
     * The dimension setting has no meaning without them, which is why the form disables it.
     *
     * @param array $config Group key => enabled.
     * @return bool True when the matrix or the vector group is on.
     */
    public static function has_structured_groups(array $config): bool {
        return !empty($config['matrix_operators']) || !empty($config['vector_operators']);
    }

    /**
     * Labels for the matrix and vector popups.
     *
     * The popup runs in JavaScript and cannot call get_string(), so the strings travel with the
     * definitions export.
     *
     * @return array Map of label keys to translated strings.
     */
    public static function get_popup_strings(): array {
        $p = 'local_stackmatheditor';

        return [
            'matrixTitle'    => get_string('popup_matrix_title', $p),
            'vectorTitle'    => get_string('popup_vector_title', $p),
            'size'           => get_string('popup_matrix_size', $p),
            'dimension'      => get_string('popup_dimension', $p),
            'orientation'    => get_string('popup_orientation', $p),
            'rowVector'      => get_string('popup_row_vector', $p),
            'columnVector'   => get_string('popup_column_vector', $p),
            'vectorSize'     => get_string('popup_vector_size', $p),
        ];
    }

    /**
     * How a one-row or one-column matrix is written to Maxima.
     *
     * 'matrix' keeps matrix([a,b,c]); 'list' writes [a,b,c], which is what STACK questions whose
     * model answer is a list expect. The orientation of a vector is not part of a list, so the
     * setting also decides how a list is drawn again on pre-fill.
     *
     * @return string Either 'matrix' or 'list'.
     */
    public static function get_vector_format(): string {
        $value = get_config('local_stackmatheditor', 'vectorformat');

        return $value === 'list' ? 'list' : 'matrix';
    }

    /**
     * Name of the Maxima function a norm is written to.
     *
     * Maxima has no norm function that covers both vectors and matrices, so the name is a
     * setting: question authors define it in the question variables (e.g. norm(v) :=
     * sqrt(v . v)) and enter the same name here.
     *
     * @return string Maxima function name.
     */
    public static function get_norm_function(): string {
        $value = trim((string)get_config('local_stackmatheditor', 'normfunction'));

        if ($value === '' || !preg_match('/^[a-z_][a-z0-9_]*$/i', $value)) {
            // The default follows the vector format, because the two have to fit: STACK's own
            // Length() takes a list and refuses a matrix, so it is only the right answer where
            // vectors are written as lists. With matrix vectors there is no function in STACK
            // that does this, and the question has to define one - "norm" is the conventional
            // name for it.
            return self::get_vector_format() === 'list' ? 'Length' : 'norm';
        }

        return $value;
    }

    /**
     * Export all definitions as a data structure for JSON encoding.
     *
     * Called by mathjax_injector to pass definitions to JavaScript modules.
     *
     * @return array Nested data structure suitable for json_encode().
     */
    public static function export_for_js(): array {
        return [
            'elementGroups'    => self::get_element_groups(),
            'functions'        => self::get_functions(),
            'constants'        => self::get_constants(),
            'operators'        => self::get_operators(),
            'comparison'       => self::get_comparison(),
            'greek'            => self::get_greek(),
            'units'            => self::get_units(),
            'unitSymbols'      => self::get_unit_symbols(),
            'functionNames'    => self::get_function_names(),
            'reservedWords'    => self::get_reserved_words(),
            'percentConstants' => self::get_percent_constants(),
            'usePercentPi'     => (bool)(int)get_config('local_stackmatheditor', 'usepercentpi'),
            'vectorFormat'     => self::get_vector_format(),
            'popupStrings'     => self::get_popup_strings(),
            'diffOps'          => self::get_differential_operators(),
            'coordinateSeparator' => self::get_coordinate_separator(),
            'pointNotation'    => (bool)(int)get_config(
                'local_stackmatheditor',
                'pointnotation'
            ),
            'normFunction'     => self::get_norm_function(),
            // Accessible names of the editor's own controls, needed synchronously on page load.
            'strings'          => self::get_js_strings(),
        ];
    }

    /**
     * Return group labels with up to three example button faces for the settings UI.
     *
     * Used to populate the multi-select on the admin settings page and the
     * configure form, giving teachers a preview of what each group contains.
     *
     * @return array Group key => label string (with examples appended).
     */
    public static function get_group_labels_with_examples(): array {
        $groups = self::get_element_groups();
        $result = [];
        foreach ($groups as $key => $group) {
            $label    = $group['label'] ?? $key;
            $examples = [];
            $count    = 0;
            foreach (($group['elements'] ?? []) as $el) {
                if ($count >= 3) {
                    $examples[] = '…';
                    break;
                }
                $examples[] = $el['display'] ?? $el['label'] ?? '';
                $count++;
            }
            if (!empty($examples)) {
                $label .= ' (' . implode(', ', $examples) . ')';
            }
            $result[$key] = $label;
        }
        return $result;
    }

    /**
     * Return only the default enabled state for each group.
     *
     * @return array Group key => bool.
     */
    public static function get_default_enabled(): array {
        $result = [];
        foreach (self::get_element_groups() as $key => $group) {
            $result[$key] = (bool) ($group['default_enabled'] ?? false);
        }
        return $result;
    }
}
