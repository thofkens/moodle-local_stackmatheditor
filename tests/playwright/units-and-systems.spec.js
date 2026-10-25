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
 * The two input shapes nothing else in the browser suite reached (#77 item 18, #72 item 8).
 *
 * A STACK units input, and the editor's system lines - several relations joined by nounand, which
 * the editor builds when the stored answer is a system. Both go through the same input layer as
 * everything else; these tests are there so that "the same layer" is a result, not an argument.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs, open, requireFixture} = require('./helpers');

test.describe.configure({mode: 'serial', timeout: 150000});

/**
 * Open an attempt of the given quiz.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string} user Seeded username.
 * @param {string} cmid Course module id.
 * @returns {Promise<void>}
 */
async function attempt(page, user, cmid) {
    await loginAs(page, user, env('SME_USER_PASS'));
    await open(page, `/mod/quiz/view.php?id=${cmid}`);
    await page.getByRole('button',
        {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForSelector('.sme-mq-container', {timeout: 60000});
    await page.waitForTimeout(1500);
}

/**
 * The editor's LaTeX and the original input's value for the first question.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @returns {Promise<Object>} {latex, input}.
 */
function firstQuestion(page) {
    return page.evaluate(() => {
        const question = document.querySelector('.que');
        const input = question.querySelector('input[name*="_ans"]');
        const field = question.querySelector('.mq-editable-field');
        const MQ = window.MathQuill.getInterface(window.MathQuill.getInterface.MAX || 2);
        return {latex: field ? MQ(field).latex() : null, input: input ? input.value : null};
    });
}

test('#77/18: a units input takes typed values and adopts external ones', async({page}) => {
    requireFixture(test, Number(process.env.SME_UNITS_CMID || 0),
        'the units quiz (SME_UNITS_CMID) - see the seed step for the reason');
    await attempt(page, 'sme_student15', env('SME_UNITS_CMID'));

    // Typing: the editor writes a quantity STACK can read as a value with units.
    await page.locator('.que').first().locator('.mq-editable-field').click();
    await page.keyboard.type('9.81*m/s^2');
    await page.waitForTimeout(800);

    let state = await firstQuestion(page);
    expect(state.input.replace(/\s/g, '')).toContain('9.81');
    expect(state.input).toMatch(/m/);

    // An external write - the same path a JSXGraph binding takes - reaches the editor.
    await page.evaluate(() => {
        const input = document.querySelector('.que input[name*="_ans"]');
        input.value = '3*kg';
        input.dispatchEvent(new Event('change'));
    });
    await page.waitForTimeout(800);

    state = await firstQuestion(page);
    expect(state.input).toBe('3*kg');
    expect(state.latex.replace(/\s/g, '')).toMatch(/3.*kg/);
});

test('#72/8: system lines are built from a stored system and write it back', async({page}) => {
    await attempt(page, 'sme_student16', env('SME_LOAD_CMID'));

    // Store a system the way a submitted answer would be stored, and reload: the editor reads
    // the stored value at start and builds one line per relation.
    await page.evaluate(() => {
        const input = document.querySelector('.que input[name*="_ans"]');
        input.value = '(x+y=3) nounand (x-y=1)';
        input.dispatchEvent(new Event('change'));
    });
    // STACK's Check is a submit input inside the question. Pressing it from the page avoids
    // Playwright waiting for it to be unobstructed - the first run timed out on exactly that -
    // and the page comes back with the stored answer.
    await Promise.all([
        page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 60000}),
        page.evaluate(() => {
            const question = document.querySelector('.que');
            const check = question.querySelector('input[type="submit"][name$="-submit"]')
                || question.querySelector('button[type="submit"], input[type="submit"]');
            check.click();
        }),
    ]);
    await page.waitForSelector('.sme-mq-container', {timeout: 60000});
    await page.waitForTimeout(2000);

    const rows = await page.evaluate(() => {
        const question = document.querySelector('.que');
        return question.querySelectorAll('.mq-editable-field').length;
    });
    expect(rows, 'one line per relation').toBeGreaterThanOrEqual(2);

    // Edit the second line the way Blink's soft keyboard does (#72): the character arrives as an
    // insertText input from the browser's own pipeline, with no keypress.
    await page.locator('.que').first().locator('.mq-editable-field textarea').nth(1).focus();
    for (const ch of '+0') {
        await page.keyboard.insertText(ch);
        await page.waitForTimeout(40);
    }
    await page.waitForTimeout(800);

    const state = await firstQuestion(page);
    expect(state.input).toMatch(/nounand/);
    expect(state.input.replace(/\s/g, '')).toContain('+0');
});
