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
 * Bridge between the visible MathQuill editors and the original STACK inputs.
 *
 * Guiding invariant: the expression visible in MathQuill is the expression STACK receives.
 *
 * - triggerValidation(): tells STACK (and any other script) that the original input changed.
 *   Exactly one native "input" and one native "change" event. Native events reach jQuery
 *   handlers as well, so additional jQuery triggers would only produce duplicates.
 * - register(flush): every editor registers a function that writes its current state into the
 *   original input without raising events. All of them run in a capture-phase listener before
 *   a form is submitted (and already on pointerdown of a submit button), so "Check" and
 *   "Submit" never send a value that a pending debounced sync has not written yet. The native
 *   event path is not touched: no preventDefault, no own submit logic.
 * - guardStaleValidation(): STACK shows an "invalid" AJAX response without checking whether
 *   it still belongs to the current value. A slow response for a transient state such as
 *   "(2)/()" can therefore arrive after the final "(1)/(6)" was validated and overwrite it.
 *   When that can have happened, validation of the current value is requested once more;
 *   STACK answers from its cache if the value was already validated.
 *
 * Integration contract for external scripts - see README.md, "Integration events".
 * All events bubble and carry detail.source = 'local_stackmatheditor':
 * - stackmatheditor:input        on the original input, after the editor changed its value;
 * - stackmatheditor:enter        on the original input, for Enter in the visible editor and
 *                                for the "+" (add row) buttons; plus a non-bubbling keydown /
 *                                keyup Enter mirror on the original input;
 * - stackmatheditor:beforecheck  on a STACK Check button, after all editors were flushed;
 * - stackmatheditor:beforesubmit on the form, after all editors were flushed.
 * The editor never cancels, replaces or isolates the native events.
 *
 * @module     local_stackmatheditor/stack_bridge
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /** @type {number} STACK's typing delay before it sends a validation request (input.js). */
    var STACK_TYPING_DELAY = 1000;

    /** @type {Function[]} Registered flush functions. */
    var flushers = [];

    /** @type {boolean} Whether the document-level listeners are installed. */
    var installed = false;

    /** @type {WeakMap} Element → {dispatched: timestamp, revalidated: value}. */
    var state = typeof WeakMap === 'function' ? new WeakMap() : null;

    /**
     * Per-element bookkeeping.
     *
     * @param {HTMLElement} el Original STACK input.
     * @returns {Object} State object.
     */
    function stateOf(el) {
        var s;

        if (!state) {
            el.smeBridgeState = el.smeBridgeState || {dispatched: 0, revalidated: null};
            return el.smeBridgeState;
        }
        s = state.get(el);
        if (!s) {
            s = {dispatched: 0, revalidated: null};
            state.set(el, s);
        }
        return s;
    }

    /**
     * Current value as STACK reads it (trimmed).
     *
     * @param {HTMLElement} el Original STACK input.
     * @returns {string} Value.
     */
    function valueOf(el) {
        return String(el.value || '').replace(/^\s+|\s+$/g, '');
    }

    /** @type {string} Prefix of all documented integration events. */
    var EVENT_PREFIX = 'stackmatheditor:';

    /**
     * Dispatch one documented integration event.
     *
     * @param {EventTarget} target Element to dispatch on.
     * @param {string} name Event name without prefix.
     * @param {Object} [detail] Event detail.
     */
    function emit(target, name, detail) {
        var data = {source: 'local_stackmatheditor'};
        Object.keys(detail || {}).forEach(function(key) {
            data[key] = detail[key];
        });
        target.dispatchEvent(new CustomEvent(EVENT_PREFIX + name, {bubbles: true, detail: data}));
    }

    /**
     * Notify STACK and other listeners that the original input changed.
     *
     * @param {HTMLElement} el Original STACK input (input or textarea).
     */
    function triggerValidation(el) {
        stateOf(el).dispatched = Date.now();
        el.dispatchEvent(new Event('input', {bubbles: true, cancelable: true}));
        el.dispatchEvent(new Event('change', {bubbles: true, cancelable: true}));
        emit(el, 'input', {name: el.name, value: el.value});
    }

    /**
     * Signal an Enter of the visible editor to integrations.
     *
     * The documented CustomEvent bubbles. The keyboard mirror does not: listeners on the
     * original field receive an Enter keydown/keyup they would otherwise never see, while
     * document-level delegation keeps receiving only the real key event from the editor -
     * nobody gets Enter twice. Synthetic key events are untrusted and never submit a form.
     *
     * @param {HTMLElement} el Original STACK input.
     * @param {Object} [info] {trigger: 'key'|'button', inputType, slot}.
     */
    function signalEnter(el, info) {
        var detail = {name: el.name, trigger: 'key'};
        Object.keys(info || {}).forEach(function(key) {
            detail[key] = info[key];
        });
        emit(el, 'enter', detail);
        ['keydown', 'keyup'].forEach(function(type) {
            var ev = new KeyboardEvent(type, {key: 'Enter', code: 'Enter', bubbles: false, cancelable: true});
            // Older scripts test keyCode / which, which the constructor cannot set.
            Object.defineProperty(ev, 'keyCode', {value: 13});
            Object.defineProperty(ev, 'which', {value: 13});
            el.dispatchEvent(ev);
        });
    }

    /**
     * True for STACK's per-question Check button (name "<prefix>-submit").
     *
     * @param {HTMLElement} el Submit control.
     * @returns {boolean} Whether it is a Check button.
     */
    function isCheckButton(el) {
        return /-submit$/.test(el.getAttribute('name') || '');
    }

    /**
     * Run every registered flush function. Errors of one editor never stop the others.
     */
    function flushAll() {
        flushers.forEach(function(fn) {
            try {
                fn();
            } catch (e) {
                // A broken editor must not block the submission of the others.
            }
        });
    }

    /**
     * The submit control an event target belongs to.
     *
     * @param {EventTarget} target Event target.
     * @returns {?HTMLElement} Submit control or null.
     */
    function submitControl(target) {
        var el = target && target.closest ? target.closest('button, input') : null;
        var type;

        if (!el || !el.form) {
            return null;
        }
        type = (el.getAttribute('type') || (el.tagName === 'BUTTON' ? 'submit' : '')).toLowerCase();
        return type === 'submit' || type === 'image' ? el : null;
    }

    /**
     * Install the capture-phase listeners once.
     */
    function install() {
        if (installed || typeof document === 'undefined') {
            return;
        }
        installed = true;
        // Pointerdown precedes the click: the value is written silently before a pending
        // debounced sync could make STACK disable the button.
        document.addEventListener('pointerdown', function(e) {
            if (submitControl(e.target)) {
                flushAll();
            }
        }, true);
        document.addEventListener('keydown', function(e) {
            if ((e.key === 'Enter' || e.key === ' ') && submitControl(e.target)) {
                flushAll();
            }
        }, true);
        // Click (mouse or keyboard) on a STACK Check button: announce the check.
        document.addEventListener('click', function(e) {
            var control = submitControl(e.target);
            if (control && isCheckButton(control)) {
                flushAll();
                emit(control, 'beforecheck', {name: control.getAttribute('name')});
            }
        }, true);
        // The form data set is built after the submit event, so this is the last safe moment.
        document.addEventListener('submit', function(e) {
            flushAll();
            emit(e.target, 'beforesubmit', {
                submitter: e.submitter ? e.submitter.getAttribute('name') : null
            });
        }, true);
    }

    /**
     * Register an editor's flush function.
     *
     * @param {Function} fn Writes the editor state into the original input without events.
     */
    function register(fn) {
        install();
        flushers.push(fn);
    }

    /**
     * Re-request validation when an "invalid" result may belong to an older value.
     *
     * @param {HTMLElement} el Original STACK input.
     */
    function guardStaleValidation(el) {
        el.addEventListener('stack-validation', function(e) {
            var detail = e.detail || {};
            var s = stateOf(el);
            var value;

            if (!detail.completed || detail.valid !== false) {
                return;
            }
            // Before STACK's typing delay has passed since our last change, the request for
            // the current value has not even been sent: its pending timer repairs the display.
            if (Date.now() - s.dispatched < STACK_TYPING_DELAY) {
                return;
            }
            value = valueOf(el);
            // Once per value: a value that really is invalid must not loop.
            if (s.revalidated === value) {
                return;
            }
            s.revalidated = value;
            el.dispatchEvent(new Event('input', {bubbles: true, cancelable: true}));
        });
    }

    return /** @alias module:local_stackmatheditor/stack_bridge */ {
        STACK_TYPING_DELAY: STACK_TYPING_DELAY,
        EVENT_PREFIX: EVENT_PREFIX,
        triggerValidation: triggerValidation,
        signalEnter: signalEnter,
        register: register,
        flushAll: flushAll,
        guardStaleValidation: guardStaleValidation
    };
});
