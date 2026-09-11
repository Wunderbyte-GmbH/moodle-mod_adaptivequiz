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

namespace adaptivequizcatmodel_testcatmodel\local\catmodel\form;

use MoodleQuickForm;
use mod_adaptivequiz\local\catmodel\form\catmodel_mod_form_data_preprocessor;
use mod_adaptivequiz\local\catmodel\form\catmodel_mod_form_modifier;
use mod_adaptivequiz\local\catmodel\form\catmodel_mod_form_validator;

/**
 * Minimal implementation of the form extension points of a CAT model.
 *
 * Adds one field, sets one default and rejects one value, which is enough to show that each of
 * the three form hooks reaches the subplugin and that its return value is used.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_form_handler implements catmodel_mod_form_data_preprocessor, catmodel_mod_form_modifier, catmodel_mod_form_validator {
    /** @var string Name of the field this CAT model adds to the activity form. */
    public const FIELD = 'testcatmodelfield';

    /** @var string The one value the validator refuses. */
    public const REJECTED_VALUE = 'refuse-me';

    /**
     * Adds the CAT model field to the activity form.
     *
     * @param MoodleQuickForm $form The activity form being built.
     * @return array The elements added, so the host can place them.
     */
    public function definition_after_data_callback(MoodleQuickForm $form): array {
        $element = $form->createElement('text', self::FIELD, 'Test CAT model field');
        $form->addElement($element);

        return [$element];
    }

    /**
     * Refuses exactly one value, so the host can show that validation errors are passed through.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Validation errors keyed by field name.
     */
    public function validation_callback(array $data, array $files): array {
        if (($data[self::FIELD] ?? '') === self::REJECTED_VALUE) {
            return [self::FIELD => 'The test CAT model refuses this value.'];
        }

        return [];
    }

    /**
     * Sets the default of the CAT model field.
     *
     * @param array $formdefaultvalues Default values collected so far.
     * @return array Modified form values.
     */
    public function data_preprocessing_callback(array $formdefaultvalues): array {
        $formdefaultvalues[self::FIELD] = 'default from the test CAT model';

        return $formdefaultvalues;
    }
}
