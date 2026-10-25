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
 * Event observers of local_stackmatheditor.
 *
 * Configuration rows belong to a course module and are removed with it. Deleting a single
 * course module fires course_module_deleted; deleting a course (remove_course_contents) removes its
 * course modules without that event and fires course_content_deleted at the end instead.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_stackmatheditor\observer::course_module_deleted',
        'internal'  => true,
    ],
    [
        'eventname' => '\core\event\course_content_deleted',
        'callback'  => '\local_stackmatheditor\observer::course_content_deleted',
        'internal'  => true,
    ],
];
