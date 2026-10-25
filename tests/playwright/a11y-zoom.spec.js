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
 * The parts of the accessibility sample that are measurements, not judgements (#69).
 *
 * Zoom (200 and 400 per cent), viewport width, target size, keyboard reach and a visible focus
 * ring: each either holds or does not, so each belongs in the run that happens anyway rather
 * than on a checklist somebody works through by hand. No screen reader needed, so this runs in
 * the normal suite.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs, open} = require('./helpers');

test.describe.configure({mode: 'serial', timeout: 120000});

/**
 * Open an attempt as a seeded student.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @returns {Promise<void>}
 */
async function attempt(page) {
    await loginAs(page, 'sme_student13', env('SME_USER_PASS'));
    await open(page, `/mod/quiz/view.php?id=${env('SME_LOAD_CMID')}`);
    await page.getByRole('button',
        {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();

    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }

    await page.waitForSelector('.sme-mq-container, .sme-toolbar', {timeout: 60000});
    await page.waitForTimeout(1500);
}

/**
 * Anything outside its toolbar, and anything too small to hit.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @returns {Promise<Object>} What was measured.
 */
function measure(page) {
    return page.evaluate(() => {
        const overflowing = [];
        const tiny = [];

        document.querySelectorAll('.sme-toolbar').forEach((toolbar, index) => {
            const bar = toolbar.getBoundingClientRect();

            toolbar.querySelectorAll('button').forEach((button) => {
                const box = button.getBoundingClientRect();
                if (box.width === 0) {
                    return;
                }
                if (box.right > bar.right + 1 || box.left < bar.left - 1) {
                    overflowing.push(`${index}: ${button.getAttribute('aria-label') || '?'}`);
                }
                // WCAG 2.5.8 asks for 24 by 24 CSS pixels.
                if (box.width < 24 || box.height < 24) {
                    tiny.push(`${button.getAttribute('aria-label') || '?'} `
                        + `${Math.round(box.width)}x${Math.round(box.height)}`);
                }
            });
        });

        // What sticks out of the viewport to the right, and whether it is the editor's. Off-canvas
        // drawers are outside on purpose. Moodle 5.3's own page header has a .row with negative
        // margins that is 4 px wider than a 640 px viewport; that is core's to fix, not this
        // plugin's, so it is reported but does not decide the test.
        const outside = [];
        document.querySelectorAll('body *').forEach((element) => {
            const box = element.getBoundingClientRect();
            if (!box.width || box.right <= window.innerWidth + 1 || element.closest('.drawer')) {
                return;
            }
            const ours = !!element.closest('[class*="sme-"]');
            outside.push({ours, what: `${element.tagName}.${String(element.className).slice(0, 60)}`,
                right: Math.round(box.right)});
        });

        return {
            overflowing,
            tiny,
            ownOutside: outside.filter((item) => item.ours).map((item) => `${item.what} ${item.right}`),
            otherOutside: outside.filter((item) => !item.ours).map((item) => `${item.what} ${item.right}`),
            documentWidth: document.documentElement.scrollWidth,
            windowWidth: window.innerWidth,
        };
    });
}

// Browser zoom on a 1280 x 900 window is that window divided by the zoom factor in CSS pixels:
// 640 x 450 at 200 %, 320 x 225 at 400 %. 400 % is where WCAG 1.4.10 asks for reflow (320 CSS
// pixels wide); 200 % is the common case. The first version set `zoom: 200%` on the body instead,
// which scales coordinates inside the page differently from the toolbar that contains them, and
// reported every button as outside it.
for (const zoom of [200, 400]) {
    const viewport = {width: Math.round(1280 * 100 / zoom), height: Math.round(900 * 100 / zoom)};

    test(`${zoom} per cent zoom keeps everything inside the page`, async({page}) => {
        await page.setViewportSize(viewport);
        await attempt(page);

        const seen = await measure(page);

        expect(seen.overflowing, JSON.stringify(seen.overflowing)).toEqual([]);
        // WCAG 1.4.10: nothing of the editor makes the page scroll sideways.
        expect(seen.ownOutside, 'editor elements outside the viewport').toEqual([]);
        if (seen.documentWidth > seen.windowWidth + 2) {
            // The page scrolls, but not because of the editor (checked above): say what does.
            test.info().annotations.push({
                type: 'core-overflow',
                description: `${seen.documentWidth} > ${seen.windowWidth}: ${seen.otherOutside.join('; ')}`,
            });
        }
    });

    test(`the core workflow works at ${zoom} per cent zoom`, async({page}) => {
        // Reflow is half of it; the other half is that the thing still does its job: type, use
        // the toolbar, and the answer arrives where STACK reads it.
        await page.setViewportSize(viewport);
        await attempt(page);

        const question = page.locator('.que.stack').first();
        const field = question.locator('.mq-editable-field').first();

        await field.scrollIntoViewIfNeeded();
        await field.click();
        await expect(field.locator('textarea')).toBeFocused();
        await page.keyboard.type('x');

        // A toolbar button, with the mouse, where the zoomed layout has put it.
        const plus = question.locator('.sme-tb-btn').first();
        await plus.scrollIntoViewIfNeeded();
        const box = await plus.boundingBox();
        expect(box.x, 'the button is inside the zoomed viewport').toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
        await plus.click();
        await page.keyboard.type('1');
        await page.waitForTimeout(800);

        const state = await question.evaluate((que) => {
            const input = que.querySelector('input[name*="_ans"]');
            const MQ = window.MathQuill.getInterface(2);
            return {input: input.value, latex: MQ(que.querySelector('.mq-editable-field')).latex()};
        });
        expect(state.input.replace(/\s/g, '')).toBe('x+1');
        expect(state.latex.replace(/\s/g, '')).toBe('x+1');

        // The toolbar handed the focus back to the field: a deletion needs no click, and deletes
        // what was typed last.
        await page.keyboard.press('Backspace');
        await page.waitForTimeout(500);
        expect(await question.locator('input[name*="_ans"]').first().inputValue()).toBe('x+');
    });
}

test('a narrow viewport keeps the toolbar usable', async({page}) => {
    await page.setViewportSize({width: 380, height: 800});
    await attempt(page);

    const seen = await measure(page);

    expect(seen.overflowing, JSON.stringify(seen.overflowing)).toEqual([]);
    expect(seen.tiny, `targets below 24x24: ${JSON.stringify(seen.tiny)}`).toEqual([]);
});

test('the keyboard reaches the editor and the toolbar', async({page}) => {
    await attempt(page);

    const reached = {editor: false, toolbar: false, toggle: false};

    for (let i = 0; i < 250; i++) {
        await page.keyboard.press('Tab');
        const where = await page.evaluate(() => {
            const active = document.activeElement;
            if (!active) {
                return 'none';
            }
            if (active.closest('.sme-toggle')) {
                return 'toggle';
            }
            if (active.classList.contains('sme-tb-btn')) {
                return 'toolbar';
            }
            if (active.closest('.sme-mq-container')) {
                return 'editor';
            }
            return 'other';
        });

        if (where in reached) {
            reached[where] = true;
        }
        if (reached.editor && reached.toolbar) {
            break;
        }
    }

    expect(reached.editor, 'the editor must be reachable by keyboard').toBe(true);
    expect(reached.toolbar, 'the toolbar must be reachable by keyboard').toBe(true);
});

test('focus is visible wherever it lands', async({page}) => {
    await attempt(page);

    const invisible = [];
    const elsewhere = [];

    for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Tab');
        const check = await page.evaluate(() => {
            const active = document.activeElement;
            if (!active || active === document.body) {
                return null;
            }
            const style = window.getComputedStyle(active);

            return {
                label: active.getAttribute('aria-label') || active.tagName,
                // The editor's own controls; the question text around them is core's. On Moodle
                // 5.3 MathJax 4 makes every formula in the question text a tab stop with a
                // focus style of its own that this check does not recognise.
                ours: !!active.closest('[class*="sme-"], .mq-editable-field'),
                visible: (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0)
                    || (style.boxShadow && style.boxShadow !== 'none'),
            };
        });

        if (check && !check.visible) {
            (check.ours ? invisible : elsewhere).push(check.label);
        }
    }

    if (elsewhere.length) {
        test.info().annotations.push({type: 'core-focus', description: elsewhere.join('; ')});
    }
    // The browser's own focus ring counts; what fails here is an element styled until it has
    // none.
    expect(invisible, `no visible focus on: ${JSON.stringify(invisible)}`).toEqual([]);
});
