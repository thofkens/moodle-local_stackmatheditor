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
 * Check a site after it has been upgraded from the previous release (#73, #85, #87).
 *
 * The configuration the old version stored means the same afterwards; what the old version could
 * leave behind - rows of deleted activities, duplicate rows for one scope - is gone, so the site
 * holds the same invariants a fresh install keeps; and the observers work without a cache purge.
 *
 * Usage: php verify_after.php /path/to/moodle
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This script runs from a checkout outside the Moodle tree and is told where Moodle is, so the
// usual literal config.php path cannot be used; the sniff that expects it is off for this file.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
define('CLI_SCRIPT', true);

$moodle = $argv[1] ?? '';
$config = is_file($moodle . '/config.php') ? $moodle . '/config.php' : $moodle . '/../config.php';
require($config);
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_stackmatheditor\config_manager;
use local_stackmatheditor\data_maintenance;
use local_stackmatheditor\definitions;

$failures = 0;

/**
 * Report one check.
 *
 * @param bool $ok Whether it holds.
 * @param string $what What was checked.
 * @return void
 */
function check(bool $ok, string $what): void {
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failures++;
    }
}

$plugin = new stdClass();
require($CFG->dirroot . '/local/stackmatheditor/version.php');
$installed = (int) get_config('local_stackmatheditor', 'version');
$state = json_decode((string) @file_get_contents($CFG->dataroot . '/sme-upgrade-state.json'), true);

echo "Installed version after the upgrade: {$installed}\n";
check($installed === (int) $plugin->version, 'the upgrade reached the version on disk');
check(is_array($state), 'the state of seed_before.php is there');
$state = (array) $state;
$quiza = (int) ($state['quiza'] ?? 0);

// What the old version stored still means the same.
check(config_manager::get_effective_enabled($quiza), 'quiz A keeps its editor');
check(!config_manager::get_effective_enabled($quiza, 7001), 'its question override keeps it off (the newer row)');
$global = config_manager::get_config(9002, 7002);
check(!empty($global['trigonometry']), 'the global question default still reaches another quiz');

// What the old version could leave behind is gone (#85, #87).
check(($state['leftover'] ?? 0) > 0, 'the deleted quiz had left rows before the upgrade (the case is real)');
check(
    $DB->count_records('local_stackmatheditor', ['cmid' => $state['quizb'] ?? -1]) === 0,
    'the rows of the quiz deleted before the upgrade are gone'
);
check(
    $DB->count_records('local_stackmatheditor', ['cmid' => 987654]) === 0,
    'the row of a course module that never existed is gone'
);
check($DB->record_exists('local_stackmatheditor', ['id' => $state['newer'] ?? -1]), 'of the duplicate pair the newer row stays');
check(!$DB->record_exists('local_stackmatheditor', ['id' => $state['older'] ?? -1]), 'the older row is gone');

// The invariants a fresh install keeps by itself.
check(data_maintenance::find_orphans() === [], 'no row without a module context');
check(data_maintenance::find_duplicate_scopes() === [], 'one row per scope');
check($DB->count_records('local_stackmatheditor') === 3, 'quiz default, question override, global default: three rows');

// The observers work right after the upgrade, without a manual cache purge (#85).
set_config('coursebinenable', 0, 'tool_recyclebin');
$gen = new testing_data_generator();
$quizc = get_coursemodule_from_instance(
    'quiz',
    $gen->create_module('quiz', ['course' => $state['course'] ?? SITEID])->id
);
$DB->insert_record('local_stackmatheditor', (object) [
    'cmid' => $quizc->id, 'questionbankentryid' => null, 'allowed_elements' => '{"_enabled":true}',
    'usermodified' => 2, 'timecreated' => time(), 'timemodified' => time(),
]);
course_delete_module((int) $quizc->id);
check(
    $DB->count_records('local_stackmatheditor', ['cmid' => $quizc->id]) === 0,
    'deleting a quiz after the upgrade removes its configuration'
);

// Running the repair again changes nothing: the upgrade step left nothing to do.
check(
    data_maintenance::delete_orphans() === 0 && data_maintenance::repair_duplicates() === 0,
    'a second repair finds nothing'
);

// The 1.3 settings arrive with defaults that change nothing.
check(config_manager::get_instance_student_toggle(), 'the student switch stays allowed (#73)');
check(definitions::get_instance_max_dimension() === 5, 'the chooser limit stays at 5 (#76)');
check(config_manager::get_effective_student_toggle($quiza), 'quiz A offers the switch as before');

// A site that has the plugin must not have lost its activation mode.
check((int) get_config('local_stackmatheditor', 'enabled') === 2, 'the activation mode survived');

// The same settings as a fresh install: every setting of the settings page has a stored value,
// and apart from the activation mode the seed changed, it is the default a fresh install writes.
require_once($CFG->libdir . '/adminlib.php');
// The settings file adds its page only for a user with site configuration rights.
\core\session\manager::set_user(get_admin());
$settingspage = admin_get_root(true, false)->locate('local_stackmatheditor');
check($settingspage instanceof admin_settingpage, 'the settings page is there');
$differs = [];
$compared = 0;
foreach ($settingspage->settings as $setting) {
    if ($setting->plugin !== 'local_stackmatheditor' || $setting->name === 'enabled') {
        continue;
    }
    $compared++;
    $stored = $setting->get_setting();
    $default = $setting->get_defaultsetting();
    // A multiselect stores a list; its order does not matter.
    $normal = function ($value) {
        if (!is_array($value)) {
            return (string) $value;
        }
        $value = array_map('strval', array_values($value));
        sort($value);
        return implode(',', $value);
    };
    if ($stored === null || $normal($stored) !== $normal($default)) {
        $differs[] = $setting->name . '=' . var_export($stored, true)
            . ' (fresh install: ' . var_export($default, true) . ')';
    }
}
check(
    $compared > 5 && $differs === [],
    "the other {$compared} settings have the value of a fresh install" . ($differs ? ': ' . implode(', ', $differs) : '')
);

echo $failures ? "{$failures} check(s) failed.\n" : "Upgrade verified.\n";
exit($failures ? 1 : 0);
