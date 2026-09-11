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

/**
 * Stops the attempt on the first evaluation, with a recognisable reason.
 *
 * The point is not the decision but that the host asked a CAT model for it at all.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stop_immediately_administration implements item_administration {
    /** @var string The reason this administration always reports. */
    public const REASON = 'the test CAT model stops here';

    /**
     * Always reports that no further item is to be administered.
     *
     * @param int|null $previousquestionslot Slot of the question answered before, null at the start.
     * @return item_administration_evaluation
     */
    public function evaluate_ability_to_administer_next_item(?int $previousquestionslot): item_administration_evaluation {
        return item_administration_evaluation::with_stoppage_reason(self::REASON);
    }
}
