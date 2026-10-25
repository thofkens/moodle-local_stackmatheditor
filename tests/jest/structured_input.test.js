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
 * Issue #62: matrices and vectors are built from a structured model, not from a LaTeX string.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {loadAmd} = require('./amd_loader');

const Model = loadAmd('structured_input');
const Serializer = loadAmd('structured_serializer');

describe('structured model', () => {
    test('a matrix is created with empty cells', () => {
        const model = Model.createMatrix(3, 2);
        expect(model.type).toBe('matrix');
        expect(model.rows.length).toBe(3);
        expect(model.rows[0].length).toBe(2);
        expect(model.rows[2][1]).toBe('');
        expect(Model.dimensions(model)).toEqual({rows: 3, columns: 2});
    });

    test('a vector is not a matrix', () => {
        const column = Model.createVector(3, 'column');
        expect(column.type).toBe('vector');
        expect(column.orientation).toBe('column');
        expect(column.elements.length).toBe(3);
        expect(column.rows).toBeUndefined();

        const row = Model.createVector(3, 'row');
        expect(Model.dimensions(row)).toEqual({rows: 1, columns: 3});
        expect(Model.dimensions(column)).toEqual({rows: 3, columns: 1});
    });

    test('invalid dimensions are refused', () => {
        expect(() => Model.createMatrix(0, 2)).toThrow();
        expect(() => Model.createMatrix(2, 0)).toThrow();
        expect(() => Model.createMatrix(-1, 2)).toThrow();
        expect(() => Model.createMatrix(2.5, 2)).toThrow();
        expect(() => Model.createMatrix(Model.MAX_DIMENSION + 1, 2)).toThrow();
        expect(() => Model.createVector(0, 'row')).toThrow();
        expect(() => Model.createVector(3, 'diagonal')).toThrow();
    });

    test('the limit is one number for the UI and the model', () => {
        expect(Model.isValidDimension(Model.MAX_DIMENSION)).toBe(true);
        expect(Model.isValidDimension(Model.MAX_DIMENSION + 1)).toBe(false);
        expect(Model.QUICK_PICK_SIZE).toBeLessThanOrEqual(Model.MAX_DIMENSION);
    });

    test('a ragged model is invalid', () => {
        const model = Model.createMatrix(2, 2);
        model.rows[1] = ['a'];
        expect(Model.isValid(model)).toBe(false);
        expect(() => Model.toLatex(model)).toThrow();
    });

    test('LaTeX uses square brackets for matrices and round ones for vectors', () => {
        const matrix = Model.createMatrix(2, 2);
        matrix.rows = [['a', 'b'], ['c', 'd']];
        expect(Model.toLatex(matrix)).toBe('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}');

        const column = Model.createVector(2, 'column');
        column.elements = ['x', 'y'];
        expect(Model.toLatex(column)).toBe('\\begin{pmatrix}x\\\\y\\end{pmatrix}');

        const row = Model.createVector(3, 'row');
        row.elements = ['x', 'y', 'z'];
        expect(Model.toLatex(row)).toBe('\\begin{pmatrix}x&y&z\\end{pmatrix}');
    });
});

describe('serializer: model to Maxima', () => {
    function matrix(rows) {
        const model = Model.createMatrix(rows.length, rows[0].length);
        model.rows = rows;
        return model;
    }

    test.each([
        [[['1', '2'], ['3', '4']], 'matrix([1,2],[3,4])'],
        [[['x', 'sqrt(y)'], ['sin(t)', '1/2']], 'matrix([x,sqrt(y)],[sin(t),1/2])'],
        [[['a', 'b', 'c']], 'matrix([a,b,c])']
    ])('%j becomes %s', (rows, expected) => {
        expect(Serializer.toMaxima(matrix(rows))).toBe(expected);
    });

    test('a column vector keeps one entry per row', () => {
        const model = Model.createVector(3, 'column');
        model.elements = ['a', 'b', 'c'];
        expect(Serializer.toMaxima(model)).toBe('matrix([a],[b],[c])');
    });

    test('a row vector is one row', () => {
        const model = Model.createVector(3, 'row');
        model.elements = ['a', 'b', 'c'];
        expect(Serializer.toMaxima(model)).toBe('matrix([a,b,c])');
    });

    test('the list format is used when the setting asks for it', () => {
        const model = Model.createVector(3, 'column');
        model.elements = ['a', 'b', 'c'];
        expect(Serializer.toMaxima(model, {defs: {vectorFormat: 'list'}})).toBe('[a,b,c]');
    });

    test('an invalid model is not serialized', () => {
        expect(() => Serializer.toMaxima({type: 'matrix', rows: []})).toThrow();
    });
});

describe('serializer: Maxima to model (reverse loading)', () => {
    test('a matrix keeps its dimensions and cell order', () => {
        const model = Serializer.fromMaxima('matrix([a,b],[c,d])');
        expect(model.type).toBe('matrix');
        expect(model.rows).toEqual([['a', 'b'], ['c', 'd']]);
    });

    test('nested expressions inside cells survive', () => {
        const model = Serializer.fromMaxima('matrix([x,sqrt(y)],[sin(t),1/2])');
        expect(model.rows[0][1]).toBe('sqrt(y)');
        expect(model.rows[1][0]).toBe('sin(t)');
    });

    test('a nested matrix stays in its cell', () => {
        const model = Serializer.fromMaxima('matrix([a,matrix([x],[y])])');
        expect(model.type).toBe('vector');
        expect(model.elements[1]).toBe('matrix([x],[y])');
    });

    test('single rows and single columns come back as vectors', () => {
        const row = Serializer.fromMaxima('matrix([a,b,c])');
        expect(row.type).toBe('vector');
        expect(row.orientation).toBe('row');

        const column = Serializer.fromMaxima('matrix([a],[b],[c])');
        expect(column.type).toBe('vector');
        expect(column.orientation).toBe('column');
        expect(column.elements).toEqual(['a', 'b', 'c']);
    });

    test('a list is read as a row vector', () => {
        const model = Serializer.fromMaxima('[a,b,c]');
        expect(model.type).toBe('vector');
        expect(model.elements).toEqual(['a', 'b', 'c']);
    });

    test('anything else is left to the generic editor', () => {
        expect(Serializer.fromMaxima('matrix(a,b)')).toBeNull();
        expect(Serializer.fromMaxima('matrix([a,b],[c])')).toBeNull();
        expect(Serializer.fromMaxima('x+1')).toBeNull();
        expect(Serializer.fromMaxima('')).toBeNull();
        expect(Serializer.fromMaxima('matrix([a,b]')).toBeNull();
    });
});

describe('roundtrip', () => {
    test.each([
        'matrix([1,2],[3,4])',
        'matrix([x,sqrt(y)],[sin(t),1/2])',
        'matrix([a,b,c])',
        'matrix([a],[b],[c])'
    ])('%s survives model -> Maxima', (maxima) => {
        const model = Serializer.fromMaxima(maxima);
        expect(model).not.toBeNull();
        expect(Serializer.toMaxima(model)).toBe(maxima);
    });

    test('a 1x1 matrix keeps its type', () => {
        const model = Serializer.fromMaxima('matrix([a])');
        expect(model.type).toBe('matrix');
        expect(Serializer.toMaxima(model)).toBe('matrix([a])');
    });
});

// What definitions::get_popup_strings() delivers in English.
const POPUP_STRINGS = {
    matrixTitle: 'Matrix',
    vectorTitle: 'Vector',
    size: '{a} × {b} matrix',
    dimension: 'Dimension',
    orientation: 'Orientation',
    rowVector: 'Row vector',
    columnVector: 'Column vector',
    vectorSize: '{a}-dimensional {b}',
};

describe('popup', () => {
    let Popup;
    let owner;

    function key(element, name) {
        const event = new window.KeyboardEvent('keydown', {key: name, bubbles: true});
        element.dispatchEvent(event);
    }

    beforeEach(() => {
        jest.resetModules();
        Popup = loadAmd('structured_popup');
        // The strings the toolbar passes in from definitions::get_popup_strings(); the popup
        // itself has no English defaults (#92).
        Popup.setStrings(POPUP_STRINGS);
        document.body.innerHTML = '';
        owner = document.createElement('button');
        document.body.appendChild(owner);
    });

    test('the grid offers the quick-pick size and announces the selection', () => {
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model));

        const cells = document.querySelectorAll('.sme-matrix-grid-cell');
        expect(cells.length).toBe(Model.QUICK_PICK_SIZE * Model.QUICK_PICK_SIZE);
        expect(owner.getAttribute('aria-expanded')).toBe('true');
        expect(document.querySelector('.sme-structured-popup-label').textContent)
            .toContain('1');
        expect(chosen.length).toBe(0);
    });

    test('arrow keys select a block and Enter confirms it', () => {
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model));
        const grid = document.querySelector('.sme-matrix-grid');

        key(grid, 'ArrowDown');
        key(grid, 'ArrowDown');
        key(grid, 'ArrowRight');

        const selected = document.querySelectorAll('.sme-matrix-grid-cell.sme-selected');
        expect(selected.length).toBe(6);
        expect(document.querySelector('.sme-structured-popup-label').textContent)
            .toBe('3 × 2 matrix');

        key(grid, 'Enter');
        expect(chosen.length).toBe(1);
        expect(Model.dimensions(chosen[0])).toEqual({rows: 3, columns: 2});
        expect(Popup.isOpen()).toBe(false);
    });

    test('the selection never leaves the grid', () => {
        Popup.openMatrixGrid(owner, () => {});
        const grid = document.querySelector('.sme-matrix-grid');

        for (let i = 0; i < Model.QUICK_PICK_SIZE + 3; i += 1) {
            key(grid, 'ArrowUp');
            key(grid, 'ArrowLeft');
        }
        expect(document.querySelectorAll('.sme-matrix-grid-cell.sme-selected').length).toBe(1);

        for (let i = 0; i < Model.QUICK_PICK_SIZE + 3; i += 1) {
            key(grid, 'ArrowDown');
            key(grid, 'ArrowRight');
        }
        expect(document.querySelectorAll('.sme-matrix-grid-cell.sme-selected').length)
            .toBe(Model.QUICK_PICK_SIZE * Model.QUICK_PICK_SIZE);
    });

    test('a click picks the cell it is on', () => {
        // Since #75 the grid listens to pointer events, which is what a mouse click is made of:
        // pointerdown and pointerup on the same cell. One code path for mouse, touch and pen.
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model));
        const cells = document.querySelectorAll('.sme-matrix-grid-cell');

        // Row 2, column 4 in a 5x5 grid.
        const press = (type) => {
            const event = new window.MouseEvent(type, {bubbles: true, clientX: 5, clientY: 5});
            Object.defineProperty(event, 'pointerId', {value: 1});
            cells[8].dispatchEvent(event);
        };

        press('pointerdown');
        press('pointerup');

        expect(Model.dimensions(chosen[0])).toEqual({rows: 2, columns: 4});
    });

    test('Escape closes without a selection and gives the focus back', () => {
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model));

        document.dispatchEvent(new window.KeyboardEvent('keydown', {key: 'Escape'}));

        expect(chosen.length).toBe(0);
        expect(Popup.isOpen()).toBe(false);
        expect(document.querySelector('.sme-structured-popup')).toBeNull();
        expect(document.activeElement).toBe(owner);
        expect(owner.getAttribute('aria-expanded')).toBe('false');
    });

    test('the grid carries ARIA roles', () => {
        Popup.openMatrixGrid(owner, () => {});
        expect(document.querySelector('.sme-matrix-grid').getAttribute('role')).toBe('grid');
        expect(document.querySelector('.sme-matrix-grid-row').getAttribute('role')).toBe('row');
        expect(document.querySelector('.sme-matrix-grid-cell').getAttribute('role'))
            .toBe('gridcell');
        expect(document.querySelector('.sme-matrix-grid-cell').getAttribute('aria-selected'))
            .toBe('true');
    });

    test('the vector chooser offers dimension and orientation', () => {
        const chosen = [];
        Popup.openVectorChooser(owner, (model) => chosen.push(model));

        const dimensions = document.querySelectorAll('.sme-vector-dimension');
        const orientations = document.querySelectorAll('.sme-vector-orientation');
        expect(dimensions.length).toBeGreaterThan(1);
        expect(orientations.length).toBe(2);

        dimensions[1].dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        orientations[1].dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        key(document.querySelector('.sme-vector-popup'), 'Enter');

        expect(chosen.length).toBe(1);
        expect(chosen[0].type).toBe('vector');
        expect(chosen[0].orientation).toBe('row');
        expect(chosen[0].elements.length).toBe(3);
    });

    test('opening a second popup closes the first', () => {
        Popup.openMatrixGrid(owner, () => {});
        Popup.openVectorChooser(owner, () => {});
        expect(document.querySelectorAll('.sme-structured-popup').length).toBe(1);
        expect(document.querySelector('.sme-vector-popup')).not.toBeNull();
    });

    test('labels come from the language pack', () => {
        Popup.setStrings({size: '{a} Zeilen, {b} Spalten'});
        Popup.openMatrixGrid(owner, () => {});
        expect(document.querySelector('.sme-structured-popup-label').textContent)
            .toBe('1 Zeilen, 1 Spalten');
    });
});

describe('changing the size of an existing structure (#62)', () => {
    // The toolbar asks MathQuill what is under the cursor and decides from that; these cases
    // mirror structureAtCursor() and the chooser's starting size in toolbar.js.
    function describe_(matrix) {
        if (!matrix) {
            return null;
        }
        const isvector = matrix.rows === 1 || matrix.columns === 1;
        return {
            rows: matrix.rows,
            columns: matrix.columns,
            isVector: isvector,
            orientation: matrix.rows === 1 ? 'row' : 'column',
            dimension: matrix.rows === 1 ? matrix.columns : matrix.rows
        };
    }

    test('a 2x3 matrix is a matrix', () => {
        const current = describe_({rows: 2, columns: 3});
        expect(current.isVector).toBe(false);
        expect(current.rows).toBe(2);
        expect(current.columns).toBe(3);
    });

    test('a single column is a vector, and knows its orientation', () => {
        const column = describe_({rows: 3, columns: 1});
        expect(column.isVector).toBe(true);
        expect(column.orientation).toBe('column');
        expect(column.dimension).toBe(3);

        const row = describe_({rows: 1, columns: 4});
        expect(row.isVector).toBe(true);
        expect(row.orientation).toBe('row');
        expect(row.dimension).toBe(4);
    });

    test('outside a structure there is nothing to change', () => {
        expect(describe_(null)).toBeNull();
    });

    test('the chooser only offers to change what it could have made', () => {
        // The matrix chooser leaves a vector alone and inserts a new matrix instead, and the
        // vector chooser does the same the other way round.
        const vector = describe_({rows: 3, columns: 1});
        const matrix = describe_({rows: 2, columns: 2});

        expect(vector.isVector === ('vector' === 'vector')).toBe(true);
        expect(matrix.isVector === ('vector' === 'vector')).toBe(false);
    });

    test('a resize keeps the model valid', () => {
        // What the chooser hands back is a model like any other.
        const model = Model.createMatrix(3, 3);
        expect(Model.isValid(model)).toBe(true);
        expect(Model.dimensions(model)).toEqual({rows: 3, columns: 3});
    });
});

describe('the configured maximum (#76)', () => {
    let Popup;
    let owner;

    beforeEach(() => {
        jest.resetModules();
        Popup = loadAmd('structured_popup');
        // The strings the toolbar passes in from definitions::get_popup_strings(); the popup
        // itself has no English defaults (#92).
        Popup.setStrings(POPUP_STRINGS);
        document.body.innerHTML = '';
        owner = document.createElement('button');
        document.body.appendChild(owner);
    });

    test('a value out of range never produces an unusable chooser', () => {
        expect(Model.clampDimension(7)).toBe(7);
        expect(Model.clampDimension('7')).toBe(7);
        expect(Model.clampDimension(1)).toBe(Model.QUICK_PICK_SIZE);
        expect(Model.clampDimension(0)).toBe(Model.QUICK_PICK_SIZE);
        expect(Model.clampDimension(null)).toBe(Model.QUICK_PICK_SIZE);
        expect(Model.clampDimension('nonsense')).toBe(Model.QUICK_PICK_SIZE);
        expect(Model.clampDimension(999)).toBe(Model.MAX_DIMENSION);
    });

    test('the grid offers exactly the configured number of rows and columns', () => {
        Popup.openMatrixGrid(owner, () => {}, null, 3);

        expect(document.querySelectorAll('.sme-matrix-grid-cell').length).toBe(9);
        expect(document.querySelectorAll('.sme-matrix-grid-row').length).toBe(3);
    });

    test('the keyboard cannot select beyond the maximum', () => {
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model), null, 3);
        const grid = document.querySelector('.sme-matrix-grid');

        for (let i = 0; i < 6; i += 1) {
            grid.dispatchEvent(new window.KeyboardEvent('keydown', {key: 'ArrowDown', bubbles: true}));
            grid.dispatchEvent(new window.KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));
        }
        grid.dispatchEvent(new window.KeyboardEvent('keydown', {key: 'Enter', bubbles: true}));

        expect(Model.dimensions(chosen[0])).toEqual({rows: 3, columns: 3});
    });

    test('the vector chooser stops at the maximum too', () => {
        Popup.openVectorChooser(owner, () => {}, null, 4);

        const dimensions = Array.from(document.querySelectorAll('.sme-vector-dimension'))
            .map((button) => Number(button.dataset.dimension));

        expect(dimensions).toEqual([2, 3, 4]);
    });

    test('an existing structure larger than the maximum is not enlarged further', () => {
        // The limit was lowered after the matrix was made; the chooser opens at the limit.
        Popup.openMatrixGrid(owner, () => {}, {rows: 8, columns: 8}, 4);

        const selected = document.querySelectorAll('.sme-matrix-grid-cell.sme-selected');
        expect(selected.length).toBe(16);
    });

    test('without a value the chooser keeps its default size', () => {
        Popup.openMatrixGrid(owner, () => {}, null, undefined);

        expect(document.querySelectorAll('.sme-matrix-grid-cell').length)
            .toBe(Model.QUICK_PICK_SIZE * Model.QUICK_PICK_SIZE);
    });
});

describe('pointer selection (#75)', () => {
    let Popup;
    let owner;

    function pointer(target, type, x, y) {
        const event = new window.MouseEvent(type, {bubbles: true, clientX: x, clientY: y});
        Object.defineProperty(event, 'pointerId', {value: 1});
        target.dispatchEvent(event);
        return event;
    }

    beforeEach(() => {
        jest.resetModules();
        Popup = loadAmd('structured_popup');
        // The strings the toolbar passes in from definitions::get_popup_strings(); the popup
        // itself has no English defaults (#92).
        Popup.setStrings(POPUP_STRINGS);
        document.body.innerHTML = '';
        owner = document.createElement('button');
        document.body.appendChild(owner);
    });

    test('the gesture maths: the longer axis decides', () => {
        expect(Model.dominantAxis(40, 6)).toBe('row');
        expect(Model.dominantAxis(6, 40)).toBe('column');
        expect(Model.dominantAxis(-40, 6)).toBe('row');
        expect(Model.dominantAxis(3, 3)).toBeNull();
        // A diagonal drag is not more one than the other; the row wins by convention.
        expect(Model.dominantAxis(30, 30)).toBe('row');
    });

    test('the gesture maths: distance becomes a dimension, within the limit', () => {
        expect(Model.dimensionFromDistance(0, 20, 5)).toBe(1);
        expect(Model.dimensionFromDistance(25, 20, 5)).toBe(2);
        expect(Model.dimensionFromDistance(-45, 20, 5)).toBe(3);
        expect(Model.dimensionFromDistance(9999, 20, 5)).toBe(5);
        expect(Model.dimensionFromDistance(9999, 20, 8)).toBe(8);
    });

    test('a drag across the grid selects and confirms on release', () => {
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model), null, 5);
        const grid = document.querySelector('.sme-matrix-grid');
        const cells = document.querySelectorAll('.sme-matrix-grid-cell');

        // jsdom has no layout, so elementFromPoint returns nothing: the event target decides,
        // which is what a real browser does for the first cell of a drag.
        pointer(cells[0], 'pointerdown', 10, 10);
        pointer(cells[7], 'pointermove', 60, 40);
        pointer(cells[7], 'pointerup', 60, 40);

        expect(chosen).toHaveLength(1);
        expect(Model.dimensions(chosen[0])).toEqual({rows: 2, columns: 3});
    });

    test('a cancelled gesture chooses nothing and leaves the popup usable', () => {
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model), null, 5);
        const grid = document.querySelector('.sme-matrix-grid');
        const cells = document.querySelectorAll('.sme-matrix-grid-cell');

        pointer(cells[0], 'pointerdown', 10, 10);
        pointer(cells[12], 'pointermove', 80, 80);
        grid.dispatchEvent(new window.Event('pointercancel', {bubbles: true}));

        expect(chosen).toEqual([]);
        expect(Popup.isOpen()).toBe(true);
        expect(document.querySelectorAll('.sme-matrix-grid-cell.sme-selected').length)
            .toBeGreaterThan(0);
    });

    test('the keyboard still works after a cancelled gesture', () => {
        const chosen = [];
        Popup.openMatrixGrid(owner, (model) => chosen.push(model), null, 5);
        const grid = document.querySelector('.sme-matrix-grid');
        const cells = document.querySelectorAll('.sme-matrix-grid-cell');

        pointer(cells[0], 'pointerdown', 10, 10);
        grid.dispatchEvent(new window.Event('pointercancel', {bubbles: true}));

        grid.dispatchEvent(new window.KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));
        grid.dispatchEvent(new window.KeyboardEvent('keydown', {key: 'Enter', bubbles: true}));

        expect(chosen).toHaveLength(1);
        expect(Model.dimensions(chosen[0]).columns).toBe(2);
    });

    test('the vector pad reads a horizontal drag as a row vector', () => {
        const chosen = [];
        Popup.openVectorChooser(owner, (model) => chosen.push(model), null, 6);
        const pad = document.querySelector('.sme-vector-pad');
        Object.defineProperty(pad, 'getBoundingClientRect', {
            value: () => ({width: 120, height: 20, left: 0, top: 0, right: 120, bottom: 20})
        });

        pointer(pad, 'pointerdown', 0, 0);
        pointer(pad, 'pointermove', 62, 4);
        pointer(pad, 'pointerup', 62, 4);

        expect(chosen).toHaveLength(1);
        expect(chosen[0].orientation).toBe('row');
        expect(chosen[0].elements).toHaveLength(4);
    });

    test('a vertical drag is a column vector', () => {
        const chosen = [];
        Popup.openVectorChooser(owner, (model) => chosen.push(model), null, 6);
        const pad = document.querySelector('.sme-vector-pad');
        Object.defineProperty(pad, 'getBoundingClientRect', {
            value: () => ({width: 120, height: 20, left: 0, top: 0, right: 120, bottom: 20})
        });

        pointer(pad, 'pointerdown', 0, 0);
        pointer(pad, 'pointermove', 5, 42);
        pointer(pad, 'pointerup', 5, 42);

        expect(chosen[0].orientation).toBe('column');
        expect(chosen[0].elements).toHaveLength(3);
    });

    test('the pad never offers more than the configured maximum', () => {
        Popup.openVectorChooser(owner, () => {}, null, 3);

        expect(document.querySelectorAll('.sme-vector-pad-cell')).toHaveLength(3);
    });

    test('the buttons keep working next to the gesture', () => {
        const chosen = [];
        Popup.openVectorChooser(owner, (model) => chosen.push(model), null, 5);

        const dimensions = document.querySelectorAll('.sme-vector-dimension');
        dimensions[2].dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        document.querySelector('.sme-vector-popup').dispatchEvent(
            new window.KeyboardEvent('keydown', {key: 'Enter', bubbles: true})
        );

        expect(chosen[0].elements).toHaveLength(4);
    });
});
