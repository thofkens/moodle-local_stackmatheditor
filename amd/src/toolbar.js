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
 * Shared toolbar builder for STACK MathEditor.
 *
 * @module     local_stackmatheditor/toolbar
 * @package
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'jquery',
    'local_stackmatheditor/structured_input',
    'local_stackmatheditor/structured_popup',
    'local_stackmatheditor/toolbar_layout'
], function($, Model, Popup, Layout) {
    'use strict';

    /**
     * Write a developer-level debug message to the browser console.
     *
     * Only active when Moodle developer debug mode is enabled
     * (M.cfg.developerdebug is truthy). Silent on production sites.
     *
     * @param {string} msg Message to log.
     */
    function dbg(msg) {
        if (window.M && window.M.cfg && window.M.cfg.developerdebug) {
            window.console.log('[SME-tb] ' + msg);
        }
    }


    /**
     * Resolve the target MQ field.
     *
     * @param {Object|Function} target MQ field or getter.
     * @returns {Object|null} MathQuill field.
     */
    function resolve(target) {
        if (typeof target === 'function') {
            return target();
        }
        return target || null;
    }

    /**
     * Extract button elements from a group definition.
     * Handles:
     * - {label, elements: [...]}  (definitions.php)
     * - {label, elements: {0:..}} (PHP object)
     * - [...]                     (direct array)
     *
     * @param {*} group Group definition.
     * @returns {Array} Array of button element objects.
     */
    function extractElements(group) {
        if (!group) {
            return [];
        }
        if (group.elements) {
            var els = group.elements;
            if (Array.isArray(els)) {
                return els;
            }
            if (typeof els === 'object') {
                return Object.values(els);
            }
        }
        if (Array.isArray(group)) {
            return group;
        }
        if (typeof group === 'object'
            && !group.label
            && !group.default_enabled) {
            return Object.values(group);
        }
        return [];
    }

    /**
     * Determine the MathQuill action and command
     * from an element definition. Supports multiple
     * property name conventions:
     *
     * - {cmd: "\\sqrt"}           → cmd("\\sqrt")
     * - {write: "\\frac{}{}")}    → write("\\frac{}{}")
     * - {latex: "\\pi"}           → cmd("\\pi")
     * - {keystroke: "Backspace"}  → keystroke(...)
     * - {matrix: {rows, columns}} → insertMatrix(...)
     * - {action: "write", cmd: …} → explicit action
     *
     * @param {Object} el Element definition.
     * @returns {Object} {action, command} or null.
     */
    function resolveCommand(el) {
        // "popup" property → the toolbar opens a chooser. The structure itself is built
        // from the structured model, never from a LaTeX string.
        if (el.popup) {
            return {action: 'popup', command: el.popup};
        }

        // "matrix" property → insertMatrix action. A matrix is a structure with its own API in
        // MathQuill, not a LaTeX string that could be written into the field.
        if (el.matrix) {
            return {action: 'matrix', command: el.matrix};
        }

        // Explicit action property.
        if (el.action && el.cmd) {
            return {action: el.action, command: el.cmd};
        }

        // "write" property → write action.
        if (el.write) {
            return {action: 'write', command: el.write};
        }

        // "cmd" property → cmd action.
        if (el.cmd) {
            return {action: 'cmd', command: el.cmd};
        }

        // "latex" property → cmd action.
        if (el.latex) {
            return {action: 'cmd', command: el.latex};
        }

        // "keystroke" property → keystroke action.
        if (el.keystroke) {
            return {
                action: 'keystroke',
                command: el.keystroke
            };
        }

        // "command" property (alternative name).
        if (el.command) {
            return {action: 'cmd', command: el.command};
        }

        return null;
    }

    /**
     * Determine the button label from an element definition.
     *
     * Checks properties in this priority order: display_html (raw HTML,
     * no MathJax), display (plain text), display_latex / displayLatex
     * (MathJax rendered), label containing backslash (MathJax rendered),
     * label (plain text).
     *
     * @param {Object} el Element definition.
     * @returns {Object} {html, text, needsTypeset} or null.
     */
    function resolveLabel(el) {
        // Property display_html: raw HTML label, highest priority, no MathJax.
        if (el.display_html) {
            return {
                html: '<span class="sme-tb-lbl sme-tb-lbl-html">'
                    + el.display_html + '</span>',
                needsTypeset: false
            };
        }

        // Property display: plain-text label.
        if (el.display) {
            return {html: null, text: el.display, needsTypeset: false};
        }

        // Property display_latex / displayLatex: MathJax-rendered label.
        var dl = el.display_latex || el.displayLatex;
        if (dl) {
            return {
                html: '<span class="sme-tb-lbl">'
                    + '\\(' + dl + '\\)</span>',
                needsTypeset: true
            };
        }

        // Label containing backslash: treat as LaTeX, render with MathJax.
        if (el.label && el.label.indexOf('\\') >= 0) {
            return {
                html: '<span class="sme-tb-lbl">'
                    + '\\(' + el.label + '\\)</span>',
                needsTypeset: true
            };
        }

        // Plain label: render as text.
        if (el.label) {
            return {html: null, text: el.label, needsTypeset: false};
        }

        return null;
    }

    /**
     * Insert a structured model into the field.
     *
     * The model decides; MathQuill gets the shape through its own structure API, so no LaTeX
     * string is assembled here.
     *
     * @param {Object} field MathQuill field.
     * @param {Object} model Structured model.
     */
    function insertModel(field, model) {
        var size = Model.dimensions(model);

        if (model.type === 'vector') {
            if (model.orientation === 'row') {
                field.insertRowVector(model.elements.length, Model.ENVIRONMENTS.vector);
            } else {
                field.insertColumnVector(model.elements.length, Model.ENVIRONMENTS.vector);
            }
        } else {
            field.insertMatrix({
                rows: size.rows,
                columns: size.columns,
                environment: Model.ENVIRONMENTS.matrix
            });
        }

        field.focus();
    }

    /**
     * Describe the structure the cursor is in, in the terms the choosers use.
     *
     * A 1 x n or n x 1 matrix is a vector to this editor, which is how it was inserted, so the
     * vector chooser recognises it as one and the matrix chooser as a matrix.
     *
     * @param {Object} field MathQuill field.
     * @returns {?Object} {rows, columns, dimension, orientation, isVector} or null.
     */
    function structureAtCursor(field) {
        var described = typeof field.matrixAtCursor === 'function'
            ? field.matrixAtCursor()
            : null;

        if (!described) {
            return null;
        }

        var isvector = described.rows === 1 || described.columns === 1;

        return {
            rows: described.rows,
            columns: described.columns,
            isVector: isvector,
            orientation: described.rows === 1 ? 'row' : 'column',
            dimension: described.rows === 1 ? described.columns : described.rows
        };
    }

    /**
     * Change the size of the structure the cursor is in.
     *
     * Asks first when filled cells would be discarded: growing is free, shrinking is not, and a
     * student should not lose an entry to a menu choice.
     *
     * @param {Object} field MathQuill field.
     * @param {Object} model Structured model with the requested size.
     * @param {Object} strings Language strings.
     * @returns {boolean} True when the structure was resized.
     */
    function resizeStructure(field, model, strings) {
        var size = Model.dimensions(model);
        var preview = field.resizeMatrix({
            rows: size.rows,
            columns: size.columns,
            dryRun: true
        });

        if (!preview.from) {
            return false;
        }

        if (preview.cellsLost > 0) {
            // No English fallback: a missing string shows as Moodle's "[[key]]" marker.
            var question = (strings.resize_confirm || '[[resize_confirm]]').replace('{a}', preview.cellsLost);
            // eslint-disable-next-line no-alert
            if (!window.confirm(question)) {
                return true;
            }
        }

        field.resizeMatrix({rows: size.rows, columns: size.columns});
        field.focus();

        return true;
    }

    /**
     * Open the chooser for a structure, then resize what is there or insert something new.
     *
     * @param {string} kind Either "matrix" or "vector".
     * @param {HTMLElement} button Button that was activated.
     * @param {Object|Function} target MQ field or getter.
     * @param {Object} defs Runtime definitions, for the language strings.
     */
    function insertFromPopup(kind, button, target, defs) {
        var open = kind === 'vector' ? Popup.openVectorChooser : Popup.openMatrixGrid;
        var field = resolve(target);
        var current = field ? structureAtCursor(field) : null;
        var strings = (defs && defs.strings) || {};

        // The chooser only offers to change what it could have made: the vector chooser for a
        // single row or column, the matrix chooser for everything else.
        if (current && current.isVector !== (kind === 'vector')) {
            current = null;
        }

        var max = maxDimensionFor(defs);

        open(button, function(model) {
            var f = resolve(target);
            if (!f) {
                return;
            }
            try {
                if (current && resizeStructure(f, model, strings)) {
                    return;
                }
                insertModel(f, model);
            } catch (ex) {
                dbg('Error: ' + ex.message);
            }
        }, current, max);
    }

    /**
     * The chooser limit for this editor.
     *
     * Already resolved on the server; the client only has to find the number for its slot.
     *
     * @param {Object} defs Runtime definitions, with the slot's limit attached by the caller.
     * @returns {number} Largest structure the choosers may offer.
     */
    function maxDimensionFor(defs) {
        return (defs && defs._maxDimension) || Model.QUICK_PICK_SIZE;
    }

    /**
     * Give a group the structure its break rule needs.
     *
     * Up to five buttons: nothing to do, the group stays whole. Beyond that, the first three and
     * the last three move into a cluster each, and the buttons in between stay direct children
     * of the group so that flexbox can break between them.
     *
     * The middle buttons are deliberately not wrapped in an element of their own, not even in
     * one with display:contents: a wrapper that has to be made invisible to the layout is a
     * wrapper that does not need to exist, and display:contents has a history of
     * dropping elements from the accessibility tree. Direct children wrap natively and no
     * browser has an opinion about them.
     *
     * The order of the buttons is untouched, so the DOM order, the visual order and the tab
     * order stay the same.
     *
     * @param {jQuery} $group Group element with its buttons already appended.
     */
    function clusterGroup($group) {
        var $buttons = $group.children();
        var layout = Layout.plan($buttons.length);

        if (layout.atomic) {
            return;
        }

        var $start = $('<span>').addClass('sme-tb-cluster sme-tb-cluster-start');
        var $end = $('<span>').addClass('sme-tb-cluster sme-tb-cluster-end');

        $buttons.slice(0, layout.start).appendTo($start);
        $buttons.slice($buttons.length - layout.end).appendTo($end);

        $group.addClass('sme-tb-group-wrap');
        $group.prepend($start);
        $group.append($end);
    }

    /**
     * Create one toolbar button.
     *
     * @param {Object} el Element definition.
     * @param {Object|Function} target MQ field or getter.
     * @param {Object} defs Runtime definitions, for the language strings.
     * @returns {jQuery|null} Button or null.
     */
    function makeButton(el, target, defs) {
        if (!el || typeof el !== 'object') {
            return null;
        }

        var resolved = resolveCommand(el);
        if (!resolved) {
            return null;
        }

        var action = resolved.action;
        var command = resolved.command;

        var labelInfo = resolveLabel(el);

        var $btn = $('<button>')
            .attr('type', 'button')
            .addClass(
                'btn btn-sm btn-outline-secondary'
                + ' sme-tb-btn')
            .attr('title',
                el.tooltip || el.display
                || el.label || command);
        // The visible content is a symbol ("√"); screen readers need the word from the
        // language pack instead.
        if (el.tooltip) {
            $btn.attr('aria-label', el.tooltip);
        }

        // Apply label.
        if (labelInfo && labelInfo.html) {
            $btn.html(labelInfo.html);
        } else if (labelInfo && labelInfo.text) {
            $btn.text(labelInfo.text);
        } else {
            $btn.text(command);
        }

        // Prevent focus steal from MQ field.
        $btn.on('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });

        if (action === 'popup') {
            $btn.attr('aria-haspopup', 'dialog').attr('aria-expanded', 'false');
            // Which chooser this button opens, so a browser test can find it without relying on
            // a translated label.
            $btn.attr('data-command', command);
        }

        $btn.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (action === 'popup') {
                insertFromPopup(command, this, target, defs);
                return;
            }

            var f = resolve(target);
            if (!f) {
                return;
            }

            try {
                if (action === 'write') {
                    f.write(command);
                    // Templates: MathQuill leaves the cursor after the written LaTeX;
                    // 'left' moves it back into the template's first field (e.g. the integrand).
                    for (var steps = parseInt(el.left, 10) || 0; steps > 0; steps--) {
                        f.keystroke('Left');
                    }
                } else if (action === 'keystroke') {
                    f.keystroke(command);
                } else if (action === 'matrix') {
                    f.insertMatrix({
                        rows: parseInt(command.rows, 10) || 2,
                        columns: parseInt(command.columns, 10) || 2,
                        environment: command.environment || 'pmatrix'
                    });
                } else {
                    f.cmd(command);
                }
                f.focus();
            } catch (ex) {
                dbg('Error: ' + ex.message);
            }
        });

        return $btn;
    }

    return /** @alias module:local_stackmatheditor/toolbar */ {

        /**
         * Build a toolbar from config and definitions.
         *
         * @param {Object|Function} target MQ field or getter.
         * @param {Object} config Enabled group flags.
         * @param {Object} defs Definitions.
         * @param {number} maxdimension Chooser limit for this slot, resolved on the server.
         * @returns {jQuery} Toolbar element.
         */
        build: function(target, config, defs, maxdimension) {
            var $bar = $('<div>').addClass('sme-toolbar');

            // The chooser limit is resolved per slot on the server; the caller knows the
            // slot, the toolbar only the number. It belongs to THIS toolbar: the definitions are
            // shared by every editor on the page, and writing the limit into them would let the
            // last toolbar built decide for all the others. A view of the definitions carries it.
            if (defs && maxdimension) {
                defs = Object.create(defs);
                defs._maxDimension = maxdimension;
            }

            // The popup labels come from the language pack via the definitions export.
            if (defs && defs.popupStrings) {
                Popup.setStrings(defs.popupStrings);
            }

            var groups = defs.groups
                || defs.elementGroups
                || {};

            var key;
            var elements;
            var i;
            var $btn;
            var buttonCount = 0;

            for (key in config) {
                if (!config.hasOwnProperty(key)) {
                    continue;
                }
                if (!config[key]
                    || key.charAt(0) === '_') {
                    continue;
                }

                elements = extractElements(groups[key]);
                if (!elements.length) {
                    continue;
                }

                var $grp = $('<span>')
                    .addClass('sme-tb-group')
                    .attr('data-group', key);

                for (i = 0; i < elements.length; i++) {
                    $btn = makeButton(
                        elements[i], target, defs);
                    if ($btn) {
                        $grp.append($btn);
                        buttonCount++;
                    }
                }

                if ($grp.children().length > 0) {
                    clusterGroup($grp);
                    $bar.append($grp);
                }
            }

            dbg('built: ' + buttonCount
                + ' buttons');

            return $bar;
        },

        /**
         * Typeset toolbar labels via MathJax.
         * Retries if MathJax not ready yet.
         *
         * @param {jQuery} $bar Toolbar element.
         */
        typeset: function($bar) {
            if (!$bar || !$bar.length) {
                return;
            }

            /**
             * Try to typeset, retry if needed.
             *
             * @param {number} attempt Current attempt.
             */
            function tryTypeset(attempt) {
                if (window.MathJax
                    && window.MathJax.typesetPromise) {
                    window.MathJax.typesetPromise(
                        [$bar[0]]
                    ).then(function() {
                        dbg('MathJax typeset OK');
                        return null;
                    }).catch(function(e) {
                        dbg('MathJax error: ' + e);
                    });
                    return;
                }
                if (window.MathJax
                    && window.MathJax.Hub) {
                    window.MathJax.Hub.Queue(
                        ['Typeset',
                            window.MathJax.Hub, $bar[0]]);
                    return;
                }
                if (attempt < 10) {
                    setTimeout(function() {
                        tryTypeset(attempt + 1);
                    }, 300);
                }
            }

            setTimeout(function() {
                tryTypeset(0);
            }, 100);
        }
    };
});
