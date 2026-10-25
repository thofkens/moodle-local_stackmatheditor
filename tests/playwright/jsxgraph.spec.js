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
 * Issue #77: MathQuill, the original STACK input and JSXGraph have to agree.
 *
 * The question behind this file is the one the bug was reported with: four algebraic inputs
 * bound to four sliders through `stack_jxg.bind_slider()`. Moving a slider writes into the
 * original input and dispatches a change event that does not bubble.
 *
 * Nothing here knows anything about JSXGraph beyond how to grab a slider and drag it. The
 * editor has no JSXGraph-specific code either, which is the point: what is tested is that an
 * external script writing into the input reaches the visible editor.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs, openPage, closePage, requireFixture} = require('./helpers');

// Local reference: 40-70 s per test. A slider question is slow to instantiate.
test.describe.configure({mode: 'serial', timeout: 180000});

/**
 * Switch the editor on for everybody.
 *
 * @param {Browser} browser Playwright browser.
 * @returns {Promise<void>}
 */
async function enableEditor(browser) {
    const admin = await openPage(browser);

    await loginAs(admin, env('SME_ADMIN_USER', 'admin'), env('SME_ADMIN_PASS'));
    await admin.goto('/admin/settings.php?section=local_stackmatheditor');
    await admin.locator('select[name="s_local_stackmatheditor_enabled"]').selectOption('1');
    await admin.getByRole('button', {name: 'Save changes'}).click();
    await closePage(admin);
}

/**
 * The frame that holds the JSXGraph board.
 *
 * STACK renders a [[jsxgraph]] block inside an iframe of its own - `[[iframe]]` with the board in
 * `#jxgbox` and JSXGraph loaded into that frame, not into the page. That is why the first versions
 * of this spec found neither `.jxgbox` nor `window.JXG`: both exist, one document further down.
 * The slider writes into the STACK input in the parent through `stack_js`, which then dispatches
 * the non-bubbling change event #77 is about.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @returns {Promise<?import('@playwright/test').Frame>} The board frame, or null.
 */
async function boardFrame(page) {
    // Up to a minute: the iframe loads JSXGraph from a CDN before it can draw anything.
    for (let attempt = 0; attempt < 120; attempt++) {
        for (const frame of page.frames()) {
            if (frame === page.mainFrame()) {
                continue;
            }
            // Any frame with a board counts - STACK names the board element per block, so
            // looking for one fixed id was one assumption too many. The registry is JXG.boards;
            // JXG.JSXGraph.boards exists too but stays empty (JSXGraph 1.12).
            const ready = await frame.evaluate(
                () => !!(window.JXG && Object.keys(window.JXG.boards || {}).length)
            ).catch(() => false);
            if (ready) {
                return frame;
            }
        }
        await page.waitForTimeout(500);
    }

    // Nothing found: record what there was, so the next run is not another guess.
    const frames = await Promise.all(page.frames().map(async(frame) => {
        const info = await frame.evaluate(() => ({
            jxg: typeof window.JXG,
            divs: Array.from(document.querySelectorAll('div[id]')).map((d) => d.id).slice(0, 6),
            scripts: Array.from(document.scripts).map((x) => x.src).filter(Boolean).slice(0, 4)
        })).catch((e) => ({error: String(e).slice(0, 80)}));
        return {url: frame.url().slice(0, 90), ...info};
    }));
    console.log('jsxgraph frames: ' + JSON.stringify(frames));
    return null;
}

/**
 * Open the JSXGraph question as a student and wait for its board.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @returns {Promise<?import('@playwright/test').Frame>} The board frame.
 */
async function openQuestion(page) {
    await loginAs(page, 'sme_student06', env('SME_USER_PASS'));
    await page.goto('/mod/quiz/view.php?id=' + env('SME_JSXGRAPH_CMID'));
    await page.getByRole('button',
        {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForSelector('.sme-toolbar', {timeout: 60000});

    const frame = await boardFrame(page);
    console.log('jsxgraph board frame: ' + (frame ? frame.url().slice(0, 80) : 'none'));
    return frame;
}

/**
 * Where a slider's handle is on the page, and what it reads.
 *
 * The board reports screen coordinates inside its own frame; the frame element's position on the
 * page is added so the mouse lands where the handle is drawn.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {import('@playwright/test').Frame} frame The board frame.
 * @param {number} index Which slider, in creation order.
 * @returns {Promise<?Object>} {x, y, value, count} in page coordinates.
 */
async function sliderAt(page, frame, index) {
    const inner = await frame.evaluate((which) => {
        const board = Object.values(window.JXG.boards)[0];
        const sliders = board.objectsList.filter((o) => o.elType === 'slider');
        const slider = sliders[which];
        if (!slider) {
            return null;
        }
        const box = board.containerObj.getBoundingClientRect();
        return {
            x: box.left + slider.coords.scrCoords[1],
            y: box.top + slider.coords.scrCoords[2],
            value: slider.Value(),
            count: sliders.length
        };
    }, index);

    if (!inner) {
        return null;
    }

    const element = await frame.frameElement();

    // The mouse works in viewport coordinates, and the board is below the fold on a default
    // window: bring the handle to the middle of the viewport before reading where it is.
    // 'instant': Bootstrap 5 (Moodle 5.x) sets scroll-behavior: smooth on the root, and a
    // smooth scroll is still moving when the handle's position is read - the drag then misses.
    await element.evaluate((iframe, y) => {
        const top = iframe.getBoundingClientRect().top + y;
        window.scrollBy({top: top - window.innerHeight / 2, left: 0, behavior: 'instant'});
    }, inner.y);
    await page.waitForTimeout(300);

    const offset = await element.boundingBox();

    return {
        x: offset.x + inner.x,
        y: offset.y + inner.y,
        value: inner.value,
        count: inner.count
    };
}

/**
 * Drag a slider handle by a horizontal distance, the way a student does.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {Object} handle Result of sliderAt().
 * @param {number} dx Pixels to the right.
 * @returns {Promise<void>}
 */
async function drag(page, handle, dx) {
    await page.mouse.move(handle.x, handle.y);
    await page.mouse.down();
    await page.mouse.move(handle.x + dx, handle.y, {steps: 8});
    await page.mouse.up();
    await page.waitForTimeout(800);
}

/**
 * What the editor and the original input hold for one answer.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string} name Input name suffix, e.g. 'ans1'.
 * @returns {Promise<Object>} {input, latex}.
 */
function answerState(page, name) {
    return page.evaluate((inputname) => {
        const input = document.querySelector('input[name$="_' + inputname + '"]');
        if (!input) {
            return {input: null, latex: null};
        }
        // The editor is inserted immediately before the input it replaces, not around it.
        const wrap = input.previousElementSibling;
        const field = wrap && wrap.classList.contains('sme-input-wrap')
            ? wrap.querySelector('.mq-editable-field') : null;
        const MQ = window.MathQuill
            ? window.MathQuill.getInterface(window.MathQuill.getInterface.MAX || 2)
            : null;
        const mq = MQ && field ? MQ(field) : null;

        return {input: input.value, latex: mq ? mq.latex() : null};
    }, name);
}

/**
 * The numbers in a value, so 0.5 matches "0.5" in Maxima and "0.5" in LaTeX alike.
 *
 * @param {string} text Any text.
 * @returns {string} Digits, dots and minus signs only.
 */
function digits(text) {
    return String(text || '').replace(/[^0-9.\-]/g, '');
}

/**
 * Type into one of the editors.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string} name Input name suffix.
 * @param {string} text What to type.
 * @returns {Promise<void>}
 */
async function typeInto(page, name, text) {
    const field = page.locator(
        '.sme-input-wrap:has(+ input[name$="_' + name + '"]) .mq-editable-field'
    ).first();
    // The page has just been scrolled to the board, and scrolling back is animated: a click sent
    // while the page was still moving landed on the board's iframe, and everything typed after it
    // went there. Scroll first, let it settle, and do not type until the editor has the focus.
    await field.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    await field.click();
    await expect(field.locator('textarea')).toBeFocused();
    await page.keyboard.press('Control+A');
    await page.keyboard.press('Backspace');
    await page.keyboard.type(text);
    await page.waitForTimeout(800);
}

// The seed leaves the id at 0 when the JSXGraph quiz could not be built. In CI that is a failure
// (the seed promises the quiz), locally the spec skips with the reason (#89).
test.beforeEach(() => {
    requireFixture(test, Number(process.env.SME_JSXGRAPH_CMID || 0),
        'the JSXGraph quiz (SME_JSXGRAPH_CMID) - see the seed step for the reason');
});

test.beforeAll(async({browser}) => {
    if (!Number(process.env.SME_JSXGRAPH_CMID || 0)) {
        return;
    }
    await enableEditor(browser);
});

test('moving a slider updates the visible editor', async({page}) => {
    const frame = await openQuestion(page);
    expect(frame, 'the JSXGraph board must render in its STACK iframe').not.toBeNull();

    const before = await sliderAt(page, frame, 0);
    expect(before.count).toBe(4);
    const start = await answerState(page, 'ans1');

    await drag(page, before, 40);

    const after = await sliderAt(page, frame, 0);
    const state = await answerState(page, 'ans1');

    expect(after.value, 'the drag must move the slider').not.toBe(before.value);
    expect(state.input, 'JSXGraph writes into the original input').not.toBe(start.input);
    // #77: the editor shows what the input holds.
    expect(digits(state.latex)).toBe(digits(state.input));
});

test('typing in the editor moves the slider', async({page}) => {
    const frame = await openQuestion(page);
    expect(frame).not.toBeNull();

    const before = await sliderAt(page, frame, 1);
    await typeInto(page, 'ans2', before.value === 2 ? '3' : '2');

    const state = await answerState(page, 'ans2');
    const after = await sliderAt(page, frame, 1);

    expect(state.input).toMatch(/^[23]$/);
    expect(after.value, 'and JSXGraph follows').toBe(Number(state.input));
});

test('alternating changes do not drift', async({page}) => {
    const frame = await openQuestion(page);
    expect(frame).not.toBeNull();

    for (let round = 0; round < 5; round++) {
        const handle = await sliderAt(page, frame, 2);
        await drag(page, handle, round % 2 ? -20 : 20);

        const afterdrag = await answerState(page, 'ans3');
        expect(digits(afterdrag.latex), 'after drag ' + round).toBe(digits(afterdrag.input));

        await typeInto(page, 'ans3', String(round % 2 ? 1 : 2));
        const aftertype = await answerState(page, 'ans3');
        const slider = await sliderAt(page, frame, 2);

        expect(aftertype.input, 'after typing ' + round).toBe(String(round % 2 ? 1 : 2));
        expect(slider.value, 'slider follows after typing ' + round).toBe(Number(aftertype.input));
    }
});

test('an adopted value is not written back as a second change', async({page}) => {
    const frame = await openQuestion(page);
    expect(frame).not.toBeNull();

    // Count the changes the input sees during one drag. Each JSXGraph update is one change;
    // the editor adopting it must not add one of its own on top.
    await page.evaluate(() => {
        window.smeChanges = 0;
        const input = document.querySelector('input[name$="_ans4"]');
        input.addEventListener('change', () => {
            window.smeChanges++;
        });
        window.smeWrites = 0;
        const original = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        Object.defineProperty(input, 'value', {
            get() {
                return original.get.call(this);
            },
            set(v) {
                window.smeWrites++;
                original.set.call(this, v);
            }
        });
    });

    const handle = await sliderAt(page, frame, 3);
    await drag(page, handle, 30);

    const counts = await page.evaluate(() => ({changes: window.smeChanges, writes: window.smeWrites}));
    const state = await answerState(page, 'ans4');

    expect(counts.changes).toBeGreaterThan(0);
    // The editor writes back only what it adopted for its own display, never more often than
    // JSXGraph wrote: one write per external change at most.
    expect(counts.writes).toBeLessThanOrEqual(counts.changes * 2);
    expect(digits(state.latex)).toBe(digits(state.input));
});
