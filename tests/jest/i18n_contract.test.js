/**
 * @jest-environment jsdom
 */
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
 * Visible text comes from the language pack, not from English fallbacks in the code (#92).
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const fs = require('fs');
const path = require('path');
const {loadAmd} = require('./amd_loader');

const SRC = path.join(__dirname, '..', '..', 'amd', 'src');

describe('language strings in the AMD sources', () => {
    test('no English fallback text after an injected string', () => {
        const offenders = [];
        fs.readdirSync(SRC).filter((file) => file.endsWith('.js')).forEach((file) => {
            fs.readFileSync(path.join(SRC, file), 'utf8').split('\n').forEach((line, index) => {
                // strings.x || 'Some text' - an English sentence the language pack never sees.
                if (/strings\.\w+\s*\|\|\s*'[A-Za-z][^']*\s[^']*'/.test(line)
                    || /^\s*\|\|\s*'[A-Z][a-z]+ [^']*'/.test(line)) {
                    offenders.push(`${file}:${index + 1}: ${line.trim()}`);
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    test('the popup has no English defaults', () => {
        const source = fs.readFileSync(path.join(SRC, 'structured_popup.js'), 'utf8');
        const block = source.slice(source.indexOf('var strings = {'), source.indexOf('};', source.indexOf('var strings = {')));
        const values = block.match(/:\s*'([^']*)'/g).map((value) => value.replace(/^:\s*'|'$/g, ''));
        expect(values.length).toBeGreaterThan(0);
        values.forEach((value) => expect(value).toMatch(/^\[\[popup_[a-z_]+\]\]$/));
    });
});

describe('a string that was not injected', () => {
    let input;

    beforeEach(() => {
        window.localStorage.clear();
        document.body.innerHTML = '<input id="ans1" value="">';
        input = document.getElementById('ans1');
    });

    function build(strings) {
        return loadAmd('editor_toggle').create({
            input: input,
            editor: [],
            strings: strings,
            id: 'sme-i18n-' + Math.random(),
            toInput: () => undefined,
            toEditor: () => undefined,
        });
    }

    test('shows Moodle\'s missing-string marker instead of English', () => {
        const toggle = build({});
        document.body.appendChild(toggle.element);
        toggle.apply(true, true);

        const box = toggle.element.querySelector('input[type="checkbox"]');
        expect(box.getAttribute('aria-label')).toBe('[[toggle_editor]]');
        expect(toggle.element.querySelector('label').textContent).toBe('[[toggle_editor]]');
        expect(toggle.element.textContent).not.toMatch(/Formula editor/);
        expect(toggle.element.querySelector('.sme-toggle-status').textContent).toBe('[[toggle_editor_on]]');
    });

    test('uses the injected strings when they are there', () => {
        const toggle = build({
            toggle_editor: 'Formeleditor',
            toggle_editor_on: 'Formeleditor an.',
            toggle_editor_off: 'Formeleditor aus.',
        });
        document.body.appendChild(toggle.element);
        toggle.apply(false, true);

        expect(toggle.element.querySelector('label').textContent).toBe('Formeleditor');
        expect(toggle.element.querySelector('.sme-toggle-status').textContent).toBe('Formeleditor aus.');
    });
});
