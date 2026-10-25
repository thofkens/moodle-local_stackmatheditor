# Manual acceptance - what a person has to do and write down

Two checks of the release cannot be made by a workflow: whether what a screen reader says can be
worked with (#69), and whether real Android keyboards type into a fresh field (#72). Both are
done once per release candidate, on the commit that the release evidence certifies, and the
result goes into the issue named next to them as a comment with the filled-in table. The release
evidence (`release-evidence.yml`, file `manual-checklist.txt`) repeats the lines to sign.

Everything that can be measured is measured elsewhere and does not need repeating by hand:
zoom 200 and 400 per cent with the core workflow, a 380 pixel viewport, target sizes, keyboard
reach and visible focus (`a11y-zoom.spec.js`); the keyCode 229 / "Unidentified" path in one-line,
multi-line and Backspace variants (`android-input.spec.js`); what NVDA announces at all
(`a11y-nvda.spec.js`, CI workflow `a11y-nvda.yml`).

## 1. Screen reader judgement (#69)

**Material:** the transcripts of the `a11y-nvda.yml` run on the certified commit (artifact
`a11y-nvda-<run id>`, folder `transcripts`), one file per flow, commit and time at the top.

**Optionally live:** the development site, NVDA with Chrome or Firefox on Windows, a student
account of the seed, quiz "SME Load Quiz".

| # | Flow | What to judge | Result |
|---|---|---|---|
| 1 | Focus the answer field | The field is announced as a math input and its question is clear from the context | |
| 2 | Type `x^2+1`, then arrow back through it | What is read is the expression, not markup; nothing is read twice | |
| 3 | Switch the editor off and on | The switch says what it does and its state; the answer is still there | |
| 4 | Toolbar: Tab to it, arrow through a group, activate "fraction" | Buttons have a name that says what they insert; the order follows the screen | |
| 5 | Matrix chooser: open, choose 2 x 3 with the arrows, Enter, Escape | Dialog, table and the chosen size are announced; Escape returns to the button | |
| 6 | Check the answer | STACK's validation is read after the input, not before | |

Record: date, browser and version, NVDA version, person, and for each row "ok" or what was
heard instead. A row that is not ok is a finding with its own issue, not a footnote.

## 2. Android devices (#72)

**Setup:** any quiz of the development site with an algebraic input, a student account, the page
opened fresh (not restored from the tab cache).

For each browser, in a **freshly focused** field, without pressing Enter first:

| # | Step | Expected |
|---|---|---|
| a | Tap the field, type `x+1` on the soft keyboard | `x+1` appears at once, each character once |
| b | Type `2`, then Backspace | one character removed, not two |
| c | Swipe or predictive input of a word, e.g. `sin` | the word appears once |
| d | Tap a toolbar button (fraction), type into it | the structure appears, the input goes into it |
| e | Switch to a second input of the same question, type | works the same without a gesture first |
| f | Check the answer | STACK validates what was typed |

| Browser | Device / Android | Keyboard | a | b | c | d | e | f | Date, by |
|---|---|---|---|---|---|---|---|---|---|
| Chrome | | Gboard | | | | | | | |
| Opera | | Gboard | | | | | | | |
| Firefox | | Gboard | | | | | | | |
| Firefox Klar (Focus) | | Gboard | | | | | | | |
| Chrome | | SwiftKey or Samsung keyboard | | | | | | | |

The last row covers an alternative input method; any keyboard other than Gboard will do. Chrome
and Firefox are already ticked in #72; Opera and Firefox Klar are still open there.

## 3. Where the result goes

* Comment on #69 (section 1) and #72 (section 2) with the filled-in table.
* Set the item in `docs/RELEASE-SIGNOFF.json` to `done` with `date` and `by` (`nvda-judgement`,
  `android-devices`).
* Tick the matching items in the issue; close it when nothing is left.
* If a check is not done before a release, the residual risk in `docs/RESIDUAL-RISKS.md` stands
  instead: the item is set to `accepted` with a date and a name. While an item is `open`,
  `release-artefact.yml` refuses a stable tag.
