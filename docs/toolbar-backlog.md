# Toolbar backlog

Operations that are deliberately **not** offered in the toolbar, with the reason, moved out of
`classes/definitions.php` for 1.3.0 (#34). A visible button is a verified mathematical contract;
everything here lacks one, and none of it is shipped, commented out or otherwise.

To bring one back: add it to `definitions.php`, regenerate the button fixture
(`tests/jest/export_buttons.php`), and give its group a scenario in
`tests/behat/cas_contract.feature`. `cas_contract_test.php` fails until all three are done.

## Number sets (ℕ, ℤ, ℚ, ℝ, ℂ) in the set theory group

**Why not shipped:** Deferred until MathQuill's write support for `\mathbb{}` is verified in the fork, and until a STACK representation is agreed - STACK has no single canonical name for these sets.

```php
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
```

## Quantifiers (∀, ∃, ∄) in the logic group

**Why not shipped:** STACK 4.13 knows neither `forall`, `exists` nor `nexists`, so every answer written with these buttons would be invalid.

```php
                    // Quantifiers: deferred - STACK 4.13 knows neither forall, exists nor
                    // nexists, so every answer written with these buttons would be invalid.
                    ['display' => '∀', 'cmd' => '\\forall',
                        'tooltip' => get_string('btn_forall', $p)],
                    ['display' => '∃', 'cmd' => '\\exists',
                        'tooltip' => get_string('btn_exists', $p)],
                    ['display' => '∄', 'cmd' => '\\nexists',
                        'tooltip' => get_string('btn_nexists', $p)],
```

## Physical constants (`constants_nature`)

**Why not shipped:** No agreed CAS representation: a speed of light or a Planck constant is a question-specific value with units, not a symbol STACK understands on its own.

```php
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
```

## Hyperbolic functions and further calculus operators (`hyperbolic`, `analysis_operators`)

**Why not shipped:** Not verified against STACK/Maxima in both directions; the hyperbolic functions additionally collide with typed text such as `sinh` being read as `s·i·n·h` in some insert-stars modes.

```php
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
```

## Integral calculus extras and statistics (`statistical_operators`)

**Why not shipped:** No verified Maxima mapping for the statistical operators, and the integral templates beyond the shipped ones were never given a contract.

```php
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
```
