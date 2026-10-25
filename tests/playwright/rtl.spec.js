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
 * Right-to-left pages (re-audit 2026-10-08, item 7).
 *
 * The seed installs a minimal right-to-left language pack ("he", only its langconfig.php), so the
 * page is rendered the way Moodle renders Hebrew or Arabic: dir="rtl" and the flipped theme
 * stylesheet. On it:
 *   - the toolbar follows the page (first group on the right, keyboard order right to left), but
 *     what is mathematics stays left to right - the formula, the symbols on the buttons, the rows
 *     and columns of the matrix chooser;
 *   - the matrix and vector choosers open under their button on the side the page reads from and
 *     stay in the window;
 *   - nothing of the editor reaches out of the viewport, axe finds nothing, and the switch turns
 *     the editor off (STACK's input with the answer, inside the window) and on again;
 *   - the configuration page reads right to left and its preview opens.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {env, loginAs, open, requireFixture} = require('./helpers');

test.describe.configure({mode: 'serial', timeout: 120000});

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Open an attempt in the right-to-left language.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @returns {Promise<void>}
 */
async function attempt(page) {
    await page.setViewportSize({width: 1100, height: 900});
    await loginAs(page, 'sme_student18', env('SME_USER_PASS'));
    await open(page, `/mod/quiz/view.php?id=${env('SME_LOAD_CMID')}&lang=he`);
    await page.getByRole('button', {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForSelector('.sme-toolbar', {timeout: 60000});
    await page.waitForTimeout(1500);
    requireFixture(test, await page.locator('html[dir="rtl"]').count() > 0,
        'the right-to-left language pack "he" (seed.php writes it into the dataroot)');
    // Resting state for axe: the mouse is still where "Start attempt" was clicked.
    await page.mouse.move(0, 0);
}

test('the editor in a right-to-left page', async({page}, info) => {
    await attempt(page);

    const layout = await page.evaluate(() => {
        const question = document.querySelector('.que.stack');
        const toolbar = question.querySelector('.sme-toolbar');
        const bar = toolbar.getBoundingClientRect();
        const groups = Array.from(toolbar.querySelectorAll('.sme-tb-group'));
        const outside = [];
        document.querySelectorAll('[class*="sme-"]').forEach((element) => {
            const box = element.getBoundingClientRect();
            if (box.width && (box.right > window.innerWidth + 1 || box.left < -1)) {
                outside.push(String(element.className).slice(0, 60));
            }
        });
        const overflowing = [];
        toolbar.querySelectorAll('button').forEach((button) => {
            const box = button.getBoundingClientRect();
            if (box.right > bar.right + 1 || box.left < bar.left - 1) {
                overflowing.push(button.getAttribute('aria-label'));
            }
        });
        const first = groups[0].getBoundingClientRect();
        const button = toolbar.querySelector('.sme-tb-btn');
        const field = question.querySelector('.sme-mq-container .mq-editable-field');
        return {
            toolbarDirection: getComputedStyle(toolbar).direction,
            firstGroupGap: Math.round(bar.right - first.right),
            firstGroupLeftOfBar: Math.round(first.left - bar.left),
            buttonDirection: getComputedStyle(button).direction,
            fieldDirection: getComputedStyle(field).direction,
            outside,
            overflowing,
            toggle: !!question.querySelector('.sme-toggle input'),
        };
    });
    await info.attach('rtl-attempt', {body: await page.locator('.que.stack').first().screenshot(), contentType: 'image/png'});

    expect(layout.toolbarDirection, 'the toolbar follows the page').toBe('rtl');
    expect(layout.firstGroupGap, 'the first group starts on the right').toBeLessThan(20);
    expect(layout.buttonDirection, 'button symbols are mathematics, left to right').toBe('ltr');
    expect(layout.fieldDirection, 'the formula is written left to right').toBe('ltr');
    expect(layout.outside, 'editor elements outside the viewport').toEqual([]);
    expect(layout.overflowing, 'buttons outside their toolbar').toEqual([]);
    expect(layout.toggle, 'the switch is there').toBe(true);

    // Keyboard order follows the reading direction: each Tab lands further to the left.
    const question = page.locator('.que.stack').first();
    await question.locator('.sme-tb-btn').first().focus();
    const xs = [];
    for (let i = 0; i < 3; i++) {
        xs.push(await page.evaluate(() => document.activeElement.getBoundingClientRect().left));
        await page.keyboard.press('Tab');
    }
    expect(xs[1], 'second button left of the first').toBeLessThan(xs[0]);
    expect(xs[2], 'third button left of the second').toBeLessThan(xs[1]);

    // Typing: the formula keeps its order, in the editor and in what STACK receives.
    const field = question.locator('.sme-mq-container .mq-editable-field').first();
    await field.evaluate((el) => window.MathQuill.getInterface(2)(el).latex(''));
    await field.click();
    await page.keyboard.type('(x-1)*2');
    await page.waitForTimeout(800);
    const typed = await question.locator('input[name*="_ans"]').first().inputValue();
    expect(typed.replace(/\s/g, '')).toBe('(x-1)*2');

    const violations = (await new AxeBuilder({page}).withTags(WCAG)
        .include('.sme-toolbar').include('.sme-mq-container').include('.sme-toggle').analyze()).violations;
    expect(violations.map((v) => v.id), JSON.stringify(violations.map((v) => v.nodes.map((n) => n.target)))).toEqual([]);

    // The switch: off brings back STACK's own input with the answer, inside the window; on again
    // brings the editor back.
    const toggle = question.locator('.sme-toggle input[type="checkbox"]').first();
    await toggle.click();
    const original = question.locator('input[name*="_ans"]').first();
    await expect(original).toBeVisible();
    const box = await original.boundingBox();
    expect(box.width, 'the plain input has its width again').toBeGreaterThan(20);
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(1100);
    expect((await original.inputValue()).replace(/\s/g, '')).toBe('(x-1)*2');
    await toggle.click();
    await expect(question.locator('.sme-toolbar').first()).toBeVisible();
});

test('the matrix and vector choosers in a right-to-left page', async({page}, info) => {
    await attempt(page);
    const question = page.locator('.que.stack').first();
    const chooser = question.locator('.sme-tb-btn[data-command="matrix"]').first();
    requireFixture(test, await chooser.count() > 0, 'the matrix chooser (the a11y spec switches every group on)');

    await chooser.scrollIntoViewIfNeeded();
    await chooser.click();
    const popup = page.locator('.sme-matrix-popup');
    await expect(popup).toBeVisible();
    await info.attach('rtl-matrix', {body: await page.screenshot(), contentType: 'image/png'});

    const place = await page.evaluate(() => {
        const box = document.querySelector('.sme-matrix-popup').getBoundingClientRect();
        const owner = document.querySelector('.sme-tb-btn[aria-expanded="true"]').getBoundingClientRect();
        const cells = Array.from(document.querySelectorAll('.sme-matrix-grid-cell'));
        const first = cells.find((c) => c.dataset.row === '1' && c.dataset.column === '1').getBoundingClientRect();
        const second = cells.find((c) => c.dataset.row === '1' && c.dataset.column === '2').getBoundingClientRect();
        return {
            right: Math.round(box.right), left: Math.round(box.left), ownerRight: Math.round(owner.right),
            width: window.innerWidth, firstColumnLeft: first.left, secondColumnLeft: second.left,
        };
    });
    expect(Math.abs(place.right - place.ownerRight), 'opens under its button, aligned to its right edge').toBeLessThanOrEqual(2);
    expect(place.left).toBeGreaterThanOrEqual(0);
    expect(place.right).toBeLessThanOrEqual(place.width);
    expect(place.secondColumnLeft, 'columns run left to right, as in the matrix').toBeGreaterThan(place.firstColumnLeft);

    // The arrow keys go where they point: right adds a column.
    const grid = page.locator('.sme-matrix-grid');
    await grid.focus();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('.sme-structured-popup-label')).toContainText('2');
    const selected = await page.locator('.sme-matrix-grid-cell.sme-selected').count();
    expect(selected, 'a 2 x 2 block').toBe(4);
    await page.keyboard.press('Escape');
    await expect(popup).toHaveCount(0);

    // The vector chooser opens the same way.
    const vector = question.locator('.sme-tb-btn[data-command="vector"]').first();
    requireFixture(test, await vector.count() > 0, 'the vector chooser (same group as the matrix chooser)');
    await vector.click();
    await expect(page.locator('.sme-vector-popup')).toBeVisible();
    const vplace = await page.evaluate(() => {
        const box = document.querySelector('.sme-vector-popup').getBoundingClientRect();
        const owner = document.querySelector('.sme-tb-btn[aria-expanded="true"]').getBoundingClientRect();
        return {right: Math.round(box.right), left: Math.round(box.left), ownerRight: Math.round(owner.right),
            width: window.innerWidth, direction: getComputedStyle(document.querySelector('.sme-vector-popup')).direction};
    });
    await info.attach('rtl-vector', {body: await page.screenshot(), contentType: 'image/png'});
    expect(Math.abs(vplace.right - vplace.ownerRight), 'opens under its button, aligned to its right edge').toBeLessThanOrEqual(2);
    expect(vplace.left).toBeGreaterThanOrEqual(0);
    expect(vplace.right).toBeLessThanOrEqual(vplace.width);
    await page.keyboard.press('Escape');
});

test('the configuration page in a right-to-left page', async({page}, info) => {
    await page.setViewportSize({width: 1100, height: 900});
    await loginAs(page, 'sme_teacher', env('SME_USER_PASS'));
    await open(page, `/local/stackmatheditor/configure.php?cmid=${env('SME_SETTINGS_CMID')}`
        + `&qbeid=${env('SME_SETTINGS_QBE1')}&lang=he`);
    requireFixture(test, await page.locator('html[dir="rtl"]').count() > 0, 'the right-to-left language pack "he"');

    const button = page.locator('a[href="#sme-question-preview"]');
    await button.click();
    await expect(page.locator('#sme-question-preview')).toBeVisible();
    await info.attach('rtl-configure', {body: await page.screenshot({fullPage: true}), contentType: 'image/png'});

    const width = await page.evaluate(() => ({doc: document.querySelector('#region-main').scrollWidth,
        view: document.querySelector('#region-main').clientWidth}));
    expect(width.doc, 'the form does not scroll sideways').toBeLessThanOrEqual(width.view + 2);

    const violations = (await new AxeBuilder({page}).withTags(WCAG)
        .include('#region-main form.mform').exclude('#sme-question-preview').analyze()).violations;
    expect(violations.map((v) => v.id), JSON.stringify(violations.map((v) => v.nodes.map((n) => n.target)))).toEqual([]);
});
