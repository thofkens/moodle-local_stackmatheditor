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
 * Injects configure links for STACK questions on quiz pages.
 *
 * Data is read from a JSON script element (#sme-configure-data)
 * to avoid the 1024-char limit of js_call_amd().
 *
 * On mod-quiz-edit, also inserts a quiz-level configure option
 * into the quiz navigation <select>.
 * The STACK check already happened in PHP — if quizConfigureUrl is
 * present in the data object, STACK questions exist in this quiz.
 *
 * @module     local_stackmatheditor/configure_links
 * @package
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
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
            window.console.log('[SME-links] ' + msg);
        }
    }

    /**
     * Read the JSON configuration element injected by the server.
     *
     * @returns {Object|null} Parsed configuration object or null.
     */
    function loadData() {
        var el = document.getElementById('sme-configure-data');
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            dbg('parse error: ' + e.message);
            return null;
        }
    }

    /**
     * Build the configure URL for a specific question or quiz.
     *
     * @param {string} base   Base configure URL.
     * @param {number} cmid   Course module ID.
     * @param {number} qbeid  Question bank entry ID.
     * @param {number} qid    Question ID.
     * @param {string} ret    Return URL.
     * @returns {string} Complete URL string.
     */
    function buildUrl(base, cmid, qbeid, qid, ret) {
        var url = base + '?cmid=' + encodeURIComponent(cmid);
        if (qbeid) {
            url += '&qbeid=' + encodeURIComponent(qbeid);
        }
        if (qid) {
            url += '&questionid=' + encodeURIComponent(qid);
        }
        if (ret) {
            url += '&returnurl=' + encodeURIComponent(ret);
        }
        return url;
    }

    /**
     * Create a plain text anchor element.
     *
     * @param {string} href  Link target URL.
     * @param {string} text  Link text.
     * @returns {jQuery} Anchor element.
     */
    function createTextLink(href, text) {
        return $('<a>')
            .attr('href', href)
            .attr('title', text)
            .addClass('sme-configure-link')
            .append($('<i>').addClass('fa fa-calculator').attr('aria-hidden', 'true'))
            .append(' ' + text);
    }

    /**
     * Create an icon-only anchor element with a settings gear icon.
     *
     * @param {string} href   Link target URL.
     * @param {string} title  Tooltip/aria-label text.
     * @returns {jQuery} Anchor element.
     */
    function createIconLink(href, title) {
        return $('<a>')
            .attr('href', href)
            .attr('title', title)
            // Spacing for Bootstrap 4 (ml/mr, Moodle 4.5) and 5 (ms/me, Moodle 5.x); the colour is
            // the theme's link colour, so it follows the dark colour mode.
            .addClass('sme-configure-edit-link ml-1 mr-1 ms-1 me-1')
            .css({'font-size': '1em', 'text-decoration': 'none'})
            .append($('<i>').addClass('fa fa-calculator fa-fw').attr('aria-hidden', 'true'));
    }

    // ── Attempt page ─────────────────────────────────────────────────────────

    /**
     * Find the question container element for a given slot number.
     *
     * @param {number} slot Slot number.
     * @returns {jQuery} Container element or empty jQuery object.
     */
    function findContainer(slot) {
        var $c = $('[id$="-' + slot + '"].que');
        if ($c.length) {
            return $c.first();
        }
        var re = new RegExp('^question-\\d+-' + slot + '$');
        var $m = $('[id^="question-"]').filter(function() {
            return re.test(this.id);
        });
        return $m.length ? $m.first() : $();
    }

    /**
     * Inject configure links into all STACK question slots on attempt/review pages.
     *
     * @param {Object} data Configuration data from the server.
     * @returns {void}
     */
    function injectAttemptLinks(data) {
        var slots = data.slots || {};
        var slot;
        var count = 0;
        for (slot in slots) {
            if (!slots.hasOwnProperty(slot)) {
                continue;
            }
            if (doAttemptSlot(data, slot, slots[slot])) {
                count++;
            }
        }
        dbg('attempt: ' + count + ' injected');
    }

    /**
     * Inject a configure link for a single attempt slot.
     *
     * @param {Object} data Configuration data from the server.
     * @param {string} slot Slot number as string key.
     * @param {Object} sd   Slot-specific data (url, qbeid, qid, name).
     * @returns {void}
     */
    function doAttemptSlot(data, slot, sd) {
        var url = buildUrl(data.configureUrl, data.cmid,
            sd.qbeid, sd.questionid, data.returnUrl);
        var $w = $('<div>').addClass('sme-configure-wrapper mt-1')
            .append(createTextLink(url, data.linkText));

        var $c = findContainer(slot);
        if (!$c.length) {
            dbg('slot ' + slot + ': not found');
            return false;
        }
        var $info = $c.find('.info');
        if ($info.length) {
            var $edit = $info.find('a[href*="editquestion"]');
            if ($edit.length) {
                $edit.first().closest('div, span, p').after($w);
                return true;
            }
            $info.append($w);
            return true;
        }
        var $f = $c.find('.formulation');
        if ($f.length) {
            $f.before($w);
            return true;
        }
        $c.prepend($w);
        return true;
    }

    // ── Edit page ─────────────────────────────────────────────────────────────

    /**
     * Inject a "Set up STACK MathQuill Editor" option into the quiz edit nav selector.
     *
     * Moodle's YUI urlselect module redirects to window.location.href = value,
     * so a full URL as option value works. We also bind our own handler with
     * stopImmediatePropagation to ensure reliable navigation regardless of
     * which Moodle version's handler fires first.
     *
     * @param {Object} data Configuration data from the server.
     * @returns {void}
     */
    function injectQuizNavOption(data) {
        if (!data.quizConfigureUrl || !data.quizLinkText) {
            dbg('quiz nav: no quizConfigureUrl, skipping');
            return;
        }

        // Stable selector — the YUI-generated id is not reliable.
        var $select = $('form[action*="jumpto.php"] select[name="jump"]');
        if (!$select.length) {
            dbg('quiz nav select not found');
            return;
        }

        // Avoid double-injection.
        if ($select.find('option[data-sme="quiz"]').length) {
            dbg('quiz nav option already present');
            return;
        }

        var fullUrl = data.quizConfigureUrl;

        var $option = $('<option>')
            .val(fullUrl)
            .attr('data-sme', 'quiz')
            .text(data.quizLinkText);

        $select.append($option);

        // Intercept change on the select. We use stopImmediatePropagation so
        // Moodle's YUI handler cannot interfere when our option is chosen.
        // For other options we do nothing and let the existing handler work.
        $select.on('change.sme', function(e) {
            var $sel = $(this).find('option:selected');
            if ($sel.attr('data-sme') === 'quiz') {
                e.stopImmediatePropagation();
                window.location.href = fullUrl;
            }
        });

        dbg('quiz nav option injected: ' + fullUrl);
    }

    /**
     * Inject configure icon links next to each STACK question on the quiz edit page.
     *
     * @param {Object} data Configuration data from the server.
     * @returns {void}
     */
    function injectEditLinks(data) {
        // 1. Per-question icon links.
        var questions = data.questions || [];
        var count = 0;
        var i;
        for (i = 0; i < questions.length; i++) {
            if (doEditQuestion(data, questions[i])) {
                count++;
            }
        }
        dbg('edit: ' + count + '/' + questions.length + ' question links injected');

        // 2. Quiz-level option in navigation selector.
        injectQuizNavOption(data);
    }

    /**
     * Executes the question edit configurator links.
     *
     * @param {Object} data Configuration data from the server.
     * @param {Object} q Question data from the server.
     * @returns {bool}
     */
    function doEditQuestion(data, q) {
        var url = buildUrl(data.configureUrl, data.cmid,
            q.qbeid, q.questionid, data.returnUrl);
        var $icon = createIconLink(url, data.linkText);

        var $edit = $('a[href*="editquestion"][href*="id=' + q.questionid + '"]');
        if (!$edit.length) {
            $edit = $('a[href*="editquestion"][href*="id=' + q.qbeid + '"]');
        }

        if ($edit.length) {
            var $row = $edit.first().closest(
                '.activity-wrapper, .activity, .slot, li, [data-for="cmitem"]');
            if ($row.length) {
                var $actions = $row.find('.activity-actions, .actions, .ml-auto');
                if ($actions.length) {
                    $actions.first().prepend($icon.clone(true));
                    return true;
                }
            }
            $edit.first().after($icon.clone(true));
            return true;
        }

        // Fallback: match by question name text.
        var name = q.name;
        var $nameLink = $('a').filter(function() {
            return $(this).text().trim() === name;
        });
        if ($nameLink.length) {
            $nameLink.first().after($icon.clone(true));
            return true;
        }

        dbg('edit q=' + q.questionid + ': DOM element not found');
        return false;
    }

    // ── Run ───────────────────────────────────────────────────────────────────

    /**
     * Route to the correct injection function based on page mode.
     *
     * @param {Object} data Configuration data from the server.
     * @returns {void}
     */
    function run(data) {
        dbg('run: mode=' + data.mode);
        if (data.mode === 'attempt') {
            injectAttemptLinks(data);
        } else if (data.mode === 'edit') {
            injectEditLinks(data);
        }
    }

    return /** @alias module:local_stackmatheditor/configure_links */ {
        init: function() {
            dbg('init');
            $(document).ready(function() {
                var data = loadData();
                if (data) {
                    run(data);
                    return;
                }
                // Single retry for race condition with inline JS element creation.
                setTimeout(function() {
                    data = loadData();
                    if (data) {
                        run(data);
                    } else {
                        dbg('no data found');
                    }
                }, 50);
            });
        }
    };
});
