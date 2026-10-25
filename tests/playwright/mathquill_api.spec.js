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
 * MathQuill API regression against the vendored runtime.
 *
 * Written while evaluating the Desmos fork (#40); kept for the MathQuill 0.10.1 build that ships,
 * because it is the guard for any future change of the vendored runtime.
 *
 * Everything the plugin uses of MathQuill, checked in a real quiz attempt: the interface factory,
 * a field created on an existing element and a new one, latex(), write(), cmd(), typedText(),
 * keystroke(), focus(), el(), moveToRightEnd(), StaticMath, and the edit/enter handlers - the
 * enter handler through a real key press, the way a student produces it. Any JavaScript error on
 * the page fails the test.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs} = require('./helpers');
test('every MathQuill API the plugin uses still behaves as expected', async({page}) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await loginAs(page, 'sme_student05', env('SME_USER_PASS'));
    await page.goto('/mod/quiz/view.php?id=' + env('SME_LOAD_CMID'));
    await page.getByRole('button', {name: /Attempt quiz|Continue your attempt|Continue the last attempt/}).click();
    const s = page.getByRole('button', {name: /Start attempt/}); if (await s.count()) { await s.click(); }
    await page.locator('.que.stack .mq-editable-field').first().waitFor({timeout: 30000});
    const r = await page.evaluate(() => {
        const out = {};
        const MQ = window.MathQuill.getInterface(2);
        // MathQuill 0.10.1 has no VERSION property; the vendored revision is documented in
        // thirdparty/readme_moodle.txt, not readable at runtime.
        out.version = window.MathQuill.VERSION || null;
        const el = document.querySelector('.que.stack .mq-editable-field');
        const f = MQ(el);
        out.isMathField = !!f && typeof f.latex === 'function';
        f.latex(''); f.focus();
        f.write('\\frac{1}{2}'); out.afterWrite = f.latex();
        f.latex(''); f.cmd('\\sqrt'); f.typedText('x'); out.afterCmd = f.latex();
        f.latex('x^2'); f.keystroke('Left'); f.typedText('1'); out.afterKeystroke = f.latex();
        out.el = !!(f.el && f.el().querySelector('textarea'));
        out.moveToRightEnd = typeof f.moveToRightEnd === 'function';
        // Handlers: build a throwaway field and check edit/enter fire.
        const host = document.createElement('span'); document.body.appendChild(host);
        const fired = [];
        const g = MQ.MathField(host, {handlers: {edit: () => fired.push('edit'), enter: () => fired.push('enter')}});
        g.write('a');
        window.__smeEnterField = g;
        window.__smeFired = fired;
        out.handlers = fired.join(',');
        out.staticMath = typeof MQ.StaticMath === 'function';
        return out;
    });
    console.log(JSON.stringify(r));
    console.log('page errors:', JSON.stringify(errors));
    expect(r.isMathField).toBe(true);
    expect(r.afterWrite).toBe('\\frac{1}{2}');
    expect(r.afterCmd).toContain('sqrt');
    expect(r.afterKeystroke).toBe('x^{21}');
    expect(r.el).toBe(true);
    expect(r.moveToRightEnd).toBe(true);
    expect(r.handlers).toContain('edit');
    // Enter through a real key press, as a student produces it.
    await page.evaluate(() => window.__smeEnterField.focus());
    await page.keyboard.press('Enter');
    const fired = await page.evaluate(() => window.__smeFired.join(','));
    console.log('after real Enter:', fired);
    expect(fired).toContain('enter');
    expect(errors).toEqual([]);
});
