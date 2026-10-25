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
 * Central mapping table for the set-theory and logic operators.
 *
 * One specification for both converters and for the tests. The Maxima side was verified against
 * the STACK 4.13 security map: set operations exist only as functions (union, intersection,
 * setdifference, elementp, subsetp), the infix words "in", "notin", "setdiff", "subset" and
 * "superset" are either unknown to STACK or mean something else ("in" is a loop keyword, subset()
 * filters by a predicate). "implies" is an allowed operator, "impliedby" and "iff" are not and are
 * rewritten.
 *
 * Two different kinds of connective must not be confused:
 * - Logic buttons (∧, ∨) write "and" / "or": STACK judges the logical statement as a whole.
 * - Structures the editor builds itself use STACK's noun operators, which keep the parts apart
 *   so that every part is assessed on its own: a solution set from ± (x=2 nounor x=-2) and an
 *   equation system (a+2b=5 nounand 2a+6b=-2). See SOLUTION_JOIN and SYSTEM_JOIN.
 *
 * Operators that need their operands (relations and set operations) travel through tex2max as
 * private-use marker characters and are turned into function calls once the operands are known.
 *
 * @module     local_stackmatheditor/operator_map
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Set operators. kind "relation": binary, lowest set precedence, becomes a predicate.
     * kind "binary"/"nary": set operations; a higher precedence binds tighter
     * (∩ over ∪ over ∖). Longest LaTeX commands first.
     *
     * @type {Object[]}
     */
    var SET_OPERATORS = [
        {name: 'notin', latex: ['\\notin'], marker: '\uE011', kind: 'relation', tex: '\\notin',
            maxima: 'not elementp(A,B)'},
        {name: 'in', latex: ['\\in'], marker: '\uE010', kind: 'relation', tex: '\\in',
            maxima: 'elementp(A,B)'},
        {name: 'subseteq', latex: ['\\subseteq'], marker: '\uE016', kind: 'relation', tex: '\\subseteq',
            maxima: 'subsetp(A,B)'},
        {name: 'supseteq', latex: ['\\supseteq'], marker: '\uE018', kind: 'relation', tex: '\\supseteq',
            maxima: 'subsetp(B,A)'},
        {name: 'subset', latex: ['\\subsetneq', '\\subset'], marker: '\uE015', kind: 'relation', tex: '\\subset',
            maxima: '(subsetp(A,B) and A#B)'},
        {name: 'supset', latex: ['\\supsetneq', '\\supset'], marker: '\uE017', kind: 'relation', tex: '\\supset',
            maxima: '(subsetp(B,A) and B#A)'},
        {name: 'setminus', latex: ['\\setminus', '\\backslash'], marker: '\uE014', kind: 'binary', precedence: 1,
            tex: '\\setminus', maxima: 'setdifference'},
        {name: 'cup', latex: ['\\cup'], marker: '\uE012', kind: 'nary', precedence: 2, tex: '\\cup',
            maxima: 'union'},
        {name: 'cap', latex: ['\\cap'], marker: '\uE013', kind: 'nary', precedence: 3, tex: '\\cap',
            maxima: 'intersection'}
    ];

    /**
     * Logic operators. Entries with a marker are rewritten structurally by tex2max; the others
     * are emitted directly as the given Maxima operator. "read" lists the legacy spellings
     * max2tex still accepts.
     *
     * @type {Object[]}
     */
    var LOGIC_OPERATORS = [
        {name: 'iff', latex: ['\\Leftrightarrow', '\\iff'], marker: '\uE01A', tex: '\\Leftrightarrow',
            maxima: '(A implies B) and (B implies A)'},
        {name: 'impliedby', latex: ['\\Leftarrow', '\\impliedby'], marker: '\uE019', tex: '\\Leftarrow',
            maxima: 'B implies A'},
        {name: 'implies', latex: ['\\Rightarrow', '\\implies'], tex: '\\Rightarrow', maxima: 'implies'},
        {name: 'and', latex: ['\\land', '\\wedge'], tex: '\\land', maxima: 'and', read: ['and', 'nounand']},
        {name: 'or', latex: ['\\lor', '\\vee'], tex: '\\lor', maxima: 'or', read: ['or', 'nounor']},
        {name: 'not', latex: ['\\neg', '\\lnot'], tex: '\\neg', maxima: 'not', read: ['not', 'nounnot']}
    ];

    /**
     * Look up an operator entry by name.
     *
     * @param {string} name Operator name.
     * @returns {?Object} Table entry or null.
     */
    function byName(name) {
        var all = SET_OPERATORS.concat(LOGIC_OPERATORS);
        var i;

        for (i = 0; i < all.length; i++) {
            if (all[i].name === name) {
                return all[i];
            }
        }
        return null;
    }

    return /** @alias module:local_stackmatheditor/operator_map */ {
        SET_OPERATORS: SET_OPERATORS,
        LOGIC_OPERATORS: LOGIC_OPERATORS,
        // Joins the alternatives of a solution set (± expansion).
        SOLUTION_JOIN: 'nounor',
        // Joins the rows of an equation / relation system.
        SYSTEM_JOIN: 'nounand',
        byName: byName
    };
});
