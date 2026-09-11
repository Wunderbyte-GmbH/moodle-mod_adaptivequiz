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

use advanced_testcase;
use context_module;
use question_engine;
use stdClass;

/**
 * A question usage must never hand out the same slot twice within one attempt.
 *
 * Historically the adaptive administration produced duplicate slots on resume and double
 * submission paths. The slot has to come from the state of the question usage, never from a
 * counter of the current request. These tests pin that invariant.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt::class)]
final class attempt_slot_uniqueness_test extends advanced_testcase {
    /** @var stdClass The activity instance under test, with its context attached. */
    private stdClass $adaptivequiz;

    /** @var stdClass The user taking the attempt. */
    private stdClass $user;

    /**
     * Builds an activity with one question per difficulty level, so the fetching never runs dry.
     * @param int $maximumquestions Maximumquestions.
     */
    private function set_up_activity(int $maximumquestions = 10): void {
        $coregenerator = $this->getDataGenerator();
        /** @var \mod_adaptivequiz_generator $modgenerator */
        $modgenerator = $coregenerator->get_plugin_generator('mod_adaptivequiz');
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $coregenerator->get_plugin_generator('core_question');
        /** @var \mod_qbank_generator $qbankgenerator */
        $qbankgenerator = $coregenerator->get_plugin_generator('mod_qbank');

        $course = $coregenerator->create_course();

        $qbank = $qbankgenerator->create_instance(['course' => $course->id]);
        $qbankcm = get_coursemodule_from_instance('qbank', $qbank->id, 0, false, MUST_EXIST);
        $qcat = question_get_default_category(context_module::instance($qbankcm->id)->id);

        // Plenty of questions on every level. The tests do not move the difficulty - they pin the
        // slot invariant, not the adaptivity - so every step draws from the starting level.
        for ($level = 1; $level <= 10; $level++) {
            for ($copy = 0; $copy < 8; $copy++) {
                $question = $questiongenerator->create_question('truefalse', null, ['category' => $qcat->id]);
                $questiongenerator->create_question_tag(['questionid' => $question->id, 'tag' => "adpq_$level"]);
            }
        }

        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'startinglevel' => 5,
            'lowestlevel' => 1,
            'highestlevel' => 10,
            'minimumquestions' => 1,
            'maximumquestions' => $maximumquestions,
        ]);
        $modgenerator->create_link_with_question_bank([
            'adaptivequizid' => $instance->id,
            'qbankid' => $qbank->id,
        ]);

        $this->adaptivequiz = clone($instance);
        $this->adaptivequiz->context = context_module::instance($instance->cmid);
        $this->user = $coregenerator->create_user();
    }

    /**
     * Returns a fresh attempt object for the user, as a new request would.
     *
     * @return attempt
     */
    private function new_request(): attempt {
        $attempt = new attempt($this->adaptivequiz, $this->user->id);
        $attempt->set_level((int) $this->adaptivequiz->startinglevel);

        return $attempt;
    }

    /**
     * Answers the question in the given slot, so the next request fetches a new one.
     *
     * @param attempt $attempt The running attempt.
     * @param int $slot The slot to answer.
     */
    private function answer(attempt $attempt, int $slot): void {
        $quba = $attempt->get_quba();
        $quba->process_action($slot, ['answer' => 1, '-submit' => 1]);
        $quba->finish_all_questions();
        question_engine::save_questions_usage_by_activity($quba);
    }

    /**
     * Returns the slots actually stored for the usage, straight from the database.
     *
     * @param int $uniqueid Id of the question usage.
     * @return int[]
     */
    private function stored_slots(int $uniqueid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select(
            'question_attempts',
            'slot',
            'questionusageid = :usageid ORDER BY slot',
            ['usageid' => $uniqueid]
        ));
    }

    /**
     * Several adaptive steps produce a strictly increasing sequence of unique slots.
     */
    public function test_adaptive_steps_never_reuse_a_slot(): void {
        $this->resetAfterTest();
        $this->set_up_activity();

        $attempt = $this->new_request();
        $this->assertTrue($attempt->start_attempt());
        $uniqueid = $attempt->get_quba()->get_id();

        $seen = [];
        for ($step = 0; $step < 4; $step++) {
            $slot = $attempt->get_question_slot_number();
            $this->assertNotContains($slot, $seen, "Slot {$slot} was handed out twice.");
            $seen[] = $slot;

            $this->answer($attempt, $slot);

            $attempt = $this->new_request();
            $this->assertTrue($attempt->start_attempt(), 'Step ' . $step . ': ' . $attempt->get_status());
        }

        $stored = $this->stored_slots($uniqueid);
        $this->assertSame(array_values(array_unique($stored)), $stored, 'The usage stored a slot twice.');
        $this->assertSame(range(1, count($stored)), $stored, 'The slots are not a gapless ascending sequence.');
    }

    /**
     * Reloading the page before answering must not add the question a second time.
     */
    public function test_reload_before_answering_keeps_the_same_slot(): void {
        $this->resetAfterTest();
        $this->set_up_activity();

        $attempt = $this->new_request();
        $this->assertTrue($attempt->start_attempt());
        $uniqueid = $attempt->get_quba()->get_id();
        $firstslot = $attempt->get_question_slot_number();

        // Same request again, nothing answered in between - the double click.
        $reload = $this->new_request();
        $this->assertTrue($reload->start_attempt());

        $this->assertSame($firstslot, $reload->get_question_slot_number(), 'The reload moved to a new slot.');
        $this->assertSame([1], $this->stored_slots($uniqueid), 'The reload added a second question.');
    }

    /**
     * Resuming after three answered questions continues with the next slot, not with an old one.
     */
    public function test_resume_continues_with_the_next_slot(): void {
        $this->resetAfterTest();
        $this->set_up_activity();

        $attempt = $this->new_request();
        $this->assertTrue($attempt->start_attempt());
        $uniqueid = $attempt->get_quba()->get_id();

        for ($step = 0; $step < 3; $step++) {
            $this->answer($attempt, $attempt->get_question_slot_number());
            $attempt = $this->new_request();
            $this->assertTrue($attempt->start_attempt(), 'Step ' . $step . ': ' . $attempt->get_status());
        }

        $this->assertSame(4, $attempt->get_question_slot_number(), 'The resumed attempt did not continue at slot 4.');
        $this->assertSame([1, 2, 3, 4], $this->stored_slots($uniqueid));
    }
}
