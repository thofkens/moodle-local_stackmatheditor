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
 * Browser performance: many STACK questions and editors on one page.
 *
 * "SME Load Quiz" shows eight single-line and two multi-line STACK inputs on one page. Measured:
 * the time until every editor is ready, that each input gets exactly one editor (also after
 * re-initialisation by reloading), and that the original inputs stay in the page.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs, openPage, closeLeftovers} = require('./helpers');

// The contexts these tests open are closed whether the test passes or not.
test.afterEach(closeLeftovers);

/**
 * Upper bound for "all ten editors ready" (local reference: 1.2-2.3 s). Provisional with CI
 * headroom until the first GitHub run.
 */
const READY_BUDGET_MS = Number(process.env.SME_READY_BUDGET_MS || 6000);

/**
 * Bounds for the storm check. Measured locally: no change at all in five idle seconds, and 177
 * for one keystroke - the editor's redraw plus STACK's validation feedback with its MathJax. A feedback loop is not twice as much - it is
 * thousands, so the bounds are generous on purpose.
 */
const IDLE_MUTATIONS = 10;
const KEYSTROKE_MUTATIONS = 1000;

test('ten STACK questions: every editor ready in time, exactly once, also after reloading', async({browser}, info) => {
    test.setTimeout(90000);
    const CMID = env('SME_LOAD_CMID');
    const admin = await openPage(browser);
    await loginAs(admin, env('SME_ADMIN_USER', 'admin'), env('SME_ADMIN_PASS'));
    await admin.goto('/admin/settings.php?section=local_stackmatheditor');
    await admin.locator('select[name="s_local_stackmatheditor_enabled"]').selectOption('1');
    await admin.getByRole('button', {name: 'Save changes'}).click();

    const page = await openPage(browser);
    await loginAs(page, 'sme_student02', env('SME_USER_PASS'));
    await page.goto('/mod/quiz/view.php?id=' + CMID);
    await page.getByRole('button', {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }

    const timings = [];
    for (let round = 0; round < 3; round++) {
        if (round > 0) {
            await page.reload();
        }
        await page.locator('.que.stack').first().waitFor();
        await page.waitForFunction(() => document.querySelectorAll('.que.stack').length === 10
            && Array.from(document.querySelectorAll('.que.stack')).every((q) => q.querySelector('.mq-editable-field')),
        null, {timeout: 30000});
        const ms = await page.evaluate(() => Math.round(performance.now()));
        timings.push(ms);

        const perQuestion = await page.locator('.que.stack').evaluateAll((qs) => qs.map((q) => ({
            editors: q.querySelectorAll('.mq-root-block').length,
            toolbars: q.querySelectorAll('.sme-toolbar').length,
            original: !!q.querySelector('input[name$="_ans1"], textarea[name$="_ans1"]'),
        })));
        perQuestion.forEach((q, i) => {
            expect(q.editors, 'question ' + (i + 1) + ': exactly one editor').toBe(1);
            expect(q.toolbars, 'question ' + (i + 1) + ': exactly one toolbar').toBe(1);
            expect(q.original, 'question ' + (i + 1) + ': original STACK input kept').toBe(true);
        });
    }
    info.annotations.push({type: 'editors ready after (ms, per load)', description: timings.join(', ')});
    await info.attach('ten editors', {body: await page.screenshot({fullPage: true}), contentType: 'image/png'});
    timings.forEach((ms) => expect(ms).toBeLessThan(READY_BUDGET_MS));
});

/**
 * No storms (#69): an idle page stays idle, and one keystroke stays one keystroke.
 *
 * The editor watches the page with a MutationObserver and writes into inputs other scripts
 * listen to. Either can feed on itself: an observer that reacts to its own changes, or a write
 * that triggers the listener that wrote it. Neither shows as a wrong result - only as a page
 * that never comes to rest. So this counts what happens when nothing happens, and what one
 * character sets off.
 */
test('an idle page stays idle, and one keystroke does not set off a storm', async({browser}, info) => {
    test.setTimeout(90000);
    const page = await openPage(browser);
    await loginAs(page, 'sme_student03', env('SME_USER_PASS'));
    await page.goto('/mod/quiz/view.php?id=' + env('SME_LOAD_CMID'));
    await page.getByRole('button', {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForFunction(() => document.querySelectorAll('.que.stack').length === 10
        && Array.from(document.querySelectorAll('.que.stack')).every((q) => q.querySelector('.mq-editable-field')),
    null, {timeout: 30000});

    // Requests this plugin makes: its web services and its own files.
    const requests = [];
    page.on('request', (request) => {
        const what = request.url() + ' ' + (request.postData() || '');
        if (what.includes('local_stackmatheditor')) {
            requests.push(request.url().slice(0, 120));
        }
    });

    // Mutations inside the questions, where the editors live.
    await page.evaluate(() => {
        window.smeMutations = 0;
        const observer = new MutationObserver((list) => {
            window.smeMutations += list.length;
        });
        document.querySelectorAll('.que.stack').forEach((question) => observer.observe(
            question, {subtree: true, childList: true, attributes: true, characterData: true}
        ));
    });
    const count = () => page.evaluate(() => {
        const n = window.smeMutations;
        window.smeMutations = 0;
        return n;
    });

    // Late initialisation is not a storm: MathJax typesets the toolbars a few seconds after the
    // editors are there, in one burst of some hundred changes. A storm does not end; this does.
    // So wait until two seconds have passed without a change - and fail if that never happens.
    let settled = false;
    for (let i = 0; i < 15 && !settled; i++) {
        await page.waitForTimeout(2000);
        settled = await count() === 0;
    }
    expect(settled, 'the page comes to rest within thirty seconds').toBe(true);
    requests.splice(0);

    await page.waitForTimeout(5000);
    const idle = {mutations: await count(), requests: requests.splice(0).length};

    await page.locator('.que.stack').first().locator('.mq-editable-field').click();
    await page.waitForTimeout(500);
    await count();
    requests.splice(0);
    await page.keyboard.type('x');
    await page.waitForTimeout(3000);
    const keystroke = {mutations: await count(), requests: requests.splice(0).length};

    info.annotations.push({type: 'idle 5 s / one keystroke', description: JSON.stringify({idle, keystroke})});

    // Nothing happens: nothing changes, nothing is requested.
    expect(idle.requests, 'plugin requests while idle').toBe(0);
    expect(idle.mutations, 'DOM changes inside the questions while idle').toBeLessThanOrEqual(IDLE_MUTATIONS);
    // One character: the editor redraws, STACK validates - a bounded amount of work, once.
    expect(keystroke.requests, 'plugin requests for one keystroke').toBeLessThanOrEqual(1);
    expect(keystroke.mutations, 'DOM changes for one keystroke').toBeLessThan(KEYSTROKE_MUTATIONS);
});
