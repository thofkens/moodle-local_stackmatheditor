# Release checklist

What has to be true before `MATURITY_STABLE`, and where the proof comes from. The rule behind
all of it: a run is evidence only if it names the exact commit it ran against.

## 1. Code gates, automated

Run on every push and pull request (`moodle-plugin-ci-dev.yml`) and, for the release branch, in
the full matrix (`moodle-plugin-ci-main.yml`):

| Gate | Where |
| --- | --- |
| PHP lint, code analysis, PHPCS, PHPDoc, savepoints, validate, mustache, gherkin | dev + main |
| PHPUnit across the declared Moodle / PHP / database matrix | main |
| Behat, including the STACK CAS preflight | dev |
| Jest, ESLint, AMD build freshness, stylelint | dev + main |
| Coverage threshold, release artefact, stale files | main |

`ci-complete` fails if any of them does.

## 2. Release evidence for the exact artefact

`release-evidence.yml`, started by hand with the commit to certify. A manually started workflow
only appears in the Actions list once its file is on the default branch - so this one shows up
after `main` has it, and the branch to certify is then chosen in the dialog under "Use workflow
from". It records what is being
certified (commit, version, vendored MathQuill with checksums), runs a dependency audit, builds
the release archive and writes the checklist of what a human still has to confirm.

Start these on the same commit **first and wait until they have finished**; the evidence
workflow checks them and fails when one is missing, still running or not green, so it is run
last rather than first:

* `moodle-plugin-ci-main.yml` - code gates across the support matrix, upgrade from the
  published build on PostgreSQL and MariaDB included; its job "CI complete" has to be green
* `playwright.yml` - browser path, accessibility included (200 and 400 per cent zoom), started
  with the default `moodle_branch: all`: one job for Moodle 4.5 and one for 5.3, both have to be
  green. On 5.3 the dark colour mode exists, so its tests run there and may not skip
* `a11y-nvda.yml` - the NVDA transcripts of this commit
* `load-k6.yml` and `load-jmeter.yml` - performance smoke

The check is `.github/required-runs.sh`: per workflow the newest run whose `head_sha` is exactly
this commit; it has to be completed with conclusion success, and for main CI and Playwright the
named jobs have to be among its green jobs. The result is the script's exit code and the last
line of `required-runs.txt` (`RESULT: PASS` or `RESULT: FAIL`), so the file and the outcome of
the workflow cannot disagree.

**Do not cite release evidence run 37797946872** (commit bdc4a19, 8 October 2026). It ended
green although main CI was still running and Playwright had not run: the old check set its
result inside a `{ ... } | tee` block, a subshell, and lost it. Evidence for build 2026100800
has to be produced again with the corrected workflow.

The evidence lists the commit, the SHA-256 of the release archive and the links of these runs,
and the state of the manual sign-off (section 5).

It also stops on a high or critical dependency finding. The audit itself still runs to
completion, so the evidence records what was found either way.

## 3. Branch protection: deliberately not used - the release is gated instead

`main` is not branch-protected, and that is a decision: a failing check stops the work and gets
fixed, or knowingly does not, but it does not block the merge mechanically. `ci-complete` stays
the honest signal, not a gate with a key.

The gate sits where a release is made:

* `release-artefact.yml` runs on a pushed tag `v<release>`. It refuses a tag that does not match
  `$plugin->release`, a stable tag on a build that is not declared stable, a commit whose
  check-runs are not all green, a commit without the green gates of section 2 and a green
  release evidence (checked directly with `.github/required-runs.sh`, not only through the
  evidence), and a stable tag while an item of `docs/RELEASE-SIGNOFF.json` is open. It then
  builds the ZIP and tests exactly that file - installed into fresh 4.5 and 5.3 sites and
  smoked (configuration, backup and restore, deletion, concurrent writes, CLI), installed as an
  upgrade over the build the plugins directory published before (4.5 on PostgreSQL and MariaDB,
  5.3 on PostgreSQL), PHPUnit and Behat run from the archive - and only then publishes it with
  its SHA-256.
* `release-guard.yml` turns a GitHub release that was published by hand back into a draft and
  fails, so a release cannot appear past the gate by accident.

Two separate steps, in this order:

1. **GitHub release** - automatic, by the tag, through the gate above.
2. **Moodle plugins directory** - by hand, after step 1. Download the ZIP and its `.sha256` from
   the GitHub release, run `sha256sum -c <zip>.sha256`, upload exactly that ZIP, and note the
   SHA-256 in the release issue. No other archive - not one built locally, not GitHub's
   automatic source archive.

No workflow can see step 2; that is residual risk R2 in `docs/RESIDUAL-RISKS.md`, to be accepted
in `docs/RELEASE-SIGNOFF.json` before the tag.

## 4. Screen reader evidence

`tests/playwright/a11y-nvda.spec.js` - the same seed, the same accounts and the same helpers as
every other browser spec, with NVDA listening. `a11y-nvda.yml` runs it inside GitHub Actions:
a Linux job builds and serves the site (Windows cannot host Moodle with Maxima), a Windows job
runs NVDA against it through a tunnel. It is started by hand, and the release evidence requires
a green run on the commit. `tests/playwright/README.md` has the commands for a local run from
Windows.

It records what NVDA says about the editor, the switch, the toolbar and the matrix chooser into
`tests/playwright/transcripts/`, one file per flow with the commit and the time at the top, and
fails when something a student depends on is silent. It cannot tell you whether what NVDA says
is understandable, in what order it comes, or whether something is read twice.

`a11y-zoom.spec.js` covers the measurable half in the normal run: 200 and 400 per cent zoom,
each with the core workflow (type, toolbar, answer in STACK), a 380 pixel viewport, 24 by 24
pixel targets, keyboard reach and a visible focus.

## 5. What no workflow can do for you

`docs/MANUAL-ACCEPTANCE.md` has the steps and the tables to fill in; the result goes into
`docs/RELEASE-SIGNOFF.json` (status `done` or `accepted`, `date`, `by`), which
`release-artefact.yml` checks before it publishes a stable release.

* The judgement half of the accessibility sample: read the transcripts from section 4 and say
  whether they describe something a person can work with. Record date, browser and NVDA version.
  Zoom, viewport width, keyboard reach and focus visibility are measured by `playwright.yml`
  and do not need repeating by hand.
* Real Android devices: the first soft-keyboard input in a fresh field, in Chrome, Opera,
  Firefox and Firefox Klar, and with a keyboard other than Gboard.
* The decision on every open P0 and P1: closed, or accepted as a residual risk - the reason in
  `docs/RESIDUAL-RISKS.md`, the name and the date in `docs/RELEASE-SIGNOFF.json`. An open,
  unexplained P1 cannot sit next to a stable release: the tag is refused.

## 6. Only then: flip the release metadata

**For 1.3.0 this order was not kept, deliberately.** `MATURITY_STABLE` was set on 6 October 2026
before the evidence runs had completed on the release commit. Ralf Erlebach accepted that risk
explicitly (#69, item 32): a stable release whose browser, accessibility and load evidence was
collected after the flag, not before it. The evidence still has to be produced; what was given up
is only that it gated the flag.

Build 2026100800 keeps the release name 1.3.0 and the stable flag of 2026100700; what it changes
is that its archive is published by the gate in section 3, so it is tested before anyone can
download it.

Atomically, in one commit:

* `version.php`: `MATURITY_STABLE` and the final release string;
* `README.md`: the version heading is no longer "in development";
* release notes final;
* tag and plugin directory metadata.

Never a mixed state - `MATURITY_STABLE` next to "in development", or the other way round. The
maturity change is the last step, not the start of the stable test.

## 7. Dependency revisions: a deliberate non-pin

Confirmed again on 27 September 2026: no pinning by tag. The section below stands as the
project's position, and #70 is answered by it rather than left open.

The release lane installs STACK and its dependencies from their moving branches, not from tags.
That is a decision, not an oversight: pinning would freeze the test bed against a STACK that
keeps moving, and the value of this matrix is that it notices when STACK changes. What the run
does instead is write down which revisions it actually tested against
(`ci-logs/dependency-revisions.txt`), so a green run can be reconstructed afterwards even though
it cannot be repeated exactly.

The residual risk is stated plainly: the same plugin commit can be green today and red next week
for reasons outside this repository. The evidence file names the SHAs that were green.

## 8. Provenance

The vendored MathQuill build is identified by its fork commit, not by a branch name, and
`thirdparty/readme_moodle.txt` carries the commit, the toolchain and the checksums of the three
imported files. A rebuild from that commit has to produce them again.
