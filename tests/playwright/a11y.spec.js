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
 * Accessibility regression (axe-core, WCAG 2.1 A/AA) for everything this plugin renders:
 * the editors and toolbars in a quiz attempt (single-line and multi-line) and the configuration
 * page. Moodle core's own markup around it is not judged here.
 *
 * Also checks what axe cannot: every toolbar button has an accessible name from the language
 * pack (not just the symbol), and the editor can be operated with the keyboard alone.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {env, loginAs, openPage, closeLeftovers} = require('./helpers');

// The contexts these tests open are closed whether the test passes or not.
test.afterEach(closeLeftovers);

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Run axe on the plugin's own elements and attach the result.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string[]} include CSS selectors of the plugin's elements.
 * @param {import('@playwright/test').TestInfo} info Test info.
 * @param {string} label Attachment name.
 * @returns {Promise<Object[]>} Violations.
 */
async function axe(page, include, info, label) {
    let builder = new AxeBuilder({page}).withTags(WCAG);
    include.forEach((selector) => {
        builder = builder.include(selector);
    });
    const result = await builder.analyze();
    await info.attach(label, {body: JSON.stringify(result.violations, null, 2), contentType: 'application/json'});
    return result.violations.map((v) => ({id: v.id, impact: v.impact, nodes: v.nodes.map((n) => n.target.join(' '))}));
}

test('attempt page: editors and toolbars are accessible', async({browser}, info) => {
    // Local reference: 30 s.
    test.setTimeout(90000);
    const admin = await openPage(browser);
    await loginAs(admin, env('SME_ADMIN_USER', 'admin'), env('SME_ADMIN_PASS'));
    await admin.goto('/admin/settings.php?section=local_stackmatheditor');
    await admin.locator('select[name="s_local_stackmatheditor_enabled"]').selectOption('1');
    // Every offered toolbar group, so every button is judged.
    const groups = admin.locator('select[name="s_local_stackmatheditor_default_groups[]"]');
    await groups.selectOption(await groups.locator('option').evaluateAll((o) => o.map((x) => x.value)));
    await admin.getByRole('button', {name: 'Save changes'}).click();

    const page = await openPage(browser);
    await loginAs(page, 'sme_student03', env('SME_USER_PASS'));
    await page.goto('/mod/quiz/view.php?id=' + env('SME_LOAD_CMID'));
    await page.getByRole('button', {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForFunction(() => document.querySelectorAll('.que.stack .mq-editable-field').length >= 10,
        null, {timeout: 30000});

    // The mouse is still where "Start attempt" was clicked; on the next page a toolbar button can
    // sit under it, and axe then measures that button in the middle of its hover transition.
    // Measure the resting state.
    await page.mouse.move(0, 0);
    await page.waitForTimeout(300);
    const violations = await axe(page, ['.sme-toolbar', '.sme-mq-container', '.sme-equiv-wrap'], info, 'axe attempt');
    expect(violations, JSON.stringify(violations, null, 1)).toEqual([]);

    // Accessible names come from the language pack, not from the symbol on the button.
    const unnamed = await page.locator('.sme-toolbar button').evaluateAll((buttons) => buttons
        .filter((b) => !b.getAttribute('aria-label') || b.getAttribute('aria-label') === b.textContent.trim())
        .map((b) => b.outerHTML.slice(0, 80)));
    expect(unnamed).toEqual([]);

    // Keyboard: the editor's own (named) input takes the keys, and typing converts. The field
    // is emptied first - a continued attempt may still hold an answer from an earlier run.
    const first = page.locator('.que.stack').first();
    await first.locator('.mq-editable-field').first().evaluate((el) => window.MathQuill.getInterface(2)(el).latex(''));
    await first.locator('.mq-editable-field textarea').focus();
    await page.keyboard.type('x');
    await expect(first.locator('input[name$="_ans1"]')).toHaveValue('x');
});

test('configuration page is accessible', async({page}, info) => {
    await loginAs(page, 'sme_teacher', env('SME_USER_PASS'));
    await page.goto('/local/stackmatheditor/configure.php?cmid=' + env('SME_SETTINGS_CMID'));
    const violations = await axe(page, ['#region-main form.mform'], info, 'axe configure');
    expect(violations, JSON.stringify(violations, null, 1)).toEqual([]);
});
