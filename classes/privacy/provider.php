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

namespace mod_adaptivequiz\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use question_display_options;
use stdClass;

/**
 * Privacy API implementation of the adaptive quiz activity module.
 *
 * The module stores one row per attempt in adaptivequiz_attempt, holding the ability estimate and
 * the state of that attempt, and it points at a question usage that carries the answers. The
 * answers themselves belong to the question subsystem, which exports and deletes them on request.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Declares what personal data the module stores.
     *
     * @param collection $collection The collection to add the items to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('adaptivequiz_attempt', [
            'userid' => 'privacy:metadata:adaptivequiz_attempt:userid',
            'uniqueid' => 'privacy:metadata:adaptivequiz_attempt:uniqueid',
            'attemptstate' => 'privacy:metadata:adaptivequiz_attempt:attemptstate',
            'attemptstopcriteria' => 'privacy:metadata:adaptivequiz_attempt:attemptstopcriteria',
            'questionsattempted' => 'privacy:metadata:adaptivequiz_attempt:questionsattempted',
            'difficultysum' => 'privacy:metadata:adaptivequiz_attempt:difficultysum',
            'standarderror' => 'privacy:metadata:adaptivequiz_attempt:standarderror',
            'measure' => 'privacy:metadata:adaptivequiz_attempt:measure',
            'timecreated' => 'privacy:metadata:adaptivequiz_attempt:timecreated',
            'timemodified' => 'privacy:metadata:adaptivequiz_attempt:timemodified',
        ], 'privacy:metadata:adaptivequiz_attempt');

        // The answers given during an attempt live in the question subsystem.
        $collection->add_subsystem_link('core_question', [], 'privacy:metadata:core_question');

        // The grade of an attempt is written to the gradebook.
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');

        // Report settings a teacher chose for themselves.
        $collection->add_user_preference(
            'adaptivequiz_users_attempts_report',
            'privacy:metadata:preference:usersattemptsreport'
        );
        $collection->add_user_preference(
            'mod_adaptivequiz_answers_distribution_chart_settings',
            'privacy:metadata:preference:answersdistributionchart'
        );

        return $collection;
    }

    /**
     * Returns the contexts in which the given user has data.
     *
     * @param int $userid The user to look for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $base = "FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {adaptivequiz} a ON a.id = cm.instance
                  JOIN {adaptivequiz_attempt} aa ON aa.instance = a.id";
        $baseparams = ['contextlevel' => CONTEXT_MODULE, 'modname' => 'adaptivequiz'];

        // Activities the user has an attempt in.
        $contextlist->add_from_sql("SELECT c.id $base WHERE aa.userid = :userid", $baseparams + ['userid' => $userid]);

        // Activities where the user is related to a question usage of an attempt without owning
        // the attempt - a manual marker, for instance.
        $qubaid = \core_question\privacy\provider::get_related_question_usages_for_user(
            'rel',
            'mod_adaptivequiz',
            'aa.uniqueid',
            $userid
        );
        $contextlist->add_from_sql(
            "SELECT c.id $base " . $qubaid->from . " WHERE " . $qubaid->where(),
            $baseparams + $qubaid->from_where_params()
        );

        return $contextlist;
    }

    /**
     * Returns the users who have data in the given context.
     *
     * @param userlist $userlist The userlist to add the users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $userlist->add_from_sql('userid', "SELECT aa.userid
                                             FROM {course_modules} cm
                                             JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                                             JOIN {adaptivequiz} a ON a.id = cm.instance
                                             JOIN {adaptivequiz_attempt} aa ON aa.instance = a.id
                                            WHERE cm.id = :cmid", [
            'modname' => 'adaptivequiz',
            'cmid' => $context->instanceid,
        ]);

        // Users related to a question usage of an attempt in this activity, for instance a manual marker.
        \core_question\privacy\provider::get_users_in_context_from_sql(
            $userlist,
            'rel',
            "SELECT aa.uniqueid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {adaptivequiz} a ON a.id = cm.instance
               JOIN {adaptivequiz_attempt} aa ON aa.instance = a.id
              WHERE cm.id = :cmid",
            ['modname' => 'adaptivequiz', 'cmid' => $context->instanceid]
        );
    }

    /**
     * Exports the data of the approved contexts for the given user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('adaptivequiz', $context->instanceid);
            if ($cm === false) {
                continue;
            }

            $attempts = $DB->get_records(
                'adaptivequiz_attempt',
                ['instance' => $cm->instance, 'userid' => $user->id],
                'timecreated ASC'
            );
            if (empty($attempts)) {
                continue;
            }

            // The activity itself, so the export carries the name and intro of what was attempted.
            writer::with_context($context)->export_data([], helper::get_context_data($context, $user));
            helper::export_context_files($context, $user);

            $index = 0;
            foreach ($attempts as $attempt) {
                $index++;
                $subcontext = [get_string('privacy:attemptpath', 'adaptivequiz', $index)];

                writer::with_context($context)->export_data($subcontext, self::attempt_export_data($attempt));

                // The answers of the attempt belong to the question subsystem. An attempt whose
                // usage has already been removed still exports its own row.
                if (!$DB->record_exists('question_usages', ['id' => $attempt->uniqueid])) {
                    continue;
                }

                \core_question\privacy\provider::export_question_usage(
                    $user->id,
                    $context,
                    array_merge($subcontext, [get_string('questions', 'core_question')]),
                    (int) $attempt->uniqueid,
                    self::question_display_options(),
                    true
                );
            }
        }
    }

    /**
     * Deletes the data of all users in the given context.
     *
     * @param context $context The context to purge.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('adaptivequiz', $context->instanceid);
        if ($cm === false) {
            return;
        }

        $usages = $DB->get_fieldset_select('adaptivequiz_attempt', 'uniqueid', 'instance = :instance', [
            'instance' => $cm->instance,
        ]);
        self::delete_question_usages($usages);

        $DB->delete_records('adaptivequiz_attempt', ['instance' => $cm->instance]);
    }

    /**
     * Deletes the data of the given user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('adaptivequiz', $context->instanceid);
            if ($cm === false) {
                continue;
            }

            $conditions = ['instance' => $cm->instance, 'userid' => $userid];
            $usages = $DB->get_fieldset_select(
                'adaptivequiz_attempt',
                'uniqueid',
                'instance = :instance AND userid = :userid',
                $conditions
            );
            self::delete_question_usages($usages);

            $DB->delete_records('adaptivequiz_attempt', $conditions);
        }
    }

    /**
     * Deletes the data of the given users in the given context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('adaptivequiz', $context->instanceid);
        if ($cm === false) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['instance' => $cm->instance] + $inparams;

        $usages = $DB->get_fieldset_select(
            'adaptivequiz_attempt',
            'uniqueid',
            "instance = :instance AND userid $insql",
            $params
        );
        self::delete_question_usages($usages);

        $DB->delete_records_select('adaptivequiz_attempt', "instance = :instance AND userid $insql", $params);
    }

    /**
     * Returns the fields of an attempt that are exported, with times formatted.
     *
     * @param stdClass $attempt The attempt record.
     * @return stdClass
     */
    private static function attempt_export_data(stdClass $attempt): stdClass {
        return (object) [
            'attemptstate' => $attempt->attemptstate,
            'attemptstopcriteria' => $attempt->attemptstopcriteria,
            'questionsattempted' => $attempt->questionsattempted,
            'difficultysum' => $attempt->difficultysum,
            'standarderror' => $attempt->standarderror,
            'measure' => $attempt->measure,
            'timecreated' => transform::datetime($attempt->timecreated),
            'timemodified' => transform::datetime($attempt->timemodified),
        ];
    }

    /**
     * Removes the given question usages through the question subsystem.
     *
     * @param array $usages Ids of the question usages.
     */
    private static function delete_question_usages(array $usages): void {
        if (empty($usages)) {
            return;
        }

        \question_engine::delete_questions_usage_by_activities(new \qubaid_list($usages));
    }

    /**
     * Returns the display options used when exporting the answers of an attempt.
     *
     * The export shows the attempt as it was, including the marks, because the owner is entitled
     * to their own data in full.
     *
     * @return question_display_options
     */
    private static function question_display_options(): question_display_options {
        $options = new question_display_options();
        $options->readonly = true;
        $options->flags = question_display_options::HIDDEN;
        $options->manualcomment = question_display_options::VISIBLE;

        return $options;
    }
}
