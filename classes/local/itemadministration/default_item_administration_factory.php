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

namespace mod_adaptivequiz\local\itemadministration;

use mod_adaptivequiz\local\attempt;
use question_usage_by_activity;
use stdClass;

/**
 * Hands out the item administration of the built-in adaptive algorithm.
 *
 * Used whenever an activity has no CAT model configured.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class default_item_administration_factory implements item_administration_factory {
    /**
     * Returns the item administration of the built-in algorithm.
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
        return new default_item_administration($attempt);
    }
}
