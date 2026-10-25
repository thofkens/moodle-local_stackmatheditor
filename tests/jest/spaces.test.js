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
 * Issue #64: a space the user typed is a multiplication boundary and has to survive.
 *
 * STACK has "insert stars" variants that read spaces, so "a b" and "ab" can mean different
 * things. The editor does not interpret the space - it passes it through and lets STACK decide.
 *
 * The LaTeX column below is what a real MathQuill field returns for the typed text, measured in
 * a browser with spaceBehavesLikeTab off.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const tex2max = loadAmd('tex2max');
const max2tex = loadAmd('max2tex');

describe('tex2max: the typed space survives', () => {
    test.each([
        ['a b', 'a\\ b', 'a b'],
        ['2 x', '2\\ x', '2 x'],
        ['x y z', 'x\\ y\\ z', 'x y z'],
        ['a b+c d', 'a\\ b+c\\ d', 'a b+c d'],
        ['U max', 'U\\ \\max', 'U max'],
        ['(a b)', '\\left(a\\ b\\right)', '(a b)']
    ])('typing %s', (typed, latex, maxima) => {
        expect(tex2max.convert(latex)).toBe(maxima);
    });

    test('the editor does not turn the space into a star', () => {
        expect(tex2max.convert('a\\ b')).not.toContain('*');
        expect(tex2max.convert('a\\ b')).not.toBe('ab');
    });

    test('these four stay four different expressions', () => {
        const results = [
            tex2max.convert('ab'),
            tex2max.convert('a\\ b'),
            tex2max.convert('a*b'),
            tex2max.convert('a\\cdot b')
        ];
        expect(results[0]).toBe('ab');
        expect(results[1]).toBe('a b');
        expect(new Set([results[0], results[1]]).size).toBe(2);
        expect(results[2].replace(/\s/g, '')).toBe('a*b');
    });

    test('typographic spacing commands still carry no meaning', () => {
        expect(tex2max.convert('a\\,b')).toBe('ab');
        expect(tex2max.convert('\\sqrt{\\pi }')).toBe('sqrt(pi)');
    });

    test('the explicit multiplication modes still insert their stars', () => {
        // Those modes are the editor's own feature and say what they do; the space is a
        // boundary there as well.
        expect(tex2max.convert('a\\ b', {variableMode: 'explicit_multi'})).toBe('a*b');
        expect(tex2max.convert('2\\ x', {variableMode: 'explicit_multi'})).toBe('2*x');
    });
});

describe('max2tex: a meaningful space comes back', () => {
    test.each([
        ['a b', 'a\\ b'],
        ['2 x', '2\\ x'],
        ['x y z', 'x\\ y\\ z'],
        ['a b+c d', 'a\\ b+c\\ d']
    ])('%s is drawn as %s', (maxima, latex) => {
        expect(max2tex.convert(maxima)).toBe(latex);
    });

    test('formatting whitespace is not turned into a boundary', () => {
        expect(max2tex.convert('1 + 2')).toBe('1 + 2');
        expect(max2tex.convert('a*b')).not.toContain('\\ ');
    });
});

describe('roundtrip (#64)', () => {
    test.each([
        'a b',
        '2 x',
        'x y z',
        'a b+c d'
    ])('%s survives STACK -> editor -> STACK', (maxima) => {
        expect(tex2max.convert(max2tex.convert(maxima))).toBe(maxima);
    });

    test('a b never collapses into ab', () => {
        const latex = max2tex.convert('a b');
        expect(latex).toContain('\\ ');
        expect(tex2max.convert(latex)).not.toBe('ab');
    });
});
