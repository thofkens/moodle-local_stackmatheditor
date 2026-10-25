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
 * Where a toolbar group may break when the toolbar wraps.
 *
 * Flexbox on its own breaks a group wherever the line happens to end, which turns a group of
 * seven buttons into rows of two and three and two - and a group that reads as three fragments
 * does not read as a group. The rule instead:
 *
 *   up to five buttons   the group never breaks; it moves to the next line as a whole
 *   six or more          the first three and the last three stay together, and only what lies
 *                        between them offers break points
 *
 * The decision is made here, in plain arithmetic, and the toolbar builder turns it into DOM.
 * That is deliberate: the rule is then visible in the markup and testable without a browser,
 * rather than hidden in nth-child selectors.
 *
 * @module     local_stackmatheditor/toolbar_layout
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Largest group that stays in one piece.
     *
     * @type {number}
     */
    var ATOMIC_LIMIT = 5;

    /**
     * How many buttons a cluster holds at each end of a larger group.
     *
     * @type {number}
     */
    var CLUSTER_SIZE = 3;

    /**
     * Split a group into a start cluster, a flexible middle and an end cluster.
     *
     * The three counts always add up to the number of buttons, and the order is never changed:
     * what the builder emits is what the keyboard walks through.
     *
     * @param {number} count Number of buttons in the group.
     * @returns {Object} {atomic, start, middle, end}.
     */
    function plan(count) {
        var total = typeof count === 'number' && count > 0 ? Math.floor(count) : 0;

        if (total <= ATOMIC_LIMIT) {
            // Small groups stay whole, and there is nothing to cluster.
            return {atomic: true, start: total, middle: 0, end: 0};
        }

        return {
            atomic: false,
            start: CLUSTER_SIZE,
            middle: total - (2 * CLUSTER_SIZE),
            end: CLUSTER_SIZE
        };
    }

    /**
     * Where the group may break, as button positions counted from one.
     *
     * A break "after 3" means a line may end after the third button. Used by the tests and by
     * anyone reasoning about the layout; the CSS gets the same information as DOM structure.
     *
     * @param {number} count Number of buttons in the group.
     * @returns {Array} Allowed break positions, ascending.
     */
    function breakPoints(count) {
        var layout = plan(count);
        var points = [];
        var i;

        if (layout.atomic) {
            return points;
        }

        // After the start cluster, after each middle button, and that is all: the end cluster
        // has to stay with the button before it.
        for (i = 0; i <= layout.middle; i++) {
            points.push(layout.start + i);
        }

        return points;
    }

    return /** @alias module:local_stackmatheditor/toolbar_layout */ {
        ATOMIC_LIMIT: ATOMIC_LIMIT,
        CLUSTER_SIZE: CLUSTER_SIZE,
        plan: plan,
        breakPoints: breakPoints
    };
});
