# local_stackmatheditor — Playwright browser tests

Smoke-level end-to-end tests that run against a **live Moodle site**. They cannot run inside the
static moodle-plugin-ci pipeline and have their own workflow (`.github/workflows/playwright.yml`).

| Suite | Checks |
|---|---|
| `smoke.spec.js` | the site answers, the settings page opens, `requirejs.php` serves the built `tex2max` |
| `settings.spec.js` | level (admin, quiz, question, inheritance chain) × setting (on/off, toolbar groups, implicit multiplication), changed through the real UI and verified in a student attempt, with screenshots |
| `performance.spec.js` | ten STACK editors on one page: ready in time, exactly one editor and toolbar per question, also after reloading |
| `dark-mode.spec.js` | Moodle 5.3+: switches to Boost's dark colour mode through its menu, then axe and measured contrast for toolbar, editor fields, typed text, switch, matrix chooser and the configuration page with its preview opened through Bootstrap's collapse. Skips, as an allowed optional skip, on a Moodle without colour modes (4.5); the workflow's 5.3 job sets `SME_NO_OPTIONAL_SKIPS=1`, so there it has to run |
| `toolbar_layout.spec.js` | the toolbar follows the width of its container, not of the window: drawer open and closed, a container narrowed to 420 px, and a sweep from 700 down to 300 px in 4 px steps - at no width is a button outside the toolbar, and no group of up to five buttons and no cluster of a larger group breaks while it fits on a line |
| `rtl.spec.js` | a right-to-left page (the seed writes a minimal `he` language pack with `thisdirection = rtl` into the dataroot): the toolbar follows the page and its keyboard order runs right to left, while formula, button symbols and the matrix grid stay left to right; matrix and vector choosers open under their button and stay in the window; no editor element outside the viewport; the switch turns the editor off and on with the answer; typed `(x-1)*2` reaches STACK unchanged; axe on editor and configuration page |
| `a11y.spec.js` | axe-core (WCAG 2.0/2.1 A/AA) on editors, toolbars and the configuration page; every toolbar button named from the language pack; keyboard input |

`seed.php` (idempotent, disposable test sites only) creates course `SMETEST`, a teacher, 20
students and two quizzes from the Moodle XML fixtures in `tests/fixtures`, and prints the
`SME_*` variables; `php seed.php --reset` removes the quiz/question configuration of the settings
quiz (used between the matrix tests).

## Run locally

```bash
make playwright SME_ADMIN_PASS='<admin password>'   # from the plugin root; installs Playwright on first run
# or manually:
cd tests/playwright
npm ci && npx playwright install --with-deps chromium
eval "$(php seed.php)"                             # exports SME_BASE_URL
SME_ADMIN_PASS='<admin password>' npm test
```

| Variable | Default | Purpose |
|---|---|---|
| `SME_BASE_URL` | from `seed.php` (`$CFG->wwwroot`) | site under test |
| `SME_ADMIN_USER` | `admin` | site administrator |
| `SME_ADMIN_PASS` | — (required) | password; a missing value fails the test instead of skipping it |
| `SME_NO_OPTIONAL_SKIPS` | unset (CI, Moodle 5.3 job: `1`) | an optional skip (dark colour mode on a Moodle without it) fails instead: the run has to execute every test |
| `SME_STRICT_FIXTURES` | unset (CI: `1`) | a fixture the seed promises (JSXGraph quiz, units quiz, multi-line input, student switch, matrix chooser) fails the test when missing instead of skipping it |

### Moodle 5.1 and later

`.github/build-test-site.sh` and `seed.php` handle both layouts: on 5.1+ the site needs
`composer install` (the routed API, which saves for example the colour mode, needs its libraries),
and questions live in a question bank module of the course instead of the course bank. Moodle 5.3
requires PostgreSQL 17; the Playwright workflow uses it for every branch.

### Required paths cannot skip (#89)

In CI every fixture is required: `helpers.requireFixture()` fails the test when one is missing.
Locally, against a site seeded by hand, the same call skips with the reason. A skip that is
legitimate in every run goes through `helpers.optionalSkip()`, which annotates it.

`run-summary.js` writes the job summary and, with `SME_STRICT_FIXTURES=1`, is a gate: it exits
with 1 when a spec file of the run collected no test, when a test was skipped without the
`optional-skip` annotation, or when a test is marked `fixme`. Playwright's own exit code says that
nothing failed; the gate says that everything that should run did run.

Reports land in `playwright-report/` (HTML) and `test-results/` (videos, traces, screenshots).
Open a trace with `npx playwright show-trace test-results/<test>/trace.zip`.

## Screen reader evidence (NVDA)

Same site, same seed, same accounts - only the browser runs on Windows with NVDA listening
(#69). `a11y-nvda.spec.js` is not part of the default run; it needs a visible browser and a
worker of its own.

Once, on the Windows side:

```powershell
cd tests\playwright
npx -y @guidepup/setup@0.24.1 setup         # the foreground-window lock relaxed
npm install
npx playwright install chromium
npx -y @guidepup/setup@0.24.1 install nvda  # the NVDA build the installed Guidepup expects
```

The three Guidepup parts have to fit: the library in `package.json` (pinned), the setup tool, and
the NVDA build the setup tool fetches for that library. Change one and re-run `install nvda`.

Then, against the Moodle you already develop on - from Windows, a site in WSL is reachable at
localhost:

```powershell
$env:SME_BASE_URL  = "http://localhost/moodle45_aliseadele"
$env:SME_LOAD_CMID = "..."        # from seed.php, as for every other spec
$env:SME_USER_PASS = "..."
$env:SME_NVDA      = "1"
npx playwright test --project=nvda
```

`seed.php` is the same one the other specs use; run it in WSL and take the values it prints.

NVDA does not speak during an automated run - its output goes to the speech viewer, and from
there into `transcripts/`, one file per flow with the commit and the time at the top. Those
files are the sample the release checklist asks for.

What the run settles: the editor announces itself as a named editable field, the switch says it
is a switch and says its state when it flips, toolbar buttons are read as words, the matrix
chooser announces itself and returns the focus. What it cannot settle: whether any of that is
*understandable*, in what order it comes, whether something is read twice. Read the transcripts
and answer those yourself.

`a11y-zoom.spec.js` needs no screen reader and runs in the normal suite: 200 and 400 per cent zoom
without horizontal scrolling, a 380 pixel viewport with no button outside its toolbar and none
below 24 by 24 pixels, the keyboard reaching editor and toolbar, a visible focus everywhere.

