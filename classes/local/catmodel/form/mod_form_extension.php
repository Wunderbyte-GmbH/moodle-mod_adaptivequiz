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

namespace mod_adaptivequiz\local\catmodel\form;

use MoodleQuickForm;
use mod_adaptivequiz\local\catmodel\catmodel_resolver;

/**
 * Applies the form extension points of a CAT model to the activity form.
 *
 * The activity form itself is awkward to instantiate outside a request, so the logic lives here
 * where it can be tested against a plain MoodleQuickForm. mod_adaptivequiz_mod_form does nothing
 * but forward to these three methods.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mod_form_extension {
    /** @var string[] Fields of the built-in algorithm that a CAT model supersedes. */
    private const SUPERSEDED_FIELDS = [
        'startinglevel',
        'lowestlevel',
        'highestlevel',
        'stopingconditionshdr',
        'minimumquestions',
        'maximumquestions',
        'standarderror',
        'showabilitymeasure',
        'showattemptprogress',
    ];

    /**
     * Lets the CAT model selected in the form add and remove fields.
     *
     * Does nothing when the form has no CAT model chooser or none is selected.
     *
     * @param MoodleQuickForm $form The activity form being built.
     */
    public static function apply(MoodleQuickForm $form): void {
        if (!$form->elementExists('catmodel')) {
            return;
        }

        $selected = $form->getElementValue('catmodel');
        $catmodel = is_array($selected) ? reset($selected) : $selected;

        $modifier = catmodel_resolver::handler($catmodel, catmodel_mod_form_modifier::class);
        if ($modifier === null) {
            return;
        }

        // The settings of the built-in algorithm no longer apply once a CAT model drives the instance.
        foreach (self::SUPERSEDED_FIELDS as $elementname) {
            if ($form->elementExists($elementname)) {
                $form->removeElement($elementname);
            }
        }

        foreach ($modifier->definition_after_data_callback($form) as $element) {
            $form->insertElementBefore($form->removeElement($element->getName(), false), 'catmodelfieldsmarker');
        }
    }

    /**
     * Adds the validation errors the CAT model reports, if it validates at all.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Validation errors keyed by field name, empty when nothing is delegated.
     */
    public static function validate(array $data, array $files): array {
        $validator = catmodel_resolver::handler($data['catmodel'] ?? null, catmodel_mod_form_validator::class);

        return $validator === null ? [] : $validator->validation_callback($data, $files);
    }

    /**
     * Lets the CAT model set the defaults of its own fields.
     *
     * @param array $defaultvalues Default values collected so far.
     * @return array The values to use.
     */
    public static function preprocess(array $defaultvalues): array {
        $preprocessor = catmodel_resolver::handler(
            $defaultvalues['catmodel'] ?? null,
            catmodel_mod_form_data_preprocessor::class
        );

        return $preprocessor === null ? $defaultvalues : $preprocessor->data_preprocessing_callback($defaultvalues);
    }
}
