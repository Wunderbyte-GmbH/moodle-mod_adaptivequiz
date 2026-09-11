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

namespace adaptivequizcatmodel_testcatmodel\local\itemadministration;

use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\local\itemadministration\item_administration;
use mod_adaptivequiz\local\itemadministration\item_administration_factory as host_factory;
use question_usage_by_activity;
use stdClass;

/**
 * Hands out the item administration of the test CAT model.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_administration_factory implements host_factory {
    /**
     * Returns the item administration to run for this attempt.
     *
     * @param question_usage_by_activity $quba The question usage of the attempt.
     * @param attempt $attempt The attempt being administered.
     * @param stdClass $adaptivequiz The activity instance record.
     * @return item_administration
     */
    public function item_administration_implementation(
        question_usage_by_activity $quba,
        attempt $attempt,
        stdClass $adaptivequiz
    ): item_administration {
        if (
            serve_fixed_question_administration::$questionid !== null
            || serve_fixed_question_administration::$slot !== null
        ) {
            return new serve_fixed_question_administration();
        }

        return new stop_immediately_administration();
    }
}
