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
use stdClass;

/**
 * Course module visibility belongs to core, and the result page has its own access policy.
 *
 * Two things are pinned here. First, changing an activity setting must never move
 * visible or visibleoncoursepage - those fields belong to core and the plugin only reads them.
 * Second, the deliberate policy of the result page: a participant may read the result of their
 * own finished attempt even when the activity has become unavailable, while starting or
 * continuing an attempt stays bound to that availability.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_update_instance')]
final class visibility_policy_test extends advanced_testcase {
    /**
     * Returns the course module record of an activity instance.
     *
     * @param int $instanceid Id of the activity instance.
     * @return stdClass
     */
    private function course_module(int $instanceid): stdClass {
        global $DB;

        $cm = get_coursemodule_from_instance('adaptivequiz', $instanceid, 0, false, MUST_EXIST);

        return $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST);
    }

    /**
     * Changing an activity setting leaves the core visibility fields untouched.
     */
    public function test_updating_settings_does_not_move_core_visibility(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);

        // Hide the activity the way a teacher would, through core.
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id, $course->id, false, MUST_EXIST);
        set_coursemodule_visible($cm->id, 0);

        $before = $this->course_module($instance->id);
        $this->assertEquals(0, $before->visible);

        // Now change a setting of the plugin, without mentioning visibility at all.
        $data = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
        $data->instance = $instance->id;
        $data->coursemodule = $cm->id;
        $data->maximumquestions = 42;
        adaptivequiz_update_instance($data);

        $after = $this->course_module($instance->id);

        $this->assertEquals($before->visible, $after->visible, 'The plugin moved course module visibility.');
        $this->assertEquals(
            $before->visibleoncoursepage,
            $after->visibleoncoursepage,
            'The plugin moved visibleoncoursepage.'
        );
        $this->assertEquals(42, $DB->get_field('adaptivequiz', 'maximumquestions', ['id' => $instance->id]));
    }

    /**
     * The plugin does not write the core visibility fields anywhere in its runtime code.
     *
     * A structural guard rather than a behavioural one: it catches a future write before it can
     * cause the kind of defect this issue is about.
     */
    public function test_plugin_never_writes_the_core_visibility_fields(): void {
        global $CFG;

        $root = $CFG->dirroot . '/mod/adaptivequiz';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/tests/') || str_contains($path, '/catmodel/')) {
                continue;
            }
            $content = file_get_contents($path);
            if (preg_match('/(->|\')(visible|visibleoncoursepage)\'?\s*=[^=]/', $content)) {
                $offenders[] = str_replace($root . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders, 'These files assign a core visibility field: ' . implode(', ', $offenders));
    }

    /**
     * The result page requires course access, not course module availability.
     *
     * Pinning the call itself is the only way to test this without a browser: the page is a
     * script, and the distinction lives entirely in the arguments passed to require_login().
     */
    public function test_result_page_requires_course_access_only(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/mod/adaptivequiz/attemptfinished.php');

        $this->assertMatchesRegularExpression(
            '/require_login\(\$course\)\s*;/',
            $source,
            'The result page enforces course module availability again, so a finished attempt '
                . 'becomes unreadable as soon as the activity is hidden or its section restricted.'
        );
        $this->assertStringContainsString(
            '$PAGE->set_cm($cm, $course);',
            $source,
            'Without the course module on the page the secondary navigation reads from null.'
        );
    }

    /**
     * Starting or continuing an attempt stays bound to course module availability.
     */
    public function test_attempt_page_still_enforces_availability(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/mod/adaptivequiz/attempt.php');

        $this->assertMatchesRegularExpression(
            '/require_login\(\$course,\s*true,\s*\$cm\)/',
            $source,
            'attempt.php must keep enforcing course module availability.'
        );
    }
}
