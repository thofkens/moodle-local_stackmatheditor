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
 * Converts Maxima CAS notation back to LaTeX for MathQuill display.
 *
 * @module     local_stackmatheditor/max2tex
 * @package
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_stackmatheditor/operator_map'], function(OperatorMap) {
    'use strict';

    /**
     * Apply regex in fixpoint loop.
     *
     * @param {string} s Input.
     * @param {RegExp} regex Pattern.
     * @param {string|Function} replacement Replacement.
     * @param {number} [maxIter=50] Safety limit.
     * @returns {string} Result.
     */
    function fixpoint(s, regex, replacement, maxIter) {
        var max = maxIter || 50;
        var prev = '';
        while (s !== prev && max > 0) {
            prev = s;
            s = s.replace(regex, replacement);
            max--;
        }
        return s;
    }

    /**
     * Find matching closing paren.
     *
     * @param {string} s String.
     * @param {number} openPos Position of '('.
     * @returns {number} Position of ')' or -1.
     */
    function findCloseParen(s, openPos) {
        var depth = 1;
        var i = openPos + 1;
        while (i < s.length && depth > 0) {
            if (s[i] === '(') {
                depth++;
            }
            if (s[i] === ')') {
                depth--;
            }
            i++;
        }
        return depth === 0 ? i - 1 : -1;
    }

    /**
     * Find matching opening paren (backwards).
     *
     * @param {string} s String.
     * @param {number} closePos Position of ')'.
     * @returns {number} Position of '(' or -1.
     */
    function findOpenParen(s, closePos) {
        var depth = 1;
        var i = closePos - 1;
        while (i >= 0 && depth > 0) {
            if (s[i] === ')') {
                depth++;
            }
            if (s[i] === '(') {
                depth--;
            }
            i--;
        }
        return depth === 0 ? i + 1 : -1;
    }

    /**
     * Convert mixed-fraction form (N+p/q) → N\frac{p}{q}.
     *
     * This reverses the tex2max mixed-fraction guard that converts
     * N\frac{p}{q} → (N+p/q) to prevent implicit multiplication.
     * Must run before processFractions() to avoid the parentheses
     * being consumed by the fraction converter.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function processMixedFractions(s) {
        // Pattern: (N+p/q) where N, p, q are bare non-negative integers.
        return s.replace(
            /\((\d+)\+(\d+)\/(\d+)\)/g,
            '$1\\frac{$2}{$3}'
        );
    }

    /**
     * Convert set-theory Maxima keywords to LaTeX commands.
     *
     * notin must be handled before in to avoid partial matching.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function processSetTheoryKeywords(s) {
        s = s.replace(/\bnotin\b/g, '\\notin ');
        s = s.replace(/\bin\b/g, '\\in ');
        s = s.replace(/\bunion\b/g, '\\cup ');
        s = s.replace(/\bintersect\b/g, '\\cap ');
        s = s.replace(/\bsetdiff\b/g, '\\setminus ');
        s = s.replace(/\bsubset\b/g, '\\subset ');
        s = s.replace(/\bsuperset\b/g, '\\supset ');
        return s;
    }

    /**
     * Convert logic Maxima keywords to LaTeX commands.
     *
     * nexists must be handled before exists to avoid partial matching.
     * impliedby must be handled before implies.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function processLogicKeywords(s) {
        s = s.replace(/\bnexists\b/g, '\\nexists ');
        s = s.replace(/\bforall\b/g, '\\forall ');
        s = s.replace(/\bexists\b/g, '\\exists ');
        s = s.replace(/\bnot\b/g, '\\neg ');
        s = s.replace(/\bnounand\b/g, '\\land ');
        s = s.replace(/\bnounor\b/g, '\\lor ');
        s = s.replace(/\band\b/g, '\\land ');
        s = s.replace(/\bor\b/g, '\\lor ');
        s = s.replace(/\bimpliedby\b/g, '\\Leftarrow ');
        s = s.replace(/\bimplies\b/g, '\\Rightarrow ');
        s = s.replace(/\biff\b/g, '\\Leftrightarrow ');
        return s;
    }

    /**
     * Convert (a)/(b) -> \frac{a}{b}.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function processFractions(s) {
        var prev = '';
        var safety = 50;
        while (s !== prev && safety > 0) {
            safety--;
            prev = s;
            var divIdx = s.indexOf(')/(');
            if (divIdx === -1) {
                break;
            }
            var numOpen = findOpenParen(s, divIdx);
            if (numOpen === -1) {
                break;
            }
            var denClose = findCloseParen(s, divIdx + 2);
            if (denClose === -1) {
                break;
            }
            var num = s.substring(numOpen + 1, divIdx);
            var den = s.substring(divIdx + 3, denClose);
            s = s.substring(0, numOpen) +
                '\\frac{' + num + '}{' + den + '}' +
                s.substring(denClose + 1);
        }
        return s;
    }

    /**
     * Convert funcname(arg) to LaTeX.
     *
     * @param {string} s Input.
     * @param {string} funcName Maxima function name.
     * @param {string} latexCmd LaTeX command.
     * @param {string} wrapType 'brace' or 'paren'.
     * @returns {string} Converted.
     */
    function processFunc(s, funcName, latexCmd, wrapType) {
        var searchStr = funcName + '(';
        var result = '';
        var i = 0;
        var safety = 50;
        while (i < s.length && safety > 0) {
            safety--;
            var idx = s.indexOf(searchStr, i);
            if (idx === -1) {
                result += s.substring(i);
                break;
            }
            // Part of a longer identifier (asqrt(, x_1sqrt() - not this function.
            // A preceding pure number is a coefficient: 2sqrt(x) is 2*sqrt(x).
            if (idx > 0 && /[a-zA-Z0-9_]/.test(s[idx - 1])
                    && !/(^|[^a-zA-Z0-9_])[0-9]+$/.test(s.substring(0, idx))) {
                result += s.substring(i, idx + 1);
                i = idx + 1;
                continue;
            }
            result += s.substring(i, idx);
            var parenOpen = idx + funcName.length;
            var parenClose = findCloseParen(s, parenOpen);
            if (parenClose === -1) {
                result += s.substring(idx);
                i = s.length;
                break;
            }
            var arg = s.substring(parenOpen + 1, parenClose);
            if (wrapType === 'brace') {
                result += latexCmd + '{' + arg + '}';
            } else {
                result += latexCmd + '\\left(' + arg + '\\right)';
            }
            i = parenClose + 1;
        }
        return result;
    }

    /**
     * Convert n-th roots: (expr)^(1/(n)) -> \sqrt[n]{expr}.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function processNthRoots(s) {
        return fixpoint(s,
            /\(([^()]*?)\)\^\(1\/\(([^()]*?)\)\)/,
            '\\sqrt[$2]{$1}'
        );
    }

    /**
     * Convert ^(expr) -> ^{expr}.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function processExponents(s) {
        return fixpoint(s, /\^\(([^()]*?)\)/, '^{$1}');
    }

    /**
     * Convert subscripts: _(abc) -> _{abc}, _x -> _{x}.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function processSubscripts(s) {
        // First: _(content) -> _{content}.
        s = fixpoint(s, /_\(([^()]*?)\)/, '_{$1}');
        // Then: _x (single char not already in braces) -> _{x}.
        // Group the whole subscript, not just its first character: U_max is one identifier and
        // has to be drawn as U_{max}. Grouping only the first character would produce U_{m}ax,
        // which means something else entirely and does not survive a roundtrip.
        s = s.replace(/_([a-zA-Z0-9]+)(?!\{)(?!\()/g, '_{$1}');
        return s;
    }

    /**
     * Clean multiplication signs for LaTeX display.
     *
     * @param {string} s Input.
     * @returns {string} Converted.
     */
    function cleanMultiplication(s) {
        // Digit * letter — juxtapose (no explicit operator).
        s = s.replace(/(\d)\s*\*\s*([a-zA-Z\\])/g, '$1$2');
        // Letter * letter — use \cdot.
        s = s.replace(/([a-zA-Z)\]])\s*\*\s*([a-zA-Z\\(])/g, '$1\\cdot $2');
        // Remaining * — use \cdot.
        s = s.replace(/\*/g, '\\cdot ');
        return s;
    }

    /**
     * Marker character of a table operator.
     *
     * @param {string} name Operator name.
     * @returns {string} Marker.
     */
    function opMarker(name) {
        return OperatorMap.byName(name).marker;
    }

    /**
     * Split a function argument list at top-level commas.
     *
     * @param {string} s Argument list without the enclosing parentheses.
     * @returns {string[]} Arguments.
     */
    function splitArguments(s) {
        var parts = [];
        var depth = 0;
        var start = 0;
        var i;
        var ch;

        for (i = 0; i < s.length; i++) {
            ch = s.charAt(i);
            if ('([{\uE020'.indexOf(ch) !== -1) {
                depth++;
            } else if (')]}\uE021'.indexOf(ch) !== -1) {
                depth--;
            } else if (ch === ',' && depth === 0) {
                parts.push(s.substring(start, i).trim());
                start = i + 1;
            }
        }
        parts.push(s.substring(start).trim());
        return parts;
    }

    /**
     * Precedence of a rendered set node: relations 0, ∖ 1, ∪ 2, ∩ 3, operand 10.
     *
     * @param {string} name Function name.
     * @returns {number} Precedence.
     */
    function setPrecedence(name) {
        return {setdifference: 1, union: 2, intersection: 3}[name] || 0;
    }

    /**
     * Render one argument of a set function, returning text and precedence.
     *
     * @param {string} arg Maxima argument.
     * @returns {Object} {text, prec}.
     */
    function renderSetOperand(arg) {
        var m = arg.match(/^(union|intersection|setdifference)\(/);
        var text = renderSetFunctions(arg);

        if (m && findCloseParen(arg, m[1].length) === arg.length - 1) {
            return {text: text, prec: setPrecedence(m[1])};
        }
        if (/^[A-Za-z0-9_%.]+$/.test(arg) || /^[({[\uE020]/.test(arg) && findMatching(arg, 0) === arg.length - 1
                || /^[A-Za-z_][A-Za-z0-9_]*\(/.test(arg) && findCloseParen(arg, arg.indexOf('(')) === arg.length - 1) {
            return {text: text, prec: 10};
        }
        return {text: '(' + text + ')', prec: 10};
    }

    /**
     * Find the matching bracket of any type.
     *
     * @param {string} s Input.
     * @param {number} pos Index of the opening bracket.
     * @returns {number} Index of the closing bracket or -1.
     */
    function findMatching(s, pos) {
        var depth = 0;
        var i;

        for (i = pos; i < s.length; i++) {
            if ('([{\uE020'.indexOf(s.charAt(i)) !== -1) {
                depth++;
            } else if (')]}\uE021'.indexOf(s.charAt(i)) !== -1) {
                depth--;
                if (depth === 0) {
                    return i;
                }
            }
        }
        return -1;
    }

    /**
     * Operand texts, bracketed where their precedence is below minPrec.
     *
     * @param {Object[]} nodes Rendered operands {text, prec}.
     * @param {number} minPrec Precedence of the enclosing operator.
     * @returns {string[]} Operand texts.
     */
    function bracketBelow(nodes, minPrec) {
        return nodes.map(function(node) {
            return node.prec < minPrec ? '(' + node.text + ')' : node.text;
        });
    }

    /**
     * Render STACK's set functions as infix operator markers.
     *
     * union(A,B) → A ∪ B, intersection → ∩, setdifference → ∖, elementp → ∈,
     * not elementp → ∉, subsetp → ⊆, and the proper-subset form
     * "subsetp(A,B) and A#B" written by tex2max → ⊂. Brackets are added
     * where the precedence ∩ > ∪ requires them, and always around a set
     * operation next to ∖.
     *
     * @param {string} s Maxima expression.
     * @returns {string} Expression with operator markers.
     */
    function renderSetFunctions(s) {
        var re = /(^|[^A-Za-z0-9_%])((?:not|nounnot)\s+)?(elementp|subsetp|union|intersection|setdifference)\(/;
        var out = '';
        var rest = s;
        var m;
        var start;
        var open;
        var close;
        var args;
        var nodes;
        var prec;
        var text;
        var after;
        var proper;

        while ((m = rest.match(re)) !== null) {
            start = m.index + m[1].length;
            open = start + (m[2] || '').length + m[3].length;
            close = findCloseParen(rest, open);
            if (close === -1) {
                break;
            }
            out += rest.substring(0, start);
            args = splitArguments(rest.substring(open + 1, close));
            after = rest.substring(close + 1);
            nodes = args.map(renderSetOperand);
            prec = setPrecedence(m[3]);

            if (m[3] === 'elementp' && args.length === 2) {
                text = nodes[0].text + ' ' + opMarker(m[2] ? 'notin' : 'in') + ' ' + nodes[1].text;
            } else if (m[3] === 'subsetp' && args.length === 2) {
                proper = after.match(/^\s*(?:and|nounand)\s*/);
                if (proper && after.substring(proper[0].length).indexOf(args[0] + '#' + args[1]) === 0) {
                    after = after.substring(proper[0].length + (args[0] + '#' + args[1]).length);
                    text = nodes[0].text + ' ' + opMarker('subset') + ' ' + nodes[1].text;
                    // Drop the brackets tex2max put around the proper-subset form.
                    if (/\($/.test(out) && /^\)/.test(after)) {
                        out = out.substring(0, out.length - 1);
                        after = after.substring(1);
                    }
                } else {
                    text = nodes[0].text + ' ' + opMarker('subseteq') + ' ' + nodes[1].text;
                }
            } else if (m[3] === 'setdifference' && args.length === 2) {
                // Always bracket a set operation next to ∖: A ∖ B ∪ C is read
                // differently by different people, (A ∖ B) ∪ C by nobody.
                text = (nodes[0].prec < 10 ? '(' + nodes[0].text + ')' : nodes[0].text)
                    + ' ' + opMarker('setminus') + ' '
                    + (nodes[1].prec < 10 ? '(' + nodes[1].text + ')' : nodes[1].text);
            } else if (m[3] === 'union' || m[3] === 'intersection') {
                text = bracketBelow(nodes, prec).join(' ' + opMarker(m[3] === 'union' ? 'cup' : 'cap') + ' ');
            } else {
                text = rest.substring(start, close + 1);
            }
            out += text;
            rest = after;
        }
        return out + rest;
    }

    /**
     * Replace integral calls by placeholders holding their LaTeX.
     *
     * Reads the evaluated form integrate(…) / int(…) and the noun forms 'int(…), 'integrate(…)
     * and nounint(…) - with 2 arguments (indefinite) or 4 (definite). Other arities are left
     * alone. Written back as \int_{a}^{b} expr\,\mathrm{d}x, the form tex2max reads.
     *
     * @param {string} s Maxima expression.
     * @param {Object} options Conversion options.
     * @param {string[]} store Collected LaTeX snippets.
     * @returns {string} Expression with placeholders.
     */
    function extractIntegralCalls(s, options, store) {
        var re = /(^|[^A-Za-z0-9_%])('?)(integrate|int|nounint)\(/;
        var out = '';
        var rest = s;
        var m;
        var start;
        var open;
        var close;
        var args;
        var body;
        var tex;

        while ((m = rest.match(re)) !== null) {
            start = m.index + m[1].length;
            open = start + m[2].length + m[3].length;
            close = findCloseParen(rest, open);
            if (close === -1) {
                break;
            }
            args = splitArguments(rest.substring(open + 1, close));
            if (args.length !== 2 && args.length !== 4) {
                out += rest.substring(0, close + 1);
                rest = rest.substring(close + 1);
                continue;
            }
            body = convert(args[0], options);
            // Brackets only where the integrand is a sum; the differential ends it anyway.
            if (/[+-]/.test(stripEnclosingParens(args[0]).replace(/^[-+]/, '').replace(/\([^()]*\)/g, ''))) {
                body = '\\left(' + body + '\\right)';
            }
            tex = '\\int';
            if (args.length === 4) {
                tex += '_{' + convert(args[2], options) + '}^{' + convert(args[3], options) + '}';
            }
            // No "\\," before the differential: MathQuill cannot parse it and would drop the
            // whole pre-filled answer.
            tex += ' ' + body + '\\mathrm{d}' + convert(args[1], options);
            out += rest.substring(0, start) + '\uE060' + store.length + '\uE061';
            store.push(tex);
            rest = rest.substring(close + 1);
        }
        return out + rest;
    }

    /**
     * Environments used when Maxima structures are written back as LaTeX.
     *
     * Vectors keep round brackets, matrices square ones, mirroring the toolbar. |...| is a
     * determinant and ‖...‖ a norm, so those two are not a matter of taste.
     *
     * @type {Object}
     */
    var MATRIX_ENVIRONMENTS = {
        vector: 'pmatrix',
        matrix: 'bmatrix',
        determinant: 'vmatrix',
        norm: 'Vmatrix'
    };

    /**
     * Wrap rows in a LaTeX matrix environment.
     *
     * @param {string[]} rows Rows, cells already joined with &.
     * @param {string} environment Environment name.
     * @returns {string} LaTeX.
     */
    function matrixEnvironment(rows, environment) {
        return '\\begin{' + environment + '}' +
            rows.join('\\\\') +
            '\\end{' + environment + '}';
    }

    /**
     * Split a Maxima matrix(...) call into its rows.
     *
     * @param {string} s Argument list of matrix(...), without the surrounding call.
     * @returns {?string[][]} Rows of raw cells, or null if the argument list is not a matrix.
     */
    function matrixRows(s) {
        var args = splitArguments(s);
        var rows = [];
        var columns = -1;
        var row;
        var cells;
        var i;

        if (!args.length) {
            return null;
        }

        for (i = 0; i < args.length; i++) {
            row = args[i].trim();
            if (row.charAt(0) !== '[' || row.charAt(row.length - 1) !== ']') {
                return null;
            }
            cells = splitArguments(row.substring(1, row.length - 1));
            if (columns === -1) {
                columns = cells.length;
            } else if (cells.length !== columns) {
                return null;
            }
            rows.push(cells);
        }

        return rows;
    }

    /**
     * LaTeX for a Maxima matrix(...) call.
     *
     * @param {string[][]} rows Raw cells.
     * @param {Object} options Conversion options.
     * @param {?string} environment Forced environment, or null for the shape-dependent default.
     * @returns {string} LaTeX.
     */
    function matrixLatex(rows, options, environment) {
        var isvector = rows.length === 1 || rows[0].length === 1;
        var rendered = rows.map(function(cells) {
            return cells.map(function(cell) {
                return convert(cell, options);
            }).join('&');
        });

        return matrixEnvironment(
            rendered,
            environment || (isvector ? MATRIX_ENVIRONMENTS.vector : MATRIX_ENVIRONMENTS.matrix)
        );
    }

    /**
     * Replace matrix(), determinant() and the configured norm function by LaTeX environments.
     *
     * Extracted into the placeholder store like integrals: the row and column separators must
     * not be touched by the later passes, and each cell is converted on its own.
     *
     * A matrix whose arguments are not all rows of equal length (Maxima allows matrix(a, b) with
     * list-valued variables) is left alone rather than rendered as something it is not. The same
     * holds for a determinant or norm of anything that is not a literal matrix: those become
     * ‖x‖ resp. |x| around the converted argument.
     *
     * With the vector format set to "list", a Maxima list is drawn as a row vector, because in
     * that mode a list is how the editor writes vectors. A list has no orientation, so a column
     * vector comes back as a row.
     *
     * @param {string} s       Maxima expression.
     * @param {Object} options Conversion options.
     * @param {Array}  store   Placeholder store.
     * @returns {string} String with structures replaced by placeholders.
     */
    function extractMatrixCalls(s, options, store) {
        var defs = options.defs || {};
        var normfunction = defs.normFunction || 'norm';
        var names = ['matrix', 'determinant', 'det', normfunction];
        var re = new RegExp(
            '(^|[^A-Za-z0-9_%])(\'?)(' + names.join('|') + ')\\('
        );
        var out = '';
        var rest = s;
        var m;
        var name;
        var start;
        var open;
        var close;
        var body;
        var rows;
        var inner;
        var tex;

        while ((m = rest.match(re)) !== null) {
            start = m.index + m[1].length;
            name = m[3];
            open = start + m[2].length + name.length;
            close = findCloseParen(rest, open);
            if (close === -1) {
                break;
            }

            body = rest.substring(open + 1, close);
            tex = null;

            if (name === 'matrix') {
                rows = matrixRows(body);
                if (rows) {
                    tex = matrixLatex(rows, options, null);
                }
            } else {
                inner = body.trim();
                rows = null;
                if (inner.indexOf('matrix(') === 0 && findCloseParen(inner, 6) === inner.length - 1) {
                    rows = matrixRows(inner.substring(7, inner.length - 1));
                }
                if (name === 'determinant' || name === 'det') {
                    tex = rows
                        ? matrixLatex(rows, options, MATRIX_ENVIRONMENTS.determinant)
                        : matrixEnvironment(
                            [convert(body, options)],
                            MATRIX_ENVIRONMENTS.determinant
                        );
                } else {
                    // ‖(x, y)‖: a vector keeps its own brackets inside the norm, a matrix does
                    // not — the norm bars replace its brackets.
                    tex = rows && !(rows.length === 1 || rows[0].length === 1)
                        ? matrixLatex(rows, options, MATRIX_ENVIRONMENTS.norm)
                        : matrixEnvironment(
                            [convert(body, options)],
                            MATRIX_ENVIRONMENTS.norm
                        );
                }
            }

            if (tex === null) {
                out += rest.substring(0, close + 1);
                rest = rest.substring(close + 1);
                continue;
            }

            out += rest.substring(0, start) + '\uE060' + store.length + '\uE061';
            store.push(tex);
            rest = rest.substring(close + 1);
        }

        return out + rest;
    }

    /**
     * In list mode, draw a Maxima list as a row vector.
     *
     * Only used when the vector format is "list": there a list is how the editor writes vectors,
     * so a pre-filled answer has to come back as one. Orientation is not part of a list, so a
     * column vector returns as a row — the trade-off the setting makes explicit.
     *
     * Indexing (a[1]) and nested lists are left alone.
     *
     * @param {string} s       Maxima expression.
     * @param {Object} options Conversion options.
     * @param {Array}  store   Placeholder store.
     * @returns {string} String with lists replaced by placeholders.
     */
    function extractListVectors(s, options, store) {
        var out = '';
        var i = 0;
        var depth;
        var startidx;
        var previous;
        var body;
        var cells;
        var ok;
        var j;

        while (i < s.length) {
            if (s.charAt(i) !== '[') {
                out += s.charAt(i);
                i++;
                continue;
            }

            previous = out.replace(/\s+$/, '').slice(-1);
            if (previous && /[A-Za-z0-9_\])]/.test(previous)) {
                // An index, not a list.
                out += s.charAt(i);
                i++;
                continue;
            }

            depth = 0;
            startidx = i;
            for (; i < s.length; i++) {
                if (s.charAt(i) === '[') {
                    depth++;
                } else if (s.charAt(i) === ']') {
                    depth--;
                    if (depth === 0) {
                        break;
                    }
                }
            }

            if (depth !== 0) {
                out += s.substring(startidx);
                return out;
            }

            body = s.substring(startidx + 1, i);
            cells = splitArguments(body);
            ok = cells.length > 0 && body.indexOf('[') === -1;
            for (j = 0; j < cells.length && ok; j++) {
                if (cells[j].trim() === '') {
                    ok = false;
                }
            }

            if (!ok) {
                out += s.substring(startidx, i + 1);
                i++;
                continue;
            }

            out += '\uE060' + store.length + '\uE061';
            store.push(
                matrixEnvironment(
                    [cells.map(function(cell) {
                        return convert(cell, options);
                    }).join('&')],
                    MATRIX_ENVIRONMENTS.vector
                )
            );
            i++;
        }

        return out;
    }

    /**
     * LaTeX of a derivative diff(expr, x[, n][, y, m …]) with the canonical ∂.
     *
     * diff(…) does not record whether d or ∂ was written, so ∂ is used throughout (a
     * deliberate design decision); the order of the variable/order pairs is kept as written.
     *
     * @param {string[]} args Arguments of diff (already split).
     * @param {Object} options Conversion options.
     * @returns {?string} LaTeX, or null for forms that are not handled (e.g. diff(expr)).
     */
    function derivativeLatex(args, options) {
        var pairs = [];
        var total = 0;
        var i;
        var order;

        if (args.length === 2) {
            pairs.push({variable: args[1], order: 1});
        } else if (args.length === 3 && /^\d+$/.test(args[2])) {
            pairs.push({variable: args[1], order: Number(args[2])});
        } else if (args.length >= 5 && args.length % 2 === 1) {
            for (i = 1; i < args.length; i += 2) {
                if (!/^\d+$/.test(args[i + 1])) {
                    return null;
                }
                pairs.push({variable: args[i], order: Number(args[i + 1])});
            }
        } else {
            return null;
        }
        if (pairs.some(function(pair) {
            return pair.order < 1 || !/^[A-Za-z%][A-Za-z0-9_]*$/.test(pair.variable);
        })) {
            return null;
        }
        total = pairs.reduce(function(sum, pair) {
            return sum + pair.order;
        }, 0);
        order = function(n) {
            return n > 1 ? '^{' + n + '}' : '';
        };
        return '\\frac{\\partial' + order(total) + '}{'
            + pairs.map(function(pair) {
                return '\\partial ' + convert(pair.variable, options) + order(pair.order);
            }).join('') + '}\\left(' + convert(args[0], options) + '\\right)';
    }

    /**
     * Replace derivative calls diff / 'diff / noundiff by placeholders holding their LaTeX.
     *
     * @param {string} s Maxima expression.
     * @param {Object} options Conversion options.
     * @param {string[]} store Collected LaTeX snippets.
     * @returns {string} Expression with placeholders.
     */
    function extractDerivativeCalls(s, options, store) {
        var re = /(^|[^A-Za-z0-9_%])('?)(diff|noundiff)\(/;
        var out = '';
        var rest = s;
        var m;
        var start;
        var open;
        var close;
        var tex;

        while ((m = rest.match(re)) !== null) {
            start = m.index + m[1].length;
            open = start + m[2].length + m[3].length;
            close = findCloseParen(rest, open);
            if (close === -1) {
                break;
            }
            tex = derivativeLatex(splitArguments(rest.substring(open + 1, close)), options);
            if (tex === null) {
                out += rest.substring(0, close + 1);
            } else {
                out += rest.substring(0, start) + '\uE060' + store.length + '\uE061';
                store.push(tex);
            }
            rest = rest.substring(close + 1);
        }
        return out + rest;
    }

    /**
     * Collapse "(A implies B) and (B implies A)" back into A ⇔ B.
     *
     * @param {string} s Maxima expression.
     * @returns {string} Expression with the ⇔ marker, or unchanged.
     */
    function collapseIff(s) {
        var parts = splitTopLevelKeyword(s, 'and');
        var first;
        var second;

        if (parts.length !== 2) {
            return s;
        }
        first = splitTopLevelKeyword(stripEnclosingParens(parts[0]), 'implies');
        second = splitTopLevelKeyword(stripEnclosingParens(parts[1]), 'implies');
        if (first.length === 2 && second.length === 2
                && first[0] === second[1] && first[1] === second[0]) {
            return first[0] + ' ' + opMarker('iff') + ' ' + first[1];
        }
        return s;
    }

    /**
     * Characters after which a sign is unary (mirrors tex2max's expansion).
     *
     * @type {RegExp}
     */
    var UNARY_BOUNDARY = /[=<>#(,[]/;

    /**
     * Merge two alternatives that differ only in coupled signs into one
     * expression carrying ± (\u00b1) and ∓ (\u2213).
     *
     * Walks both strings in parallel. Equal characters are copied; "+" against
     * "-" becomes ±, "-" against "+" becomes ∓. A sign present in only one
     * variant is accepted in unary position (start, after a relation, "(",
     * "," or "["), because tex2max drops the unary "+" of the positive
     * alternative: "x=2" against "x=-2" becomes "x=±2". Any other difference
     * means the alternatives are not a ± pair, and null is returned.
     *
     * @param {string} v1 First alternative (Maxima).
     * @param {string} v2 Second alternative (Maxima).
     * @returns {?string} Merged expression, or null when not collapsible.
     */
    function mergeSignAlternatives(v1, v2) {
        var out = '';
        var i = 0;
        var j = 0;
        var hasSign = false;
        var atBoundary;
        var a;
        var b;

        while (i < v1.length || j < v2.length) {
            a = v1.charAt(i);
            b = v2.charAt(j);
            atBoundary = out === '' || UNARY_BOUNDARY.test(out.charAt(out.length - 1));
            if (a === b && a !== '') {
                out += a;
                i++;
                j++;
            } else if (a === '+' && b === '-') {
                out += '\u00b1';
                hasSign = true;
                i++;
                j++;
            } else if (a === '-' && b === '+') {
                out += '\u2213';
                hasSign = true;
                i++;
                j++;
            } else if (atBoundary && b === '-' && a !== '+' && a !== '-') {
                // Unary "+" omitted in the first alternative.
                out += '\u00b1';
                hasSign = true;
                j++;
            } else if (atBoundary && a === '-' && b !== '+' && b !== '-') {
                // Unary "+" omitted in the second alternative.
                out += '\u2213';
                hasSign = true;
                i++;
            } else {
                return null;
            }
        }
        return hasSign ? out : null;
    }

    /**
     * Collapse exactly two sign alternatives back into one ± expression.
     *
     * Reads "(A) nounor (B)" only. A logical "or" is never collapsed: it is a
     * statement a student made with the ∨ button, and turning it into ± would
     * change its meaning on the next save. Anything else - more than two
     * alternatives, or alternatives that differ in more than coupled signs -
     * is returned unchanged and later rendered as a disjunction.
     *
     * @param {string} s Maxima expression.
     * @returns {string} Expression with ± / ∓, or the unmodified input.
     */
    function collapsePlusMinus(s) {
        // Only the structural nounor: a logical "or" typed with the ∨ button must
        // stay a logical statement and is never reinterpreted as a solution set.
        var keywords = [OperatorMap.SOLUTION_JOIN];
        var k;
        var parts;
        var merged;

        for (k = 0; k < keywords.length; k++) {
            parts = splitTopLevelKeyword(s, keywords[k]);
            if (parts.length !== 2) {
                continue;
            }
            merged = mergeSignAlternatives(
                stripEnclosingParens(parts[0]),
                stripEnclosingParens(parts[1])
            );
            if (merged !== null) {
                return merged;
            }
        }
        return s;
    }

    /**
     * Strip one level of enclosing parentheses if they wrap the whole string.
     *
     * @param {string} s Input.
     * @returns {string} Trimmed string.
     */
    function stripEnclosingParens(s) {
        var trimmed = s.trim();
        var changed = true;

        while (changed && trimmed.charAt(0) === '(' && trimmed.charAt(trimmed.length - 1) === ')') {
            changed = false;
            if (findCloseParen(trimmed, 0) === trimmed.length - 1) {
                trimmed = trimmed.substring(1, trimmed.length - 1).trim();
                changed = true;
            }
        }

        return trimmed;
    }

    /**
     * Split an expression by a top-level keyword.
     *
     * @param {string} s Input.
     * @param {string} keyword Keyword to split by.
     * @returns {Array} Parts.
     */
    function splitTopLevelKeyword(s, keyword) {
        var parts = [];
        var depth = 0;
        var start = 0;
        var i;
        var before;
        var after;
        var boundaryBefore;
        var boundaryAfter;

        for (i = 0; i < s.length; i++) {
            if (s.charAt(i) === '(') {
                depth++;
                continue;
            }
            if (s.charAt(i) === ')') {
                depth--;
                continue;
            }
            if (depth !== 0) {
                continue;
            }
            if (s.substring(i, i + keyword.length) !== keyword) {
                continue;
            }

            before = i > 0 ? s.charAt(i - 1) : ' ';
            after = i + keyword.length < s.length ? s.charAt(i + keyword.length) : ' ';
            boundaryBefore = /\s|\(/.test(before);
            boundaryAfter = /\s|\)/.test(after);

            if (!boundaryBefore || !boundaryAfter) {
                continue;
            }

            parts.push(s.substring(start, i).trim());
            start = i + keyword.length;
            i = start - 1;
        }

        parts.push(s.substring(start).trim());
        return parts;
    }

    /**
     * Find a top-level relation operator.
     *
     * @param {string} s Input.
     * @returns {?Object} Relation parts or null.
     */
    function parseTopLevelRelation(s) {
        var operators = ['<=', '>=', '~=', '#', '=', '<', '>'];
        var depth = 0;
        var i;
        var op;
        var oi;

        for (i = 0; i < s.length; i++) {
            if (s.charAt(i) === '(') {
                depth++;
                continue;
            }
            if (s.charAt(i) === ')') {
                depth--;
                continue;
            }
            if (depth !== 0) {
                continue;
            }
            for (oi = 0; oi < operators.length; oi++) {
                op = operators[oi];
                if (s.substring(i, i + op.length) === op) {
                    return {
                        lhs: s.substring(0, i).trim(),
                        operator: op,
                        rhs: s.substring(i + op.length).trim()
                    };
                }
            }
        }

        return null;
    }

    /**
     * Convert top-level and-connected relations to a LaTeX cases environment.
     *
     * @param {string} s Maxima input.
     * @returns {string} Cases environment or original input.
     */
    function convertRelationSystemToCases(s) {
        var normalized = stripEnclosingParens(s);
        // Only the structural nounand: a logical "and" is not an equation system.
        var parts = splitTopLevelKeyword(normalized, OperatorMap.SYSTEM_JOIN);
        var rows = [];
        var i;
        var relation;

        if (parts.length < 2) {
            return s;
        }

        for (i = 0; i < parts.length; i++) {
            parts[i] = stripEnclosingParens(parts[i]);
            relation = parseTopLevelRelation(parts[i]);
            if (!relation || !relation.lhs || !relation.rhs) {
                return s;
            }
            rows.push('{' + relation.lhs + ' &' + relation.operator + ' ' + relation.rhs + '}');
        }

        return '\\begin{cases} ' + rows.join(' \\\\ ') + ' \\end{cases}';
    }

    /**
     * Draw STACK's geometry functions in school notation again.
     *
     * Only the constructs whose STACK form is unambiguous:
     *
     *     Distance(A,B)  ->  d(A,B)
     *     Angle(A,B,C)   ->  \angle ABC
     *
     * A list is only drawn as a point when the site asks for it: [2,3] is a point in a geometry
     * question and an ordinary list everywhere else, and the editor cannot tell from the string.
     *
     * @param {string} s Maxima expression.
     * @param {Object} options Conversion options.
     * @returns {string} Expression in school notation.
     */
    function renderGeometry(s, options) {
        var defs = (options || {}).defs || {};
        var separator = defs.coordinateSeparator || '|';

        s = s.replace(
            /(^|[^A-Za-z0-9_%])Angle\(\s*([^(),]+?)\s*,\s*([^(),]+?)\s*,\s*([^(),]+?)\s*\)/g,
            '$1\\angle $2$3$4'
        );
        s = s.replace(
            /(^|[^A-Za-z0-9_%])Distance\(\s*([^(),]+?)\s*,\s*([^(),]+?)\s*\)/g,
            '$1d\\left($2,$3\\right)'
        );

        if (defs.pointNotation) {
            s = s.replace(
                /(^|[^A-Za-z0-9_%\]])\[([^[\]]+)\]/g,
                function(match, before, body) {
                    var parts = body.split(',').map(function(part) {
                        return part.trim();
                    });

                    if (parts.length < 2 || parts.some(function(part) {
                        return part === '' || part.indexOf('[') !== -1;
                    })) {
                        return match;
                    }

                    return before + '\\left(' + parts.join(separator) + '\\right)';
                }
            );
        }

        return s;
    }

    /**
     * LaTeX label of each differential operator, by semantic id.
     *
     * @type {Object}
     */
    var DIFFERENTIAL_OPERATOR_LATEX = {
        gradient: '\\operatorname{grad}',
        divergence: '\\operatorname{div}',
        curl: '\\operatorname{rot}',
        laplacian: '\\Delta'
    };

    /**
     * Draw the configured differential operators with their label.
     *
     * Runs on the finished LaTeX, where a function call is already \\left( ... \\right). Only
     * names the site has configured are recognised; anything else stays the function call it
     * is. The CAS name and the label are separate: a site whose curl is called "curl" still
     * shows "rot" to a German-speaking student.
     *
     * @param {string} s Maxima expression.
     * @param {Object} options Conversion options.
     * @returns {string} Expression with operator names replaced by their LaTeX label.
     */
    function renderDifferentialOperators(s, options) {
        var operators = ((options || {}).defs || {}).diffOps || {};

        Object.keys(DIFFERENTIAL_OPERATOR_LATEX).forEach(function(semantic) {
            var name = operators[semantic];
            if (!name) {
                return;
            }
            s = s.replace(
                new RegExp('(^|[^A-Za-z0-9_%])' + name + '\\s*(?=(?:\\\\left)?\\()', 'g'),
                '$1' + DIFFERENTIAL_OPERATOR_LATEX[semantic]
            );
        });

        return s;
    }

    /**
     * Marker for a space that separates two factors in the CAS string.
     *
     * @type {string}
     */
    var EXPLICIT_SPACE = '\uE003';

    /**
     * Protect the spaces STACK considers meaningful.
     *
     * Only a space between two operands counts: "a b" is a boundary, the space in "1 + 2" is
     * formatting. Runs after the keyword passes, so the spaces around "and", "in" or "union"
     * have already done their work. The marker travels through the remaining passes and becomes
     * a LaTeX control space at the end.
     *
     * @param {string} s Maxima expression.
     * @returns {string} Expression with protected spaces.
     */
    function protectExplicitSpaces(s) {
        return s.replace(
            /([A-Za-z0-9_%)\]]) +(?=[A-Za-z0-9_%([])/g,
            function(match, left, offset) {
                // The keyword passes have already produced LaTeX such as "x \in A"; the space
                // after a control word belongs to the command, not to the user.
                var before = s.substring(0, offset + left.length);
                if (/\\[A-Za-z]+$/.test(before)) {
                    return match;
                }

                return left + EXPLICIT_SPACE;
            }
        );
    }

    /**
     * Main Maxima -> LaTeX conversion.
     *
     * @param {string} maxima Maxima expression.
     * @param {Object} [options] Options.
     * @returns {string} LaTeX.
     */

    /**
     * Apply %-constant definitions from defs to a Maxima string.
     *
     * @param {string} s         Maxima expression.
     * @param {Array}  constants Constants array from defs.
     * @returns {string} String with constants replaced.
     */
    function processConstantsList(s, constants) {
        var k, con, conMaxima;
        for (k = 0; k < constants.length; k++) {
            con = constants[k];
            conMaxima = null;

            if (con && typeof con === 'object') {
                conMaxima = con.maxima || con.name || null;
            } else if (typeof con === 'string') {
                if (con === 'pi') {
                    conMaxima = '%pi';
                } else if (con === 'e') {
                    conMaxima = '%e';
                } else if (con === 'i') {
                    conMaxima = '%i';
                } else {
                    conMaxima = con;
                }
            }

            if (conMaxima === '%pi' || conMaxima === 'pi') {
                s = s.replace(/%pi/g, '\\pi ');
                s = s.replace(/(?<!\\)\bpi\b/g, '\\pi ');
            } else if (conMaxima === 'inf') {
                s = s.replace(/\binf\b/g, '\\infty ');
            } else if (conMaxima === 'minf') {
                s = s.replace(/\bminf\b/g, '-\\infty ');
            } else if (conMaxima === '%e' || conMaxima === 'e') {
                s = s.replace(/%e(?![a-zA-Z])/g, '\\mathrm{e}');
            } else if (conMaxima === '%i' || conMaxima === 'i') {
                s = s.replace(/%i(?![a-zA-Z])/g, '\\mathrm{i}');
            }
        }
        return s;
    }

    /**
     * Apply comparison operator definitions from defs to a Maxima string.
     *
     * @param {string} s          Maxima expression.
     * @param {Array}  comparison Comparison array from defs.
     * @returns {string} String with comparisons replaced.
     */
    function processComparisonList(s, comparison) {
        var k, cmpItem;
        for (k = 0; k < comparison.length; k++) {
            cmpItem = comparison[k];
            if (!cmpItem || typeof cmpItem !== 'object' || !cmpItem.maxima) {
                continue;
            }
            s = s.replace(
                new RegExp(cmpItem.maxima.replace(/([<>=#])/g, '\\$1'), 'g'),
                cmpItem.latex_write || cmpItem.maxima
            );
        }
        return s;
    }

    /**
     * Convert binomial(n,k) to \\binom{n}{k}, arguments and nesting included.
     *
     * @param {string} s Maxima expression.
     * @returns {string} String with binomial() replaced.
     */
    function processBinomial(s) {
        var guard = 20;
        var changed = true;

        while (changed && guard > 0) {
            changed = false;
            guard--;

            var at = s.indexOf('binomial(');
            while (at !== -1) {
                if (at > 0 && /[a-zA-Z0-9_]/.test(s.charAt(at - 1))) {
                    at = s.indexOf('binomial(', at + 1);
                    continue;
                }

                var open = at + 'binomial'.length;
                var close = findCloseParen(s, open);
                if (close === -1) {
                    break;
                }

                var args = splitArguments(s.substring(open + 1, close));
                if (args.length !== 2) {
                    at = s.indexOf('binomial(', at + 1);
                    continue;
                }

                s = s.substring(0, at)
                    + '\\binom{' + args[0].trim() + '}{' + args[1].trim() + '}'
                    + s.substring(close + 1);
                changed = true;
                break;
            }
        }

        return s;
    }

    /**
     * Replace abs(expr) with \left|expr\right|.
     *
     * @param {string} s Maxima expression.
     * @returns {string} String with abs() replaced.
     */
    function processAbsFunction(s) {
        var guard = 20;
        var before;

        // Nested absolute values need more than one pass: a call is replaced and its argument is
        // left as it is, inner abs( and all.
        do {
            before = s;
            s = processAbsFunctionOnce(s);
            guard--;
        } while (s !== before && guard > 0);

        return s;
    }

    /**
     * One pass of abs(expr) -> \\left|expr \\right|.
     *
     * @param {string} s Maxima expression.
     * @returns {string} String with the outermost abs() calls replaced.
     */
    function processAbsFunctionOnce(s) {
        var absSearch = 'abs(';
        var absResult = '';
        var absI = 0;
        var absIdx, absOpen, absClose, absArg;
        var safety = 50;
        while (absI < s.length && safety > 0) {
            safety--;
            absIdx = s.indexOf(absSearch, absI);
            if (absIdx === -1) {
                absResult += s.substring(absI);
                break;
            }
            if (absIdx > 0 && /[a-zA-Z0-9_]/.test(s[absIdx - 1])) {
                absResult += s.substring(absI, absIdx + 1);
                absI = absIdx + 1;
                continue;
            }
            absResult += s.substring(absI, absIdx);
            absOpen = absIdx + 3;
            absClose = findCloseParen(s, absOpen);
            if (absClose === -1) {
                absResult += s.substring(absIdx);
                absI = s.length;
                break;
            }
            absArg = s.substring(absOpen + 1, absClose);
            absResult += '\\left|' + absArg + '\\right|';
            absI = absClose + 1;
        }
        return absResult;
    }

    /**
     * Apply the hardcoded standard function list to a Maxima string.
     *
     * @param {string} s Maxima expression.
     * @returns {string} String with standard functions replaced.
     */
    function processStdFunctions(s) {
        var fi, sf;
        var stdFuncs = [
            ['sqrt', '\\sqrt', 'brace'],
            ['sin', '\\sin', 'paren'],
            ['cos', '\\cos', 'paren'],
            ['tan', '\\tan', 'paren'],
            ['asin', 'Bg\\sin', 'paren'],
            ['acos', 'Bg\\cos', 'paren'],
            ['atan', 'Bg\\tan', 'paren'],
            ['bgsin', 'Bg\\sin', 'paren'],
            ['bgcos', 'Bg\\cos', 'paren'],
            ['bgtan', 'Bg\\tan', 'paren'],
            ['arcsin', '\\arcsin', 'paren'],
            ['arccos', '\\arccos', 'paren'],
            ['arctan', '\\arctan', 'paren'],
            ['sinh', '\\sinh', 'paren'],
            ['cosh', '\\cosh', 'paren'],
            ['tanh', '\\tanh', 'paren'],
            ['exp', '\\exp', 'paren'],
            ['log', '\\ln', 'paren']
            // Deliberately without abs: processAbsFunction() owns it, and the generic wrapper
            // here would open a bar it never closes.
        ];
        // Repeat the whole list until nothing changes. processFunc() replaces a call and leaves
        // its argument as it is, so after a single pass an inner sqrt( would sit untouched inside
        // the LaTeX the outer one produced, and the student would see a root sign wrapped around
        // the word "sqrt". One more pass finds it, and the loop ends when a pass changes nothing.
        var guard = 20;
        var before;

        do {
            before = s;
            for (fi = 0; fi < stdFuncs.length; fi++) {
                sf = stdFuncs[fi];
                if (s.indexOf(sf[0] + '(') >= 0) {
                    s = processFunc(s, sf[0], sf[1], sf[2]);
                }
            }
            guard--;
        } while (s !== before && guard > 0);

        return s;
    }


    /**
     * Apply function definitions from defs to convert named functions to LaTeX.
     *
     * @param {string} s        Maxima expression.
     * @param {Array}  funcDefs Function definitions array from defs.
     * @returns {string} String with named functions replaced.
     */
    function processFunctionDefsList(s, funcDefs) {
        var k, def, wrapType;
        var guard = 20;
        var before;

        // Repeated until nothing changes, exactly as for the standard functions: a
        // configured function nested in itself is the same problem, and whether a function is
        // hardcoded or configured must not decide whether recursion works.
        do {
            before = s;
            for (k = 0; k < funcDefs.length; k++) {
                def = funcDefs[k];
                if (!def || typeof def !== 'object' || !def.maxima_name || !def.latex_cmd) {
                    continue;
                }
                wrapType = def.type === 'brace' ? 'brace' : 'paren';
                s = processFunc(s, def.maxima_name, def.latex_cmd, wrapType);
            }
            guard--;
        } while (s !== before && guard > 0);

        return s;
    }

    /**
     * Replace upper-case Greek letter names with LaTeX commands (fallback pass).
     *
     * @param {string} s Maxima expression.
     * @returns {string} String with upper-case Greek letters replaced.
     */
    function processUpperGreekList(s) {
        var ug, ugl;
        var upperGreek = [
            'Gamma', 'Delta', 'Theta', 'Lambda',
            'Xi', 'Pi', 'Sigma', 'Upsilon',
            'Phi', 'Psi', 'Omega'
        ];
        for (ug = 0; ug < upperGreek.length; ug++) {
            ugl = upperGreek[ug];
            s = s.replace(
                new RegExp('(?<![a-zA-Z\\\\])' + ugl + '(?![a-zA-Z])', 'g'),
                '\\' + ugl + ' '
            );
        }
        return s;
    }

    /**
     * Replace Greek letter names from defs with LaTeX commands.
     * Sorts longest-first to prevent prefix matches (e.g. "e" inside "epsilon").
     *
     * @param {string} s     Maxima expression.
     * @param {Array}  greek Greek letters array from defs.
     * @returns {string} String with Greek letters replaced.
     */
    function processGreekLettersList(s, greek) {
        var k;
        var sorted = greek.slice().sort(function(a, b) {
            return b.length - a.length;
        });
        for (k = 0; k < sorted.length; k++) {
            if (typeof sorted[k] !== 'string' || !sorted[k]) {
                continue;
            }
            s = s.replace(
                new RegExp('(?<![a-zA-Z\\\\])' + sorted[k] + '(?![a-zA-Z])', 'g'),
                '\\' + sorted[k] + ' '
            );
        }
        return s;
    }

    /**
     * Convert a Maxima expression string to LaTeX.
     *
     * @param {string} maxima  Maxima expression.
     * @param {Object} options Conversion options (defs, commaDecimal).
     * @returns {string} LaTeX string.
     */
    function convert(maxima, options) {
        var opts = options || {};
        var commaDecimal = opts.commaDecimal || false;
        var defs = opts.defs || {};
        var s = collapsePlusMinus(maxima.trim());
        var prev;
        var integrals = [];

        s = extractIntegralCalls(s, opts, integrals);
        s = extractDerivativeCalls(s, opts, integrals);
        s = extractMatrixCalls(s, opts, integrals);
        if ((opts.defs || {}).vectorFormat === 'list') {
            s = extractListVectors(s, opts, integrals);
        }

        // Maxima braces are set literals; LaTeX braces are grouping. Protect the
        // literals now and write them as \\left\\{ ... \\right\\} at the end.
        s = s.replace(/\{/g, '\uE020').replace(/\}/g, '\uE021');

        // Set functions and ⇔ become operator markers before any keyword pass.
        s = convertRelationSystemToCases(renderSetFunctions(collapseIff(s)));

        if (!s) {
            return s;
        }

        // Mixed fractions BEFORE fraction processing (order matters).
        s = processMixedFractions(s);

        // Set-theory and logic keywords BEFORE multiplication cleaning.
        s = processSetTheoryKeywords(s);
        s = processLogicKeywords(s);

        // Only now: before this point a space also separates keywords ("x in A", "p and q"),
        // and protecting those would keep the keyword passes from matching.
        s = protectExplicitSpaces(s);

        // %-constants -> LaTeX (BEFORE Greek letter replacement).
        s = processConstantsList(s, defs.constants || []);

        // Additional %-constants not in the constants list.
        s = s.replace(/%i(?![a-zA-Z])/g, '\\mathrm{i}');

        // ── Hardcoded constant fallbacks ────────────
        if (s.indexOf('%pi') >= 0) {
            s = s.replace(/%pi/g, '\\pi ');
        }
        // Bare "pi" → \pi (after logic keyword processing, so "pi" cannot match inside "implies" etc.).
        // Not after a backslash: the constants pass may already have produced "\pi", and a second
        // replacement would turn it into "\\pi", a LaTeX line break followed by the letters pi.
        s = s.replace(/(?<!\\)\bpi\b/g, '\\pi ');
        if (s.indexOf('inf') >= 0) {
            s = s.replace(/\bminf\b/g, '-\\infty ');
            s = s.replace(/\binf\b/g, '\\infty ');
        }
        if (s.indexOf('%e') >= 0) {
            s = s.replace(/%e(?![a-zA-Z])/g, '\\mathrm{e}');
        }

        // ── Comparison fallbacks ────────────────────
        s = s.replace(/<=/g, '\\leq ');
        s = s.replace(/>=/g, '\\geq ');
        s = s.replace(/#/g, '\\neq ');
        s = s.replace(/~=/g, '\\approx ');

        s = s.replace(/%phi(?![a-zA-Z])/g, '\\phi ');
        s = s.replace(/%gamma(?![a-zA-Z])/g, '\\gamma ');
        s = s.replace(/\bminf\b/g, '-\\infty ');

        // Comparison -> LaTeX.
        s = processComparisonList(s, defs.comparison || []);

        // Functions -> LaTeX.
        s = processFunctionDefsList(s, defs.functions || []);

        // Binomial: binomial(n,k) -> \\binom{n}{k}. The arguments are read with a bracket
        // counter and the pass repeats, so binomial(f(x),k) and a binomial inside another one
        // both survive.
        s = processBinomial(s);

        // Upper Greek fallback (if not handled by defs).
        s = processUpperGreekList(s);

        // ── Absolute value: abs(expr) -> \left|expr\right| ──
        s = processAbsFunction(s);

        // ── Hardcoded function fallbacks ────────────
        s = processStdFunctions(s);

        // Fractions.
        prev = '';
        while (s !== prev) {
            prev = s;
            s = processFractions(s);
        }

        // N-th roots.
        prev = '';
        while (s !== prev) {
            prev = s;
            s = processNthRoots(s);
        }

        // Exponents.
        prev = '';
        while (s !== prev) {
            prev = s;
            s = processExponents(s);
        }

        // Subscripts.
        s = processSubscripts(s);

        // Greek letters: word -> \word.
        s = processGreekLettersList(s, defs.greek || []);

        // Multiplication signs.
        s = cleanMultiplication(s);

        // Decimal separator.
        if (commaDecimal) {
            s = s.replace(/(\d)\.(\d)/g, '$1,$2');
        }

        s = s.replace(/\uE060(\d+)\uE061/g, function(match, index) {
            return integrals[Number(index)];
        });

        // Coupled signs restored by collapsePlusMinus().
        s = s.replace(/\u00b1/g, '\\pm ').replace(/\u2213/g, '\\mp ');

        s = s.replace(/\uE020/g, '\\left\\{').replace(/\uE021/g, '\\right\\}');

        // Set/logic operator markers from the central table.
        OperatorMap.SET_OPERATORS.concat(OperatorMap.LOGIC_OPERATORS).forEach(function(op) {
            if (op.marker) {
                s = s.split(op.marker).join(op.tex + ' ');
            }
        });

        // Late, on the finished LaTeX: the braces of \operatorname{...} must not be mistaken
        // for a Maxima set literal, and the operand brackets are \left( ... \right) by now.
        s = renderDifferentialOperators(s, options);
        s = renderGeometry(s, options);

        s = s.replace(/\s+/g, ' ').trim();
        // A space the user meant becomes MathQuill's control space, so the editor shows the
        // boundary again after a pre-fill.
        s = s.replace(new RegExp(EXPLICIT_SPACE, 'g'), '\\ ');
        return s;
    }

    /**
     * Detect whether a string is Maxima notation (vs LaTeX).
     *
     * @param {string} s String to check.
     * @returns {boolean} True if Maxima.
     */
    function isMaxima(s) {
        if (!s || !s.trim()) {
            return false;
        }
        // LaTeX indicators.
        if (/\\frac|\\sqrt|\\sin|\\cos|\\pi|\\left|\\pm|\\mp/.test(s)) {
            return false;
        }
        // Maxima indicators.
        if (/%pi|%e|sqrt\(|log\(|\)\/\(/.test(s)) {
            return true;
        }
        // No backslashes -> probably Maxima.
        if (s.indexOf('\\') === -1) {
            return true;
        }
        return false;
    }

    return /** @alias module:local_stackmatheditor/max2tex */ {
        convert: convert,
        isMaxima: isMaxima
    };
});
