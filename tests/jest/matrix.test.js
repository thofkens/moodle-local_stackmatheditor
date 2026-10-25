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
 * Matrix and vector conversion between MathQuill LaTeX and Maxima.
 *
 * The editor writes the environment the toolbar inserted; Maxima only knows matrix(). Both
 * directions are covered here because the pre-fill path (Maxima -> LaTeX -> editor -> Maxima)
 * has to be stable: what a student sees after reloading an attempt must convert back to what
 * was stored.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const tex2max = loadAmd('tex2max');
const max2tex = loadAmd('max2tex');

const ENVIRONMENTS = ['matrix', 'pmatrix', 'bmatrix', 'Bmatrix', 'vmatrix', 'Vmatrix'];

describe('tex2max: matrix environments', () => {
    test.each(['matrix', 'pmatrix', 'bmatrix', 'Bmatrix'])(
        '%s becomes matrix()',
        (environment) => {
            const latex = '\\begin{' + environment + '}a&b\\\\c&d\\end{' + environment + '}';
            expect(tex2max.convert(latex)).toBe('matrix([a,b],[c,d])');
        }
    );

    test('vmatrix is a determinant, not a matrix', () => {
        expect(tex2max.convert('\\begin{vmatrix}a&b\\\\c&d\\end{vmatrix}'))
            .toBe('determinant(matrix([a,b],[c,d]))');
    });

    test('\\det in front of any environment means the same', () => {
        expect(tex2max.convert('\\det\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}'))
            .toBe('determinant(matrix([a,b],[c,d]))');
        expect(tex2max.convert('\\det \\begin{pmatrix}a&b\\\\c&d\\end{pmatrix}'))
            .toBe('determinant(matrix([a,b],[c,d]))');
    });

    test('Vmatrix is a norm', () => {
        expect(tex2max.convert('\\begin{Vmatrix}a&b\\\\c&d\\end{Vmatrix}'))
            .toBe('norm(matrix([a,b],[c,d]))');
    });

    test('a norm of a vector keeps the inner brackets', () => {
        expect(tex2max.convert(
            '\\begin{Vmatrix}\\begin{pmatrix}x\\\\y\\end{pmatrix}\\end{Vmatrix}'
        )).toBe('norm(matrix([x],[y]))');
    });

    test('the norm function name is configurable', () => {
        expect(tex2max.convert(
            '\\begin{Vmatrix}v\\end{Vmatrix}',
            {defs: {normFunction: 'vnorm'}}
        )).toBe('vnorm(v)');
    });

    test('list mode writes vectors as lists, matrices stay matrices', () => {
        const defs = {defs: {vectorFormat: 'list'}};
        expect(tex2max.convert('\\begin{pmatrix}x&y&z\\end{pmatrix}', defs)).toBe('[x,y,z]');
        expect(tex2max.convert('\\begin{pmatrix}x\\\\y\\end{pmatrix}', defs)).toBe('[x,y]');
        expect(tex2max.convert('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}', defs))
            .toBe('matrix([a,b],[c,d])');
    });

    test('a column vector is an n x 1 matrix', () => {
        expect(tex2max.convert('\\begin{pmatrix}x\\\\y\\\\z\\end{pmatrix}'))
            .toBe('matrix([x],[y],[z])');
    });

    test('a row vector is a 1 x n matrix', () => {
        expect(tex2max.convert('\\begin{pmatrix}x&y&z\\end{pmatrix}'))
            .toBe('matrix([x,y,z])');
    });

    test('cell contents are converted like any other expression', () => {
        expect(tex2max.convert('\\begin{bmatrix}\\frac{1}{2}&\\sqrt{x}\\end{bmatrix}'))
            .toBe('matrix([(1)/(2),sqrt(x)])');
    });

    test('a matrix inside an expression keeps its surroundings', () => {
        expect(tex2max.convert('2\\begin{bmatrix}a&b\\end{bmatrix}+1'))
            .toBe('2matrix([a,b])+1');
    });

    test('a matrix inside a cell is converted too', () => {
        expect(tex2max.convert(
            '\\begin{bmatrix}a&\\begin{pmatrix}x\\\\y\\end{pmatrix}\\end{bmatrix}'
        )).toBe('matrix([a,matrix([x],[y])])');
    });

    test('no backslash survives in the CAS string (#39)', () => {
        const maxima = tex2max.convert(
            '\\begin{bmatrix}\\alpha&\\frac{1}{2}\\\\\\sqrt{x}&\\pi\\end{bmatrix}'
        );
        expect(maxima).not.toMatch(/\\/);
    });

    test('an empty cell is reported instead of silently filled', () => {
        const result = tex2max.analyse('\\begin{bmatrix}a&\\end{bmatrix}');
        expect(result.problems).toContain('matrix_cell_empty');
        expect(result.maxima).toBe('');
    });

    test('a short row is padded and reported', () => {
        const result = tex2max.analyse('\\begin{bmatrix}a&b\\\\c\\end{bmatrix}');
        expect(result.problems).toContain('matrix_cell_empty');
    });

    test('a complete matrix produces no problems', () => {
        const result = tex2max.analyse('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}');
        expect(result.problems).toEqual([]);
        expect(result.maxima).toBe('matrix([a,b],[c,d])');
    });
});

describe('max2tex: matrix()', () => {
    test('a matrix uses square brackets', () => {
        expect(max2tex.convert('matrix([a,b],[c,d])'))
            .toBe('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}');
    });

    test('vectors use round brackets', () => {
        expect(max2tex.convert('matrix([1],[2],[3])'))
            .toBe('\\begin{pmatrix}1\\\\2\\\\3\\end{pmatrix}');
        expect(max2tex.convert('matrix([1,2,3])'))
            .toBe('\\begin{pmatrix}1&2&3\\end{pmatrix}');
    });

    test('a determinant becomes vmatrix', () => {
        expect(max2tex.convert('determinant(matrix([a,b],[c,d]))'))
            .toBe('\\begin{vmatrix}a&b\\\\c&d\\end{vmatrix}');
        expect(max2tex.convert('determinant(A)'))
            .toBe('\\begin{vmatrix}A\\end{vmatrix}');
    });

    test('a norm becomes Vmatrix, with inner brackets for vectors', () => {
        expect(max2tex.convert('norm(matrix([a,b],[c,d]))'))
            .toBe('\\begin{Vmatrix}a&b\\\\c&d\\end{Vmatrix}');
        expect(max2tex.convert('norm(matrix([x],[y]))'))
            .toBe('\\begin{Vmatrix}\\begin{pmatrix}x\\\\y\\end{pmatrix}\\end{Vmatrix}');
        expect(max2tex.convert('norm(v)')).toBe('\\begin{Vmatrix}v\\end{Vmatrix}');
    });

    test('list mode draws a list as a row vector', () => {
        const defs = {defs: {vectorFormat: 'list'}};
        expect(max2tex.convert('[a,b,c]', defs))
            .toBe('\\begin{pmatrix}a&b&c\\end{pmatrix}');
        expect(max2tex.convert('[a,b,c]')).toBe('[a,b,c]');
        expect(max2tex.convert('a[1]+[x,y]', defs))
            .toBe('a[1]+\\begin{pmatrix}x&y\\end{pmatrix}');
    });

    test('each cell is converted on its own', () => {
        expect(max2tex.convert('matrix([sqrt(y),sin(x)])'))
            .toBe('\\begin{pmatrix}\\sqrt{y}&\\sin\\left(x\\right)\\end{pmatrix}');
    });

    test('surroundings are kept', () => {
        expect(max2tex.convert('2*matrix([a,b])+1'))
            .toBe('2\\cdot \\begin{pmatrix}a&b\\end{pmatrix}+1');
    });

    test('a nested matrix is converted', () => {
        expect(max2tex.convert('matrix([a,matrix([x],[y])])'))
            .toBe('\\begin{pmatrix}a&\\begin{pmatrix}x\\\\y\\end{pmatrix}\\end{pmatrix}');
    });

    test('matrix() with non-row arguments is left alone', () => {
        // matrix(a, b) is legal Maxima when a and b are list-valued variables. Rendering it as a
        // 1 x 2 matrix would claim a structure that is not there.
        expect(max2tex.convert('matrix(a,b)')).toBe('matrix(a,b)');
    });

    test('a ragged matrix is left alone', () => {
        expect(max2tex.convert('matrix([a,b],[c])')).toBe('matrix([a,b],[c])');
    });
});

describe('roundtrip', () => {
    const roundtrips = [
        'matrix([a,b],[c,d])',
        'matrix([x],[y],[z])',
        'matrix([x,y,z])',
        'matrix([a,b,c],[d,e,f])',
        'matrix([a,matrix([x],[y])])'
    ];

    test.each(roundtrips)('Maxima -> LaTeX -> Maxima is stable for %s', (maxima) => {
        const latex = max2tex.convert(maxima);
        expect(tex2max.convert(latex)).toBe(maxima);
    });

    test.each(['matrix', 'pmatrix', 'bmatrix', 'Bmatrix'])(
        'LaTeX -> Maxima -> LaTeX normalises %s to bmatrix',
        (environment) => {
            const latex = '\\begin{' + environment + '}a&b\\\\c&d\\end{' + environment + '}';
            expect(max2tex.convert(tex2max.convert(latex)))
                .toBe('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}');
        }
    );

    test('a determinant survives both directions', () => {
        const latex = '\\begin{vmatrix}a&b\\\\c&d\\end{vmatrix}';
        expect(max2tex.convert(tex2max.convert(latex))).toBe(latex);
    });

    test('a norm survives both directions', () => {
        const latex = '\\begin{Vmatrix}a&b\\\\c&d\\end{Vmatrix}';
        expect(max2tex.convert(tex2max.convert(latex))).toBe(latex);
    });

    test('a vector keeps its round brackets', () => {
        const latex = '\\begin{pmatrix}x\\\\y\\end{pmatrix}';
        expect(max2tex.convert(tex2max.convert(latex))).toBe(latex);
    });

    test('list mode is stable for row vectors', () => {
        const defs = {defs: {vectorFormat: 'list'}};
        const latex = '\\begin{pmatrix}x&y&z\\end{pmatrix}';
        expect(tex2max.convert(latex, defs)).toBe('[x,y,z]');
        expect(max2tex.convert('[x,y,z]', defs)).toBe(latex);
    });
});
