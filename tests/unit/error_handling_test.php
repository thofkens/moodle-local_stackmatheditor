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
 * Fail-soft error handling does not hide defects (docs/ERROR-HANDLING.md).
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_stackmatheditor\quiz_helper
 */
final class error_handling_test extends \advanced_testcase {
    /**
     * The places that may catch everything: the boundaries between the plugin and a page that
     * has to work without it. File => number of catch-alls.
     */
    private const BOUNDARIES = [
        'lib.php' => 1,
        'classes/hook_callbacks.php' => 2,
        'configure.php' => 1,
    ];

    /**
     * Defects are reported through debugging(), data problems are not.
     *
     * @return void
     */
    public function test_defects_are_reported(): void {
        $defects = [
            new \Error('Call to undefined method x::y()'),
            new \TypeError('Argument 1 must be of type int'),
            new \coding_exception('wrong use'),
            new \dml_read_exception('syntax error', 'SELECT', []),
            new \dml_write_exception('syntax error', 'UPDATE', []),
        ];
        foreach ($defects as $e) {
            $this->assertTrue(quiz_helper::is_defect($e), get_class($e));
            quiz_helper::caught($e, 'test');
            $this->assertDebuggingCalled(null, null, get_class($e) . ' is reported');
        }

        $data = [
            new \moodle_exception('invalidrecord'),
            new \dml_missing_record_exception('question'),
            new \required_capability_exception(\context_system::instance(), 'moodle/site:config', 'nopermissions', ''),
        ];
        foreach ($data as $e) {
            $this->assertFalse(quiz_helper::is_defect($e), get_class($e));
            quiz_helper::caught($e, 'test');
            $this->assertDebuggingNotCalled(get_class($e) . ' stays quiet');
        }
    }

    /**
     * Only the boundaries catch everything, and no catch is silent.
     *
     * A new catch (\Throwable) inside a lookup would let a defect pass as "no data"; a catch
     * that does not hand the throwable to caught() would hide it entirely.
     *
     * @return void
     */
    public function test_catch_inventory(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/stackmatheditor';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $catchalls = [];
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (substr($relative, -4) !== '.php' || preg_match('#^(tests|vendor|node_modules)/#', $relative)) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $count = preg_match_all('/catch \(\\\\Throwable \$/', $source);
            if ($count) {
                $catchalls[$relative] = $count;
            }
            preg_match_all('/catch \(([^)]+)\) \{(.*?)\n\s*\}/s', $source, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                if (strpos($block[1], '$') === false) {
                    continue;
                }
                // A catch either reports through caught() or rethrows; a lock or transaction
                // cleanup that rethrows is fine, a silent one is not.
                $handled = strpos($block[2], 'caught(') !== false || strpos($block[2], 'throw ') !== false
                    || strpos($block[2], 'rollback(') !== false;
                $this->assertTrue($handled, "{$relative}: catch ({$block[1]}) neither reports nor rethrows");
            }
        }
        ksort($catchalls);
        $expected = self::BOUNDARIES;
        ksort($expected);
        $this->assertSame($expected, $catchalls, 'catch (\\Throwable) only at the boundaries');
    }
}
