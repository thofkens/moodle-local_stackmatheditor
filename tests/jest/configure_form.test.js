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
 * The size field of the configuration form follows the group selection.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const Form = loadAmd('configure_form');

/**
 * Build the two form elements the module binds.
 *
 * @param {string[]} selected Group keys selected at the start.
 * @returns {{groups: HTMLSelectElement, field: HTMLInputElement}} The elements.
 */
function form(selected) {
    document.body.innerHTML = '<select name="groups[]" multiple>'
        + ['basic_operators', 'matrix_operators', 'vector_operators', 'greek_lower']
            .map((key) => `<option value="${key}"${selected.includes(key) ? ' selected' : ''}>${key}</option>`)
            .join('')
        + '</select><input id="id_sme_maxdimension" value="5">';
    return {
        groups: document.querySelector('select'),
        field: document.getElementById('id_sme_maxdimension'),
    };
}

/**
 * Select exactly these groups and tell the form, as a user's change does.
 *
 * @param {HTMLSelectElement} groups The multiselect.
 * @param {string[]} keys Group keys to select.
 */
function choose(groups, keys) {
    Array.from(groups.options).forEach((option) => {
        option.selected = keys.includes(option.value);
    });
    groups.dispatchEvent(new Event('change'));
}

describe('configure form: size field', () => {
    test('matrix off and vector off: disabled', () => {
        const {field} = form(['basic_operators', 'greek_lower']);
        expect(Form.init()).toBe(true);
        expect(field.disabled).toBe(true);
        expect(field.getAttribute('aria-disabled')).toBe('true');
    });

    test('matrix on: enabled', () => {
        const {field} = form(['matrix_operators']);
        Form.init();
        expect(field.disabled).toBe(false);
        expect(field.getAttribute('aria-disabled')).toBe('false');
    });

    test('vector on: enabled', () => {
        const {field} = form(['basic_operators', 'vector_operators']);
        Form.init();
        expect(field.disabled).toBe(false);
    });

    test('follows the selection while the form is open and keeps the value', () => {
        const {groups, field} = form(['matrix_operators']);
        Form.init();
        field.value = '7';

        choose(groups, ['basic_operators']);
        expect(field.disabled).toBe(true);
        expect(field.value).toBe('7');

        choose(groups, ['vector_operators']);
        expect(field.disabled).toBe(false);
        expect(field.value).toBe('7');
    });

    test('a form without the field or the list is left alone', () => {
        document.body.innerHTML = '<select name="groups[]" multiple></select>';
        expect(Form.init()).toBe(false);
        document.body.innerHTML = '<input id="id_sme_maxdimension">';
        expect(Form.init()).toBe(false);
    });

    test('needsDimension', () => {
        expect(Form.needsDimension([])).toBe(false);
        expect(Form.needsDimension(['trigonometry'])).toBe(false);
        expect(Form.needsDimension(['trigonometry', 'matrix_operators'])).toBe(true);
        expect(Form.needsDimension(['vector_operators'])).toBe(true);
    });
});
