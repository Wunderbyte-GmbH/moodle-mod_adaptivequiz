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

use mod_adaptivequiz\local\itemadministration\item_administration;
use mod_adaptivequiz\local\itemadministration\item_administration_evaluation;
use mod_adaptivequiz\local\itemadministration\next_item;

/**
 * Always names the same question, the way a CAT model answers: by question id, not by slot.
 *
 * The point is not the choice but that the host has to put the named question into the usage - and
 * has to notice when a slot for it already exists.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class serve_fixed_question_administration implements item_administration {
    /** @var int|null Question this administration names, set by the test before the run. */
    public static ?int $questionid = null;

    /** @var int|null Slot this administration answers with instead, the way a CAT model may. */
    public static ?int $slot = null;

    /**
     * Names the configured question.
     *
     * @param int|null $previousquestionslot Slot of the question answered before, null at the start.
     * @return item_administration_evaluation
     */
    public function evaluate_ability_to_administer_next_item(?int $previousquestionslot): item_administration_evaluation {
        if (self::$slot !== null) {
            return item_administration_evaluation::with_next_item(next_item::from_quba_slot(self::$slot));
        }

        return item_administration_evaluation::with_next_item(next_item::from_question_id(self::$questionid));
    }
}
