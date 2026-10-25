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
 * Issue #45: differential operators are operations, not symbols.
 *
 * Maxima has no gradient, divergence or curl that works everywhere, so the CAS name is a site
 * setting. An operator without a configured name is reported instead of being written out - a
 * wrong CAS expression is worse than a message.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const tex2max = loadAmd('tex2max');
const max2tex = loadAmd('max2tex');

const CONFIGURED = {
    defs: {
        diffOps: {
            gradient: 'grad',
            divergence: 'div',
            curl: 'curl',
            laplacian: 'laplacian'
        }
    }
};

describe('tex2max: configured operators', () => {
    test.each([
        ['\\operatorname{grad}\\left(f\\right)', 'grad(f)'],
        ['\\operatorname{div}\\left(F\\right)', 'div(F)'],
        ['\\operatorname{rot}\\left(F\\right)', 'curl(F)'],
        ['\\Delta\\left(f\\right)', 'laplacian(f)']
    ])('%s becomes %s', (latex, maxima) => {
        expect(tex2max.convert(latex, CONFIGURED)).toBe(maxima);
    });

    test('the UI label never becomes the CAS name', () => {
        // "rot" is German for curl; the CAS function on this site is called curl.
        expect(tex2max.convert('\\operatorname{rot}\\left(F\\right)', CONFIGURED))
            .not.toContain('rot');
    });

    test('a nested and a multi-variable operand', () => {
        expect(tex2max.convert('\\operatorname{grad}\\left(x^{2}+y^{2}\\right)', CONFIGURED))
            .toBe('grad(x^2+y^2)');
        expect(tex2max.convert('\\operatorname{div}\\left(\\operatorname{grad}\\left(f\\right)\\right)', CONFIGURED))
            .toBe('div(grad(f))');
    });

    test('an operator on a vector', () => {
        expect(tex2max.convert(
            '\\operatorname{div}\\left(\\begin{pmatrix}x\\\\y\\end{pmatrix}\\right)',
            CONFIGURED
        )).toBe('div(matrix([x],[y]))');
    });

    test('the site decides the name', () => {
        const own = {defs: {diffOps: {gradient: 'myGradient'}}};
        expect(tex2max.convert('\\operatorname{grad}\\left(f\\right)', own)).toBe('myGradient(f)');
    });
});

describe('tex2max: operators that are not available', () => {
    test.each([
        '\\operatorname{grad}\\left(f\\right)',
        '\\operatorname{div}\\left(F\\right)',
        '\\operatorname{rot}\\left(F\\right)',
        '\\Delta\\left(f\\right)'
    ])('%s is reported, not guessed', (latex) => {
        const result = tex2max.analyse(latex);
        expect(result.problems).toContain('diffop_unavailable');
        expect(result.maxima).toBe('');
    });

    test('an operator without an operand is reported', () => {
        // What the display-only buttons of earlier versions produced.
        const result = tex2max.analyse('\\mathrm{grad}\\,f', CONFIGURED);
        expect(result.problems).toContain('diffop_operand_missing');
        expect(result.maxima).toBe('');
    });

    test('no invalid CAS string is produced', () => {
        for (const latex of [
            '\\operatorname{rot}',
            '\\operatorname{div}',
            '\\mathrm{rot}\\,',
            '\\Delta\\left(\\right)'
        ]) {
            const result = tex2max.analyse(latex, CONFIGURED);
            expect(result.maxima).not.toMatch(/\\/);
            expect(result.maxima).not.toMatch(/rot/);
        }
    });
});

describe('tex2max: bare symbols stay symbols', () => {
    test.each([
        ['\\Delta', 'Delta'],
        ['\\Delta x', 'Delta x'],
        ['2\\Delta', '2Delta'],
        ['\\Delta+1', 'Delta+1'],
        ['\\nabla', 'nabla'],
        ['\\nabla+1', 'nabla+1']
    ])('%s stays %s', (latex, maxima) => {
        expect(tex2max.convert(latex, CONFIGURED)).toBe(maxima);
        expect(tex2max.analyse(latex, CONFIGURED).problems).toEqual([]);
    });

    test('no backslash reaches the CAS (#39)', () => {
        expect(tex2max.convert('\\nabla', CONFIGURED)).not.toMatch(/\\/);
        expect(tex2max.convert('\\Delta', CONFIGURED)).not.toMatch(/\\/);
    });
});

describe('max2tex: the label comes back', () => {
    test.each([
        ['grad(f)', '\\operatorname{grad}(f)'],
        ['div(F)', '\\operatorname{div}(F)'],
        ['curl(F)', '\\operatorname{rot}(F)'],
        ['laplacian(f)', '\\Delta(f)']
    ])('%s is drawn as %s', (maxima, latex) => {
        expect(max2tex.convert(maxima, CONFIGURED)).toBe(latex);
    });

    test('without a configured name nothing is claimed', () => {
        expect(max2tex.convert('grad(f)')).toBe('grad(f)');
        expect(max2tex.convert('curl(F)')).toBe('curl(F)');
    });

    test('a name is only an operator as a whole word in front of a bracket', () => {
        expect(max2tex.convert('gradient', CONFIGURED)).toBe('gradient');
        expect(max2tex.convert('nabla', CONFIGURED)).toBe('nabla');
    });
});

describe('roundtrip (#45)', () => {
    test.each([
        'grad(f)',
        'div(F)',
        'curl(F)',
        'laplacian(f)',
        'grad(x^2+y^2)',
        'div(grad(f))'
    ])('%s survives Maxima -> editor -> Maxima', (maxima) => {
        expect(tex2max.convert(max2tex.convert(maxima, CONFIGURED), CONFIGURED)).toBe(maxima);
    });
});

describe('determinant and norm from the toolbar (#34)', () => {
    test('det(A) is Maxima\'s determinant()', () => {
        expect(tex2max.convert('\\det\\left(A\\right)')).toBe('determinant(A)');
        expect(tex2max.convert('det\\left(A\\right)')).toBe('determinant(A)');
        expect(tex2max.convert('\\det\\left(\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}\\right)'))
            .toBe('determinant(matrix([a,b],[c,d]))');
    });

    test('a variable whose name starts with det is left alone', () => {
        expect(tex2max.convert('detergent')).toBe('detergent');
        expect(tex2max.convert('2det')).toBe('2det');
    });

    test('the double bar is a norm, the single bar an absolute value', () => {
        const configured = {defs: {normFunction: 'norm'}};
        expect(tex2max.convert('\\left\\|v\\right\\|', configured)).toBe('norm(v)');
        expect(tex2max.convert('\\left\\|a+b\\right\\|', configured)).toBe('norm(a+b)');
        expect(tex2max.convert('\\left|x\\right|', configured)).toBe('abs(x)');
    });

    test('without a configured norm function nothing changes', () => {
        // The site has not said what its norm is called; the previous behaviour stands.
        expect(tex2max.convert('\\left\\|v\\right\\|')).toBe('abs(v)');
    });
});
