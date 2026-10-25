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
 * What NVDA says about the editor (#69).
 *
 * Same site, same seed, same helpers and the same accounts as every other spec in this
 * directory - only the browser is on Windows and a screen reader is listening. Run it against
 * the site you already use:
 *
 *   SME_NVDA=1 npx playwright test --project=nvda
 *
 * See README.md for the two commands that come before it.
 *
 * It does not replace the person signing the release off. Whether an announcement is
 * understandable is a judgement; what this settles is whether there is one at all. Every run
 * writes the transcripts next to the report, so the sample is dated and archived rather than
 * remembered.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {nvdaTest: test} = require('@guidepup/playwright');
const {expect} = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const {env, loginAs, open, requireFixture} = require('./helpers');

// Everything NVDA says after a command, not only its first utterance: role and state often come
// as a second one ("check box", then "checked").
test.use({nvdaStartOptions: {capture: true}});

/**
 * Keep what NVDA said, with the commit and the date on top.
 *
 * @param {string} name File name without extension.
 * @param {Array} phrases Spoken phrases.
 * @returns {void}
 */
function archive(name, phrases) {
    const dir = path.join(__dirname, 'transcripts');

    fs.mkdirSync(dir, {recursive: true});
    fs.writeFileSync(
        path.join(dir, `${name}.txt`),
        [
            `# ${name}`,
            `# recorded ${new Date().toISOString()}`,
            `# commit ${process.env.GITHUB_SHA || 'local'}`,
            `# site ${process.env.SME_BASE_URL || 'default'}`,
            '',
            ...phrases,
        ].join('\n'),
        'utf8'
    );
}

/**
 * Everything NVDA said, lower-cased, for readable assertions.
 *
 * @param {Object} nvda The Guidepup NVDA instance.
 * @returns {Promise<string>} One string.
 */
async function spoken(nvda) {
    return (await nvda.spokenPhraseLog()).join(' | ').toLowerCase();
}

/**
 * Open an attempt as a seeded student, exactly as the other specs do.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @returns {Promise<void>}
 */
async function attempt(page) {
    await loginAs(page, 'sme_student12', env('SME_USER_PASS'));
    await open(page, `/mod/quiz/view.php?id=${env('SME_LOAD_CMID')}`);
    await page.getByRole('button',
        {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();

    const start = page.getByRole('button', {name: /Start attempt/});
    if (await start.count()) {
        await start.click();
    }

    await page.waitForSelector('.sme-mq-container, .sme-toolbar', {timeout: 60000});
    // The ARIA labels and the switch are attached once MathQuill has settled.
    await page.waitForTimeout(2000);
}

/**
 * Open an attempt and make the browser the window NVDA is listening to.
 *
 * NVDA reads the foreground window. Without this, a test that sets the focus itself would be
 * talking to a browser NVDA is not following, and the transcript would be empty.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {Object} nvda The Guidepup NVDA instance.
 * @returns {Promise<void>}
 */
async function listen(page, nvda) {
    await attempt(page);
    await nvda.navigateToWebContent();
    // navigateToWebContent ends by moving NVDA to the top of the page; let that settle before a
    // test sets the focus itself, or the two race.
    await page.waitForTimeout(1000);
    await nvda.clearSpokenPhraseLog();
}

/**
 * Put the focus on an element and make sure it is there before NVDA is asked to act.
 *
 * The first NVDA run on the release commit passed "toolbar buttons are announced by name" with a
 * transcript of the site navigation: the focus had not been on the toolbar when Tab was pressed,
 * and the assertions were satisfied by any five buttons anywhere. A test that sets the focus
 * therefore proves that it is set.
 *
 * @param {import('@playwright/test').Locator} target Element to focus.
 * @returns {Promise<void>}
 */
async function focusOn(target) {
    await target.scrollIntoViewIfNeeded();
    await target.focus();
    await expect(target).toBeFocused();
}

/**
 * What has the focus, in words a failure message can use.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @returns {Promise<string>} The element's label, or its tag and text.
 */
function focused(page) {
    return page.evaluate(() => {
        const active = document.activeElement;
        if (!active) {
            return 'nothing';
        }
        return active.getAttribute('aria-label')
            || `${active.tagName.toLowerCase()} "${(active.textContent || '').trim().slice(0, 40)}"`;
    });
}

test.describe('NVDA reads the editor', () => {
    test('the editor announces itself as an editable field with a name', async({page, nvda}) => {
        await listen(page, nvda);

        // The toolbar comes before the field in the tab order, with some seventy buttons. Start
        // on its last button and let NVDA make the one step into the field - forty Tabs from
        // the top of the page, as this test first did, end in the middle of the toolbar.
        await focusOn(page.locator('.sme-input-wrap').first().locator('.sme-tb-btn').last());
        await nvda.clearSpokenPhraseLog();
        await nvda.press('Tab');
        await page.waitForTimeout(1000);
        await expect(page.locator('.sme-input-wrap').first().locator('.mq-editable-field textarea'))
            .toBeFocused();

        const log = await spoken(nvda);
        archive('editor-field', await nvda.spokenPhraseLog());

        expect(log).toMatch(/edit|bearbeit/);
        expect(log).toMatch(/formula|formel|answer|antwort|math/);
    });

    test('the on/off switch announces its role and its state', async({page, nvda}) => {
        await listen(page, nvda);

        const toggle = page.locator('.sme-toggle input[type="checkbox"]').first();
        requireFixture(test, await toggle.count() > 0, 'the student switch (seed --all-groups sets allowstudenttoggle)');

        await focusOn(toggle);
        await nvda.clearSpokenPhraseLog();
        await nvda.press('Shift+Tab');
        await nvda.press('Tab');

        const roleLog = await spoken(nvda);

        await nvda.clearSpokenPhraseLog();
        await nvda.press('Space');
        await page.waitForTimeout(1500);

        const stateLog = await spoken(nvda);
        archive('editor-switch', await nvda.spokenPhraseLog());

        expect(`${roleLog} ${stateLog}`).toMatch(/switch|schalter|checkbox|kontrollkästchen/);
        expect(stateLog).toMatch(/on|off|ein|aus|pressed|aktiviert|deaktiviert/);
    });

    test('toolbar buttons are announced by name, not by symbol', async({page, nvda}) => {
        await listen(page, nvda);

        const heard = [];

        const buttons = page.locator('.sme-input-wrap').first().locator('.sme-tb-btn');

        // In browse mode NVDA does not tab from the focused element but from its own cursor
        // wherever that is - after navigateToWebContent, at the top of the page. That is where
        // the first Tab of this test went, twice. A keyboard user on a toolbar is in focus mode,
        // where Tab goes from the focus; whether NVDA is in it cannot be asked, only seen. So:
        // press Tab, look where the focus went, and switch the mode once if it went elsewhere.
        let mode = 'as found';
        await focusOn(buttons.first());
        await nvda.clearSpokenPhraseLog();
        await nvda.press('Tab');
        if (!await buttons.nth(1).evaluate((button) => button === document.activeElement)) {
            heard.push(`first Tab went to: ${await focused(page)} - "${await spoken(nvda)}"`);
            await nvda.perform(nvda.keyboardCommands.toggleBetweenBrowseAndFocusMode, {capture: false});
            mode = 'after switching between browse and focus mode';
            await focusOn(buttons.first());
            await nvda.clearSpokenPhraseLog();
            await nvda.press('Tab');
        }
        heard.push(`NVDA mode: ${mode}`);

        // Each Tab has to land on the next toolbar button, and NVDA has to say that button's
        // name - the word from the language pack, not the symbol on its face.
        const missing = [];
        try {
            for (let i = 1; i <= 5; i++) {
                if (i > 1) {
                    await nvda.clearSpokenPhraseLog();
                    await nvda.press('Tab');
                }
                const label = await buttons.nth(i).getAttribute('aria-label');
                const said = await spoken(nvda);
                heard.push(`${label}: ${said}`);

                // Named in the message, so a miss says where the focus is instead of "inactive".
                expect(await focused(page), `Tab ${i} must land on toolbar button ${i + 1}`).toBe(label);

                const word = String(label || '').toLowerCase().match(/[a-zäöüß]{3,}/);
                if (!word || !said.includes(word[0]) || !/button|schaltfläche/.test(said)) {
                    missing.push(`${label} -> "${said}"`);
                }
            }
        } finally {
            // Also when it fails: what NVDA said is what explains the failure.
            archive('toolbar-buttons', heard);
        }

        expect(missing, 'buttons NVDA did not announce by name and role').toEqual([]);
    });

    test('the matrix chooser announces itself and gives the focus back', async({page, nvda}) => {
        await listen(page, nvda);

        const chooser = page.locator('.sme-tb-btn[data-command="matrix"]').first();
        requireFixture(test, await chooser.count() > 0, 'the matrix chooser (seed --all-groups switches the group on)');

        await focusOn(chooser);
        await nvda.clearSpokenPhraseLog();
        await nvda.press('Enter');
        await page.waitForTimeout(1500);

        const openLog = await spoken(nvda);

        await nvda.press('Escape');
        await page.waitForTimeout(1000);
        archive('matrix-chooser', await nvda.spokenPhraseLog());

        expect(openLog).toMatch(/dialog|grid|tabelle|raster|matrix/);
        await expect(chooser).toBeFocused();
    });

    test('the core flow is recorded for the person signing it off', async({page, nvda}) => {
        await listen(page, nvda);

        for (let i = 0; i < 30; i++) {
            await nvda.next();
        }

        const phrases = await nvda.spokenPhraseLog();
        archive('core-flow', phrases);

        expect(phrases.length).toBeGreaterThan(10);
    });
});
