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

/**
 * The item administration of the built-in adaptive algorithm.
 *
 * It holds no logic of its own: the built-in algorithm lives in attempt::start_attempt(), which
 * picks the next question and records its slot. This class only puts that behind the same contract
 * a CAT model implements, so the activity has exactly one way of asking for the next item.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class default_item_administration implements item_administration {
    /** @var attempt $attempt The running attempt. */
    private attempt $attempt;

    /**
     * Constructor.
     *
     * @param attempt $attempt The running attempt.
     */
    public function __construct(attempt $attempt) {
        $this->attempt = $attempt;
    }

    /**
     * Lets the built-in algorithm pick the next question, or report why it stops.
     *
     * @param int|null $previousquestionslot Slot of the question answered before, null at the start.
     * @return item_administration_evaluation
     */
    public function evaluate_ability_to_administer_next_item(?int $previousquestionslot): item_administration_evaluation {
        if (empty($this->attempt->start_attempt())) {
            return item_administration_evaluation::with_stoppage_reason($this->attempt->get_status());
        }

        return item_administration_evaluation::with_next_item(
            next_item::from_quba_slot($this->attempt->get_question_slot_number())
        );
    }
}
