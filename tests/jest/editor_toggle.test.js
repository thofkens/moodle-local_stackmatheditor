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
 * Issue #13: students can put the formula editor away.
 *
 * Switching off hands the answer to the original STACK input and brings that input back into
 * its place; switching on takes the input's current value - including anything typed while the
 * editor was away - back into the editor.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const Toggle = loadAmd('editor_toggle');

describe('editor toggle', () => {
    let input;
    let toolbar;
    let container;
    let calls;

    function build(strings) {
        return Toggle.create({
            input: input,
            editor: [toolbar, container],
            strings: strings || {},
            id: 'sme-toggle-ans1',
            toInput: () => {
                calls.push('toInput');
                input.value = 'x^2+1';
            },
            toEditor: () => {
                calls.push('toEditor:' + input.value);
            }
        });
    }

    beforeEach(() => {
        window.localStorage.clear();
        document.body.innerHTML = '';
        calls = [];

        input = document.createElement('input');
        input.type = 'text';
        input.id = 'q1:1_ans1';
        toolbar = document.createElement('div');
        container = document.createElement('div');
        document.body.append(toolbar, container, input);
    });

    test('the checkbox id can never look like a STACK input', () => {
        // An id ending in _ans1 made the editor treat its own switch as an answer field, which
        // built an editor around it, which added a switch, and so on (#13).
        const first = build();
        const second = build();
        const ids = [
            first.element.querySelector('input').id,
            second.element.querySelector('input').id
        ];

        ids.forEach((id) => {
            expect(id).not.toMatch(/_ans/);
            expect(id).toMatch(/^sme-editor-switch-\d+$/);
        });
        expect(ids[0]).not.toBe(ids[1]);
    });

    test('it is a switch, labelled and keyboard reachable', () => {
        const toggle = build({toggle_editor: 'Formeleditor'});
        document.body.prepend(toggle.element);

        const box = toggle.element.querySelector('input');
        expect(box.type).toBe('checkbox');
        expect(box.getAttribute('role')).toBe('switch');
        expect(box.getAttribute('aria-label')).toBe('Formeleditor');
        expect(toggle.element.querySelector('label').getAttribute('for')).toBe(box.id);
        expect(box.id).not.toMatch(/_ans/);
        expect(toggle.element.querySelector('label').textContent).toBe('Formeleditor');
    });

    test('switching off hands the answer over and shows the original input', () => {
        const toggle = build();
        toggle.apply(true, true);
        calls = [];

        toggle.apply(false, false);

        expect(calls).toEqual(['toInput']);
        expect(input.value).toBe('x^2+1');
        expect(input.classList.contains(Toggle.HIDDEN_INPUT_CLASS)).toBe(false);
        expect(toolbar.classList.contains(Toggle.HIDDEN_CLASS)).toBe(true);
        expect(container.classList.contains(Toggle.HIDDEN_CLASS)).toBe(true);
    });

    test('the first application does not transfer anything back', () => {
        const toggle = build();

        toggle.apply(true, true);

        expect(calls).toEqual([]);
        expect(input.classList.contains(Toggle.HIDDEN_INPUT_CLASS)).toBe(true);
    });

    test('switching on takes what is in the input now', () => {
        const toggle = build();
        toggle.apply(false, true);
        // The student typed into the plain input while the editor was away.
        input.value = 'sqrt(2)';
        calls = [];

        toggle.apply(true, false);

        expect(calls).toEqual(['toEditor:sqrt(2)']);
        expect(input.classList.contains(Toggle.HIDDEN_INPUT_CLASS)).toBe(true);
        expect(toolbar.classList.contains(Toggle.HIDDEN_CLASS)).toBe(false);
        expect(container.classList.contains(Toggle.HIDDEN_CLASS)).toBe(false);
    });

    test('the original input is never removed from the page', () => {
        const toggle = build();

        toggle.apply(true, true);
        expect(document.body.contains(input)).toBe(true);
        expect(input.style.display).not.toBe('none');

        toggle.apply(false, false);
        expect(document.body.contains(input)).toBe(true);
    });

    test('clicking the switch drives the same path', () => {
        const toggle = build();
        document.body.prepend(toggle.element);
        toggle.apply(true, true);
        calls = [];

        const box = toggle.element.querySelector('input');
        box.checked = false;
        box.dispatchEvent(new window.Event('change'));
        expect(calls).toEqual(['toInput']);

        box.checked = true;
        box.dispatchEvent(new window.Event('change'));
        expect(calls[1]).toMatch(/^toEditor:/);
    });

    test('the state is announced, not only shown', () => {
        const toggle = build({toggle_editor_on: 'An.', toggle_editor_off: 'Aus.'});
        const status = toggle.element.querySelector('.sme-toggle-status');
        expect(status.getAttribute('aria-live')).toBe('polite');

        toggle.apply(false, true);
        expect(status.textContent).toBe('Aus.');
        toggle.apply(true, false);
        expect(status.textContent).toBe('An.');
    });

    test('the choice is remembered, the initial application is not a choice', () => {
        const toggle = build();

        toggle.apply(false, true);
        expect(Toggle.startsOff()).toBe(false);

        toggle.apply(false, false);
        expect(Toggle.startsOff()).toBe(true);

        toggle.apply(true, false);
        expect(Toggle.startsOff()).toBe(false);
    });

    test('blocked storage does not break the switch', () => {
        // Private mode: the browser refuses to store anything.
        const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('denied');
        });
        const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });

        const toggle = build();
        expect(() => toggle.apply(false, false)).not.toThrow();
        expect(Toggle.startsOff()).toBe(false);
        expect(toolbar.classList.contains(Toggle.HIDDEN_CLASS)).toBe(true);

        setItem.mockRestore();
        getItem.mockRestore();
    });
});

describe('the switch on a system of equations (#13)', () => {
    // The system editor hands its lines over as one nounand-joined answer, and takes that
    // answer - or whatever was typed while it was away - back into its rows.
    let input;
    let editor;
    let rows;
    let rebuilt;

    const SYSTEM = '(a+2*b=5) nounand (2*a+6*b=-2)';

    function joinRows() {
        return rows.map((row) => '(' + row + ')').join(' nounand ');
    }

    function build() {
        let handedOver = null;

        return Toggle.create({
            input: input,
            editor: [editor],
            strings: {},
            id: 'sme-toggle-system',
            toInput: () => {
                input.value = joinRows();
                handedOver = input.value;
            },
            toEditor: () => {
                if (input.value !== handedOver) {
                    rebuilt = input.value;
                    rows = input.value
                        ? input.value.split(' nounand ').map((part) => part.replace(/^\(|\)$/g, ''))
                        : [''];
                }
            }
        });
    }

    beforeEach(() => {
        window.localStorage.clear();
        document.body.innerHTML = '';
        rebuilt = null;
        rows = ['a+2*b=5', '2*a+6*b=-2'];
        input = document.createElement('input');
        editor = document.createElement('div');
        document.body.append(editor, input);
    });

    test('switching off writes the lines as one nounand answer', () => {
        const toggle = build();
        toggle.apply(true, true);

        toggle.apply(false, false);

        expect(input.value).toBe(SYSTEM);
        expect(input.classList.contains(Toggle.HIDDEN_INPUT_CLASS)).toBe(false);
        expect(editor.classList.contains(Toggle.HIDDEN_CLASS)).toBe(true);
    });

    test('switching on without a change keeps the rows as they are', () => {
        const toggle = build();
        toggle.apply(true, true);
        toggle.apply(false, false);

        toggle.apply(true, false);

        expect(rebuilt).toBeNull();
        expect(rows).toEqual(['a+2*b=5', '2*a+6*b=-2']);
        expect(input.classList.contains(Toggle.HIDDEN_INPUT_CLASS)).toBe(true);
    });

    test('an edit made while the editor was away is taken over', () => {
        const toggle = build();
        toggle.apply(true, true);
        toggle.apply(false, false);

        input.value = '(a=1) nounand (b=2) nounand (c=3)';
        toggle.apply(true, false);

        expect(rebuilt).toBe('(a=1) nounand (b=2) nounand (c=3)');
        expect(rows).toEqual(['a=1', 'b=2', 'c=3']);
    });

    test('an answer that is no longer a system is not thrown away', () => {
        const toggle = build();
        toggle.apply(true, true);
        toggle.apply(false, false);

        input.value = 'x=1';
        toggle.apply(true, false);

        expect(rows).toEqual(['x=1']);
    });
});

describe('the switch is a permission, not a preference (#73)', () => {
    // The runtime decides from the server-resolved per-slot flag; these cases mirror what
    // mayToggleEditor() in input_fields.js is given.
    function mayToggle(ctx, slot) {
        if (ctx.allowStudentToggle === false) {
            return false;
        }
        const perslot = ctx.slotStudentToggle || {};
        if (Object.prototype.hasOwnProperty.call(perslot, slot)) {
            return !!perslot[slot];
        }
        return true;
    }

    test('the site can take it away for every slot', () => {
        const ctx = {allowStudentToggle: false, slotStudentToggle: {1: true}};
        expect(mayToggle(ctx, '1')).toBe(false);
    });

    test('a slot decides for itself when the site allows it', () => {
        const ctx = {allowStudentToggle: true, slotStudentToggle: {1: true, 2: false}};
        expect(mayToggle(ctx, '1')).toBe(true);
        expect(mayToggle(ctx, '2')).toBe(false);
    });

    test('a slot the server said nothing about keeps the switch', () => {
        expect(mayToggle({allowStudentToggle: true, slotStudentToggle: {}}, '3')).toBe(true);
        expect(mayToggle({}, '3')).toBe(true);
    });

    test('a remembered preference cannot bring the switch back', () => {
        // The author switched it off; the browser remembers the editor as off. The editor has
        // to stay on, and no switch is offered.
        window.localStorage.setItem(Toggle.STORAGE_KEY, '1');
        expect(Toggle.startsOff()).toBe(true);

        const ctx = {allowStudentToggle: false, slotStudentToggle: {1: false}};
        const allowed = mayToggle(ctx, '1');
        const editorOn = allowed ? !Toggle.startsOff() : true;

        expect(allowed).toBe(false);
        expect(editorOn).toBe(true);
    });
});
