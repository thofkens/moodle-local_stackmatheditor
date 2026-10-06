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
/**
 * Notation Flemish students type: Bgsin with a capital, the textbook power sin^3(x), and a
 * fraction whose numerator nests braces more than one level deep.
 *
 * @copyright  2026 Tom Hofkens
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const tex2max = loadAmd('tex2max');

describe('Belgian notation', () => {
    test.each([
        ['Bg\\sin\\left(5t\\right)', 'bgsin(5t)'],
        ['Bg\\ \\sin\\left(5t\\right)', 'bgsin(5t)'],
        ['BG\\cos\\left(x\\right)', 'bgcos(x)'],
        ['\\cos^3\\left(3x\\right)', 'cos(3x)^(3)'],
        ['\\sin^{2}\\left(\\frac{x}{2}\\right)', 'sin((x)/(2))^(2)'],
        ['bg\\sin^2\\left(4t\\right)', 'bgsin(4t)^(2)'],
        ['\\sin^{-1}\\left(x\\right)', 'sin^(-1)(x)'],
        ['\\frac{4\\sqrt{1-5^{2x}}}{\\ln\\left(50\\right)}', '(4sqrt(1-5^(2x)))/(log(50))'],
        ['\\frac{\\frac{1}{2}}{3}', '((1)/(2))/(3)'],
    ])('%s -> %s', (latex, maxima) => {
        expect(tex2max.convert(latex, {variableMode: 'stack'})).toBe(maxima);
    });
});
