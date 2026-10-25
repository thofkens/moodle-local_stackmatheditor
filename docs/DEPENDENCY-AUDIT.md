# Dependency audit - 1.3.0

Recorded 6 October 2026 against version 2026100603, with `npm audit` on the two lock files the
repository carries (#69). Neither package is part of the plugin a site installs: everything under
`tests/` is excluded from the release archive by `.gitattributes`.

## What would ship

    tests/jest        npm audit --omit=dev    0 vulnerabilities
    tests/playwright  npm audit --omit=dev    0 vulnerabilities

No runtime dependency of the plugin comes from npm. The JavaScript a browser receives is the
plugin's own `amd/build` and the vendored MathQuill build, whose provenance is recorded in
`thirdparty/readme_moodle.txt`.

## Development tools

### tests/playwright - 0 findings

The audit found one critical (`decompress`) and one moderate finding, both arriving through
`@guidepup/setup`. That package is a command-line tool, not something the tests import: it was
removed as a dependency, and the NVDA workflow and a local run call it through `npx` with a pinned
version. With `@guidepup/guidepup` 0.33.0 and `@guidepup/playwright` 0.19.1 the tree has ten
packages and no finding.

### tests/jest - 34 findings, accepted

All 34 trace back to two packages deep inside Jest 29:

| Advisory | Package | Severity | Path | Fixed version |
|---|---|---|---|---|
| [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) | braces 3.0.3 | high | jest > @jest/core > micromatch > braces | none (all versions ≤ 3.0.3 affected) |
| [GHSA-hp3w-g68c-fv3c](https://github.com/advisories/GHSA-hp3w-g68c-fv3c) | sprintf-js 1.0.3 | moderate | jest > babel-plugin-istanbul > js-yaml > argparse > sprintf-js | none (all versions ≤ 1.1.3 affected) |

The other 32 entries are the Jest packages that depend on these two and inherit the rating.

**Assessment:** accepted for development use. Both are denial-of-service issues that need crafted
input - a pathological glob pattern for braces, a hostile format string for sprintf-js. The test
runner only ever processes this repository's own configuration and test files, never untrusted
input, and it never runs on a site. There is no patched version of either package to move to;
`npm audit fix` changes nothing, and `--force` would mean a Jest major release the suite is not
written for.

**Not a runtime blocker.** The release evidence workflow gates on the runtime audit only
(`--omit=dev`) and records the full audit as evidence.

## Re-running

    cd tests/jest && npm audit
    cd tests/playwright && npm audit

Revisit when Jest publishes a release with patched dependencies, or when either advisory gains a
fixed version.
