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
 * On/off switch for the formula editor.
 *
 * A student can put the editor away - to see more of the question on a phone, or to type STACK
 * syntax directly. Switching off hands the current answer to the original STACK input in Maxima
 * syntax and puts that input back where it belongs; switching on takes whatever is in the input
 * now, including anything typed while the editor was away, back into the editor.
 *
 * The module owns the switch and the two CSS classes, nothing else: what "hand over" means is
 * the caller's business, because only the caller knows whether it is driving one field or a
 * system of equations.
 *
 * Deliberately free of dependencies: plain DOM, so the behaviour can be tested without a
 * browser and without MathQuill.
 *
 * @module     local_stackmatheditor/editor_toggle
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Key under which the choice is remembered.
     *
     * One preference for the whole site, not one per question: someone who switches the editor
     * off on a phone wants it off on the next question too.
     *
     * @type {string}
     */
    var STORAGE_KEY = 'local_stackmatheditor_editor_off';

    /**
     * Class that parks the original input off screen.
     *
     * Never display:none - the field stays focusable, stays in the accessibility tree, and stays
     * where STACK expects it for validation feedback.
     *
     * @type {string}
     */
    var HIDDEN_INPUT_CLASS = 'sme-original-hidden';

    /**
     * Counter for the checkbox ids.
     *
     * The id must not look like a STACK input: the editor looks for "_ans" in name and id, and a
     * switch called sme-toggle-q1:1_ans1 would be picked up as an input and get an editor of its
     * own, and that one another one.
     *
     * @type {number}
     */
    var switchCount = 0;

    /**
     * Class that hides the editor.
     *
     * @type {string}
     */
    var HIDDEN_CLASS = 'sme-hidden';

    /**
     * Read the remembered choice.
     *
     * @returns {boolean} True when the editor should start switched off.
     */
    function startsOff() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            // Private mode or blocked storage: the editor simply starts on.
            return false;
        }
    }

    /**
     * Remember the choice.
     *
     * @param {boolean} off True when the editor is switched off.
     */
    function remember(off) {
        try {
            window.localStorage.setItem(STORAGE_KEY, off ? '1' : '0');
        } catch (e) {
            return;
        }
    }

    /**
     * Park the original input off screen.
     *
     * @param {HTMLElement} input Original STACK input.
     */
    function hideOriginal(input) {
        input.classList.add(HIDDEN_INPUT_CLASS);
    }

    /**
     * Put the original input back in its place.
     *
     * @param {HTMLElement} input Original STACK input.
     */
    function showOriginal(input) {
        input.classList.remove(HIDDEN_INPUT_CLASS);
    }

    /**
     * A language string the caller injected, or Moodle's marker for a missing one.
     *
     * The strings are a contract with the server (definitions::get_js_strings()). An English
     * fallback here would hide a missing string from every language but English; "[[key]]" is
     * what Moodle itself shows for a missing string, and the Jest suite fails on it.
     *
     * @param {Object} strings Injected strings.
     * @param {string} key String identifier.
     * @returns {string} The text.
     */
    function text(strings, key) {
        return (typeof strings[key] === 'string' && strings[key]) ? strings[key] : '[[' + key + ']]';
    }

    /**
     * Build the switch.
     *
     * @param {Object} spec Configuration.
     * @param {HTMLElement} spec.input Original STACK input.
     * @param {Array} spec.editor Elements that make up the editor and are hidden with it.
     * @param {Function} spec.toInput Write the editor's answer into the input, as Maxima.
     * @param {Function} spec.toEditor Read the input's answer back into the editor.
     * @param {Object} spec.strings Language strings.
     * @returns {Object} The element and an apply(on, initial) function.
     */
    function create(spec) {
        var strings = spec.strings || {};
        var id = 'sme-editor-switch-' + (++switchCount);
        var element = document.createElement('div');
        var box = document.createElement('input');
        var label = document.createElement('label');
        var status = document.createElement('span');

        element.className = 'sme-toggle form-check form-switch';

        box.type = 'checkbox';
        box.id = id;
        box.className = 'form-check-input sme-toggle-input';
        box.setAttribute('role', 'switch');
        box.setAttribute('aria-label', text(strings, 'toggle_editor'));

        label.setAttribute('for', id);
        label.className = 'form-check-label sme-toggle-label';
        label.textContent = text(strings, 'toggle_editor');

        // The state is also read out, not only shown by the position of the switch.
        status.className = 'sme-toggle-status sr-only visually-hidden';
        status.setAttribute('aria-live', 'polite');

        element.appendChild(box);
        element.appendChild(label);
        element.appendChild(status);

        /**
         * Show or hide the editor.
         *
         * @param {boolean} on True for the editor, false for the plain input.
         * @param {boolean} initial True while applying the remembered choice.
         */
        function apply(on, initial) {
            if (on) {
                // On the first application the editor already holds the answer - it was just
                // built from it. Reading it back would rebuild the field for nothing, and in
                // the system editor it would throw away rows that were created a moment ago.
                if (!initial) {
                    spec.toEditor();
                }
                hideOriginal(spec.input);
            } else {
                spec.toInput();
                showOriginal(spec.input);
            }

            (spec.editor || []).forEach(function(part) {
                if (!part) {
                    return;
                }
                if (on) {
                    part.classList.remove(HIDDEN_CLASS);
                } else {
                    part.classList.add(HIDDEN_CLASS);
                }
            });

            box.checked = on;
            status.textContent = on
                ? text(strings, 'toggle_editor_on')
                : text(strings, 'toggle_editor_off');
            element.setAttribute('title', status.textContent);

            if (!initial) {
                remember(!on);
            }
        }

        box.addEventListener('change', function() {
            apply(box.checked, false);
        });

        return {element: element, apply: apply, input: box};
    }

    return /** @alias module:local_stackmatheditor/editor_toggle */ {
        STORAGE_KEY: STORAGE_KEY,
        HIDDEN_CLASS: HIDDEN_CLASS,
        HIDDEN_INPUT_CLASS: HIDDEN_INPUT_CLASS,
        startsOff: startsOff,
        remember: remember,
        hideOriginal: hideOriginal,
        showOriginal: showOriginal,
        create: create
    };
});
