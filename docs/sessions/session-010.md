# Session 010 – MathQuill fork with matrix support imported

**Branch:** `development`
**Date:** 2026-09-13
**Plugin version:** 2026100700 (release 1.3.0, MATURITY_STABLE)
**Predecessor:** session-009 (#53–#56)

---

## 1. Context

Issue #40 (switching to the desmosinc fork) was rolled back in an earlier session because that
fork has no matrices either. The matrices have now been built in our own fork:

    https://github.com/ralferlebach/mathquill  branch feature/matrix-environments
    base: mathquill/mathquill main @ bb9974ab

The fork adds the six LaTeX matrix environments as editable structures, the public API
`insertMatrix` / `insertColumnVector` / `insertRowVector`, and browser test automation for the
MathQuill suites themselves. Verified there: `make`, `make lint`, `prettier --check` green,
811 Mocha tests green, 29 Playwright tests green in Chromium, Firefox and WebKit each.

## 2. What this iteration changes

Only the vendored library and its metadata. No plugin source file is touched, so `amd/build`
stays byte-identical and the grunt gate is unaffected.

- `thirdparty/mathquill/`: `mathquill.js`, `mathquill.min.js`, `mathquill.css` replaced with the
  fork build; `font/` replaced by `fonts/` (the fork's build emits the plural name and the CSS
  references it relatively). `README.md` placeholder removed.
- The basic build and the Symbola-basic fonts are not imported — `mathquill.css` does not
  reference them.
- `thirdpartylibs.xml`: version `0.10.1-sme.1`, repository is now the fork, `customised` is now
  `true`. The previous declaration claimed unmodified upstream code, which would have been wrong
  the moment the fork build landed.
- `thirdparty/readme_moodle.txt`: rewritten. Records the base commit, the fork, the build steps,
  the `-sme.N` scheme and the MPL-2.0 consequence of shipping a modified build.
- `db/removed_files.txt`: `thirdparty/mathquill/font` and the old placeholder README.
- `README.md`: third-party section names the fork.
- `version.php`: 2026091300.

## 3. Why the library swap is a smaller risk than it looks

The plugin's coupling to MathQuill's DOM is thin. Across `amd/src`, `styles.css`, Behat and
Playwright, only five MathQuill classes are used: `mq-editable-field`, `mq-container`,
`mq-root-block`, `mq-empty`, `mq-math-mode`. All exist unchanged in the fork build.

The API surface is equally small: `MQ.MathField(el, {spaceBehavesLikeTab, handlers})`,
`mq.latex()`, `mq.focus()`, `field.el()`. `getInterface(2)` still requires `window.jQuery`, which
`mathquill_init.js` already sets before loading.

What did change upstream since the 2017 release is the rendering: brackets are SVG now, not
scaled spans, and there is a spacing-bug feature detection that adds an `mq-has-spacing-bug`
class to the root block. Nothing in the plugin selects on either, but this is exactly what the
verification below has to prove.

## 4. Verification — NOT yet run

This iteration was prepared without a Moodle tree. Before it is merged, the following must run
locally and in CI:

- `make check` (PHPCS, PHPDoc, Mustache, Gherkin, PHPCPD, ESLint, AMD build, Jest, PHPUnit)
- Behat, including the CAS scenarios (`make behat-stack` first)
- Playwright: the settings page and, above all, the delivered assets over `requirejs.php` —
  a wrong font path shows up in the network panel, not in the DOM
- A manual look at a STACK input in a quiz attempt: rendering, cursor, `\,` handling (the old
  0.10.1 could not parse `\,`; the converter workaround may now be unnecessary — check before
  removing it)

Expected side effects to watch for: the extra root-block class, SVG brackets in axe runs, and
`mathquill.js` growing from 173 KB to 490 KB (the minified file, which the injector prefers, is
166 KB and thus close to the old unminified size).

## 5. Iteration 2 (2026091301): conversion rules and toolbar

### tex2max

`convertMatrixEnvironments()` converts all six environments to Maxima's `matrix()`. It runs
before the `\left` / `\right` and brace passes, matches the innermost environment first (so a
matrix inside a cell works) and leaves cell contents to the rest of the pipeline.

    \begin{bmatrix}a&b\\c&d\end{bmatrix}   ->  matrix([a,b],[c,d])
    \begin{pmatrix}x\\y\\z\end{pmatrix}    ->  matrix([x],[y],[z])
    \begin{bmatrix}\frac{1}{2}&\sqrt{x}\end{bmatrix}  ->  matrix([(1)/(2),sqrt(x)])

An empty cell is not padded with a zero — that would silently change the answer. It is reported
through the problem channel introduced for #44: `analyse()` returns an empty `maxima` and the
code `matrix_cell_empty`, which `local_validation.js` renders using the new language string. A
short row is padded structurally and reported the same way.

### max2tex

`extractMatrixCalls()` follows the integral pattern: the matrix goes into the placeholder store
so that the row and column separators survive the later passes, and every cell is converted on
its own. `matrix(a,b)` without row lists and ragged matrices are left untouched rather than
rendered as a structure they do not have.

The write-back environment is `pmatrix` (`MATRIX_ENVIRONMENT` in max2tex.js) and the toolbar
inserts the same. This keeps the pre-fill loop visually stable: a reloaded attempt looks like
what the student typed. Inserting bmatrix and writing back pmatrix would change the brackets on
every save.

### Toolbar

`toolbar.js` gains the action `matrix`, which calls MathQuill's `insertMatrix()`. A matrix is a
structure with its own API, not a LaTeX string that could go through `write`. The
`matrix_operators` group in `definitions.php` gains four buttons: 2x2, 3x3, row vector (1x3),
column vector (3x1). `definitions_test.php` accepts the new element type and checks that both
dimensions are present and are positive integers.

The Jest definitions fixture is unaffected: it only carries the conversion-relevant keys, not
`elementGroups`.

### Verification of iteration 2

- Jest: 744 tests green (711 before, 33 new). No regression.
- ESLint over `amd/src` via grunt: green.
- `grunt amd` in a real Moodle 4.5 tree: `amd/build` rebuilt for max2tex, tex2max and toolbar,
  including the source maps. They are part of this delivery.
- PHPCS, Moodle standard, errors *and* warnings, run inside the Moodle tree over `classes`,
  `lang`, `tests/unit` and `version.php`: green.
- Still outstanding: PHPUnit (needs a database), Behat, Playwright and a manual look at a real
  STACK input.

## 6. Iteration 3 (2026091302): delimiters carry meaning, and a setting for vectors

Ralf's specification, implemented as given:

| LaTeX | Maxima |
| --- | --- |
| `\begin{bmatrix}` (toolbar: matrices) | `matrix([a,b],[c,d])` |
| `\begin{pmatrix}` (toolbar: vectors) | `matrix([x],[y])` |
| `\begin{vmatrix}` or `\det` + any environment | `determinant(matrix(...))` |
| `\begin{Vmatrix}` | `norm(matrix(...))` |
| `\begin{Vmatrix}\begin{pmatrix}…` | `norm(matrix([x],[y]))` |

Write-back: matrices as `bmatrix`, vectors (1×n, n×1) as `pmatrix`, `determinant()` as `vmatrix`,
the norm function as `Vmatrix` — with the inner `pmatrix` kept for vectors and dropped for
matrices, because there the norm bars replace the brackets.

`det`, `determinant`, `norm` and `transpose` were added to the function names, so implicit
multiplication no longer splits them into single variables.

### Two settings

`vectorformat` (matrix | list, default matrix) decides whether a one-row or one-column matrix is
written as `matrix([a,b,c])` or as the list `[a,b,c]`. This is an answer-test question, not a
display question: `ATAlgEquiv` fails when a list meets a matrix. In list mode `max2tex` draws a
list as a row vector — a list has no orientation, so a column vector comes back as a row. The
setting description says so.

`normfunction` (default `norm`) is the Maxima function a norm is written to. Maxima has no norm
that covers vectors and matrices alike, so the name belongs to the question author, who defines
it in the question variables (`norm(v) := sqrt(v . v)`) and enters the same name here. Nothing
is invented behind the author's back.

### Verification of iteration 3

- Jest: 753 tests green (744 before).
- ESLint via grunt: green after one trailing-space fix; `amd/build` rebuilt and included.
- PHPCS, Moodle standard, errors and warnings, over `classes`, `lang`, `tests/unit`,
  `settings.php`, `version.php`: green.
- The Jest definitions fixture was updated for the new function names; it does not carry
  `elementGroups`, so the toolbar change does not affect it.

### Open question

`vmatrix` now means determinant. A student who wants to write "the matrix with bars" no longer
can — that is the point of the specification, but it is worth a look during the first real
question test.

## 7. Iteration 4 (2026091303): the two Behat failures from the CI run

The dev run was green everywhere except Behat: 45 of 47 scenarios passed.

### `sqrt((p^(2))/(4-q))` instead of `sqrt((p^2)/(4-q))`

A direct consequence of the MathQuill upgrade, not of the matrix work. The 2017 release wrote a
squared term as `p^2`; the current build normalises it to `p^{2}`, and tex2max turned every
`^{…}` into `^(…)`. Every squared term in every answer was therefore reaching the CAS with an
extra pair of parentheses.

The superscript rule now drops the parentheses when the exponent is a single digit group or a
single letter, and keeps them otherwise:

    p^{2}   -> p^2         x^{n+1} -> x^(n+1)
    x^{10}  -> x^10        x^{ab}  -> x^(ab)
    e^{x}   -> e^x         x^{-1}  -> x^(-1)

`x^{ab}` deliberately keeps its parentheses: `x^ab` would be split into `x^a*b` by implicit
multiplication. Seven cases were added to `conversion.test.js`.

### The capability scenario could never pass

`configure.php` answers a request without `mod/quiz:manage` with `required_capability_exception`,
which is correct and is what the scenario wanted to prove. But Moodle renders that as an
exception page, and Behat's after-step hook fails *any* step that ends on one — so the
`When I am on the … configuration page` step failed before the `Then` could assert anything. The
scenario was unrunnable as written, independently of this branch.

The check now lives in a step of its own, `… with question "nonexistent" is denied to me`: it
visits the page, asserts the permission message, asserts that "Cannot resolve the question" is
*not* there (the actual point of #54 — the capability gate comes before question resolution) and
then navigates away, so the after-step hook sees a clean page.

### Verification of iteration 4

- Jest: 760 green (753 before).
- `grunt amd`: rebuilt, included.
- PHPCS over `classes`, `lang`, `tests`, `settings.php`, `configure.php`, `version.php`: green.
- Behat itself could not be run here (no database). The gherkin change is one line; the step is
  new code and is the thing to watch in the next run.

## 8. Next steps

1. Behat for the matrix path: insert through the toolbar, fill, save, reload and check that the
   pre-filled answer is the same matrix.
2. Decide whether the `\,` workaround in the converter can go now that MathQuill is current.
3. Consider whether a 1xn matrix should convert to a Maxima list instead. It currently does not:
   `matrix([a,b,c])` keeps the matrix semantics, which is the honest reading of what the editor
   shows.


## 9. Iteration 5 (2026091304): #58, #59, #60 — identifier integrity

Applied to 1.3.0 and, identically, to 1.2.0 (released as 1.2.1, version 2026091300).

### #58 / #60 — an operator name inside a longer identifier

`Umax` reached STACK as `U max`. The cause is not the reserved-words logic — in the "leave
untouched" mode that code does not even run, as #60 established. MathQuill un-italicises an
operator name wherever it finds one inside a run of letters, so typing `Umax` yields the LaTeX
`U\max `. `markControlWords()` then put a token boundary in front of `\max`, and
`resolveBoundaries()` turned that boundary into a space, because it separates an identifier
from a following word. That rule is right for `a\sqrt{b}` (#39) and wrong here.

`mergeGluedOperatorNames()` now runs before `markControlWords()` and re-joins such a name with
the identifier it belongs to. The deciding question is whether the name is applied to anything:

    U\max                  -> Umax          one identifier (#58)
    \max imum              -> maximum       one identifier
    a\sin\left(x\right)     -> a sin(x)      unchanged, a times sin of x
    \max\left(a,b\right)    -> max(a,b)      unchanged, the function max
    a\sqrt{b}              -> a sqrt(b)     unchanged, \sqrt is not an operator name

The name list mirrors MathQuill's own defaults and is built the same way MathQuill builds it,
so it can be compared against the library when the configuration changes.

### #59 — multi-character subscripts

`max2tex` grouped only the first character after `_`, so `U_max` was drawn as `U_{m}ax`. It now
groups the whole alphanumeric suffix: `U_{max}`, `x_{12}`, `T_{amb}`.

`tex2max` collapsed `U_{m}ax` into `U_max`, which is a different expression and made the two
forms indistinguishable. A subscript group that is followed directly by more characters now
keeps a boundary marker, so the existing variable-mode logic decides what happens at that
boundary:

| LaTeX     | stack     | explicit_single | explicit_multi |
| --------- | --------- | --------------- | -------------- |
| `U_{max}` | `U_max`   | `U_max`         | `U_max`        |
| `U_{m}ax` | `U_m ax`  | `U_m*a*x`       | `U_m*ax`       |

The roundtrip `U_max -> U_{max} -> U_max` is stable and covered by tests.

### Verification

- Jest: 809 green in 1.3.0 (760 before), 760 green in 1.2.1 (711 before). The 49 new cases are
  in `tests/jest/identifiers.test.js`.
- `grunt amd` in a Moodle 4.5 tree: `tex2max` and `max2tex` rebuilt in both versions.
- PHPCS, Moodle standard: green.
- New Behat scenarios in `tests/behat/tex2max_conversion.feature` use the existing
  `tex2max output for latex ... in variableMode ...` step; every expected value in them was
  checked against the real converter, but Behat itself was not run (no database here).

### Not done deliberately

No language strings were touched, and MathQuill itself was not changed. The fix is in the
converter, which is what both versions share; 1.2.x ships MathQuill 0.10.1 and could not take a
library change anyway. The editor still *displays* `U max` in roman type while typing — the
value handed to STACK is now correct, the display is a MathQuill matter and belongs in the fork.


## 10. Iteration 6 (2026091305): #61 checked against the fixed code

#61 is the technical cross-check of #58 and comes with a full acceptance matrix. Running it
against the code from iteration 5 showed that the identifier half was already satisfied and
that one row was not: `max(x,y)`.

`max` was in no function list of the plugin — neither in `BUILTIN_FUNCTION_NAMES` in
`tex2max.js` nor in `definitions::get_function_names()`. In the multi-character modes that
produced `max*(x,y)`, in single-character mode `m*a*x*(x,y)`. `min` fared slightly better
because it is protected as a word elsewhere, but it still collected a star: `min*(x,y)`.
`max` and `min` are now registered as functions in both places, and the Jest fixture follows.

The complete matrix from #61 now holds, in all five modes:

| typed      | stack      | explicit_multi | space_multi | explicit_single | space_single |
| ---------- | ---------- | -------------- | ----------- | --------------- | ------------ |
| `max(x,y)` | `max(x,y)` | `max(x,y)`     | `max(x,y)`  | `max(x,y)`      | `max(x,y)`   |
| `Umax`     | `Umax`     | `Umax`         | `Umax`      | `U*m*a*x`       | `U m a x`    |
| `maxU`     | `maxU`     | `maxU`         | `maxU`      | `m*a*x*U`       | `m a x U`    |
| `argmax`   | `argmax`   | `argmax`       | `argmax`    | `a*r*g*m*a*x`   | `a r g m a x`|
| `maximum`  | `maximum`  | `maximum`      | `maximum`   | `m*a*x*i*m*u*m` | `m a x i m u m` |

Jest: 822 green in 1.3.0, 773 in 1.2.1. The matrix is a test table, not prose.

### Where this implementation differs from the issue's recommendation

#61 advises against repairing `U\max ` back into `Umax` afterwards, because control-word
boundaries could be damaged. The repair is what iteration 5 does, deliberately: it is the only
fix that works for 1.2.x as well, which ships MathQuill 0.10.1 and cannot take a library
change. The risk the issue names is contained by construction — only names from MathQuill's own
auto-operator list are merged, and only where the name is not applied to an argument, so
`\sqrt`, `\frac`, `\pi` and every other control word are untouched (covered by tests).

What the repair cannot fix is the display: while typing, `Umax` still shows "max" in roman
type. That is MathQuill's doing and belongs in the fork, where `autoUnItalicize()` should only
accept an operator name that spans the entire run of letters.


## 11. Iteration 7 (2026091306): what typing actually produces

The matrix in #61 lists typed words, not LaTeX. Everything so far had been tested against the
LaTeX I assumed MathQuill produces. Typing the words into a real MathQuill field in a browser
and reading `field.latex()` back confirmed the assumption for ten of eleven rows — and found one
case nobody had written down:

    typed "U_max"  ->  U_{\max}

The operator-name substitution also fires inside a subscript. The converter turned that into
`U_ max` in stack mode: the boundary marker in front of `\max` survived the subscript
replacement, and `resolveBoundaries()` made a space out of it, because the text in front,
`U_`, looks like an identifier. `U_ max` is not a valid CAS string.

Two changes:

* `mergeGluedOperatorNames()` also treats an operator name that fills a `{...}` group on its own
  as a label rather than a function, so `U_{\max}` becomes `U_max` in every mode.
* Every `MQ.MathField()` call now passes `disableAutoSubstitutionInSubscripts: true`, so the
  substitution no longer happens in the first place: typing `U_max` yields `U_{max}`. Verified
  in a browser. MathQuill 0.10.1 does not have the option and ignores it, which is why 1.2.1
  needs the converter change and gets it.

The captured browser output is now a test table in `identifiers.test.js` — the LaTeX column is
measured, not assumed.

Jest: 833 green in 1.3.0, 784 in 1.2.1.


## 12. Iteration 8 (2026091307): the display follows the value

Until now the converter repaired what MathQuill had already split: the value handed to STACK was
right, but while typing, "Umax" still showed "max" in roman type. That part cannot be fixed in
the plugin — it happens in `autoUnItalicize()`, which un-italicises an operator name wherever it
occurs inside a run of letters.

The fork now has an option for it, `autoOperatorNamesOnlyWholeWord` (default false, so the
library's own tests and any other user of the fork are unaffected). A name is recognised only
when it covers the whole run:

| typed      | option off             | option on              |
| ---------- | ---------------------- | ---------------------- |
| `max`      | `\max`                 | `\max`                 |
| `max(x,y)` | `\max\left(x,y\right)`  | `\max\left(x,y\right)`  |
| `Umax`     | `U\max`                | `Umax`                 |
| `argmax`   | `\arg\max`             | `argmax`               |
| `maximum`  | `\max imum`            | `maximum`              |
| `sinvalue` | `\sin value`           | `sinvalue`             |
| `U_max`    | `U_{\max}`             | `U_{max}`              |

This iteration imports the fork build that carries the option (`0.10.1-sme.2`) and switches it
on at all three `MQ.MathField()` call sites, next to
`disableAutoSubstitutionInSubscripts`. Measured against the vendored file in a browser, with
exactly the options the plugin passes — the table above is that measurement.

The converter merge from iteration 5 stays. It is now a safety net rather than the main fix:
answers stored before this version still contain `U\max`, and a field configured elsewhere
without the option still produces it.

1.2.1 keeps the converter-only solution. MathQuill 0.10.1 has neither option and ignores both.

### Verification

- Typed behaviour measured in a browser against `thirdparty/mathquill/mathquill.min.js`
- Jest 833 green, PHPCS green, `amd/build` rebuilt for `input_fields` and `textarea_fields`
- `thirdpartylibs.xml` and `thirdparty/readme_moodle.txt` record `0.10.1-sme.2` and what
  changed in it

### Still open

Behat and Playwright have not run here (no database). The library swap touches the editor in
every quiz attempt, so the full suite matters more than usual before this leaves `development`.


## 13. Iteration 9 (2026091308): #62 — matrix and vector input from the toolbar

The four fixed buttons from iteration 3 are gone. In their place the matrix group has two
buttons that open a chooser:

    [⋮] ▾    grid, 5 × 5, highlights the block from (1,1) to the cell under the cursor
    (a⋮b) ▾  dimension and orientation for a vector

### Structured model instead of a LaTeX string

The issue is explicit that complex structures must not be assembled as LaTeX and written into
the field. Three new modules:

* `structured_input.js` — the model. `createMatrix(rows, columns)`,
  `createVector(dimension, orientation)`, validation, the shared size limit (10, quick pick 5),
  and `toLatex()` for the cases where MathQuill has to be handed LaTeX.
* `structured_serializer.js` — model ↔ Maxima. Parsing walks the string with a bracket counter,
  not a global regular expression, because cells contain commas, brackets and nested matrices.
* `structured_popup.js` — the two choosers. Keyboard first: arrows move, Enter confirms, Escape
  closes and returns the focus to the button. The chosen size is written out as text
  (`3 × 2 matrix`) in an `aria-live` region, so it is not carried by colour alone.

`toolbar.js` gained the action `popup` and hands the model to MathQuill's structure API
(`insertMatrix` / `insertRowVector` / `insertColumnVector`). It never builds a LaTeX string.

### A vector is not a matrix

The model keeps the distinction: `{type: 'vector', orientation, elements}` against
`{type: 'matrix', rows}`. The serializer decides once what that means for the CAS —
`matrix([a],[b],[c])` for a column vector, or a list when `vectorformat` is set to `list`.
Reverse loading reads a single row or a single column back as a vector, which is what the
editor offered in the first place.

### Reverse loading and errors

`fromMaxima()` returns null for anything that is not one of these structures —
`matrix(a,b)` with list-valued variables, a ragged matrix, an unbalanced bracket. The caller
keeps the generic editor behaviour rather than discarding the answer.

### Verification

- 33 new Jest cases in `structured_input.test.js`: model, serializer, reverse loading,
  roundtrip for the four fixtures from the issue, and the popup under jsdom — arrow keys, the
  selection staying inside the grid, click, Enter, Escape, focus return, ARIA roles, language
  pack labels. Suite total 866 green.
- ESLint and the AMD build via grunt in a Moodle 4.5 tree, PHPCS green.

### Not covered by this iteration

Changing the dimensions of an existing matrix still happens through the keyboard
(`Shift-Enter`, `Shift-Spacebar`, Backspace on an empty row or column) from iteration 3, not
through a context menu. §3 of the issue allows either; a menu would be the nicer UI and is the
obvious follow-up.

Behat and Playwright have not run here. The popup is tested under jsdom, which covers the
logic but not the layout, the focus ring or the placement below the button.


## 14. Iteration 10 (2026091309): #64 — the typed space is a boundary

STACK has "insert stars" variants that read spaces: with them, `a b` and `ab` are different
inputs. The editor could not produce that difference at all, because every field was created
with `spaceBehavesLikeTab: true` — the space key navigated instead of typing. And even if it
had, `tex2max` deleted the control space with `s.replace(/\\ /g, '')`.

Both are fixed, and the editor still does not interpret the space; it passes it on.

| typed     | field.latex() | CAS string |
| --------- | ------------- | ---------- |
| `a b`     | `a\ b`        | `a b`      |
| `2 x`     | `2\ x`        | `2 x`      |
| `x y z`   | `x\ y\ z`     | `x y z`    |
| `a b+c d` | `a\ b+c\ d`   | `a b+c d`  |
| `U max`   | `U\ \max`     | `U max`    |
| `ab`      | `ab`          | `ab`       |

The LaTeX column is measured in a browser against the vendored build, with the options the
plugin passes. Tab and Shift-Tab still leave a block — checked in the same run by typing into a
fraction denominator and tabbing out.

### How the space survives

`tex2max` turns the control space into its own marker, distinct from the token boundary the
converter uses internally: a boundary may disappear, user input may not. The marker becomes a
plain space at the very end, after every pass that could have swallowed it. Typographic spacing
(`\,`, `\;`) still carries no meaning and is still dropped.

`max2tex` goes the other way, but only for a space between two operands, and only after the
keyword passes have run: before that, a space also separates `x in A` or `p and q`, and
protecting those spaces kept the keyword rules from matching. A space that belongs to a control
word (`\in A`) is left alone as well. Both mistakes were caught by the existing suite, which is
what it is for.

### Visibility

The fork got a class for the typed space (`mq-space`, build `0.10.1-sme.3`) — MathQuill renders
it as an unclassed span, which gives no stable styling hook. `styles.css` widens it slightly. No
symbol is drawn: a middle dot would mean explicit multiplication, which is a different
expression again.

### Verification

- 20 new Jest cases in `spaces.test.js`, suite total 886 green
- Typing, the class and Tab navigation measured in Chromium against
  `thirdparty/mathquill/mathquill.min.js`
- ESLint and the AMD build through grunt, PHPCS green

### What this changes for users

Space no longer jumps out of a fraction or a subscript. That was a convenience; it is now
Tab, Shift-Tab or the arrow keys. The trade is deliberate: without it, a whole family of STACK
input configurations cannot be served at all.


## 15. Iteration 11 (2026091310): #65 — STACK owns the input semantics

The editor had its own setting for implicit multiplication next to STACK's. Two settings for one
question, and the second one could contradict the first. It is gone.

* `settings.php`: the admin default `variablemode` is removed.
* `configure_form.php`: the select is replaced by a read-only section.
* `config_manager::get_instance_variable_mode()` always returns the STACK mode. Values stored by
  earlier versions are ignored rather than migrated — handing the decision back to STACK must not
  depend on an upgrade step having run.
* The four language strings of the removed setting are deleted.

### What is shown instead

`classes/stack_inputs.php` reads `qtype_stack_inputs.insertstars` — per input, because one
question can have several inputs with different settings, and the global
`qtype_stack/inputinsertstars` is only the default for new ones. The configuration page lists:

    STACK input semantics
    ans1    Don't insert stars
    ans2    Insert stars for implied multiplication only
    ans3    Insert stars assuming single-character variables, implied and for spaces

    [Edit STACK input settings]

The labels come from `qtype_stack`'s own language strings (the value map mirrors
`stack_options::get_insert_star_options()`), so a teacher reads the same words as in the
question editor, in their language. A value this plugin does not know is shown as a number
rather than guessed at.

The link appears only with the question edit capability; without it the values are still shown,
because knowing the semantics is useful even when you may not change it. The editor never writes
`qtype_stack_inputs`.

### Verification

- New `tests/unit/stack_inputs_test.php`: several inputs with different values, no inputs, the
  labels against `qtype_stack`, an unknown value, and no link without a question. The tests skip
  themselves where `qtype_stack` is not installed instead of failing.
- `config_manager_test.php` now asserts the invariant instead of the removed setting: whatever
  `variablemode` holds, the mode is STACK's.
- PHPCS green, Jest unchanged at 886.

### Interaction with #64

#64 made a typed space reach STACK unchanged. This iteration is what makes that decision
readable: a teacher configuring the toolbar now sees whether the input actually reads spaces
(`Insert stars for spaces only` and friends) — without the editor interpreting anything.

### Still open

PHPUnit did not run here (no database), so the new tests are unexecuted. The Behat and
Playwright scenarios from the issue — two inputs with different values, change in STACK, reload —
need a live instance.


## 16. Iteration 12 (2026091311): the ESLint complexity gate

The CI run after iteration 11 was green everywhere except JS/CSS:

    137:5  warning  Function 'fromMaxima' has a complexity of 25. Maximum allowed is 20
    Warning: ESLint found too many warnings (maximum: 0)

`fromMaxima()` from #62 did three jobs at once: recognise a list, read the rows of a
`matrix(...)` call, and decide which model the rows describe. It is now three functions —
`listToModel()`, `matrixCallRows()`, `rowsToModel()` — with `fromMaxima()` as four lines on top.
Behaviour is unchanged; the 33 tests of the module pass as before.

### Why this slipped through

The AMD rebuild here runs `grunt amd --force`, copied from the plugin's makefile, and `--force`
turns an ESLint failure into a printed warning that the exit code no longer reflects. The CI
runs `moodle-plugin-ci grunt --max-lint-warnings 0`, which does not forgive. Since this
iteration the local rebuild runs without `--force`, so the two agree.


## 17. Iteration 13 (2026091312): #45 — differential operators

The three buttons that existed wrote `\mathrm{grad}\,` and friends. What reached the CAS was
`gradf` — one identifier, silently wrong. `rot` would have gone to Maxima as `rot`, which is a
German label, not a function.

### The mapping is a setting, not an assumption

Maxima has no gradient, divergence or curl that works everywhere: they live in the `vect`
package, need `express()` and depend on the coordinate system, and many STACK questions define
their own. Which of these a given site has cannot be decided in this plugin, so each operator
gets a site setting holding the Maxima function name:

    diffopgradient      diffopdivergence      diffopcurl      diffoplaplacian

An empty setting means the operator does not exist here, and then **the button is not offered
at all**. If such an operator still turns up in an answer — an old one, or an import — the
converter reports `diffop_unavailable` through the problem channel and hands STACK nothing,
rather than an expression the CAS would reject.

### Label and CAS name are separate levels

| button | LaTeX | CAS (example configuration) |
| ------ | ----- | --------------------------- |
| grad   | `\operatorname{grad}(...)` | `grad(...)` |
| div    | `\operatorname{div}(...)`  | `div(...)`  |
| rot    | `\operatorname{rot}(...)`  | `curl(...)` |
| Δ      | `\Delta(...)`              | `laplacian(...)` |

A site whose curl is called `curl` still shows `rot` to a German-speaking student, and back the
other way the label returns. The operand is part of the template: the button writes the brackets
and puts the cursor inside them.

### Δ and ∇

`\Delta` is the Laplace operator only when a bracket follows it. A bare `Δ`, `Δx` or `2Δ` stays
the Greek letter — that distinction is what the issue asks for, and it is covered by tests.
`\nabla` has no button: a free nabla without an operand is notation, not an operation. It still
converts to the identifier `nabla` so that no backslash reaches the CAS (#39).

An operator without a bracketed operand — what the old buttons produced — is reported as
`diffop_operand_missing`. `grad f` is not a CAS call, and guessing where the operand ends would
be inventing semantics.

### Verification

- 33 new Jest cases: the four operators configured and unconfigured, nested and multi-variable
  operands, an operator on a vector, a site-specific name, the negative cases (free ∇, free Δ,
  `rot` without operand, `div` without vector) and the roundtrip. Suite total 919 green.
- ESLint via grunt **without `--force`** (see iteration 12), PHPCS green — which caught a
  duplicated language key before it reached CI.

### What is still unverified

The wording of the issue is "verified STACK/Maxima mapping". What this iteration provides is the
*mechanism* for a verified mapping plus the refusal to guess one. Whether `grad` exists on your
installation, whether it needs `express()`, and in which coordinate system it works, has to be
tried in a STACK question — I cannot test that here. Until an admin enters a name, the operators
stay invisible, which is the state the issue asks for.


## 18. Iteration 14 (2026091313): #50 — the editor follows STACK, not the module name

Two decisions were mixed up in one list of module names. They are separate now.

**Runtime.** Whether an editor appears is decided in the browser, against the rendered DOM: a
`qtype_stack` input that is editable gets one. The page type only decides whether the bootstrap
is loaded at all, and that list is no longer a constant in the hook class but
`context_resolver::supports_page()`, which knows the previous pages plus `mod-capquiz-*`,
`mod-studentquiz-view` and `filter-embedquestion-showquestion` — and reads further ones from a
site setting, so a new question-engine consumer needs no code change. On a page without a STACK
input, the cost is one module load.

**Configuration.** Resolving a question-bank entry, a capability and a return URL stays module
specific, and the plugin only claims it for quiz and adaptive quiz. Everywhere else the editor
runs with the site defaults instead of not running at all - which is what §5 of the issue asks
for. `lib.php` asks `has_configuration_ui()` for the settings link rather than comparing module
names.

### Two smaller things that belong to the same issue

A read-only or disabled input no longer gets an editor. It never should have: next to a
teacher-rendered answer, a MathQuill field suggests the answer could still be changed.

Questions rendered after page load — CAPQuiz and StudentQuiz replace them over AJAX — are picked
up by a `MutationObserver`, throttled to one pass per burst. The pass is idempotent through the
`data-sme-init` marker that was already there, so no input ever gets two editors.

### Verification

- New `context_resolver_test.php`: the old contexts keep working, the new ones are supported,
  unrelated pages are not, the admin setting is honoured and validated (`*`, `<script>`,
  `../etc/passwd` are ignored), and runtime and configuration are asserted to be separate
  decisions - CAPQuiz gets the editor and no configuration page.
- ESLint via grunt without `--force`, PHPCS over the whole plugin: green.
- README: the sentence about mod_quiz and mod_adaptivequiz is replaced by a matrix.

### What cannot be verified here

Everything that needs a live instance: CAPQuiz, StudentQuiz and `filter_embedquestion` with a
real STACK question, the AJAX question change, and whether the editor initialises inside the
embed iframe. The issue asks for that explicitly, and the page types are best guesses from the
respective module's URL structure - `mod-capquiz-view` may well be wrong for the version you
have. If it is, the *Additional page types* setting fixes it without a release, which is one
reason that setting exists.


## 19. Iteration 15 (2026091314): #63 — elementary geometry

School notation on the surface, STACK's `geometry.mac` underneath. The existing geometry group
in the toolbar gains five structured entries; the symbols it already had stay.

| button | editor | STACK |
| ------ | ------ | ----- |
| P(x\|y)   | `P\left(2\|3\right)` | `[2,3]` |
| P(x\|y\|z) | `P\left(2\|3\|4\right)` | `[2,3,4]` |
| d(A,B)   | `d\left(A,B\right)` | `Distance(A,B)` |
| ∠ABC     | `\angle ABC` | `Angle(A,B,C)` — B is the vertex |
| \|AB\|     | `\left\|\overline{AB}\right\|` | `Distance(A,B)` |

The point name is a label: it does not reach the CAS. The coordinate separator is a setting
(`|`, `;`, `,`) and changes notation only — STACK receives a list either way.

### What is deliberately not in this iteration

Segment, ray, line, circle and sphere have no STACK type. The issue says as much and forbids
inventing one, and it leaves the representation open ("a documented parameter representation").
Which one is right depends on what the questions compare against — a pair of points, a circle
equation, two separate values — and that is a decision for the question author, not for me. The
buttons are therefore not shipped. The same reasoning as for the differential operators in #45.

### Reverse loading and a collision worth knowing about

`Distance(A,B)` and `Angle(A,B,C)` are unambiguous and always come back as `d(A,B)` and `∠ABC`.
A bare list is not: `[2,3]` is a point in a geometry question, a vector when the vector format is
set to `list` (#62), and an ordinary list elsewhere. The editor cannot tell from the string, so
drawing lists as points is a setting and it is **off by default**. With it on, `[2,3]` shows as
`P(2|3)` and the roundtrip is stable.

### Verification

- 31 new Jest cases: points in 2D, 3D and all three separators, the name not reaching the CAS,
  ordinary brackets and function calls staying what they are, distance in both directions, the
  vertex of an angle, no degree conversion, `cos(∠ABC)` as the combination the issue describes,
  the model itself, and regression against the vector, matrix and trigonometry rubrics. Suite
  total 950 green.
- ESLint via grunt without `--force`, PHPCS over the whole plugin: green.

### Still open

`geometry.mac` has to be loaded for `Distance` and `Angle` to exist in a question. Whether that
happens automatically in your STACK version, and whether the GeoGebra binding passes points in
exactly this form, needs a real question — as with #45, the editor produces the documented call
and does not check the CAS.


## 20. Iteration 16 (2026091315): the CI run after #63

Two jobs red, both from iteration 14 and 15, both from the same kind of gap between what I run
here and what the CI runs.

### PHPUnit: a debugging message counts as a failure

`stack_inputs::get_edit_url()` asked `question_has_capability_on()` about a question id that does
not exist. Moodle answers that with a `debugging()` message, and in a PHPUnit run an unexpected
debugging message fails the test — two of them, on all three Moodle versions.

The fix is better production behaviour as well: the question is looked up first, and a missing
one means no link, without troubling the question engine. The test now asserts
`assertDebuggingNotCalled()`, so the message cannot come back unnoticed.

This was the first CI run in which the tests from #65 could fail at all. They ran green in the
previous run because iteration 11 had not reached that runner yet.

### ESLint: warnings, not errors

    max2tex.js  1204:50  Unnecessary escape character: \[     no-useless-escape
    input_fields.js 449  'initField' has a complexity of 23   complexity

Both are warnings, and my local `grunt amd` tolerates warnings — the CI passes
`--max-lint-warnings 0` to moodle-plugin-ci, which does not. Iteration 12 had already drawn the
conclusion to drop `--force`; that was not enough, because `--force` and the warning threshold
are two different things. The check that matches the CI is:

    npx eslint --max-warnings 0 local/stackmatheditor/amd/src/*.js

Note that `npx grunt amd --max-lint-warnings 0` is *not* it: grunt reads the 0 as a task name,
reports "Task 0 not found" and exits non-zero for a reason that has nothing to do with the code.
That is what I ran at first, and it looked like a real failure.

The escape in the character class was simply superfluous. The complexity came from the read-only
check that #50 added to `initField()`; the guard clauses now live in `skipField()`, which reads
better anyway.

### Verification

Jest 950 green, PHPCS over the whole plugin green, `eslint --max-warnings 0` green.


## 21. Iteration 17 (2026091316): a green job failed on its log upload

The run after iteration 16 reported `lint-php: failure`, and with it `phpunit: skipped`,
`behat: skipped`, `CI complete: failure`. The lint itself had passed:

    Checked 40 files in 0.1 seconds
    No syntax error found
    job: lint-php
    status: success

What failed was the step after it:

    Uploading artifact: error-summary-dev-lint-php.zip
    Finished uploading artifact content to blob storage!
    Finalizing artifact upload
    ##[error]Failed to FinalizeArtifact: ... (403) Forbidden

Three other jobs in the same run uploaded their summary without trouble, so this is GitHub's
artifact service, not the workflow. No code change would have fixed it, and a re-run probably
would have.

That a transient upload error can take down a job whose checks passed - and skip everything
downstream - is the part that is worth fixing. Every `actions/upload-artifact` step in all five
workflows now carries `continue-on-error: true`. The logs are a convenience for reading a
failure, never the gate.

`ci-complete` itself stays strict: it still demands `success` from every job, and it reported
this correctly.

### Verification

The five workflow files parse and every upload step is guarded (checked mechanically, not by
eye). No plugin code changed in this iteration.


## 22. Iteration 18 (2026091317): #13 — students can put the editor away

A switch above each editor. Off means: the answer goes into the original STACK input as Maxima,
and that input comes back into its normal place, where it can be typed into directly. On means:
whatever is in the input at that moment - including what was typed while the editor was away -
goes back into the editor as LaTeX, and the input returns off screen.

The preference is remembered in `localStorage`, for the site rather than per question: someone
who switches the editor off to see more of a question on a phone wants it off on the next one
too. Blocked storage (private mode) is not an error; the editor then simply starts on.

### A module of its own

`amd/src/editor_toggle.js` owns the switch and the two CSS classes and nothing else. What
"hand over" means is passed in as two callbacks, because only the caller knows whether it drives
one field or something else. The module has no dependencies - no jQuery, no MathQuill - which is
why its behaviour can be tested under jsdom rather than reasoned about.

The original input is parked with a class now instead of inline styles, and it is still parked
off screen rather than hidden: `display:none` would take it out of the accessibility tree and
move STACK's validation feedback.

### Where it is not offered

The relation-system editor (several lines with a brace) has no switch. Coming back from the
plain input would mean rebuilding the rows from the text, and if that failed, the student's
typing would be gone. Better no switch than a switch that loses an answer. The same applies to
the textarea editor, which has its own module.

### Verification

- 8 Jest cases under jsdom: the switch is a labelled `role="switch"` checkbox, switching off
  hands the answer over and reveals the input, switching on reads back what is in the input at
  that moment, the input is never removed from the page, a click drives the same path, the state
  is announced in an `aria-live` region, the choice is remembered but the initial application is
  not a choice, and blocked storage does not break anything. Suite total 958 green.
- ESLint `--max-warnings 0`, stylelint and PHPCS green.
- Not verified here: how it looks and feels in a real quiz attempt. The switch uses the
  Bootstrap classes and brings its own CSS fallback, but the visual check is yours.


## 23. Iteration 19 (2026091318): the switch for the system editor too

The previous iteration left the relation-system editor without a switch, for fear of losing an
answer on the way back. That fear was addressable, and here is how.

**Off**: `syncSystemToInput()` already writes what the lines mean as one nounand-joined answer -
`(a+2*b=5) nounand (2*a+6*b=-2)` - so handing over is exactly the existing function. The plain
input comes back into its place with that value.

**On**: the value is read back. If it is unchanged since the hand-over, the rows stay as they
are, with their cursor position. If it changed - the student typed into the plain input - the
rows are rebuilt from it with `getRelationSystemParts()`. An answer that is no longer a system
(`x=1`) becomes one row instead of nothing: no answer is thrown away, which was the whole
objection.

### One bug found by writing the test

The first application of the switch used to call the "read it back" direction as well. In the
single-line editor that only re-prefilled a field that already held the answer; in the system
editor it threw away rows that had just been built and rebuilt them from the input. Since the
initial application the editor is left alone - it was built from that very value a moment ago.
`apply(on, initial)` now only transfers when it is a real switch.

### Refactoring

`rebuildSystemRows()` and `attachSystemToggle()` are module-level functions, not nested ones:
ESLint refuses a function declaration inside a block, and `initField()` was over the complexity
limit again. Caught by `eslint --max-warnings 0` before the CI saw it, which is what iteration
16 changed.

### Verification

4 further Jest cases: off writes the nounand answer, on without a change keeps the rows, an edit
made while the editor was away is taken over, and an answer that is no longer a system is kept.
Suite total 963 green. ESLint, stylelint, PHPCS green.

Still not offered in the textarea editor, which has its own module and no line structure to
rebuild.


## 24. Iteration 20 (2026091319): the switch built editors around itself

Behat ran for the first time since the library swap, and it was worth the wait: 20 of 61
scenarios failed, all of them with the same root cause, and one that no unit test could have
found.

The DOM in the failure dump:

    <div class="sme-input-wrap"><div class="sme-toggle ...">
      <div class="sme-input-wrap"><div class="sme-toggle ...">
        <div class="sme-input-wrap"><div class="sme-toggle ...">   (and so on, 1430 times)

The editor finds its inputs with, among others, `input[id*="_ans"]`. The switch is an `<input>`
as well, and its id was built from the input it belongs to: `sme-toggle-q1:1_ans1` - which
contains `_ans`. So the switch looked like a STACK answer field, got an editor of its own, that
editor added a switch, and the `MutationObserver` from #50 kept the cycle going. Nothing was
typed into the real field any more, which is why a `latex()` came back as `"on"`: the value of a
checkbox.

Two changes, because one would have been enough and both are cheap:

* The switch ids are now counted (`sme-editor-switch-1`), never derived from the input. A Jest
  case asserts that the id cannot contain `_ans`.
* `skipField()` refuses anything inside an `.sme-input-wrap` and anything that is a checkbox,
  radio, button, submit or file input. A STACK answer is typed, not ticked.

### What this says about the test setup

Jest tests the modules, Behat tests the page. This bug lived exactly between them: every module
behaved correctly, and their combination did not. The `MutationObserver` turned a small mistake
into a runaway loop - a reminder that #50 added a mechanism that reacts to the editor's own DOM
changes.

### Verification

964 Jest cases green, ESLint `--max-warnings 0`, PHPCS green. The CI run also shows PHPUnit
green on 4.5, 5.0 and 5.2, plus JS/CSS, quality, stale files and PHP lint - Behat was the only
red job, and the 41 scenarios that passed did so with the new MathQuill build, the space
handling from #64 and the read-only rule from #50 in place.


## 25. Iteration 21 (2026091320): #66 — a button needs its CAS package too

#34 says a button must have a verified mapping. #66 adds the second condition, and it is the one
that closes the points left open by #45 and #63:

    Visible button = verified mapping AND the package is available in this question.

### The resolver

`classes/dependency_resolver.php` reads `qtype_stack_options.questionvariables` and answers one
question per group: are its packages loaded here? Three requirement types:

* `stack_core` - loaded by `stackmaxima.mac`, always satisfied. `geometry.mac` with `Distance`,
  `Angle` and `Length` is core, so the geometry group from #63 is never marked.
* `stack_contrib` - needs `stack_include_contrib("...");` in the question.
* `maxima_share` - needs `load("...");`.

Detection normalises before it matches: Maxima comments are removed first (a `load("vect")`
inside `/* ... */` is not a load), whitespace is collapsed, and both quote styles are accepted.
`load ( 'vect' )` counts, `myload("vect")` and `load("vectors")` do not.

It is feature detection and nothing else: no CAS code runs, no question variables are written,
no package is loaded. What it cannot read counts as not loaded - never the other way round.

### Where it takes effect

* `definitions.php`: the groups declare their requirements. `vector_differential` needs `vect`;
  `geometry` declares `geometry.mac` as core, so the declaration is complete rather than absent.
* `editor_injector`: a group whose packages are missing is switched off in the configuration
  that reaches the browser - per slot, per question. A student sees no button and no reason.
* `configure_form`: the author sees the group marked with `*`, with the exact line to add. The
  choice can still be saved: configure now, load the package afterwards, and the group appears on
  the next reload. Nothing about availability is persisted - the question variables are the only
  truth.

### Verification

12 new PHPUnit cases: every documented syntax variant, what must not count (comments, a longer
package name, a different function), core without a load, the instruction text per type, several
requirements as an AND, a group without requirements, the declared dependencies of the toolbar
being well formed, and the fail-safe for a question that cannot be read. PHPCS green over the
whole plugin.

### What this changes for #45 and #63

The differential operators now have two gates: an admin has to name the Maxima function
(iteration 13), and the question has to load `vect`. Both are the author's decisions, and
neither is guessed. The geometry group needs no action - `geometry.mac` is core.

### Still open

Behat for the dynamic case - add the line, reload, group appears; remove it, group disappears -
needs a live instance. And the CI matrix from #34 §CI, which would fail the build when a visible
group has no declared dependency, is not written: the PHPUnit test asserts the declarations are
well formed, but not that every group with CAS-dependent buttons has declared one. That check
needs the catalogue from #34 to exist first.


## 26. Iteration 22 (2026091321): three things found by asking where the buttons are

Ralf asked where vectors, matrices, cross and dot product, norm and det are. Answering it turned
up two defects and one genuine gap.

### The duplicate array key

Iteration 15 (#63) added a group `'geometry'` while a group of that name already existed, and
then merged the new entries into the old one as well. Two entries with the same key in one PHP
array: the later one wins, silently. The effective geometry group was the new one - points,
distance, angle - and the original symbols (overline, degree, angle, perpendicular) had
disappeared, along with the `requires` declaration from #66.

The duplicate is removed; the merged group from iteration 15 is the one that stays.

### The norm button promised something it did not deliver

`‖v‖` wrote `\left\|…\right\|`, which the converter turned into `abs(v)`. For a vector that is
not the norm, it is a different function - exactly the kind of button #34 forbids. With a
configured norm function (#45) the double bar now becomes `norm(v)`; the single bar stays
`abs(x)`, which is right. Without a configured function nothing changes, and the site has said
nothing about what its norm is called.

### det had no button at all

The conversions existed since #61 and #45 - `|A|` as a vmatrix, and `\det` in front of a matrix
environment - but no button offered them. There is one now, in the matrix group, and `det(A)`
from the keyboard is converted too: Maxima calls it `determinant()`.

### Verification

4 new Jest cases, suite at 968 green. ESLint, PHPCS green.

### Where the buttons live, for the record

| Wanted | Group | Default |
| ------ | ----- | ------- |
| Matrices, vectors (the two choosers) | Matrix operations | off |
| Vector arrow, norm, dot, cross | Vector operations | off |
| det | Matrix operations | off |
| Points, distance, angle, overline, degree | Geometry | off |
| grad, div, rot, Δ | Vector differential | off, and needs `load("vect");` |

Groups that are off by default have to be switched on in the site settings or per quiz/question.


## 27. Iteration 23 (2026091322): the groups were inside a comment

The screenshots settle the question from iteration 22. The settings page offers 14 groups, and
vectors, matrices and geometry are not among them. The reason is in `definitions.php`:

    // @codingStandardsIgnoreStart
    /*
    // 10. Physical constants.
    ...
    // 20. Statistics.
    */
    // @codingStandardsIgnoreEnd

Two block comments hold eight groups: physical constants, geometry, hyperbolic functions,
calculus operators, vectors, vector differential, matrices and statistics. They were parked
there - the same discipline as #34, features waiting for a verified mapping - and every
iteration since #45 has been editing code inside those comments without noticing. The matrix
choosers from #62, the operators from #45, the geometry entries from #63: all written, all
tested by Jest through the converter, and none of them reachable from the settings page.

A harness that evaluates `get_element_groups()` outside Moodle made it visible in one line: 14
groups instead of 22.

### What is activated now, and what is not

Four groups leave the comment: geometry, vector operations, vector differential, matrices. Each
keeps only the buttons whose mapping I could verify, which is the point of #34:

| Group | Buttons | Dropped, and why |
| --- | --- | --- |
| Geometry | P(x\|y), P(x\|y\|z), d(A,B), angle ABC, \|AB\|, overline | degree sign, bare angle, perpendicular - `90circ`, `angle` and `a perp b` are not Maxima expressions |
| Vectors | arrow, dot, norm | the cross product: LaTeX's times sign is multiplication, and a cross product needs `express(a ~ b)` from vect |
| Matrices | matrix chooser, vector chooser, det, ident, transpose | `A^T`, `A^*`, `A^†` - they became `A^(T)`, `A^(*)`, `A^(dagger)`, none of which Maxima accepts. ident and transpose are now function templates that do convert |
| Vector differential | grad, div, rot, Δ | nothing; the group is empty until an admin names the Maxima functions (#45) and is hidden without `load("vect")` (#66) |

Physical constants, hyperbolic functions, calculus operators and statistics stay in the comment:
they were not asked for, and I have not checked their mappings.

### The regression that should have existed

`definitions_test.php` now asserts that these four groups are in the catalogue and in the
settings list, and that an offered group has buttons - `vector_differential` excepted, because
there a setting decides. A group inside a comment is invisible from the outside: nothing failed,
the settings page was simply shorter than the code suggested.

### Verification

PHPCS green over the whole plugin, Jest 968 green, the group list checked with the harness: 18
groups, with the expected buttons per group.


## 28. Iteration 24 (2026091323): the last two red tests, and the README

Two CI runs, both red only in PHPUnit, and they tell the story of the last two iterations.

The earlier run (2026091321) failed three tests of `dependency_resolver_test`: the group
`vector_differential` was not in the catalogue. That was the comment-block problem, and
iteration 23 fixed it.

The later run (2026091322) fails two older tests of `definitions_test`: every group must have at
least one element, and every label must carry an example. `vector_differential` has neither -
its buttons exist only once an administrator has named the Maxima function for each of them
(#45). Both assertions now skip the groups listed in a new `CONFIGURABLE_GROUPS` constant, with
the reason in the docblock; the test from iteration 23 uses the same list instead of a local
copy.

The group stays in the catalogue although it is empty, on purpose: the settings page needs
somewhere to show that it exists, and #66 needs somewhere to say which package is missing.

### README

The feature list for 1.3 now names what has been built since 1.2 - matrices and vectors,
geometry, determinant and norm, the differential operators with their two gates, the typed
space, the on/off switch, STACK owning the implicit multiplication, identifier integrity and the
wider set of question contexts. The conversion section gains the matrix, determinant, point,
operator and space rules, and the pitfalls gain the question people will actually ask: a button
that is not there is a group switched off, a function not named, or a package not loaded.

Physical constants, hyperbolic functions, calculus operators and statistics stay commented out
as agreed - their mappings are unchecked, and #34 is clear about what that means.


## 29. Iteration 25 (2026091324): the README says what it should

The requirements section still read "mod_adaptivequiz is supported if it is installed, but not
required" - true since 1.0 and misleading since #50. No activity module is required at all; the
editor attaches itself wherever the question engine renders an editable STACK input, and the
sentence now points at the table that lists where that is.

The 1.3 feature list is rewritten the way Ralf asked: geometry, vectors, matrices, spaces,
the on/off switch, then the contexts. Vectors and matrices are separate entries now rather than
one bullet about choosers - a student looking for the norm is looking under vectors, not under
"structured input". Determinant, identity and transpose moved to the matrix entry, the norm to
the vector entry, and the CAS detail left the list: what a bullet has to say is what the button
does, not how it travels to Maxima.

Two entries are gone entirely. Identifier integrity (#58, #59, #61) is not a feature, it is the
absence of a bug. Implicit multiplication is not one either: #65 removed a setting, and what
remains is STACK doing what it always did.

Privacy keeps its own section, which now says what the API support actually does, instead of
claiming a place in a list of things a teacher can use.


## 30. Iteration 26 (2026091500): #67 - the external service authorises again

#67 is a regression of #14, and the invariant it states is the whole finding: a valid Moodle
context is not an authorisation, and a valid question id does not prove the question belongs to
the quiz that was asked about.

`classes/external/get_config.php` did `context_module::instance($cmid)` followed by
`validate_context()`, and then resolved every question id the caller sent through
`config_manager::resolve_qbeid()` - globally, against the whole question bank. Three things were
missing, and all three are now in the runtime path, in the order the review checklist demands:

1. `get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST)` - a context id says nothing
   about what the module is.
2. `require_capability('mod/quiz:view', $context)` after `validate_context()` - the line #14
   settled on, back where it belongs.
3. The question ids are scoped against the quiz: `quiz_helper::load_quiz_qbeids()` returns the
   bank entries the quiz actually uses, and anything else is dropped from the answer. Fail
   closed, not resolved globally.

`load_quiz_qbeids()` is new and deliberately does not filter by question type, unlike
`load_quiz_stack_questions()`: the question here is "does this quiz use it", not "is it a STACK
question". A quiz whose slots cannot be read scopes to nothing, never to everything.

### Tests

`tests/unit/external_get_config_test.php` covers what the issue lists: an unknown question id is
dropped, a question from another quiz is not answered for while the same question through its own
quiz is, a user whose `mod/quiz:view` is prohibited gets nothing, a forum course module is
refused, a course module id that does not exist is refused, and an empty quiz scopes to nothing.

The one test that needs a question in a quiz skips itself where `quiz_add_quiz_question()` is not
available, so a future Moodle that moves that API does not turn a red test into a false finding.

### Not done in this iteration

Issues #68, #70 and #71 could not be read: GitHub's API refused with a rate limit, and only #67
and #69 came through. #69 (`MATURITY_STABLE` evidence: browser, accessibility, performance,
dependency and final artefact) is a release-process issue rather than a code change, and it needs
the four verification runs that cannot happen here.


## 31. Iteration 27 (2026091501): #71 - documentation and runtime describe the same contract

#71 (P1, stable documentation gate) lists four findings. Three are addressed here; the
fourth is a release step and cannot be done from a development branch.

### A and B: the README contradicted itself

Under "Usage & Settings" it still listed four settings, among them "Handling of implicit
multiplication", and said teachers could override "the variable mode". Two sections further down
it said, correctly, that STACK decides this and the editor has no setting for it. #65 removed
that setting in iteration 11; the README kept describing it.

The settings section now lists what `settings.php` actually registers - activation, default
groups, percent-pi, vector format, norm function, the four operator mappings, coordinate
separator, point notation and additional page types - and states plainly that implicit
multiplication is not among them.

### The guard, so it does not drift again

`tests/unit/documentation_test.php` reads `settings.php`, takes the setting names out of it and
asserts:

* every setting has a title and a description string;
* every title appears in the README;
* the README does not describe the removed setting, in any of its three old spellings;
* the strings of the removed setting are gone as well;
* the English and German packs have the same keys.

It is a cheap test and it would have caught this drift the day it appeared.

### #58: a browser regression on the real typing path

The issue is right that a converter test is not enough for this defect: it was born in MathQuill's
typing path. The existing Behat step types through `write()`, which is a different path.

A new step, `I press the keys "Umax" into the MathQuill field for "ans1"`, clicks the field and
sends real key events through Moodle's own `behat_general::i_type`. Five scenarios use it -
`Umax`, `Umin`, `argmax`, `maximum`, `sinvalue` - plus `max(x,y)` staying a function and `U_max`
keeping its subscript, in both directions. They assert the hidden STACK input and the LaTeX in
the field, which is the chain the issue describes.

### Release notes

The README has a "Release notes 1.3" section covering what the issue asks for: the new features,
the two fixes, the behaviour changes (the removed setting, the space key), what question authors
have to load or configure, and the support matrix.

### Not done here

The release step itself - `MATURITY_STABLE`, the version string, the tag and the plugin directory
metadata changed atomically - belongs to the release, not to a development iteration. So does the
formal decision on #34, #58 and #66: I can report what is implemented, but closing an issue or
accepting a residual risk is the maintainer's call.

What I can say about the three: #66's DoD is implemented and covered by tests, except the
dynamic browser check (add the line, reload, group appears). #58 now has the browser regression
the issue asks for, but it has not run yet. #34 is not finished - there is no catalogue that
assigns every visible button its verified mapping, and no CI check that fails when one is
missing. The buttons activated in iteration 23 were checked by hand, one at a time.


## 32. Iteration 28 (2026091502): #68, #69 and #70

### #68 - configuration travelled between quizzes

`config_manager` documented five lookup layers, and one of them was "any qbeid match (legacy
fallback)" - a query on `questionbankentryid` with no `cmid` at all. The same question used in
two quizzes therefore shared whatever one of them had configured, although `cmid = 0 + qbeid`
already exists as the explicit way to say "for this question everywhere".

The layer is gone from both paths. The single lookup no longer reads an unscoped qbeid record,
and the batch lookup - which had the same thing in bulk form - now starts at the global default.
Single and batch follow the same hierarchy again, which they did not before.

The other legacy layer, the one on the old `questionid` column, had the same hole and is now
scoped to `cmid = 0 OR cmid = <this quiz>`: a genuinely old record still rescues the quiz it
belongs to, and reaches no other.

Six new PHPUnit cases in `config_cross_quiz_test.php`: a configuration in quiz A does not reach
quiz B, the explicit global default does reach both, an exact configuration wins in its own quiz
only, batch and single lookup agree, the batch path inherits the global default, and a quiz
default stays in its quiz.

### #70 - the vendored library is pinned to a commit

`thirdparty/readme_moodle.txt` named a branch, which is not a pin: a rebuild of
`feature/matrix-environments` next month produces something else. It now carries the fork commit
`99c967df728178973084ebfe27577961348ffc56`, the upstream base, the toolchain (Node 22.22.2, npm
10.9.7), the build command, the import date and the SHA-256 of the three imported files, plus the
reproduction recipe that checks out the commit rather than the branch. The leftover sentence
claiming this build was `sme.1` is corrected - it is `sme.3`.

The main workflow gained a step that writes down which revisions a run actually tested against:
the plugin SHA, the Moodle branch, the HEAD of every STACK dependency it installed, and the
vendored MathQuill provenance. The branch variables stay as they are - testing against STACK
master is what catches incompatibilities early - but a run now says what it meant.

### #69 - evidence for the exact artefact

A new workflow, `release-evidence.yml`, started by hand with the commit to certify. It records
what is being certified (commit, version, maturity, vendored library with checksums), runs a
dependency audit over both lock files, builds the release archive with its checksum, and writes
the list of gates that no workflow can close - the manual accessibility sample, and the run links
for Playwright, accessibility and load on that same commit.

`docs/RELEASE-CHECKLIST.md` says the same thing in prose, including the part the issue is most
insistent about: the maturity flip is the last step, atomically with README, release notes, tag
and directory metadata, and never a mixed state.

### What I did not do

Option A of #69 - making Playwright, accessibility and load blocking jobs of the main workflow -
is a decision about how long every push should take, and it is yours. The hybrid gate is built;
turning it into a required check is a repository setting, as is branch protection for `main`
(#69 finding C), which I cannot change from here.

Nor did I close any issue or accept a residual risk. #34 in particular is not done: there is no
catalogue that maps every visible button to its verified mapping, and no CI check that fails when
one is missing.


## 33. Iteration 29 (2026091503): the issue numbers, correctly attached

The five review findings arrived as files named 01 to 05, and I wrote those numbers into code
comments and documentation. They are not issue numbers. The mapping, checked against the issue
titles on GitHub rather than against the file names:

| File | Issue | Subject | Done in |
| --- | --- | --- | --- |
| 01 | #67 | External service without capability and object scope (P0) | 2026091500 |
| 02 | #68 | Unscoped qbeid fallback mixes configuration between quizzes | 2026091502 |
| 03 | #69 | MATURITY_STABLE needs browser, a11y, performance, dependency and artefact evidence | 2026091502 |
| 04 | #70 | Stable build not reproducible: fork and CI dependencies not pinned | 2026091502 |
| 05 | #71 | README, open P1 issues and runtime semantics out of sync | 2026091501 |

Every `(#02)`, `(#03)`, `(#04)` in `config_manager.php`, `config_cross_quiz_test.php`,
`release-evidence.yml`, `moodle-plugin-ci-main.yml` and this document now reads `(#68)`, `(#69)`,
`(#70)`. #67 and #71 were already referenced correctly, the first by name from the start, the
second only in prose - `documentation_test.php` now names it too.

Three of the five mappings are confirmed by the issue title (#67, #68, #69 were readable through
the API or the page); #70 and #71 were confirmed by reading their page titles after the API rate
limit refused. No mapping rests on the file order alone.


## 34. Iteration 30 (2026091504): the CI run, and #34 gets its gate

### The run

Behat: 69 scenarios, 804 steps, all green - including the seven keyboard scenarios from #58 and
everything the toggle recursion had broken. The browser regression the documentation issue asked
for is no longer a plan, it is a result.

PHPUnit: one test red, `test_without_the_capability`. Prohibiting `mod/quiz:view` also makes the
activity inaccessible, so `require_login()` objects inside `validate_context()` before
`require_capability()` is ever reached - and the test insisted on the second exception. Both
mean the same thing, and the test now accepts either.

That exposed something worth saying out loud: a behavioural test cannot prove the capability
line is there, because the service looks correct without it. So a second test asserts the line
exists in the source and comes after `validate_context()`. That is exactly the regression #67
describes - #14 was closed with that line, and the line disappeared.

### #34 - a visible button is a promise, and now it is checked

`definitions::export_button_catalogue()` exports every button with the LaTeX it writes - no
language strings, because a contract is the template, not the label. `export_buttons.php`
regenerates the fixture, `button_fixture_test.php` fails when it is stale, and
`button_contract.test.js` takes all 116 buttons, fills the empty slots of each template with an
operand and converts it. Three assertions per button: nothing is reported as unconvertible, the
result is not empty, and no backslash survives (#39). Sixteen structural buttons are additionally
checked against their exact documented mapping.

It found a bug on the first run. The norm button wrote `\left\\|\right\\|` - a LaTeX line
break followed by a pipe, not a double bar - and it reached the CAS as `abs(\)`. I introduced
that in iteration 23 with one backslash too many, checked it by hand, and did not see it.
`button_fixture_test` now also refuses any template containing a line break.

This is the P1 that stood between the current state and stable. What the gate proves is narrow
and worth restating: every button produces a CAS-safe string, and the structural ones produce
the documented one. It does not prove the CAS agrees with the meaning - for the operators and
the geometry functions that needs a real question with the packages from #66.

Jest is at 1085 tests.


## 35. Iteration 31 (2026091505 / 1.2.2): #72 - Android Blink dropped the first characters

The issue is precise about the mechanism, and it is right. MathQuill's keyboard shim polls with
`EveryTick`, whose handler starts as `noop`. `typedText` is registered only from the keypress and
paste paths. Blink on Android delivers soft-keyboard text as `beforeinput`/`input` without a
keypress the shim can use - so the poller still held `noop`, and every character was dropped
until some other key registered it. Enter did, which is why Enter "healed" it.

### The fix, in the input layer, in both versions

`onInput()` is now a text-entry path of its own: for a non-composing insert event it registers
`typedText` with the poller. Registering rather than calling is the whole trick for
deduplication - `typedText()` empties the textarea when it inserts, so a second run on engines
that also fire the classic path finds nothing to insert. No browser detection, no synthetic key,
no new field, exactly as the issue asks.

An IME commits a whole word at once, which `typedText()` cannot do - it inserts a single
character. `compositionend` therefore commits the textarea content character by character and
leaves it empty.

* 1.3.x: fixed in the fork, `0.10.1-sme.4`, fork commit `5364d108`, rebuilt and vendored.
* 1.2.x: MathQuill 0.10.1 is a plain upstream release there and cannot take a fork. The same
  change is applied to the vendored `mathquill.js`, which makes it a modified library:
  `thirdpartylibs.xml` now says `customised=true` with version `0.10.1-sme72`, and
  `thirdparty/readme_moodle.txt` describes the patch, its reason, the SHA-256 of the patched
  file and how to re-apply it after an update. Released as 1.2.2.

### Verification

Six new Mocha cases in the fork (`test/unit/androidInput.test.js`): a character without keydown
or keypress, digits and operators, a fresh field needing no Enter first, a deletion left to the
keystroke path, the classic desktop sequence typing exactly one character, and an IME commit
arriving once. Mocha is at 822.

Both builds were then driven in a real browser - the vendored 1.3 bundle and the patched 1.2
bundle, each loaded on a blank page: typing `a`, `1`, `+` through input events alone produces
`a1+` in both, and the classic keydown/keypress/input/keyup sequence produces exactly `q`.

PHPCS green on both codebases, Jest 1085 (1.3) and 784 (1.2).

### What is not covered

A real Android device. The fix is verified against the event sequence the issue documents, not
against Gboard on a phone. The browser matrix in the issue - Chrome, Opera, Firefox, Edge, Brave
on Android - still has to be walked through by hand.


## 36. Iteration 32 (2026091506): the Playwright failure, and #73

### The e2e failure was the same drift as #71, one layer down

`settings.spec.js` timed out after two minutes on `admin: on/off`. The reason is in the helper,
not in the test: `adminSettings()` selected `s_local_stackmatheditor_variablemode`, and
`configure()` selected `variablemode` on the configuration page. #65 removed both in iteration
11. Playwright waits for a locator that will never exist, and the test dies of a timeout rather
than of a clear "element not found".

The browser suite still described a product that had not existed for twenty iterations. Fixed:
the helpers no longer touch the setting, the five-mode test became one - what is typed is what
STACK receives - and the quiz/question tests assert groups and activation, which is what they
were really about. The test that checked the removed setting is gone rather than adapted.

### #73: the student switch becomes a permission

Two things were mixed in one switch: whether the editor exists, and whether a student may put it
away. They are separate now, and the second is subordinate to the first.

* `allowstudenttoggle` as a site setting, default on, so an upgrade takes nothing away.
* `_allowStudentToggle` in the quiz and question configuration, stored like the activation, and
  only ever stored as true when the editor is on at that level.
* `config_manager::get_effective_student_toggle()` resolves it as an AND: no editor, no switch;
  the site can take it away; the quiz can; the question can; none of them can give it back.
* The configuration form shows the checkbox directly under the activation one, with
  `disabledIf` on it - the dependency is visible, and `configure.php` enforces it again when
  saving, because a disabled control is not a validation.
* The runtime receives the answer per slot and either renders the switch or does not. Not a
  disabled control: a switch that cannot be used still says there is a decision to make.
* A remembered browser preference cannot get around it. With no permission the editor stays in
  the state the author configured, whatever localStorage holds - `Preference != Permission`, as
  the issue puts it.

Tests: the full cross-level matrix from the issue as a PHPUnit table, the downstream cases
(system off / quiz on / question on, quiz off / question on), no editor means no switch, and the
upgrade default. Four Jest cases for the runtime decision, including the stored-preference case.
Jest is at 1089.

### Not done for #73

The Playwright cross-level matrix. The suite that would host it is the one that had been broken
since iteration 11, and I would rather see it green once before extending it.


## 37. Iteration 33 (2026091507): the matrix test asserted a feature nobody asked for

One PHPUnit case out of 125 failed on all three matrix jobs: case 5 of the #73 cross-level
matrix. System editor on, quiz editor off, question editor on - the issue's table says the
editor is off, the plugin says it is on.

The plugin is right, and my test was wrong to encode the table without checking. Instance modes
2 and 3 exist so that a question can decide for itself; the configuration page offers exactly
that to teachers, and it has worked that way since 1.0. #73 is about the student switch and
assumed the activation contract rather than changing it - Fall E even says so explicitly
("sofern die bestehende globale Aktivierungslogik ebenfalls als harte Obergrenze definiert
ist").

So the editor keeps its contract and the switch follows the issue. The case now asserts what
actually happens, with the reason in the docblock, and a new case covers what the issue was
really after: a quiz that switches the editor off decides for every question that says nothing.

If the activation contract should change - quiz off as a hard bound for its questions - that is
a separate feature with its own migration story, not a test fix.

### #70 recorded as a decision

No tags: the release lane keeps installing STACK from its moving branches, deliberately, and the
run writes down the revisions it tested against. `docs/RELEASE-CHECKLIST.md` now states that as
an accepted residual risk rather than leaving it looking like an oversight.


## 38. Iteration 34 (2026091508): #68 closed by measurement, #69 answered by decision

### #68

Ralf ran the two queries on his instance. No duplicates, and the second query failed with
"Unknown column 'questionid'" - his installation was created after that column was dropped, so
the legacy layer could never have fired there at all.

That is the answer, but only for one installation. `cli/diagnose_legacy_config.php` asks the
same questions anywhere: duplicates per (cmid, questionbankentryid), records of the old
questionid kind where that column still exists, and question bank entries configured in more
than one quiz - which is legitimate and was exactly the situation in which the removed layer
leaked one quiz's configuration into another. Read-only, and it says what it found rather than
what someone should do about it.

With that, #68's "historische Records migriert oder bewusst diagnostiziert" is answerable for
any installation, and answered for this one: nothing to migrate.

### #69

Two of the three things I asked for came back as decisions rather than tasks, which is fine -
they just have to be written down instead of left looking undone.

Branch protection: not wanted. A failing check stops the work and gets fixed, or knowingly does
not, but it should not block the merge mechanically. `docs/RELEASE-CHECKLIST.md` records that,
including what it costs: nothing prevents a tag on a red run, so the evidence run is the only
thing that shows a stable release was green.

The missing workflow: `release-evidence.yml` is not in the Actions list because a manually
started workflow only appears once its file is on the default branch. It was added on
`development` in 2026091502 and `main` still carries 1.2.2. It will appear after the next merge;
the checklist now says so, rather than leaving it looking broken.


## 39. Iteration 35 (2026091509): the vendored library is reproducible again

`ralferlebach/mathquill` main is now at `c1aa2e8b1ec0b0edafb965a12dd0a3be80a473bd`
("android-fix"), and it carries the keyboard shim and the six unit tests unchanged from what was
verified here.

Rebuilt from that commit with Node 22.22.2 and npm 10.9.7:

    mathquill.js      a8f0b253bf380ee2f625e71f9826fa585eece1087fa60b06bfe42a9747e3b0d5
    mathquill.min.js  19be0bd1d948c1692db5bc905a0bb18955ef51a9a486542eb60b3dfcbb07cfce
    mathquill.css     25af0d2b872ae38cb2024599787d4617dbecfb3a0228301a8b296ed60c59cb78

All three match the files this plugin ships, byte for byte. `readme_moodle.txt` now names that
commit instead of `5364d108`, which only ever existed on the machine that produced the bundle.

That was the last open point of #70 that could be closed from here: the modified runtime library
can be rebuilt from a commit that exists in the fork, and the checksums prove the rebuild lands
on the same bytes. The dependency pinning of the CI lane stays deliberately unpinned, as
recorded in the release checklist.


## 40. Iteration 36 (2026091510): cross product, the norm default, the textarea switch

### The cross product, with as few prerequisites as possible

Neither Maxima nor STACK has a cross product function. The operator is `~` from the vect
package, and it only produces a result inside `express()`. So the button writes exactly that:

    a x b                    ->  express(a ~ b)
    (a x b) x c              ->  express((express(a ~ b)) ~ c)
    column vectors           ->  express(matrix([1],[2],[3]) ~ matrix([4],[5],[6]))

One line in the question variables is the whole prerequisite, `load("vect");`, and nothing has
to be defined. The resolution runs on the finished Maxima string, where an operand is an
identifier, a number, a bracketed group or a function call, innermost first.

The button lives in a group of its own, "Vector products", which declares the vect package
through #66: where the question does not load it, the group is not rendered, and the arrow, the
dot and the norm in the neighbouring group are unaffected.

Two guards, because the times sign can also arrive from imported content: between two numbers it
stays multiplication, and a sign without an operand on one side is not turned into a call.

Worth recording, because I argued the opposite two messages earlier: STACK's `multsgn = "cross"`
option does *not* interfere. It only changes how STACK renders its own interpretation of the
answer, and the editor never reads that - a pre-fill reads the stored Maxima string. What remains
is imported LaTeX containing a times sign, which is what the two guards are for.

### The norm default follows the vector format

`stack/maxima/geometry.mac` has `Length(v)`, and it is the Euclidean norm in any dimension - but
it takes a **list** and refuses a matrix. The plugin writes vectors as matrices by default, so a
single default was wrong in one of the two cases either way. It now follows the format: `Length`
for list vectors, `norm` for matrix vectors, where the question defines it. The setting
description says so.

### The textarea editor gets the switch

The reason it was left out does not apply there: the lines of a textarea answer are separated by
newlines, so reading them back is splitting a string, not parsing one. `rebuildFrom()` does that,
and the permission from #73 is honoured per slot exactly as in the other two editors.

### Not in this iteration

Changing the size of an existing matrix through the chooser (#62 §3). The editor would have to
read the matrix under the cursor and resize it, and the fork's public API has `insertMatrix` but
nothing to inspect or resize an existing one. That is a fork change first, plugin second, and it
deserves its own iteration rather than the tail of this one.

Jest: 1097.


## 41. Iteration 37 (2026091600 / fork sme.5): resizing a matrix

Two changes, one in each repository, because the editor could not do this alone: the fork's
public API could make a matrix but not look at one.

### Fork

`matrixAtCursor()` returns the size, the environment and the cell contents of the matrix the
cursor is in, or null. `resizeMatrix({rows, columns, dryRun})` grows or shrinks it in place:
rows and columns are added at the end and start empty, everything beyond the new size is
removed, and the cells that stay keep their content and their position - which is the difference
between resizing and re-inserting. `dryRun` reports how many filled cells a shrink would discard
without changing anything.

Nine unit tests in `test/unit/matrixResize.test.js`; the Mocha suite is at 831.

### Plugin

The two choosers now take a starting size. With the cursor inside a matrix, the matrix chooser
opens on that size and changes it; the vector chooser does the same for a single row or column.
Each only offers to change what it could have made - the matrix chooser leaves a vector alone and
inserts a new matrix instead, and the other way round.

Before anything filled is discarded, the editor asks. Growing is free and happens without a
question; shrinking is not, and losing an entry to a menu choice would be the kind of thing a
student notices one question too late.

### Verified

The resize path was driven through the vendored bundle in a browser: a 2x2 matrix reports itself
correctly, a dry run to 1x1 reports three filled cells lost, growing to 3x3 keeps 1, 2, 3, 4 in
place and adds five empty cells. Jest 1103, ESLint and PHPCS green.

### One thing to do before this ships

`thirdparty/readme_moodle.txt` says PENDING where the fork commit belongs: this build was made
from the change before it was pushed. The fork source is delivered alongside; after pushing it,
the commit goes in there and the checksums have to match the rebuild. Same procedure as with the
Android fix, and for the same reason - a branch name or a local commit is not a release pin.


## 42. Iteration 38 (2026091601): sme.5 has its commit

`ralferlebach/mathquill` main is at `9a6ebaf4eb522cc49d2886a33531ec4142beca5b` ("matrix
resize"). All five changed files are identical to what was verified here, and a rebuild from
that commit produces the three artefacts the plugin ships, byte for byte:

    mathquill.js      300429ef6c7c1e4ff0ecbaf5b1ab5bfc7781d6bc4a441ccdf42d8f5f8588fa89
    mathquill.min.js  716c7a040668452d0fc6f7305c498c466c739487706114a53f010d91bd6686b4
    mathquill.css     25af0d2b872ae38cb2024599787d4617dbecfb3a0228301a8b296ed60c59cb78

`readme_moodle.txt` names it instead of PENDING. The fork's own CI on that commit is green:
29 browser tests, 58 cross-browser, 11 visual, both Node builds.

Nothing else changed in this iteration.


## 43. Iteration 39 (2026091602): #74 - the toolbar measures the editor, not the window

Two problems, and they are independent.

### Container instead of viewport

`@media (max-width: 767px)` asks the browser window. Moodle's navigation drawer does not touch
the window; it takes a third of the width away from the question. So the toolbar kept its desktop
layout in a column that was half as wide, and the buttons went off the right edge.

`.sme-input-wrap` and `.sme-equiv-wrap` are now query containers (`container-type: inline-size`,
named `smeeditor`), and the toolbar's responsive rules are `@container` rules. Opening the drawer
rewraps it, closing it expands again, and no JavaScript hears about any of it - the width changed,
that is all the layout needs to know. The media query stays as a fallback.

The wrappers also got `min-width: 0` and `max-width: 100%`, without which a flex item refuses to
shrink below its content and the editor pushes itself out of its column.

Moodle's stylelint reports container queries as unknown properties - its CSS syntax database
predates them. The validator is switched off for that block, with the reason in the file.

### Groups that break into fragments

Plain `flex-wrap: wrap` breaks a group of seven into two, three and two, and three fragments do
not read as one group. The rule from the issue: up to five buttons stay whole; beyond that the
first three and the last three stay together and only the middle offers break points.

`toolbar_layout.js` does the arithmetic - a module with no dependencies, so the rule is testable
without a browser - and `toolbar.js` turns it into DOM: a start cluster, the middle buttons as
direct children, an end cluster.

The middle buttons deliberately have no wrapper. The issue suggests one with `display: contents`;
a wrapper that has to be made invisible to the layout is a wrapper that does not need to exist,
and `display: contents` has a history of dropping elements from the accessibility tree. Direct
children wrap natively, and the DOM order, the visual order and the tab order stay identical.

### Tests

20 Jest cases for the break rule: every size from one to five stays whole, six breaks only as
3 + 3, seven as 3 + 4 or 4 + 3, eight after 3, 4 or 5, no break ever falls inside a cluster, the
pieces always add up, and nonsense input does not produce a broken plan. Suite at 1123.

`tests/playwright/toolbar_layout.spec.js` measures the rendered page: no button beyond the right
edge of its toolbar with the drawer closed and open, the width coming back when the drawer
closes, a container narrowed to 420px while the window stays wide, and the clusters of large
groups staying on one line each. It has not run - it needs the seeded site - and the drawer
selector is the part I would expect to need adjusting first.


## 44. Iteration 40 (2026091603): what the Playwright run found

The dev CI was green - 126 PHPUnit tests, 69 Behat scenarios, every static gate. The Playwright
run was not, and it found two real things.

### The label of the student switch failed the contrast check

    color-contrast (serious): label[for="sme-editor-switch-9"], label[for="sme-editor-switch-10"]

`--gray-600` on a light background is between 4.5 and 4.9 depending on what exactly is behind it,
which is right at the line WCAG AA draws for text of this size - and axe measured it below.
The label is now `--gray-700`, which is above 7:1 on white and on the toolbar grey alike, so it
passes whatever the background under those two questions turns out to be. I cannot see from the
log which background axe measured; the new value removes the question rather than answering it.

Two switches out of many were reported, which is worth noting for later: the editors on that page
do not all sit on the same background.

### The layout test never saw a toolbar

    TimeoutError: waiting for locator('.sme-toolbar') to be visible

Not a layout problem: the specs share one Moodle, they run in one worker, and `settings.spec.js`
runs first. It leaves the plugin in whatever state its last case needed - and that case is about
a different quiz. My spec opened the load quiz and waited for an editor that the settings suite
had switched off.

`a11y.spec.js` does not have that problem because it sets the admin settings itself before it
opens anything. The layout spec now does the same in a `beforeAll`: editor on, every group
offered. A test that measures a toolbar has to make sure there is one rather than inherit the
state of its predecessor.

The two tests that were skipped after the failure - the narrowed container and the clusters - have
not run at all yet.


## 45. Iteration 41 (2026091604): the drawer toggle, and #76

### Why the layout test hung for two minutes

The selector matched Boost's mobile navbar toggler - `d-block d-md-none`, hidden at 1280px - and
Playwright waited for a hidden element to become clickable until the test timed out. The log
says so plainly: "element is not visible", 236 times.

`visibleDrawerToggle()` now walks the candidates and takes the first one that is actually
visible; without one it records a skip annotation and returns, and the clicks carry a ten second
timeout so a wrong guess costs ten seconds instead of two minutes. The other two tests in the
file - the narrowed container and the clusters - have still never run, because they were skipped
after this failure both times.

### #76: one limit, resolved once

`maxstructureddimension` on the site, `_maxStructuredDimension` per quiz and per question,
resolved as ordinary inheritance: question, then quiz, then site, then 5. `0`, `null` and `''`
are not dimensions - they mean the level said nothing - which is the trap the issue warns about
and the reason `clean_max_dimension()` exists rather than a cast.

One setting for both choosers, as the issue asks. The client is handed the resolved number per
slot and never the levels it came from: resolving a hierarchy is a server's job, and a browser
that only knows the answer cannot get it wrong.

The form field is disabled while neither structured group is selected, and a small inline script
keeps that in step with the group selection while the form is open - a dependency that only
appears after saving is not a visible dependency. A stored value survives the groups being
switched off; a temporary deactivation is not a reason to forget a number.

Range 2 to 20, clamped rather than rejected on both sides: a value that was once valid should not
silently leave a chooser with no cells.

### Tests

PHPUnit: the cross-level matrix from the issue, the upgrade default, empty values meaning
inherit, clamping in both directions, an invalid site value falling back to 5 rather than
disabling anything, the group dependency, and a stored value surviving deactivation.

Jest: the grid offers exactly N x N, the keyboard cannot go past it, the vector chooser stops at
N, a structure larger than a lowered limit opens at the limit, and no value keeps the default.
Suite at 1129.

### Not done

The Playwright part of #76 - set 5, 7, 3 on the three levels and count cells in the popup. It
belongs in the layout spec's neighbourhood, and I would rather see that file run green once
before adding to it.


## 46. Iteration 42 (2026091605): three red tests, three different mistakes

### The setting's strings did not carry its name

`documentation_test` derives the string identifier from the setting name, which is the whole
point of it: it is the test that keeps the README, the settings and the language packs in step.
The new setting is `maxstructureddimension`, its strings were `setting_maxdimension`, and the
test said so on all three matrix jobs.

Renamed rather than excused. The convention the test encodes - a setting's strings are named
after the setting - is worth more than four characters of brevity, and an exception list in the
test would have hidden the next case.

I now run that check locally before delivering: a small script that reads `settings.php`, derives
the identifiers and looks for them in the language pack and the README. It takes a second, and it
is exactly what the CI does.

### A negative number is not a small dimension

`clean_max_dimension(-4)` returns null - "this level said nothing" - and my test asserted it
would be clamped to 2. The code is right and the test was wrong: a positive value below the
minimum is an intention worth clamping, a negative one is garbage, and garbage should inherit
rather than silently become a valid setting. The test now says that, with the reason next to it.

### Boost swaps the drawer buttons

The retry spent its ten seconds on `class="... hidden"`. Opening the drawer hides the button that
opened it and reveals a separate close button - so clicking the same locator again waits for an
element that will never be visible. The spec looks the visible toggle up again before closing.

That is the third selector problem in this one test, and each time the log said exactly what was
wrong: "element is not visible". The lesson I am taking is not about Boost - it is that a locator
captured before an interaction is not the same element afterwards.


## 47. Iteration 43 (2026091606): half a rename took the whole run down

PHPUnit and Behat both failed, on all matrix jobs, and neither ran a single test. The cause is
one line:

    Invalid get_string() identifier: 'setting_maxdimension_desc'
    * line 84 of /local/stackmatheditor/settings.php
    * line 524 of /lib/installlib.php: call to admin_apply_default_settings()

Iteration 42 renamed the setting's strings and updated `get_string('setting_maxdimension', ...)`
in settings.php - but not `get_string('setting_maxdimension_desc', ...)` two lines below it. My
rename replaced one string literal and left the other, because they are different literals and I
replaced the one I was thinking about.

What makes this worse than a failing test: Moodle loads `settings.php` while it applies default
settings during installation. A missing identifier there is a debugging message, moodle-plugin-ci
treats that as a failed install, and every job that needs a site dies before it starts. A missing
string in a form would have cost one test; here it cost the run.

### The guard

`documentation_test` now reads every `get_string('literal', 'local_stackmatheditor')` out of
settings.php and asserts the identifier exists. Identifiers built in a loop - the four operator
settings - are skipped, because a static check cannot say anything useful about them.

That test cannot run when the install fails, so it is a guard for the next kind of mistake rather
than for this one. The one that would have caught this is the local pre-check I now run before
delivering, which does the same thing without Moodle. It found the leftover in a second.

I have added it next to the README/settings check from iteration 42. Both exist because the same
class of mistake - a rename that touches some places and not others - has now cost two CI runs.


## 48. Iteration 44 (2026091700): #75 implemented, #76 finished

The consolidation issue lists twelve sections. Two of them asked for code that did not exist yet;
this iteration writes it. The rest are verification runs, and the last section of this entry says
plainly which ones I cannot do from here.

### #75: pointer selection

The matrix grid listens to `pointerdown`, `pointermove`, `pointerup` and `pointercancel` with
pointer capture, and the cell under the pointer is found with `elementFromPoint` rather than from
the event target - once a pointer is captured, every move is reported on the element the gesture
started on, so the target stops moving while the finger does not.

The `click` handler is gone. It was not needed: a mouse click is a `pointerdown` and a
`pointerup` on the same cell, so mouse, touch and pen now travel one code path instead of two.
`touch-action: none` in the stylesheet is what keeps the page from scrolling under the gesture;
the browser decides that before the first `pointermove` arrives, so cancelling events alone would
be too late.

The vector chooser gains a gesture pad above its buttons. One drag decides both things a vector
needs: the dominant axis says row or column, the distance says how long. A drag that has barely
moved has no direction yet, and the pad says so by keeping the orientation it had rather than
guessing. The buttons stay exactly as they were - a gesture is an addition, never the only way
in, and the keyboard path is unchanged.

A cancelled gesture chooses nothing and leaves the popup as it was: the system took the pointer
away, the student did not change their mind about the size.

The gesture maths - `dominantAxis()` and `dimensionFromDistance()` - lives in the model, without
dependencies, so it is tested as arithmetic rather than through a browser.

### #76: the last two points

The dimension field is now validated rather than clamped. Reading a stored value stays generous:
40 in the database becomes 20, because something once meant it and the chooser has to show
something. Typing 40 is refused with a message, because silently turning it into 20 would tell
the author their input was accepted as given. Two different jobs, two different functions, and
the tests say so next to each other.

### What this iteration cannot close

* **#34's CAS contract** needs Behat scenarios that evaluate buttons through a real STACK. That
  is a sizeable piece of work in its own right, and it belongs in one increment rather than the
  tail of this one.
* **#66, #72, #73, #74** need runs and devices: the dependency matrix in Behat, an Android phone,
  the cross-level matrix in the browser, and the Playwright suite that has still never got past
  its first test.
* **#70** asks for pinned release dependencies. That contradicts the decision recorded in
  `docs/RELEASE-CHECKLIST.md`, where not pinning is deliberate and the residual risk is written
  down. One of the two has to give, and that is Ralf's call rather than mine.
* **#69** wants the evidence workflow to check Playwright, a11y and load results for the same SHA
  automatically. Buildable - it means querying the GitHub API for runs on that commit - but it
  needs the workflows to have run on a common SHA at least once, which has not happened yet.

Jest 1138, PHPCS green, ESLint and stylelint green, both documentation pre-checks green.


## 49. Iteration 45 (2026091701): #77 - the bridge only carried traffic one way

The editor wrote into the original STACK input and raised the events STACK listens for. What it
never did was listen itself. So when STACK's JSXGraph binding moved a slider, set the input value
and dispatched a change event, the input held the new answer and the visible editor still showed
the old one - two different answers on one screen, and the student had no way to tell which one
would be marked.

### The listener

Directly on the input element, for `input` and `change`. Not delegated, not on a container: an
external library is under no obligation to let its events bubble, and STACK's do not. That single
detail is why a listener further up would have changed nothing.

When one arrives, the value goes back through `prefill()` into MathQuill, and that is the end of
it - the editor is being told, not asked. Writing the value out again would raise a second
validation for a value STACK already has.

### Telling an echo from a change

The editor raises `input` and `change` itself whenever it writes, so its own events come straight
back to the new listener. Two guards, both state rather than timing, as the issue asks:

* `prefilling`, which already existed and marks the moments when the editor is writing into
  MathQuill;
* `lastwritten`, the Maxima string this editor last put into the input. `syncToInput()` now
  returns what it wrote, so every writing path records it.

An event whose value equals `lastwritten` is our own echo and is dropped. Anything else is
somebody else's change.

### With the switch off

The input is what the student sees and types in then, so the value is remembered but not taken
into a hidden editor. Switching back on reads the input anyway, which is the behaviour from #13
and is deliberately unchanged.

### Tests

Nine Jest cases for the decision the listener makes: a non-bubbling `change` is heard, an `input`
is heard, our own echo is not adopted, nothing is adopted while the editor writes, an adopted
value is not written back out, ten alternating changes do not drift, a burst in one tick is
adopted once, the switch-off case, and an empty external value.

They test the rule, not the editor: MathQuill and jQuery are not available in that environment.
Three Behat scenarios cover the real thing - an external script sets the value and dispatches a
non-bubbling `change`, the editor picks it up, and an edit afterwards still reaches the input.

The textarea editor got the same channel; its lines are rebuilt from the text, which it could
already do for the switch.

### Not covered here

The JSXGraph regression from the issue, with the real question and four sliders. That needs the
question imported into a live site, and it is the test I would run first.


## 50. Iteration 46 (2026091702): the JSXGraph question becomes a fixture

The question from #77 is now `tests/fixtures/stack_jsxgraph.xml`: four algebraic inputs bound to
four sliders through `stack_jxg.bind_slider()`. Kept as close to the reported original as
possible - only the question name is changed to something stable - because a simplified version
would not prove the same thing. A slider that snaps to 0.5, a board that redraws on every drag
step and a binding that dispatches non-bubbling events are exactly the conditions the bug lived
in.

`seed.php` gives it a quiz of its own and exports `SME_JSXGRAPH_CMID`. Its own quiz because a
slider question is slow to instantiate and none of the other suites needs it; the export reaches
the tests through `$GITHUB_ENV` like the others, so the workflow needs no change.

### What the spec does

`tests/playwright/jsxgraph.spec.js` drags the real slider. It finds the handle by asking the
board where it is - `board.objectsList`, `elType === 'slider'`, `coords.scrCoords` plus the
container's offset - and then moves the mouse across it. No JSXGraph knowledge beyond that, and
none of it in the editor.

Four tests:

* moving a slider changes the original input **and** the visible editor, and the editor shows the
  value the slider now reads;
* typing in the editor moves the slider;
* five rounds of drag-then-type, checking after every single one that editor and input still
  agree - drift would show up as a growing difference rather than a single wrong value;
* the event count while dragging, because an adopted external value must not answer with a write
  of its own.

### Honest about what this is

It has not run. The fixture has never been imported, the drag coordinates have never been tried,
and JSXGraph's slider geometry is the kind of thing that works in the second attempt. What the
spec is good for today is that the next Playwright run will say something specific about #77
rather than nothing.


## 51. Iteration 47 (2026091800): the return channel adopted its own keystrokes

Eight Behat scenarios failed, and the symptom said exactly what had happened:

    STACK input 'ans1' value 'U' does not equal 'Umax'

Only the first character of every typed word arrived. All five keyboard scenarios from #58, the
function-call one, the subscript one, and the write-back scenario from #77 itself.

### The window I left open

`syncToInput()` dispatches the `input` and `change` events STACK listens for - from inside
itself, before it returns. The edit handler wrote

    lastwritten = syncToInput(...);

so at the moment the event reached the new listener, `lastwritten` still held the *previous*
value. The listener compared the input's new content against it, decided somebody else had
written it, and adopted the editor's own keystroke: `prefilling` went up, MathQuill was refilled
from "U", and the next keystroke landed in a field that was being rewritten. Every character
after the first was swallowed.

The guard was correct and the ordering was not, which is the kind of mistake a value comparison
cannot catch on its own.

### The fix

`syncingToInput`, exactly the state flag #77 asks for, set around every call that writes into the
input - the edit handler, the toggle, and the submit bridge - and checked first in the listener.
`dispatchEvent` is synchronous, so a plain boolean covers the whole window without a timer.

The submit bridge now records what it wrote as well; it did not before, which would have left the
same trap for a check-button press.

### Tests

Two Jest cases that reproduce the hazard rather than the symptom: one writes three times the way
`syncToInput()` does - events first, `lastwritten` afterwards - and asserts nothing is adopted;
the other asserts that a genuine external change immediately after our own write still is. The
previous tests all passed with the bug in place, because they set `lastwritten` before
dispatching, which is not what the code did.

Jest 1149, ESLint, PHPCS green.


## 52. Iteration 48 (2026091801): #79 - nesting, measured before it was fixed

The issue is an analysis of where recursion could break. Before changing anything I ran every
case it lists through both converters and wrote down what actually happened. That list is shorter
than the issue's and worse in one respect: the failures are not refusals, they are corruptions.

### What was really broken

    \sqrt{\sqrt{x}}                     ->  sqrt(sqrtx)        silently different
    \left|\left|x\right|\right|         ->  abs()x abs()       nonsense
    \left\|...\right\| nested           ->  norm(abs(v))       wrong function
    \binom{\binom{n}{k}}{r}            ->  binomial(binomnk,r)
    \sqrt[3]{\sqrt[5]{x}}              ->  (sqrt[5]x)^(1/(3))
    \vec{v_{1}}                        ->  vecv_1
    abs(abs(x))                        ->  \left|\left|\left(x\right)\right|   unbalanced

Everything else the issue lists - fractions, powers, subscripts, coordinates, mixed functions -
was already correct, because those paths use the bracket-aware helpers. The nth-root and
coordinate cases in particular were fine, so nothing was "fixed" there.

In `max2tex` the standard functions produced valid but wrong-looking LaTeX: the outer call was
converted and the inner one was left inside it, so a student saw a root sign around the word
"sqrt". That is #78 exactly.

### What changed

Three mechanisms, each replacing a family of regexes rather than a single case:

* `replaceBracedCommand(s, command, arity, build)` reads brace arguments with a counter and
  repeats until nothing changes. `\sqrt`, `\binom`, `\nthroot`, `\vec`, `\overline`,
  `\mathbb`, `\mathrm`, `\text` and `\operatorname` all go through it now, and
  `replaceOptionalArgCommand` does the same for `\sqrt[n]{...}`.
* `replaceInnermostDelimiters(s, open, close, build)` matches delimiter pairs from the inside
  out, because the first closing delimiter always belongs to the last opening before it. Used for
  `\left|...\right|` and `\left\|...\right\|`.
* In `max2tex`, the standard function list and the absolute value repeat until a pass changes
  nothing, and `binomial` is read with a bracket counter instead of a pattern that forbade
  brackets in its arguments.

One correction worth recording: the absolute value had two owners. `processAbsFunction()` did it
properly, and the standard-function list had an `abs` entry that fell through to the generic
wrapper and emitted `\left|` with no closing bar. The entry is gone.

### Where a limit stays

Bare `|x|` without `\left` keeps the old simple rule. Two identical delimiters cannot be nested
unambiguously - `||x||` is either a nested value or two empty ones - and guessing would be worse
than the limitation. Everything the editor itself produces uses the long form and goes through
the structural pass, which is why the nested case works at all.

### Tests

110 new Jest cases in `nesting.test.js`, built around depth rather than examples: every structure
at depths 1, 2, 3 and 5, in both directions, plus the four invariants from the issue - no stray
LaTeX in the CAS string, no raw function call in the LaTeX, roundtrip stability over a corpus of
21 expressions, and the depth matrix. Four Behat scenarios cover the editor path, including a
nested root written into the input from outside and read back.

Suite total 1259.

### Not done

The Playwright workflow in section 7 - clicking the root button inside a root - is not written.
The Behat scenarios cover the same chain through the editor's own API, and the toolbar path
belongs with the other button tests once that suite runs green.


## 53. Iteration 49 (2026092600): the rest of the #79 matrix

Four of the remaining items were real, and one of them was the worst kind of bug in this issue.

### Configured functions did not recurse

`processFunctionDefsList()` ran its list once, so `myfun(myfun(x))` left the inner call raw
inside the LaTeX - the same defect the standard functions had, in the code path nobody had looked
at because it is driven by configuration rather than by a list in the source. It repeats until
nothing changes now, as the standard list does.

### Coordinates were left alone rather than converted

`P(f(x)|g(x))` was not a failure and not a conversion: the pattern forbade inner brackets, did
not match, and the expression travelled on to STACK as a function call with a logical or inside
it. `convertPointNotation()` matches the brackets structurally and splits on separators outside
them, so coordinates may be expressions.

One ambiguity had to be decided: the separator and the absolute value share the bar character.
Inside `\left| ... \right|` the bars belong to the value, so a bracket containing them is left
alone. Whoever wrote the long form meant a value.

### A safety limit produced a plausible wrong answer

Forty nested fractions exhaust the fraction loop's twenty iterations. What came out was
`frac1 frac1 frac1 ...`: the generic `\command -> command` fallback had stripped the backslashes
off a structure the converter knows perfectly well, and nothing was reported. Now a known
structure that survives to the fallback raises `nested_structure_unparsed`, and the student is
asked to simplify rather than handed something that means nothing like what they typed.

Sixty nested roots still convert cleanly - the structural readers have their own generous guard -
so the limit is the fraction loop's, not a new restriction.

### Nth roots and subscripts were already correct

Measured, not assumed: `(sqrt(x))^(1/(3))` and `((x)^(1/(5)))^(1/(3))` come back as nested
radicals, and `x_{a_{1}}` collapses to `x_a_1`, which is the identifier a student means. Both are
covered by tests that say so, which is what the issue asked for where a case is supported rather
than fixed.

Jest 1278.

### What the meta issue still lists, and who can do it

Verification runs: the dev CI and Playwright on the current SHA, the Behat scenarios for #58,
#66, #67, #68 and #78, and a real Android device for #72. None of those can happen here.

Browser evidence that needs writing: the student-toggle matrix for #73 and the dimension
inheritance for #76, both as Playwright specs in the suite that has still never run green.

Process: #69's same-SHA gating and #70's pinning. #70 contradicts the decision recorded in the
release checklist, where not pinning is deliberate; that contradiction is Ralf's to resolve
before I change the workflow.

One discrepancy worth flagging: the review lists #75 as substantially open, but the pointer
lifecycle, the dead zone, the dominant axis, `touch-action: none` and `pointercancel` were
implemented in 2026091700 and are covered by Jest. Either the review read an earlier tree, or
those changes are not on the branch it looked at.


## 54. Iteration 50 (2026092601): the evidence the issues are waiting for

Three pieces of work, chosen because they are the ones that can be written rather than run.

### #73 and #76: browser evidence

`tests/playwright/permissions.spec.js` drives both hierarchies through the student's own view.

For the switch: with the permission it is there, the answer travels with it in both directions,
and the editor comes back holding what was typed. Without it the switch is absent and the editor
stays - including in a browser whose localStorage says the student had switched it off. That is
the assertion the issue is really about: a preference is not a permission, and a stored one must
not get around an author's decision.

For the limit: the site value reaches both choosers, a quiz override wins over it, the keyboard
cannot walk past the limit, and the configuration field goes dead when no structured group is
selected while keeping the number it holds.

The chooser buttons now carry `data-command`, so a test finds them without depending on a
translated label.

### #34: the contract asks a CAS

The Jest catalogue proves a button produces a CAS-safe string. It has no CAS, so it cannot prove
the CAS agrees - and that gap is what #34 is about.

`tests/behat/cas_contract.feature` puts one expression per group in front of a real STACK and a
new step, "STACK should accept the answer in", blurs the input and reads STACK's own validation
feedback. A rejection is a failure; silence and an interpretation are both acceptance.

`cas_contract_test.php` is the gate that keeps it honest: every group that ships visible buttons
must be named by a scenario, every scenario claimed must exist, every scenario must actually ask
the CAS, and the two groups without a contract of their own - brackets and the upper-case Greek
letters, which share the lower-case rule - are listed explicitly with their reason. Add a group
without a scenario and CI says so.

One scenario per group rather than per button: a group shares one mapping, and a broken mapping
shows up on the first expression that uses it. 116 scenarios would be slower and prove the same
thing.

### #69: the gate stops being advisory

`release-evidence.yml` now asks the GitHub API which runs exist for the commit being certified.
Playwright, k6 and JMeter have to have run on that exact SHA and be green, or the job fails and
says which one is missing. High and critical dependency findings stop it too; the audit still
runs to completion so the evidence records what was found either way.

The consequence is an order: the manual workflows run first, the evidence workflow last. The
release checklist says so now.

### What is still not mine to do

The runs themselves, the Android device for #72, and the decision on #70 - the meta issue asks
for pinned dependencies, the release checklist records not pinning as deliberate, and those two
cannot both stand.


## 55. Iteration 51 (2026092700): the two specs I wrote blind

16 passed, 2 failed - and both failures are the specs from the last iteration, which had never
run. Everything that existed before, including the toolbar layout tests from #74 and the whole
settings suite, is green.

### The checkbox that is two elements

    strict mode violation: locator('input[name="s_local_stackmatheditor_allowstudenttoggle"]')
    resolved to 2 elements

Moodle writes a hidden input with value 0 next to every checkbox setting, so that unchecking
submits something. Both carry the same name. The selector now includes the type.

### window.JXG was never going to appear

The JSXGraph spec waited sixty seconds for a global that does not exist: STACK loads JSXGraph
through RequireJS, and an AMD module does not publish itself on `window`. The board was on the
page the whole time.

Now the spec waits for `.jxgbox` first - which proves the question rendered and fails in seconds
rather than a minute - and then asks RequireJS for the module under the names STACK might use.
If none of them resolves, each test skips with the reason written down instead of failing. The
suite goes green and the gap stays visible, which is the right trade for a spec whose subject is
another plugin's module layout.

### Two decisions recorded

No pinning by tag, confirmed; the release checklist now says so with a date, and #70 is answered
by that position rather than left hanging. And #72 is verified on a real Android device, which
was the only thing standing between it and closing.


## 56. Iteration 52 (2026100100): #80 was a real bug, and Behat was not failing

### #80 / #81: the coarse gate blocked the fine one

With "off by default, can be enabled per quiz or question", a question could not be switched on
while its quiz stayed off. The resolution in `config_manager::get_effective_enabled()` was
already correct for every cell of the matrix - the issue's own analysis says so. The fault was
one line in front of it:

    if (!config_manager::get_effective_enabled($cmid)) { skip }

That asks about the quiz and knows nothing about the questions in it. A quiz that stayed off
stopped the runtime from loading at all, so the question override was never evaluated. Two
activation decisions existed, one coarse and one fine, and the coarse one could silence the
other.

`page_may_need_editor()` now decides only what holds for the whole page: mode 0 is off
everywhere and nothing below can change it; everything else loads the runtime and lets each slot
decide.

That moves a decision from the server to the client, and with it a trap the issue is right to
warn about: an adaptive quiz has no per-question configuration, so its slots are not in the map
at all, and "not in the map" used to mean "enabled". The runtime now carries
`defaultEnabled` - the quiz-level answer - and a field the slot map says nothing about follows
it. Both editors do this, single-line and textarea.

Tests: the full matrix as two data providers, nine cases each for modes 2 and 3, including
`quiz off / question on -> editor` in both; modes 0 and 1 against all nine combinations each;
the page gate itself; and the runtime default for pages without question-level overrides.

### Behat was running, and being killed

"Behat läuft nicht an" is what a cancelled job looks like in the UI: no result, no failure, no
log. The job ran for exactly thirty minutes and hit `timeout-minutes: 30`, which GitHub reports
as *cancelled*. The CAS contract from #34 added sixteen scenarios that each wait for a real
Maxima answer, and 69 scenarios in nineteen minutes became more than thirty.

Timeout raised to sixty, and the CAS contract moved into a step of its own. A failure there
means "the CAS disagrees with a button", not "the editor is broken", and keeping them apart
keeps that readable.

### Playwright: two failures, two different kinds

The JSXGraph spec waited for `.jxgbox` on a page where the question had clearly rendered - the
screenshot shows the editors, the toolbar and the switch. Rather than guess again at STACK's
container markup, the spec now accepts anything whose class or id mentions jxg, and when it finds
nothing it records what the page actually contained: how many STACK questions, how many editors,
which div ids look board-like, whether RequireJS is there. The next run answers the question
instead of repeating it.

The permissions spec found no switch, and the cause is my own rule from #73: the switch is only
stored as allowed where the editor is enabled at that level, and the test ticked the permission
without ticking the activation. It now ticks both, and if the switch is still missing it reports
the page's state instead of only the missing element.


## 57. Iteration 53 (2026100101): my diagnostic caused the failure it was meant to explain

16 passed, 4 skipped, 1 failed - and the one failure was the guard I added last time:

    Error: no switch rendered; page shows {"editors":0,"wraps":10,"toggles":0}

Two things in one line, and both were mine.

### The count raced the editor

`expect(toggle).toHaveCount(1)` waits. My guard counted `.sme-toggle` straight away and threw
before the assertion could wait - and the switch is attached a tick after the toolbar, which is
what the test waits for. The screenshot proves it: question 2 on that very page has its switch.
So the guard turned a passing test into a failing one.

Wait first, report second. The diagnostic now lives in the catch block.

### `.sme-mq-field` never existed

`wraps: 10, editors: 0` was not a product fault either: the editor's container is
`.sme-mq-container`, and `.sme-mq-field` is a class I invented when writing the spec blind. It
appeared seven times in permissions.spec.js and once in jsxgraph.spec.js, including in the line
the test waits on - which passed only because the selector had `.sme-toolbar` as an alternative.

Both specs use the real class now, and the MathQuill field is found through
`.mq-editable-field` inside the wrap rather than by assuming it is the input's previous sibling.

### The four skips said nothing

The JSXGraph tests skipped as designed, and the annotation explaining why went into the HTML
report, which nobody reads when the job is green-ish. `openQuestion()` now prints a one-line
page inventory to the console: how many boards, how many jxg-ish elements, which div ids look
like boards, how many STACK questions and editors. The next run says what STACK actually puts on
the page instead of leaving it to the imagination.


## 58. Iteration 54 (2026100102): a real screen reader in the pipeline

`tests/a11y-nvda/` drives NVDA through Guidepup. Its own package, because it needs Windows, a
headed browser and a site that is already running - none of which the Linux suites want.

### What it asserts, and why only this much

Four things, all of them cases where silence is a defect rather than a matter of taste: the
editor announces itself as an editable field *with a name*; the switch says it is a switch and
says its state when it flips; a toolbar button is read as a word rather than a symbol; the matrix
chooser announces itself and gives the focus back on Escape.

Everything else NVDA says is written to `transcripts/`, with the commit, the site and the time at
the top, and not asserted. Whether an announcement is understandable, whether the order matches
the task, whether something is read twice - those are judgements, and a test that pretended to
make them would be worse than no test. The workflow prints them as questions at the end.

### The half that needs no judgement

`zoom.spec.js` runs in the same job without NVDA: 200 per cent zoom without horizontal
scrolling, a 380 pixel viewport with no button outside its toolbar and none below 24 by 24 CSS
pixels, the keyboard reaching editor, toolbar and switch, and a visible focus wherever it lands.
Four of the six lines on the manual checklist are measurements, and measurements belong in CI.

### Why it points at a site instead of building one

NVDA exists only on Windows; the test site needs Maxima and is built on Linux. Putting both in
one job would mean a Windows Moodle with a Windows Maxima, which is a project of its own. So the
workflow takes a URL - staging, or a tunnel to the local instance - and the site it tests is a
real one rather than a fixture.

### What this does to #69

The release checklist's manual sample becomes: start the workflow, read five transcripts, answer
four questions, record the date and the NVDA version. The evidence is archived with the commit
instead of living in somebody's memory.


## 59. Iteration 55 (2026100200): the NVDA suite, built on what was already there

The first attempt asked for a URL a GitHub runner could reach and a course module id typed by
hand. Nobody has a public staging Moodle lying around, and the question "what do I enter here"
was the right one: I had built a second world next to the one that already works.

The browser suite has all of it - `seed.php` prints the ids, `helpers.js` logs in, the accounts
exist, `SME_BASE_URL` points at the site. The screen reader needs none of that to be different.
It needs Windows and a visible browser, and that is all.

So `a11y-nvda.spec.js` now lives in `tests/playwright/`, uses `env()`, `loginAs()` and `open()`
from the same helpers, logs in as a seeded student and opens the same quiz as the other specs.
It is kept out of the default run by `testIgnore` and gets its own project when `SME_NVDA` is
set - a visible browser, one worker, a longer timeout. Three commands on the Windows side, and
the site is the one already running in WSL.

What came out of the first attempt and stays: the four assertions where silence is a defect, the
transcripts with commit and date, and the honest limit that a judgement is not a test.
`a11y-zoom.spec.js` keeps the measurable half - zoom, viewport, target size, keyboard reach,
focus visibility - in the run that happens anyway.

What was deleted: the separate package, the static harness, the AMD shim, the generated fixture
exports and the Windows workflow. The harness worked - the editor booted in Chromium without
Moodle, which was interesting - but "interesting" is not a reason to maintain a second way of
loading this plugin. One site, one seed, one set of helpers.


## 60. Iteration 56 (2026100600): 1.3.0, stable

### The red CI was four newlines

Both pipelines failed on the same thing: "File is stale and needs to be rebuilt" for the maps of
tex2max, max2tex, input_fields and textarea_fields. The sources were identical to mine, and so
were the minified files. The committed maps each ended with a newline that grunt does not write;
the ZIPs I delivered did not have it. Something on the way into the repository - an editor
saving the file, most likely - added it to exactly the four maps that had been opened.

`.editorconfig` now tells editors to leave `amd/build/` alone, and every build file in this
delivery was regenerated in a fresh 4.5 tree and copied over together, not module by module.

### NVDA inside GitHub, without anything to type

The second attempt still asked for a URL, because NVDA needs Windows and the site needs Linux.
The answer is two jobs that meet: the Linux job builds and seeds the site exactly as the
Playwright workflow does, opens a temporary Cloudflare tunnel in front of it, points Moodle's
wwwroot at the tunnel and hands the URL plus the seed values to the Windows job as an artefact.
The Windows job waits for that artefact, runs `a11y-nvda.spec.js` with NVDA listening, and
uploads a "done" artefact the Linux job is waiting for before it shuts the site down.

The server and the tunnel are started with `setsid nohup` so they outlive the step that starts
them - the reason serve-test-site.sh is normally sourced in the same step as the tests.

Found on the way: `tests/playwright/package.json` had gained the Guidepup dependencies in the
last delivery without a matching `package-lock.json`, so `npm ci` - in every Playwright run, not
only this one - would have refused. The lock file is regenerated.

### Moodle 5.3

`MOODLE_503_STABLE` exists and is marked stable (build 20261005). Its environment file asks for
PHP 8.3, PostgreSQL 17 and MariaDB 11.4, so the release matrix gains two 5.3 rows with those
database versions; the service images take their version from the matrix. MariaDB 11 dropped
the `mysqladmin` name, so the health check uses `mariadb-admin`, which 10.11 has too.
`$plugin->supported` is `[405, 503]`.

### Release metadata, atomically

`version.php`: 2026100600, `1.3.0`, `MATURITY_STABLE`. README: the heading says 1.3 without "in
development", the support matrix says 5.3, and the release notes list what was added and fixed
since they were last written - the switch permission, the cross product, the pointer choosers,
the container-responsive toolbar, the external sync, the activation fix and the cross-quiz fix.


## 61. Iteration 57 (2026100601): the toolbar backlog leaves the code

The five commented-out groups in `definitions.php` - number sets, quantifiers, physical
constants, hyperbolic and further calculus operators, statistics - move to
`docs/toolbar-backlog.md`, each with the reason it is not shipped and the three steps that bring
one back. `definitions.php` no longer carries operations nobody can see; #34's last point.
The button catalogue is unchanged at 117, so nothing a student sees moves.


## 62. Iteration 58 (2026100602): the two gaps from the issue audit

### #73 item 17: switching the editor back on lost the switch

The save stored `_allowStudentToggle = allowed AND editor enabled`. While the editor is off the
checkbox is disabled, a disabled field is not submitted, and the stored "allowed" became 0 - so
turning the editor back on did not bring the switch back. `student_toggle_to_store()` now keeps
the stored choice whenever the editor is off or the field is absent; the effective resolution
already refuses the switch wherever the editor is off, so keeping the choice changes nothing
until the editor returns.

### #81: what was already there, and what was missing

The locked-off and locked-on hints for modes 0 and 1 already existed in the form, as did the
defaults for new configurations. What was missing was persistence: the form always submits the
activation, pre-filled with what the level inherits, and the save stored it unconditionally. Any
save - even one that only changed the toolbar groups - turned an inherited value into an override,
and the question stopped following its quiz. `activation_to_store()` stores a value only when it
differs from what is inherited or the level already had one of its own.

Both rules are pure functions in `config_manager` now, so they are tested as rules rather than
through a form: `form_persistence_test.php` with twelve activation cases, the switch cases, the
"saved the groups, then the quiz went off" scenario and re-enabling the editor.

Preview is defined and tested: a question preview has no course module, so it follows the
instance mode alone - shown in modes 1 and 3, not in 0 and 2.

`settings.spec.js` gains five browser cases on the two-question settings quiz: mode 2 with one
question on in a quiz that is off, mode 2 with nothing set, mode 3 with one question off, mode 3
with one question on in a quiz that is off, and saving only the groups.

### Moodle 5.3 in the dev pipeline too

The PHPUnit matrix gains 5.3 on PostgreSQL 17 and on MariaDB 11.4, with service images taken from
the matrix and the MariaDB health check on `mariadb-admin`, as in the release matrix.


## 63. Iteration 59 (2026100603): the board was one document further down

### #77: why the JSXGraph spec never found a board

STACK renders a `[[jsxgraph]]` block inside an iframe of its own - `[[iframe]]`, with the board in
`#jxgbox` and JSXGraph loaded into that frame from a CDN. The slider talks to the STACK input in
the parent page through `stack_js`, which sets the value and dispatches the non-bubbling change
event the return channel listens for. Every earlier version of the spec looked for `.jxgbox` and
`window.JXG` in the page; both exist, one document further down. Reading
`stack/cas/castext2/blocks/jsxgraph.block.php` settled it.

The spec now finds the frame whose document has `#jxgbox` and a board, reads the slider
positions there, adds the frame element's offset so the mouse lands on the handle, and asserts
both directions without a skip: slider to editor, editor to slider, five drag-then-type rounds
(ten changes) with editor and input compared after every one, and a write count that catches
the editor answering an adopted value with changes of its own.

### #72: the phone's event sequence, in a real attempt

A phone cannot run in CI, but what distinguishes it is the sequence of events its keyboard
sends. `android-input.spec.js` reproduces that sequence on a Pixel 7 viewport with touch, in a
real Moodle attempt, through the plugin's own editors into the original STACK input: Blink
(keydown 229, beforeinput, input, no keypress - the sequence that lost the first characters),
Gecko (keydown, keypress, input) and IME composition, for the single-line and the multi-line
editor, plus a deletion that must stay a deletion.

What it cannot do is replace the devices the issue names - Opera, Firefox and Firefox Klar on an
actual phone - and the system editor has no fixture on the load quiz.


## 64. Iteration 60 (2026100604): the dependency audit, done rather than planned

`npm audit` on both lock files. The Playwright tree had a critical finding (`decompress`) that I
had brought in myself with `@guidepup/setup` - a package the NVDA workflow does not even use,
because it installs NVDA with `guidepup/setup-action`. Removed; the tree is clean.

The Jest tree has 34 findings from two root advisories, braces and sprintf-js, neither with a
patched version. Both are DoS issues needing crafted input to a test runner that only reads this
repository. Accepted and documented in `docs/DEPENDENCY-AUDIT.md`.

The release evidence gate used to grep the whole audit for "high" and would have stopped every
release on those dev-only findings - exactly what #69 says must not happen. It gates on the
runtime audit (`--omit=dev`) now and keeps the full audit as evidence.


## 65. Iteration 61 (2026100605): the three pieces that needed code before a run

### Upgrade test (#73, item 43)

Every existing site reaches 1.3 by upgrading, and CI only ever installed fresh. A new `upgrade`
job in the release workflow finds the previous release by what `version.php` said - the
repository has no tags - installs a site with it, seeds the configuration a 1.2 site has (a quiz
default, a question override, a global question default, activation mode 2) straight into the
table, upgrades in place to the current commit and checks that every stored meaning survived and
the 1.3 settings arrived with defaults that change nothing. It is part of `ci-complete`.

### Units inputs (#77, item 18)

`stack_units.xml` is the algebraic fixture with a STACK units input, seeded into a quiz of its own
next to an algebraic question - the load quiz has a shape the performance suite depends on. The
browser test types a quantity and writes one from outside, the path a JSXGraph binding takes.

### System lines (#72, item 8)

The editor builds system lines when the stored answer is several relations joined by nounand. The
test stores such an answer, presses Check so the page comes back with it, counts the lines and
edits one with the Blink soft-keyboard sequence from #72.

### #69, item 32

Recorded as a decision in the release checklist: stable was set before the evidence, and the risk
was accepted explicitly.


## 66. Iteration 62 (2026100606): four red workflows, two causes

Dev CI was green. Playwright, NVDA, k6 and JMeter all failed - and all four at the same step:
"Seed test data", exit code 1, with nothing printed.

### One wrong word in a fixture took down every browser and load workflow

`stack_units.xml` used `<answertest>Units</answertest>`. STACK has no test called plain "Units" -
there are UnitsSigFigs, UnitsAbsolute and their strict variants - so the import threw, and
`seed.php` with it. Every workflow that builds a test site runs the same seed, so one fixture for
one test stopped four workflows.

Three changes, because each of them would have limited the damage on its own:

* the fixture uses AlgEquiv; what is under test is the units *input*, not the grading;
* the quizzes that serve a single spec - JSXGraph and units - are seeded through a wrapper that
  catches the failure, reports it on stderr, exports the id as 0 and lets the rest of the seed
  finish; both specs skip with the reason when their id is 0;
* the seed step prints its own output when it fails. Its stdout goes into the env file, so an
  exception message used to vanish, and "exit code 1" was all the log ever said. That is why this
  took a log download to understand rather than a glance.

### The NVDA job never started

`guidepup/setup-action@v1` does not exist; the action is tagged `0.21.0`, `0.20.0` and so on, with
no major-version alias. GitHub refuses to start a job whose actions it cannot resolve, so the
Windows job failed at "Set up job" before any of its own code ran. Pinned to `0.21.0`.

Neither was caught locally: a fixture is only checked by importing it into STACK, and an action
reference only by GitHub resolving it.


## 67. Iteration 63 (2026100700): six Playwright failures, one product gap among them

Dev CI, k6 and JMeter (on its second attempt) were green; Playwright had six failures and the
NVDA workflow did not get past its tunnel. Five of the six Playwright failures were mistakes in
specs I had written without being able to run them. One was a real gap in the plugin.

### The product gap: mode 1 could not take the switch away

`permissions.spec.js` expected a quiz to revoke the student switch while the editor is on
everywhere, and found it on all ten questions. The form only rendered the switch checkbox inside
the block for the override modes 2 and 3, next to the activation checkbox. In mode 1 there is no
activation checkbox, so there was no switch checkbox either - and #73 says a quiz or question may
take the switch away whenever the site allows it. The checkbox is now rendered in modes 1, 2 and
3, and its `disabledIf` only where the activation checkbox it depends on exists.

### The five spec mistakes

* **Zoom.** `zoom: 200%` on the body scales coordinates inside the page differently from the
  toolbar that holds them, so every button looked like it was outside it. Browser zoom at 200 % is
  a viewport half as wide; the spec sets 640 x 450 now, which is what WCAG reflow means.
* **Soft keyboard.** All characters were dispatched in one go, so "x+1" sat in MathQuill's
  textarea at once and was read as a paste. A real keyboard delivers one character per task; the
  spec waits between characters now.
* **JSXGraph.** The frame search required a board element called `jxgbox`; any frame with a
  board now counts, the wait is a minute because the frame loads JSXGraph from a CDN, and if
  nothing is found the spec prints every frame's URL, globals and script sources.
* **Settings matrix.** The new two-question cases assumed a clean slate, but the serial cases
  before them leave quiz and question overrides behind. Each resets the settings quiz first.
* **System lines.** Clicking STACK's Check timed out waiting for the button to be unobstructed.
  It is pressed from the page now, followed by a wait for the navigation.

### NVDA: an empty grep ended the step

The tunnel step ran under `bash -e -o pipefail`, and while cloudflared was still starting, the
grep for its URL found nothing, exited 1, and ended the step on the first try. `|| true`.

### JMeter: flaky in one specific way

Attempt 1 of the green run failed on one login out of ten - a 404 from PHP's built-in server at
ramp-up - while every request the plugin is measured on passed. The plan already starts the next
loop on error, so that thread logged in again and carried on. `check_jtl.py` now forgives exactly
that: a failed login followed by a successful one on the same thread, reported as recovered. A
thread that never logs in still fails the run, and so does any failure of anything else; both are
covered by tests of the script.


## 68. Iteration 64 (2026100700, version unchanged): README on the template, history in CHANGES.md

The README follows `ralferlebach/moodle-plugintemplate` now: badges, a one-paragraph description,
Requirements, Motivation, Installation, Usage & Settings, Capabilities, Scheduled Tasks, How it
works, Theme support, repositories, support, proposals, release support, translation, RTL,
Maintainers, Copyright - with Privacy, Third-party libraries and Development kept, because they
say things a site administrator needs. Greek letters and integration events moved under "How this
plugin works", where they belong.

Badges: CI, MDL Shield, release 1.3.0, maturity stable, Moodle 4.5-5.3, PHP 8.2-8.4, GPL-3.0.

The motivation section keeps six key features. Everything version-differentiated - the 1.3 release
notes, the full 1.3 feature list, the 1.2.2 and 1.2.1 fixes, 1.2, 1.1 and 1.0 - is in
`docs/CHANGES.md`, together with the device check: Android, Chrome and Firefox, portrait and
landscape, without findings.

`docs/` is excluded from the release archive, so the README would have linked to a file the
shipped plugin does not contain. `.gitattributes` now excludes `docs/**` and re-includes
`docs/CHANGES.md` - a directory-level exclusion cannot be overridden for a file inside it, which a
git archive dry run showed - and the release-artefact gate allows exactly that one file under
`docs/`. `tests/upgrade/` is excluded from the archive and the gate as well.

The version stays at 2026100700 / 1.3.0 / MATURITY_STABLE, as decided: documentation only.


## 69. Iteration 65 (2026100700, version unchanged): NVDA job sent to its own localhost; README trimmed

NVDA run 3 (commit 77a42b2) was the first to get as far as the tests: site built, tunnel up,
Windows job handed the site - and then the test step failed within seconds. The job log is not
readable without a sign-in and the API was not available in this session, so the cause is read
from the workflow, not from the log: `handoff/site.env` named `SME_BASE_URL` twice. First the
tunnel, then - from the seed's own export lines - `http://127.0.0.1:8000`, the address the site
had when it was seeded. In `GITHUB_ENV` the last assignment wins, so the browser on Windows went
to its own localhost. That fits the run: ten attempts failing in under a minute and an artefact
of 778 KB, with traces and video switched on.

- The seed's `SME_BASE_URL` line is dropped from the handoff, the tunnel is written last, and the
  step fails if the file does not name exactly one.
- The Windows job checks the site through the tunnel before the tests and says so in one
  annotation if it cannot reach it.
- In GitHub Actions the Playwright `github` reporter turns each failure into an annotation, and
  `tests/playwright/run-summary.js` writes outcome, first error and every transcript into the
  job summary. Both are on the run page without a sign-in.
- Every NVDA test brings the browser to the foreground (`navigateToWebContent`) before it sets a
  focus; three of the five did not, and NVDA reads the foreground window only.

Not verified: the NVDA assertions themselves. No run has reached them yet.

README, as ordered: two badges only (Moodle Plugin CI, MDL Shield); the sentence linking to
`docs/CHANGES.md` is gone. `tests/a11y-nvda/` - the separate harness that was rejected - is still
in the repository because unpacking an archive does not delete; it has to be removed by hand.


## 70. Iteration 66 (2026100700, version unchanged): Playwright run locally, six failures, four of them the product

Playwright run 21 (commit 77a42b2) was red and its log is not readable without a sign-in. Instead
of another round of guessing, the CI site was built in the session - Moodle 4.5, STACK, Maxima,
PostgreSQL, the seed, Chromium - and the suite run against it: 27 passed, 6 failed, 11 did not
run. Each failure was then reproduced and traced on that site. After the fixes: 44 passed,
1 skipped (a documented `fixme`), PHPUnit 180 tests green, Jest 1278 green, PHPCS clean.

Product defects the specs were right about:

- **#76, chooser limit.** `toolbar.build()` wrote the slot's limit into the definitions object
  every editor on the page shares, so the toolbar built last decided for all of them; and the
  multi-line editor passed the site value instead of its slot's. A quiz override of 7 showed 5.
  The limit now lives in a per-toolbar view of the definitions, and the multi-line editor reads
  its slot.
- **#81, frozen override.** `configure.php` took the "existing" activation from the merged
  configuration. A question that only inherited "on" from its quiz therefore looked as if it had
  chosen it, and saving its toolbar groups stored `_enabled: true` - switching the quiz off
  afterwards did not reach it. `config_manager::get_own_config()` returns what a level stored
  itself; the page uses it for activation, student switch and chooser limit. The student switch
  got the same store-only-what-says-something rule. The unit test for this case had passed
  because it handed the function the null the page never did; three tests now pin the page.
- **#77, lost answer.** A value written into the input that the single-line editor cannot show -
  a relation system - left the editor empty, and Check/Submit then wrote that emptiness over the
  value. An empty editor that was never edited no longer overwrites the input.
- **Angle button.** Its label was `'\u{2220}ABC'` in single quotes and reached the toolbar as
  those ten characters. Double quotes; the button fixture regenerated.

Defects of the specs:

- a11y-zoom: the toolbar has about seventy buttons before the field; sixty Tabs were not enough.
- android-input: events built by hand with `dispatchEvent` are not what a browser sends. The spec
  now uses the DevTools input pipeline (`Input.insertText`, `imeSetComposition`, real key
  events), and no longer runs serially, so one failure does not hide five results.
- jsxgraph: JSXGraph 1.12 keeps its boards in `JXG.boards`, not `JXG.JSXGraph.boards`; the board
  is below the fold and the mouse works in viewport coordinates; a click sent while the page was
  still scrolling back landed on the board's iframe; the editor sits before its input, not
  around it.
- settings and permissions left the site "off by default" with one group, and the load quiz
  without switch and with a limit of 3 - the specs after them met a different site. Both restore
  what they found.

Known gap, not fixed, marked `test.fixme`: a keydown with key "Unidentified" (keyCode 229)
directly before the text makes the MathQuill fork's `typedText()` return early - an upstream guard
against a ChromeOS quirk - and the character is dropped. The devices checked for #72 evidently do
not send that keydown; a keyboard that does would lose input. This is a fork change and a
decision.

Observation, not changed: the toolbar precedes the field in the tab order, so a keyboard user
passes about seventy buttons to reach the answer field. One tab stop with arrow-key navigation
(roving tabindex) would be the usual pattern.

Workflows: the Playwright job has 35 minutes instead of 20 (run 21 ended at 18:47), and both
browser workflows write totals, failures and - for NVDA - transcripts into the job summary via
`tests/playwright/run-summary.js`. NVDA's first test no longer tabs from the top of the page.

Not run: Behat, k6, JMeter, NVDA.


## 71. Iteration 67 (2026100700, version unchanged): NVDA not installed - three Guidepup parts that did not fit

Runs on commit afda74d, read from the run pages (annotations are public, logs are not).

Playwright run 22: the six failures of iteration 66, on a commit that does not contain
iteration 66 yet. Nothing new to fix; the fixes are in this archive.

NVDA run 4: the first run to reach NVDA. Tunnel, handoff and the new preflight worked; every
test failed with `Error: NVDA not installed at NVDAClient.connect`. Read from the Guidepup
sources rather than guessed:

- `guidepup/setup-action@0.21.0` bundles `@guidepup/setup` 0.23.0, which installs an NVDA build
  under the registry key `guidepup_nvda_0.2.0-2026.1.1`.
- `@guidepup/guidepup` 0.22.x - what `^0.22.1` in package.json resolved to - looks for an older
  build. The project has since moved on altogether: current libraries find NVDA in a cache
  directory, chosen by a manifest inside the library, and the setup tool has an `install`
  command that reads that manifest from the project.

So the fix is a set of versions that belong together, not a path: `@guidepup/guidepup` 0.33.0,
`@guidepup/playwright` 0.19.1 and `@guidepup/setup` 0.24.1 - the combination the Guidepup
Playwright project runs in its own Windows CI. The libraries are pinned exactly; the workflow
runs `setup --ci` before and `install nvda` after `npm ci`, in the project directory, and then
asks the library whether it finds NVDA, so a mismatch fails in one line. The setup action is gone.
The runner is `windows-2025`, named instead of "latest". The lockfile lost 557 lines; `npm audit`
reports nothing.

The spec starts NVDA with full capture instead of the first utterance only: role and state
often arrive as a second one.

Not verified: the NVDA assertions. No run has reached them yet.


## 72. Iteration 68 (2026100700, version unchanged): upgrade job takes the previous release from moodle.org

Main CI runs 16 and 17 (commits c76b0ef, 7065167), read from the run pages.

**Upgrade job.** It looked for the previous release in the git history
(`git log -G"release *= *'1\.2\.2'"`) and found nothing: 1.2.2 was released from a branch of its
own and is not an ancestor of main. As ordered, the job now takes the package from the Moodle
plugins directory. `.github/fetch-previous-release.sh` reads
`https://download.moodle.org/api/1.3/pluglist.php`, picks the newest published version of
`local_stackmatheditor` that is older than the commit under test - older, not newest, because
this commit's own version will be in the list once it is published -, downloads it from the URL
the directory gives, compares the MD5 the directory states, and checks that the unpacked
`version.php` says the version that was asked for. The checkout no longer needs the history.

Run in the session, end to end, on a fresh Moodle 4.5 with STACK: the script fetched 1.2.2
(2026091500, checksum as stated), the site installed with it, `seed_before.php` seeded, the
upgrade to 2026100700 completed, and `verify_after.php` passed all nine checks.

**Matrix.** Seven rows ended with "exceeded the maximum execution time of 35m0s" - none with a
failed step. Every row runs the whole Behat suite, which has grown with the CAS contract; the dev
workflow had its Behat limit raised to 60 minutes for the same reason. The row limit is 75 now.
Not known: whether Behat passes on Moodle 5.0-5.3. No row there has ever run to its end, and the
dev workflow runs Behat on 4.5 only.

Left in the repository by unpacking: `tests/playwright/nvda-summary.js`, replaced by
`run-summary.js`. To be deleted by hand.


## 73. Iteration 69 (2026100700, version unchanged): issues checked against the runs on the release commit

Runs on main, commit 7065167 (development c76b0ef is its parent), read through the API:

| Workflow | Run | Result |
|---|---|---|
| Moodle Plugin CI Dev (c76b0ef) | 37619771767 | success |
| Playwright | 37626231174 | success - 44 passed, 1 skipped (the documented `fixme`) |
| Accessibility (NVDA) | 37626180385 | success - 4 passed, 1 skipped |
| Load test (k6) | 37630688758 | success |
| Load test (JMeter) | 37626205745 | cancelled after 15 minutes in "Set up JMeter" |
| Moodle Plugin CI Main | 37626071278 | failure - upgrade job, and three rows over 35 minutes |

In the main matrix eight rows passed in 25 to 34 minutes, on Moodle 4.5 to 5.2 with both
databases. Both Moodle 5.3 rows and one 5.1 row were stopped by the limit during Behat. That
supports iteration 68's 75 minutes; it does not say that 5.3 passes.

Verified against these runs and the code, and ready to be ticked: #69 items 11, 14, 19, 21, 24,
25; #71 item 27; #72 items 6, 7, 8, 16, 19; #73 item 41; #76 items 48, 49; #77 items 3, 4, 12-16,
18 - which completes #77; #81 items 34, 36. Not ticked from this session: the session's GitHub
access reads issues but may not change them.

What the first real NVDA run showed, from its transcripts:

- The field: "Math Input:, edit, multi line, blank". It has a role and a name - MathQuill's
  own, which `aria-labelledby` puts before the plugin's `aria-label`.
- The switch: "Formula editor off: type the answer as plain text.. off".
- "Toolbar buttons are announced by name" passed with a transcript of the site navigation. The
  focus was not on the toolbar when NVDA pressed Tab, and the assertions were satisfied by any
  buttons anywhere. That result is void. The test now proves the focus before and after every
  Tab and compares what NVDA says with the button's own label.
- The matrix chooser test was skipped: a fresh site does not offer the matrix group. The NVDA
  site is seeded with `--all-groups` now.

JMeter: `curl` had no time limit and archive.apache.org stalled, twice. The download now tries
the mirror network first, the archive second, four minutes at most each, checksum from
apache.org.

New tests, for #69's items that had no evidence, both green locally:

- a11y-zoom: the core workflow at 200 per cent - type, use a toolbar button with the mouse,
  delete; the answer arrives in the STACK input.
- performance: no storms. Once the page has come to rest, five idle seconds bring no change
  inside the questions and no request from the plugin; one keystroke brings 177 changes and no
  plugin request. The first version of the test failed with 437 "idle" changes - MathJax
  finishing the toolbars in one burst, after which the page was silent for fifteen seconds. The
  test waits for rest instead of for three seconds.

Still open for reasons that are not code: #69 items 10 and 26 (a person reads the NVDA
transcripts), 12, 13, 18 (the new tests have to run in CI), 20, 22, 27, 30, 31; #71 items 24, 26;
#72 items 3 and 5 (Opera, Firefox Klar on a device); #73 items 43-45, #76 items 50-51 and #81
item 35 (main CI green, including the upgrade job).


## 74. Iteration 70 (2026100700, version unchanged): NVDA tabs from its own cursor, not from the focus

NVDA run 37678246346 on main (3670c5e): four tests passed, the matrix chooser among them for the
first time - "Matrix, dialog. Matrix, table. ... 1 times 1 matrix, row 1, column 1", and after
Escape the button again, "collapsed, opens dialog". One failed, twice: "toolbar buttons are
announced by name". The focus was on the first toolbar button - the test had proved it - and
after NVDA's Tab the second button did not have it.

That is the behaviour the previous run had only hinted at with its transcript of the site
navigation. In browse mode NVDA does not hand Tab to the browser as it is: when its own cursor is
not inside the focused element, it tabs relative to that cursor. `navigateToWebContent` leaves
the cursor at the top of the page, and a focus set by script does not reliably bring it along.
The step into the editor field (last button, one Tab) happened to work; the same step from the
first button did not.

Whether NVDA is in browse or focus mode cannot be asked - Guidepup's configuration leaves the
mode change to a sound - but it can be seen: the test presses Tab, looks where the focus went,
and switches the mode once if it went elsewhere. The transcript records which mode it ended up
in and, if the first Tab missed, where it landed and what NVDA said. A miss later on names the
element that has the focus instead of reporting "inactive". The transcript is written when the
test fails, too.

Not verified: this cannot run outside Windows. If the second attempt misses as well, the
transcript will say where the focus went.

`tests/load/__pycache__/` had been committed; `__pycache__/` is in `.gitignore` now, the
directory has to be deleted by hand.


## 75. Iteration 71 (2026100700, version unchanged): two flaky jobs, one cause each

**Upgrade job** (main CI 37683136764, commit 758c5e7): cancelled by its 20-minute limit in
"Install Maxima (STACK)". The log shows the step starting at 20:41:11 and nothing until the
cancellation at 21:00:50 - `apt-get update -qq && apt-get install -y -qq` waiting on a mirror
that does not answer, with `-qq` hiding which of the two it was. One run earlier the same job
passed in three and a half minutes. Nothing in the upgrade path itself failed.

The same two lines were in nine steps of six workflows. They are replaced by
`.github/install-maxima.sh`: timeouts on every download, apt's own retries, IPv4 only, no
prompt that can wait, each phase under a hard limit of 150 seconds and tried three times, the
phase and attempt printed, and the Maxima version checked everywhere, not only in two of the
workflows. Worst case about eighteen minutes instead of the job's whole limit; the usual case is
unchanged. Run in the session: both phases first try, Maxima 5.46.0.

**JMeter** (run 37683262811): `POST login: 9/10`, one HTTP 404. The result file shows which one:
thread 2's login, sent while thread 1's - the first login in the life of the site - was still
being processed (783 ms; every later login took about 40 ms). The 404 is a Moodle error page,
21 799 bytes, not a missing file; the eight logins after it succeeded. The stricter verdict in
`check_jtl.py` forgives a failed login only if the thread logs in later, and this plan stops a
thread that could not log in - correctly, it has nothing to measure.

`.github/warm-up-site.sh` now takes the site through its first login before a load plan
starts: one seeded student who is not part of the plans logs in alone and opens the dashboard
and the quiz. Used by the JMeter and the k6 workflow. Tried in the session against the local
site, including the refusal of a wrong password.

Not established: which exception Moodle raised - the result file does not keep response bodies,
and ten runs against the local site, warm and with purged caches, did not produce it. The
attempt to reproduce it on a site restored to its never-logged-in state was not completed. The
warm-up removes the condition the failure occurred under; it is not proof of the cause.


## 76. Iteration 72 (2026100700, version unchanged): Playwright - browser contexts that were never closed

Playwright run 37687306651 (commit 758c5e7): 40 passed, 1 failed, 1 flaky, 4 did not run. Both
failures are in `permissions.spec.js` and both are the same thing: `browser.newContext` answered
"Protocol error (Target.createBrowserContext): Failed to create browser context", or did not
answer until the test's three minutes were over. Run 22 had shown it once before, as a single
"flaky" line. The run before and the run after this code passed.

No assertion about the plugin failed. What the specs did: wherever a second user was needed,
`(await browser.newContext()).newPage()` - and later `page.close()`, or nothing. That closes the
page and leaves the context; Playwright closes only the context it created itself. The browser
is one process for the whole run, and with `video: 'on'` and `trace: 'on'` every context that is
left keeps its recorders. Counted from the code, in the order the files run: two from the axe
spec, one from JSXGraph, three from performance, four from the permissions spec itself - about
ten open contexts when the eleventh was refused.

`helpers.js` has `openPage()`, `closePage()` and `closeLeftovers()` now. Every one of the
nineteen places goes through them; contexts are remembered when they are opened, closed with
their page, and whatever a failed test left behind is closed in `afterEach` (in the settings
spec, whose three pages live for the whole file, in `afterAll`). No `browser.newContext` is left
in a spec.

Run in the session, whole suite: 46 passed, 1 skipped.

Not established: that the left-over contexts were the cause and not merely a burden. The
session runs without video and trace, so the refusal could not be provoked here. The leak is
real and removed either way; the next runs show whether the refusals go with it.


## 77. Iteration 73 (2026100700, version unchanged): MDL Shield review of 2026-10-08

Review of commit 758c5e7, grade C (pass): one medium finding, three low. All four were confirmed
in the code and fixed; for each the same kind of mistake was then looked for in every file.

**1. Configuration page, broken access control (medium).** `configure.php` checked
`mod/quiz:manage` on the quiz from `cmid` and then rendered whatever question bank entry the
request named. New: `quiz_helper::quiz_uses_entry()`, called before anything about the entry is
read; an entry the quiz does not use gets the same error as one that does not exist, so the page
can be used neither to view nor to probe questions elsewhere. Checked against the running local
site as administrator: the own question renders, quiz 1's question requested through quiz 2 and
a non-existent entry both end on the same error page.

The same pattern elsewhere - an id taken from the request and trusted because a page check came
before:
- `editor_injector` and `configure_injector` read `attempt` from the request.
  `load_attempt_stack_slots()` takes the quiz now and returns nothing for an attempt of another
  quiz. Core has checked the attempt on these pages already; the injectors no longer rely on it.
- `editor_injector` on the question preview reads `id`. It checks
  `question_has_capability_on(..., 'use')` itself now, as the preview page does.
- The external function was already fail-closed (`load_quiz_qbeids`), as the review notes.

**2. Undefined method in `lib.php` (low).** `quiz_helper::adaptivequiz_has_stack_questions()`
did not exist; the catch-all around the call turned the error into a configuration link that
never appeared for adaptive quizzes. Implemented against mod_adaptivequiz's own schema
(`adaptivequiz_question.instance`, `.questioncategory` - read from the module's `install.xml`),
returning false where the module is not installed.

The same pattern elsewhere: eleven catch-alls, all logging programming errors to a developer log
at most. `quiz_helper::caught()` reports an `\Error` (undefined method or function, type error)
through `debugging()` - a failure in PHPUnit and Behat, silent in production - and keeps runtime
exceptions quiet as before; every catch-all uses it. A tokenizer scan of all PHP files resolving
every static call to the plugin's own classes found this one method and nothing else (486 calls);
it is a PHPUnit test now. A scan for functions that need a library outside the bootstrap found
`stack_inputs` calling `question_has_capability_on()` inside a catch-all without loading
`questionlib.php` - it worked only because the one caller happened to load it. It loads it now.

**3. CLI script without `clilib.php` (low).** Fixed; the script was run against the local site.
A test checks every file that defines `CLI_SCRIPT` and calls a `cli_*` helper.

**4. Wrong `@package` in two tools (low).** Fixed; a test checks every docblock `@package`.

**Note from the review, privacy provider:** the export read `allowed_elements` directly while the
plugin reads and writes through its column helper. It uses the helper now.

Tests: `tests/unit/review_2026_10_08_test.php`, nine tests - entry membership, the order of the
checks in `configure.php`, an attempt bound to its quiz, the adaptive-quiz query against a
temporary table with the module's definition, the reporter, and the three code-wide scans.
PHPUnit 189 green, Playwright 46 green and 1 skipped, PHPCS clean. Not run: Behat.

## 78. Iteration 74 (2026100700, version unchanged): audit of 2026-10-08, issues #84-#95

The audit came as fifteen files (overall audit with closing matrix, fourteen findings). The
findings went into the repository as issues #84-#94, the overall audit as #95; the parts for #69,
#71 and #72, which already existed, were added there as comments. Each was checked against the code, then worked through in order.
Nothing was ticked or closed: acceptance criteria and DoD are checked afterwards, by the owner.

The version stays pinned at 2026100700 / 1.3.0, so no upgrade step, no schema change and no new
capability. Where an issue suggested one, the pinned-version alternative is named below.

**#84 Configure IDOR (P0).** Done in iteration 73; covered by `configure_access_test` (real STACK
questions, editing teacher: own question, foreign quiz, foreign course, missing entry, non-STACK,
prohibited `view`) and `configure-access.spec.js` (foreign and missing give the same page).

**#85 Rows of deleted course modules (P0).** `db/events.php` + `observer`: `course_module_deleted`
removes the rows of that cmid; `course_content_deleted` (course deletion goes through
`remove_course_contents()`, which deletes modules and contexts without the per-module event -
checked in `lib/moodlelib.php`) removes every row whose module context is gone. The privacy
provider reports such orphans in the user context of `usermodified`, exports them there, and
deletion removes them. Historical orphans: `cli/repair_config.php` (dry run by default,
`--execute`). Pinned version: the observers are read from the event cache, so a site that
already runs a 2026100700 build picks them up after "Purge all caches" - a fresh install or an
upgrade from 1.2 has them at once. Tests: `config_lifecycle_test` (module delete, course delete,
orphan export/delete per user and per userlist, repair, observer registration).

**#86 Adaptive quiz capability (P1).** `quiz_helper::configure_capability()`: `mod/quiz:manage`
for quiz, `moodle/course:manageactivities` (write, editingteacher and manager) for adaptive quiz
instead of the report capability. Navigation, edit-page links (`can_configure()`) and
`configure.php` ask the same method. A plugin capability would need a version bump.
Tests: `configure_capability_test` - mapping, capability metadata and archetypes, roles incl. a
prohibit override, and the real `extend_settings_navigation` callback through
`settings_navigation::initialise()` for editing and non-editing teacher.

**#87 Scope uniqueness (P1).** No unique index possible while the version is pinned (and NULL
semantics differ between databases anyway). Instead: one writer per scope through the Moodle lock
API, read-write-collapse in one delegated transaction, total read order `timemodified DESC, id
DESC` everywhere (single and batch reads take the newest row per scope instead of merging all of
them), `collapse_scope()` after every write, `cli/repair_config.php --duplicates` for old data.
Tests: `config_scope_integrity_test` incl. a held lock (database lock factory, not re-entrant in
one process) that makes the writer fail with `locktimeout` and write nothing.

**#88 Backup/restore/duplicate (P1).** `backup_local_stackmatheditor_plugin` /
`restore_local_stackmatheditor_plugin` at the module connection point: new cmid, question bank
entries through the `question_bank_entry` mapping (new or matched entry), rows for entries the
restored quiz does not use dropped, `usermodified` only with user data. Tests:
`backup_restore_test` - restore into a new course with and without users, duplicate_module.

**#89 Required paths cannot skip (P1).** `helpers.requireFixture()` fails in CI
(`SME_STRICT_FIXTURES=1`) and skips locally; `optionalSkip()` annotates a legitimate skip.
`run-summary.js` is a gate in both browser workflows: a spec without tests, a skip without the
annotation, or a fixme fails the job. Checked with a crafted result file (exit 1) and the real run.

**#72 Android 229 (P1).** Patch to the fork's `saneKeyboardEvents`: the Ctrl-Shift-U guard
matches the Unicode entry only, not a bare "Unidentified" with keyCode 229. Four new Mocha cases
in the fork, two of them fail without the change; the fork suite runs 835/0 in Chromium. Built as
0.10.1-sme.6, vendored, the patch file in `thirdparty/patches/` (the fork itself was not pushed),
provenance and checksums updated, library URLs carry the build as cache key. The fixme in
`android-input.spec.js` is gone; blink229 green in single- and multi-line, plus one Backspace.

**#69 400 % and evidence (P1).** `a11y-zoom.spec.js` runs reflow and the core workflow at 200
and 400 per cent. The evidence workflow requires main CI and the NVDA run on the commit as well
and writes the archive SHA-256 into the signoff sheet; NVDA judgement and the publish signoff stay
human lines.

**#71 Documentation drift (P1).** README MathQuill version, adaptive quiz per-question = per
activity (`context_resolver::supports_question_configuration()`, which `configure.php` uses now).
`documentation_test` compares README, thirdpartylibs.xml, readme_moodle.txt, the cache key and
the file checksums, and the support table against the runtime.

**#90/#91/#94.** Navigation callback tested for real (above); the dev workflow runs both CLI
scripts on the clean installation and fails on any PHP error; `.gitignore` has `*.py[cod]`, the
tracked `.pyc` is removed from the index; the doubled assignment was already gone.

**#92 i18n (P2).** Privacy export: `scope` from `privacy:scope_*`, `scope_key` machine-readable.
JS: no English fallbacks; a missing string shows as `[[key]]`, the popup's defaults are markers.
Jest (`i18n_contract.test.js`) and PHPUnit (`test_js_string_contract`) keep both sides in step.

**#93 Moodle 5.3 dark mode / BS5 (P2).** Checked on a real Moodle 5.3 site built locally
(`MOODLE_503_STABLE`, PostgreSQL 16 with the environment check relaxed locally - 5.3 asks for 17).
Measured first, then fixed only what failed: toolbar buttons 1.41:1 (light toolbar background in
dark mode), switch label 1.93:1 (`--gray-700` is not defined on 5.x, so its light fallback was
used). All colours are now `--bs-*` variables with the 4.5 values as fallback. Collapse and badges
carry the Bootstrap 5 attributes and classes next to the old ones. New `dark-mode.spec.js`
switches through the colour mode menu and runs axe plus measured contrast (field edges 3:1,
typed text 4.5:1, chooser cells 3:1); it skips as optional where there are no colour modes.

The 5.3 site showed what a Playwright run with `moodle_branch: MOODLE_503_STABLE` would have hit
in CI: `admin/cli` is outside `public/` (install, upgrade and purge paths fixed), the site needs
`composer install` (the routed API failed with a missing class - that is why the colour mode was
not saved), `seed.php` used the course question bank that 5.x no longer has, and the workflow's
PostgreSQL 16 is too old for 5.3 (now 17). Two layout findings on 5.3 as well: a five-button
group wider than a 420 px question reached out of the toolbar (groups may break inside now, but
only when they alone are wider than the toolbar), and Bootstrap 5's smooth scrolling moved the
JSXGraph board while the test read its position (instant scroll in the test). Two core
observations are reported, not asserted: Moodle 5.3's header row is 4 px wider than a 640 px
viewport, and MathJax 4 makes formulas in the question text tab stops.

One more product defect turned up on 5.3 through the JSXGraph spec: after a fast slider drag the
editor sometimes stayed one value behind the input. `adoptExternalValue()` ignored every value
while the `prefilling` flag of the previous adoption was up - it is released one timer tick
later, and Chrome runs pointer input before timers. The listener now has its own re-entrancy
guard and a separate flag for the initial value; `prefilling` only keeps the edit handler quiet.
The mirrored Jest case asserted the old behaviour (a burst adopted once, the last value waiting
for another event) and was rewritten. The a11y spec measured a toolbar button mid hover transition
when the mouse rested where "Start attempt" had been; it moves the mouse away before axe now.
On 5.2+ the lifecycle and duplicate tests use the course format actions instead of the
deprecated `course_delete_module()` / `duplicate_module()`.

Runs, all on the final state:
- PHPUnit 215/215 on 4.5 and on 5.3 (PostgreSQL; on 5.3 only PHPUnit 11's doc-comment metadata
  deprecations remain, as in every test of the plugin);
- Jest 1282/1282; PHPCS (moodle) clean; stylelint clean; AMD build fresh;
- Playwright with `SME_STRICT_FIXTURES=1` and the summary gate: 4.5 52 passed + the two dark-mode
  tests as optional skips, gate green; 5.3 54 passed, gate green;
- the fork's Mocha suite 835/0.
Not run here: Behat, MariaDB, NVDA, the k6/JMeter load runs.

## 79. Iteration 75 (2026100700, version unchanged): MathQuill fork commit, release artefact workflow

The Android fix is committed to the fork as `d094d8a4` (on top of `9a6ebaf4`; source and tests
identical to the delivered patch). Rebuilt from that commit: the three runtime files match the
vendored sme.6 files byte for byte, so `thirdparty/readme_moodle.txt` names the commit now
instead of "fork commit + patch", and `thirdparty/patches/` is gone. `documentation_test`
requires a full commit id as the fork reference.

`.github/workflows/release-artefact.yml`, taken over from moodle-mod_vimipad and adapted: on a
`v1.*` tag (or by hand) it builds `moodle-local_stackmatheditor-<release>-<version>.zip` with
root folder `stackmatheditor` via `git archive`, refuses a commit without green check-runs and
without green main CI and release evidence, checks tag against `$plugin->release`, the build date
(not newer than the commit - older is allowed while the version is pinned), the MathQuill
checksums against the provenance file, a committed build for every AMD module, languages (en,
de), development files, required runtime files and the stable maturity. The ZIP is then
installed into fresh Moodle 4.5 and 5.3 sites with STACK (`build-test-site.sh`, PostgreSQL 17),
the schema is checked and both CLI scripts run; only then is it published with
`archive: false` and attached to the GitHub Release with its SHA-256. The build steps were run
locally against the working tree: 166 files, one root, all checks green.

Also in this iteration, from checking the open issues against the code:
- #86: `can_configure()` requires access to the activity as well (course access, module visible),
  as `configure.php` does with `require_login()`; tested with a capability granted to a user
  outside the course and on a hidden module.
- #90: the navigation callback is tested end to end for mod_adaptivequiz too (real instances
  from vtos/moodle-mod_adaptivequiz: STACK question in the pool → link, none → no link, a role
  with `viewreport` only → no link). The main CI installs the module in its 4.5 rows; elsewhere
  the test skips.
- #88: course duplication (`core_course_external::duplicate_course`) has its own test.
- Main CI on 7781a9d: the PHPDoc checker (local_moodlecheck) rejected two inline mentions of
  `@package` in the text of `review_2026_10_08_test.php` - reworded, moodlecheck is clean. The
  5.3/pgsql row failed in apt: the Azure mirror delivered maxima at about 17 MB per 150 s, three
  attempts were not enough. `install-maxima.sh` now downloads first, resumably, with six
  attempts of 300 s, and installs from the cache.

Issues: #86, #88, #89, #90, #91, #92, #93 ticked and closed. #84, #85, #87, #94 ticked except the
CI items (green main CI incl. browser runs on the fix commit; static gates), which wait for this
delivery to be pushed and run. #69 (manual NVDA judgement), #71 (open P1, tag and release
metadata) and #72 (Opera and Firefox Klar on devices) keep their human items.

## 80. Iteration 76 (2026100800): version, upgrade step, concurrency, release smoke, RTL

Re-audit of 2026-10-08 (on 7781a9d) worked through; the release name stays 1.3.0 by decision of
the maintainer (the review proposed 1.3.1), the build becomes 2026100800, so every site with the
published 2026100700 is offered the upgrade.

- **Upgrade step** (`db/upgrade.php`, savepoint 2026100800), written against the table rather than
  the plugin classes: removes configurations of course modules whose context no longer exists
  (chunks of 500) and collapses duplicate scopes to the row every read returns (newest, then
  highest id). Idempotent. `tests/unit/upgrade_step_test.php`; checked by hand on MariaDB.
  `tests/upgrade/seed_before.php` now leaves what 2026100700 leaves behind - a quiz deleted with
  its rows still there, an orphan, a duplicate pair - and `verify_after.php` checks that the
  upgrade removed exactly those, that the observer works right after it and that a second repair
  finds nothing. Run locally from the ZIP published on moodle.org to the working tree: green.
- **One row per scope:** decision record `docs/DATA-INTEGRITY.md` - no unique index (NULL quiz
  defaults never collide on PostgreSQL/MariaDB), lock + transaction + collapse instead.
  `tests/concurrency/concurrent_save.php` starts N PHP processes writing the same two scopes at the
  same moment; its control mode runs the old read-then-insert path and shows the race (5-12
  duplicates per run on PostgreSQL and MariaDB), the real path always leaves 1/1 (4.5 PG, 5.3 PG,
  4.5 MariaDB, 16 x 40 stress). Main CI job *Concurrent writes* (pgsql, mariadb);
  `build-test-site.sh` takes `DB_TYPE`/`DB_PORT`.
- **Release ZIP tested as shipped** (`release-artefact.yml`): required files extended (events,
  observer, data_maintenance, backup, upgrade, repair CLI, fixture); the installed ZIP runs
  `tests/release/smoke.php` (import, configure, override, backup/restore into a new course,
  deletion, invariants) and the concurrency script; job `upgrade` installs the previously
  published build, seeds, replaces it with the ZIP, upgrades and verifies (4.5 and 5.3); job
  `archive-tests` runs PHPUnit (`--fail-on-warning`) and Behat from the unpacked ZIP; publish
  needs all of them. Fixture tests that need `tests/jest` skip in the archive.
- **Release guard:** `release-guard.yml` turns a GitHub release published by hand back into a draft
  and fails. RELEASE-CHECKLIST section 3 describes the gate; the moodle.org upload by hand is the
  remaining path, accepted as R2 in the new `docs/RESIDUAL-RISKS.md`.
- **Manual acceptance:** `docs/MANUAL-ACCEPTANCE.md` with the NVDA judgement (#69) and the Android
  device table (#72); the release evidence asks for both, for the SHA-256 of the uploaded
  archive and for the residual-risk acceptance.
- **RTL:** the seed writes a minimal `he` language pack (`thisdirection = rtl`) into the dataroot;
  `rtl.spec.js` (toolbar, both choosers, switch, configure page, focus order, overflow, axe).
  Found and fixed: button symbols and labels such as ∂²/∂x∂y or ∠ABC were rearranged by the page
  direction, the matrix grid was mirrored, the choosers anchored to the left of their button, and
  STACK's hidden input sat 9999 px outside the window on the right (RTLCSS flips `left`). Math
  elements keep `direction: ltr` (`/*rtl:ignore*/`), the choosers anchor by the toolbar's
  direction and are clamped to the viewport, the hidden input is clipped to one pixel without a
  side offset (with a `max-width` above STACK's `.que.stack input[type=text]`). No other physical
  properties needed changing.
- **CI:** `install-maxima.sh` downloads first with retries (the 5.3/pgsql failure of 7781a9d);
  release metadata test (`documentation_test::test_release_metadata_agrees`).

Note for the push: `thirdparty/patches/` was removed in iteration 75 but is still in 756f540
(unpacking a ZIP does not delete) - `git rm -r thirdparty/patches`.

## 81. Iteration 77 (2026100800, version unchanged): remaining work after the re-audit of bdc4a19

Version stays 1.3.0 / 2026100800 by decision of the maintainer. Pushed state bdc4a19 was identical
to the delivered tree.

- **P0 false green of the release evidence:** run 37797946872 ended green although main CI was
  still running and Playwright had not run - `missing=1` was set inside `{ ... } | tee`, a
  subshell. The check is now `.github/required-runs.sh`: per workflow the newest run whose
  `head_sha` is the commit, completed and successful, for main CI the job "CI complete" and for
  Playwright one green job each for Moodle 4.5 and 5.3; the result is the exit code and the last
  line of `required-runs.txt`. Tested against the real repository (bdc4a19: FAIL on Playwright;
  758c5e7: FAIL, the green Playwright run has no 4.5/5.3 jobs; unknown commit: FAIL everywhere)
  and with a stub (PASS, still running, missing job). `release-artefact.yml` calls it directly
  for a tag, with `release-evidence.yml` as an additional requirement, so an evidence run made
  too early does not carry a release. Run 37797946872 is named in RELEASE-CHECKLIST as not
  citable.
- **Playwright red on CI (toolbar_layout, "matrix_operators (5)" broken at 420 px):** reproduced
  with a width sweep - every group broke within a band of about 7 px above its own width,
  because the group's separator (padding-right 6 + border 1, margin 6) counted towards the line.
  Separators are now `::after` elements in the column gap (no layout), so a group breaks only
  when its buttons do not fit. Clusters of large groups may break when they alone are wider than
  the toolbar (before they reached out of it below ~350 px). New test: sweep 700-300 px in 4 px
  steps; it fails with the old CSS (424/428 px matrix_operators, 352-360 px
  differential_operators, buttons outside at 344/348 px) and passes with the new one.
- **Playwright 4.5 + 5.3:** `playwright.yml` runs both by default (`moodle_branch: all`, jobs
  "Playwright / MOODLE_405_STABLE" and "/ MOODLE_503_STABLE"); the 5.3 job sets
  `SME_NO_OPTIONAL_SKIPS=1`, so the dark mode tests must run there (optionalSkip throws,
  run-summary counts every skip).
- **Upgrade on MariaDB:** main CI upgrade job and the release workflow's upgrade job run on
  PostgreSQL and MariaDB; `check_database_schema.php` in both; `verify_after.php` compares every
  setting of the settings page with the fresh-install default (13 settings). Replayed locally from
  the published 2026100700 on PostgreSQL and MariaDB: green.
- **Adaptive quiz backup/restore/duplicate:** `backup_restore_adaptivequiz_test.php` (with users,
  without users, duplicate; question rows of an adaptive quiz do not travel).
- **Manual sign-off as a gate:** `docs/RELEASE-SIGNOFF.json` (status/date/by per item, R1-R6),
  `.github/check-signoff.py`: format in main CI, `--complete` refuses a stable tag in
  `release-artefact.yml`. R4 and R6 accepted, R1/R2/R3/R5 open - the tag waits for them.
  GitHub release and moodle.org upload described as two steps, SHA-256 check before the upload.
- **Comments:** issue numbers, audit markers and bug narratives removed from production PHP,
  `amd/src` and `styles.css`; reasons kept as present-tense rules. Checked mechanically: `php -w`
  identical for every PHP file, identical JS token streams, CSS identical without comments,
  minified builds identical apart from their docblock.
- **Inline JS:** the size-field logic of `configure.php` is `amd/src/configure_form.js` with Jest
  tests; the remaining inline script (`page_helper::inject_json_element`) carries JSON only.
- **Catch-alls:** lookups catch `\moodle_exception` only; `\Throwable` only at four page
  boundaries; `caught()` reports `\Error`, `coding_exception` and SQL errors through
  `debugging()`. `error_handling_test.php` fails on a new catch-all or a silent catch;
  `preview_config_test.php` covers the positive path of the preview lookup.
  `docs/ERROR-HANDLING.md`.
