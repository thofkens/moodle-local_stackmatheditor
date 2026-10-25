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
 * Single-line MathQuill editor for STACK input fields.
 *
 * Supports: data-stack-input-type="algebraic" and "units".
 *
 * @module     local_stackmatheditor/input_fields
 * @package
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'jquery',
    'local_stackmatheditor/tex2max',
    'local_stackmatheditor/max2tex',
    'local_stackmatheditor/toolbar',
    'local_stackmatheditor/operator_map',
    'local_stackmatheditor/stack_bridge',
    'local_stackmatheditor/local_validation',
    'local_stackmatheditor/a11y',
    'local_stackmatheditor/editor_toggle'
], function(
    $,
    tex2max,
    max2tex,
    toolbar,
    OperatorMap,
    Bridge,
    LocalValidation,
    A11y,
    Toggle
) {
    'use strict';

    var TYPES = ['algebraic', 'units'];

    /**
     * Build selector for supported types.
     *
     * @returns {string} Selector.
     */
        function selector() {
        // Primary: STACK attribute selectors.
        var attrSelectors = TYPES.map(function(t) {
            return 'input[data-stack-input-type="' + t + '"]';
        });
        // Fallback: STACK-typical *_ansN name/id patterns for STACK versions
        // that do not emit data-stack-input-type.
        attrSelectors.push('input[name*="_ans"]', 'input[id*="_ans"]');
        return attrSelectors.join(',');
    }

    /**
     * Trigger STACK's validation mechanism.
     * STACK listens for specific events on inputs.
     *
     * @param {jQuery} $input Hidden input.
     */
    function triggerStackValidation($input) {
        // One native input + change event; they reach jQuery handlers too.
        Bridge.triggerValidation($input[0]);
    }

    /**
     * Sync MathQuill to hidden input.
     *
     * @param {Object} mqField MathQuill field.
     * @param {jQuery} $input Hidden input.
     * @param {Object} convOpts Conversion options.
     * @param {Function} dbg Debug logger.
     * @param {boolean} [silent] Write without raising events (right before a submit).
     * @returns {string} The Maxima string that was written.
     */
    function syncToInput(mqField, $input, convOpts, dbg, silent) {
        var latex = mqField.latex();
        var maxima = '';
        var result;
        if (latex && latex.trim()) {
            try {
                // An incomplete structure yields no CAS text and a local message.
                result = tex2max.analyse(latex, convOpts);
                maxima = result.maxima;
                LocalValidation.show(mqField.el(), result.problems);
            } catch (e) {
                maxima = latex;
            }
        } else {
            LocalValidation.show(mqField.el(), []);
        }
        var oldVal = $input.val();
        $input.val(maxima);

        // Only trigger validation if value changed.
        if (maxima !== oldVal && !silent) {
            triggerStackValidation($input);
            dbg('Sync: LaTeX="' + latex
                + '" Maxima="' + maxima + '"');
        }

        // What we last wrote, so that the return channel can tell its own echo from a
        // change somebody else made.
        return maxima;
    }


    /**
     * Find a top-level relation operator in one expression.
     *
     * @param {string} expr Maxima fragment.
     * @returns {boolean} True if a top-level relation exists.
     */
    function hasTopLevelRelation(expr) {
        var depth = 0;
        var i;
        var twochar;
        for (i = 0; i < expr.length; i++) {
            if (expr.charAt(i) === '(') {
                depth++;
                continue;
            }
            if (expr.charAt(i) === ')') {
                depth = Math.max(0, depth - 1);
                continue;
            }
            if (depth !== 0) {
                continue;
            }
            twochar = expr.slice(i, i + 2);
            if (twochar === '<=' || twochar === '>=' || twochar === '#=' ||
                    twochar === '~=') {
                return true;
            }
            if (expr.charAt(i) === '=' || expr.charAt(i) === '<' ||
                    expr.charAt(i) === '>' || expr.charAt(i) === '#') {
                return true;
            }
        }
        return false;
    }

    /**
     * Split a Maxima expression at the top-level system join (nounand).
     *
     * @param {string} expr Maxima expression.
     * @returns {string[]} Parts or the original expression.
     */
    function splitTopLevelAnd(expr) {
        var parts = [];
        var depth = 0;
        var start = 0;
        var i;
        var prev;
        var next;
        var part;
        var kw = OperatorMap.SYSTEM_JOIN;

        for (i = 0; i < expr.length; i++) {
            if (expr.charAt(i) === '(') {
                depth++;
                continue;
            }
            if (expr.charAt(i) === ')') {
                depth = Math.max(0, depth - 1);
                continue;
            }
            if (depth !== 0 || expr.slice(i, i + kw.length) !== kw) {
                continue;
            }
            prev = i > 0 ? expr.charAt(i - 1) : '';
            next = i + kw.length < expr.length ? expr.charAt(i + kw.length) : '';
            if ((prev && /[A-Za-z0-9_]/.test(prev)) ||
                    (next && /[A-Za-z0-9_]/.test(next))) {
                continue;
            }
            part = expr.slice(start, i).trim();
            if (part) {
                parts.push(part);
            }
            start = i + kw.length;
            i += kw.length - 1;
        }

        part = expr.slice(start).trim();
        if (part) {
            parts.push(part);
        }

        return parts;
    }

    /**
     * Remove one pair of outer parentheses if they wrap the whole expression.
     *
     * @param {string} expr Expression.
     * @returns {string} Unwrapped expression.
     */
    function unwrapOuterParens(expr) {
        var text = (expr || '').trim();
        var depth = 0;
        var i;

        if (!text || text.charAt(0) !== '(' ||
                text.charAt(text.length - 1) !== ')') {
            return text;
        }

        for (i = 0; i < text.length; i++) {
            if (text.charAt(i) === '(') {
                depth++;
            } else if (text.charAt(i) === ')') {
                depth--;
                if (depth === 0 && i < text.length - 1) {
                    return text;
                }
            }
        }

        return text.slice(1, -1).trim();
    }

    /**
     * Detect a relation system: relations joined by the structural nounand.
     * A logical "and" (∧ button) is a statement, not a system.
     *
     * @param {string} maxima Maxima input.
     * @returns {?Array} Relation parts or null.
     */
    function getRelationSystemParts(maxima) {
        var trimmed = (maxima || '').trim();
        var parts;
        if (!trimmed) {
            return null;
        }
        parts = splitTopLevelAnd(trimmed);
        if (parts.length < 2) {
            return null;
        }
        if (!parts.every(function(part) {
            return hasTopLevelRelation(unwrapOuterParens(part));
        })) {
            return null;
        }
        return parts.map(function(part) {
            return unwrapOuterParens(part);
        });
    }

    /**
     * Convert one MathQuill field to Maxima.
     *
     * @param {Object} mqField MathQuill field.
     * @param {Object} convOpts Conversion options.
     * @returns {string} Maxima fragment.
     */
    function latexFieldToMaxima(mqField, convOpts) {
        var latex = mqField.latex();
        var result;
        if (!latex || !latex.trim()) {
            LocalValidation.show(mqField.el(), []);
            return '';
        }
        try {
            result = tex2max.analyse(latex, convOpts);
            LocalValidation.show(mqField.el(), result.problems);
            return result.maxima;
        } catch (e) {
            return latex;
        }
    }

    /**
     * Sync a graphical relation system editor back to the hidden input.
     *
     * @param {Array} rows Row descriptors.
     * @param {jQuery} $input Hidden input.
     * @param {Object} convOpts Conversion options.
     * @param {Function} dbg Debug logger.
     * @param {boolean} [silent] Write without raising events (right before a submit).
     */
    function syncSystemToInput(rows, $input, convOpts, dbg, silent) {
        var maxima = rows.map(function(row) {
            return latexFieldToMaxima(row.mqField, convOpts).trim();
        }).filter(function(value) {
            return !!value;
        }).map(function(value) {
            return '(' + value + ')';
        }).join(' ' + OperatorMap.SYSTEM_JOIN + ' ');
        var oldVal = $input.val();

        $input.val(maxima);

        if (maxima !== oldVal && !silent) {
            triggerStackValidation($input);
            dbg('System sync: Maxima="' + maxima + '"');
        }
    }

    /**
     * Rebuild the rows of the system editor from a Maxima string.
     *
     * Used when the editor comes back after the student has typed into the plain input: the
     * rows on screen are stale then, and what counts is what the input holds. A value that is
     * not a system becomes one row rather than nothing.
     *
     * @param {Object} spec Same object attachSystemToggle() works with.
     * @param {string} maxima Current value of the input.
     */
    function rebuildSystemRows(spec, maxima) {
        var parts = getRelationSystemParts(maxima);

        if (!parts || !parts.length) {
            parts = (maxima && maxima.trim()) ? [maxima.trim()] : [''];
        }

        spec.rows.length = 0;
        spec.$rowsWrap.empty();
        spec.getActiveField(null);

        parts.forEach(function(part) {
            createSystemRow(
                spec.$rowsWrap,
                spec.rows,
                spec.ctx,
                spec.$input,
                spec.convOpts,
                part,
                spec.varMode,
                spec.getActiveField
            );
        });

        if (spec.rows.length) {
            spec.getActiveField(spec.rows[0].mqField);
            spec.rows[0].$row.addClass('sme-system-row-active');
        }
    }

    /**
     * Put the on/off switch above the system editor.
     *
     * Off hands the lines over as one nounand-joined answer and brings the plain input back
     * into its place; on reads that answer - or whatever was typed in the meantime - back into
     * the lines.
     *
     * @param {Object} spec Everything the switch needs.
     * @returns {Object} The toggle, as editor_toggle returns it.
     */
    function attachSystemToggle(spec) {
        var handedOver = null;
        var strings = (spec.ctx.defs && spec.ctx.defs.strings) || {};
        var toggle = Toggle.create({
            input: spec.$input[0],
            editor: [spec.$tb[0], spec.$container[0]],
            strings: strings,
            toInput: function() {
                syncSystemToInput(spec.rows, spec.$input, spec.convOpts, spec.ctx.dbg, true);
                handedOver = spec.$input.val();
            },
            toEditor: function() {
                var current = spec.$input.val();
                if (current !== handedOver) {
                    rebuildSystemRows(spec, current);
                }
                spec.$input.val(current);
            }
        });

        if (spec.mayToggle) {
            spec.$wrap.prepend(toggle.element);
        }

        return toggle;
    }

    /**
     * Create one graphical system row.
     *
     * @param {jQuery} $rowsWrap Rows wrapper.
     * @param {Array} rows Row registry.
     * @param {Object} ctx Shared context.
     * @param {jQuery} $input Hidden input.
     * @param {Object} convOpts Conversion options.
     * @param {string} maximaPart Initial maxima fragment.
     * @param {string} varMode Variable mode.
     * @param {Function} getActiveField Active field setter/getter.
     */
    function createSystemRow(
            $rowsWrap,
            rows,
            ctx,
            $input,
            convOpts,
            maximaPart,
            varMode,
            getActiveField) {
        var row = {
            prefilling: !!(maximaPart && maximaPart.trim())
        };
        var $row = $('<div>').addClass('sme-system-row');
        var $mqWrap = $('<div>').addClass('sme-system-mqwrap');
        var $mqSpan = $('<span>');
        var $remove = $('<button>')
            .attr('type', 'button')
            .addClass('btn btn-link sme-system-del')
            .text('×');
        A11y.label($remove[0], 'aria_remove_row', true);

        $mqWrap.append($mqSpan);
        $row.append($mqWrap).append($remove);
        $rowsWrap.append($row);
        row.$row = $row;
        row.$mqWrap = $mqWrap;
        row.$mqSpan = $mqSpan;

        row.mqField = ctx.MQ.MathField($mqSpan[0], {
            // Space inserts a space, it does not navigate. STACK's space-sensitive
            // "insert stars" variants read "a b" differently from "ab", so the editor has to be
            // able to produce that boundary at all. Tab and Shift-Tab still leave the block.
            spaceBehavesLikeTab: false,
            // Typing "U_max" would otherwise give U_{\max}: a subscript is a label, not a
            // function call. Ignored by MathQuill 0.10.1, which lacks the option.
            disableAutoSubstitutionInSubscripts: true,
            // "Umax" is one variable, not U times max: an operator name counts only
            // when it is the whole word. Needs the fork build; MathQuill 0.10.1 ignores it.
            autoOperatorNamesOnlyWholeWord: true,
            handlers: {
                edit: function() {
                    if (row.prefilling) {
                        return;
                    }
                    syncSystemToInput(rows, $input, convOpts, ctx.dbg);
                },
                enter: function() {
                    Bridge.signalEnter($input[0], {trigger: 'key', inputType: 'system'});
                }
            }
        });
        A11y.labelEditor(row.mqField);

        /**
         * Mark the current system row as active.
         */
        function activateRow() {
            rows.forEach(function(entry) {
                entry.$row.removeClass('sme-system-row-active');
            });
            $row.addClass('sme-system-row-active');
            getActiveField(row.mqField);
        }

        $mqWrap.on('click', function() {
            activateRow();
            row.mqField.focus();
        });

        $row.on('mousedown focusin', function() {
            activateRow();
        });

        $remove.on('click', function() {
            var index = rows.indexOf(row);
            if (rows.length <= 1 || index === -1) {
                return;
            }
            row.$row.remove();
            rows.splice(index, 1);
            if (rows[index]) {
                rows[index].mqField.focus();
            } else if (rows[index - 1]) {
                rows[index - 1].mqField.focus();
            }
            syncSystemToInput(rows, $input, convOpts, ctx.dbg);
        });

        rows.push(row);

        if (row.prefilling) {
            setTimeout(function() {
                prefill(row.mqField, maximaPart, ctx.defs, varMode, ctx.dbg);
                setTimeout(function() {
                    row.prefilling = false;
                }, 0);
            }, 0);
        }

        return row;
    }

    /**
     * Pre-fill MathQuill from Maxima.
     *
     * @param {Object} mqField MathQuill field.
     * @param {string} maxima Maxima expression.
     * @param {Object} defs Definitions.
     * @param {string} varMode Variable mode.
     * @param {Function} dbg Debug logger.
     */
    function prefill(mqField, maxima, defs, varMode, dbg) {
        if (!maxima || !maxima.trim()) {
            return;
        }
        try {
            var latex = max2tex.convert(maxima, {
                defs: defs,
                variableMode: varMode
            });
            mqField.latex(latex);
            dbg('Pre-fill: Maxima="' + maxima
                + '" -> LaTeX="' + latex + '"');
        } catch (e) {
            dbg('Pre-fill error: ' + e.message);
            try {
                mqField.latex(maxima);
                dbg('Pre-fill fallback with raw value: "' + maxima + '"');
            } catch (fallbackError) {
                dbg('Pre-fill fallback error: ' + fallbackError.message);
            }
        }
    }

    /**
     * Is the editor enabled for this slot?
     *
     * The slot map is the per-question answer; where it says nothing - an adaptive quiz has no
     * per-question configuration - the page default applies. The page gate does not decide
     * this, so a missing entry must not be read as "on".
     *
     * @param {Object} ctx Shared context.
     * @param {string} slot Slot number as a string.
     * @returns {boolean} True when this field gets an editor.
     */
    function slotIsEnabled(ctx, slot) {
        if (ctx.slotEnabled
                && Object.prototype.hasOwnProperty.call(ctx.slotEnabled, slot)) {
            return !!ctx.slotEnabled[slot];
        }

        return ctx.defaultEnabled !== false;
    }

    /**
     * The chooser limit for a slot.
     *
     * @param {Object} ctx Shared context.
     * @param {string} slot Slot number as a string.
     * @returns {number} Largest structure the choosers may offer here.
     */
    function maxDimensionForSlot(ctx, slot) {
        var perslot = ctx.slotMaxDimension || {};

        if (slot && Object.prototype.hasOwnProperty.call(perslot, slot)) {
            return perslot[slot];
        }

        return ctx.maxDimension || 0;
    }

    /**
     * May the student switch the editor off in this slot?
     *
     * A permission, not a preference: what the author switched off higher up cannot be switched
     * on here, and a remembered browser state does not override it either. When this is false
     * the switch is not rendered at all - a disabled control would suggest there is something to
     * decide.
     *
     * @param {Object} ctx Shared context.
     * @param {string} slot Slot number as a string.
     * @returns {boolean} True when the switch may be shown.
     */
    function mayToggleEditor(ctx, slot) {
        if (ctx.allowStudentToggle === false) {
            return false;
        }

        var perslot = ctx.slotStudentToggle || {};
        if (Object.prototype.hasOwnProperty.call(perslot, slot)) {
            return !!perslot[slot];
        }

        return true;
    }

    /**
     * Decide whether an input is none of the editor's business.
     *
     * Split out of initField() so that both stay within the complexity the Moodle ESLint
     * configuration allows.
     *
     * @param {jQuery} $input Candidate input.
     * @param {Object} ctx Shared context.
     * @returns {boolean} True when the input must be left alone.
     */
    function skipField($input, ctx) {
        var name = $input.attr('name') || '';
        var inputid = $input.attr('id') || '';

        if ($input.attr('data-sme-init') === '1') {
            return true;
        }

        // Anything the editor built itself is not an answer field: the on/off switch is an
        // <input> too, and an id ending in _ans1 would make it look like a STACK input - it
        // would get an editor of its own, whose switch would get another one.
        if ($input.closest('.sme-input-wrap').length) {
            return true;
        }

        // A STACK answer is typed, not ticked.
        if (['checkbox', 'radio', 'button', 'submit', 'file'].indexOf(
            ($input.attr('type') || '').toLowerCase()
        ) !== -1) {
            return true;
        }

        // Only an editable input gets an editor: a review page or a teacher-rendered
        // answer shows the value read-only, and a MathQuill field there would suggest the
        // answer could still be changed.
        if ($input.prop('disabled') || $input.prop('readonly')) {
            ctx.dbg('Skipping read-only input: ' + name);
            return true;
        }

        // Skip inputs that matched *_ans patterns but are not STACK inputs.
        // A genuine STACK algebraic input name ends with _ansN (one or more digits).
        if (!$input.attr('data-stack-input-type')
                && !/(^|_)ans\d+$/.test(name)
                && !/(^|_)ans\d+$/.test(inputid)) {
            ctx.dbg('Skipping non-STACK input: ' + name);
            return true;
        }

        return false;
    }

    /**
     * Initialize one field.
     *
     * @param {HTMLInputElement} input Original input.
     * @param {Object} ctx Shared context.
     */
    function initField(input, ctx) {
        var $input = $(input);

        if (skipField($input, ctx)) {
            return;
        }

        var name = $input.attr('name') || '';
        var slot = ctx.extractSlot(name);

        // Check per-slot enabled map.
        if (!slotIsEnabled(ctx, slot)) {
            ctx.dbg('Input ' + name + ' -> slot ' + slot + ' not enabled, skipping');
            return;
        }

        var config = ctx.slotConfigs[slot]
            || ctx.instanceDefaults;
        var varMode = ctx.slotVarModes[slot]
            || ctx.instanceVarMode;
        var commaDecimal = ctx.isCommaDecimal(
            $input, ctx.localeComma);
        var inputType = $input.attr(
            'data-stack-input-type') || 'algebraic';

        ctx.dbg('Input ' + name
            + ' -> slot ' + slot
            + ' -> type=' + inputType
            + ', varMode=' + varMode);

        var convOpts = {
            commaDecimal: commaDecimal,
            defs: ctx.defs,
            variableMode: varMode
        };

        // Build editor wrapper.
        var $wrap = $('<div>')
            .addClass('sme-input-wrap');

        // Toolbar — mqField doesn't exist yet.
        var mqField = null;
        var $tb = toolbar.build(
            function() {
                return mqField;
            },
            config, ctx.defs, maxDimensionForSlot(ctx, slot));
        $wrap.append($tb);

        // MQ container.
        var $container = $('<div>')
            .addClass('sme-mq-container');
        var $mqSpan = $('<span>');
        $container.append($mqSpan);
        $wrap.append($container);

        $container.on('click', function() {
            if (mqField) {
                mqField.focus();
            }
        });

        // Insert BEFORE the input, keep input in
        // its original DOM position so STACK's
        // validation feedback stays correctly placed.
        $input.before($wrap);
        Toggle.hideOriginal($input[0]);
        $input.attr('data-sme-init', '1');

        // Read the initial Maxima value from the HTML attribute (defaultValue)
        // rather than the JS .value property.
        //
        // WHY: STACK or Moodle JS can clear input.value before our AMD module
        // initialises (e.g. during STACK's own input validation setup). The
        // .defaultValue property always holds the value="" attribute written by
        // PHP, and is immune to JS manipulation unless form.reset() is called.
        // We fall back to .val() for dynamically created inputs where PHP never
        // set a value attribute but JS may have set the property.
        var initialMaxima = $input[0].defaultValue || $input.val();

        var systemParts = getRelationSystemParts(initialMaxima);
        var prefilling = (initialMaxima && initialMaxima.trim()) ? true : false;
        // True until the initial value has been written into the editor (two ticks after
        // creation, see the pre-fill below). External values are not taken before that.
        var initialPending = prefilling;
        // True while adoptExternalValue() is writing into the editor - its re-entrancy guard.
        var adopting = false;
        // The last value this editor wrote into the input, so the return channel can tell
        // its own echo from a change somebody else made.
        var lastwritten = initialMaxima || '';
        // True while this editor is writing into the input. syncToInput() dispatches the events
        // STACK listens for from inside itself, so they arrive before it has returned and before
        // lastwritten can be updated - the flag is what covers that window.
        var syncingToInput = false;
        var activeMqField = null;
        var getActiveField = null;

        if (systemParts) {
            var rows = [];
            var $systemWrap = $('<div>').addClass('sme-system-wrap');
            var $brace = $('<div>').addClass('sme-system-brace')
                .append($('<span>').addClass('sme-system-brace-top'))
                .append($('<span>').addClass('sme-system-brace-mid'))
                .append($('<span>').addClass('sme-system-brace-bottom'));
            var $rowsWrap = $('<div>').addClass('sme-system-rows');
            var $controls = $('<div>').addClass('sme-system-controls');
            var $add = $('<button>')
                .attr('type', 'button')
                .addClass('btn btn-secondary btn-sm sme-system-add')
                .text('+');

            $controls.append($add);
            $container.empty()
                .append($systemWrap);
            $systemWrap.append($brace)
                .append($rowsWrap)
                .append($controls);

            getActiveField = function(field) {
                if (typeof field !== 'undefined') {
                    activeMqField = field;
                }
                return activeMqField;
            };

            mqField = {
                focus: function() {
                    if (activeMqField) {
                        activeMqField.focus();
                    } else if (rows.length) {
                        rows[0].mqField.focus();
                    }
                },
                latex: function() {
                    return rows.map(function(row) {
                        return row.mqField.latex();
                    }).join(' \\ ');
                }
            };

            systemParts.forEach(function(part) {
                createSystemRow(
                    $rowsWrap,
                    rows,
                    ctx,
                    $input,
                    convOpts,
                    part,
                    varMode,
                    getActiveField
                );
            });

            if (rows.length) {
                getActiveField(rows[0].mqField);
                rows[0].$row.addClass('sme-system-row-active');
            }

            $add.on('click', function() {
                var row = createSystemRow(
                    $rowsWrap,
                    rows,
                    ctx,
                    $input,
                    convOpts,
                    '',
                    varMode,
                    getActiveField
                );
                row.prefilling = false;
                row.mqField.focus();
                syncSystemToInput(rows, $input, convOpts, ctx.dbg);
                // The "+" button adds a row like Enter does, and says so.
                Bridge.signalEnter($input[0], {trigger: 'button', inputType: 'system'});
            });

            // On/off switch for the system editor; see attachSystemToggle().
            var systemToggle = attachSystemToggle({
                ctx: ctx,
                $input: $input,
                $wrap: $wrap,
                $tb: $tb,
                $container: $container,
                $rowsWrap: $rowsWrap,
                rows: rows,
                convOpts: convOpts,
                varMode: varMode,
                getActiveField: getActiveField,
                slot: slot,
                mayToggle: mayToggleEditor(ctx, slot)
            });

            toolbar.typeset($tb);
            setTimeout(function() {
                $input.val(initialMaxima);
                setTimeout(function() {
                    syncSystemToInput(rows, $input, convOpts, ctx.dbg);
                    systemToggle.apply(
                        mayToggleEditor(ctx, slot) ? !Toggle.startsOff() : true,
                        true
                    );
                }, 0);
            }, 0);

            // Check / Submit always send the visible system.
            Bridge.register(function() {
                syncSystemToInput(rows, $input, convOpts, ctx.dbg, true);
            });
            Bridge.guardStaleValidation($input[0]);
            return;
        }

        // Create MathQuill.
        mqField = ctx.MQ.MathField($mqSpan[0], {
            // Space inserts a space, it does not navigate. STACK's space-sensitive
            // "insert stars" variants read "a b" differently from "ab", so the editor has to be
            // able to produce that boundary at all. Tab and Shift-Tab still leave the block.
            spaceBehavesLikeTab: false,
            // Typing "U_max" would otherwise give U_{\max}: a subscript is a label, not a
            // function call. Ignored by MathQuill 0.10.1, which lacks the option.
            disableAutoSubstitutionInSubscripts: true,
            // "Umax" is one variable, not U times max: an operator name counts only
            // when it is the whole word. Needs the fork build; MathQuill 0.10.1 ignores it.
            autoOperatorNamesOnlyWholeWord: true,
            handlers: {
                edit: function() {
                    if (prefilling) {
                        return;
                    }
                    syncingToInput = true;
                    try {
                        lastwritten = syncToInput(
                            mqField, $input,
                            convOpts, ctx.dbg);
                    } finally {
                        syncingToInput = false;
                    }
                },
                enter: function() {
                    // No editor action on Enter; the signal stays observable.
                    Bridge.signalEnter($input[0], {trigger: 'key', inputType: 'algebraic'});
                }
            }
        });
        A11y.labelEditor(mqField);

        // The original STACK input is the integration point in both directions. STACK's
        // JSXGraph bindings, and any other script, write into it and dispatch an event; without
        // this the visible editor would keep showing the old answer while STACK already has the
        // new one - two different answers on one screen.
        //
        // The listeners sit on the element itself rather than on a container: an external
        // library is under no obligation to let its events bubble, and STACK's do not.
        /**
         * Take a value somebody else wrote into the input back into the editor.
         *
         * @param {Event} e The input or change event.
         */
        function adoptExternalValue(e) {
            var current = $input.val();

            // Our own write, echoed back by the event we raised for STACK, or a call from inside
            // an adoption. Not the prefilling flag: an adoption releases it only one tick later,
            // and a fast slider drag delivers its next pointer events - Chrome runs input before
            // timers - while it is still up. Checking that flag would drop the last value of a
            // drag and leave the editor one step behind the input.
            if (syncingToInput || adopting || initialPending || current === lastwritten) {
                return;
            }

            // With the editor switched off the input is what the student sees and types in;
            // taking its value into a hidden editor would be busywork, and switching back on
            // reads it anyway.
            if ($container.hasClass('sme-hidden')) {
                lastwritten = current;
                return;
            }

            ctx.dbg('External change on ' + ($input.attr('name') || '') + ': "' + current
                + '" (' + (e && e.type) + ')');

            // The editor is being told, not asked: writing the value back out now would raise a
            // second validation for a value STACK already has.
            prefilling = true;
            adopting = true;
            try {
                if (current && current.trim()) {
                    prefill(mqField, current, ctx.defs, varMode, ctx.dbg);
                } else {
                    mqField.latex('');
                }
                $input.val(current);
                lastwritten = current;
            } finally {
                adopting = false;
            }
            setTimeout(function() {
                prefilling = false;
            }, 0);
        }

        $input[0].addEventListener('input', adoptExternalValue);
        $input[0].addEventListener('change', adoptExternalValue);

        /**
         * Does the input hold a value this editor was given but could not show?
         *
         * A single-line editor cannot display everything a script may write into the input - a
         * relation system, for one. The editor is then empty although the input is not, and the
         * forced hand-over on Check and Submit must not write that emptiness over the value, or
         * the answer would be gone without anybody having deleted it. An empty editor that was
         * never edited has nothing to say. Once the student types or deletes, the edit handler has
         * written the editor's state and this is false again.
         *
         * @returns {boolean} True while the input's value must be left alone.
         */
        function holdsUnshownValue() {
            var current = $input.val();

            return !!(current && current.trim())
                && current === lastwritten
                && !mqField.latex().trim();
        }

        // On/off switch for the editor. Only for the single-line editor: a relation
        // system would have to be rebuilt from the plain text on the way back, and silently
        // dropping what a student typed while the editor was off is not an option.
        var mayToggle = mayToggleEditor(ctx, slot);
        var toggle = Toggle.create({
            input: $input[0],
            editor: [$tb[0], $container[0]],
            strings: (ctx.defs && ctx.defs.strings) || {},
            toInput: function() {
                if (!prefilling && !holdsUnshownValue()) {
                    syncingToInput = true;
                    try {
                        lastwritten = syncToInput(mqField, $input, convOpts, ctx.dbg, true);
                    } finally {
                        syncingToInput = false;
                    }
                }
            },
            toEditor: function() {
                var current = $input.val();
                lastwritten = current;
                prefilling = true;
                if (current && current.trim()) {
                    prefill(mqField, current, ctx.defs, varMode, ctx.dbg);
                } else {
                    mqField.latex('');
                }
                $input.val(current);
                setTimeout(function() {
                    prefilling = false;
                }, 0);
            }
        });
        if (mayToggle) {
            $wrap.prepend(toggle.element);
        }

        // Check / Submit always send the visible state.
        Bridge.register(function() {
            if (!prefilling && !holdsUnshownValue()) {
                syncingToInput = true;
                try {
                    lastwritten = syncToInput(mqField, $input, convOpts, ctx.dbg, true);
                } finally {
                    syncingToInput = false;
                }
            }
        });
        Bridge.guardStaleValidation($input[0]);

        // Typeset toolbar (delayed for MathJax).
        toolbar.typeset($tb);

        // Apply the remembered choice after the pre-fill has settled, so that switching off
        // right away still hands the answer over correctly.
        setTimeout(function() {
            // Without the permission the editor stays as the author configured it, whatever
            // the browser remembers.
            toggle.apply(mayToggle ? !Toggle.startsOff() : true, true);
        }, 0);

        // Pre-fill: two nested setTimeout(0) calls are used intentionally.
        //
        // Outer setTimeout(0): lets MathQuill finish building its internal DOM
        //   after MathField() returns, before we write LaTeX into it.
        //
        // Inner setTimeout(0): clears the prefilling flag only AFTER all
        //   synchronous and microtask-queued MathQuill edit events from the
        //   latex() setter have fired and been suppressed. If we cleared the
        //   flag synchronously inside the outer callback, a MathQuill-internal
        //   deferred edit event could still fire with an empty value afterwards.
        //
        // After both ticks, we restore $input.val(initialMaxima) directly —
        // this is more reliable than calling syncToInput(), which round-trips
        // through tex2max and could produce a slightly different Maxima string.
        if (initialMaxima && initialMaxima.trim()) {
            setTimeout(function() {
                // Write the pre-filled LaTeX into the MathQuill field.
                prefill(mqField, initialMaxima,
                    ctx.defs, varMode, ctx.dbg);
                // Immediately restore the known-good Maxima value into the
                // hidden input. This is more reliable than calling syncToInput()
                // which would round-trip through tex2max and might produce a
                // slightly different Maxima string.
                $input.val(initialMaxima);
                lastwritten = initialMaxima;
                initialPending = false;
                // Release the prefilling guard after one more tick so that any
                // async MathQuill edit events triggered by the latex() call above
                // (which MathQuill can fire on a deferred internal setTimeout) are
                // still suppressed before normal user-edit handling resumes.
                setTimeout(function() {
                    prefilling = false;
                    // Final consistency check: if MathQuill is still empty at
                    // this point (e.g. latex() silently failed), try once more.
                    if (initialMaxima && !mqField.latex().trim()) {
                        ctx.dbg('Pre-fill retry for: ' + initialMaxima);
                        prefilling = true;
                        prefill(mqField, initialMaxima,
                            ctx.defs, varMode, ctx.dbg);
                        $input.val(initialMaxima);
                        setTimeout(function() {
                            prefilling = false;
                        }, 0);
                    }
                }, 0);
            }, 0);
        }
    }

    /**
     * Watch for questions rendered after page load.
     *
     * CAPQuiz, StudentQuiz and embedded questions replace the question in place over AJAX. The
     * observer is throttled through a timeout so that a burst of mutations leads to one pass,
     * and the pass itself is idempotent.
     *
     * @param {Object} ctx Shared context.
     * @param {Function} attach Called when new nodes may carry a STACK input.
     */
    function observe(ctx, attach) {
        var pending = null;

        if (typeof window.MutationObserver !== 'function' || !document.body) {
            return;
        }

        new window.MutationObserver(function(mutations) {
            var relevant = mutations.some(function(mutation) {
                return mutation.addedNodes && mutation.addedNodes.length;
            });

            if (!relevant || pending) {
                return;
            }

            pending = window.setTimeout(function() {
                pending = null;
                attach(ctx);
            }, 50);
        }).observe(document.body, {childList: true, subtree: true});
    }

    return /** @alias module:local_stackmatheditor/input_fields */ {

        /**
         * Find and init all supported input fields.
         *
         * @param {Object} ctx Shared context.
         */
        init: function(ctx) {
            A11y.useStrings(ctx.defs && ctx.defs.strings);
            this.attach(ctx);
            observe(ctx, this.attach.bind(this));
        },

        /**
         * Attach an editor to every STACK input that does not have one yet.
         *
         * Idempotent: initField() leaves a marker on the input, so calling this again after a
         * question has been replaced touches only the new one.
         *
         * @param {Object} ctx Shared context.
         */
        attach: function(ctx) {
            var $inputs = $(selector());
            if (!$inputs.length) {
                ctx.dbg('No supported input fields found');
                return;
            }
            ctx.dbg('Found ' + $inputs.length + ' input fields');
            $inputs.each(function() {
                initField(this, ctx);
            });
        }
    };
});
