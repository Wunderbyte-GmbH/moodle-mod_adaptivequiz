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

namespace mod_adaptivequiz\privacy;

use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use stdClass;

/**
 * Tests of the Privacy API implementation.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $adaptivequiz;

    /** @var context_module The context of that activity. */
    private context_module $context;

    /** @var stdClass A user with an attempt. */
    private stdClass $owner;

    /** @var stdClass Another user with an attempt in the same activity. */
    private stdClass $other;

    /** @var int A question the attempts can administer. */
    private int $questionid;

    /**
     * Builds one activity with an attempt for each of two users.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->adaptivequiz = $generator->create_module('adaptivequiz', ['course' => $course->id]);
        $this->context = context_module::instance($this->adaptivequiz->cmid);

        /** @var \mod_qbank_generator $qbankgenerator */
        $qbankgenerator = $generator->get_plugin_generator('mod_qbank');
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $qbank = $qbankgenerator->create_instance(['course' => $course->id]);
        $qbankcm = get_coursemodule_from_instance('qbank', $qbank->id, 0, false, MUST_EXIST);
        $qcat = question_get_default_category(context_module::instance($qbankcm->id)->id);
        $this->questionid = (int) $questiongenerator->create_question('truefalse', null, [
            'category' => $qcat->id,
        ])->id;

        $this->owner = $generator->create_user();
        $this->other = $generator->create_user();

        $this->create_attempt($this->owner->id);
        $this->create_attempt($this->other->id);
    }

    /**
     * Creates a completed attempt for the given user.
     *
     * @param int $userid The user the attempt belongs to.
     * @return int Id of the attempt.
     */
    private function create_attempt(int $userid): int {
        global $DB;

        // A question usage with a question in it. It has to carry one: on MySQL and MariaDB the
        // question engine deletes usages through a join over question_attempts, so an empty usage
        // survives the deletion there. A real attempt always holds at least one question, so the
        // fixture models the real case rather than the core quirk.
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $this->context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $quba->add_question(\question_bank::load_question($this->questionid));
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);

        return $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id,
            'userid' => $userid,
            'uniqueid' => $quba->get_id(),
            'attemptstate' => 'complete',
            'attemptstopcriteria' => 'Standard error reached',
            'questionsattempted' => 7,
            'difficultysum' => 35.0,
            'standarderror' => 0.4,
            'measure' => 0.75,
            'timecreated' => time() - 600,
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the number of attempts stored for a user in the activity.
     *
     * @param int $userid The user to count for.
     * @return int
     */
    private function attempt_count(int $userid): int {
        global $DB;

        return $DB->count_records('adaptivequiz_attempt', [
            'instance' => $this->adaptivequiz->id,
            'userid' => $userid,
        ]);
    }

    /**
     * The metadata names every column of the attempt table that holds personal data.
     */
    public function test_metadata_covers_the_attempt_table(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('mod_adaptivequiz'));
        $tables = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $tables[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }

        $this->assertArrayHasKey('adaptivequiz_attempt', $tables);
        $this->assertEqualsCanonicalizing(
            ['userid', 'uniqueid', 'attemptstate', 'attemptstopcriteria', 'questionsattempted',
                'difficultysum', 'standarderror', 'measure', 'timecreated', 'timemodified'],
            $tables['adaptivequiz_attempt']
        );
    }

    /**
     * A user with an attempt is found in the context of that activity.
     */
    public function test_context_of_a_user_with_an_attempt_is_found(): void {
        $contextlist = provider::get_contexts_for_userid((int) $this->owner->id);

        $this->assertCount(1, $contextlist);
        $this->assertEquals($this->context->id, $contextlist->get_contextids()[0]);
    }

    /**
     * A user without an attempt is found in no context.
     */
    public function test_user_without_an_attempt_has_no_context(): void {
        $stranger = $this->getDataGenerator()->create_user();

        $this->assertCount(0, provider::get_contexts_for_userid((int) $stranger->id));
    }

    /**
     * Both users with an attempt are listed for the context.
     */
    public function test_users_in_context_are_listed(): void {
        $userlist = new userlist($this->context, 'mod_adaptivequiz');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing(
            [(int) $this->owner->id, (int) $this->other->id],
            $userlist->get_userids()
        );
    }

    /**
     * The export contains the attempt of the user and its measured ability.
     */
    public function test_export_contains_the_attempt(): void {
        $this->export_context_data_for_user(
            (int) $this->owner->id,
            $this->context,
            'mod_adaptivequiz'
        );

        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $exported = $writer->get_data([get_string('privacy:attemptpath', 'adaptivequiz', 1)]);
        $this->assertEquals('complete', $exported->attemptstate);
        $this->assertEquals(7, $exported->questionsattempted);
        $this->assertEquals(0.75, $exported->measure);
    }

    /**
     * Deleting one user leaves the other one untouched.
     */
    public function test_delete_for_user_leaves_the_other_user(): void {
        $contextlist = new approved_contextlist($this->owner, 'mod_adaptivequiz', [$this->context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $this->attempt_count((int) $this->owner->id));
        $this->assertSame(1, $this->attempt_count((int) $this->other->id));
    }

    /**
     * Deleting the context removes the attempts of everyone in it.
     */
    public function test_delete_for_all_users_in_context(): void {
        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $this->attempt_count((int) $this->owner->id));
        $this->assertSame(0, $this->attempt_count((int) $this->other->id));
    }

    /**
     * Deleting an approved list of users removes exactly those.
     */
    public function test_delete_for_users(): void {
        $userlist = new approved_userlist($this->context, 'mod_adaptivequiz', [(int) $this->other->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(1, $this->attempt_count((int) $this->owner->id));
        $this->assertSame(0, $this->attempt_count((int) $this->other->id));
    }

    /**
     * Deleting an attempt also removes the question usage that held the answers.
     */
    public function test_delete_removes_the_question_usage(): void {
        global $DB;

        $usageid = (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', [
            'instance' => $this->adaptivequiz->id,
            'userid' => $this->owner->id,
        ]);
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $usageid]));

        $contextlist = new approved_contextlist($this->owner, 'mod_adaptivequiz', [$this->context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse(
            $DB->record_exists('question_usages', ['id' => $usageid]),
            'The answers of the deleted attempt are still in the database.'
        );
    }

    /**
     * Deleting in one activity does not touch the attempts in another.
     */
    public function test_delete_is_limited_to_the_given_context(): void {
        global $DB;

        $othercourse = $this->getDataGenerator()->create_course();
        $otherinstance = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $othercourse->id]);
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $otherinstance->id,
            'userid' => $this->owner->id,
            'uniqueid' => 0,
            'attemptstate' => 'complete',
            'attemptstopcriteria' => '',
            'questionsattempted' => 1,
            'difficultysum' => 1.0,
            'standarderror' => 1.0,
            'measure' => 0.1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $this->attempt_count((int) $this->owner->id));
        $this->assertSame(1, $DB->count_records('adaptivequiz_attempt', [
            'instance' => $otherinstance->id,
            'userid' => $this->owner->id,
        ]));
    }
}
