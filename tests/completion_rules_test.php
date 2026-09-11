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
use mod_adaptivequiz_mod_form;
use stdClass;

/**
 * The custom completion rule must survive duplication and stay switchable afterwards.
 *
 * Since Moodle 4.3 the completion settings appear twice on one page, for the activity and for the
 * course default, kept apart by a suffix on every element name. A form that ignores the suffix
 * reads its own rule as always disabled and writes it under a name core does not look at.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_adaptivequiz_mod_form::class)]
final class completion_rules_test extends advanced_testcase {
    /**
     * Builds the activity form for an existing instance.
     *
     * @param stdClass $instance The activity instance record.
     * @param string $suffix The completion suffix core would set, empty for the activity itself.
     * @return mod_adaptivequiz_mod_form
     */
    private function build_form(stdClass $instance, string $suffix = ''): mod_adaptivequiz_mod_form {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/mod_form.php');

        [$course, $cm] = get_course_and_cm_from_instance($instance->id, 'adaptivequiz');
        $PAGE->set_course($course);

        $data = new stdClass();
        $data->instance = $instance;
        $data->id = $instance->id;
        $data->course = $instance->course;

        $form = new mod_adaptivequiz_mod_form($data, $cm->sectionnum, $cm, $course);
        if ($suffix !== '') {
            $form->set_suffix($suffix);
        }

        return $form;
    }

    /**
     * The rule elements carry the suffix core expects, so activity and course default stay apart.
     */
    public function test_completion_rule_element_carries_the_suffix(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionattemptcompleted' => 1,
        ]);

        $form = $this->build_form($instance, '_adaptivequiz');

        $this->assertSame(['completionattemptcompleted_adaptivequiz'], $form->add_completion_rules());
    }

    /**
     * A rule submitted under the suffixed name is recognised, one without it is not.
     */
    public function test_completion_rule_is_read_under_the_suffixed_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);

        $form = $this->build_form($instance, '_adaptivequiz');

        $this->assertTrue($form->completion_rule_enabled(['completionattemptcompleted_adaptivequiz' => 1]));
        $this->assertFalse($form->completion_rule_enabled(['completionattemptcompleted_adaptivequiz' => 0]));

        // The unsuffixed name belongs to a different form and must not be picked up.
        $this->assertFalse($form->completion_rule_enabled(['completionattemptcompleted' => 1]));
    }

    /**
     * Duplicates a course module, using whichever API the Moodle version offers.
     *
     * duplicate_module() is deprecated since Moodle 5.2. The replacement lives in cmactions,
     * which does exist in 5.1 but without the duplicate() method - so the check has to be on the
     * method, not on the class.
     *
     * @param stdClass $course The course the module lives in.
     * @param stdClass $cm The course module to duplicate.
     * @return object The duplicated course module.
     */
    private function duplicate(stdClass $course, stdClass $cm): object {
        if (method_exists('\\core_courseformat\\local\\cmactions', 'duplicate')) {
            return (new \core_courseformat\local\cmactions($course))->duplicate($cm->id);
        }

        return duplicate_module($course, $cm);
    }

    /**
     * A duplicated activity keeps the rule and can afterwards be set back to no condition.
     */
    public function test_rule_survives_duplication_and_can_be_switched_off(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionattemptcompleted' => 1,
        ]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id, $course->id, false, MUST_EXIST);

        $duplicatedcm = $this->duplicate($course, $cm);

        $duplicated = $DB->get_record('adaptivequiz', ['id' => $duplicatedcm->instance], '*', MUST_EXIST);
        $this->assertEquals(1, $duplicated->completionattemptcompleted, 'The duplicate lost the completion rule.');

        // Switch the rule off on the duplicate, the way saving the form does.
        [, , , , $moduleinfo] = get_moduleinfo_data(
            get_coursemodule_from_id('adaptivequiz', $duplicatedcm->id, 0, false, MUST_EXIST),
            $course
        );
        $moduleinfo->completionattemptcompleted = 0;
        $moduleinfo->completion = COMPLETION_TRACKING_NONE;
        $moduleinfo->modulename = 'adaptivequiz';
        $moduleinfo->coursemodule = $duplicatedcm->id;
        $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_MOODLE, 'itemid' => 0];
        $moduleinfo->cmidnumber = '';
        update_moduleinfo(
            get_coursemodule_from_id('adaptivequiz', $duplicatedcm->id, 0, false, MUST_EXIST),
            $moduleinfo,
            $course
        );

        $duplicated = $DB->get_record('adaptivequiz', ['id' => $duplicatedcm->instance], '*', MUST_EXIST);
        $this->assertEquals(0, $duplicated->completionattemptcompleted, 'The rule could not be switched off again.');
    }
}
