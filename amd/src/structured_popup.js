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
 * Toolbar popups for matrix and vector dimensions.
 *
 * The matrix popup is the grid known from office applications: moving over a cell highlights
 * the block from (1,1) to that cell, and the chosen size is also written out as text, because
 * a colour alone is not an answer for anyone using a screen reader.
 *
 * Everything is reachable from the keyboard: arrow keys move, Enter confirms, Escape closes and
 * returns the focus to the button that opened the popup.
 *
 * The popup only produces a structured model. Inserting it is the caller's job.
 *
 * @module     local_stackmatheditor/structured_popup
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_stackmatheditor/structured_input'], function(Model) {
    'use strict';

    /**
     * Currently open popup, so a second click closes the first one.
     *
     * @type {?Object}
     */
    var openPopup = null;

    /**
     * Strings the popup shows, replaced by the caller with the language pack values
     * (definitions::get_popup_strings()). Until then each holds Moodle's marker for a missing
     * string rather than English text, so a string that does not arrive is visible in every
     * language instead of silently English.
     *
     * @type {Object}
     */
    var strings = {
        matrixTitle: '[[popup_matrix_title]]',
        vectorTitle: '[[popup_vector_title]]',
        size: '[[popup_matrix_size]]',
        dimension: '[[popup_dimension]]',
        orientation: '[[popup_orientation]]',
        rowVector: '[[popup_row_vector]]',
        columnVector: '[[popup_column_vector]]',
        vectorSize: '[[popup_vector_size]]'
    };

    /**
     * Replace the {a} and {b} placeholders in a string.
     *
     * @param {string} template Template.
     * @param {string|number} a First value.
     * @param {string|number} b Second value.
     * @returns {string} Filled template.
     */
    function format(template, a, b) {
        return String(template).replace('{a}', a).replace('{b}', b);
    }

    /**
     * Close whatever popup is open.
     *
     * @param {boolean} restoreFocus Move the focus back to the opening button.
     */
    function close(restoreFocus) {
        if (!openPopup) {
            return;
        }

        var popup = openPopup;
        openPopup = null;

        if (popup.element && popup.element.parentNode) {
            popup.element.parentNode.removeChild(popup.element);
        }
        document.removeEventListener('mousedown', popup.onOutside, true);
        document.removeEventListener('keydown', popup.onEscape, true);
        if (popup.owner) {
            popup.owner.setAttribute('aria-expanded', 'false');
            if (restoreFocus && typeof popup.owner.focus === 'function') {
                popup.owner.focus();
            }
        }
    }

    /**
     * Place the popup below its button.
     *
     * @param {HTMLElement} element Popup element.
     * @param {HTMLElement} owner Button.
     */
    function position(element, owner) {
        var box = owner.getBoundingClientRect ? owner.getBoundingClientRect() : null;

        element.style.position = 'absolute';
        element.style.zIndex = '1051';
        if (box) {
            // Below the button, on the side the page reads from: its left edge in a left-to-right
            // page, its right edge in a right-to-left one - and never past the window's edge.
            var width = element.offsetWidth || 0;
            // The button itself is set left to right (its symbols are mathematics); the page
            // direction is that of the toolbar around it.
            var context = (owner.closest && owner.closest('.sme-toolbar')) || document.body;
            var rtl = window.getComputedStyle && window.getComputedStyle(context).direction === 'rtl';
            var left = rtl ? box.right - width : box.left;
            var viewport = document.documentElement.clientWidth || window.innerWidth;
            left = Math.max(0, Math.min(left, viewport - width));
            element.style.left = (left + window.pageXOffset) + 'px';
            element.style.top = (box.bottom + window.pageYOffset + 2) + 'px';
        }
    }

    /**
     * Register the shared close handlers and remember the popup.
     *
     * @param {HTMLElement} element Popup element.
     * @param {HTMLElement} owner Button.
     */
    function register(element, owner) {
        var popup = {element: element, owner: owner};

        popup.onOutside = function(e) {
            if (!element.contains(e.target) && e.target !== owner) {
                close(false);
            }
        };
        popup.onEscape = function(e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                close(true);
            }
        };

        document.addEventListener('mousedown', popup.onOutside, true);
        document.addEventListener('keydown', popup.onEscape, true);
        openPopup = popup;
        owner.setAttribute('aria-expanded', 'true');
    }

    /**
     * Open the matrix grid popup.
     *
     * @param {HTMLElement} owner Button that opens the popup.
     * @param {Function} onChoose Called with the structured model.
     * @param {?Object} current Size of the structure being changed, or null for a new one.
     * @param {number} max Largest size the site, quiz or question allows.
     * @returns {HTMLElement} The popup element.
     */
    function openMatrixGrid(owner, onChoose, current, max) {
        var size = Model.clampDimension(max);
        var element = document.createElement('div');
        var grid = document.createElement('div');
        var label = document.createElement('div');
        var cells = [];
        var selected = {rows: 1, columns: 1};
        var dragging = false;
        var rowElement;
        var cell;
        var r;
        var c;

        close(false);

        element.className = 'sme-structured-popup sme-matrix-popup';
        element.setAttribute('role', 'dialog');
        element.setAttribute('aria-label', strings.matrixTitle);

        grid.className = 'sme-matrix-grid';
        grid.setAttribute('role', 'grid');
        grid.setAttribute('aria-label', strings.matrixTitle);

        label.className = 'sme-structured-popup-label';
        label.setAttribute('aria-live', 'polite');

        /**
         * Highlight the block from (1,1) to the given cell and announce its size.
         *
         * @param {number} rows Row count.
         * @param {number} columns Column count.
         */
        function highlight(rows, columns) {
            selected = {rows: rows, columns: columns};
            cells.forEach(function(cell) {
                var inside = Number(cell.dataset.row) <= rows
                    && Number(cell.dataset.column) <= columns;
                cell.classList.toggle('sme-selected', inside);
                cell.setAttribute('aria-selected', inside ? 'true' : 'false');
                cell.tabIndex = (Number(cell.dataset.row) === rows
                    && Number(cell.dataset.column) === columns) ? 0 : -1;
            });
            label.textContent = format(strings.size, rows, columns);
        }

        /**
         * Highlight on hover, unless a drag is in charge of the selection.
         */
        function hoverHandler() {
            if (!dragging) {
                highlight(Number(this.dataset.row), Number(this.dataset.column));
            }
        }

        /**
         * Confirm the current selection.
         */
        function confirm() {
            var model = Model.createMatrix(selected.rows, selected.columns);
            close(false);
            onChoose(model);
        }

        for (r = 1; r <= size; r++) {
            rowElement = document.createElement('div');
            rowElement.className = 'sme-matrix-grid-row';
            rowElement.setAttribute('role', 'row');
            for (c = 1; c <= size; c++) {
                cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'sme-matrix-grid-cell';
                cell.setAttribute('role', 'gridcell');
                cell.dataset.row = r;
                cell.dataset.column = c;
                cell.tabIndex = -1;
                cell.setAttribute('aria-label', format(strings.size, r, c));
                cell.addEventListener('mouseenter', hoverHandler);
                cell.addEventListener('focus', function() {
                    highlight(Number(this.dataset.row), Number(this.dataset.column));
                });
                cells.push(cell);
                rowElement.appendChild(cell);
            }
            grid.appendChild(rowElement);
        }

        grid.addEventListener('keydown', function(e) {
            var rows = selected.rows;
            var columns = selected.columns;
            var handled = true;

            switch (e.key) {
                case 'ArrowRight':
                    columns = Math.min(size, columns + 1);
                    break;
                case 'ArrowLeft':
                    columns = Math.max(1, columns - 1);
                    break;
                case 'ArrowDown':
                    rows = Math.min(size, rows + 1);
                    break;
                case 'ArrowUp':
                    rows = Math.max(1, rows - 1);
                    break;
                case 'Enter':
                case ' ':
                    confirm();
                    e.preventDefault();
                    return;
                default:
                    handled = false;
            }

            if (!handled) {
                return;
            }
            e.preventDefault();
            highlight(rows, columns);
            cells.forEach(function(cell) {
                if (Number(cell.dataset.row) === rows && Number(cell.dataset.column) === columns) {
                    cell.focus();
                }
            });
        });

        /**
         * The cell under a pointer, wherever the pointer currently is.
         *
         * Drag selection cannot rely on the event target: once the pointer is captured, every
         * move is reported on the element the gesture started on.
         *
         * @param {Event} e Pointer event.
         * @returns {?HTMLElement} The cell, or null outside the grid.
         */
        function cellUnder(e) {
            var found = document.elementFromPoint
                ? document.elementFromPoint(e.clientX, e.clientY)
                : e.target;

            while (found && found !== grid) {
                if (found.classList && found.classList.contains('sme-matrix-grid-cell')) {
                    return found;
                }
                found = found.parentNode;
            }

            return null;
        }

        grid.addEventListener('pointerdown', function(e) {
            var cellat = cellUnder(e);
            if (!cellat) {
                return;
            }

            // The page must not scroll while a finger is choosing a matrix size. touch-action in
            // the stylesheet does most of it; this covers the engines that still need the event
            // cancelled as well.
            e.preventDefault();
            dragging = true;
            if (typeof grid.setPointerCapture === 'function' && e.pointerId !== undefined) {
                try {
                    grid.setPointerCapture(e.pointerId);
                } catch (ignored) {
                    grid.dataset.capture = 'unavailable';
                }
            }
            highlight(Number(cellat.dataset.row), Number(cellat.dataset.column));
        });

        grid.addEventListener('pointermove', function(e) {
            if (!dragging) {
                return;
            }
            var cellat = cellUnder(e);
            if (cellat) {
                e.preventDefault();
                highlight(Number(cellat.dataset.row), Number(cellat.dataset.column));
            }
        });

        grid.addEventListener('pointerup', function(e) {
            if (!dragging) {
                return;
            }
            dragging = false;
            var cellat = cellUnder(e);
            if (cellat) {
                highlight(Number(cellat.dataset.row), Number(cellat.dataset.column));
            }
            confirm();
        });

        grid.addEventListener('pointercancel', function() {
            // A cancelled gesture chooses nothing and leaves the popup as it was: the system took
            // the pointer away, the student did not change their mind about the size.
            dragging = false;
            highlight(selected.rows, selected.columns);
        });

        element.appendChild(grid);
        element.appendChild(label);
        document.body.appendChild(element);
        position(element, owner);
        register(element, owner);

        // An existing matrix opens on its own size, so the grid shows what is there and the
        // student changes it rather than starting over.
        var startrows = current && current.rows ? Math.min(size, current.rows) : 1;
        var startcolumns = current && current.columns ? Math.min(size, current.columns) : 1;
        highlight(startrows, startcolumns);

        cells.forEach(function(cell) {
            if (Number(cell.dataset.row) === startrows
                    && Number(cell.dataset.column) === startcolumns) {
                cell.focus();
            }
        });

        return element;
    }

    /**
     * Open the vector popup: dimension and orientation.
     *
     * @param {HTMLElement} owner Button that opens the popup.
     * @param {Function} onChoose Called with the structured model.
     * @param {?Object} current Size of the structure being changed, or null for a new one.
     * @param {number} max Largest dimension the site, quiz or question allows.
     * @returns {HTMLElement} The popup element.
     */
    function openVectorChooser(owner, onChoose, current, max) {
        var element = document.createElement('div');
        var dimensionRow = document.createElement('div');
        var orientationRow = document.createElement('div');
        var label = document.createElement('div');
        var limit = Model.clampDimension(max);
        var state = {
            dimension: Math.min(limit, (current && current.dimension) || 3),
            orientation: (current && current.orientation) || 'column'
        };
        var dimensionButtons = [];
        var orientationButtons = [];
        var dimensionButton;
        var d;

        close(false);

        element.className = 'sme-structured-popup sme-vector-popup';
        element.setAttribute('role', 'dialog');
        element.setAttribute('aria-label', strings.vectorTitle);

        dimensionRow.className = 'sme-vector-row';
        dimensionRow.setAttribute('role', 'group');
        dimensionRow.setAttribute('aria-label', strings.dimension);

        orientationRow.className = 'sme-vector-row';
        orientationRow.setAttribute('role', 'group');
        orientationRow.setAttribute('aria-label', strings.orientation);

        label.className = 'sme-structured-popup-label';
        label.setAttribute('aria-live', 'polite');

        /**
         * Reflect the current choice in the buttons and the text.
         */
        function refresh() {
            dimensionButtons.forEach(function(button) {
                var active = Number(button.dataset.dimension) === state.dimension;
                button.classList.toggle('sme-selected', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            orientationButtons.forEach(function(button) {
                var active = button.dataset.orientation === state.orientation;
                button.classList.toggle('sme-selected', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            label.textContent = format(
                strings.vectorSize,
                state.dimension,
                state.orientation === 'row' ? strings.rowVector : strings.columnVector
            );
        }

        /**
         * Confirm the current choice.
         */
        function confirm() {
            var model = Model.createVector(state.dimension, state.orientation);
            close(false);
            onChoose(model);
        }

        for (d = 2; d <= limit; d++) {
            dimensionButton = document.createElement('button');
            dimensionButton.type = 'button';
            dimensionButton.className = 'sme-vector-dimension';
            dimensionButton.dataset.dimension = d;
            dimensionButton.textContent = String(d);
            dimensionButton.addEventListener('click', function(e) {
                e.preventDefault();
                state.dimension = Number(this.dataset.dimension);
                refresh();
            });
            dimensionButtons.push(dimensionButton);
            dimensionRow.appendChild(dimensionButton);
        }

        [
            {orientation: 'column', text: strings.columnVector},
            {orientation: 'row', text: strings.rowVector}
        ].forEach(function(option) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sme-vector-orientation';
            button.dataset.orientation = option.orientation;
            button.textContent = option.text;
            button.addEventListener('click', function(e) {
                e.preventDefault();
                state.orientation = this.dataset.orientation;
                refresh();
            });
            orientationButtons.push(button);
            orientationRow.appendChild(button);
        });

        element.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                confirm();
            }
        });

        /**
         * The gesture pad.
         *
         * One drag decides both things a vector needs: the direction says whether it is a row or
         * a column, the distance says how long it is. The buttons below remain fully usable - a
         * gesture is an addition, never the only way in.
         */
        var pad = document.createElement('div');
        var padcells = [];
        var origin = null;
        var i;

        pad.className = 'sme-vector-pad';
        pad.setAttribute('aria-hidden', 'true');

        for (i = 1; i <= limit; i++) {
            var padcell = document.createElement('span');
            padcell.className = 'sme-vector-pad-cell';
            padcell.dataset.index = i;
            padcells.push(padcell);
            pad.appendChild(padcell);
        }

        /**
         * Show what the gesture would produce.
         */
        function paint() {
            pad.classList.toggle('sme-vector-pad-row', state.orientation === 'row');
            pad.classList.toggle('sme-vector-pad-column', state.orientation === 'column');
            padcells.forEach(function(cellel) {
                cellel.classList.toggle(
                    'sme-selected',
                    Number(cellel.dataset.index) <= state.dimension
                );
            });
        }

        /**
         * Read a gesture and turn it into a dimension and an orientation.
         *
         * @param {Event} e Pointer event.
         */
        function applyGesture(e) {
            var dx = e.clientX - origin.x;
            var dy = e.clientY - origin.y;
            var axis = Model.dominantAxis(dx, dy, 8);
            var step = origin.step;

            if (axis) {
                state.orientation = axis;
            }

            state.dimension = Model.dimensionFromDistance(
                state.orientation === 'row' ? dx : dy,
                step,
                limit
            );

            refresh();
            paint();
        }

        pad.addEventListener('pointerdown', function(e) {
            var box = pad.getBoundingClientRect();
            e.preventDefault();
            origin = {
                x: e.clientX,
                y: e.clientY,
                step: Math.max(1, Math.round(box.width / limit))
            };
            if (typeof pad.setPointerCapture === 'function' && e.pointerId !== undefined) {
                try {
                    pad.setPointerCapture(e.pointerId);
                } catch (ignored) {
                    // Capture is a convenience: without it the drag still works, it just stops
                    // following the pointer outside the pad.
                    pad.dataset.capture = 'unavailable';
                }
            }
        });

        pad.addEventListener('pointermove', function(e) {
            if (!origin) {
                return;
            }
            e.preventDefault();
            applyGesture(e);
        });

        pad.addEventListener('pointerup', function(e) {
            if (!origin) {
                return;
            }
            applyGesture(e);
            origin = null;
            confirm();
        });

        pad.addEventListener('pointercancel', function() {
            // Back to where the gesture started: a cancelled drag is not a choice.
            origin = null;
            refresh();
            paint();
        });

        element.appendChild(pad);
        element.appendChild(dimensionRow);
        element.appendChild(orientationRow);
        element.appendChild(label);
        document.body.appendChild(element);
        position(element, owner);
        register(element, owner);
        refresh();
        paint();
        dimensionButtons[0].focus();

        return element;
    }

    return /** @alias module:local_stackmatheditor/structured_popup */ {
        setStrings: function(values) {
            Object.keys(values || {}).forEach(function(key) {
                if (typeof values[key] === 'string' && values[key]) {
                    strings[key] = values[key];
                }
            });
        },
        openMatrixGrid: openMatrixGrid,
        openVectorChooser: openVectorChooser,
        close: close,
        isOpen: function() {
            return openPopup !== null;
        }
    };
});
