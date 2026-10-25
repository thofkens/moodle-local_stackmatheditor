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
 * Issue #74: the toolbar follows the width of the editor, not of the window.
 *
 * Opening Moodle's navigation drawer leaves the viewport alone and takes a third of the width
 * away from the question. A viewport media query does not notice that; a container query does.
 * These tests measure what is on screen: no button beyond the right edge of its container, and
 * no group of five or fewer, no cluster of a larger group torn apart while it fits on a line -
 * at one width and across every width from 700 down to 300 pixels. What is wider than the whole
 * toolbar on its own breaks inside instead of reaching out of it.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs, openPage, closePage} = require('./helpers');

// Local reference: 40-60 s per test.
test.describe.configure({mode: 'serial', timeout: 120000});

/**
 * Switch the editor on site-wide and offer every group.
 *
 * The specs share one Moodle, and the settings suite runs before this one and leaves the plugin
 * in whatever state its last case needed. A test that measures a toolbar has to make sure there
 * is one, rather than inherit the mood of its predecessor.
 *
 * @param {Browser} browser Playwright browser.
 * @returns {Promise<void>}
 */
async function enableEditorEverywhere(browser) {
    const admin = await openPage(browser);

    await loginAs(admin, env('SME_ADMIN_USER', 'admin'), env('SME_ADMIN_PASS'));
    await admin.goto('/admin/settings.php?section=local_stackmatheditor');
    await admin.locator('select[name="s_local_stackmatheditor_enabled"]').selectOption('1');

    // Every group, so that the large ones with clusters are on the page as well.
    const groups = admin.locator('select[name="s_local_stackmatheditor_default_groups[]"]');
    await groups.selectOption(
        await groups.locator('option').evaluateAll((options) => options.map((o) => o.value))
    );
    await admin.getByRole('button', {name: 'Save changes'}).click();
    await closePage(admin);
}

/**
 * Open an attempt with editors on the page.
 *
 * @param {Page} page Playwright page.
 * @returns {Promise<void>}
 */
async function openAttempt(page) {
    await loginAs(page, 'sme_student04', env('SME_USER_PASS'));
    await page.setViewportSize({width: 1280, height: 900});
    await page.goto('/mod/quiz/view.php?id=' + env('SME_LOAD_CMID'));
    await page.getByRole('button',
        {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }
    await page.waitForSelector('.sme-toolbar', {timeout: 30000});
}

/**
 * The drawer toggle a user could actually click.
 *
 * Boost ships several of them and hides all but one: at 1280px the first match in the DOM is the
 * mobile navbar toggler, which is display:none. Clicking it waits forever, which is exactly what
 * the first run of this test did.
 *
 * @param {Page} page Playwright page.
 * @returns {Promise<?Locator>} The visible toggle, or null when the theme has none.
 */
async function visibleDrawerToggle(page) {
    const candidates = page.locator(
        'button[data-toggler="drawers"], button[data-action="toggle-drawer"], .drawertoggle'
    );
    const count = await candidates.count();

    for (let i = 0; i < count; i += 1) {
        const candidate = candidates.nth(i);
        if (await candidate.isVisible()) {
            return candidate;
        }
    }

    return null;
}

/**
 * Measure every toolbar against its own container.
 *
 * @param {Page} page Playwright page.
 * @returns {Promise<Object>} Overflow and group measurements.
 */
function measure(page) {
    return page.evaluate(() => {
        const overflowing = [];
        const brokenGroups = [];

        document.querySelectorAll('.sme-toolbar').forEach((toolbar, index) => {
            const bar = toolbar.getBoundingClientRect();

            toolbar.querySelectorAll('button').forEach((button) => {
                const box = button.getBoundingClientRect();
                // One pixel of tolerance for sub-pixel rounding.
                if (box.right > bar.right + 1 || box.left < bar.left - 1) {
                    overflowing.push(index + ': ' + (button.getAttribute('aria-label') || button.textContent));
                }
            });

            const style = getComputedStyle(toolbar);
            const room = toolbar.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);

            toolbar.querySelectorAll('.sme-tb-group').forEach((group) => {
                const buttons = Array.from(group.querySelectorAll('button'));
                if (buttons.length === 0 || buttons.length > 5) {
                    return;
                }
                // A group of five or fewer lives on one line: every button shares a top edge -
                // unless the group on its own is wider than the toolbar. Then breaking inside is
                // the only alternative to reaching out of the toolbar (#93).
                const gap = parseFloat(getComputedStyle(group).columnGap) || 0;
                const width = buttons.reduce((sum, b) => sum + b.getBoundingClientRect().width, 0)
                    + gap * (buttons.length - 1);
                const tops = new Set(buttons.map((b) => Math.round(b.getBoundingClientRect().top)));
                if (tops.size > 1 && width <= room) {
                    brokenGroups.push(group.getAttribute('data-group') + ' (' + buttons.length + ')');
                }
            });
        });

        const editor = document.querySelector('.sme-input-wrap, .sme-equiv-wrap');

        return {
            overflowing: overflowing,
            brokenGroups: brokenGroups,
            toolbars: document.querySelectorAll('.sme-toolbar').length,
            editorWidth: editor ? Math.round(editor.getBoundingClientRect().width) : 0
        };
    });
}

test.beforeAll(async({browser}) => {
    await enableEditorEverywhere(browser);
});

test('the toolbar stays inside its container, drawer open and closed', async({page}) => {
    await openAttempt(page);

    const closed = await measure(page);
    expect(closed.toolbars).toBeGreaterThan(0);
    expect(closed.overflowing, JSON.stringify(closed.overflowing)).toEqual([]);
    expect(closed.brokenGroups, JSON.stringify(closed.brokenGroups)).toEqual([]);

    // Moodle's own drawer: the viewport does not change, the question gets narrower.
    const drawer = await visibleDrawerToggle(page);
    if (!drawer) {
        test.info().annotations.push({
            type: 'skipped',
            description: 'this theme has no visible drawer toggle; the container test covers the rest'
        });
        return;
    }

    await drawer.click({timeout: 10000});
    await page.waitForTimeout(800);

    const open = await measure(page);
    expect(open.overflowing, JSON.stringify(open.overflowing)).toEqual([]);
    expect(open.brokenGroups, JSON.stringify(open.brokenGroups)).toEqual([]);
    // A drawer that does not take width away proves nothing, but it must not break anything
    // either - so this is an observation, not an assertion about Moodle's layout.
    expect(open.editorWidth).toBeLessThanOrEqual(closed.editorWidth);

    // Closing it again must give the width back - no layout frozen at the narrow size.
    //
    // Boost swaps the buttons: the one that opened the drawer is hidden once it is open, and a
    // second, separate "close" button takes its place. Clicking the first locator again waits
    // for an element with class="hidden" - which is what the previous run spent its ten seconds
    // on. So the visible toggle is looked up again.
    const closer = await visibleDrawerToggle(page);
    if (!closer) {
        test.info().annotations.push({
            type: 'skipped',
            description: 'the drawer has no visible toggle while it is open'
        });
        return;
    }

    await closer.click({timeout: 10000});
    await page.waitForTimeout(800);

    const reopened = await measure(page);
    expect(reopened.editorWidth).toBeGreaterThanOrEqual(open.editorWidth);
    expect(reopened.overflowing, JSON.stringify(reopened.overflowing)).toEqual([]);
});

test('a narrow container wraps the toolbar without a narrow window', async({page}) => {
    await openAttempt(page);

    // The viewport stays wide; only the editor's container is narrowed. This is what a drawer,
    // a quiz navigation column or an embedded view does, and what a media query cannot see.
    const before = await measure(page);
    expect(before.overflowing).toEqual([]);

    await page.evaluate(() => {
        document.querySelectorAll('.que.stack').forEach((question) => {
            question.style.maxWidth = '420px';
        });
    });
    await page.waitForTimeout(400);

    const narrow = await measure(page);
    expect(narrow.editorWidth).toBeLessThan(before.editorWidth);
    expect(narrow.overflowing, JSON.stringify(narrow.overflowing)).toEqual([]);
    expect(narrow.brokenGroups, JSON.stringify(narrow.brokenGroups)).toEqual([]);
});

test('no group and no cluster breaks while it fits, at any container width', async({page}) => {
    await openAttempt(page);

    // One width is a sample; the fonts of the machine decide where a group would land. A group
    // that once broke only between 425 and 431 px of question width - by the few pixels its own
    // separator took - passed locally and failed on the CI runner. So every width from wide to
    // very narrow, in small steps: whatever fits on a line stays on it.
    const failures = await page.evaluate(async() => {
        const frame = () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        const room = (toolbar) => {
            const style = getComputedStyle(toolbar);
            return toolbar.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        };
        const span = (buttons, gap) => buttons.reduce((sum, b) => sum + b.getBoundingClientRect().width, 0)
            + gap * (buttons.length - 1);
        const lines = (buttons) => new Set(buttons.map((b) => Math.round(b.getBoundingClientRect().top))).size;
        const found = [];

        for (let width = 700; width >= 300; width -= 4) {
            document.querySelectorAll('.que.stack').forEach((question) => {
                question.style.maxWidth = width + 'px';
            });
            await frame();
            document.querySelectorAll('.sme-toolbar').forEach((toolbar) => {
                const free = room(toolbar);
                const bar = toolbar.getBoundingClientRect();
                toolbar.querySelectorAll('button').forEach((button) => {
                    const box = button.getBoundingClientRect();
                    if (box.right > bar.right + 1 || box.left < bar.left - 1) {
                        found.push(width + 'px: outside the toolbar: ' + button.getAttribute('aria-label'));
                    }
                });
                toolbar.querySelectorAll('.sme-tb-group, .sme-tb-cluster').forEach((part) => {
                    const buttons = Array.from(part.querySelectorAll(':scope > button, :scope > * > button'));
                    if (part.classList.contains('sme-tb-group-wrap') || buttons.length < 2) {
                        return;
                    }
                    const gap = parseFloat(getComputedStyle(part).columnGap) || 0;
                    if (lines(buttons) > 1 && span(buttons, gap) <= free) {
                        found.push(width + 'px: broken although it fits: '
                            + (part.getAttribute('data-group') || part.className));
                    }
                });
            });
        }
        return Array.from(new Set(found));
    });

    expect(failures, failures.slice(0, 20).join('\n')).toEqual([]);
});

test('the ends of a large group stay together', async({page}) => {
    await openAttempt(page);

    await page.evaluate(() => {
        document.querySelectorAll('.que.stack').forEach((question) => {
            question.style.maxWidth = '360px';
        });
    });
    await page.waitForTimeout(400);

    const clusters = await page.evaluate(() => {
        const broken = [];

        const outside = [];
        let together = 0;
        document.querySelectorAll('.sme-tb-cluster').forEach((cluster) => {
            const buttons = Array.from(cluster.querySelectorAll('button'));
            if (buttons.length < 2) {
                return;
            }
            const toolbar = cluster.closest('.sme-toolbar');
            const style = getComputedStyle(toolbar);
            const room = toolbar.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            const gap = parseFloat(getComputedStyle(cluster).columnGap) || 0;
            const width = buttons.reduce((sum, b) => sum + b.getBoundingClientRect().width, 0)
                + gap * (buttons.length - 1);
            const tops = new Set(buttons.map((b) => Math.round(b.getBoundingClientRect().top)));
            // A cluster wider than the whole toolbar has to break rather than reach out of it.
            if (width > room) {
                return;
            }
            together += 1;
            if (tops.size > 1) {
                broken.push(cluster.className + ' with ' + buttons.length + ' buttons');
            }
        });
        document.querySelectorAll('.sme-toolbar').forEach((toolbar) => {
            const bar = toolbar.getBoundingClientRect();
            toolbar.querySelectorAll('.sme-tb-cluster button').forEach((button) => {
                const box = button.getBoundingClientRect();
                if (box.right > bar.right + 1 || box.left < bar.left - 1) {
                    outside.push(button.getAttribute('aria-label'));
                }
            });
        });

        return {
            broken: broken,
            outside: outside,
            together: together,
            clusters: document.querySelectorAll('.sme-tb-cluster').length
        };
    });

    // Only groups of more than five buttons have clusters; the fixture has several, and most of
    // them fit even at this width.
    expect(clusters.clusters).toBeGreaterThan(0);
    expect(clusters.together).toBeGreaterThan(0);
    expect(clusters.broken, JSON.stringify(clusters.broken)).toEqual([]);
    expect(clusters.outside, JSON.stringify(clusters.outside)).toEqual([]);
});
