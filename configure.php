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

/**
 * STACK MathQuill toolbar configuration page.
 *
 * Handles quiz-level configuration for both mod_quiz and mod_adaptivequiz,
 * as well as question-level configuration for mod_quiz.
 *
 * Operating modes
 * ---------------
 * Quiz-mode    : neither qbeid nor questionid supplied (or both 0).
 *                Supported by both mod_quiz and mod_adaptivequiz.
 * Question-mode: at least one of qbeid / questionid is set.
 *                Supported by mod_quiz only; mod_adaptivequiz always uses quiz-mode.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');

use local_stackmatheditor\config_manager;
use local_stackmatheditor\definitions;
use local_stackmatheditor\form\configure_form;

// Parameters.
$cmid       = required_param('cmid', PARAM_INT);
$questionid = optional_param('questionid', 0, PARAM_INT);
$qbeid      = optional_param('qbeid', 0, PARAM_INT);
$returnurl  = optional_param('returnurl', '', PARAM_LOCALURL);

// Resolve the course module without committing to a specific modname yet.
// This allows the page to serve both mod_quiz and mod_adaptivequiz.
$cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);

$modname = $cm->modname;
$isadaptivequiz = ($modname === 'adaptivequiz');
$isquiz         = ($modname === 'quiz');

if (!$isquiz && !$isadaptivequiz) {
    throw new \moodle_exception('invalidcoursemodule');
}

$course = get_course($cm->course);

// Authentication and authorisation FIRST: nothing question-specific happens before the login and
// capability gates, so an anonymous request cannot tell existing from missing or STACK from
// non-STACK questions, and runs no question-bank queries.
$context = \context_module::instance($cmid);
require_login($course, false, $cm);

// A write capability of the module, the same one the navigation link requires.
require_capability(\local_stackmatheditor\quiz_helper::configure_capability($modname), $context);

// Load the activity record (not needed for the login gate).
$activity = $DB->get_record($modname, ['id' => $cm->instance], '*', MUST_EXIST);

// Determine operating mode.
// mod_adaptivequiz always uses quiz-mode (no per-question configuration).
$quizmode = !\local_stackmatheditor\context_resolver::supports_question_configuration($modname)
    || ($qbeid <= 0 && $questionid <= 0);

// Question resolution (mod_quiz question-mode only). Managing the quiz given by cmid is
// permission for the questions of that quiz, not for any question on the site: the entry has to
// be one the quiz uses, and the user has to be allowed to view the question where it lives. Both
// are checked before anything is shown, evaluated or saved (protection against IDOR).
$questionrecord = null;
if (!$quizmode) {
    $questionrecord = \local_stackmatheditor\quiz_helper::require_configurable_question(
        (int) $activity->id,
        (int) $qbeid,
        (int) $questionid
    );
    $qbeid      = (int) $questionrecord->qbeid;
    $questionid = (int) $questionrecord->id;
}

// Return URL: the calling page passed by every configuration link; only a direct call without
// (or with an unusable) returnurl falls back to the activity's view page.
$returnurl = \local_stackmatheditor\quiz_helper::resolve_return_url($returnurl, $cmid, $modname);

// Page setup.
$pageparams = ['cmid' => $cmid];
if (!$quizmode) {
    $pageparams['qbeid'] = $qbeid;
}
if (!empty($returnurl)) {
    $pageparams['returnurl'] = $returnurl;
}
$pageurl = new \moodle_url('/local/stackmatheditor/configure.php', $pageparams);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');

// Page title, heading, and breadcrumb.
if ($quizmode) {
    if ($isadaptivequiz) {
        $PAGE->set_title(get_string('configure_adaptivequiz', 'local_stackmatheditor'));
        $PAGE->set_heading($course->fullname);
        $PAGE->navbar->add(
            $activity->name,
            new \moodle_url('/mod/adaptivequiz/view.php', ['id' => $cmid])
        );
        $PAGE->navbar->add(get_string('configure_adaptivequiz', 'local_stackmatheditor'));
    } else {
        $PAGE->set_title(get_string('configure_quiz', 'local_stackmatheditor'));
        $PAGE->set_heading($course->fullname);
        $PAGE->navbar->add(
            $activity->name,
            new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid])
        );
        $PAGE->navbar->add(get_string('configure_quiz', 'local_stackmatheditor'));
    }
} else {
    $PAGE->set_title(get_string('configure', 'local_stackmatheditor'));
    $PAGE->set_heading($course->fullname);
    $PAGE->navbar->add(
        $activity->name,
        new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid])
    );
    $PAGE->navbar->add(get_string('configure', 'local_stackmatheditor'));
}

// Instance enabled mode.
$instancemode = config_manager::get_instance_enabled_mode();

// Question preview (mod_quiz question-mode only).
$questionpreviewhtml = '';
if (!$quizmode && $questionrecord) {
    try {
        $question = question_bank::load_question($questionid);
        $quba = question_engine::make_questions_usage_by_activity(
            'local_stackmatheditor',
            $context
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question($question);
        $quba->start_question($slot);

        $options                   = new question_display_options();
        $options->readonly         = true;
        $options->flags            = question_display_options::HIDDEN;
        $options->marks            = question_display_options::HIDDEN;
        $options->rightanswer      = question_display_options::HIDDEN;
        $options->manualcomment    = question_display_options::HIDDEN;
        $options->history          = question_display_options::HIDDEN;
        $options->feedback         = question_display_options::HIDDEN;
        $options->numpartscorrect  = question_display_options::HIDDEN;
        $options->generalfeedback  = question_display_options::HIDDEN;
        $options->correctness      = question_display_options::HIDDEN;

        $questionpreviewhtml = $quba->render_question($slot, $options);
    } catch (\Throwable $e) {
        // Preview boundary: the question type renders the preview and may fail in ways of its
        // own; the form is still usable without it. A defect is reported by caught().
        \local_stackmatheditor\quiz_helper::caught($e, 'configure question preview');
        $questionpreviewhtml = '';
    }
}

// Definitions.
$groups      = definitions::get_element_groups();
$grouplabels = definitions::get_group_labels_with_examples();

// Load current config.
if ($quizmode) {
    $existingquizdefault = config_manager::get_quiz_default($cmid);
    if ($existingquizdefault !== null) {
        $config = $existingquizdefault;
    } else {
        // No quiz-level record yet → start from instance defaults.
        $config = config_manager::get_instance_defaults();
    }
} else {
    $config = config_manager::get_config($cmid, $qbeid, $questionid);
}

// What this level has stored itself. $config is what applies here, inherited values included;
// deciding what to store needs the level's own values only.
$own = $quizmode
    ? (config_manager::get_own_config($cmid) ?? [])
    : (config_manager::get_own_config($cmid, (int) $qbeid) ?? []);

// Build selected group keys.
$selectedkeys = [];
foreach (array_keys($groups) as $key) {
    if (!empty($config[$key])) {
        $selectedkeys[] = $key;
    }
}

// What this level inherits when it has no activation of its own: the quiz value for a
// question, the instance default for a quiz.
$inheritedenabled = ($instancemode === 1 || $instancemode === 3);
if (!$quizmode && ($instancemode === 2 || $instancemode === 3)) {
    $parentdefault = config_manager::get_quiz_default($cmid);
    if ($parentdefault !== null && isset($parentdefault['_enabled'])) {
        $inheritedenabled = (bool) $parentdefault['_enabled'];
    }
}

// Determine initial enabled state.
if ($instancemode === 0) {
    $currentenabled = false;
} else if ($instancemode === 1) {
    $currentenabled = true;
} else {
    // Modes 2 and 3 – check stored value first.
    if (isset($config['_enabled'])) {
        $currentenabled = (bool) $config['_enabled'];
    } else if ($quizmode) {
        // Quiz-level, no stored record yet → instance default.
        $currentenabled = ($instancemode === 3);
    } else {
        // Question-level → try quiz-level default, then instance default.
        $quizdefault    = config_manager::get_quiz_default($cmid);
        if ($quizdefault !== null && isset($quizdefault['_enabled'])) {
            $currentenabled = (bool) $quizdefault['_enabled'];
        } else {
            $currentenabled = ($instancemode === 3);
        }
    }
}

// Create form.
$mform = new configure_form($pageurl->out(false), [
    'mode'           => $quizmode ? 'quiz' : 'question',
    'modname'        => $modname,
    'questionrecord' => $questionrecord,
    'activity'       => $activity,
    'grouplabels'    => $grouplabels,
    'previewhtml'    => $questionpreviewhtml,
    'returnurl'      => $returnurl,
    'instancemode'   => $instancemode,
    'dependencies'   => \local_stackmatheditor\dependency_resolver::get_group_status($questionid),
    'stacksemantics' => \local_stackmatheditor\stack_inputs::get_semantics_summary(
        $questionid,
        (int) $course->id,
        $PAGE->url->out(false)
    ),
]);

// Set current values. Implicit multiplication is not an editor setting: STACK owns that
// semantics, the editor only shows it.
// The student switch: the stored value of this level, else what the level above allows.
if (isset($config['_allowStudentToggle'])) {
    $currentstudenttoggle = (bool) $config['_allowStudentToggle'];
} else {
    $currentstudenttoggle = config_manager::get_instance_student_toggle();
    if (!$quizmode) {
        $parentconfig = config_manager::get_quiz_default($cmid);
        if ($parentconfig !== null && isset($parentconfig['_allowStudentToggle'])) {
            $currentstudenttoggle = (bool) $parentconfig['_allowStudentToggle'];
        }
    }
}

// What the level above allows, for deciding whether a submitted value says anything.
$inheritedtoggle = config_manager::get_instance_student_toggle();
if (!$quizmode) {
    $quizown = config_manager::get_own_config($cmid) ?? [];
    if (isset($quizown['_allowStudentToggle'])) {
        $inheritedtoggle = $inheritedtoggle && (bool) $quizown['_allowStudentToggle'];
    }
}

$formdata = [
    'maxdimension'       => $own['_maxStructuredDimension'] ?? '',
    'groups'             => $selectedkeys,
    'enabled'            => (int) $currentenabled,
    'allowstudenttoggle' => (int) ((bool) $currentstudenttoggle),
];
$mform->set_data($formdata);

// Process form.
if ($mform->is_cancelled()) {
    if (!empty($returnurl)) {
        redirect(new \moodle_url($returnurl));
    }
} else if ($data = $mform->get_data()) {
    $selectedgroups = $data->groups ?? [];
    $elements       = [];
    foreach (array_keys($groups) as $key) {
        $elements[$key] = in_array($key, $selectedgroups);
    }

    // The editor hands STACK what was typed and lets STACK's own "insert stars" setting decide.
    // STACK is the sole source of truth for implicit multiplication, so nothing about it is stored.
    $elements['_variableMode'] = definitions::IMPLICIT_STACK;

    // The student switch is stored like the activation itself, and it is only ever stored
    // as true when the editor is on here: a level that has no editor grants no permission.
    // The chooser limit. An empty field means "inherit", and a level whose structured
    // groups are off keeps whatever it had: a temporary deactivation is not a reason to forget.
    if (property_exists($data, 'maxdimension')) {
        $cleaned = definitions::clean_max_dimension($data->maxdimension);
        if ($cleaned === null) {
            unset($elements['_maxStructuredDimension']);
        } else {
            $elements['_maxStructuredDimension'] = $cleaned;
        }
    } else if (isset($own['_maxStructuredDimension'])) {
        $elements['_maxStructuredDimension'] = (int) $own['_maxStructuredDimension'];
    }



    // Store activation only when it differs from inheritance; otherwise saving unrelated settings
    // (such as the toolbar groups) would create an unintended override.
    $submittedenabled = property_exists($data, 'enabled') ? (bool) $data->enabled : null;
    $storeenabled = config_manager::activation_to_store(
        $instancemode,
        isset($own['_enabled']) ? (bool) $own['_enabled'] : null,
        $inheritedenabled,
        $submittedenabled
    );
    if ($storeenabled === null) {
        unset($elements['_enabled']);
    } else {
        $elements['_enabled'] = $storeenabled;
    }

    // Student switch: an absent field - disabled while the editor is off - keeps the
    // author's stored choice instead of overwriting it with "no".
    $editornow = $storeenabled ?? $inheritedenabled;
    $storetoggle = config_manager::student_toggle_to_store(
        isset($own['_allowStudentToggle']) ? (bool) $own['_allowStudentToggle'] : null,
        $editornow,
        property_exists($data, 'allowstudenttoggle') ? (bool) $data->allowstudenttoggle : null,
        $inheritedtoggle
    );
    if ($storetoggle === null) {
        unset($elements['_allowStudentToggle']);
    } else {
        $elements['_allowStudentToggle'] = (int) $storetoggle;
    }

    if ($quizmode) {
        config_manager::save_quiz_default($cmid, $elements);
    } else {
        config_manager::save_config($cmid, $qbeid, $elements);
    }

    redirect(
        $pageurl,
        get_string('config_saved', 'local_stackmatheditor'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Output.
// The size field follows the group selection while the form is open.
$PAGE->requires->js_call_amd('local_stackmatheditor/configure_form', 'init');

echo $OUTPUT->header();

if ($quizmode) {
    if ($isadaptivequiz) {
        echo $OUTPUT->heading(
            get_string('configure_adaptivequiz_heading', 'local_stackmatheditor', $activity->name)
        );
    } else {
        echo $OUTPUT->heading(
            get_string('configure_quiz_heading', 'local_stackmatheditor', $activity->name)
        );
    }
} else {
    echo $OUTPUT->heading(
        get_string('configure_heading', 'local_stackmatheditor', $questionrecord->name)
    );
}

$mform->display();

echo $OUTPUT->footer();
