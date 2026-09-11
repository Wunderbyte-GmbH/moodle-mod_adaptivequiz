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
 * Creating and updating an instance must not depend on the attempt feedback form fields.
 *
 * The activity form always submits attemptfeedbackenable and attemptfeedbackeditor, so through
 * the interface the fields are never missing. Everything that creates an instance programmatically
 * omits them, because a CAT test has no use for them: generators, seed and provisioning scripts,
 * web service and automation code. Those paths must work.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_update_instance')]
final class attempt_feedback_fields_test extends advanced_testcase {
    /**
     * Returns the minimal instance data, deliberately without any attempt feedback field.
     *
     * @param int $courseid Id of the course to place the activity in.
     * @return stdClass
     */
    private function minimal_instance_data(int $courseid): stdClass {
        $data = new stdClass();
        $data->course = $courseid;
        $data->name = 'Instance without feedback fields';
        $data->intro = '';
        $data->introformat = FORMAT_MOODLE;
        $data->questionpool = [];
        $data->startinglevel = 5;
        $data->lowestlevel = 1;
        $data->highestlevel = 10;
        $data->minimumquestions = 1;
        $data->maximumquestions = 10;
        $data->standarderror = 5;
        $data->attempts = 0;
        $data->grademethod = ADAPTIVEQUIZ_GRADEHIGHEST;
        $data->showabilitymeasure = 0;
        $data->showattemptprogress = 0;
        $data->debuginfoenable = 0;
        $data->completionattemptcompleted = 0;

        return $data;
    }

    /**
     * An instance can be created without the attempt feedback fields.
     */
    public function test_instance_can_be_created_without_the_feedback_fields(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $data = $this->minimal_instance_data($course->id);
        $data->coursemodule = $this->create_course_module($course);

        $id = adaptivequiz_add_instance($data);

        $this->assertIsInt($id);

        $record = $DB->get_record('adaptivequiz', ['id' => $id]);
        $this->assertSame('', $record->attemptfeedback);
        $this->assertEquals(0, $record->attemptfeedbackenable);
    }

    /**
     * An instance can be updated without the attempt feedback fields.
     */
    public function test_instance_can_be_updated_without_the_feedback_fields(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id);

        $data = $this->minimal_instance_data($course->id);
        $data->instance = $instance->id;
        $data->coursemodule = $cm->id;
        $data->name = 'Renamed without feedback fields';

        $this->assertNotFalse(adaptivequiz_update_instance($data));

        $record = $DB->get_record('adaptivequiz', ['id' => $instance->id]);
        $this->assertSame('Renamed without feedback fields', $record->name);
    }

    /**
     * Creates an empty course module the instance data can point at.
     *
     * @param stdClass $course The course to place it in.
     * @return int Id of the course module.
     */
    private function create_course_module(stdClass $course): int {
        $placeholder = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);

        return get_coursemodule_from_instance('adaptivequiz', $placeholder->id)->id;
    }
}
