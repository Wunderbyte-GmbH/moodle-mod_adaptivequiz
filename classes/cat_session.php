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

namespace mod_adaptivequiz;

use core\lock\lock_config;
use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\local\catmodel\catmodel_resolver;
use mod_adaptivequiz\local\itemadministration\default_item_administration_factory;
use mod_adaptivequiz\local\itemadministration\item_administration_evaluation;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;
use question_bank;
use question_engine;
use question_usage_by_activity;
use stdClass;

/**
 * The parts of a running CAT session that must not live inside attempt.php.
 *
 * attempt.php is a script: it cannot be instantiated in a test, so anything decided there is
 * decided untested. Whatever can be answered without the request context belongs here instead.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cat_session {
    /**
     * Returns the item administration factory that drives the given activity.
     *
     * The CAT model of the activity if it offers one, the built-in algorithm otherwise. This is
     * the single place where the activity decides who picks the next question.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @return item_administration_factory
     */
    public static function item_administration_factory_for(stdClass $adaptivequiz): item_administration_factory {
        $factory = catmodel_resolver::handler($adaptivequiz->catmodel ?? null, item_administration_factory::class);

        return $factory ?? new default_item_administration_factory();
    }

    /**
     * Administers the next item of a running attempt, whoever picks it.
     *
     * The whole step runs under a lock keyed on activity and user. Without it a double click or a
     * concurrent AJAX request can have two requests pick a question at the same time, each adding
     * its own slot for the same item. A CAT attempt only ever has one active unanswered slot.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @param attempt $attempt The running attempt, with its question usage already initialised.
     * @return item_administration_evaluation|null The evaluation, or null when a concurrent
     *      request is already administering an item for this attempt.
     */
    public static function administer_next_item(stdClass $adaptivequiz, attempt $attempt): ?item_administration_evaluation {
        global $USER;

        $lock = lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $adaptivequiz->id . '_' . $USER->id, 10);

        if (!$lock) {
            return null;
        }

        try {
            return self::administer_next_item_locked($adaptivequiz, $attempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * Does the work of administer_next_item(), with the lock already held.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @param attempt $attempt The running attempt.
     * @return item_administration_evaluation
     */
    private static function administer_next_item_locked(
        stdClass $adaptivequiz,
        attempt $attempt
    ): item_administration_evaluation {
        $quba = $attempt->get_quba();
        $slots = $quba->get_slots();

        $administration = self::item_administration_factory_for($adaptivequiz)
            ->item_administration_implementation($quba, $attempt, $adaptivequiz);

        $evaluation = $administration->evaluate_ability_to_administer_next_item(
            !empty($slots) ? end($slots) : null
        );

        if ($evaluation->item_administration_is_to_stop()) {
            return $evaluation;
        }

        $slot = $evaluation->next_item()->quba_slot();

        if ($slot !== null) {
            // The built-in algorithm has put the question into the usage itself and answers with
            // its slot. The number still has to be written back: attempt.php reads it from the
            // attempt to render the question.
            $attempt->set_question_slot_number($slot);

            return $evaluation;
        }

        // A CAT model only names the question - the usage belongs to the host, so the host puts it in.
        $questionid = $evaluation->next_item()->question_id();

        // On a reload the CAT model may name a different question than the one already sitting in
        // the active, unanswered slot. Reusing that slot is the intended outcome: appending a new
        // one would make the visible question number grow with every reload.
        $existing = self::active_slot_for_question($quba, $questionid) ?? self::any_active_slot($quba);

        if ($existing !== null) {
            $attempt->set_question_slot_number($existing);

            return $evaluation;
        }

        $slot = $quba->add_question(question_bank::load_question($questionid));

        if (!$quba->get_question_state($slot)->is_active()) {
            $quba->start_question($slot);
            question_engine::save_questions_usage_by_activity($quba);

            if (count($quba->get_slots()) == 1) {
                $attempt->set_quba_id($quba->get_id());
            }
        }

        $attempt->set_question_slot_number($slot);

        return $evaluation;
    }

    /**
     * Returns the active slot holding the given question, if there is one.
     *
     * @param question_usage_by_activity $quba The question usage of the attempt.
     * @param int $questionid Id of the question to look for.
     * @return int|null
     */
    private static function active_slot_for_question(question_usage_by_activity $quba, int $questionid): ?int {
        foreach ($quba->get_slots() as $slot) {
            if ((int) $quba->get_question($slot)->id === $questionid && $quba->get_question_state($slot)->is_active()) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Returns any active slot of the usage, if there is one.
     *
     * @param question_usage_by_activity $quba The question usage of the attempt.
     * @return int|null
     */
    private static function any_active_slot(question_usage_by_activity $quba): ?int {
        foreach ($quba->get_slots() as $slot) {
            if ($quba->get_question_state($slot)->is_active()) {
                return $slot;
            }
        }

        return null;
    }
}
