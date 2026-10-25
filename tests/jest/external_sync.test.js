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
 * Issue #77: the original STACK input is the integration point in both directions.
 *
 * STACK's JSXGraph bindings write into that input and dispatch an event - one that does not
 * bubble. Until now the visible editor never heard about it, so the student saw one answer and
 * STACK had another.
 *
 * The editor itself needs MathQuill and jQuery, which this environment does not have, so these
 * tests exercise the decision the listener makes: when to adopt a value, when to ignore it, and
 * how not to write it straight back out. The listener in input_fields.js follows exactly this
 * shape; the browser proof belongs in Behat with a real JSXGraph question.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

describe('adopting an external change (#77)', () => {
    let input;
    let editor;
    let state;
    let adopted;

    /**
     * The listener as input_fields.js registers it.
     *
     * @returns {Function} The handler.
     */
    function makeListener() {
        return function(e) {
            const current = input.value;

            if (state.syncingToInput || state.adopting || state.initialPending
                || current === state.lastwritten) {
                return;
            }
            if (editor.classList.contains('sme-hidden')) {
                state.lastwritten = current;
                return;
            }

            // prefilling only keeps the editor's own edit handler quiet; it is released a tick
            // later and does not decide whether a value is adopted.
            state.prefilling = true;
            state.adopting = true;
            try {
                adopted.push({value: current, type: e && e.type});
                state.lastwritten = current;
                // The editor is told, not asked: no write-back, so no second validation.
                state.writes.push('none');
            } finally {
                state.adopting = false;
            }
            setTimeout(() => {
                state.prefilling = false;
            }, 0);
        };
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        input = document.createElement('input');
        input.type = 'text';
        input.value = '1';
        editor = document.createElement('div');
        document.body.append(editor, input);

        state = {
            prefilling: false, adopting: false, initialPending: false, syncingToInput: false,
            lastwritten: '1', writes: [],
        };
        adopted = [];

        const listener = makeListener();
        input.addEventListener('input', listener);
        input.addEventListener('change', listener);
    });

    test('a non-bubbling change event is heard', () => {
        input.value = '2';
        // Exactly what STACK dispatches: no bubbles, no composed.
        input.dispatchEvent(new window.Event('change'));

        expect(adopted).toEqual([{value: '2', type: 'change'}]);
    });

    test('an input event is heard as well', () => {
        input.value = '3';
        input.dispatchEvent(new window.Event('input'));

        expect(adopted).toEqual([{value: '3', type: 'input'}]);
    });

    test('our own echo is not adopted', () => {
        // The editor wrote 5 and raised the event STACK needs; that must not come back in.
        state.lastwritten = '5';
        input.value = '5';
        input.dispatchEvent(new window.Event('change'));

        expect(adopted).toEqual([]);
    });

    test('nothing is adopted before the initial value is in the editor', () => {
        state.initialPending = true;
        input.value = '7';
        input.dispatchEvent(new window.Event('change'));

        expect(adopted).toEqual([]);
    });

    test('an adopted value is not written back out', () => {
        input.value = '2';
        input.dispatchEvent(new window.Event('change'));

        // No write means no second STACK validation for a value STACK already has.
        expect(state.writes).toEqual(['none']);
    });

    test('alternating changes do not drift', async() => {
        for (let i = 2; i <= 11; i += 1) {
            // External side writes.
            input.value = String(i);
            input.dispatchEvent(new window.Event('change'));
            // The guard is released on the next tick, exactly as in the editor - a slider moving
            // ten times is ten gestures, not ten events in one microtask.
            await new Promise((resolve) => setTimeout(resolve, 0));

            // Editor side writes the same value back, as a student edit would.
            state.lastwritten = String(i);
            input.value = String(i);
            input.dispatchEvent(new window.Event('input'));
        }

        expect(adopted).toHaveLength(10);
        expect(adopted[9].value).toBe('11');
        expect(input.value).toBe('11');
    });

    test('a burst of external events before the next tick ends on the last value', async() => {
        // A slider dragged quickly: Chrome runs the pointer events before the timer that
        // releases the prefilling flag, so several values arrive while it is still up. The last
        // one used to be dropped and the editor stayed a step behind the input (#77). Each is
        // adopted now, and the editor ends on the value the input ends on - without waiting for
        // another event.
        for (const value of ['2', '3', '4']) {
            input.value = value;
            input.dispatchEvent(new window.Event('input'));
        }

        expect(adopted.map((a) => a.value)).toEqual(['2', '3', '4']);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(state.prefilling).toBe(false);
    });


    test('the editor writing does not come back through the listener', () => {
        // The hazard that broke eight Behat scenarios: syncToInput() dispatches the events STACK
        // listens for from inside itself, so they arrive before it has returned and before
        // lastwritten can be updated. Without the flag the editor adopts its own keystroke,
        // re-fills MathQuill from it and swallows every character after the first.
        const writeLikeTheEditor = (value) => {
            state.syncingToInput = true;
            try {
                input.value = value;
                input.dispatchEvent(new window.Event('input'));
                input.dispatchEvent(new window.Event('change'));
                // Only now, as in syncToInput(), is the write recorded.
                state.lastwritten = value;
            } finally {
                state.syncingToInput = false;
            }
        };

        writeLikeTheEditor('U');
        writeLikeTheEditor('Um');
        writeLikeTheEditor('Umax');

        expect(adopted).toEqual([]);
        expect(input.value).toBe('Umax');
    });

    test('an external change right after our own write is still adopted', () => {
        state.syncingToInput = true;
        input.value = 'U';
        input.dispatchEvent(new window.Event('change'));
        state.lastwritten = 'U';
        state.syncingToInput = false;

        input.value = '2';
        input.dispatchEvent(new window.Event('change'));

        expect(adopted).toEqual([{value: '2', type: 'change'}]);
    });

    test('with the editor switched off the input keeps the value', () => {
        editor.classList.add('sme-hidden');
        input.value = '4';
        input.dispatchEvent(new window.Event('change'));

        expect(adopted).toEqual([]);
        // Remembered, so switching the editor back on does not read it as an external change.
        expect(state.lastwritten).toBe('4');
    });

    test('an empty external value is adopted too', () => {
        input.value = '';
        input.dispatchEvent(new window.Event('change'));

        expect(adopted).toEqual([{value: '', type: 'change'}]);
    });
});
