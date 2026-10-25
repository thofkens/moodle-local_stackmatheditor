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
 * Structured input model for matrices and vectors.
 *
 * The editor does not build complex structures by writing a LaTeX string into MathQuill. It
 * builds a model first, validates it, and only then renders it. The model is also what the
 * serializer reads, so display and CAS output can never drift apart.
 *
 * A vector is not a 1 x n matrix. It carries its own type and an orientation, because the same
 * picture can mean different things: a column of three numbers is a vector to the student and
 * either a vector or a 3 x 1 matrix to the CAS. Keeping the distinction in the model lets the
 * serializer decide once, in one place.
 *
 * @module     local_stackmatheditor/structured_input
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Largest structure the editor creates, for the UI and the model alike.
     *
     * @type {number}
     */
    var MAX_DIMENSION = 10;

    /**
     * Size of the quick-pick grid in the toolbar popup.
     *
     * @type {number}
     */
    var QUICK_PICK_SIZE = 5;

    /**
     * LaTeX environments used for the two structures.
     *
     * @type {Object}
     */
    var ENVIRONMENTS = {
        matrix: 'bmatrix',
        vector: 'pmatrix'
    };

    /**
     * Check a dimension against the shared limit.
     *
     * @param {*} value Candidate.
     * @returns {boolean} True when it is an integer within the limit.
     */
    function isValidDimension(value) {
        return typeof value === 'number'
            && isFinite(value)
            && Math.floor(value) === value
            && value >= 1
            && value <= MAX_DIMENSION;
    }

    /**
     * Clamp a configured maximum into what the editor can actually offer.
     *
     * The server resolves the hierarchy and sends a number; this is the last guard, so that a
     * value that somehow arrives broken cannot produce a chooser with no cells or with hundreds.
     *
     * @param {*} value Configured maximum.
     * @returns {number} A usable grid size.
     */
    function clampDimension(value) {
        var number = Math.floor(Number(value));

        if (!isFinite(number) || number < 2) {
            return QUICK_PICK_SIZE;
        }

        return Math.min(MAX_DIMENSION, number);
    }

    /**
     * Which way a pointer gesture is going.
     *
     * A vector is a row or a column, and the drag decides which: the axis the pointer travelled
     * furthest along wins. A gesture that has barely moved has no direction yet, and saying so is
     * better than guessing - the caller keeps the orientation it already had.
     *
     * @param {number} dx Horizontal distance in pixels.
     * @param {number} dy Vertical distance in pixels.
     * @param {number} threshold Pixels below which nothing counts as movement.
     * @returns {?string} 'row', 'column', or null while the gesture is still undecided.
     */
    function dominantAxis(dx, dy, threshold) {
        var limit = typeof threshold === 'number' ? threshold : 8;
        var horizontal = Math.abs(dx);
        var vertical = Math.abs(dy);

        if (horizontal < limit && vertical < limit) {
            return null;
        }

        // A tie goes to the row: a horizontal drag is the more common gesture, and an exact tie
        // means the pointer moved diagonally, where neither answer is more right than the other.
        return vertical > horizontal ? 'column' : 'row';
    }

    /**
     * How many cells a gesture of this length covers.
     *
     * @param {number} distance Distance in pixels along the dominant axis.
     * @param {number} step Size of one cell in pixels, including its gap.
     * @param {number} max Largest dimension allowed here.
     * @returns {number} Dimension between 1 and max.
     */
    function dimensionFromDistance(distance, step, max) {
        var cell = step > 0 ? step : 1;
        var limit = clampDimension(max);
        var count = Math.floor(Math.abs(distance) / cell) + 1;

        return Math.max(1, Math.min(limit, count));
    }

    /**
     * Build a matrix model of the given size, with empty cells.
     *
     * @param {number} rows Number of rows.
     * @param {number} columns Number of columns.
     * @returns {Object} Matrix model.
     * @throws {Error} On an invalid dimension.
     */
    function createMatrix(rows, columns) {
        var model = {type: 'matrix', rows: []};
        var r;
        var c;
        var row;

        if (!isValidDimension(rows) || !isValidDimension(columns)) {
            throw new Error(
                'local_stackmatheditor: matrix dimensions must be integers between 1 and '
                + MAX_DIMENSION + ', got ' + rows + 'x' + columns
            );
        }

        for (r = 0; r < rows; r++) {
            row = [];
            for (c = 0; c < columns; c++) {
                row.push('');
            }
            model.rows.push(row);
        }

        return model;
    }

    /**
     * Build a vector model.
     *
     * @param {number} dimension Number of components.
     * @param {string} orientation Either "column" or "row".
     * @returns {Object} Vector model.
     * @throws {Error} On an invalid dimension or orientation.
     */
    function createVector(dimension, orientation) {
        var elements = [];
        var i;

        if (!isValidDimension(dimension)) {
            throw new Error(
                'local_stackmatheditor: vector dimension must be an integer between 1 and '
                + MAX_DIMENSION + ', got ' + dimension
            );
        }
        if (orientation !== 'column' && orientation !== 'row') {
            throw new Error(
                'local_stackmatheditor: vector orientation must be "column" or "row", got '
                + orientation
            );
        }

        for (i = 0; i < dimension; i++) {
            elements.push('');
        }

        return {type: 'vector', orientation: orientation, elements: elements};
    }

    /**
     * Report the size of a model as rows and columns, without turning a vector into a matrix.
     *
     * @param {Object} model Structured model.
     * @returns {Object} Object with rows and columns.
     */
    function dimensions(model) {
        if (!model || typeof model !== 'object') {
            throw new Error('local_stackmatheditor: not a structured model');
        }

        if (model.type === 'matrix') {
            return {rows: model.rows.length, columns: model.rows[0].length};
        }

        if (model.type === 'vector') {
            if (model.orientation === 'row') {
                return {rows: 1, columns: model.elements.length};
            }
            return {rows: model.elements.length, columns: 1};
        }

        throw new Error('local_stackmatheditor: unknown model type: ' + model.type);
    }

    /**
     * Validate a model: rectangular, within the limit, of a known type.
     *
     * @param {Object} model Structured model.
     * @returns {boolean} True when the model is well formed.
     */
    function isValid(model) {
        var size;
        var i;

        if (!model || typeof model !== 'object') {
            return false;
        }

        if (model.type === 'matrix') {
            if (!Array.isArray(model.rows) || !model.rows.length) {
                return false;
            }
            if (!Array.isArray(model.rows[0]) || !model.rows[0].length) {
                return false;
            }
            for (i = 0; i < model.rows.length; i++) {
                if (!Array.isArray(model.rows[i])
                    || model.rows[i].length !== model.rows[0].length) {
                    return false;
                }
            }
            size = dimensions(model);
            return isValidDimension(size.rows) && isValidDimension(size.columns);
        }

        if (model.type === 'vector') {
            if (!Array.isArray(model.elements) || !model.elements.length) {
                return false;
            }
            if (model.orientation !== 'column' && model.orientation !== 'row') {
                return false;
            }
            return isValidDimension(model.elements.length);
        }

        return false;
    }

    /**
     * Render a model as a LaTeX environment.
     *
     * Only used where MathQuill has to be given LaTeX, for example when restoring a stored
     * answer. Insertion of an empty structure goes through MathQuill's own API instead.
     *
     * @param {Object} model Structured model.
     * @returns {string} LaTeX.
     */
    function toLatex(model) {
        var environment;
        var rows;

        if (!isValid(model)) {
            throw new Error('local_stackmatheditor: cannot render an invalid model');
        }

        if (model.type === 'matrix') {
            environment = ENVIRONMENTS.matrix;
            rows = model.rows.map(function(row) {
                return row.join('&');
            });
        } else {
            environment = ENVIRONMENTS.vector;
            rows = model.orientation === 'row'
                ? [model.elements.join('&')]
                : model.elements.slice();
        }

        return '\\begin{' + environment + '}'
            + rows.join('\\\\')
            + '\\end{' + environment + '}';
    }


    /**
     * Coordinate separators a point may be written with.
     *
     * Display only: the separator changes what a student sees and types, never the semantics.
     *
     * @type {Array}
     */
    var COORDINATE_SEPARATORS = ['|', ';', ','];

    /**
     * Build a point model.
     *
     * A point is a list of coordinates and, in school notation, usually a name. The name is
     * label only: STACK receives the coordinates.
     *
     * @param {Array} coordinates Coordinate expressions.
     * @param {string} name Optional point name.
     * @returns {Object} Point model.
     */
    function createPoint(coordinates, name) {
        if (!Array.isArray(coordinates) || !coordinates.length
            || coordinates.length > MAX_DIMENSION) {
            throw new Error(
                'local_stackmatheditor: a point needs between 1 and ' + MAX_DIMENSION
                + ' coordinates'
            );
        }

        return {
            type: 'point',
            name: typeof name === 'string' ? name : '',
            coordinates: coordinates.slice()
        };
    }

    /**
     * Build a distance model: the distance between two points.
     *
     * @param {string} left First point.
     * @param {string} right Second point.
     * @returns {Object} Distance model.
     */
    function createDistance(left, right) {
        if (!left || !right) {
            throw new Error('local_stackmatheditor: a distance needs two points');
        }

        return {type: 'distance', left: left, right: right};
    }

    /**
     * Build an angle model. The vertex is the middle point, as in the school notation ABC.
     *
     * @param {string} first First leg.
     * @param {string} vertex Vertex.
     * @param {string} second Second leg.
     * @returns {Object} Angle model.
     */
    function createAngle(first, vertex, second) {
        if (!first || !vertex || !second) {
            throw new Error('local_stackmatheditor: an angle needs three points');
        }

        return {type: 'angle', first: first, vertex: vertex, second: second};
    }

    /**
     * Render a point in school notation, with the configured separator.
     *
     * @param {Object} model Point model.
     * @param {string} separator One of COORDINATE_SEPARATORS.
     * @returns {string} LaTeX.
     */
    function pointToLatex(model, separator) {
        var glue = COORDINATE_SEPARATORS.indexOf(separator) === -1 ? '|' : separator;

        return (model.name || '') + '\\left(' + model.coordinates.join(glue) + '\\right)';
    }

    return /** @alias module:local_stackmatheditor/structured_input */ {
        MAX_DIMENSION: MAX_DIMENSION,
        QUICK_PICK_SIZE: QUICK_PICK_SIZE,
        ENVIRONMENTS: ENVIRONMENTS,
        isValidDimension: isValidDimension,
        clampDimension: clampDimension,
        dominantAxis: dominantAxis,
        dimensionFromDistance: dimensionFromDistance,
        createMatrix: createMatrix,
        createVector: createVector,
        dimensions: dimensions,
        isValid: isValid,
        toLatex: toLatex,
        COORDINATE_SEPARATORS: COORDINATE_SEPARATORS,
        createPoint: createPoint,
        createDistance: createDistance,
        createAngle: createAngle,
        pointToLatex: pointToLatex
    };
});
