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
 * Issue #34: a visible button is a promise.
 *
 * Every button the toolbar offers writes LaTeX, and that LaTeX has to reach STACK as something
 * the CAS can read. This test takes the catalogue of buttons as it is exported from
 * definitions.php, fills the empty slots of each template with an operand, and converts it.
 *
 * What it proves is narrow and worth stating: no backslash survives (#39), nothing is reported
 * as an unconvertible construct, and the result is not empty. It does not prove that the CAS
 * agrees with the meaning - that needs a real question, and for the operators and the geometry
 * functions it needs the packages from #66.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const buttons = require('./fixtures/buttons.json');
const tex2max = loadAmd('tex2max');

/**
 * The site settings the contract is checked against.
 *
 * The operators and the norm only have a mapping once a site names the Maxima function; the
 * buttons do not exist without one, so the catalogue is checked with them configured.
 */
const DEFS = {
    defs: {
        normFunction: 'norm',
        diffOps: {
            gradient: 'grad',
            divergence: 'div',
            curl: 'curl',
            laplacian: 'laplacian'
        }
    }
};

/**
 * Fill the empty slots of a template so that it becomes a complete expression.
 *
 * A button writes a skeleton and puts the cursor in it; an empty skeleton is the state before
 * the student types, not the state that reaches the CAS.
 *
 * @param {string} template LaTeX the button writes.
 * @returns {string} A complete expression.
 */
function fill(template) {
    // Plain string replacement, not regular expressions: these templates are full of
    // backslashes and pipes, and an escaping mistake here would look like a failing button.
    const slots = [
        ['\\left(\\right)', '\\left(x\\right)'],
        ['\\left[\\right]', '\\left[x\\right]'],
        ['\\left\\{\\right\\}', '\\left\\{x\\right\\}'],
        ['\\left\\|\\right\\|', '\\left\\|x\\right\\|'],
        ['\\left|\\right|', '\\left|x\\right|'],
        ['{}', '{x}'],
        ['()', '(x)'],
        ['[]', '[x]']
    ];

    let latex = template;
    slots.forEach(([empty, filled]) => {
        latex = latex.split(empty).join(filled);
    });

    // A template that starts with a superscript, a subscript or an operator needs something in
    // front of it, exactly as it would have in the field.
    const needsoperands = /^[\^_]/.test(latex)
        || /^\\(cdot|div|times|pm|mp|leq|geq|neq|approx|in|notin|cup|cap|setminus|subseteq|supseteq|subset|supset|land|lor|Rightarrow|Leftarrow|Leftrightarrow|neg|perp|angle|circ)/.test(latex);

    return needsoperands ? 'a' + latex + 'b' : latex;
}

describe('every visible button has a usable mapping (#34)', () => {
    const templated = buttons.filter(
        (button) => button.kind === 'write' || button.kind === 'cmd'
    );

    test('the catalogue is not empty and covers every group', () => {
        expect(templated.length).toBeGreaterThan(50);
        expect(new Set(buttons.map((button) => button.group)).size).toBeGreaterThan(10);
    });

    test('every button has a template or is a chooser', () => {
        buttons.forEach((button) => {
            if (button.kind === 'popup' || button.kind === 'matrix') {
                expect(button.template).not.toBe('');
                return;
            }
            expect(button.kind).toMatch(/^(write|cmd)$/);
            expect(button.template).not.toBe('');
        });
    });

    test.each(
        templated.map((button) => [
            button.group + ' / ' + (button.display || button.template),
            button
        ])
    )('%s produces a CAS-safe expression', (_name, button) => {
        const latex = fill(button.template);
        const result = tex2max.analyse(latex, DEFS);

        expect(result.problems).toEqual([]);
        expect(result.maxima).not.toBe('');
        // #39: nothing that still looks like LaTeX may reach the CAS.
        expect(result.maxima).not.toMatch(/\\/);
    });

    test('the structural buttons keep their documented mapping', () => {
        // The ones where the mapping is the point of the button, spelled out rather than
        // derived, so a change in either direction has to be deliberate.
        const contracts = [
            ['\\sqrt{x}', 'sqrt(x)'],
            ['\\frac{x}{y}', '(x)/(y)'],
            ['\\operatorname{grad}\\left(f\\right)', 'grad(f)'],
            ['\\operatorname{div}\\left(F\\right)', 'div(F)'],
            ['\\operatorname{rot}\\left(F\\right)', 'curl(F)'],
            ['\\Delta\\left(f\\right)', 'laplacian(f)'],
            ['\\det\\left(A\\right)', 'determinant(A)'],
            ['\\operatorname{transpose}\\left(A\\right)', 'transpose(A)'],
            ['\\operatorname{ident}\\left(3\\right)', 'ident(3)'],
            ['\\left\\|v\\right\\|', 'norm(v)'],
            ['\\left(2|3\\right)', '[2,3]'],
            ['d\\left(A,B\\right)', 'Distance(A,B)'],
            ['\\angle ABC', 'Angle(A,B,C)'],
            ['\\left|\\overline{AB}\\right|', 'Distance(A,B)'],
            ['\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}', 'matrix([a,b],[c,d])'],
            ['\\begin{pmatrix}x\\\\y\\end{pmatrix}', 'matrix([x],[y])'],
            // Curly brackets are a Maxima set, and braces are allowed to survive for that
            // reason alone - which is why the loop above does not forbid them.
            ['\\left\\{x,y\\right\\}', '{x,y}']
        ];

        contracts.forEach(([latex, maxima]) => {
            expect(tex2max.convert(latex, DEFS)).toBe(maxima);
        });
    });
});
