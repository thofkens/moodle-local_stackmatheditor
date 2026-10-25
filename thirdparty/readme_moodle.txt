This plugin includes the following third-party library:

* MathQuill
  - Location: thirdparty/mathquill
  - Version: 0.10.1-sme.6
  - License: MPL-2.0
  - Upstream repository: https://github.com/mathquill/mathquill
  - Fork used here:      https://github.com/ralferlebach/mathquill

Source
------
MathQuill is included here as a build of a fork, not as an upstream release.

  Base:   mathquill/mathquill, branch main, commit bb9974ab
          (the build banner still reads "v0.10.1"; upstream has not tagged a
          release since 2017, so the banner is not a usable version marker.)
  Fork:   ralferlebach/mathquill
  Fork commit: d094d8a418696ab8b3e4201a2cace40e3f5ce2c6
          (branch main - informative only; the commit is what identifies this
           build, a branch moves)
  Change: editable LaTeX matrix environments (matrix, pmatrix, bmatrix,
          Bmatrix, vmatrix, Vmatrix), the public API insertMatrix /
          insertColumnVector / insertRowVector, the configuration option
          autoOperatorNamesOnlyWholeWord, and the browser test automation
          around them.

  sme.6:  keeps soft-keyboard text that follows a keydown with key
          "Unidentified" and keyCode 229 (#72). Android soft keyboards send
          that keydown before every character; the upstream guard against
          Chrome's Ctrl-Shift-U Unicode entry (ChromeOS) dropped the character.
          The guard now matches the Unicode entry only: Ctrl-Shift with
          U/Process/Unidentified, or a bare "Unidentified" without keyCode
          229. The fork's Mocha suite has four new cases (229 input, order,
          both suppressed ChromeOS variants); two of them fail without the
          change.

  sme.5:  adds matrixAtCursor() and resizeMatrix() so that a host application
          can read the matrix under the cursor and change its size, with a dry
          run that reports how many filled cells a shrink would discard (#62).

  sme.4:  makes the input event its own text-entry path (#72). Blink on
          Android delivers soft-keyboard text without a keypress, so the
          shim had never registered its typedText handler and dropped the
          first characters until Enter was pressed. An IME commit is handled
          on compositionend, character by character.

  sme.3:  gives the typed space the class mq-space, so a space the user
          entered can be made visible (#64). Without a class there is no
          stable hook: MathQuill renders it as an unclassed span with a
          non-breaking space.

  sme.2:  adds autoOperatorNamesOnlyWholeWord. With it, MathQuill accepts an
          operator name only when it covers the whole run of letters, so
          typing "Umax" stays one identifier instead of becoming U \max
          (#58, #61). The option defaults to false upstream; this plugin
          switches it on for every editable field.

The version string 0.10.1-sme.6 identifies this build; -sme.N is incremented
whenever a new fork build is imported.

Build provenance of 0.10.1-sme.6
--------------------------------
  Upstream repository: https://github.com/mathquill/mathquill
  Upstream base:       bb9974ab
  Fork repository:     https://github.com/ralferlebach/mathquill
  Fork commit:         d094d8a418696ab8b3e4201a2cace40e3f5ce2c6
                       ("fix android soft keyboard glitch", on top of 9a6ebaf4;
                       besides the change it carries the patch file and a README
                       in the repository root, which the build does not read)
  Built with:          Node 22.22.0, npm 10.9.x
  Build command:       npm ci && make
  Imported:            2026-10-08
  Verified:            rebuilt from d094d8a4; the three files below match byte
                       for byte. The fork's Mocha suite (test/unit.html) in
                       Chromium: 835 passing, 0 failing. mathquill.css and the
                       fonts are byte-identical to sme.5, mathquill.js differs
                       from sme.5 only in the changed guard
  License:             MPL-2.0

  SHA-256 of the imported runtime files:
    mathquill.js      1d28f9e6137d93930dab40b675ea2deb321aa35b697f54b0ebdead81fc61f2ec
    mathquill.min.js  46cc27cd5ffee362c6856e07826923a096c37bcba7da86dc846fc8ce7b76c283
    mathquill.css     25af0d2b872ae38cb2024599787d4617dbecfb3a0228301a8b296ed60c59cb78

  Reproduce with:
    git clone https://github.com/ralferlebach/mathquill
    cd mathquill
    git checkout d094d8a418696ab8b3e4201a2cace40e3f5ce2c6
    npm ci
    make

  Not with "git checkout feature/matrix-environments": a branch name is not a
  release pin, and a later build of it can produce different artefacts.

  The library URLs carry the build as cache key (?v=0.10.1-sme.6, constant
  editor_injector::MATHQUILL_VERSION), so a browser does not keep the previous
  build when only the library changes. Raise it together with the version
  here and in thirdpartylibs.xml.

MPL-2.0 requires modified files to be identifiable as modified. The
modification is not applied to the distributed build by hand: it lives in the
fork's source tree, and the fork's history is the record of what was changed.

Installation / update procedure
-------------------------------
The library is stored in:
local/stackmatheditor/thirdparty/mathquill

To update this library:

1. Check out the fork and build it:

     git clone https://github.com/ralferlebach/mathquill
     cd mathquill && npm ci && make

2. Note the exact commit you built (git rev-parse HEAD) - it belongs in this
   file. Then copy from the fork's build/ directory into
   local/stackmatheditor/thirdparty/mathquill:

     mathquill.js
     mathquill.min.js
     mathquill.css
     fonts/Symbola.{eot,svg,ttf,woff,woff2}

   The basic build (mathquill-basic.*) and the Symbola-basic fonts are not
   used by this plugin and are not imported.

3. Raise the -sme.N suffix here, in thirdpartylibs.xml and in
   editor_injector::MATHQUILL_VERSION, and update the SHA-256 values above.
4. Run the plugin's own test suites: a MathQuill upgrade changes the DOM the
   Behat and Playwright selectors depend on.

Note on the font directory
--------------------------
The fork's build emits fonts/ (plural); releases up to 0.10.1 used font/.
mathquill.css references fonts/ relatively, so the directory name must not be
changed on import.

Notes
-----
This library is third-party code and is not covered by the plugin copyright,
with the exception of the matrix support contributed by the plugin author to
the fork, which is likewise MPL-2.0.
