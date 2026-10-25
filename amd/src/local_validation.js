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
 * Local validation messages for visible but not yet serialisable structures.
 *
 * An incomplete integral stays in the editor as the student wrote it, but no CAS expression is
 * invented for it; this module tells the student what is missing, right below the editor.
 *
 * @module     local_stackmatheditor/local_validation
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/str'], function(Str) {
    'use strict';

    /**
     * Show (or clear) the messages for the given problem codes below an editor.
     *
     * @param {HTMLElement} anchor Element after which the message box is placed.
     * @param {string[]} problems Problem codes from tex2max.analyse() (lang string keys).
     */
    function show(anchor, problems) {
        var box = anchor.nextElementSibling;
        var unique = (problems || []).filter(function(code, index, all) {
            return all.indexOf(code) === index;
        });

        if (!box || !box.classList.contains('sme-local-validation')) {
            box = document.createElement('div');
            box.className = 'sme-local-validation alert alert-warning py-1 px-2 mt-1 mb-0 small';
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            anchor.parentNode.insertBefore(box, anchor.nextSibling);
        }
        box.dataset.problems = unique.join(',');
        if (!unique.length) {
            box.hidden = true;
            box.textContent = '';
            return;
        }
        box.hidden = false;
        Str.get_strings(unique.map(function(code) {
            return {key: code, component: 'local_stackmatheditor'};
        })).then(function(texts) {
            // Ignore an answer that arrives after the problems changed again.
            if (box.dataset.problems === unique.join(',')) {
                box.textContent = texts.join(' ');
            }
            return texts;
        }).catch(function() {
            box.textContent = unique.join(', ');
        });
    }

    return /** @alias module:local_stackmatheditor/local_validation */ {
        show: show
    };
});
