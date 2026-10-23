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
 * Absolute value straight after a logarithm: MathQuill emits \ln\left|x\right|, which must reach
 * STACK as log(abs(x)) and not as the variable log next to abs(x).
 *
 * @copyright  2026 Tom Hofkens
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const tex2max = loadAmd('tex2max');

describe('Logarithm of an absolute value', () => {
    test.each([
        ['\\ln\\left|x\\right|', 'log(abs(x))'],
        ['\\ln|x-2|', 'log(abs(x-2))'],
        ['2\\ln\\left|x-1\\right|+\\ln\\left|x-4\\right|+c', '2log(abs(x-1))+log(abs(x-4))+c'],
        ['\\ln\\left(\\left|x\\right|\\right)', 'log(abs(x))'],
    ])('%s', (latex, expected) => {
        expect(tex2max.convert(latex, {})).toBe(expected);
    });
});
