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
 * Issues #73 and #76: what the author decides is what the student gets.
 *
 * Both are hierarchies resolved on the server - the student switch with inhibitory semantics,
 * the chooser limit with ordinary inheritance - and both were tested there. What was missing is
 * the other end: that the browser shows exactly the resolved state, and that a preference stored
 * in one student's browser cannot get around an author's decision.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs, openPage, closePage, closeLeftovers} = require('./helpers');

// Local reference: 30-60 s per test.
test.describe.configure({mode: 'serial', timeout: 180000});

const ADMIN = () => ({user: env('SME_ADMIN_USER', 'admin'), pass: env('SME_ADMIN_PASS')});

/**
 * Set the site-wide settings this file cares about.
 *
 * @param {Browser} browser Playwright browser.
 * @param {Object} settings {enabled, studentToggle, maxDimension}.
 * @returns {Promise<void>}
 */
async function siteSettings(browser, settings) {
    const page = await openPage(browser);
    const admin = ADMIN();

    await loginAs(page, admin.user, admin.pass);
    await page.goto('/admin/settings.php?section=local_stackmatheditor');

    await page.locator('select[name="s_local_stackmatheditor_enabled"]')
        .selectOption(String(settings.enabled));

    // Moodle writes a hidden input with the same name next to the checkbox, so the type has to
    // be part of the selector - otherwise the locator matches two elements and refuses.
    const toggle = page.locator(
        'input[type="checkbox"][name="s_local_stackmatheditor_allowstudenttoggle"]'
    );
    if (await toggle.count()) {
        if (settings.studentToggle) {
            await toggle.check();
        } else {
            await toggle.uncheck();
        }
    }

    const max = page.locator('input[name="s_local_stackmatheditor_maxstructureddimension"]');
    if (await max.count() && settings.maxDimension) {
        await max.fill(String(settings.maxDimension));
    }

    // Every group, so the matrix and vector choosers are on the page.
    const groups = page.locator('select[name="s_local_stackmatheditor_default_groups[]"]');
    await groups.selectOption(
        await groups.locator('option').evaluateAll((options) => options.map((o) => o.value))
    );

    await page.getByRole('button', {name: 'Save changes'}).click();
    await closePage(page);
}

/**
 * Configure the quiz level through the plugin's own configuration page.
 *
 * @param {Browser} browser Playwright browser.
 * @param {Object} values {studentToggle, maxDimension} - undefined leaves a field alone.
 * @returns {Promise<void>}
 */
async function quizSettings(browser, values) {
    const page = await openPage(browser);
    const admin = ADMIN();

    await loginAs(page, admin.user, admin.pass);
    await page.goto('/local/stackmatheditor/configure.php?cmid=' + env('SME_LOAD_CMID'));

    // The switch is only stored as allowed where the editor is on at this level (#73), so a
    // test that wants the switch has to enable the editor here as well.
    if (values.studentToggle) {
        const enabled = page.locator('input[type="checkbox"][name="enabled"]');
        if (await enabled.count()) {
            await enabled.check();
        }
    }

    if (values.studentToggle !== undefined) {
        const toggle = page.locator('input[type="checkbox"][name="allowstudenttoggle"]');
        if (await toggle.count()) {
            if (values.studentToggle) {
                await toggle.check();
            } else {
                await toggle.uncheck();
            }
        }
    }

    if (values.maxDimension !== undefined) {
        await page.locator('#id_sme_maxdimension').fill(String(values.maxDimension));
    }

    await page.getByRole('button', {name: /Save/}).click();
    await closePage(page);
}

/**
 * Open the quiz as a student.
 *
 * @param {Page} page Playwright page.
 * @param {string} who Username.
 * @returns {Promise<void>}
 */
async function attempt(page, who) {
    await loginAs(page, who, env('SME_USER_PASS'));
    await page.goto('/mod/quiz/view.php?id=' + env('SME_LOAD_CMID'));
    await page.getByRole('button',
        {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForSelector('.sme-mq-container, .sme-toolbar', {timeout: 60000});
}

// A test that fails half way does not reach its closePage(); this does.
test.afterEach(closeLeftovers);

// The load quiz is shared with the other specs. Hand it back as the seed made it: switch
// allowed, chooser limit inherited - the last cases here leave it with neither.
test.afterAll(async({browser}) => {
    await siteSettings(browser, {enabled: 1, studentToggle: true, maxDimension: 5});
    await quizSettings(browser, {studentToggle: true, maxDimension: ''});
});

test.describe('#73: the student switch is a permission', () => {
    test('with the permission the switch is there and keeps the answer', async({browser}) => {
        await siteSettings(browser, {enabled: 1, studentToggle: true, maxDimension: 5});
        await quizSettings(browser, {studentToggle: true});

        const page = await openPage(browser);
        await attempt(page, 'sme_student07');

        const toggle = page.locator('.sme-toggle input[type="checkbox"]').first();

        // Wait first, report second. The switch is attached a tick after the toolbar, so a
        // count taken straight away races the editor's own setup - which is what the previous
        // run tripped over, with a diagnostic that fired before the assertion could wait.
        try {
            await expect(toggle).toHaveCount(1, {timeout: 20000});
        } catch (ignored) {
            const seen = await page.evaluate(() => ({
                editors: document.querySelectorAll('.sme-mq-container').length,
                wraps: document.querySelectorAll('.sme-input-wrap, .sme-equiv-wrap').length,
                toggles: document.querySelectorAll('.sme-toggle').length
            }));
            throw new Error('no switch rendered; page shows ' + JSON.stringify(seen));
        }

        // Type something, then switch the editor off: the answer has to come with it.
        await page.locator('.sme-mq-container').first().click();
        await page.keyboard.type('2+3');
        await page.waitForTimeout(500);

        await toggle.click();
        await page.waitForTimeout(500);

        const input = page.locator('input[name$="_ans1"]').first();
        await expect(input).toBeVisible();
        await expect(input).toHaveValue(/2\+3/);

        // And back again.
        await toggle.click();
        await page.waitForTimeout(500);
        const latex = await page.evaluate(() => {
            const field = document.querySelector('.mq-editable-field');
            const MQ = window.MathQuill.getInterface(window.MathQuill.getInterface.MAX || 2);
            return MQ(field).latex();
        });
        expect(latex).toContain('2+3');

        await closePage(page);
    });

    test('a stored preference does not survive the author taking the permission away',
        async({browser}) => {
            // The student switched the editor off in the previous test, so the browser of that
            // profile remembers "off". A fresh context plus a revoked permission has to show the
            // editor, with no switch: a preference is not a permission.
            await siteSettings(browser, {enabled: 1, studentToggle: false, maxDimension: 5});

            const page = await openPage(browser);
            await attempt(page, 'sme_student07');

            await page.evaluate(() => {
                try {
                    window.localStorage.setItem('local_stackmatheditor_editor_off', '1');
                } catch (ignored) {
                    // Storage may be blocked; the assertion below is what matters.
                }
            });
            await page.reload();
            await page.waitForSelector('.sme-mq-container', {timeout: 60000});

            await expect(page.locator('.sme-toggle input[type="checkbox"]')).toHaveCount(0);
            await expect(page.locator('.sme-mq-container').first()).toBeVisible();

            await closePage(page);
        });

    test('the quiz can take the permission away when the site allows it', async({browser}) => {
        await siteSettings(browser, {enabled: 1, studentToggle: true, maxDimension: 5});
        await quizSettings(browser, {studentToggle: false});

        const page = await openPage(browser);
        await attempt(page, 'sme_student08');

        await expect(page.locator('.sme-toggle input[type="checkbox"]')).toHaveCount(0);
        await expect(page.locator('.sme-mq-container').first()).toBeVisible();

        await closePage(page);
    });
});

test.describe('#76: the chooser limit is inherited', () => {
    /**
     * How many rows the matrix grid offers.
     *
     * @param {Page} page Playwright page.
     * @returns {Promise<Object>} {rows, columns, vector}.
     */
    async function chooserSize(page) {
        await page.locator('.sme-tb-btn[data-command="matrix"]').first().click();
        await page.waitForSelector('.sme-matrix-grid', {timeout: 10000});

        const size = await page.evaluate(() => ({
            rows: document.querySelectorAll('.sme-matrix-grid-row').length,
            cells: document.querySelectorAll('.sme-matrix-grid-cell').length
        }));

        await page.keyboard.press('Escape');
        await page.waitForTimeout(200);

        await page.locator('.sme-tb-btn[data-command="vector"]').first().click();
        await page.waitForSelector('.sme-vector-popup', {timeout: 10000});
        const vector = await page.evaluate(
            () => document.querySelectorAll('.sme-vector-dimension').length
        );
        await page.keyboard.press('Escape');

        return {rows: size.rows, columns: size.cells / size.rows, vector: vector};
    }

    test('the site value reaches both choosers', async({browser}) => {
        await siteSettings(browser, {enabled: 1, studentToggle: true, maxDimension: 5});
        await quizSettings(browser, {maxDimension: ''});

        const page = await openPage(browser);
        await attempt(page, 'sme_student09');

        const size = await chooserSize(page);
        expect(size.rows).toBe(5);
        expect(size.columns).toBe(5);
        // The vector chooser offers 2..max.
        expect(size.vector).toBe(4);

        await closePage(page);
    });

    test('a quiz override wins over the site value', async({browser}) => {
        await siteSettings(browser, {enabled: 1, studentToggle: true, maxDimension: 5});
        await quizSettings(browser, {maxDimension: 7});

        const page = await openPage(browser);
        await attempt(page, 'sme_student10');

        const size = await chooserSize(page);
        expect(size.rows).toBe(7);
        expect(size.columns).toBe(7);
        expect(size.vector).toBe(6);

        await closePage(page);
    });

    test('the keyboard cannot select beyond the limit', async({browser}) => {
        await siteSettings(browser, {enabled: 1, studentToggle: true, maxDimension: 5});
        await quizSettings(browser, {maxDimension: 3});

        const page = await openPage(browser);
        await attempt(page, 'sme_student11');

        await page.locator('.sme-tb-btn[data-command="matrix"]').first().click();
        await page.waitForSelector('.sme-matrix-grid', {timeout: 10000});

        for (let i = 0; i < 8; i += 1) {
            await page.keyboard.press('ArrowDown');
            await page.keyboard.press('ArrowRight');
        }
        await page.keyboard.press('Enter');
        await page.waitForTimeout(500);

        const latex = await page.evaluate(() => {
            const field = document.querySelector('.mq-editable-field');
            const MQ = window.MathQuill.getInterface(window.MathQuill.getInterface.MAX || 2);
            return MQ(field).latex();
        });

        // Three rows: two line breaks inside the matrix environment, and no more.
        expect((latex.match(/\\\\/g) || []).length).toBe(2);

        await closePage(page);
    });

    test('the field is disabled without a structured group, and keeps its value',
        async({browser}) => {
            const page = await openPage(browser);
            const admin = ADMIN();

            await loginAs(page, admin.user, admin.pass);
            await page.goto('/local/stackmatheditor/configure.php?cmid=' + env('SME_LOAD_CMID'));

            const field = page.locator('#id_sme_maxdimension');
            const groups = page.locator('select[name="groups[]"]');

            // With the structured groups selected the field is usable.
            await groups.selectOption(['matrix_operators']);
            await page.waitForTimeout(300);
            await expect(field).toBeEnabled();
            await field.fill('6');

            // Deselect them: the field goes dead but keeps what it holds.
            await groups.selectOption(['basic_operators']);
            await page.waitForTimeout(300);
            await expect(field).toBeDisabled();
            await expect(field).toHaveValue('6');

            // And comes back when a structured group returns.
            await groups.selectOption(['vector_operators']);
            await page.waitForTimeout(300);
            await expect(field).toBeEnabled();
            await expect(field).toHaveValue('6');

            await closePage(page);
        });
});
