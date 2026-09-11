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

namespace mod_adaptivequiz\output;

use mod_adaptivequiz\local\catmodel\catmodel_resolver;
use moodle_url;
use renderable;
use stdClass;

/**
 * The number of attempts on an activity, with a link to the report of the CAT model if there is one.
 *
 * An activity driven by a CAT model does not show the built-in attempts report - the numbers there
 * come from the built-in algorithm and would not match. It shows the number of attempts instead,
 * and turns that number into a link when the CAT model offers a report of its own. Without that
 * link a teacher would have no way to reach an attempts overview at all, and with it no way to
 * close an attempt.
 *
 * @package    mod_adaptivequiz
 * @copyright  2024 Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempts_number implements renderable {
    /** @var int $number How many attempts the activity has. */
    public int $number;

    /** @var moodle_url|null $reporturl Report of the CAT model, null when there is none. */
    public ?moodle_url $reporturl;

    /**
     * Builds the object for an activity that is driven by a CAT model.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @param stdClass $cm The course module record of that activity.
     * @return self
     */
    public static function when_custom_catmodel_in_use(stdClass $adaptivequiz, stdClass $cm): self {
        global $DB;

        $attemptsnumber = new self();
        $attemptsnumber->number = $DB->count_records('adaptivequiz_attempt', ['instance' => $adaptivequiz->id]);
        $attemptsnumber->reporturl = catmodel_resolver::callback(
            $adaptivequiz->catmodel ?? null,
            'attempts_report_url',
            $adaptivequiz,
            $cm
        );

        return $attemptsnumber;
    }
}
