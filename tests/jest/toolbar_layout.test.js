/**
 * @jest-environment jsdom
 */
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
 * Issue #74: a group that breaks into fragments is no longer a group.
 *
 * Up to five buttons a group stays whole; beyond that the first three and the last three stay
 * together and only the middle offers break points. The arithmetic is here, the DOM that follows
 * from it is built in toolbar.js.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const Layout = loadAmd('toolbar_layout');

describe('how a group may break', () => {
    test.each([1, 2, 3, 4, 5])('a group of %i stays whole', (count) => {
        const layout = Layout.plan(count);

        expect(layout.atomic).toBe(true);
        expect(layout.start).toBe(count);
        expect(layout.middle).toBe(0);
        expect(layout.end).toBe(0);
        expect(Layout.breakPoints(count)).toEqual([]);
    });

    test('six buttons break only as 3 + 3', () => {
        expect(Layout.plan(6)).toEqual({atomic: false, start: 3, middle: 0, end: 3});
        expect(Layout.breakPoints(6)).toEqual([3]);
    });

    test('seven buttons break as 3 + 4 or 4 + 3', () => {
        expect(Layout.plan(7)).toEqual({atomic: false, start: 3, middle: 1, end: 3});
        expect(Layout.breakPoints(7)).toEqual([3, 4]);
    });

    test('eight buttons break after 3, 4 or 5', () => {
        expect(Layout.plan(8)).toEqual({atomic: false, start: 3, middle: 2, end: 3});
        expect(Layout.breakPoints(8)).toEqual([3, 4, 5]);
    });

    test.each([6, 7, 8, 12, 24])('a group of %i never breaks inside a cluster', (count) => {
        const points = Layout.breakPoints(count);

        // Nothing may break inside the first three or the last three buttons.
        points.forEach((point) => {
            expect(point).toBeGreaterThanOrEqual(Layout.CLUSTER_SIZE);
            expect(point).toBeLessThanOrEqual(count - Layout.CLUSTER_SIZE);
        });
    });

    test.each([6, 7, 8, 9, 24])('the pieces of a group of %i add up', (count) => {
        const layout = Layout.plan(count);

        expect(layout.start + layout.middle + layout.end).toBe(count);
        expect(layout.middle).toBeGreaterThanOrEqual(0);
    });

    test('the greek rows, which are the widest groups, keep their ends', () => {
        // 24 letters: the first three and the last three stay together, everything between them
        // may break.
        const layout = Layout.plan(24);

        expect(layout.start).toBe(3);
        expect(layout.end).toBe(3);
        expect(layout.middle).toBe(18);
        expect(Layout.breakPoints(24)).toHaveLength(19);
    });

    test('nonsense input does not produce a broken plan', () => {
        [0, -3, null, undefined, 'seven'].forEach((value) => {
            const layout = Layout.plan(value);
            expect(layout.atomic).toBe(true);
            expect(layout.start + layout.middle + layout.end).toBe(0);
        });
    });
});
