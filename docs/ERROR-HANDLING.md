# Error handling - where the plugin catches, and what it lets through

The editor is an addition to pages that must work without it: a quiz attempt, the quiz
settings menu, the configuration page's preview. Nothing the plugin does may break such a page
for a student. That is the reason for catching at all. The risk of catching is that a defect of
the code looks like "no data" and stays unnoticed. The plugin handles both with two levels.

## Two levels

**Lookups** catch only `\moodle_exception` - what Moodle raises for data that is missing,
unreadable or not accessible (a deleted question, a quiz without slots, a refused capability).
A PHP `\Error` (undefined method, type error) is not caught there and goes up to the boundary.

**Boundaries** catch `\Throwable`. They are the places where the page continues without the
editor's part. There are four, and `tests/unit/error_handling_test.php` fails if one more
appears:

| File | Boundary | The page continues |
|---|---|---|
| `classes/hook_callbacks.php` | editor injection | with the plain STACK input |
| `classes/hook_callbacks.php` | configure link injection | without the configure link |
| `lib.php` | settings navigation of an adaptive quiz | without the configure entry |
| `configure.php` | question preview | without the preview; the form works |

Every catch, at both levels, hands what it caught to `quiz_helper::caught()`. The test also fails
for a catch that neither reports nor rethrows (a transaction rollback counts as rethrowing).

## What is reported

`quiz_helper::caught()` decides with `quiz_helper::is_defect()`:

| Caught | Treated as | Reported |
|---|---|---|
| `\Error` (incl. `\TypeError`) | defect | `debugging()`, DEBUG_DEVELOPER |
| `\coding_exception` | defect | `debugging()` |
| `\dml_read_exception`, `\dml_write_exception`, `\ddl_exception` | defect (SQL the database rejects) | `debugging()` |
| every other `\moodle_exception` (missing record, capability, login) | data | developer log line only |

`debugging()` is silent on a production site and visible on a development site; in PHPUnit an
unexpected debugging message fails the test, in Behat it fails the step. So a defect behind a
catch cannot pass CI silently, and a student never sees an error page because of the editor.

## Lookups and what they answer on a caught exception

| Method | Answers |
|---|---|
| `quiz_helper::slots_have_qbeid()` | the column is not there |
| `quiz_helper::load_quiz_stack_questions()` | no STACK questions |
| `quiz_helper::load_quiz_qbeids()` | no entries - a quiz whose slots cannot be read scopes to nothing, never to everything |
| `quiz_helper::load_attempt_stack_slots()` | no slot mapping |
| `quiz_helper::get_return_url()` | the activity's own page |
| `quiz_helper::can_configure()` | not allowed |
| `editor_injector::resolve_preview_configs()` | no configuration for the preview |
| `stack_inputs` edit link | no link |

The positive paths of these are covered by their own tests (configuration access, adaptive quiz
navigation, attempt slot mapping, preview), so a lookup that always failed would not look green.
