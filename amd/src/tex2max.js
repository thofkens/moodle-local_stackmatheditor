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
 * Converts MathQuill LaTeX output to Maxima CAS notation.
 *
 * Features:
 * - Standard LaTeX → Maxima conversion
 * - Locale-aware decimal separator (comma → dot)
 * - Implicit multiplication handling with configurable modes
 * - Operator keyword protection (or, and, not, …)
 * - Configurable pi notation (pi / %pi)
 *
 * @module     local_stackmatheditor/tex2max
 * @package
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_stackmatheditor/operator_map'], function(OperatorMap) {
    'use strict';

    /**
     * Known unit abbreviations.
     * Sorted by length descending so longer matches are checked first.
     *
     * Fallback list; runtime defs.units take precedence when available.
     *
     * @type {string[]}
     */
    var UNITS = [
        'kHz', 'MHz', 'GHz',
        'kPa', 'MPa',
        'kcal',
        'kW', 'MW',
        'kJ', 'MJ', 'eV',
        'kN',
        'kV', 'mA',
        'kg', 'mg',
        'km', 'cm', 'mm', 'nm', 'um',
        'ms', 'mL', 'dL',
        'min',
        'mol',
        'Ohm', 'ohm',
        'bar', 'atm',
        'cal',
        'Hz',
        'Pa',
        'lb', 'oz', 'ft', 'yd', 'mi',
        'hr',
        'm', 'g', 's', 'h', 't',
        'N', 'J', 'W', 'V', 'A', 'K', 'L', 'F', 'C'
    ];

    /**
     * Maxima operator keywords that must never be split into individual
     * characters in single-variable mode and must be surrounded by spaces
     * rather than implicit-multiplication separators.
     *
     * @type {string[]}
     */
    var MAXIMA_OPERATOR_KEYWORDS = [
        'nounor', 'nounand',
        'or', 'and', 'not', 'mod', 'div', 'iff',
        'implies', 'impliedby', 'notin', 'in',
        'union', 'intersect', 'setdiff',
        'subset', 'superset',
        'forall', 'exists', 'nexists'
    ];

    /**
     * Maxima function names that are always recognised, independent of the
     * server-side definitions. Merged with defs.functionNames so that a
     * missing or partial definitions payload can never turn a function call
     * such as sqrt(x) into an implicit product (s*q*r*t*(x), see #39).
     *
     * @type {string[]}
     */
    var BUILTIN_FUNCTION_NAMES = [
        'sqrt', 'abs', 'sgn', 'exp', 'log', 'ln',
        'sin', 'cos', 'tan', 'cot', 'sec', 'csc',
        'arcsin', 'arccos', 'arctan', 'asin', 'acos', 'atan',
        'bgsin', 'bgcos', 'bgtan',
        'sinh', 'cosh', 'tanh', 'binomial', 'integrate', 'diff',
        // Maxima functions the editor has to know as complete tokens (#61): without them
        // max(x,y) picks up an implicit multiplication star before the bracket.
        'max', 'min'
    ];

    /**
     * Zero-width token boundary placed in front of every LaTeX control word.
     *
     * Converting a control word yields a bare Maxima word (\sqrt{x} becomes
     * sqrt(x), \pi becomes pi). Without a boundary that word fuses with
     * whatever precedes it: a\sqrt{b} became the identifier "asqrt", and
     * \pm\sqrt{...} became "\pmsqrt", which the \pm rule no longer
     * matched and single-variable mode split into p*m*s*q*r*t (#39).
     * The marker is skipped by the tokenizer and resolved at the very end.
     *
     * @type {string}
     */
    var BOUNDARY = '\uE000';

    /**
     * Build a fast-lookup set from an array of strings.
     *
     * @param {Array} list Array of strings.
     * @returns {Object} Object with string keys for O(1) lookup.
     */
    function buildWordSet(list) {
        var set = Object.create(null);
        var i;
        var item;

        list = list || [];
        for (i = 0; i < list.length; i++) {
            item = list[i];
            if (typeof item === 'string' && item) {
                set[item] = true;
            }
        }
        return set;
    }

    /**
     * Return a word-set of known unit abbreviations from defs or the built-in list.
     *
     * @param {Object} defs Definitions object from the server.
     * @returns {Object} Word set of unit strings.
     */
    function getUnitSet(defs) {
        if (defs && defs.units && defs.units.length) {
            return buildWordSet(defs.units);
        }
        return buildWordSet(UNITS);
    }

    /**
     * Return a word-set of function names: built-in list plus defs.
     *
     * @param {Object} defs Definitions object from the server.
     * @returns {Object} Word set of function names.
     */
    function getFunctionNameSet(defs) {
        var d = defs || {};
        return buildWordSet(
            BUILTIN_FUNCTION_NAMES.concat(d.functionNames || d.functions || [])
        );
    }

    /**
     * Build the combined protected-words set for identifier splitting.
     *
     * Merges MAXIMA_OPERATOR_KEYWORDS with all runtime definition sets
     * (function names, constants, Greek letters, reserved words, units,
     * %-constants). Any word in this set is kept intact and never split
     * into individual characters by expandIdentifiers().
     *
     * @param {Object} defs Definitions object from the server.
     * @returns {Object} Fast-lookup set of protected word strings.
     */
    function buildProtectedWords(defs) {
        var d = defs || {};
        var protectedWords = Object.create(null);
        var sets = [
            buildWordSet(MAXIMA_OPERATOR_KEYWORDS),
            getFunctionNameSet(d),
            buildWordSet(d.constants || []),
            buildWordSet(d.greek || []),
            buildWordSet(d.reservedWords || []),
            getUnitSet(d),
            buildWordSet(d.percentConstants || [])
        ];
        var si;
        var keys;
        var ki;

        for (si = 0; si < sets.length; si++) {
            keys = Object.keys(sets[si]);
            for (ki = 0; ki < keys.length; ki++) {
                protectedWords[keys[ki]] = true;
            }
        }
        return protectedWords;
    }

    /**
     * Normalise a variable mode string to a canonical form.
     *
     * Accepts legacy aliases ("single", "multi") and maps them to
     * the current canonical names.
     *
     * @param {string} mode Raw mode string.
     * @returns {string} Canonical mode string.
     */
    function normaliseImplicitMode(mode) {
        switch (mode) {
            case 'single':
                return 'explicit_single';
            case 'multi':
                return 'explicit_multi';
            case 'explicit_single':
            case 'explicit_multi':
            case 'space_single':
            case 'space_multi':
            case 'stack':
                return mode;
            default:
                return 'stack';
        }
    }

    /**
     * Return the implicit multiplication separator character for a given mode.
     *
     * @param {string} mode Variable mode string.
     * @returns {string} Separator: "*", " ", or "".
     */
    function getImplicitSeparator(mode) {
        mode = normaliseImplicitMode(mode);

        if (mode === 'explicit_single' || mode === 'explicit_multi') {
            return '*';
        }
        if (mode === 'space_single' || mode === 'space_multi') {
            return ' ';
        }
        return '';
    }

    /**
     * Replace decimal commas with dots, ignoring commas inside list brackets.
     *
     * @param {string} s Input string.
     * @returns {string} String with decimal commas replaced by dots.
     */
    function replaceDecimalCommas(s) {
        var result = '';
        var bracketDepth = 0;
        var i;

        for (i = 0; i < s.length; i++) {
            if (s[i] === '[') {
                bracketDepth++;
            }
            if (s[i] === ']' && bracketDepth > 0) {
                bracketDepth--;
            }
            if (s[i] === ',' && bracketDepth === 0) {
                if (i > 0 && i < s.length - 1 &&
                    /\d/.test(s[i - 1]) && /\d/.test(s[i + 1])) {
                    result += '.';
                    continue;
                }
            }
            result += s[i];
        }

        return result;
    }

    /**
     * Tokenize a LaTeX string for implicit multiplication detection.
     *
     * @param {string} s Preprocessed LaTeX string.
     * @returns {Array} Array of token objects with type and value.
     */
    function tokenizeForImplicitMultiplication(s) {
        var tokens = [];
        var i = 0;
        var ch;
        var rest;
        var m;

        while (i < s.length) {
            ch = s.charAt(i);

            if (/\s/.test(ch) || ch === BOUNDARY) {
                i++;
                continue;
            }

            rest = s.substring(i);

            // Placeholder of an already converted structure (integral): one atomic operand.
            m = rest.match(/^\uE050\d+\uE051/);
            if (m) {
                tokens.push({type: 'ident', value: m[0]});
                i += m[0].length;
                continue;
            }

            m = rest.match(/^\d+(?:[.,]\d+)?/);
            if (m) {
                tokens.push({type: 'number', value: m[0]});
                i += m[0].length;
                continue;
            }

            m = rest.match(/^%[a-zA-Z]+/);
            if (m) {
                tokens.push({type: 'ident', value: m[0]});
                i += m[0].length;
                continue;
            }

            m = rest.match(/^[a-zA-Z]+(?:_[a-zA-Z0-9]+)?/);
            if (m) {
                tokens.push({type: 'ident', value: m[0]});
                i += m[0].length;
                continue;
            }

            if (ch === '(') {
                tokens.push({type: 'open', value: ch});
                i++;
                continue;
            }
            if (ch === ')') {
                tokens.push({type: 'close', value: ch});
                i++;
                continue;
            }
            if (ch === ',') {
                tokens.push({type: 'comma', value: ch});
                i++;
                continue;
            }

            tokens.push({type: 'other', value: ch});
            i++;
        }

        return tokens;
    }

    /**
     * Expand multi-character identifiers into individual variables if required.
     *
     * Identifiers that appear in the protected-words set (function names,
     * constants, Greek letters, units, Maxima operator keywords such as
     * "or", "and", "not", …) are always kept intact.
     *
     * @param {Array}  tokens  Token array from tokenizeForImplicitMultiplication.
     * @param {Object} options Conversion options.
     * @returns {Array} Expanded token array.
     */
    function expandIdentifiers(tokens, options) {
        var opts = options || {};
        var defs = opts.defs || {};
        var mode = normaliseImplicitMode(opts.variableMode || 'stack');
        var splitIdentifiers = mode === 'explicit_single' || mode === 'space_single';
        var protectedWords = buildProtectedWords(defs);
        var out = [];
        var i;
        var tok;
        var value;
        var parts;

        for (i = 0; i < tokens.length; i++) {
            tok = tokens[i];

            if (tok.type !== 'ident') {
                out.push(tok);
                continue;
            }

            value = tok.value;

            if (!splitIdentifiers) {
                out.push(tok);
                continue;
            }

            if (protectedWords[value]
                    || value.charAt(0) === '%'
                    || value.indexOf('_') !== -1
                    || !/^[a-zA-Z]+$/.test(value)) {
                out.push(tok);
                continue;
            }

            parts = value.split('');
            parts.forEach(function(part) {
                out.push({type: 'ident', value: part});
            });
        }

        return out;
    }

    /**
     * Determine whether an implicit multiplication sign should be inserted
     * between two adjacent tokens.
     *
     * @param {Object} prev    Previous token.
     * @param {Object} curr    Current token.
     * @param {Object} options Conversion options.
     * @returns {boolean} True if a multiplication sign should be inserted.
     */
    /**
     * Return true if the token pair (prev, curr) blocks implicit multiplication.
     *
     * @param {Object} prev Previous token.
     * @param {Object} curr Current token.
     * @returns {boolean} True when multiplication is blocked.
     */
    function blocksImplicitMultiplication(prev, curr) {
        if (!prev || !curr) {
            return true;
        }
        if (prev.type === 'other' || curr.type === 'other') {
            return true;
        }
        if (prev.type === 'comma' || curr.type === 'comma') {
            return true;
        }
        return prev.type === 'open' || curr.type === 'close';
    }

    /**
     * Determine the multiplication rule for a close→X transition.
     *
     * @param {Object} curr Current token.
     * @returns {boolean} True if multiplication is needed.
     */
    function closeTokenNeedsMultiply(curr) {
        return curr.type === 'ident'
            || curr.type === 'number'
            || curr.type === 'open';
    }

    /**
     * Decide whether implicit multiplication is needed between two tokens.
     *
     * @param {Object} prev    Previous token.
     * @param {Object} curr    Current token.
     * @param {Object} options Conversion options (defs, variableMode).
     * @returns {boolean} True when a multiplication sign should be inserted.
     */
    function needsImplicitMultiplication(prev, curr, options) {
        var opts = options || {};
        var defs = opts.defs || {};
        var functionNames = getFunctionNameSet(defs);
        var unitSet = getUnitSet(defs);

        if (blocksImplicitMultiplication(prev, curr)) {
            return false;
        }

        if (prev.type === 'number' && curr.type === 'ident') {
            return !unitSet[curr.value];
        }
        if (prev.type === 'number' && curr.type === 'open') {
            return true;
        }
        if (prev.type === 'ident' && curr.type === 'ident') {
            return true;
        }
        if (prev.type === 'ident' && curr.type === 'open') {
            return !functionNames[prev.value];
        }
        if (prev.type === 'close') {
            return closeTokenNeedsMultiply(curr);
        }

        return false;
    }

    /**
     * Insert implicit multiplication signs between tokens where required.
     *
     * At keyword operator boundaries (or, and, not, …) a plain space is
     * always used regardless of the configured separator, so that keywords
     * are never glued to adjacent tokens with a "*" sign.
     *
     * @param {string} s       Preprocessed string.
     * @param {Object} options Conversion options.
     * @returns {string} String with explicit multiplication signs inserted.
     */
    function insertImplicitMultiplication(s, options) {
        var opts = options || {};
        var mode = normaliseImplicitMode(opts.variableMode || 'stack');
        var separator = getImplicitSeparator(mode);
        var tokens;
        var operatorKeywords;
        var out = '';
        var i;
        var prevIsKeyword;
        var currIsKeyword;

        if (mode === 'stack') {
            return s;
        }

        operatorKeywords = buildWordSet(MAXIMA_OPERATOR_KEYWORDS);
        tokens = tokenizeForImplicitMultiplication(s);
        tokens = expandIdentifiers(tokens, opts);

        for (i = 0; i < tokens.length; i++) {
            if (i > 0) {
                prevIsKeyword = tokens[i - 1].type === 'ident'
                    && operatorKeywords[tokens[i - 1].value];
                currIsKeyword = tokens[i].type === 'ident'
                    && operatorKeywords[tokens[i].value];

                if (prevIsKeyword || currIsKeyword) {
                    // Always use a plain space around keyword operators.
                    out += ' ';
                } else if (needsImplicitMultiplication(
                        tokens[i - 1], tokens[i], opts)) {
                    out += separator;
                }
            }
            out += tokens[i].value;
        }

        return out;
    }


    /**
     * Split a string by a delimiter that is only recognised at top level.
     *
     * @param {string} s Input.
     * @param {string} delimiter Delimiter.
     * @returns {Array} Parts.
     */
    function splitTopLevel(s, delimiter) {
        var parts = [];
        var depthParen = 0;
        var depthBrace = 0;
        var start = 0;
        var i;

        for (i = 0; i < s.length; i++) {
            if (s.charAt(i) === '(') {
                depthParen++;
                continue;
            }
            if (s.charAt(i) === ')' && depthParen > 0) {
                depthParen--;
                continue;
            }
            if (s.charAt(i) === '{') {
                depthBrace++;
                continue;
            }
            if (s.charAt(i) === '}' && depthBrace > 0) {
                depthBrace--;
                continue;
            }
            if (depthParen !== 0 || depthBrace !== 0) {
                continue;
            }
            if (s.substring(i, i + delimiter.length) !== delimiter) {
                continue;
            }

            parts.push(s.substring(start, i));
            start = i + delimiter.length;
            i = start - 1;
        }

        parts.push(s.substring(start));
        return parts;
    }

    /**
     * Strip one layer of outer braces around a row.
     *
     * @param {string} s Input.
     * @returns {string} Row without outer braces.
     */
    function stripOuterBraces(s) {
        var trimmed = s.trim();

        if (trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length - 1) === '}') {
            return trimmed.substring(1, trimmed.length - 1).trim();
        }

        return trimmed;
    }

    /**
     * Convert a LaTeX cases environment to an equation system: the rows are
     * joined by STACK's nounand, so that every row is assessed on its own.
     *
     * @param {string} s Input.
     * @returns {string} Converted string.
     */
    function convertCasesToAndRelations(s) {
        return s.replace(
            /(?:\\\[\s*)?\\begin\{cases\}([\s\S]*?)\\end\{cases\}(?:\s*\\\])?/g,
            function(match, body) {
                var rows = splitTopLevel(body, '\\\\');
                var parts = [];
                var i;
                var row;

                for (i = 0; i < rows.length; i++) {
                    row = stripOuterBraces(rows[i]);
                    if (!row) {
                        continue;
                    }
                    row = row.replace(/\s*&\s*/g, '');
                    row = row.replace(/\s+/g, ' ').trim();
                    if (!row) {
                        continue;
                    }
                    parts.push('(' + row + ')');
                }

                if (parts.length < 2) {
                    return match;
                }

                return parts.join(' ' + OperatorMap.SYSTEM_JOIN + ' ');
            }
        );
    }

    /**
     * Escape a string for use inside a regular expression.
     *
     * @param {string} str Literal text.
     * @returns {string} Escaped text.
     */
    function escapeRegExp(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Replace the LaTeX commands of the central operator table (#35).
     *
     * @param {string} s LaTeX string.
     * @returns {string} String with markers or Maxima operators.
     */
    function replaceTableOperators(s) {
        var ops = OperatorMap.SET_OPERATORS.concat(OperatorMap.LOGIC_OPERATORS);

        ops.forEach(function(op) {
            op.latex.forEach(function(cmd) {
                s = s.replace(
                    new RegExp(escapeRegExp(cmd) + '(?![a-zA-Z])', 'g'),
                    ' ' + (op.marker || op.maxima) + ' '
                );
            });
        });
        return s;
    }

    /**
     * Find the matching closing bracket for the opening bracket at pos.
     *
     * @param {string} s Input.
     * @param {number} pos Index of "(", "[" or "{".
     * @returns {number} Index of the matching bracket or -1.
     */
    function matchingBracket(s, pos) {
        var depth = 0;
        var i;
        var ch;

        for (i = pos; i < s.length; i++) {
            ch = s.charAt(i);
            if ('([{'.indexOf(ch) !== -1) {
                depth++;
            } else if (')]}'.indexOf(ch) !== -1) {
                depth--;
                if (depth === 0) {
                    return i;
                }
            }
        }
        return -1;
    }

    /**
     * Return the top-level indices at which one of the given markers occurs.
     *
     * @param {string} s Input.
     * @param {string[]} markers Marker characters.
     * @returns {number[]} Indices.
     */
    function topLevelIndices(s, markers) {
        var out = [];
        var depth = 0;
        var i;
        var ch;

        for (i = 0; i < s.length; i++) {
            ch = s.charAt(i);
            if ('([{'.indexOf(ch) !== -1) {
                depth++;
            } else if (')]}'.indexOf(ch) !== -1) {
                depth--;
            } else if (depth === 0 && markers.indexOf(ch) !== -1) {
                out.push(i);
            }
        }
        return out;
    }

    /**
     * True when x is a single operand: identifier, number, bracket group or function call.
     *
     * @param {string} x Expression.
     * @returns {boolean} Whether no brackets are needed around it.
     */
    function isAtomic(x) {
        var open;

        x = x.trim();
        if (/^[A-Za-z0-9_%.]+$/.test(x)) {
            return true;
        }
        if ('([{'.indexOf(x.charAt(0)) !== -1 && matchingBracket(x, 0) === x.length - 1) {
            return true;
        }
        open = x.indexOf('(');
        return /^[A-Za-z_][A-Za-z0-9_]*\(/.test(x) && matchingBracket(x, open) === x.length - 1;
    }

    /**
     * Put brackets around a compound operand.
     *
     * @param {string} x Expression.
     * @returns {string} Operand safe to combine with an operator.
     */
    function wrap(x) {
        x = x.trim();
        return isAtomic(x) ? x : '(' + x + ')';
    }

    /**
     * Remove brackets enclosing a whole function argument.
     *
     * Arguments are delimited by commas, so brackets around one are never
     * needed: union((a*b),c) is union(a*b,c). Dropping them keeps the output
     * identical after a roundtrip through max2tex.
     *
     * @param {string} x Expression.
     * @returns {string} Argument without enclosing brackets.
     */
    function unwrap(x) {
        x = x.trim();
        while (x.charAt(0) === '(' && matchingBracket(x, 0) === x.length - 1) {
            x = x.substring(1, x.length - 1).trim();
        }
        return x;
    }

    /**
     * Marker of a set operator by name.
     *
     * @param {string} name Operator name from the table.
     * @returns {string} Marker character.
     */
    function marker(name) {
        return OperatorMap.byName(name).marker;
    }

    /**
     * Maxima form of one set relation (#35). Proper subsets are a logical
     * statement and therefore use "and", not the structural nounand.
     *
     * @param {string} name Relation name from the operator table.
     * @param {string} left Left operand (Maxima).
     * @param {string} right Right operand (Maxima).
     * @returns {string} Maxima predicate.
     */
    function setRelation(name, left, right) {
        switch (name) {
            case 'in':
                return 'elementp(' + left + ',' + right + ')';
            case 'notin':
                return 'not elementp(' + left + ',' + right + ')';
            case 'subseteq':
                return 'subsetp(' + left + ',' + right + ')';
            case 'supseteq':
                return 'subsetp(' + right + ',' + left + ')';
            case 'subset':
                return '(subsetp(' + left + ',' + right + ') and ' + left + '#' + right + ')';
            default:
                return '(subsetp(' + right + ',' + left + ') and ' + right + '#' + left + ')';
        }
    }

    /**
     * Turn one set-level segment (no logic operator, relation or comma at top
     * level) into Maxima function calls.
     *
     * Relations bind loosest (a chain becomes a conjunction), then ∖, ∪, ∩
     * (tightest). ∖ is left-associative, ∪ and ∩ are n-ary.
     *
     * @param {string} seg Segment.
     * @returns {string} Converted segment.
     */
    function convertSetSegment(seg) {
        var t = seg.trim();
        var relations = ['notin', 'in', 'subseteq', 'supseteq', 'subset', 'supset'];
        var relMarkers = relations.map(marker);
        var idx;
        var left;
        var right;
        var parts;
        var operands;
        var start;

        idx = topLevelIndices(t, relMarkers);
        if (idx.length) {
            // A chain "A ⊃ B ⊂ C" means "A ⊃ B and B ⊂ C" (like a<b<c): each
            // relation gets its two neighbouring operands.
            operands = [];
            start = 0;
            idx.forEach(function(pos) {
                operands.push(unwrap(convertSetSegment(t.substring(start, pos))));
                start = pos + 1;
            });
            operands.push(unwrap(convertSetSegment(t.substring(start))));
            return idx.map(function(pos, k) {
                return setRelation(relations[relMarkers.indexOf(t.charAt(pos))], operands[k], operands[k + 1]);
            }).join(' and ');
        }

        idx = topLevelIndices(t, [marker('setminus')]);
        if (idx.length) {
            left = unwrap(convertSetSegment(t.substring(0, idx[idx.length - 1])));
            right = unwrap(convertSetSegment(t.substring(idx[idx.length - 1] + 1)));
            return 'setdifference(' + left + ',' + right + ')';
        }

        [['cup', 'union'], ['cap', 'intersection']].some(function(pair) {
            var positions = topLevelIndices(t, [marker(pair[0])]);
            var start = 0;

            if (!positions.length) {
                return false;
            }
            parts = [];
            positions.forEach(function(pos) {
                parts.push(unwrap(convertSetSegment(t.substring(start, pos))));
                start = pos + 1;
            });
            parts.push(unwrap(convertSetSegment(t.substring(start))));
            t = pair[1] + '(' + parts.join(',') + ')';
            return true;
        });
        return t;
    }

    /**
     * Split a level at top-level logic operators, relations and commas and
     * convert each set-level segment.
     *
     * @param {string} s Expression (bracket groups already converted).
     * @returns {string} Converted expression.
     */
    function convertSegments(s) {
        var boundary = /^(\s+(?:nounand|nounor|implies|and|or|xor)\s+|(?:^|\s)(?:not|nounnot)\s+|<=|>=|=|<|>|#|,)/;
        var out = '';
        var seg = '';
        var depth = 0;
        var i = 0;
        var ch;
        var m;

        while (i < s.length) {
            ch = s.charAt(i);
            if ('([{'.indexOf(ch) !== -1) {
                depth++;
            } else if (')]}'.indexOf(ch) !== -1) {
                depth--;
            }
            m = depth === 0 ? s.substring(i).match(boundary) : null;
            if (m && m[0] && (m[0].trim() !== 'not' || seg.trim() === '')) {
                out += convertSetSegment(seg) + m[0];
                seg = '';
                i += m[0].length;
                continue;
            }
            seg += ch;
            i++;
        }
        return out + convertSetSegment(seg);
    }

    /**
     * Convert the operator markers of one bracket level, innermost first (#35).
     *
     * ⇔ becomes the logical conjunction of both implications and ⇐ a swapped
     * implication, because STACK knows neither "iff" nor "impliedby".
     *
     * @param {string} s Expression.
     * @returns {string} Expression with Maxima function calls.
     */
    function convertLevel(s) {
        var out = '';
        var i = 0;
        var close;
        var idx;
        var a;
        var b;

        while (i < s.length) {
            if ('([{'.indexOf(s.charAt(i)) !== -1) {
                close = matchingBracket(s, i);
                if (close !== -1) {
                    out += s.charAt(i) + convertLevel(s.substring(i + 1, close)) + s.charAt(close);
                    i = close + 1;
                    continue;
                }
            }
            out += s.charAt(i);
            i++;
        }
        s = out;

        idx = topLevelIndices(s, [marker('iff')]);
        if (idx.length) {
            a = wrap(convertLevel(s.substring(0, idx[0])));
            b = wrap(convertLevel(s.substring(idx[0] + 1)));
            return '(' + a + ' implies ' + b + ') and (' + b + ' implies ' + a + ')';
        }
        idx = topLevelIndices(s, [marker('impliedby')]);
        if (idx.length) {
            a = wrap(convertLevel(s.substring(0, idx[0])));
            b = wrap(convertLevel(s.substring(idx[0] + 1)));
            return b + ' implies ' + a;
        }
        return convertSegments(s);
    }

    /**
     * Turn set/logic operator markers into STACK-valid Maxima (#35).
     *
     * @param {string} s Maxima string that may contain operator markers.
     * @returns {string} Converted string.
     */
    function convertStructuredOperators(s) {
        if (!/[\uE010-\uE01A]/.test(s)) {
            return s;
        }
        return convertLevel(s).replace(/\s+/g, ' ').trim();
    }

    /**
     * Drop every "+" that stands in unary position.
     *
     * @param {string} s One alternative of a ± / ∓ expansion.
     * @returns {string} Alternative without unary plus signs.
     */
    function stripUnaryPlus(s) {
        return s.replace(/(^|[=<>#(,[])\s*\+/g, '$1');
    }

    /**
     * Expand plus-minus (±) and minus-plus (∓) into two coupled alternatives
     * joined by STACK's non-simplifying "nounor" (#30).
     *
     * The signs are coupled, not combined: variant A reads ± as + and ∓ as -,
     * variant B reads ± as - and ∓ as +. "x=a±b∓c" therefore yields exactly
     * two alternatives, never four. Each sign is replaced in place, so the
     * subtree it belongs to - and every bracket around it - stays unchanged.
     *
     * A "+" that ends up in unary position (start, after a relation, "(", ","
     * or "[") is dropped from both alternatives - for ± that is the first, for
     * ∓ the second one (#49); it carries no meaning and must never read like
     * "+x = ...". A binary "+" is never touched.
     *
     * Both alternatives are wrapped in brackets so that "nounor" never binds
     * into a relation: (x=2) nounor (x=-2).
     *
     * @param {string} s Maxima string possibly containing ± or ∓.
     * @returns {string} Expanded string or unmodified input.
     */
    function expandPlusMinus(s) {
        var v1;
        var v2;

        if (s.indexOf('\u00b1') === -1
                && s.indexOf('\u2213') === -1) {
            return s;
        }
        v1 = s.replace(/\u00b1/g, '+').replace(/\u2213/g, '-');
        v2 = s.replace(/\u00b1/g, '-').replace(/\u2213/g, '+');

        // Symmetric (#49): the positive alternative is v1 for ± but v2 for ∓.
        v1 = stripUnaryPlus(v1);
        v2 = stripUnaryPlus(v2);

        return '(' + v1.trim() + ') ' + OperatorMap.SOLUTION_JOIN + ' (' + v2.trim() + ')';
    }

    /**
     * Operator names MathQuill recognises while typing (#58, #60).
     *
     * MathQuill scans every contiguous run of letters and un-italicises any operator name it
     * finds inside it, at any position. Typing "Umax" therefore produces the LaTeX "U\\max ",
     * and "maximum" produces "\\max imum". The user typed one identifier; MathQuill split it.
     *
     * The list mirrors MathQuill's own defaults (BuiltInOpNames plus AutoOpNames). It has to be
     * kept in sync when the MathQuill configuration changes, which is why it is built the same
     * way MathQuill builds it instead of being written out by hand.
     *
     * @returns {Object} Word set of operator names.
     */
    function buildAutoOperatorNames() {
        var names = (
            'arg deg det dim exp gcd hom inf ker lg lim ln log max min sup ' +
            'limsup liminf injlim projlim Pr gcf hcf lcm proj span'
        ).split(' ');
        var trigs = 'sin cos tan sec cosec csc cotan cot ctg'.split(' ');
        var i;

        for (i = 0; i < trigs.length; i++) {
            names.push(trigs[i]);
            names.push('arc' + trigs[i]);
            names.push(trigs[i] + 'h');
            names.push('ar' + trigs[i] + 'h');
            names.push('arc' + trigs[i] + 'h');
        }

        return buildWordSet(names);
    }

    var AUTO_OPERATOR_NAMES = buildAutoOperatorNames();

    /**
     * Re-join operator names that MathQuill split out of a longer identifier (#58, #60).
     *
     * The editor cannot tell "Umax" (one variable) from "U max" (a product) once MathQuill has
     * turned the input into "U\\max ". The deciding question is whether the operator name is
     * applied to anything: a name followed by an opening delimiter is a function call and stays
     * one, everything else is part of the identifier the user typed.
     *
     *     U\\max              -> Umax          (one identifier, #58)
     *     \\max imum          -> maximum       (one identifier)
     *     a\\sin\\left(x\\right) -> unchanged    (a times sin of x)
     *     \\max\\left(a,b\\right) -> unchanged    (the function max)
     *
     * This runs before markControlWords(), so a re-joined name never gets a token boundary and
     * can no longer be separated by resolveBoundaries() or split by the variable-mode logic.
     *
     * @param {string} s LaTeX input.
     * @returns {string} Input with glued operator names merged into their identifier.
     */
    function mergeGluedOperatorNames(s) {
        // \operatorname{word} (MathQuill's form for names LaTeX does not know) and \word.
        var pattern = /\\operatorname\{([A-Za-z]+)\}|\\([A-Za-z]+)( ?)/g;

        return s.replace(pattern, function(match, braced, bare, space, offset) {
            var name = braced || bare;
            var before = offset > 0 ? s.charAt(offset - 1) : '';
            var after = s.substring(offset + match.length);

            if (!AUTO_OPERATOR_NAMES[name]) {
                return match;
            }

            // Applied to an argument: a function call, not part of a name.
            if (/^(\(|\\left|\{|\^|_)/.test(after)) {
                // Belgian cyclometric names bgsin/bgcos/bgtan: MathQuill splits them into bg\sin.
                if (/^(sin|cos|tan)$/.test(name) && /(^|[^A-Za-z])bg$/.test(s.substring(0, offset))) {
                    return name;
                }
                return match;
            }

            // Letters follow directly: the user typed one longer word.
            if (/^[A-Za-z]/.test(after)) {
                return name;
            }

            // The name fills a subscript or superscript group on its own: typing "U_max" gives
            // U_{\max}, which is a label, not a function applied to something (#61).
            if (before === '{' && /^\}/.test(after)) {
                return name;
            }

            // An identifier character precedes: the name is its tail. The \operatorname form
            // has no trailing space group, hence the fallback.
            if (/[A-Za-z0-9]/.test(before)) {
                return name + (space || '');
            }

            return match;
        });
    }

    /**
     * Put a token boundary in front of every LaTeX control word.
     *
     * Only control words (backslash + letters) are marked; the LaTeX row break
     * "\\" and control symbols such as "\{" or "\," are left alone.
     *
     * @param {string} s LaTeX input.
     * @returns {string} Input with BOUNDARY before each control word.
     */
    function markControlWords(s) {
        var out = '';
        var i;
        var ch;

        for (i = 0; i < s.length; i++) {
            ch = s.charAt(i);
            if (ch === '\\' && s.charAt(i + 1) === '\\') {
                out += '\\\\';
                i++;
                continue;
            }
            if (ch === '\\' && /[a-zA-Z]/.test(s.charAt(i + 1)) && i > 0) {
                out += BOUNDARY;
            }
            out += ch;
        }
        return out;
    }

    /**
     * Resolve the remaining token boundaries.
     *
     * A boundary that separates an identifier from a following word becomes a
     * space (so "a sqrt(b)" never fuses into "asqrt(b)"); every other boundary
     * disappears without trace, which keeps e.g. "2sqrt(x)" and "(a)sqrt(b)"
     * exactly as they were.
     *
     * @param {string} s Converted string.
     * @returns {string} String without boundary markers.
     */
    function resolveBoundaries(s) {
        var out = '';
        var i;
        var j;
        var next;
        var inIdentifier;

        for (i = 0; i < s.length; i++) {
            if (s.charAt(i) !== BOUNDARY) {
                out += s.charAt(i);
                continue;
            }
            next = s.charAt(i + 1);
            // Walk back over the preceding alphanumeric run; it is an
            // identifier (not a number) when it starts with a letter.
            j = out.length - 1;
            while (j >= 0 && /[a-zA-Z0-9_]/.test(out.charAt(j))) {
                j--;
            }
            inIdentifier = j < out.length - 1 && /[a-zA-Z_]/.test(out.charAt(j + 1));
            if (inIdentifier && /[a-zA-Z%]/.test(next)) {
                out += ' ';
            }
        }
        return out;
    }

    /**
     * Convert a MathQuill LaTeX string to Maxima CAS notation.
     *
     * @param {string} latex   LaTeX string from MathQuill.
     * @param {Object} options Conversion options.
     * @param {boolean} [options.commaDecimal] Treat commas as decimal separators.
     * @param {Object}  [options.defs]          Server-side definitions.
     * @param {string}  [options.variableMode]  Variable interpretation mode.
     * @returns {string} Maxima expression string.
     */
    function convert(latex, options) {
        return analyse(latex, options).maxima;
    }

    /**
     * Convert and report structures that cannot be serialised yet (#44).
     *
     * An integral without a (simple) integration variable is a visible but incomplete editor
     * state, not a CAS expression: no "integrate(expr)" is ever invented. In that case maxima is
     * the empty string and problems names what is missing, so the editor can say so.
     *
     * @param {string} latex   LaTeX string from MathQuill.
     * @param {Object} options Conversion options (see convert()).
     * @returns {Object} {maxima: string, problems: string[]}.
     */
    function analyse(latex, options) {
        var ctx = {problems: []};
        var maxima = convertInner(latex || '', options || {}, ctx, false);
        return {maxima: ctx.problems.length ? '' : maxima, problems: ctx.problems};
    }

    /**
     * Read one LaTeX argument starting at pos: {…} group, control word, or single character.
     *
     * @param {string} s LaTeX.
     * @param {number} pos Start index.
     * @returns {?Object} {text, end} or null.
     */
    function readLatexArgument(s, pos) {
        var depth = 0;
        var i;
        var m;

        if (s.charAt(pos) === '{') {
            for (i = pos; i < s.length; i++) {
                if (s.charAt(i) === '\\') {
                    i++;
                    continue;
                }
                if (s.charAt(i) === '{') {
                    depth++;
                } else if (s.charAt(i) === '}') {
                    depth--;
                    if (depth === 0) {
                        return {text: s.substring(pos + 1, i), end: i + 1};
                    }
                }
            }
            return null;
        }
        m = s.substring(pos).match(/^\\[a-zA-Z]+|^[^\s{}]/);
        return m ? {text: m[0], end: pos + m[0].length} : null;
    }

    /**
     * Match the differential "\mathrm{d}x" / "dx" at pos.
     *
     * @param {string} s LaTeX.
     * @param {number} pos Index.
     * @param {boolean} allowBare Also accept a bare "d".
     * @returns {?Object} {variable, atomic, end} or null.
     */
    function matchDifferential(s, pos, allowBare) {
        var rest = s.substring(pos);
        var m = rest.match(/^(?:\\[,;:!]|\s)*(\\mathrm\{d\}|\\text\{d\}|d)\s*/);
        var after;
        var v;

        if (!m || (m[1] === 'd' && !allowBare)) {
            return null;
        }
        after = rest.substring(m[0].length);
        v = after.match(/^(?:[A-Za-z]|\\[a-zA-Z]+)(?:_\{[^{}]*\}|_[A-Za-z0-9])?/);
        if (!v) {
            return m[1] === 'd' ? null : {variable: '', atomic: false, end: pos + m[0].length};
        }
        // A bracket right after the variable means d(f(x)) / d f(x): a composite quantity.
        return {
            variable: v[0],
            atomic: !/^\s*(?:\(|\\left)/.test(after.substring(v[0].length)),
            end: pos + m[0].length + v[0].length
        };
    }

    /**
     * Read the optional limits "_{a}^{b}" (either order) after \\int.
     *
     * @param {string} s LaTeX.
     * @param {number} pos Index right after "\\int".
     * @returns {Object} {lower, upper, end}; a missing limit is null.
     */
    function readIntegralLimits(s, pos) {
        var result = {lower: null, upper: null, end: pos};
        var arg;

        while (s.charAt(result.end) === '_' || s.charAt(result.end) === '^') {
            arg = readLatexArgument(s, result.end + 1);
            if (!arg) {
                break;
            }
            result[s.charAt(result.end) === '_' ? 'lower' : 'upper'] = arg.text.trim();
            result.end = arg.end;
        }
        return result;
    }

    /**
     * True when a bare differential "dx" starts at i (not the "d" inside a longer word).
     *
     * @param {string} s LaTeX.
     * @param {number} i Index.
     * @param {number} bodyStart Start of the integrand.
     * @returns {boolean} Whether a bare differential starts here.
     */
    function isBareDifferentialAt(s, i, bodyStart) {
        return s.charAt(i) === 'd' && i > bodyStart && !/[A-Za-z]/.test(s.charAt(i - 1))
            && !!matchDifferential(s, i, true);
    }

    /**
     * Find the differential that closes the integral whose integrand starts at bodyStart.
     *
     * Inner integrals consume their own differential. "\\mathrm{d}x" wins; otherwise the last
     * bare "dx" on the same level is taken.
     *
     * @param {string} s LaTeX.
     * @param {number} bodyStart Start of the integrand.
     * @returns {?Object} {differential, bodyEnd} or null.
     */
    function findIntegralEnd(s, bodyStart) {
        var depth = 0;
        var nested = 0;
        var lastBare = null;
        var found;
        var ch;
        var i;

        for (i = bodyStart; i < s.length; i++) {
            ch = s.charAt(i);
            if (ch === '\\' && /[{}]/.test(s.charAt(i + 1))) {
                i++;
            } else if ('({['.indexOf(ch) !== -1) {
                depth++;
            } else if (')}]'.indexOf(ch) !== -1) {
                depth--;
                if (depth < 0) {
                    break;
                }
            } else if (depth === 0 && /^\\int(?![a-zA-Z])/.test(s.substring(i))) {
                nested++;
            } else if (depth === 0 && /^(?:\\[,;:!]|\s)*\\(?:mathrm|text)\{d\}/.test(s.substring(i))) {
                found = matchDifferential(s, i, false);
                if (nested === 0) {
                    return found ? {differential: found, bodyEnd: i} : null;
                }
                // The differential of an inner integral: skip it completely.
                nested--;
                i = (found ? found.end : i + 1) - 1;
            } else if (depth === 0 && nested === 0 && isBareDifferentialAt(s, i, bodyStart)) {
                lastBare = i;
            }
        }
        return lastBare === null ? null : {differential: matchDifferential(s, lastBare, true), bodyEnd: lastBare};
    }

    /**
     * Classify an integral that cannot be serialised yet.
     *
     * @param {?Object} end Result of findIntegralEnd().
     * @param {Object} limits Result of readIntegralLimits().
     * @returns {?string} Problem code or null.
     */
    function integralProblem(end, limits) {
        if (!end || !end.differential.variable) {
            return 'integral_variable_missing';
        }
        if (!end.differential.atomic) {
            return 'integral_variable_composite';
        }
        if (!limits.lower !== !limits.upper) {
            return 'integral_limit_missing';
        }
        return null;
    }

    /**
     * Replace every \\int … d<var> by a placeholder for integrate(…) (#44).
     *
     * @param {string} s LaTeX.
     * @param {Object} opts Conversion options.
     * @param {Object} ctx Context {problems, placeholders}.
     * @returns {string} LaTeX with placeholders.
     */
    function extractIntegrals(s, opts, ctx) {
        var start = s.search(/\\int(?![a-zA-Z])/);
        var limits;
        var end;
        var problem;
        var body;
        var parts;
        var index;

        if (start === -1) {
            return s;
        }
        limits = readIntegralLimits(s, start + 4);
        end = findIntegralEnd(s, limits.end);
        problem = integralProblem(end, limits);
        body = end ? s.substring(limits.end, end.bodyEnd).trim() : '';
        if (!problem && !body) {
            problem = 'integral_integrand_missing';
        }
        if (problem) {
            ctx.problems.push(problem);
            return s.substring(0, start) + ' ' + s.substring(start + 4);
        }

        parts = [
            unwrapArgument(convertInner(body, opts, ctx, true)),
            convertInner(end.differential.variable, opts, ctx, true)
        ];
        if (limits.lower) {
            parts.push(unwrapArgument(convertInner(limits.lower, opts, ctx, true)));
            parts.push(unwrapArgument(convertInner(limits.upper, opts, ctx, true)));
        }
        index = ctx.placeholders.length;
        ctx.placeholders.push('integrate(' + parts.join(',') + ')');
        return extractIntegrals(
            s.substring(0, start) + '\uE050' + index + '\uE051' + s.substring(end.differential.end),
            opts,
            ctx
        );
    }

    /**
     * Parse the numerator of a Leibniz derivative operator: \partial, d or \mathrm{d}, with an
     * optional total order (\partial^{3}).
     *
     * @param {string} tex Numerator LaTeX.
     * @returns {?Object} {order: ?number} or null when this is no derivative operator.
     */
    function parseDerivativeNumerator(tex) {
        var m = tex.trim().match(/^(?:\\partial|\\mathrm\{d\}|d)\s*(?:\^\s*(?:\{\s*(\d+)\s*\}|(\d)))?$/);
        if (!m) {
            return null;
        }
        return {order: m[1] || m[2] ? Number(m[1] || m[2]) : null};
    }

    /**
     * Parse the denominator: one or more "\partial x^{n}" (or "dx^{n}") factors.
     *
     * @param {string} tex Denominator LaTeX.
     * @returns {?Object} {pairs: [{variable, order}], atomic: boolean} or null.
     */
    function parseDerivativeDenominator(tex) {
        var re = new RegExp(
            '(?:\\\\partial|\\\\mathrm\\{d\\}|d)\\s*'
            + '((?:[A-Za-z]|\\\\[a-zA-Z]+)(?:_\\{[^{}]*\\}|_[A-Za-z0-9])?)'
            + '\\s*(?:\\^\\s*(?:\\{\\s*(\\d+)\\s*\\}|(\\d)))?\\s*',
            'g'
        );
        var rest = tex.trim();
        var pairs = [];
        var consumed = 0;
        var m;

        while ((m = re.exec(rest)) !== null && m.index === consumed) {
            pairs.push({variable: m[1], order: m[2] || m[3] ? Number(m[2] || m[3]) : 1});
            consumed = re.lastIndex;
        }
        if (!pairs.length) {
            return null;
        }
        return {pairs: pairs, atomic: consumed === rest.length};
    }

    /**
     * Read the obligatory bracketed operand "\left( … \right)" or "( … )" at pos.
     *
     * @param {string} s LaTeX.
     * @param {number} pos Index after the operator.
     * @returns {?Object} {text, end} or null when no bracket follows.
     */
    function readDerivativeOperand(s, pos) {
        var m = s.substring(pos).match(/^\s*(\\left\s*)?\(/);
        var open;
        var close;

        if (!m) {
            return null;
        }
        open = pos + m[0].length - 1;
        close = matchingBracket(s, open);
        if (close === -1) {
            return null;
        }
        return {
            text: s.substring(open + 1, close).replace(/\\right\s*$/, '').trim(),
            end: close + 1
        };
    }

    /**
     * Replace every Leibniz derivative operator with operand by a placeholder for diff(…) (#46).
     *
     * ∂/∂x (E) → diff(E,x); ∂ⁿ/∂xⁿ (E) → diff(E,x,n); ∂ᴺ/(∂xⁿ ∂yᵐ) (E) → diff(E,x,n,y,m).
     * The operand must be bracketed (no implicit scope rule); a numerator order must equal the
     * sum of the denominator orders.
     *
     * @param {string} s LaTeX.
     * @param {Object} opts Conversion options.
     * @param {Object} ctx Context {problems, placeholders}.
     * @param {number} [from] Index to continue searching from.
     * @returns {string} LaTeX with placeholders.
     */
    function extractDerivatives(s, opts, ctx, from) {
        var start = s.indexOf('\\frac', from || 0);
        var num;
        var den;
        var numerator;
        var denominator;
        var operand;
        var total;
        var args;
        var index;

        if (start === -1) {
            return s;
        }
        num = readLatexArgument(s, start + 5);
        den = num ? readLatexArgument(s, num.end) : null;
        numerator = num ? parseDerivativeNumerator(num.text) : null;
        denominator = numerator && den ? parseDerivativeDenominator(den.text) : null;
        if (!denominator) {
            return extractDerivatives(s, opts, ctx, start + 5);
        }
        operand = readDerivativeOperand(s, den.end);
        total = denominator.pairs.reduce(function(sum, pair) {
            return sum + pair.order;
        }, 0);
        if (!denominator.atomic) {
            ctx.problems.push('derivative_variable_composite');
        } else if (!operand || !operand.text) {
            ctx.problems.push('derivative_operand_missing');
        } else if (numerator.order !== null && numerator.order !== total) {
            ctx.problems.push('derivative_order_mismatch');
        } else {
            args = [unwrapArgument(convertInner(operand.text, opts, ctx, true))];
            denominator.pairs.forEach(function(pair) {
                args.push(convertInner(pair.variable, opts, ctx, true));
                if (denominator.pairs.length > 1 || pair.order > 1) {
                    args.push(String(pair.order));
                }
            });
            index = ctx.placeholders.length;
            ctx.placeholders.push('diff(' + args.join(',') + ')');
            return extractDerivatives(
                s.substring(0, start) + '\uE050' + index + '\uE051' + s.substring(operand.end),
                opts,
                ctx,
                start
            );
        }
        return extractDerivatives(s, opts, ctx, den.end);
    }

    /**
     * Remove brackets that enclose a whole function argument.
     *
     * @param {string} x Maxima expression.
     * @returns {string} Argument.
     */
    function unwrapArgument(x) {
        x = x.trim();
        while (x.charAt(0) === '(' && matchingBracket(x, 0) === x.length - 1) {
            x = x.substring(1, x.length - 1).trim();
        }
        return x;
    }

    /**
     * The conversion pipeline.
     *
     * @param {string} latex LaTeX input.
     * @param {Object} opts Conversion options.
     * @param {Object} ctx Context {problems, placeholders}.
     * @param {boolean} fragment True for a part of a structure (no ± expansion).
     * @returns {string} Maxima.
     */
    function convertInner(latex, opts, ctx, fragment) {
        var commaDecimal = opts.commaDecimal || false;
        var defs = opts.defs || {};
        var variableMode = opts.variableMode || 'stack';
        var s = latex;
        var maxIter = 20;
        var placeholders = [];
        var local = {problems: ctx.problems, placeholders: placeholders};

        s = s.replace(/\s+/g, ' ').trim();
        s = extractIntegrals(s, opts, local);
        s = extractDerivatives(s, opts, local);
        // A space after a control word only ends the command's name (LaTeX ignores it). In front
        // of anything but a letter or digit it carries nothing and would otherwise survive in
        // stack mode ("gamma (x)", "epsilon _0").
        s = s.replace(/(\\[a-zA-Z]+)\s+(?=[^A-Za-z0-9\s])/g, '$1');
        s = convertCasesToAndRelations(s);
        s = mergeGluedOperatorNames(s);
        s = markControlWords(s);
        // Set braces survive the generic brace removal below (#39: no
        // backslash may reach the CAS string).
        s = s.replace(/\\left\s*\\\{/g, '\uE001').replace(/\\right\s*\\\}/g, '\uE002');
        s = s.replace(/\\\{/g, '\uE001').replace(/\\\}/g, '\uE002');
        s = s.replace(/\\left/g, '');
        s = s.replace(/\\right/g, '');

        s = s.replace(
            /\\sqrt\[([^\]]+)\]\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/g,
            '($2)^(1/($1))'
        );
        s = s.replace(
            /\\nthroot\{([^{}]*)\}\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/g,
            '($2)^(1/($1))'
        );
        s = s.replace(
            /\\binom\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/g,
            'binomial($1,$2)'
        );

        while (s.indexOf('\\frac') !== -1 && maxIter > 0) {
            maxIter--;
            s = s.replace(
                /\\frac\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/,
                '($1)/($2)'
            );
        }

        // Mixed-fraction guard: N(p)/(q) → (N+p/q).
        // Prevents N*(p/q) implicit multiplication; supports multi-digit integers.
        s = s.replace(new RegExp('(\\d+)' + BOUNDARY + '?\\((\\d+)\\)\\/\\((\\d+)\\)', 'g'), '($1+$2/$3)');

        s = s.replace(
            /\\sqrt\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/g,
            'sqrt($1)'
        );

        s = s.replace(/\\vec\{([^{}]*)\}/g, '$1');
        s = s.replace(/\\overline\{([^{}]*)\}/g, '$1');
        s = s.replace(/\\mathbb\{([^{}]*)\}/g, '$1');
        s = s.replace(/\\mathrm\{e\}/g, '%e');
        s = s.replace(/\\mathrm\{i\}/g, '%i');
        s = s.replace(/\\mathrm\{([^{}]*)\}/g, '$1');
        s = s.replace(/\\text\{([^{}]*)\}/g, '$1');
        s = s.replace(/\\operatorname\{([^{}]*)\}/g, '$1');
        s = s.replace(/\^\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/g, '^($1)');
        // A subscript group that is followed directly by more characters is not part of the
        // subscript (#59): U_{m}ax is U_m followed by ax, and must never collapse into U_max,
        // which is what U_{max} means. The boundary marker keeps the two apart for the
        // variable-mode logic and for resolveBoundaries().
        s = s.replace(/_\{([^{}]*)\}(?=[A-Za-z0-9])/g, '_$1' + BOUNDARY);
        s = s.replace(/_\{([^{}]*)\}/g, '_$1');

        var funcs = [
            'sin', 'cos', 'tan', 'cot', 'sec', 'csc',
            'arcsin', 'arccos', 'arctan',
            'sinh', 'cosh', 'tanh'
        ];
        funcs.forEach(function(fn) {
            s = s.replace(
                new RegExp('\\\\' + fn + '(?![a-zA-Z])', 'g'),
                fn
            );
        });

        s = s.replace(/\\ln(?![a-zA-Z])/g, 'log');
        s = s.replace(/\\log(?![a-zA-Z])/g, 'log');
        s = s.replace(/\\exp(?![a-zA-Z])/g, 'exp');
        // Pi notation is configurable: plain "pi" (default) or Maxima "%pi".
        s = s.replace(/\\pi(?![a-zA-Z])/g, defs.usePercentPi ? '%pi' : 'pi');
        s = s.replace(/\\infty/g, 'inf');
        s = s.replace(/\\e(?![a-zA-Z])/g, '%e');
        s = s.replace(/\\cdot/g, '*');
        s = s.replace(/\\times/g, '*');
        s = s.replace(/\\div/g, '/');
        s = s.replace(/\\%/g, '%');
        s = s.replace(/\\&/g, '&');
        s = s.replace(/\\leq?(?![a-zA-Z])/g, '<=');
        s = s.replace(/\\geq?(?![a-zA-Z])/g, '>=');
        // Lookahead: without it \\neg (¬) became "#g".
        s = s.replace(/\\neq?(?![a-zA-Z])/g, '#');
        s = s.replace(/\\ne(?![a-zA-Z])/g, '#');
        s = s.replace(/\\approx(?![a-zA-Z])/g, '~=');
        s = s.replace(/\\pm(?![a-zA-Z])\s?/g, '\u00b1');
        s = s.replace(/\\mp(?![a-zA-Z])\s?/g, '\u2213');
        s = s.replace(/\\oint(?![a-zA-Z])/g, 'oint');
        s = s.replace(/\\int(?![a-zA-Z])/g, 'int');
        s = s.replace(/\\sum(?![a-zA-Z])/g, 'sum');
        s = s.replace(/\\prod(?![a-zA-Z])/g, 'product');
        s = s.replace(/\\\|/g, '|');
        s = s.replace(/\|([^|]+)\|/g, 'abs($1)');

        // Greek letters (#22) use STACK's own convention: the letter's name. STACK accepts every
        // name as a student variable and typesets it as the Greek glyph. The variant glyphs have
        // no STACK identity of their own and map to their letter (\varphi -> phi), so they never
        // reach STACK as an unknown word that single-letter mode would split into v*a*r*p*h*i.
        s = s.replace(/\\var(epsilon|theta|phi)(?![a-zA-Z])/g, '$1');
        var greek = [
            'alpha', 'beta', 'gamma', 'delta',
            'epsilon', 'zeta', 'eta', 'theta',
            'iota', 'kappa', 'lambda', 'mu',
            'nu', 'xi', 'rho', 'sigma',
            'tau', 'upsilon', 'phi', 'chi',
            'psi', 'omega'
        ];
        greek.forEach(function(letter) {
            s = s.replace(
                new RegExp('\\\\' + letter + '(?![a-zA-Z])', 'g'),
                letter
            );
        });

        var greekUpper = [
            'Gamma', 'Delta', 'Theta', 'Lambda',
            'Xi', 'Pi', 'Sigma', 'Upsilon',
            'Phi', 'Psi', 'Omega'
        ];
        greekUpper.forEach(function(letter) {
            s = s.replace(
                new RegExp('\\\\' + letter + '(?![a-zA-Z])', 'g'),
                letter
            );
        });

        // Set-theory and logic operators from the central table (#35). Operators
        // that need their operands become marker characters here and function
        // calls in convertStructuredOperators(); the others are emitted directly.
        s = replaceTableOperators(s);

        // Quantifiers (nexists before exists to avoid partial match).
        s = s.replace(/\\nexists/g, ' nexists ');
        s = s.replace(new RegExp('\\\\not' + BOUNDARY + '?\\\\exists', 'g'), ' nexists ');
        s = s.replace(/\\forall(?![a-zA-Z])/g, ' forall ');
        s = s.replace(/\\exists(?![a-zA-Z])/g, ' exists ');
        s = s.replace(/\\angle(?![a-zA-Z])/g, 'angle');
        s = s.replace(/\\perp(?![a-zA-Z])/g, 'perp');
        s = s.replace(/\\circ(?![a-zA-Z])/g, 'circ');
        s = s.replace(/\\nabla(?![a-zA-Z])/g, 'nabla');
        s = s.replace(/\\partial(?![a-zA-Z])/g, 'del');
        s = s.replace(/\\hbar(?![a-zA-Z])/g, 'hbar');
        s = s.replace(/\\dagger(?![a-zA-Z])/g, 'dagger');
        s = s.replace(/\\intercal(?![a-zA-Z])/g, 'T');
        s = s.replace(/\\ /g, '');
        // Spacing commands carry no mathematical meaning.
        s = s.replace(/\\[,;:!]/g, '');
        // Any control word still left is unknown to this converter. Keep its
        // name as a plain word so STACK reports an unknown identifier instead
        // of rejecting the backslash (#39).
        s = s.replace(/\\([a-zA-Z]+)/g, '$1');
        s = s.replace(/[{}]/g, '');
        s = s.replace(/\uE001/g, '{').replace(/\uE002/g, '}');

        if (commaDecimal) {
            s = replaceDecimalCommas(s);
        }

        s = insertImplicitMultiplication(s, {
            defs: defs,
            variableMode: variableMode
        });

        s = resolveBoundaries(s);
        // "lambda(" is Maxima's anonymous-function constructor. A Greek lambda written in front of
        // a bracket is always a product (#22); in stack mode, where no implicit multiplication is
        // inserted, make that explicit.
        s = s.replace(/(^|[^A-Za-z0-9_%])lambda\s*(?=\()/g, '$1lambda*');
        s = s.replace(/\s+/g, ' ').trim();
        // A space next to a bracket or comma never separates two factors
        // ("sqrt(pi )" from "\sqrt{\pi }"); drop it so the output is stable.
        s = s.replace(/\s+([)\],}])/g, '$1').replace(/([([{,])\s+/g, '$1');
        s = convertStructuredOperators(s);
        s = s.replace(/(^|[\s\S])\uE050(\d+)\uE051/g, function(match, before, index) {
            // Never fuse with a preceding identifier ("xintegrate(...)" in stack mode).
            return before + (/[A-Za-z_]/.test(before) ? ' ' : '') + placeholders[Number(index)];
        });
        if (!fragment) {
            s = expandPlusMinus(s);
        }
        return s;
    }

    return /** @alias module:local_stackmatheditor/tex2max */ {
        convert: convert,
        analyse: analyse
    };
});
