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
 * Seed a site that runs the previous release, before it is upgraded (#73, #85, #87).
 *
 * Writes what a site on the previous release holds in the wild, through real activities:
 *   - quiz A: a quiz default and a question override, and the global question default (cmid 0);
 *   - quiz A: a second, older row for the same question scope - a duplicate the old write path
 *     could leave behind (#87);
 *   - quiz B: a configuration, then quiz B deleted with the old plugin installed, which leaves its
 *     row behind (#85), and a row of a course module that never existed.
 * The rows go straight into the table rather than through config_manager, because this runs
 * against the old plugin and must not depend on an API the old version may not have. The ids
 * verify_after.php needs are written to a small state file next to the site's dataroot.
 *
 * Usage: php seed_before.php /path/to/moodle
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

global $DB;

$before = get_config('local_stackmatheditor', 'version');
echo "Plugin version before the upgrade: {$before}\n";

set_config('enabled', 2, 'local_stackmatheditor');
// The deletion below is the old behaviour under test, not the recycle bin's.
set_config('coursebinenable', 0, 'tool_recyclebin');

$gen = new testing_data_generator();
$course = $gen->create_course(['shortname' => 'SMEUPGRADE', 'fullname' => 'Upgrade check']);
$quiza = get_coursemodule_from_instance('quiz', $gen->create_module('quiz', ['course' => $course->id])->id);
$quizb = get_coursemodule_from_instance('quiz', $gen->create_module('quiz', ['course' => $course->id])->id);

$now = time();
$insert = function (int $cmid, ?int $qbeid, array $config, int $time) use ($DB): int {
    return (int) $DB->insert_record('local_stackmatheditor', (object) [
        'cmid' => $cmid,
        'questionbankentryid' => $qbeid,
        'allowed_elements' => json_encode($config),
        'usermodified' => 2,
        'timecreated' => $time,
        'timemodified' => $time,
    ]);
};

$insert((int) $quiza->id, null, ['_enabled' => true, 'basic_operators' => true, 'greek_lower' => true], $now);
// Two rows for one question scope; the newer one is what every read returns.
$older = $insert((int) $quiza->id, 7001, ['_enabled' => true, 'basic_operators' => true], $now - 100);
$newer = $insert((int) $quiza->id, 7001, ['_enabled' => false, 'basic_operators' => true], $now);
$insert(0, 7002, ['trigonometry' => true], $now);
$insert((int) $quizb->id, null, ['_enabled' => true], $now);
$insert(987654, 7003, ['_enabled' => true], $now);

// Quiz B deleted while the previous release is installed: before 2026100800 nothing removed its
// configuration.
course_delete_module((int) $quizb->id);
$leftover = $DB->count_records('local_stackmatheditor', ['cmid' => $quizb->id]);
echo "Rows of the deleted quiz still there before the upgrade: {$leftover}\n";

$state = [
    'quiza' => (int) $quiza->id,
    'quizb' => (int) $quizb->id,
    'course' => (int) $course->id,
    'older' => $older,
    'newer' => $newer,
    'leftover' => $leftover,
];
file_put_contents($CFG->dataroot . '/sme-upgrade-state.json', json_encode($state));

echo 'Seeded ' . $DB->count_records('local_stackmatheditor') . " configuration records and enabled mode 2.\n";
