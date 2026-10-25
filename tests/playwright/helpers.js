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
 * Shared helpers for the local_stackmatheditor browser suites.
 *
 * Taken from moodle-plugintemplate; the awkward parts are deliberate and were each learned from a
 * failing run - see the comment on fillStable() in particular.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {expect} = require('@playwright/test');

/**
 * Read a required environment variable or fail with a clear message.
 *
 * A missing credential must not turn into a silently skipped test: a skipped test is not a
 * passing test.
 *
 * @param {string} name Variable name.
 * @param {string} [fallback] Value used when the variable is not set.
 * @returns {string} The value.
 */
function env(name, fallback) {
    const value = process.env[name] || fallback;
    if (!value) {
        throw new Error(`Environment variable ${name} is not set (see tests/playwright/README.md).`);
    }
    return value;
}

/**
 * Fill a field and make sure the value survives.
 *
 * Moodle's login password uses the `toggle_sensitive` component, whose JavaScript initialises after
 * the markup is in place and resets the field. Filling before that happens silently produced an
 * empty password: the value looked right, and the server then answered "Invalid login".
 *
 * @param {import('@playwright/test').Locator} field The input to fill.
 * @param {string} value The value to enter.
 * @returns {Promise<void>}
 */
async function fillStable(field, value) {
    let current = '';
    for (let attempt = 0; attempt < 3; attempt++) {
        await field.fill(value);
        // Give a late-initialising component the chance to reset the field before trusting it.
        await field.page().waitForTimeout(300);
        current = await field.inputValue();
        if (current === value) {
            return;
        }
    }
    throw new Error(`The field kept losing its value; it now holds "${current}".`);
}

/**
 * Log in through Moodle's login form and assert that the login succeeded.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @param {string} username The username to use.
 * @param {string} password The password to use.
 * @returns {Promise<void>}
 */
async function loginAs(page, username, password) {
    await page.goto('/login/index.php');
    await page.waitForLoadState('domcontentloaded');
    const form = page.locator('form[action*="login/index.php"]').first();
    await fillStable(form.locator('input[name="username"]'), username);
    await fillStable(form.locator('input[name="password"]'), password);
    await form.locator('#loginbtn, button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
    // Still on the login page means the credentials were rejected - say so instead of timing out later.
    await expect(page, 'Login failed - check SME_ADMIN_USER / SME_ADMIN_PASS').not.toHaveURL(/login\/index\.php/);
}

/**
 * Open a page and assert it rendered rather than redirecting to login or erroring out.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @param {string} url The URL to open.
 * @returns {Promise<void>}
 */
async function open(page, url) {
    const response = await page.goto(url);
    // Deliberately not 'networkidle': pages carrying an AJAX autocomplete keep polling and never
    // reach that state, which consumed the whole test timeout.
    await page.waitForLoadState('domcontentloaded');
    expect(response, `No response for ${url}`).not.toBeNull();
    expect(response.status(), `Unexpected HTTP status for ${url}`).toBe(200);
    await expect(page.locator('body')).not.toContainText(/Coding error|Exception|Debug info/i);
}

// Contexts opened through openPage() and not closed yet.
const opened = new Set();

/**
 * Open a page in a browser context of its own.
 *
 * Every spec that needs a second user - an administrator changing a setting, a student looking
 * at the result - used `(await browser.newContext()).newPage()` and later closed the page. That
 * leaves the context: Playwright closes only the one it created itself. With video and trace
 * switched on, every left-over context keeps a recorder running, and the browser is the same
 * one for the whole run. By the time the permissions spec asked for its next context there were
 * about ten of them, and the request was answered with "Failed to create browser context" or not
 * at all - on some runs, not on others. Contexts opened here are remembered, so they can be
 * closed whatever happens to the test.
 *
 * @param {import('@playwright/test').Browser} browser The browser.
 * @param {Object} [options] Options for browser.newContext().
 * @returns {Promise<import('@playwright/test').Page>} A page in a new context.
 */
async function openPage(browser, options) {
    const context = await browser.newContext(options);

    opened.add(context);
    return context.newPage();
}

/**
 * Close a page together with its context.
 *
 * @param {import('@playwright/test').Page} page A page from openPage().
 * @returns {Promise<void>}
 */
async function closePage(page) {
    const context = page.context();

    opened.delete(context);
    await context.close();
}

/**
 * Close every context openPage() opened and nobody closed - a test that failed half way, say.
 * For `test.afterEach` or `test.afterAll`.
 *
 * @returns {Promise<void>}
 */
async function closeLeftovers() {
    const contexts = Array.from(opened);

    opened.clear();
    await Promise.all(contexts.map((context) => context.close().catch(() => undefined)));
}

/**
 * Whether a missing fixture fails the test instead of skipping it (#89).
 *
 * The CI workflows set SME_STRICT_FIXTURES=1: there the seed promises every quiz, input and
 * setting a spec needs, so a missing one is a broken seed or a broken plugin and must turn the run
 * red. Locally, against a site seeded by hand, the same spec skips with the reason.
 *
 * @returns {boolean} True in strict mode.
 */
function strictFixtures() {
    return process.env.SME_STRICT_FIXTURES === '1';
}

/**
 * Require a fixture: fail in strict mode, skip with the reason otherwise.
 *
 * Call it inside a test or a beforeEach hook.
 *
 * @param {Object} test The Playwright test object.
 * @param {*} present Truthy when the fixture is there.
 * @param {string} what What is missing, for the message.
 * @returns {void}
 */
function requireFixture(test, present, what) {
    if (present) {
        return;
    }
    if (strictFixtures()) {
        throw new Error(`Required fixture missing: ${what}. The seed promises it for this run, `
            + 'so the test fails instead of skipping.');
    }
    test.skip(true, `fixture missing: ${what}`);
}

/**
 * Skip a test for a reason that is legitimate on some sites, also in strict mode.
 *
 * The annotation is what run-summary.js accepts as an intended skip; every other skip, and every
 * fixme, fails the summary gate in strict mode. A run that has to prove what such a test checks -
 * the Moodle 5.3 row of the Playwright workflow for the dark colour mode - sets
 * SME_NO_OPTIONAL_SKIPS=1, and the skip becomes a failure.
 *
 * @param {Object} test The Playwright test object.
 * @param {boolean} condition Skip when true.
 * @param {string} reason Why skipping is legitimate here.
 * @returns {void}
 */
function optionalSkip(test, condition, reason) {
    if (!condition) {
        return;
    }
    if (process.env.SME_NO_OPTIONAL_SKIPS === '1') {
        throw new Error(`This run has to execute the test, but it would skip: ${reason}.`);
    }
    test.info().annotations.push({type: 'optional-skip', description: reason});
    test.skip(true, reason);
}

module.exports = {
    env, fillStable, loginAs, open, openPage, closePage, closeLeftovers,
    strictFixtures, requireFixture, optionalSkip,
};
