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

use help_icon;
use mod_adaptivequiz\external\ability_measure_exporter;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * A class to display a table with user's own attempts on the activity's view page.
 *
 * @package    mod_adaptivequiz
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ability_measure implements renderable, templatable {
    /**
     * @var stdClass $adaptivequiz
     */
    private $adaptivequiz;

    /**
     * @var stdClass $attempt
     */
    private $attempt;

    /**
     * The constructor.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt
     */
    public function __construct(stdClass $adaptivequiz, stdClass $attempt) {
        $this->adaptivequiz = $adaptivequiz;
        $this->attempt = $attempt;
    }

    /**
     * Implements the interface.
     *
     * @param renderer_base $output
     * @return \stdClass|array
     */
    public function export_for_template(renderer_base $output) {
        $abilitymeasure = (array) (new ability_measure_exporter([
            'highestlevel' => $this->adaptivequiz->highestlevel,
            'lowestlevel' => $this->adaptivequiz->lowestlevel,
        ], [
            'attempt' => $this->attempt,
        ]))
            ->export($output);

        return array_merge($abilitymeasure, [
            'helpicon' => $output->render(new help_icon('abilityestimated', 'adaptivequiz')),
        ]);
    }
}
