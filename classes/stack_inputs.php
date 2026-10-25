<?php
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

namespace local_stackmatheditor;

/**
 * Read-only view of STACK's input semantics.
 *
 * STACK stores "insert stars" per input in qtype_stack_inputs, so one question can have several
 * inputs with different settings. This class reads that value and nothing else: the editor never
 * writes it, and it keeps no second copy. Editing stays in STACK.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stack_inputs {
    /**
     * STACK's own language string per insertstars value.
     *
     * Mirrors stack_options::get_insert_star_options(). The strings are read from qtype_stack so
     * that the editor shows exactly the wording a teacher sees in the question, in their
     * language, instead of a parallel set of terms.
     */
    public const INSERT_STARS_STRINGS = [
        0 => 'insertstarsno',
        1 => 'insertstarsyes',
        2 => 'insertstarsassumesinglechar',
        3 => 'insertspaces',
        4 => 'insertstarsspaces',
        5 => 'insertstarsspacessinglechar',
        6 => 'insertspacesfunctions',
        7 => 'insertspacesfunctionssingle',
    ];

    /**
     * Return the insertstars value of every input of a STACK question.
     *
     * @param int $questionid Question id.
     * @return array Input name => insertstars value, ordered by input name.
     */
    public static function get_insertstars(int $questionid): array {
        global $DB;

        if ($questionid <= 0) {
            return [];
        }

        // STACK is a dependency, but the table may still be missing during an upgrade.
        if (!$DB->get_manager()->table_exists('qtype_stack_inputs')) {
            return [];
        }

        $records = $DB->get_records(
            'qtype_stack_inputs',
            ['questionid' => $questionid],
            'name ASC',
            'id, name, insertstars'
        );

        $values = [];
        foreach ($records as $record) {
            $values[$record->name] = (int) $record->insertstars;
        }

        return $values;
    }

    /**
     * Label for an insertstars value, in STACK's own words.
     *
     * @param int $value Value from qtype_stack_inputs.insertstars.
     * @return string Human readable label.
     */
    public static function get_insertstars_label(int $value): string {
        if (!isset(self::INSERT_STARS_STRINGS[$value])) {
            // A value this plugin does not know about: show it rather than claim a meaning.
            return get_string('insertstars_unknown', 'local_stackmatheditor', $value);
        }

        $identifier = self::INSERT_STARS_STRINGS[$value];

        if (get_string_manager()->string_exists($identifier, 'qtype_stack')) {
            return trim(get_string($identifier, 'qtype_stack'));
        }

        return get_string('insertstars_unknown', 'local_stackmatheditor', $value);
    }

    /**
     * URL for editing the STACK question, or null without the capability.
     *
     * The editor does not offer a way in when the user may not edit the question; it still shows
     * the value, because knowing the semantics is useful even without edit rights.
     *
     * @param int $questionid Question id.
     * @param int $courseid Course the editor was opened from.
     * @param string $returnurl Where the question editor should return to.
     * @return \moodle_url|null URL or null.
     */
    public static function get_edit_url(
        int $questionid,
        int $courseid,
        string $returnurl = ''
    ): ?\moodle_url {
        global $DB;

        if ($questionid <= 0) {
            return null;
        }

        // Ask before letting the question engine ask: a missing question makes
        // question_has_capability_on() emit a debugging message, which is noise here and an
        // error in a PHPUnit run.
        if (!$DB->record_exists('question', ['id' => $questionid])) {
            return null;
        }

        // The page that calls this happens to load the question library; this method does not
        // rely on it. A missing function inside the catch-all below would otherwise mean a link
        // that silently never appears.
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        try {
            if (!question_has_capability_on($questionid, 'edit')) {
                return null;
            }
        } catch (\moodle_exception $e) {
            // No category, no context, no answer: no link.
            quiz_helper::caught($e, 'stack_inputs edit link');
            return null;
        }

        $params = ['id' => $questionid, 'courseid' => $courseid];
        if ($returnurl !== '') {
            $params['returnurl'] = $returnurl;
        }

        return new \moodle_url('/question/bank/editquestion/question.php', $params);
    }

    /**
     * Everything the configuration page needs to show the STACK input semantics.
     *
     * @param int $questionid Question id.
     * @param int $courseid Course id.
     * @param string $returnurl Return URL for the question editor.
     * @return array Array with 'inputs' (name, value, label) and 'editurl' (moodle_url|null).
     */
    public static function get_semantics_summary(
        int $questionid,
        int $courseid,
        string $returnurl = ''
    ): array {
        $inputs = [];
        foreach (self::get_insertstars($questionid) as $name => $value) {
            $inputs[] = [
                'name'  => $name,
                'value' => $value,
                'label' => self::get_insertstars_label($value),
            ];
        }

        return [
            'inputs'  => $inputs,
            'editurl' => self::get_edit_url($questionid, $courseid, $returnurl),
        ];
    }
}
