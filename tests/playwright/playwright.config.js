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
 * Playwright configuration for the local_stackmatheditor browser tests.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {defineConfig} = require('@playwright/test');

module.exports = defineConfig({
    testDir: '.',
    // The screen reader suite is in this directory too, and uses the same helpers, the same
    // seeded accounts and the same base URL (#69). It is not in the default run because NVDA
    // exists only on Windows and reads the foreground window, so it needs a visible browser and
    // a worker of its own: `npx playwright test --project=nvda`.
    testMatch: '**/*.spec.js',
    testIgnore: process.env.SME_NVDA ? [] : ['**/a11y-nvda.spec.js'],
    projects: process.env.SME_NVDA ? [
        {
            name: 'nvda',
            testMatch: '**/a11y-nvda.spec.js',
            use: {headless: false},
            timeout: 300000,
        },
    ] : undefined,
    timeout: 60000,
    expect: {timeout: 10000},
    // One retry in CI only; a flaky pass is still visible in the report.
    retries: process.env.CI ? 1 : 0,
    // PHP's built-in server is the CI target; parallel workers would only queue up behind it.
    workers: 1,
    use: {
        baseURL: process.env.SME_BASE_URL || 'http://127.0.0.1:8000',
        headless: true,
        // Captured on every run, not just on failure: a green run documents the intended journey
        // end to end, a red one can be diagnosed without reproducing it locally.
        screenshot: 'on',
        trace: 'on',
        video: 'on',
    },
    // In GitHub Actions each failure also becomes an annotation on the run page, and the JSON
    // file feeds the NVDA job summary - a red run can be read without opening a log.
    reporter: process.env.GITHUB_ACTIONS
        ? [['list'], ['html', {open: 'never'}], ['github'],
            ['json', {outputFile: 'test-results/results.json'}]]
        : [['list'], ['html', {open: 'never'}]],
});
