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

use adaptivequizcatmodel_testcatmodel\local\itemadministration\serve_fixed_question_administration;
use advanced_testcase;
use context_module;
use mod_adaptivequiz\local\attempt;
use stdClass;

/**
 * Tests of the item administration step of a running attempt.
 *
 * A CAT model names the next question, the host puts it into the question usage. That step must
 * never produce a second slot for an item that already has an active one - on a reload, on a
 * double click, or when the CAT model changes its mind between two requests.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cat_session::class)]
final class cat_session_test extends advanced_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $adaptivequiz;

    /** @var stdClass The user taking the attempt. */
    private stdClass $user;

    /** @var int[] Ids of the questions available to the activity. */
    private array $questionids = [];

    /**
     * Builds an activity with questions on the starting level and a user to take it.
     *
     * @param string|null $catmodel Name of the CAT model to configure, null for the built-in one.
     */
    private function set_up_activity(?string $catmodel = null): void {
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

        for ($copy = 0; $copy < 4; $copy++) {
            $question = $questiongenerator->create_question('truefalse', null, ['category' => $qcat->id]);
            $questiongenerator->create_question_tag(['questionid' => $question->id, 'tag' => 'adpq_5']);
            $this->questionids[] = (int) $question->id;
        }

        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'startinglevel' => 5,
            'lowestlevel' => 1,
            'highestlevel' => 10,
            'minimumquestions' => 1,
            'maximumquestions' => 10,
            'catmodel' => $catmodel ?? '',
        ]);
        $modgenerator->create_link_with_question_bank([
            'adaptivequizid' => $instance->id,
            'qbankid' => $qbank->id,
        ]);

        $this->adaptivequiz = clone($instance);
        $this->adaptivequiz->context = context_module::instance($instance->cmid);
        $this->user = $coregenerator->create_user();
        $this->setUser($this->user);
    }

    /**
     * Returns a fresh attempt object with its question usage initialised, as a new request would.
     *
     * @return attempt
     */
    private function new_request(): attempt {
        $attempt = new attempt($this->adaptivequiz, $this->user->id);
        $attempt->set_level((int) $this->adaptivequiz->startinglevel);
        $attempt->get_attempt();
        $attempt->initialize_quba();

        return $attempt;
    }

    /**
     * Resets what the fixture CAT model answers.
     */
    protected function tearDown(): void {
        serve_fixed_question_administration::$questionid = null;
        serve_fixed_question_administration::$slot = null;

        parent::tearDown();
    }

    /**
     * The built-in algorithm answers with a slot, and that slot reaches the attempt.
     */
    public function test_built_in_algorithm_writes_the_slot_back(): void {
        $this->resetAfterTest();
        $this->set_up_activity();

        $attempt = $this->new_request();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $this->assertNotNull($evaluation);
        $this->assertFalse($evaluation->item_administration_is_to_stop());
        $this->assertSame(1, $attempt->get_question_slot_number());
    }

    /**
     * A question named by the CAT model is put into the usage by the host.
     */
    public function test_question_named_by_the_catmodel_is_added_once(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];

        $attempt = $this->new_request();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $this->assertNotNull($evaluation);
        $this->assertSame(1, $attempt->get_question_slot_number());
        $this->assertCount(1, $attempt->get_quba()->get_slots());
    }

    /**
     * A second request without an answer reuses the slot instead of adding another one.
     */
    public function test_reload_reuses_the_active_slot(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];

        $first = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $first);

        $second = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $second);

        $this->assertSame(1, $second->get_question_slot_number(), 'The reload moved to a new slot.');
        $this->assertCount(1, $second->get_quba()->get_slots(), 'The reload added a second question.');
    }

    /**
     * Even a CAT model that changes its mind must not get a second active slot.
     */
    public function test_a_different_question_on_reload_still_reuses_the_slot(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');

        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $first = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $first);

        // The CAT model now names a different question, as it may after a reload.
        serve_fixed_question_administration::$questionid = $this->questionids[1];
        $second = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $second);

        $this->assertCount(
            1,
            $second->get_quba()->get_slots(),
            'A changed choice on reload appended a slot instead of reusing the active one.'
        );
        $this->assertSame(1, $second->get_question_slot_number());
    }

    /**
     * A CAT model may answer with a slot instead of a question, and that slot must reach the attempt.
     *
     * The built-in algorithm sets the slot number on the attempt itself, so this write-back only
     * shows when a CAT model answers by slot. Without it the attempt keeps whatever number it
     * carried before, and rendering the question fails.
     */
    public function test_slot_named_by_the_catmodel_reaches_the_attempt(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');

        // Put a question into the usage first, so there is a slot to name.
        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $first = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $first);

        serve_fixed_question_administration::$questionid = null;
        serve_fixed_question_administration::$slot = 1;

        $second = $this->new_request();
        $this->assertSame(0, $second->get_question_slot_number());

        cat_session::administer_next_item($this->adaptivequiz, $second);

        $this->assertSame(1, $second->get_question_slot_number());
    }

    /**
     * A CAT model that stops is passed through unchanged, without touching the usage.
     */
    public function test_stopping_catmodel_is_passed_through(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');

        $attempt = $this->new_request();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $this->assertNotNull($evaluation);
        $this->assertTrue($evaluation->item_administration_is_to_stop());
        $this->assertCount(0, $attempt->get_quba()->get_slots());
    }
}
