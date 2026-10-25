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

use local_stackmatheditor\output\editor_injector;

/**
 * The question preview gets the question's configuration - and only for a user who may use it.
 *
 * The lookup sits behind a fail-soft catch; this is its positive path, so a lookup that always
 * failed would not pass as "no configuration".
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\output\editor_injector
 */
final class preview_config_test extends \advanced_testcase {
    /**
     * Call the private lookup with a question id in the request.
     *
     * @param int $questionid Question id the preview page would carry.
     * @return array Slot => configuration.
     */
    private function preview_configs(int $questionid): array {
        $_GET['id'] = $questionid;
        try {
            $method = new \ReflectionMethod(editor_injector::class, 'resolve_preview_configs');
            return $method->invoke(null, 0);
        } finally {
            unset($_GET['id']);
        }
    }

    /**
     * A question with a global default: the preview gets it; a user without access gets nothing.
     *
     * @return void
     */
    public function test_preview_configuration_and_access(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $questions = $generator->get_plugin_generator('core_question');
        $course = $generator->create_course();
        $category = $questions->create_question_category(['contextid' => \context_course::instance($course->id)->id]);
        $question = $questions->create_question('stack', 'test3', ['category' => $category->id]);
        $qbeid = config_manager::resolve_qbeid((int) $question->id);
        $DB->insert_record(config_manager::TABLE, (object) [
            'cmid' => 0,
            'questionbankentryid' => $qbeid,
            'allowed_elements' => json_encode(['_enabled' => true, 'trigonometry' => true, 'greek_lower' => false]),
            'usermodified' => 0,
            'timecreated' => 1,
            'timemodified' => 1,
        ]);

        $configs = $this->preview_configs((int) $question->id);
        $this->assertSame([1], array_keys($configs));
        $this->assertTrue($configs[1]['trigonometry']);
        $this->assertFalse($configs[1]['greek_lower']);
        $this->assertDebuggingNotCalled();

        $this->setUser($generator->create_user());
        $this->assertSame([], $this->preview_configs((int) $question->id), 'no access, no configuration');

        $this->setAdminUser();
        $this->assertSame([], $this->preview_configs(0), 'no question id');
        $this->assertSame([], $this->preview_configs(999999999), 'unknown question');
    }
}
