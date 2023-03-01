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

namespace mod_adaptivequiz\local;

use mod_adaptivequiz\local\attempt\attempt;
use question_bank;
use question_engine;
use question_state_gaveup;
use question_state_gradedpartial;
use question_state_gradedright;
use question_state_gradedwrong;
use question_usage_by_activity;
use stdClass;

/**
 * An entry point to run an adaptive quiz.
 *
 * The class contains high level methods to orchestrate entities and services involved in the process of taking an adaptive quiz.
 *
 * @package    mod_adaptivequiz
 * @copyright  2013 onwards Remote-Learner {@link http://www.remote-learner.ca/}
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class adaptive_quiz_session {

    /**
     * @var question_usage_by_activity $quba
     */
    private $quba;

    /**
     * @var fetchquestion $fetchquestion
     */
    private $fetchquestion;

    /**
     * @var int $level The difficulty level the attempt is currently set at.
     */
    private $level;

    /**
     * @var int $slot A question slot number.
     */
    private $slot = 0;

    /**
     * @var string $attemptstopcriteria
     */
    private $attemptstopcriteria;

    public function __construct(question_usage_by_activity $quba, fetchquestion $fetchquestion, int $level) {
        $this->quba = $quba;
        $this->fetchquestion = $fetchquestion;
        $this->level = $level;
    }

    /**
     * This function does the work of initializing data required to fetch a new question for the attempt.
     *
     * @param attempt $attempt
     * @param stdClass $adaptivequiz A record from {adaptivequiz}.
     * @param int $lastdifficultylevel Previous difficulty level, it can modify the process of searching a next question.
     */
    public function start_attempt(attempt $attempt, stdClass $adaptivequiz, int $lastdifficultylevel): bool {
        // Check if the level requested is out of the minimum/maximum boundries for the attempt.
        if (!$this->level_in_bounds($this->level, $adaptivequiz)) {
            $this->attemptstopcriteria = get_string('leveloutofbounds', 'adaptivequiz', $this->level);

            return false;
        }

        // Check if the attempt has reached the maximum number of questions attempted.
        if ($attempt->read_attempt_data()->questionsattempted >= $adaptivequiz->maximumquestions) {
            $this->attemptstopcriteria = get_string('maxquestattempted', 'adaptivequiz');

            return false;
        }

        // Find the last question viewed/answered by the user.
        // The last slot in the array should be the last question that was attempted (meaning it was either shown to the user
        // or the user submitted an answer to it).
        $questionslots = $this->quba->get_slots();
        $this->slot = !empty($questionslots) ? end($questionslots) : 0;

        // Check if this is the beginning of an attempt (and pass the starting level) or the continuation of an attempt.
        if (empty($this->slot) && 0 == $attempt->read_attempt_data()->questionsattempted) {
            // Set the starting difficulty level.
            $this->fetchquestion->set_level((int) $adaptivequiz->startinglevel);
            // Sets the level class property.
            $this->level = $adaptivequiz->startinglevel;
            // Set the rebuild flag for fetchquestion class.
            $this->fetchquestion->rebuild = true;
        } else if (!empty($this->slot) && $this->was_answer_submitted_to_question($this->quba, $this->slot)) {
            // If the attempt already has a question attached to it, check if an answer was submitted to the question.
            // If so fetch a new question.

            // Provide the question-fetching process with limits based on our last question.
            // If the last question was correct...
            if ($this->quba->get_question_mark($this->slot) > 0) {
                // Only ask questions harder than the last question unless we are already at the top of the ability scale.
                if ($lastdifficultylevel < $adaptivequiz->highestlevel) {
                    $this->fetchquestion->set_minimum_level($lastdifficultylevel + 1);
                    // Do not ask a question of the same level unless we are already at the max.
                    if ($lastdifficultylevel == $this->level) {
                        $this->level++;
                    }
                }
            } else {
                // If the last question was wrong...
                // Only ask questions easier than the last question unless we are already at the bottom of the ability scale.
                if ($lastdifficultylevel > $adaptivequiz->lowestlevel) {
                    $this->fetchquestion->set_maximum_level($lastdifficultylevel - 1);
                    // Do not ask a question of the same level unless we are already at the min.
                    if ($lastdifficultylevel == $this->level) {
                        $this->level--;
                    }
                }
            }

            // Reset the slot number back to zero, since we are going to fetch a new question.
            $this->slot = 0;
            // Set the level of difficulty to fetch.
            $this->fetchquestion->set_level($this->level);
        } else if (empty($this->slot) && 0 < $attempt->read_attempt_data()->questionsattempted) {
            // If this condition is met, then something went wrong because the slot id is empty BUT the questions attempted is
            // greater than zero. Stop the attempt.
            $this->attemptstopcriteria = get_string('errorattemptstate', 'adaptivequiz');

            return false;
        }

        // If the slot property is set, then we have a question that is ready to be attempted.  No more process is required.
        if (!empty($this->slot)) {
            return true;
        }

        // If we are here, then the slot property was unset and a new question needs to prepared for display.
        $status = $this->get_question_ready($attempt);

        if (empty($status)) {
            $this->attemptstopcriteria = get_string('errorfetchingquest', 'adaptivequiz', $this->level);

            return false;
        }

        return true;
    }

    /**
     * This function returns the $level property.
     */
    public function level(): int {
        return $this->level;
    }

    /**
     * This function returns the current slot number set for the attempt.
     *
     * @return int question slot number
     */
    public function question_slot_number(): int {
        return $this->slot;
    }

    public function attempt_stop_criteria(): string {
        return $this->attemptstopcriteria;
    }

    /**
     * This function gets the question ready for display to the user.
     *
     * @return bool True if everything went okay, otherwise false.
     */
    private function get_question_ready(attempt $attempt): bool {
        // Fetch questions already attempted.
        $exclude = $this->get_all_questions_in_attempt($attempt->read_attempt_data()->uniqueid);
        // Fetch questions for display.
        $questionids = $this->fetchquestion->fetch_questions($exclude);

        if (empty($questionids)) {
            return false;
        }

        // Select one random question.
        $questiontodisplay = $this->return_random_question($questionids);
        if (empty($questiontodisplay)) {
            return false;
        }

        // Load basic question data.
        $questionobj = question_preload_questions(array($questiontodisplay));
        get_question_options($questionobj);

        // Make a copy of the array and pop off the first (and only) element (current() didn't work for some reason).
        $quest = $questionobj;
        $quest = array_pop($quest);

        // Create the question_definition object.
        $question = question_bank::load_question($quest->id);
        // Add the question to the usage question_usable_by_activity object.
        $this->slot = $this->quba->add_question($question);
        // Start the question attempt.
        $this->quba->start_question($this->slot);
        // Save the question usage and question attempt state to the DB.
        question_engine::save_questions_usage_by_activity($this->quba);
        // Update the attempt quba id.
        $attempt->set_attempt_uniqueid($this->quba->get_id());

        // Set class level property to the difficulty level of the question returned from the fetchquestion class.
        $this->level = $this->fetchquestion->get_level();

        return true;
    }

    /**
     * This functions returns an array of all question ids that have been used in this attempt.
     *
     * @return array an array of question ids
     */
    private function get_all_questions_in_attempt($uniqueid): array {
        global $DB;

        return $DB->get_records_menu('question_attempts', ['questionusageid' => $uniqueid], 'id ASC', 'id,questionid');
    }

    /**
     * This function checks to see if the difficulty level is out of the boundaries set for the attempt.
     *
     * @param int $level The difficulty level requested.
     * @param stdClass $adaptivequiz An {adaptivequiz} record.
     */
    private function level_in_bounds(int $level, stdClass $adaptivequiz): bool {
        if ($adaptivequiz->lowestlevel <= $level && $adaptivequiz->highestlevel >= $level) {
            return true;
        }

        return false;
    }

    /**
     * This function determines if the user submitted an answer to the question.
     *
     * @param question_usage_by_activity $quba
     * @param int $slotid Question slot id.
     */
    private function was_answer_submitted_to_question(question_usage_by_activity $quba, int $slotid): bool {
        $state = $quba->get_question_state($slotid);

        // Check if the state of the question attempted was graded right, partially right, wrong or gave up, count the question has
        // having an answer submitted.
        $marked = $state instanceof question_state_gradedright || $state instanceof question_state_gradedpartial
            || $state instanceof question_state_gradedwrong || $state instanceof question_state_gaveup;

        if ($marked) {
            return true;
        }

        return false;
    }

    /**
     * This function returns a random array element.
     *
     * @param array $questions An array of question ids. Array key values are question ids.
     * @return int A question id.
     */
    private function return_random_question(array $questions): int {
        if (empty($questions)) {
            return 0;
        }

        return array_rand($questions);
    }
}
