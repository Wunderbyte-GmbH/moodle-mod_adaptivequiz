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

namespace adaptivequizcatmodel_catquiz\local\catmodel\itemadministration;

use local_catquiz\catquiz_handler;
use mod_adaptivequiz\event\alise_debug_event;
use mod_adaptivequiz\local\attempt\attempt;
use mod_adaptivequiz\local\itemadministration\item_administration;
use mod_adaptivequiz\local\itemadministration\item_administration_evaluation;
use mod_adaptivequiz\local\itemadministration\next_item;
use mod_adaptivequiz\local\question\question_answer_evaluation;
use mod_adaptivequiz\local\question\question_answer_evaluation_result;
use question_bank;
use question_engine;
use question_usage_by_activity;
use stdClass;

/**
 * Contains implementations of the item administration interface.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2023 Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catquiz_item_administration implements item_administration {

    /**
     * @var question_usage_by_activity $quba
     */
    private $quba;

    /**
     * @var question_answer_evaluation
     */
    private $questionanswerevaluation;

    /**
     * @var stdClass $adaptivequiz
     */
    private $adaptivequiz;

    private attempt $attempt;

    /**
     * The constructor.
     *
     * @param question_usage_by_activity $quba
     * @param stdClass $adaptivequiz
     */
    public function __construct(
        question_usage_by_activity $quba,
        question_answer_evaluation $questionanswerevaluation,
        stdClass $adaptivequiz,
        attempt $attempt) {
        $this->quba = $quba;
        $this->questionanswerevaluation = $questionanswerevaluation;
        $this->adaptivequiz = $adaptivequiz;
        $this->attempt = $attempt;
    }

    /**
     * Implements the interface.
     *
     * The example logic is to stop the attempt if the question is answered incorrectly, in case of a correct answer just fetch
     * any random question from the configured pool.
     *
     * @param int|null $previousquestionslot
     * @return item_administration_evaluation
     */
    public function evaluate_ability_to_administer_next_item(?int $previousquestionslot): item_administration_evaluation {
        [$questionid, $errormessage] = catquiz_handler::fetch_question_id(
            $this->adaptivequiz->id,
            'mod_adaptivequiz',
            $this->attempt->read_attempt_data()
        );
        // This means no answer has been given yet, it's a fresh attempt.
        if (is_null($previousquestionslot)) {
            alise_debug_event::log(
                $this->attempt->read_attempt_data()->id,
                sprintf('Previous question slot is null')
            );
            if ($questionid === 0) {
                alise_debug_event::log(
                    $this->attempt->read_attempt_data()->id,
                    sprintf('Stopping because questionid is 0')
                );
                return item_administration_evaluation::with_stoppage_reason(
                    $errormessage
                );
            }


            alise_debug_event::log(
                $this->attempt->read_attempt_data()->id,
                sprintf('Fetched question with ID %d', $questionid)
            );
            return item_administration_evaluation::with_next_item(next_item::from_question_id($questionid));
        }

        if ($questionid === 0) {
            return item_administration_evaluation::with_stoppage_reason(
                $errormessage
            );
        }

        return item_administration_evaluation::with_next_item(next_item::from_question_id($questionid));
    }
}
