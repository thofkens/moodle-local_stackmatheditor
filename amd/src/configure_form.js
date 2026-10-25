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
 * Configuration form: the largest matrix or vector size follows the group selection.
 *
 * The size field only means something while the matrix or the vector group is offered. It is
 * enabled and disabled as the selection changes, while the form is open, so the dependency is
 * visible before saving and not only after it.
 *
 * @module     local_stackmatheditor/configure_form
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /** Groups whose buttons open a size chooser. */
    var STRUCTURED = ['matrix_operators', 'vector_operators'];

    /**
     * Whether a selection offers a size chooser.
     *
     * @param {string[]} values Selected group keys.
     * @returns {boolean} True when the matrix or the vector group is among them.
     */
    function needsDimension(values) {
        return values.some(function(value) {
            return STRUCTURED.indexOf(value) !== -1;
        });
    }

    /**
     * Enable or disable the size field after the current selection.
     *
     * @param {HTMLSelectElement} groups The group multiselect.
     * @param {HTMLInputElement} field The size field.
     */
    function apply(groups, field) {
        var values = Array.prototype.map.call(groups.selectedOptions, function(option) {
            return option.value;
        });
        var on = needsDimension(values);
        field.disabled = !on;
        field.setAttribute('aria-disabled', on ? 'false' : 'true');
    }

    /**
     * Bind the size field to the group selection of the form on the page.
     *
     * Does nothing on a form without either of them (the question mode has no group list of its
     * own when it inherits).
     *
     * @param {string} [groupsSelector] Selector of the group multiselect.
     * @param {string} [fieldId] Id of the size field.
     * @returns {boolean} Whether the form had both and was bound.
     */
    function init(groupsSelector, fieldId) {
        var groups = document.querySelector(groupsSelector || 'select[name="groups[]"]');
        var field = document.getElementById(fieldId || 'id_sme_maxdimension');
        if (!groups || !field) {
            return false;
        }
        groups.addEventListener('change', function() {
            apply(groups, field);
        });
        apply(groups, field);
        return true;
    }

    return {
        init: init,
        apply: apply,
        needsDimension: needsDimension
    };
});
