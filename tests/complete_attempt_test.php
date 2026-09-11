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
use context_module;
use mod_adaptivequiz\local\attempt\attempt_state;
use stdClass;

/**
 * Completing an attempt records the completion time once and hands the attempt to the CAT model.
 *
 * The reports used to show timemodified as the completion time. That value changes with every
 * later touch of the attempt, so a finished attempt appeared to move in time.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_complete_attempt')]
final class complete_attempt_test extends advanced_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $adaptivequiz;

    /** @var context_module The context of that activity. */
    private context_module $context;

    /** @var stdClass The user taking the attempt. */
    private stdClass $user;

    /**
     * Builds an activity with one user.
     *
     * @param string $catmodel Name of the CAT model to configure, empty for the built-in algorithm.
     */
    private function set_up_activity(string $catmodel = ''): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $this->adaptivequiz = $generator->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => $catmodel,
        ]);
        $this->context = context_module::instance($this->adaptivequiz->cmid);
        $this->user = $generator->create_user();
    }

    /**
     * Creates an attempt in progress and returns its question usage id.
     *
     * @param int $timemodified The value to store as the last change.
     * @return int
     */
    private function create_attempt_in_progress(int $timemodified): int {
        global $DB;

        $uniqueid = 4711;
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id,
            'userid' => $this->user->id,
            'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS,
            'attemptstopcriteria' => '',
            'questionsattempted' => 3,
            'difficultysum' => 15.0,
            'standarderror' => 0.5,
            'measure' => 0.5,
            'timecreated' => $timemodified - 100,
            'timemodified' => $timemodified,
        ]);

        return $uniqueid;
    }

    /**
     * Returns the attempt record.
     *
     * @param int $uniqueid Id of the question usage of the attempt.
     * @return stdClass
     */
    private function attempt_record(int $uniqueid): stdClass {
        global $DB;

        return $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
    }

    /**
     * Completing an attempt records the time it was completed.
     */
    public function test_completion_records_the_completion_time(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $this->resetAfterTest();
        $this->set_up_activity();
        $uniqueid = $this->create_attempt_in_progress(time() - 500);

        adaptivequiz_complete_attempt($uniqueid, $this->adaptivequiz, $this->context, (int) $this->user->id, '0.5', 'done');

        $attempt = $this->attempt_record($uniqueid);

        $this->assertEquals(attempt_state::COMPLETED, $attempt->attemptstate);
        $this->assertNotEmpty($attempt->timefinished);
        $this->assertEquals($attempt->timemodified, $attempt->timefinished);
    }

    /**
     * A later change to the attempt does not move the completion time.
     */
    public function test_completion_time_is_not_moved_by_a_later_change(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $this->resetAfterTest();
        $this->set_up_activity();
        $uniqueid = $this->create_attempt_in_progress(time() - 500);

        adaptivequiz_complete_attempt($uniqueid, $this->adaptivequiz, $this->context, (int) $this->user->id, '0.5', 'done');

        // Move the recorded completion time into the past, so a repeated completion would be
        // visible. Within one test run time() does not advance far enough on its own.
        $attempt = $this->attempt_record($uniqueid);
        $attempt->timefinished = time() - 5000;
        $attempt->timemodified = time();
        $DB->update_record('adaptivequiz_attempt', $attempt);
        $finished = $attempt->timefinished;

        adaptivequiz_complete_attempt($uniqueid, $this->adaptivequiz, $this->context, (int) $this->user->id, '0.5', 'done');

        $this->assertEquals(
            $finished,
            $this->attempt_record($uniqueid)->timefinished,
            'The completion time moved on a repeated completion.'
        );
    }
}
