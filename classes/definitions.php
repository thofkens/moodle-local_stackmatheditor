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
    /** Legacy single-character variable mode identifier. */
    const VAR_SINGLE = 'single';

    /** Legacy multi-character variable mode identifier. */
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
     * Supports legacy values 'single' and 'multi'.
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
                    ['label'        => '\\frac{a}{b}', 'write' => '\\frac{}{}',
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
                    ['display' => "x\u{207F}", 'write' => '^{}',
                        'tooltip' => get_string('btn_power', $p)],
                    ['display' => "\u{221A}", 'write' => '\\sqrt{}', 'left' => 1,
                        'tooltip' => get_string('btn_sqrt', $p)],
                    ['display' => "ⁿ\u{221A}", 'write' => '\\nthroot{}{}',
                        'tooltip' => get_string('btn_nthroot', $p)],
                ],
            ],

            // 3. Exponential and logarithm.
            'exponential_log' => [
                'label'           => get_string('group_exponential_log', $p),
                'default_enabled' => true,
                'elements'        => [
                    ['display' => 'exp', 'write' => '\\exp\\left(\\right)',
                        'tooltip' => get_string('btn_exp', $p)],
                    ['display' => 'log', 'write' => '\\log\\left(\\right)',
                        'tooltip' => get_string('btn_log', $p)],
                    ['display' => 'ln', 'write' => '\\ln\\left(\\right)',
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
                    ['display' => '|x|', 'write' => '\\left|\\right|',
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
                    // @codingStandardsIgnoreStart
                    /*
                    // Number sets: deferred until MathQuill write support is verified.
                    ['display' => 'ℕ', 'write' => '\\mathbb{N}',
                        'tooltip' => get_string('btn_naturals', $p)],
                    ['display' => 'ℤ', 'write' => '\\mathbb{Z}',
                        'tooltip' => get_string('btn_integers', $p)],
                    ['display' => 'ℚ', 'write' => '\\mathbb{Q}',
                        'tooltip' => get_string('btn_rationals', $p)],
                    ['display' => 'ℝ', 'write' => '\\mathbb{R}',
                        'tooltip' => get_string('btn_reals', $p)],
                    ['display' => 'ℂ', 'write' => '\\mathbb{C}',
                        'tooltip' => get_string('btn_complex', $p)],
                    */
                    // @codingStandardsIgnoreEnd
                ],
            ],

            // 7. Logic.
            'logic' => [
                'label'           => get_string('group_logic', $p),
                'default_enabled' => false,
                'elements'        => [
                    // @codingStandardsIgnoreStart
                    /*
                    // Quantifiers: deferred - STACK 4.13 knows neither forall, exists nor
                    // nexists, so every answer written with these buttons would be invalid.
                    ['display' => '∀', 'cmd' => '\\forall',
                        'tooltip' => get_string('btn_forall', $p)],
                    ['display' => '∃', 'cmd' => '\\exists',
                        'tooltip' => get_string('btn_exists', $p)],
                    ['display' => '∄', 'cmd' => '\\nexists',
                        'tooltip' => get_string('btn_nexists', $p)],
                    */
                    // @codingStandardsIgnoreEnd
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

            // Differential calculus (#46): three structured templates, canonical ∂ (diff(...) does
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

            // Integral calculus (#44): one structured template - limits (leave empty for an
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
                    ['display' => '( )', 'write' => '\\left(\\right)',
                        'tooltip' => get_string('btn_round_brackets', $p)],
                    ['display' => '[ ]', 'write' => '\\left[\\right]',
                        'tooltip' => get_string('btn_square_brackets', $p)],
                    ['display' => '{ }', 'write' => '\\left\\{\\right\\}',
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

            // @codingStandardsIgnoreStart
            /*
            // 10. Physical constants.
            'constants_nature' => [
                'label'           => get_string('group_constants_nature', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => 'c₀', 'write' => 'c_0',
                        'tooltip' => get_string('btn_speed_of_light', $p)],
                    ['display' => 'ℏ', 'cmd'   => '\\hbar',
                        'tooltip' => get_string('btn_hbar', $p)],
                    ['display' => 'G', 'cmd'   => '\\mathrm{G}',
                        'tooltip' => get_string('btn_gravitational', $p)],
                    ['display' => 'e⁻', 'write' => '\\mathrm{e^{-}}',
                        'tooltip' => get_string('btn_electron_charge', $p)],
                    ['display' => 'k', 'write' => '\\mathrm{k_B}',
                        'tooltip' => get_string('btn_boltzmann', $p)],
                    ['display' => 'ε₀', 'write' => '\\varepsilon_0',
                        'tooltip' => get_string('btn_permittivity', $p)],
                    ['display' => 'μ₀', 'write' => '\\mu_0',
                        'tooltip' => get_string('btn_permeability', $p)],
                ],
            ],

            // 11. Geometry.
            'geometry' => [
                'label'           => get_string('group_geometry', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['label'   => '\\overline{AB}', 'write' => '\\overline{}',
                        'display' => 'AB̅',
                        'tooltip' => get_string('btn_overline', $p)],
                    ['display' => '°', 'cmd'   => '\\circ',
                        'tooltip' => get_string('btn_degree', $p)],
                    ['display' => '∠', 'cmd'   => '\\angle',
                        'tooltip' => get_string('btn_angle', $p)],
                    ['display' => '⊥', 'cmd'   => '\\perp',
                        'tooltip' => get_string('btn_perp', $p)],
                ],
            ],
            */
            // @codingStandardsIgnoreEnd

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

            // @codingStandardsIgnoreStart
            /*
            // 13. Hyperbolic functions.
            'hyperbolic' => [
                'label'           => get_string('group_hyperbolic', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => 'sinh', 'write' => '\\sinh\\left(\\right)'],
                    ['display' => 'cosh', 'write' => '\\cosh\\left(\\right)'],
                    ['display' => 'tanh', 'write' => '\\tanh\\left(\\right)'],
                ],
            ],

            // 14. Calculus operators.
            'analysis_operators' => [
                'label'           => get_string('group_analysis_operators', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => '|x|', 'write' => '\\left|\\right|',
                        'tooltip' => get_string('btn_abs', $p)],
                    ['display' => '∑', 'write' => '\\sum_{}^{}',
                        'tooltip' => get_string('btn_sum', $p)],
                    ['display' => '∏', 'write' => '\\prod_{}^{}',
                        'tooltip' => get_string('btn_prod', $p)],
                ],
            ],

            // 15. Vectors.
            'vector_operators' => [
                'label'           => get_string('group_vector_operators', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['label'   => '\\vec{v}', 'write' => '\\vec{}',
                        'display' => 'v⃗',
                        'tooltip' => get_string('btn_vec', $p)],
                    ['display' => '‖v‖', 'write' => '\\left\\|\\right\\|',
                        'tooltip' => get_string('btn_norm', $p)],
                    ['display' => '·', 'cmd'   => '\\cdot',
                        'tooltip' => get_string('btn_cdot', $p)],
                    ['display' => '×', 'cmd'   => '\\times',
                        'tooltip' => get_string('btn_cross', $p)],
                ],
            ],

            // 16. Differential calculus.
            ],

            // 17. Vector differential.
            'vector_differential' => [
                'label'           => get_string('group_vector_differential', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => 'grad', 'write' => '\\mathrm{grad}\\,',
                        'tooltip' => get_string('btn_grad', $p)],
                    ['display' => 'div', 'write' => '\\mathrm{div}\\,',
                        'tooltip' => get_string('btn_div_op', $p)],
                    ['display' => 'rot', 'write' => '\\mathrm{rot}\\,',
                        'tooltip' => get_string('btn_rot', $p)],
                ],
            ],

            // 18. Matrices.
            'matrix_operators' => [
                'label'           => get_string('group_matrix_operators', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => '𝟙', 'write' => '\\mathbb{1}',
                        'tooltip' => get_string('btn_unity_matrix', $p)],
                    ['display' => 'Aᵀ', 'write' => '^{\\intercal}',
                        'tooltip' => get_string('btn_transpose', $p)],
                    ['display' => 'A*', 'write' => '^{*}',
                        'tooltip' => get_string('btn_conjugate', $p)],
                    ['display' => 'A†', 'write' => '^{\\dagger}',
                        'tooltip' => get_string('btn_adjoint', $p)],
                ],
            ],

            // 19. Integral calculus.
            ],

            // 20. Statistics.
            'statistical_operators' => [
                'label'           => get_string('group_statistical_operators', $p),
                'default_enabled' => false,
                'elements'        => [
                    ['display' => 'n!', 'write' => '!',
                        'tooltip' => get_string('btn_factorial', $p)],
                    ['label'   => '\\binom{n}{k}', 'write' => '\\binom{}{}',
                        'display' => 'C(n,k)',
                        'tooltip' => get_string('btn_binomial', $p)],
                    ['display' => 'E[X]', 'write' => 'E\\left[\\right]',
                        'tooltip' => get_string('btn_expected_value', $p)],
                    ['display' => 'σ', 'cmd'   => '\\sigma',
                        'tooltip' => get_string('btn_std_dev', $p)],
                    ['display' => '∧', 'cmd'   => '\\land',
                        'tooltip' => get_string('btn_logical_and', $p)],
                    ['display' => '∨', 'cmd'   => '\\lor',
                        'tooltip' => get_string('btn_logical_or', $p)],
                    ['display' => 'Γ', 'cmd'   => '\\Gamma',
                        'tooltip' => get_string('btn_gamma_func', $p)],
                ],
            ],
            */
            // @codingStandardsIgnoreEnd

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
            // Complete-token functions (#61): max(x,y) and min(x,y) are function calls,
            // while max inside Umax or argmax is just part of the identifier.
            'max', 'min',
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
        $keys = ['aria_add_line', 'aria_add_row', 'aria_formula_input', 'aria_remove_row', 'aria_remove_step'];
        $strings = [];
        foreach ($keys as $key) {
            $strings[$key] = get_string($key, 'local_stackmatheditor');
        }
        return $strings;
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
