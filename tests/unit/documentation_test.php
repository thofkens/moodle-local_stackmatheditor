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
 * Documentation must describe the product that exists.
 *
 * #71. The README once documented a setting for implicit multiplication that #65 had removed, and
 * described the correct behaviour a few sections further down - the same document contradicting
 * itself. This test is the cheap guard against that: every setting the plugin defines has a
 * title, every title appears in the README, and settings that were removed are not described as
 * if they were still there.
 *
 * @package    local_stackmatheditor
 * @copyright  2026 Ralf Erlebach
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class documentation_test extends \advanced_testcase {
    /**
     * Setting names as settings.php registers them.
     *
     * @return array Setting names without the component prefix.
     */
    private function settings_in_code(): array {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/stackmatheditor/settings.php');
        $this->assertNotFalse($source, 'settings.php must be readable');

        preg_match_all("#'local_stackmatheditor/([a-z_]+)'#", $source, $matches);
        $names = array_values(array_unique($matches[1]));

        // One setting is registered in a loop over the operator names.
        if (in_array('diffop', $names, true)) {
            $names = array_diff($names, ['diffop']);
            foreach (['gradient', 'divergence', 'curl', 'laplacian'] as $operator) {
                $names[] = 'diffop_' . $operator;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Every string settings.php asks for exists.
     *
     * A missing identifier there is not a failing test, it is a failing installation: Moodle
     * loads settings.php while it applies the default settings, `get_string()` raises a
     * debugging message, and moodle-plugin-ci aborts the install - so PHPUnit and Behat both
     * fail before a single test runs. That happened, and the cause was a rename that changed the
     * title but not the description.
     *
     * @return void
     */
    public function test_every_string_settings_php_asks_for_exists(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/stackmatheditor/settings.php');
        $this->assertNotFalse($source);

        // Only identifiers written out in full: the four operator settings build theirs in a
        // loop, and a static check cannot say anything useful about those.
        preg_match_all(
            "#get_string\(\s*'([a-z0-9_]+)'\s*,\s*'local_stackmatheditor'#i",
            $source,
            $matches
        );
        $this->assertNotEmpty($matches[1], 'settings.php must ask for some strings');

        foreach (array_unique($matches[1]) as $identifier) {
            $this->assertTrue(
                get_string_manager()->string_exists($identifier, 'local_stackmatheditor'),
                "settings.php asks for '$identifier', which no language pack defines"
            );
        }
    }

    /**
     * Every setting has a title and a description in both language packs.
     *
     * @return void
     */
    public function test_every_setting_has_strings(): void {
        foreach ($this->settings_in_code() as $name) {
            $identifier = $name === 'default_groups' ? 'setting_defaultgroups' : 'setting_' . $name;

            $this->assertTrue(
                get_string_manager()->string_exists($identifier, 'local_stackmatheditor'),
                "setting '$name' has no title string ($identifier)"
            );
            $this->assertTrue(
                get_string_manager()->string_exists(
                    $identifier . '_desc',
                    'local_stackmatheditor'
                ),
                "setting '$name' has no description string"
            );
        }
    }

    /**
     * Every setting the plugin has is described in the README.
     *
     * @return void
     */
    public function test_the_readme_describes_every_setting(): void {
        global $CFG;

        $readme = file_get_contents($CFG->dirroot . '/local/stackmatheditor/README.md');
        $this->assertNotFalse($readme, 'README.md must be readable');

        foreach ($this->settings_in_code() as $name) {
            $identifier = $name === 'default_groups' ? 'setting_defaultgroups' : 'setting_' . $name;
            $title = get_string($identifier, 'local_stackmatheditor');

            if (str_starts_with($name, 'diffop_')) {
                // The four operator settings share one entry in the README.
                $title = 'Maxima function for the';
            }

            $this->assertStringContainsString(
                $title,
                $readme,
                "the README does not describe the setting '$name' ($title)"
            );
        }
    }

    /**
     * The README must not describe settings that no longer exist.
     *
     * @return void
     */
    public function test_the_readme_does_not_describe_removed_settings(): void {
        global $CFG;

        $readme = file_get_contents($CFG->dirroot . '/local/stackmatheditor/README.md');

        // Removed by #65: implicit multiplication is STACK's decision, not a setting here.
        foreach (
            [
            'Handling of implicit multiplication',
            'the variable mode',
            'variablemode',
            ] as $gone
        ) {
            $this->assertStringNotContainsString(
                $gone,
                $readme,
                "the README still describes the removed setting: '$gone'"
            );
        }
    }

    /**
     * A setting that was removed leaves no strings behind either.
     *
     * @return void
     */
    public function test_removed_settings_leave_no_strings(): void {
        foreach (['setting_variablemode', 'label_variablemode'] as $gone) {
            $this->assertFalse(
                get_string_manager()->string_exists($gone, 'local_stackmatheditor'),
                "the string '$gone' belongs to a setting that no longer exists"
            );
        }
    }

    /**
     * English and German describe the same set of strings.
     *
     * @return void
     */
    public function test_both_language_packs_have_the_same_keys(): void {
        global $CFG;

        $english = $this->string_keys($CFG->dirroot . '/local/stackmatheditor/lang/en/local_stackmatheditor.php');
        $german = $this->string_keys($CFG->dirroot . '/local/stackmatheditor/lang/de/local_stackmatheditor.php');

        $this->assertSame(
            [],
            array_values(array_diff($english, $german)),
            'strings missing from the German language pack'
        );
        $this->assertSame(
            [],
            array_values(array_diff($german, $english)),
            'strings only in the German language pack'
        );
    }

    /**
     * Read the string identifiers of a language file.
     *
     * @param string $path Language file.
     * @return array Identifiers.
     */
    private function string_keys(string $path): array {
        $source = file_get_contents($path);
        $this->assertNotFalse($source, "$path must be readable");

        preg_match_all("#\\\$string\\['([a-zA-Z0-9_]+)'\\]#", $source, $matches);
        $keys = array_unique($matches[1]);
        sort($keys);

        return $keys;
    }

    /**
     * The MathQuill build is named the same everywhere, and the checksums are those of the files.
     *
     * #71: the README named 0.10.1-sme.1 while sme.5 was vendored.
     *
     * @return void
     */
    public function test_mathquill_version_and_checksums_agree(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/stackmatheditor/';

        $xml = simplexml_load_file($root . 'thirdpartylibs.xml');
        $this->assertNotFalse($xml);
        $version = (string) $xml->library[0]->version;
        $this->assertMatchesRegularExpression('/^0\.10\.1-sme\.\d+$/', $version);

        $readme = file_get_contents($root . 'README.md');
        $this->assertStringContainsString("MathQuill {$version} ", $readme, 'README third-party section');
        preg_match_all('/MathQuill (0\.10\.1-sme\.\d+)/', $readme, $named);
        $this->assertSame([$version], array_values(array_unique($named[1])), 'README names one build');

        $provenance = file_get_contents($root . 'thirdparty/readme_moodle.txt');
        $this->assertStringContainsString("Version: {$version}", $provenance);
        $this->assertStringContainsString("Build provenance of {$version}", $provenance);
        $this->assertSame(
            $version,
            \local_stackmatheditor\output\editor_injector::MATHQUILL_VERSION,
            'the cache key of the library URLs'
        );

        foreach (['mathquill.js', 'mathquill.min.js', 'mathquill.css'] as $file) {
            $this->assertMatchesRegularExpression(
                '/^\s+' . preg_quote($file, '/') . '\s+' . hash_file('sha256', $root . 'thirdparty/mathquill/' . $file) . '$/m',
                $provenance,
                "SHA-256 of {$file} in thirdparty/readme_moodle.txt"
            );
        }
        // The build is identified by a commit, not by a branch or a patch on top of one.
        $this->assertMatchesRegularExpression('/^\s+Fork commit:\s+[0-9a-f]{40}\s*$/m', $provenance);
    }

    /**
     * The README support table says per-question configuration exactly where the configuration
     * page offers it.
     *
     * #71: the table said "yes" for the adaptive quiz, whose configuration page has quiz mode only.
     *
     * @return void
     */
    public function test_support_table_matches_runtime(): void {
        global $CFG;
        $readme = file_get_contents($CFG->dirroot . '/local/stackmatheditor/README.md');
        $rows = [
            'quiz' => '| Quiz attempt |',
            'adaptivequiz' => '| Adaptive Quiz (',
        ];
        foreach ($rows as $modname => $prefix) {
            $line = null;
            foreach (explode("\n", $readme) as $candidate) {
                if (strpos($candidate, $prefix) === 0) {
                    $line = $candidate;
                }
            }
            $this->assertNotNull($line, "README support table row for {$modname}");
            $cells = array_map('trim', explode('|', trim($line, " |")));
            $perquestion = strpos($cells[2], 'yes') === 0;
            $this->assertSame(
                context_resolver::supports_question_configuration($modname),
                $perquestion,
                "per-question configuration of {$modname}: README says '{$cells[2]}'"
            );
            $this->assertTrue(context_resolver::has_configuration_ui($modname), $modname);
        }
        // The configuration page decides its mode by the same method.
        $configure = file_get_contents($CFG->dirroot . '/local/stackmatheditor/configure.php');
        $this->assertStringContainsString('context_resolver::supports_question_configuration($modname)', $configure);
    }

    /**
     * version.php, the change log and the README describe the same release.
     *
     * #71: README, version.php, tag and release metadata are set consistently for stable. The
     * tag is checked where it is made - release-artefact.yml refuses a tag other than
     * "v" plus the release name and a stable tag on a build that is not declared stable. This
     * test covers what is in the repository: the newest change-log entry names the release, the
     * build and the maturity of version.php, and the README does not call a stable build
     * "in development".
     *
     * @return void
     */
    public function test_release_metadata_agrees(): void {
        global $CFG;
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/stackmatheditor/version.php');

        $changes = file_get_contents($CFG->dirroot . '/local/stackmatheditor/docs/CHANGES.md');
        $found = preg_match('/^(\d+\.\d+\.\d+) \((\d{10})\) - ([a-z]+)$/m', $changes, $heading);
        $this->assertSame(1, $found, 'the change log starts its newest entry with "<release> (<build>) - <maturity>"');
        $maturities = [
            MATURITY_ALPHA => 'alpha',
            MATURITY_BETA => 'beta',
            MATURITY_RC => 'rc',
            MATURITY_STABLE => 'stable',
        ];
        $this->assertSame($plugin->release, $heading[1], 'release name in the change log');
        $this->assertSame((string) $plugin->version, $heading[2], 'build in the change log');
        $this->assertSame($maturities[$plugin->maturity], $heading[3], 'maturity in the change log');

        if ($plugin->maturity === MATURITY_STABLE) {
            $readme = file_get_contents($CFG->dirroot . '/local/stackmatheditor/README.md');
            $this->assertStringNotContainsStringIgnoringCase('in development', $readme);
            $this->assertStringNotContainsString('-dev', $plugin->release);
        }
    }
}
