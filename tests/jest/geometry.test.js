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
 * Issue #63: school notation in the editor, STACK's geometry functions underneath.
 *
 * Only the constructs with a documented STACK counterpart are converted - a point as a list,
 * Distance(A,B) and Angle(A,B,C). Nothing is invented for a segment, ray, line, circle or
 * sphere.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const tex2max = loadAmd('tex2max');
const max2tex = loadAmd('max2tex');
const Model = loadAmd('structured_input');

const POINTS = {defs: {pointNotation: true}};

describe('points', () => {
    test.each([
        ['P\\left(2|3\\right)', '[2,3]'],
        ['\\left(2|3\\right)', '[2,3]'],
        ['P\\left(2|3|4\\right)', '[2,3,4]'],
        ['A\\left(x|y\\right)', '[x,y]'],
        ['P\\left(-1|2\\right)', '[-1,2]']
    ])('%s becomes %s', (latex, maxima) => {
        expect(tex2max.convert(latex)).toBe(maxima);
    });

    test.each([';', ','])('the separator %s is configurable', (separator) => {
        const defs = {defs: {coordinateSeparator: separator}};
        expect(tex2max.convert('P\\left(2' + separator + '3\\right)', defs)).toBe('[2,3]');
    });

    test('the point name is a label and does not reach the CAS', () => {
        expect(tex2max.convert('P\\left(2|3\\right)')).not.toContain('P');
    });

    test('an ordinary bracket is not a point', () => {
        expect(tex2max.convert('f\\left(x\\right)')).toBe('f(x)');
        expect(tex2max.convert('\\left(a+b\\right)')).toBe('(a+b)');
        expect(tex2max.convert('\\sin\\left(x\\right)')).toBe('sin(x)');
    });

    test('a list comes back as a point when the site asks for it', () => {
        expect(max2tex.convert('[2,3]', POINTS)).toBe('\\left(2|3\\right)');
        expect(max2tex.convert('[2,3,4]', POINTS)).toBe('\\left(2|3|4\\right)');
        // Off by default: a list is not always a point.
        expect(max2tex.convert('[2,3]')).toBe('[2,3]');
    });

    test.each(['[2,3]', '[2,3,4]', '[x,y]'])('%s survives the roundtrip', (maxima) => {
        expect(tex2max.convert(max2tex.convert(maxima, POINTS))).toBe(maxima);
    });
});

describe('distance', () => {
    test.each([
        ['d\\left(A,B\\right)', 'Distance(A,B)'],
        ['d(A,B)', 'Distance(A,B)'],
        ['\\left|\\overline{AB}\\right|', 'Distance(A,B)'],
        ['\\overline{AB}', 'Distance(A,B)']
    ])('%s becomes %s', (latex, maxima) => {
        expect(tex2max.convert(latex)).toBe(maxima);
    });

    test('the length of a segment is a distance, in any dimension', () => {
        // Distance() works on point lists and does not care about the dimension.
        expect(tex2max.convert('d\\left(P,Q\\right)+1')).toBe('Distance(P,Q)+1');
    });

    test('reverse loading', () => {
        expect(max2tex.convert('Distance(A,B)')).toBe('d\\left(A,B\\right)');
        expect(tex2max.convert(max2tex.convert('Distance(A,B)'))).toBe('Distance(A,B)');
    });

    test('a variable called d is not a distance', () => {
        expect(tex2max.convert('d\\cdot x')).not.toContain('Distance');
    });
});

describe('angle', () => {
    test('the vertex is the middle point', () => {
        expect(tex2max.convert('\\angle ABC')).toBe('Angle(A,B,C)');
        expect(tex2max.convert('\\angle PQR')).toBe('Angle(P,Q,R)');
    });

    test('reverse loading', () => {
        expect(max2tex.convert('Angle(A,B,C)')).toBe('\\angle ABC');
        expect(tex2max.convert(max2tex.convert('Angle(A,B,C)'))).toBe('Angle(A,B,C)');
    });

    test('no degrees are invented', () => {
        // STACK returns radians; the editor does not convert anything behind the scenes.
        expect(max2tex.convert('Angle(A,B,C)')).not.toContain('circ');
        expect(tex2max.convert('\\angle ABC')).not.toMatch(/180|pi/);
    });

    test('trigonometry stays separate', () => {
        expect(tex2max.convert('\\cos\\left(\\angle ABC\\right)')).toBe('cos(Angle(A,B,C))');
    });
});

describe('structured geometry model', () => {
    test('a point carries its coordinates and its name', () => {
        const point = Model.createPoint(['2', '3'], 'P');
        expect(point.type).toBe('point');
        expect(point.coordinates).toEqual(['2', '3']);
        expect(point.name).toBe('P');
    });

    test('the separator is display only', () => {
        const point = Model.createPoint(['2', '3'], 'P');
        expect(Model.pointToLatex(point, '|')).toBe('P\\left(2|3\\right)');
        expect(Model.pointToLatex(point, ';')).toBe('P\\left(2;3\\right)');
        expect(tex2max.convert(Model.pointToLatex(point, '|'))).toBe('[2,3]');
    });

    test('distance and angle keep their roles', () => {
        expect(Model.createDistance('A', 'B')).toEqual({type: 'distance', left: 'A', right: 'B'});
        expect(Model.createAngle('A', 'B', 'C').vertex).toBe('B');
    });

    test('invalid structures are refused', () => {
        expect(() => Model.createPoint([], 'P')).toThrow();
        expect(() => Model.createDistance('A', '')).toThrow();
        expect(() => Model.createAngle('A', 'B', '')).toThrow();
    });
});

describe('the other rubrics stay separate', () => {
    test('vectors and matrices are unaffected', () => {
        expect(tex2max.convert('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}'))
            .toBe('matrix([a,b],[c,d])');
        expect(tex2max.convert('\\begin{pmatrix}x\\\\y\\end{pmatrix}')).toBe('matrix([x],[y])');
        expect(max2tex.convert('matrix([a,b],[c,d])'))
            .toBe('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}');
    });

    test('the point setting does not touch matrices', () => {
        expect(max2tex.convert('matrix([a,b],[c,d])', POINTS))
            .toBe('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}');
    });

    test('trigonometry is untouched', () => {
        expect(tex2max.convert('\\sin\\left(x\\right)')).toBe('sin(x)');
    });
});

describe('cross product (#34)', () => {
    // There is no cross product in Maxima or STACK: the operator is ~ from the vect package and
    // it only produces a result inside express(). The question needs one line, load("vect"), and
    // #66 hides the button where that line is missing.
    test.each([
        ['a\\times b', 'express(a ~ b)'],
        ['\\vec{a}\\times\\vec{b}', 'express(a ~ b)'],
        ['\\sin\\left(x\\right)\\times b', 'express(sin(x) ~ b)']
    ])('%s becomes %s', (latex, maxima) => {
        expect(tex2max.convert(latex)).toBe(maxima);
    });

    test('a nested product nests the calls', () => {
        expect(tex2max.convert('\\left(a\\times b\\right)\\times c'))
            .toBe('express((express(a ~ b)) ~ c)');
    });

    test('column vectors are operands like any other', () => {
        expect(tex2max.convert(
            '\\begin{pmatrix}1\\\\2\\\\3\\end{pmatrix}\\times\\begin{pmatrix}4\\\\5\\\\6\\end{pmatrix}'
        )).toBe('express(matrix([1],[2],[3]) ~ matrix([4],[5],[6]))');
    });

    test('two numbers are a multiplication, whatever the sign looked like', () => {
        // The times sign can only reach the converter from imported content here - the button is
        // for vectors - and between two numbers it meant multiplication.
        expect(tex2max.convert('2\\times 3')).toBe('2* 3');
    });

    test('a sign without operands is not turned into a call', () => {
        expect(tex2max.convert('a\\times')).toBe('a*');
        expect(tex2max.convert('\\times b')).toBe('* b');
    });

    test('the rest of the expression is untouched', () => {
        expect(tex2max.convert('a\\times b+c')).toBe('express(a ~ b)+c');
        expect(tex2max.convert('a\\cdot b')).toBe('a* b');
    });
});
