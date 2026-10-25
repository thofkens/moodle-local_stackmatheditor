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
 * The configuration page opens only the quiz's own questions (#84).
 *
 * The request names the course module and the question bank entry independently. Changing the
 * entry in the address must not open a question of another quiz - not even for an administrator,
 * whom no capability stops: the entry has to belong to the quiz.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs} = require('./helpers');

/**
 * Open the configuration page as administrator.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string} cmid Course module id.
 * @param {string} qbeid Question bank entry id.
 * @returns {Promise<?import('@playwright/test').Response>} The response.
 */
async function configure(page, cmid, qbeid) {
    await loginAs(page, env('SME_ADMIN_USER', 'admin'), env('SME_ADMIN_PASS'));
    return page.goto(`/local/stackmatheditor/configure.php?cmid=${cmid}&qbeid=${qbeid}`);
}

test('the quiz\'s own question opens with its preview', async({page}) => {
    const response = await configure(page, env('SME_SETTINGS_CMID'), env('SME_SETTINGS_QBE1'));

    expect(response.status()).toBe(200);
    // The preview sits in a collapsed section; it is rendered, not necessarily shown.
    await expect(page.locator('.que.stack')).toHaveCount(1);
    await expect(page.locator('form[action*="configure.php"]')).toHaveCount(1);
});

test('another quiz\'s question is refused, like one that does not exist', async({page}) => {
    // SME_SETTINGS_QBE1 belongs to the settings quiz; the load quiz does not use it.
    const foreign = await configure(page, env('SME_LOAD_CMID'), env('SME_SETTINGS_QBE1'));
    const foreignText = await page.locator('.errormessage').first().innerText();

    expect(foreign.status()).not.toBe(200);
    await expect(page.locator('.que.stack')).toHaveCount(0);
    await expect(page.locator('form[action*="configure.php"]')).toHaveCount(0);

    const missing = await page.goto(
        `/local/stackmatheditor/configure.php?cmid=${env('SME_LOAD_CMID')}&qbeid=987654321`
    );
    const missingText = await page.locator('.errormessage').first().innerText();

    // Same status, same message: the page does not tell foreign from missing.
    expect(missing.status()).toBe(foreign.status());
    expect(missingText.trim()).toBe(foreignText.trim());
    expect(foreignText.trim().length).toBeGreaterThan(0);
});
