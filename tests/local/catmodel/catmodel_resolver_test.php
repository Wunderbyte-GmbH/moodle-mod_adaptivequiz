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

namespace mod_adaptivequiz\local\catmodel;

use adaptivequizcatmodel_testcatmodel\local\catmodel\form\mod_form_handler;
use adaptivequizcatmodel_testcatmodel\local\itemadministration\stop_immediately_administration;
use adaptivequizcatmodel_testcatmodel\local\catmodel\instance\instance_handler;
use MoodleQuickForm;
use advanced_testcase;
use coding_exception;
use mod_adaptivequiz\local\catmodel\form\catmodel_mod_form_modifier;
use mod_adaptivequiz\local\catmodel\form\catmodel_mod_form_validator;
use mod_adaptivequiz\local\catmodel\form\mod_form_extension;
use mod_adaptivequiz\local\catmodel\instance\catmodel_add_instance_handler;
use mod_adaptivequiz\local\catmodel\instance\catmodel_delete_instance_handler;
use mod_adaptivequiz\local\catmodel\instance\catmodel_update_instance_handler;
use mod_adaptivequiz\local\itemadministration\default_item_administration_factory;
use mod_adaptivequiz\local\itemadministration\item_administration_evaluation;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;
use mod_adaptivequiz\local\itemadministration\next_item;
use mod_adaptivequiz\output\attempts_number;

/**
 * Tests the generic CAT model subplugin contract.
 *
 * These tests deliberately use the neutral CAT model under catmodel/testcatmodel and never a
 * real one. The host must be testable on an installation that has no real CAT model at all.
 * The test CAT model is excluded from the released package by .gitattributes.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(catmodel_resolver::class)]
final class catmodel_resolver_test extends advanced_testcase {
    /**
     * Without a CAT model the resolver returns nothing, which is what makes the host default apply.
     */
    public function test_no_catmodel_configured_resolves_to_nothing(): void {
        $this->assertFalse(catmodel_resolver::is_configured(null));
        $this->assertFalse(catmodel_resolver::is_configured(''));
        $this->assertNull(catmodel_resolver::handler(null, catmodel_add_instance_handler::class));
        $this->assertNull(catmodel_resolver::handler('', catmodel_add_instance_handler::class));
    }

    /**
     * An installed CAT model is found for every extension point it implements.
     */
    public function test_installed_catmodel_is_discovered(): void {
        $this->assertTrue(catmodel_resolver::is_configured('testcatmodel'));

        $interfaces = [
            catmodel_add_instance_handler::class,
            catmodel_update_instance_handler::class,
            catmodel_delete_instance_handler::class,
            catmodel_mod_form_modifier::class,
            catmodel_mod_form_validator::class,
        ];
        foreach ($interfaces as $interface) {
            $handler = catmodel_resolver::handler('testcatmodel', $interface);
            $this->assertInstanceOf($interface, $handler, "No handler resolved for {$interface}.");
        }
    }

    /**
     * An extension point the CAT model does not implement falls back to the host default.
     */
    public function test_unimplemented_extension_point_falls_back(): void {
        $this->assertNull(catmodel_resolver::handler('testcatmodel', \core\output\renderable::class));
    }

    /**
     * The host delegates the whole activity lifecycle to the CAT model of the instance.
     */
    public function test_activity_lifecycle_is_delegated(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

        $this->resetAfterTest();
        instance_handler::reset();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => 'testcatmodel',
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);

        $this->assertSame(['add_instance_callback'], instance_handler::called());

        $record = $DB->get_record('adaptivequiz', ['id' => $instance->id]);
        $record->instance = $record->id;
        $record->coursemodule = get_coursemodule_from_instance('adaptivequiz', $record->id)->id;
        $record->attemptfeedbackeditor = ['text' => '', 'format' => FORMAT_MOODLE];
        adaptivequiz_update_instance($record);

        $this->assertSame(['add_instance_callback', 'update_instance_callback'], instance_handler::called());

        adaptivequiz_delete_instance($instance->id);

        $this->assertSame(
            ['add_instance_callback', 'update_instance_callback', 'delete_instance_callback'],
            instance_handler::called()
        );
    }

    /**
     * Without a CAT model the host runs its own lifecycle and delegates nothing.
     */
    public function test_activity_lifecycle_without_a_catmodel_delegates_nothing(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

        $this->resetAfterTest();
        instance_handler::reset();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        adaptivequiz_delete_instance($instance->id);

        $this->assertSame([], instance_handler::called());
    }

    /**
     * The selected CAT model adds its own field and removes those of the built-in algorithm.
     */
    public function test_activity_form_is_extended_by_the_catmodel(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');

        $this->resetAfterTest();

        $form = new MoodleQuickForm('testform', 'post', '');
        $form->addElement('select', 'catmodel', 'CAT model', ['' => '', 'testcatmodel' => 'Test CAT model']);
        $form->setConstant('catmodel', 'testcatmodel');
        $form->addElement('text', 'minimumquestions', 'Minimum questions');
        $form->addElement('hidden', 'catmodelfieldsmarker');

        mod_form_extension::apply($form);

        $this->assertTrue(
            $form->elementExists(mod_form_handler::FIELD),
            'The field of the CAT model did not reach the form.'
        );
        $this->assertFalse(
            $form->elementExists('minimumquestions'),
            'A field of the built-in algorithm survived although a CAT model drives the instance.'
        );
    }

    /**
     * Without a CAT model the form keeps the fields of the built-in algorithm.
     */
    public function test_activity_form_without_a_catmodel_is_untouched(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');

        $this->resetAfterTest();

        $form = new MoodleQuickForm('testform', 'post', '');
        $form->addElement('select', 'catmodel', 'CAT model', ['' => '']);
        $form->addElement('text', 'minimumquestions', 'Minimum questions');
        $form->addElement('hidden', 'catmodelfieldsmarker');

        mod_form_extension::apply($form);

        $this->assertTrue($form->elementExists('minimumquestions'));
        $this->assertFalse($form->elementExists(mod_form_handler::FIELD));
    }

    /**
     * The CAT model decides whether submitted data is acceptable, and sets its own defaults.
     */
    public function test_activity_form_validation_and_defaults_are_delegated(): void {
        $this->resetAfterTest();

        $accepted = ['catmodel' => 'testcatmodel', mod_form_handler::FIELD => 'anything'];
        $refused = ['catmodel' => 'testcatmodel', mod_form_handler::FIELD => mod_form_handler::REJECTED_VALUE];

        $this->assertSame([], mod_form_extension::validate($accepted, []));
        $this->assertArrayHasKey(mod_form_handler::FIELD, mod_form_extension::validate($refused, []));

        // Without a CAT model nothing is delegated, whatever the data says.
        $this->assertSame([], mod_form_extension::validate([mod_form_handler::FIELD => 'refuse-me'], []));

        $defaults = mod_form_extension::preprocess(['catmodel' => 'testcatmodel']);
        $this->assertArrayHasKey(mod_form_handler::FIELD, $defaults);
        $this->assertSame(['unrelated' => 1], mod_form_extension::preprocess(['unrelated' => 1]));
    }

    /**
     * The item administration of an attempt is an extension point like the others.
     */
    public function test_item_administration_is_resolved(): void {
        $this->resetAfterTest();

        $factory = catmodel_resolver::handler('testcatmodel', item_administration_factory::class);
        $this->assertInstanceOf(item_administration_factory::class, $factory);

        $administration = new stop_immediately_administration();
        $evaluation = $administration->evaluate_ability_to_administer_next_item(null);

        $this->assertTrue($evaluation->item_administration_is_to_stop());
        $this->assertSame(stop_immediately_administration::REASON, $evaluation->stoppage_reason());
        $this->assertNull($evaluation->next_item());
    }

    /**
     * The value objects of the item administration refuse states that make no sense.
     */
    public function test_item_administration_value_objects_guard_their_invariants(): void {
        $item = next_item::from_question_id(7);
        $this->assertSame(7, $item->question_id());
        $this->assertNull($item->quba_slot());

        $evaluation = item_administration_evaluation::with_next_item($item);
        $this->assertFalse($evaluation->item_administration_is_to_stop());
        $this->assertSame($item, $evaluation->next_item());

        $this->expectException(coding_exception::class);
        next_item::from_question_id(0);
    }

    /**
     * A CAT model can also answer through a plugin callback, and the resolver routes that too.
     */
    public function test_plugin_callback_is_routed_through_the_resolver(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $withcatmodel = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => 'testcatmodel',
        ]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $withcatmodel->id, 0, false, MUST_EXIST);

        $url = catmodel_resolver::callback('testcatmodel', 'attempts_report_url', $withcatmodel, $cm);
        $this->assertInstanceOf(\moodle_url::class, $url);

        // Without a CAT model, and for a callback nobody offers, the resolver stays silent.
        $this->assertNull(catmodel_resolver::callback(null, 'attempts_report_url', $withcatmodel, $cm));
        $this->assertNull(catmodel_resolver::callback('testcatmodel', 'there_is_no_such_callback'));
    }

    /**
     * The number of attempts links to the report of the CAT model, and stands alone without one.
     */
    public function test_attempts_number_links_to_the_catmodel_report(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $withcatmodel = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => 'testcatmodel',
        ]);
        $without = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);

        $cm = get_coursemodule_from_instance('adaptivequiz', $withcatmodel->id, 0, false, MUST_EXIST);
        $number = attempts_number::when_custom_catmodel_in_use($withcatmodel, $cm);

        $this->assertSame(0, $number->number);
        $this->assertInstanceOf(\moodle_url::class, $number->reporturl);

        $cmwithout = get_coursemodule_from_instance('adaptivequiz', $without->id, 0, false, MUST_EXIST);
        $this->assertNull(attempts_number::when_custom_catmodel_in_use($without, $cmwithout)->reporturl);
    }

    /**
     * Without a CAT model the host uses the built-in item administration.
     */
    public function test_default_item_administration_answers_for_a_plain_activity(): void {
        $this->resetAfterTest();

        $plain = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $this->getDataGenerator()->create_course()->id,
        ]);

        $this->assertInstanceOf(
            default_item_administration_factory::class,
            \mod_adaptivequiz\cat_session::item_administration_factory_for($plain)
        );
    }

    /**
     * With a CAT model the host uses that one instead.
     */
    public function test_catmodel_item_administration_replaces_the_default(): void {
        $this->resetAfterTest();

        $withcatmodel = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $this->getDataGenerator()->create_course()->id,
            'catmodel' => 'testcatmodel',
        ]);

        $factory = \mod_adaptivequiz\cat_session::item_administration_factory_for($withcatmodel);

        $this->assertNotInstanceOf(default_item_administration_factory::class, $factory);
        $this->assertInstanceOf(item_administration_factory::class, $factory);
    }

    /**
     * A configured but uninstalled CAT model produces a controlled error, not a PHP fatal.
     */
    public function test_unknown_catmodel_raises_a_controlled_error(): void {
        $this->expectException(coding_exception::class);
        $this->expectExceptionMessageMatches('/is not installed/');

        catmodel_resolver::handler('thereisnosuchcatmodel', catmodel_add_instance_handler::class);
    }
}
