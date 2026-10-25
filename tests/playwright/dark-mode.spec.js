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
 * Moodle 5.3 dark colour mode: the editor's controls stay readable and usable (#93).
 *
 * theme_boost has colour modes from Moodle 5.3 on. The seed offers them; this spec switches to
 * dark the way a person does (the colour mode menu), then measures the plugin's own elements:
 * axe (text contrast, names, roles) on toolbar, editor, switch and matrix chooser, and the
 * non-text contrast axe does not check - the border of an editor field and of a chooser cell
 * against what surrounds it (WCAG 1.4.11, 3:1). The configuration page is checked the same way,
 * with its question preview opening through Bootstrap's collapse.
 *
 * On a Moodle without colour modes (4.5 to 5.2) the spec skips with that reason; the skip is
 * annotated as optional, so the run summary gate accepts it.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {env, loginAs, open, openPage, closePage, closeLeftovers, optionalSkip} = require('./helpers');

test.describe.configure({mode: 'serial', timeout: 120000});
test.afterEach(closeLeftovers);

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Switch the logged-in user to dark through the colour mode menu.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @returns {Promise<boolean>} False when this Moodle has no colour mode menu.
 */
async function switchToDark(page) {
    await open(page, '/my/');
    const toggle = page.locator('#colourmode-menu-toggle');
    if (!await toggle.count()) {
        return false;
    }
    await toggle.click();
    // The choice is stored as a user preference through the routed API; the next page is
    // rendered from it, so the test waits for that request rather than for a fixed time.
    const saved = page.waitForResponse((response) => /preferences\/theme_boost_colourmode/.test(response.url()));
    await page.locator('[data-action="set-colourmode"][data-colourmode="dark"]').click();
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
    expect((await saved).status(), 'the colour mode preference is saved').toBe(200);
    return true;
}

/**
 * Run axe on the given selectors.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string[]} include Selectors.
 * @param {import('@playwright/test').TestInfo} info Test info.
 * @param {string} label Attachment name.
 * @param {string[]} [exclude] Selectors left out.
 * @param {string[]|null} [rules] Only these axe rules; all WCAG A/AA rules when null.
 * @returns {Promise<Object[]>} Violations.
 */
async function axe(page, include, info, label, exclude = [], rules = null) {
    let builder = new AxeBuilder({page});
    builder = rules ? builder.withRules(rules) : builder.withTags(WCAG);
    include.forEach((selector) => {
        builder = builder.include(selector);
    });
    exclude.forEach((selector) => {
        builder = builder.exclude(selector);
    });
    const result = await builder.analyze();
    await info.attach(label, {body: JSON.stringify(result.violations, null, 2), contentType: 'application/json'});
    return result.violations.map((v) => ({id: v.id, nodes: v.nodes.map((n) => `${n.target.join(' ')}: ${n.failureSummary}`)}));
}

/**
 * Contrast of an element's border against the background behind it, for every match.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} selector Elements to measure.
 * @returns {Promise<Object[]>} [{what, ratio}] per element.
 */
function borderContrast(page, selector) {
    return page.evaluate((sel) => {
        const parse = (colour) => {
            const m = colour.match(/rgba?\(([^)]+)\)/);
            if (!m) {
                return null;
            }
            const parts = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
            return {r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1};
        };
        const lum = (c) => {
            const f = (v) => {
                v /= 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            };
            return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
        };
        const behind = (element) => {
            for (let node = element.parentElement; node; node = node.parentElement) {
                const c = parse(getComputedStyle(node).backgroundColor);
                if (c && c.a > 0.5) {
                    return c;
                }
            }
            return parse(getComputedStyle(document.body).backgroundColor) || {r: 255, g: 255, b: 255, a: 1};
        };
        return Array.from(document.querySelectorAll(sel)).filter((el) => el.getBoundingClientRect().width > 0)
            .map((el) => {
                const style = getComputedStyle(el);
                const border = parse(style.borderTopColor);
                // An element's own fill counts as its edge where it differs from the background.
                const fill = parse(style.backgroundColor);
                const back = behind(el);
                const ratio = (a, b) => {
                    const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
                    return (hi + 0.05) / (lo + 0.05);
                };
                const edge = Math.max(
                    border && parseFloat(style.borderTopWidth) > 0 ? ratio(border, back) : 1,
                    fill && fill.a > 0.5 ? ratio(fill, back) : 1
                );
                return {what: `${el.className}`.slice(0, 50), ratio: Math.round(edge * 100) / 100};
            });
    }, selector);
}

/**
 * Contrast of an element's text colour against the background behind it, for every match.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} selector Elements to measure.
 * @returns {Promise<Object[]>} [{what, ratio}] per element.
 */
function textContrast(page, selector) {
    return page.evaluate((sel) => {
        const parse = (colour) => {
            const m = colour.match(/rgba?\(([^)]+)\)/);
            if (!m) {
                return null;
            }
            const parts = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
            return {r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1};
        };
        const lum = (c) => {
            const f = (v) => {
                v /= 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            };
            return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
        };
        const behind = (element) => {
            for (let node = element; node; node = node.parentElement) {
                const c = parse(getComputedStyle(node).backgroundColor);
                if (c && c.a > 0.5) {
                    return c;
                }
            }
            return {r: 255, g: 255, b: 255, a: 1};
        };
        return Array.from(document.querySelectorAll(sel)).filter((el) => el.getBoundingClientRect().width > 0)
            .map((el) => {
                const text = parse(getComputedStyle(el).color);
                const back = behind(el);
                const [hi, lo] = [lum(text), lum(back)].sort((x, y) => y - x);
                return {what: `${el.className}`.slice(0, 50), ratio: Math.round((hi + 0.05) / (lo + 0.05) * 100) / 100};
            });
    }, selector);
}

/**
 * Make sure every toolbar group is offered, so the matrix chooser is on the page.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @returns {Promise<void>}
 */
async function allGroups(browser) {
    const admin = await openPage(browser);
    await loginAs(admin, env('SME_ADMIN_USER', 'admin'), env('SME_ADMIN_PASS'));
    await admin.goto('/admin/settings.php?section=local_stackmatheditor');
    await admin.locator('select[name="s_local_stackmatheditor_enabled"]').selectOption('1');
    const groups = admin.locator('select[name="s_local_stackmatheditor_default_groups[]"]');
    await groups.selectOption(await groups.locator('option').evaluateAll((o) => o.map((x) => x.value)));
    await admin.getByRole('button', {name: 'Save changes'}).click();
    await closePage(admin);
}

test('dark mode: editor, toolbar, switch and matrix chooser stay readable', async({browser}, info) => {
    await allGroups(browser);
    const page = await openPage(browser);
    await loginAs(page, 'sme_student12', env('SME_USER_PASS'));
    const dark = await switchToDark(page);
    optionalSkip(test, !dark, 'this Moodle has no colour modes (theme_boost, Moodle 5.3 and later)');

    await open(page, `/mod/quiz/view.php?id=${env('SME_LOAD_CMID')}`);
    await page.getByRole('button', {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForSelector('.sme-toolbar', {timeout: 60000});
    await page.waitForTimeout(1500);
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
    await info.attach('attempt-dark', {body: await page.screenshot({fullPage: false}), contentType: 'image/png'});

    // Resting state: the mouse is still where "Start attempt" was clicked (see a11y.spec.js).
    await page.mouse.move(0, 0);
    await page.waitForTimeout(300);
    const violations = await axe(page, ['.sme-toolbar', '.sme-mq-container', '.sme-equiv-wrap', '.sme-toggle'],
        info, 'axe attempt dark');
    expect(violations, JSON.stringify(violations, null, 1)).toEqual([]);

    // Where an answer goes has to be visible: the field's edge against the page (1.4.11).
    const fields = await borderContrast(page, '.sme-mq-container, .sme-equiv-rows');
    expect(fields.length).toBeGreaterThan(0);
    expect(fields.filter((f) => f.ratio < 3), JSON.stringify(fields)).toEqual([]);

    // What is typed is readable: MathQuill's text against the field (1.4.3, 4.5:1).
    const field = page.locator('.sme-mq-container .mq-editable-field').first();
    await field.click();
    await page.keyboard.type('x+1');
    const typed = await textContrast(page, '.sme-mq-container .mq-root-block');
    expect(typed.length).toBeGreaterThan(0);
    expect(typed.filter((t) => t.ratio < 4.5), JSON.stringify(typed)).toEqual([]);

    // The matrix chooser: text by axe, cell edges measured.
    const chooser = page.locator('.sme-tb-btn[data-command="matrix"]').first();
    await chooser.scrollIntoViewIfNeeded();
    await chooser.click();
    await expect(page.locator('.sme-matrix-popup')).toBeVisible();
    await info.attach('matrix-chooser-dark', {body: await page.screenshot(), contentType: 'image/png'});
    const popup = await axe(page, ['.sme-matrix-popup'], info, 'axe matrix chooser dark');
    expect(popup, JSON.stringify(popup, null, 1)).toEqual([]);
    const cells = await borderContrast(page, '.sme-matrix-grid-cell');
    expect(cells.filter((c) => c.ratio < 3), JSON.stringify(cells.slice(0, 5))).toEqual([]);
    await page.keyboard.press('Escape');
    await closePage(page);
});

test('dark mode: the configuration page and its question preview', async({browser}, info) => {
    const page = await openPage(browser);
    await loginAs(page, 'sme_teacher', env('SME_USER_PASS'));
    const dark = await switchToDark(page);
    optionalSkip(test, !dark, 'this Moodle has no colour modes (theme_boost, Moodle 5.3 and later)');

    await open(page, `/local/stackmatheditor/configure.php?cmid=${env('SME_SETTINGS_CMID')}`
        + `&qbeid=${env('SME_SETTINGS_QBE1')}`);
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');

    // The preview opens through Bootstrap's collapse - the attribute Moodle 5.x reads.
    const button = page.locator('a[href="#sme-question-preview"]');
    await expect(button).toHaveAttribute('data-bs-toggle', 'collapse');
    await button.click();
    await expect(page.locator('#sme-question-preview')).toBeVisible();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await info.attach('configure-dark', {body: await page.screenshot({fullPage: true}), contentType: 'image/png'});

    // The form as a whole; the preview is STACK's own read-only rendering of the question (its
    // inputs carry no label of their own there, as in STACK's preview), so for it only contrast
    // is the plugin's business - it must be readable in dark mode.
    const violations = await axe(page, ['#region-main form.mform'], info, 'axe configure dark', ['#sme-question-preview']);
    expect(violations, JSON.stringify(violations, null, 1)).toEqual([]);
    const preview = await axe(page, ['#sme-question-preview'], info, 'axe preview dark', [], ['color-contrast']);
    expect(preview, JSON.stringify(preview, null, 1)).toEqual([]);
    await closePage(page);
});
