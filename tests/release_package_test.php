<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_adaptivequiz;

use advanced_testcase;

/**
 * The released package must not contain tests that need the development fixture.
 *
 * The neutral CAT model under catmodel/testcatmodel is excluded from the release by
 * .gitattributes. Every test that uses it has to be excluded as well - otherwise an installation
 * made from the release ZIP runs those tests against a subplugin that is not there. That has
 * happened twice already, so it is pinned here.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class release_package_test extends advanced_testcase {
    /**
     * Every test file referring to the fixture CAT model is export-ignored.
     */
    public function test_tests_using_the_fixture_catmodel_are_excluded_from_the_release(): void {
        global $CFG;

        $root = $CFG->dirroot . '/mod/adaptivequiz';
        $attributes = $root . '/.gitattributes';

        if (!file_exists($attributes)) {
            // An installation made from the release ZIP has no .gitattributes - and, correctly,
            // none of the excluded files either. Nothing to check there.
            $this->assertFileDoesNotExist($root . '/catmodel/testcatmodel');

            return;
        }

        $excluded = [];
        foreach (file($attributes, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('#^/(\S+)\s+export-ignore#', trim($line), $matches)) {
                $excluded[] = $matches[1];
            }
        }

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tests'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($root . '/', '', $file->getPathname());
            if ($relative === 'tests/release_package_test.php') {
                // This guard names the fixture to look for it; it does not use it.
                continue;
            }

            if (!str_contains(file_get_contents($file->getPathname()), 'adaptivequizcatmodel_testcatmodel')) {
                continue;
            }

            $covered = false;
            foreach ($excluded as $pattern) {
                if ($relative === $pattern || str_starts_with($relative, rtrim($pattern, '/') . '/')) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These tests need the fixture CAT model but would ship: ' . implode(', ', $offenders)
        );
    }
}
