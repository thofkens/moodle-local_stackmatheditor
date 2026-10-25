moodle-local_stackmatheditor
============================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-local_stackmatheditor/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-local_stackmatheditor/actions/workflows/moodle-plugin-ci-main.yml?query=branch%3Amain)
[![MDL Shield](https://img.shields.io/endpoint?url=https%3A%2F%2Fmdlshield.com%2Fapi%2Fbadge%2Flocal_stackmatheditor)](https://mdlshield.com/plugins/local_stackmatheditor)

STACK Math Editor replaces the plain text answer inputs of **STACK** questions with a visual
**MathQuill** formula editor and a configurable toolbar. Students write mathematics as they would
on paper; the plugin converts it into the Maxima syntax STACK expects - and back again, so saved
answers reappear as formulas.


Requirements
------------

This plugin requires Moodle 4.5+ and PHP 8.2+. The CI covers Moodle 4.5 to 5.3 with PHP 8.2 to 8.4.

It also requires **qtype_stack** (STACK 4.13 or later) together with the plugins STACK depends on:
qbehaviour_adaptivemultipart, qbehaviour_dfexplicitvaildate, qbehaviour_dfcbmexplicitvaildate and
qbank_importasversion. STACK needs a working Maxima installation (Maxima 5.46 or later).

No activity module is required. The editor attaches itself wherever Moodle's question engine
renders an editable STACK input - see *Where the editor runs* below.


Motivation for this plugin
--------------------------

STACK assesses mathematical answers with a computer algebra system, but students have to type them
in a linear syntax such as `(x^2+1)/sqrt(2*x)`. For many learners, especially at school level and at
the start of their studies, this syntax is a barrier of its own: answers are marked wrong or invalid
because of brackets and stars, not because of mathematics.

This plugin lets students enter fractions, roots, powers, sets, logic and Greek letters visually,
while STACK keeps receiving exactly the syntax it needs. Teachers decide per site, per quiz and per
question which toolbar groups are offered.

### Key features

* A visual formula editor for every editable STACK input - quiz, adaptive quiz, question preview,
  CAPQuiz, StudentQuiz and embedded questions - with conversion to Maxima and back.
* A toolbar configurable per site, per quiz and per question: arithmetic, roots and powers,
  functions, sets, logic, calculus, Greek letters, and structured matrices, vectors and geometry.
* Toolbar groups that need a CAS package are offered only where the question loads it.
* Students may switch the editor off and back on, if site, quiz and question allow it; the
  answer travels with it.
* Works with touch, pen and soft keyboards, and the toolbar follows the width of the editor.
* Stays in step with scripts that write into the STACK input, such as JSXGraph sliders.


Installation
------------

Install the plugin like any other plugin to folder
/local/stackmatheditor

See https://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins.


Usage & Settings
----------------

After installing the plugin, it is active with its default settings: STACK questions in quizzes show
the visual editor.

To configure the plugin and its behaviour, please visit:
Site administration -> Plugins -> Local plugins -> STACK MathQuill Editor

There, you find these settings:

* **Plugin activation (instance-wide)** – completely disabled, completely enabled, or off / on by
  default with an override per quiz or question.
  With the two override modes, the most specific statement wins: a question can have the editor
  in a quiz that does not, and the other way round. A level that was never configured follows the
  one above it, and saving its form for another reason - the toolbar groups, say - does not turn
  the inherited value into an override of its own. A question preview has no quiz, so it follows
  the instance mode alone: shown in modes 1 and 3, not in 0 and 2.
* **Default toolbar groups** – the groups offered unless a quiz or question says otherwise.
* **Use percent-pi notation for π** – send π as `%pi` instead of `pi`.
* **Vectors in Maxima** – whether a one-row or one-column matrix is written as `matrix([a,b,c])`
  or as the list `[a,b,c]`. Choose what your STACK questions compare against.
* **Maxima function for a norm** – the function `‖v‖` is written to. The default follows the
  vector format: `Length` where vectors are lists (STACK's own function, any dimension), `norm`
  where they are matrices - there the question has to define it, conventionally
  `norm(v) := sqrt(v . v)`.
* **Maxima function for the gradient / divergence / curl / Laplace operator** – one name each.
  Empty means the button is not offered on this site.
* **Coordinate separator** – how the coordinates of a point are separated on screen. Notation
  only; STACK always receives a list.
* **Maximum matrix / vector dimension** – the largest size the choosers offer. Default 5, and a
  quiz or a question can set its own; an empty field there inherits. Without the matrix or the
  vector group the setting has no effect.
* **Allow students to switch the editor on and off** – the site-wide permission for the student
  switch. A quiz or a question can take it away as well; none of them can give it back.
* **Draw lists as points** – whether a stored list such as `[2,3]` is shown as `P(2|3)` again.
* **Additional page types** – further activities that render STACK questions through the question
  engine.

There is no setting for implicit multiplication. STACK decides that per input, and the
configuration page shows its current value read-only with a link into the question.

Teachers who may manage a quiz can override the toolbar groups and – depending on the activation
setting – the activation itself:

* for the whole quiz, via the quiz's "More" menu -> "Set up STACK MathQuill Editor";
* for a single STACK question, via the configuration icon next to the question on the quiz edit
  page or in the question info box of an attempt.

The configuration page shows quiz and question name (with version) and a collapsible preview of the
question. "Back" returns to exactly the page the configuration was opened from.

If you want to learn more about using local plugins in Moodle, please see
https://docs.moodle.org/en/Local_plugins.


Capabilities
------------

This plugin does not add any additional capabilities.

Configuring a quiz or question is a write action and requires a write capability of the activity:
`mod/quiz:manage` for a quiz, `moodle/course:manageactivities` for an adaptive quiz (that module
has no manage capability of its own; a role that may only view its reports cannot change the
configuration). The settings menu link, the links on the quiz edit page and the configuration page
itself check the same capability.


Backup, restore and duplication
-------------------------------

The editor configuration of a quiz or adaptive quiz is part of the activity backup. Restore,
import and "Duplicate" put it onto the new activity: the quiz-level setting as it was, each
question setting on the question as the restored quiz uses it. A setting for a question the
restored quiz does not contain is not restored. Who last changed a setting travels only with user
data; without it the restored setting has no "last modified by".


Scheduled Tasks
---------------

This plugin does not add any additional scheduled tasks.


How this plugin works / Pitfalls
--------------------------------

### The chain

The original STACK input stays in the page (moved off-screen, never removed), so STACK, Moodle and
other scripts keep working with it. The editor converts every change and writes the result into
that input:

    MathQuill (LaTeX) -> tex2max -> original STACK input -> STACK / Maxima
    saved answer -> max2tex -> MathQuill

Supported STACK input types: algebraic, units, textarea and equivalence reasoning.
### Which buttons a question offers

A button is shown when two things hold: its mapping to STACK is verified, and the CAS packages
it needs are available in that particular question.

| Group | Needs | Where it comes from |
|---|---|---|
| Geometry (points, distance, angle) | `geometry.mac` | STACK core, always there |
| Vector differential (grad, div, rot, Δ) | Maxima package `vect` | `load("vect");` in the question variables |

Every button in the toolbar is covered by an automated contract test: its template is converted
and has to produce a CAS-safe expression, and the structural ones are checked against their
documented mapping. A button whose mapping is not verified is not shipped.

A group whose packages are missing can still be selected and saved on the configuration page -
it is marked there, with the line to add - but it is not rendered for students. No second
"available" flag is stored anywhere: the question variables are the only source. Add the line
and the group appears on the next reload; remove it and the group disappears again.

The editor never writes `load(...)` or `stack_include_contrib(...)` into a question. That is the
author's decision.

### Where the editor runs

The editor extends STACK questions, not a quiz module. Wherever the Moodle question engine
renders an editable `qtype_stack` input, the editor attaches itself to it.

| Context | Editor | Per-question configuration |
|---|---|---|
| Quiz attempt | yes | yes |
| Quiz review, where the answer is still editable | yes | yes |
| Question preview | yes | yes |
| Adaptive Quiz (`mod_adaptivequiz`) | yes | per activity - one setting for all its questions |
| CAPQuiz (`mod_capquiz`) | yes | site defaults |
| Embedded questions (`filter_embedquestion`), including Page, Book and Lesson content | yes | site defaults |
| StudentQuiz (`mod_studentquiz`) | yes, where STACK is an allowed question type | site defaults |
| Another question-engine consumer | add its page type under *Additional page types* | site defaults |

Not supported, and not for want of trying: native Lesson question types and Offline Quiz do not
use `qtype_stack`, and a mobile app that renders its own question UI has no Moodle question DOM
to attach to.

A read-only input never gets an editor: on a review page or next to a teacher-rendered answer
the value stays as it is shown.

Questions that arrive after page load — CAPQuiz and StudentQuiz replace them over AJAX — are
picked up as well, and never twice: an input that already carries an editor is skipped.


### Implicit multiplication

STACK decides this, not the editor. Its "Insert stars" setting lives per input in the STACK
question; the editor hands STACK what was typed and shows the current setting on its
configuration page, with a link into the question.

Function names (sqrt, sin, log, …), constants and Greek letter names are never split.

### Conversion rules worth knowing

* **Roots** always become `sqrt(...)` and never touch neighbouring symbols.
* **Plus/minus** becomes a solution set: `x = ±2` → `(x=2) nounor (x=-2)`; `∓` is the mirror image;
  several signs are coupled (exactly two alternatives).
* **Equation systems** join their rows with `nounand`. STACK assesses each part of a `nounor` /
  `nounand` structure separately.
* **Logic buttons** write `and` / `or` / `not` / `implies` – STACK judges the statement as a whole.
  `⇐` becomes a swapped implication, `⇔` the conjunction of both implications.
* **Set theory** uses STACK's functions: `x ∈ A ∪ B` → `elementp(x,union(A,B))`; ⊆ → `subsetp`,
  ⊂/⊃ are proper subsets/supersets; a chain `A ⊃ B ⊂ C` means `A ⊃ B and B ⊂ C`.
* **Integrals** become `integrate(expr,x)` or `integrate(expr,x,a,b)`; `integrate`, `int` and their
  noun forms are read back. Only simple integration variables (dx, dt, dθ, …) are supported. An
  incomplete integral stays in the editor with a hint below it; nothing is sent for it.
* **Derivatives** become `diff(expr,x)`, `diff(expr,x,n)` or `diff(expr,x,n,y,m,…)`; the
  expression to differentiate is always written in the template's brackets. `diff` cannot tell
  whether d or ∂ was meant, so saved derivatives are always shown with ∂.
* **Matrices** become `matrix([a,b],[c,d])`; a vector is a one-row or one-column matrix, or a
  list when the vector format setting says so. Delimiters are notation: square brackets for a
  matrix, round ones for a vector, `|A|` for a determinant and `‖A‖` for a norm.
* **Determinants** become `determinant(...)`, whether written as `det(A)`, `\det` in front of a
  matrix or with vertical bars around one.
* **Points** become a list of coordinates: `P(2|3)` → `[2,3]`. The point name is a label and does
  not reach the CAS. `d(A,B)` and `|AB|` become `Distance(A,B)`, `∠ABC` becomes `Angle(A,B,C)`
  with B as the vertex.
* **Differential operators** become the function the site has configured, so `rot` can be `curl`
  in Maxima while the button still says `rot`. Δ is the Laplace operator only when a bracket
  follows it; a bare Δ stays the Greek letter.
* **A typed space** stays a space. `\,` and similar typographic spacing still carry no meaning.
* **Greek letters** use STACK's own convention, the letter's name (`\alpha` ↔ `alpha`); variant
  glyphs map to their letter; the uppercase buttons that look like Latin letters write the Latin
  letter (STACK does not distinguish them either).

### Pitfalls

* **A button that is not there** is usually a group that is switched off, a Maxima function that
  has not been named in the settings, or a package the question does not load. The configuration
  page says which of the three it is.
* **Empty lines** can be edited in the multi-line editor, but STACK itself drops them when an
  answer is saved; after a reload they are gone.
* A plain logical `or` is never turned back into `±` – only STACK's `nounor` is.
* Answers saved by versions before 1.2 with `and` between system rows or `or` between ±
  alternatives are displayed as logical `∧` / `∨` and keep that meaning when edited.

### Greek letters

* `\alpha` becomes `alpha`, `\Lambda` becomes `Lambda`, and back again. STACK accepts every Greek
  name as a student variable and typesets it as the Greek glyph, so teacher answers written as
  `alpha` match.
* Variant glyphs have no identity of their own in STACK and map to their letter: `\varepsilon` →
  `epsilon`, `\vartheta` → `theta`, `\varphi` → `phi` (they come back as the standard glyph).
* `\pi` becomes `pi` (or `%pi`, depending on the setting).
* `lambda` in front of a bracket is always written as a product (`lambda*(x)`), because
  `lambda(...)` is Maxima's anonymous-function constructor.
* Latin letters typed as a Greek name (a-l-p-h-a) form that name, which STACK – like the editor on
  the way back – shows as α.

### Integration events

The editor is an input surface, not an event gateway: it never cancels, replaces or isolates
Moodle's or STACK's own events. External scripts (learning or mentoring tools) should listen to
the **original STACK input** and to the documented `stackmatheditor:*` events below. They must
not depend on the internal `.sme-*` DOM structure, which may change without notice.

All events are `CustomEvent`s that bubble; `event.detail.source` is `'local_stackmatheditor'`.

| Event | Dispatched on | When | `detail` |
|---|---|---|---|
| `stackmatheditor:input` | original STACK input | after the editor wrote a changed value (the native `input` and `change` events fire right before it, once each) | `name`, `value` |
| `stackmatheditor:enter` | original STACK input | Enter in the visible editor, and the "+" buttons that add a line or row | `name`, `trigger` (`'key'` or `'button'`), `inputType`, `slot` (textarea/equiv) |
| `stackmatheditor:beforecheck` | STACK's Check button | click on Check, after every editor wrote its visible state | `name` (button name) |
| `stackmatheditor:beforesubmit` | the form | form submission, after every editor wrote its visible state | `submitter` (name of the submitting button, if any) |

Additionally, every Enter signal is mirrored as a non-bubbling `keydown`/`keyup` with
`key: 'Enter'` (`keyCode`/`which` 13) on the original input, for scripts that listen directly on
that field. The mirror does not bubble, so document-level delegation still sees exactly one Enter:
the real key event of the visible editor. Synthetic key events are untrusted and never submit a
form.

A Check click raises `beforecheck` followed by `beforesubmit`, then the native submission runs
unchanged, exactly once.

```javascript
const input = document.querySelector('textarea[name$="_ans1"]');
input.addEventListener('stackmatheditor:enter', (e) => {
    // e.detail.trigger is 'key' or 'button'.
});
document.addEventListener('stackmatheditor:beforecheck', () => {
    // The original STACK inputs already hold the visible editor state here.
});
```


Theme support
-------------

This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.

On Moodle 5.3 and later the editor follows Boost's dark colour mode: toolbar, editor fields, the
on/off switch and the matrix and vector choosers take their colours from the theme. A browser test
switches to dark mode and checks their contrast.


Plugin repositories
-------------------

This plugin is published and regularly updated in the Moodle plugins repository:
https://marketplace.moodle.com/plugins/3697

It is checked by MDL Shield (see the badge above).

The latest development version can be found on Github:
https://github.com/ralferlebach/moodle-local_stackmatheditor


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-local_stackmatheditor/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/ralferlebach/moodle-local_stackmatheditor/issues

Please create pull requests on Github:
https://github.com/ralferlebach/moodle-local_stackmatheditor/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

Due to limited resources, this plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

This plugin is designed to be compatible with all currently supported versions of Moodle, leveraging its latest APIs. However, if you are using a legacy version of Moodle, we kindly advise against installing or using this plugin. Instead, we strongly recommend updating your Moodle instance to a supported version to ensure security and compliance with current technological standards. Thank you for your understanding.


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be managed through AMOS (https://lang.moodle.org), where they will become part of Moodle's official language pack.

As the plugin creator, we continue to maintain the German translation. For all other languages, we kindly ask you to contribute your translations directly in AMOS. These contributions will be reviewed by Moodle's official language pack maintainers before being included in the official repository.

Thank you for supporting the global Moodle community!


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Privacy
-------

The plugin stores toolbar configurations per quiz and question, together with the user who last
modified them (see the privacy metadata). It stores no data about students or their answers –
answers remain in STACK.

The Moodle privacy API is fully supported: a data request reports and exports that "last modified
by" reference and anonymises it on deletion. The configurations themselves are course data and
are kept as long as the activity exists.

Deleting a quiz or a whole course deletes its configurations as well. Rows left over from an
installation that did not do this yet are reported in the user context of the person who last
changed them, so a data request still finds them; deleting that user's data removes them.
`cli/repair_config.php` reports such rows and, with `--execute`, deletes them; it also reduces any
duplicate configuration of one quiz or question to the one in use.


Third-party libraries
---------------------

MathQuill 0.10.1-sme.6 (https://mathquill.com), Mozilla Public License 2.0 – see
`thirdpartylibs.xml`. This is a build of the fork
https://github.com/ralferlebach/mathquill, which adds editable matrix and vector
environments and keeps soft-keyboard input on Android; `thirdparty/readme_moodle.txt` records the
base commit, the patch on top of it, the build steps and the checksums.


Development
-----------

The development environment, the quality gates and the CI pipelines are described in
`docs/ENTWICKLUNGSUMGEBUNG.md` (German); the test suites (PHPUnit, Behat, Jest, Playwright,
k6, JMeter) in `tests/README.md`. Neither is part of the release package.


Maintainers
-----------

The plugin is maintained by\
Ralf Erlebach


Copyright
---------

The copyright of this plugin is held by\
Ralf Erlebach

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.

This program is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.
