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

namespace local_stackmatheditor\form;

use local_stackmatheditor\definitions;

/**
 * Form for configuring MathQuill toolbar per question or per quiz/activity.
 *
 * Operates in two modes depending on $customdata['mode']:
 *   'question' – configure a single STACK question (cmid + qbeid)
 *                (mod_quiz only)
 *   'quiz'     – configure activity-level defaults  (cmid only, qbeid IS NULL)
 *                (mod_quiz and mod_adaptivequiz)
 *
 * The 'modname' customdata value distinguishes the parent activity:
 *   'quiz'         – standard mod_quiz
 *   'adaptivequiz' – mod_adaptivequiz
 *
 * The 'instancemode' customdata value (0-3) controls the enabled section:
 *   0 – globally off, no override: shows locked-off badge (read-only)
 *   1 – globally on,  no override: shows locked-on  badge (read-only)
 *   2 – default off, override allowed: shows checkbox, default unchecked
 *   3 – default on,  override allowed: shows checkbox, default checked
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configure_form extends \moodleform {
    /**
     * Build the form elements.
     *
     * @return void
     */
    protected function definition(): void {
        $mform      = $this->_form;
        $customdata = $this->_customdata;

        $mode           = $customdata['mode'] ?? 'question';
        $modname        = $customdata['modname'] ?? 'quiz';
        $questionrecord = $customdata['questionrecord'] ?? null;
        // Backwards-compatible: callers may pass 'quiz' or 'activity'.
        $activity       = $customdata['activity'] ?? ($customdata['quiz'] ?? null);
        $grouplabels    = $customdata['grouplabels'];
        $previewhtml    = $customdata['previewhtml'] ?? '';
        $returnurl      = $customdata['returnurl'] ?? '';
        $instancemode   = (int) ($customdata['instancemode'] ?? 1);
        $isadaptivequiz = ($modname === 'adaptivequiz');

        // Info section.
        $mform->addElement(
            'header',
            'infosection',
            get_string('configure', 'local_stackmatheditor')
        );

        // Activity name row: use the correct module string for the label.
        $activitylabelstr = $isadaptivequiz
            ? get_string('modulename', 'adaptivequiz')
            : get_string('modulename', 'quiz');

        $mform->addElement(
            'static',
            'activityname',
            $activitylabelstr,
            format_string($activity->name)
        );

        if ($mode === 'question' && $questionrecord) {
            $mform->addElement(
                'static',
                'questionname',
                get_string('question'),
                format_string($questionrecord->name)
                    . ' <span class="text-muted">(v'
                    . $questionrecord->version . ')</span>'
            );

            if (!empty($previewhtml)) {
                $collapseid    = 'sme-question-preview';
                $previewbutton =
                    '<a class="btn btn-outline-secondary btn-sm"'
                    // Both attributes: Bootstrap 4 (Moodle 4.5) reads data-toggle, Bootstrap 5
                    // (Moodle 5.x) data-bs-toggle; its compatibility layer for the old name is
                    // deprecated.
                    . ' data-toggle="collapse" data-bs-toggle="collapse"'
                    . ' href="#' . $collapseid . '"'
                    . ' role="button" aria-expanded="false"'
                    . ' aria-controls="' . $collapseid . '">'
                    . get_string('questionpreview', 'local_stackmatheditor')
                    . '</a>';
                $previewcontent =
                    '<div class="collapse mt-2" id="' . $collapseid . '">'
                    . '<div class="card card-body">'
                    . $previewhtml
                    . '</div></div>';

                $mform->addElement(
                    'static',
                    'preview',
                    get_string('questionpreview', 'local_stackmatheditor'),
                    $previewbutton . $previewcontent
                );
            }
        } else {
            // Quiz/activity mode: display a scope note.
            $notemsgkey = $isadaptivequiz
                ? 'configure_adaptivequiz_note'
                : 'configure_quiz_note';

            $mform->addElement(
                'static',
                'activitymodenote',
                '',
                '<div class="alert alert-info mb-0">'
                    . get_string($notemsgkey, 'local_stackmatheditor')
                    . '</div>'
            );
        }

        // Enabled section – always visible.
        $mform->addElement(
            'header',
            'enabledsection',
            get_string('configure_enabled_header', 'local_stackmatheditor')
        );
        $mform->setExpanded('enabledsection', true);

        if ($instancemode === 0) {
            // Globally forced off – read-only info, no checkbox.
            $mform->addElement(
                'static',
                'enabled_info',
                '',
                '<span class="badge badge-secondary bg-secondary px-2 py-1">'
                    . '<i class="fa fa-lock mr-1 me-1" aria-hidden="true"></i>'
                    . get_string('configure_enabled_locked_off', 'local_stackmatheditor')
                    . '</span>'
            );
        } else if ($instancemode === 1) {
            // Globally forced on – read-only info, no checkbox.
            $mform->addElement(
                'static',
                'enabled_info',
                '',
                '<span class="badge badge-success bg-success px-2 py-1">'
                    . '<i class="fa fa-lock mr-1 me-1" aria-hidden="true"></i>'
                    . get_string('configure_enabled_locked_on', 'local_stackmatheditor')
                    . '</span>'
            );
        } else {
            // Modes 2 and 3: override allowed – show editable checkbox.
            if ($mode === 'quiz') {
                $checkboxlabelkey = $isadaptivequiz
                    ? 'configure_enabled_checkboxlabel_adaptivequiz'
                    : 'configure_enabled_checkboxlabel_quiz';
            } else {
                $checkboxlabelkey = 'configure_enabled_checkboxlabel_question';
            }

            $mform->addElement(
                'advcheckbox',
                'enabled',
                get_string('configure_enabled_label', 'local_stackmatheditor'),
                get_string($checkboxlabelkey, 'local_stackmatheditor'),
                ['id' => 'id_sme_enabled'],
                [0, 1]
            );
            $mform->setType('enabled', PARAM_INT);
            $mform->addHelpButton(
                'enabled',
                'configure_enabled_label',
                'local_stackmatheditor'
            );

            // Context hint: show what the parent default is.
            $parenthint = ($instancemode === 3)
                ? get_string('configure_enabled_parenthint_on', 'local_stackmatheditor')
                : get_string('configure_enabled_parenthint_off', 'local_stackmatheditor');
            $mform->addElement(
                'static',
                'enabled_hint',
                '',
                '<small class="text-muted">'
                    . '<i class="fa fa-info-circle mr-1 me-1" aria-hidden="true"></i>'
                    . $parenthint
                    . '</small>'
            );
        }

        // The student switch. Not only in the override modes: with the editor on everywhere
        // (mode 1) a quiz or a question must still be able to take the switch away - and in
        // mode 1 there is no activation checkbox for it to sit under. Mode 0 has no editor, so there is no switch to allow.
        if ($instancemode !== 0) {
            $mform->addElement(
                'advcheckbox',
                'allowstudenttoggle',
                get_string('configure_studenttoggle_label', 'local_stackmatheditor'),
                get_string('configure_studenttoggle_checkboxlabel', 'local_stackmatheditor'),
                ['id' => 'id_sme_allowstudenttoggle'],
                [0, 1]
            );
            $mform->setType('allowstudenttoggle', PARAM_INT);
            $mform->addHelpButton(
                'allowstudenttoggle',
                'configure_studenttoggle_label',
                'local_stackmatheditor'
            );
            // Only where an activation checkbox exists: a rule against a field that is not on the
            // form disables the switch for good, which is how mode 1 lost it.
            if ($instancemode === 2 || $instancemode === 3) {
                $mform->disabledIf('allowstudenttoggle', 'enabled', 'notchecked');
            }
        }

        // Toolbar groups section.
        $mform->addElement(
            'header',
            'toolbarsection',
            get_string('setting_defaultgroups', 'local_stackmatheditor')
        );
        $mform->setExpanded('toolbarsection', true);

        $select = $mform->addElement(
            'select',
            'groups',
            get_string('setting_defaultgroups', 'local_stackmatheditor'),
            $grouplabels
        );
        $select->setMultiple(true);
        $select->setSize(count($grouplabels));
        $mform->addHelpButton(
            'groups',
            'setting_defaultgroups',
            'local_stackmatheditor'
        );

        // The chooser limit. It only means something with a structured group, so the field
        // is disabled without one - visible, so that the dependency is visible too. An empty
        // value inherits from the level above.
        $mform->addElement(
            'text',
            'maxdimension',
            get_string('setting_maxstructureddimension', 'local_stackmatheditor'),
            ['size' => 4, 'id' => 'id_sme_maxdimension']
        );
        $mform->setType('maxdimension', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('maxdimension', 'setting_maxstructureddimension', 'local_stackmatheditor');

        // Groups whose CAS packages this question does not load. The choice can still be
        // made and saved: the author configures the group now and loads the package afterwards.
        // Until then the group stays hidden from learners, and here is what to add.
        $dependencies = $customdata['dependencies'] ?? [];

        foreach ($dependencies as $key => $status) {
            if (!empty($status['available'])) {
                continue;
            }

            $label = $grouplabels[$key] ?? $key;
            $lines = [];

            if (!empty($status['unknown'])) {
                $lines[] = get_string('dependency_unknown', 'local_stackmatheditor');
            }

            foreach ($status['requirements'] as $requirement) {
                if (!empty($requirement['satisfied']) || $requirement['instruction'] === '') {
                    continue;
                }
                $lines[] = get_string(
                    'dependency_missing',
                    'local_stackmatheditor',
                    (object) [
                        'package'     => s($requirement['package']),
                        'instruction' => s($requirement['instruction']),
                    ]
                );
            }

            $mform->addElement(
                'static',
                'dependency_' . $key,
                \html_writer::span(
                    get_string('dependency_marker', 'local_stackmatheditor', s($label))
                ),
                \html_writer::div(
                    implode(\html_writer::empty_tag('br'), $lines),
                    'text-muted small'
                )
            );
        }

        // STACK input semantics. Read-only: STACK stores "insert stars" per input, and
        // that is where it is edited. The editor only makes the value visible where the toolbar
        // is configured.
        $semantics = $customdata['stacksemantics'] ?? ['inputs' => [], 'editurl' => null];

        if ($mode === 'question' && !empty($semantics['inputs'])) {
            $mform->addElement(
                'header',
                'stacksemanticssection',
                get_string('stacksemantics', 'local_stackmatheditor')
            );
            $mform->setExpanded('stacksemanticssection', true);

            foreach ($semantics['inputs'] as $index => $input) {
                $mform->addElement(
                    'static',
                    'stackinsertstars' . $index,
                    s($input['name']),
                    \html_writer::span(s($input['label']))
                );
            }

            $mform->addElement(
                'static',
                'stacksemanticsnote',
                '',
                \html_writer::span(
                    get_string('stacksemantics_desc', 'local_stackmatheditor'),
                    'text-muted'
                )
            );

            if (!empty($semantics['editurl'])) {
                $mform->addElement(
                    'static',
                    'stacksemanticsedit',
                    '',
                    \html_writer::link(
                        $semantics['editurl'],
                        get_string('stacksemantics_edit', 'local_stackmatheditor')
                    )
                );
            }
        }

        // Buttons.
        $buttons   = [];
        $buttons[] = $mform->createElement(
            'submit',
            'submitbutton',
            get_string('save', 'local_stackmatheditor')
        );

        if (!empty($returnurl)) {
            $buttons[] = $mform->createElement(
                'cancel',
                'cancel',
                get_string('back', 'local_stackmatheditor')
            );
        }

        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Refuse a dimension the plugin cannot honour.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (isset($data['maxdimension'])) {
            $error = \local_stackmatheditor\definitions::validate_max_dimension_input(
                (string) $data['maxdimension']
            );
            if ($error !== null) {
                $errors['maxdimension'] = get_string(
                    $error,
                    'local_stackmatheditor',
                    (object) [
                        'min' => \local_stackmatheditor\definitions::MIN_STRUCTURED_DIMENSION,
                        'max' => \local_stackmatheditor\definitions::MAX_STRUCTURED_DIMENSION,
                    ]
                );
            }
        }

        return $errors;
    }
}
