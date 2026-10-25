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
 * Event observers that keep the configuration table in step with the course-module lifecycle.
 *
 * Both observers are internal (run inside the deleting transaction): if the deletion is rolled
 * back, the configuration stays as well.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A single course module was deleted: remove its configurations.
     *
     * @param \core\event\course_module_deleted $event The event.
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        data_maintenance::delete_for_cmid((int) $event->objectid);
    }

    /**
     * The contents of a course were deleted (course deletion or restore with deletion).
     *
     * remove_course_contents() deletes the course modules and their contexts without firing
     * course_module_deleted per module. The rows of the deleted course are therefore the rows
     * whose module context is gone, which is exactly the orphan set.
     *
     * @param \core\event\course_content_deleted $event The event.
     * @return void
     */
    public static function course_content_deleted(\core\event\course_content_deleted $event): void {
        data_maintenance::delete_orphans();
    }
}
