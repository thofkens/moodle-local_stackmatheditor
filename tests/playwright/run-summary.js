// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Markdown summary of a Playwright run for the GitHub job summary.
 *
 * Prints the totals, every test that did not simply pass with its first error, and - for the
 * NVDA run (#69) - every transcript. The run page shows it, so a red run can be read without
 * opening the log or downloading the artefact.
 *
 * With SME_STRICT_FIXTURES=1 (the CI workflows) it is also a gate (#89) and exits with 1 when
 *   - no result file was written or no test was collected,
 *   - a spec file of this run collected no test,
 *   - a test was skipped without the 'optional-skip' annotation (helpers.optionalSkip), or at
 *     all when SME_NO_OPTIONAL_SKIPS=1 (the run has to execute every test), or
 *   - a test is marked fixme: a known-broken path does not pass as a skip.
 * The process exit code of Playwright says that nothing failed; this says that what should have
 * run did run.
 *
 *   node run-summary.js "Playwright" >> "$GITHUB_STEP_SUMMARY"
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const fs = require('fs');
const path = require('path');

const out = [];
const problems = [];
const strict = process.env.SME_STRICT_FIXTURES === '1';
const results = path.join(__dirname, 'test-results', 'results.json');
const transcripts = path.join(__dirname, 'transcripts');

/**
 * Strip terminal colour codes and keep a message short enough for a summary.
 *
 * @param {string} text Raw error text.
 * @returns {string} Plain text.
 */
function plain(text) {
    // eslint-disable-next-line no-control-regex
    return String(text || '').replace(/\u001b\[[0-9;]*m/g, '').slice(0, 1500);
}

/**
 * Collect the specs of a suite and of everything nested in it.
 *
 * @param {Object} suite A suite from Playwright's JSON report.
 * @param {Array} into Collected specs.
 * @returns {Array} The collected specs.
 */
function specs(suite, into) {
    (suite.specs || []).forEach((spec) => into.push(spec));
    (suite.suites || []).forEach((child) => specs(child, into));
    return into;
}

out.push(`## ${process.argv[2] || 'Playwright'} run`, '', `Site: \`${process.env.SME_BASE_URL || 'not set'}\``, '');

if (fs.existsSync(results)) {
    const report = JSON.parse(fs.readFileSync(results, 'utf8'));
    const all = (report.suites || []).reduce((list, suite) => specs(suite, list), []);

    (report.errors || []).forEach((error) => {
        out.push('**Error outside a test**', '', '```', plain(error.message || error.stack), '```', '');
    });
    if (!all.length) {
        out.push('No test was collected.', '');
        problems.push('no test was collected');
    }

    // Every spec file of this run has to contribute tests: the NVDA run has its own file, the
    // default run all the others (see playwright.config.js).
    const expectedfiles = fs.readdirSync(__dirname)
        .filter((file) => file.endsWith('.spec.js'))
        .filter((file) => (process.env.SME_NVDA ? file === 'a11y-nvda.spec.js' : file !== 'a11y-nvda.spec.js'));
    expectedfiles.forEach((file) => {
        if (!all.some((spec) => path.basename(spec.file || '') === file && (spec.tests || []).length)) {
            problems.push(`${file} collected no test`);
        }
    });
    const stats = report.stats || {};
    out.push(`Passed ${stats.expected || 0}, failed ${stats.unexpected || 0}, `
        + `flaky ${stats.flaky || 0}, skipped ${stats.skipped || 0}.`, '');

    all.forEach((spec) => {
        (spec.tests || []).forEach((test) => {
            const runs = test.results || [];
            // "expected" is a plain pass; everything else is worth a line.
            if (test.status === 'expected') {
                return;
            }
            if (test.status === 'skipped') {
                const annotations = (test.annotations || [])
                    .concat(...runs.map((run) => run.annotations || []));
                const types = annotations.map((annotation) => annotation.type);
                if (types.includes('fixme')) {
                    problems.push(`fixme: ${spec.file || ''} - ${spec.title}`);
                } else if (!types.includes('optional-skip') || process.env.SME_NO_OPTIONAL_SKIPS === '1') {
                    const reason = (annotations.find((annotation) => annotation.type === 'skip') || {}).description;
                    problems.push(`unexpected skip: ${spec.file || ''} - ${spec.title}`
                        + (reason ? ` (${reason})` : ''));
                }
            }
            out.push(`### ${test.status}: ${spec.file || ''} - ${spec.title}`, '');
            runs.forEach((run, index) => {
                const error = (run.errors || [])[0] || run.error;
                if (error) {
                    out.push(`Attempt ${index + 1} (${run.status}, ${run.duration} ms)`, '',
                        '```', plain(error.message || error.stack), '```', '');
                }
            });
        });
    });
} else {
    out.push('Playwright wrote no result file: the run ended before a test started.', '');
    problems.push('no result file');
}

if (problems.length) {
    out.push(strict ? '### Gate: failed' : '### Gate (informational, SME_STRICT_FIXTURES is not set)', '');
    problems.forEach((problem) => out.push(`* ${problem}`));
    out.push('');
} else {
    out.push('### Gate: every spec collected tests, no unexpected skip, no fixme', '');
}

if (fs.existsSync(transcripts)) {
    fs.readdirSync(transcripts).sort().forEach((file) => {
        out.push(`### Transcript: ${file}`, '', '```',
            fs.readFileSync(path.join(transcripts, file), 'utf8').slice(0, 6000), '```', '');
    });
} else if (process.env.SME_NVDA) {
    out.push('NVDA recorded no transcript.', '');
}

process.stdout.write(out.join('\n') + '\n');
if (strict && problems.length) {
    process.stderr.write(`Run summary gate failed: ${problems.join('; ')}\n`);
    process.exitCode = 1;
}
