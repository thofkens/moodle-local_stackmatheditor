# Residual risks of 1.3.0 (build 2026100800)

#71: "open P1 are closed or formally accepted as a residual risk". This file describes every
point a release of this build goes out with although it is not closed, why it is tolerable and
what limits it; who accepted it when is recorded in `docs/RELEASE-SIGNOFF.json`. A risk without
a name and a date there is not accepted - it is open, and the release workflow refuses to
publish.

| # | Risk | Issue | Why tolerable | What limits it | Closed by |
|---|---|---|---|---|---|
| R1 | No person has yet judged whether the NVDA output can be worked with (order, wording, nothing read twice) | #69 | Every announcement a student depends on is checked on every release candidate: `a11y-nvda.yml` fails when the field, the switch, the toolbar or the matrix chooser is silent, and axe, keyboard and zoom checks run in `playwright.yml` | The judgement is a reading of transcripts that already exist; it can be made after the release and a finding becomes a patch release | Section 1 of `docs/MANUAL-ACCEPTANCE.md`, comment in #69 |
| R2 | A release can still be made outside the gate by uploading an archive to the Moodle plugins directory by hand | #69 | GitHub releases cannot: `release-artefact.yml` publishes only a tested ZIP of a green commit, and `release-guard.yml` turns any release published by hand back into a draft. The plugins directory has no API a workflow could guard | The upload is one person's step; the evidence (`manual-checklist.txt`) asks for the SHA-256 of the uploaded archive to be compared with the one the release publishes | Accepted permanently while the plugins directory offers no upload hook |
| R3 | Android Opera and Firefox Klar have not been tried on a real device | #72 | The cause was in MathQuill's keyboard layer, shared by every engine; the fix is tested with the exact event sequence Android sends (keyCode 229, key "Unidentified") in Chromium, one-line and multi-line, Backspace included. Opera is Blink like Chrome, Firefox Klar is Gecko like Firefox, and both of those were confirmed on devices | A failure would show as the old symptom (first characters lost until Enter) with a known workaround, not as wrong answers being submitted | Section 2 of `docs/MANUAL-ACCEPTANCE.md`, comment in #72 |
| R4 | The test bed installs STACK from its moving branches, so a green run cannot be repeated exactly | #70 | Deliberate: the matrix is meant to notice when STACK changes (`docs/RELEASE-CHECKLIST.md`, section 7) | Each run writes the STACK revisions it tested into `ci-logs/dependency-revisions.txt` | Accepted permanently, 27 September 2026 |
| R5 | One configuration per scope is enforced by the plugin, not by a unique index | #87 | A unique index cannot express the rule on the nullable column (`docs/DATA-INTEGRITY.md`); the lock and transaction are tested with concurrent processes on PostgreSQL and MariaDB in every main CI run and against the release ZIP | Reads are deterministic even with duplicates, and every write and the upgrade repair them | Decision record `docs/DATA-INTEGRITY.md` |
| R6 | Two builds carry the release name 1.3.0: 2026100700, already published, and 2026100800 | - | The release name was kept on purpose; Moodle decides upgrades by the build number, which is higher, so every site with 2026100700 is offered the upgrade and runs its repair step | `docs/CHANGES.md` names both builds; the release page and the archive name carry the build number | Decision by the maintainer, 8 October 2026 |

## Acceptance

Recorded in `docs/RELEASE-SIGNOFF.json`, one item per risk: `status` (`open`, `done` for a
manual check that was made, `accepted` for an accepted risk), `date` and `by`. The main CI
checks the file's format; `release-artefact.yml` refuses a stable tag while any item is open
(`.github/check-signoff.py --complete`). So a risk without a name and a date does not only wait
on paper - the release cannot be published.

State of build 2026100800: R4 and R6 accepted; R1, R2, R3 and R5 open.

Once a risk is closed (R1 and R3 by the manual acceptance), its row moves to the change log of
the next release, and this file keeps only what is still open.
