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

namespace adaptivequizcatmodel_testcatmodel\local\catmodel\instance;

use mod_adaptivequiz\local\catmodel\instance\catmodel_add_instance_handler;
use mod_adaptivequiz\local\catmodel\instance\catmodel_delete_instance_handler;
use mod_adaptivequiz\local\catmodel\instance\catmodel_update_instance_handler;
use mod_adaptivequiz_mod_form;
use stdClass;

/**
 * Records the activity lifecycle calls the host delegates to a CAT model.
 *
 * The handler holds no CAT logic of its own. It exists so the host can prove that its extension
 * points fire, with the right arguments, without installing a real CAT model.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance_handler implements
    catmodel_add_instance_handler,
    catmodel_delete_instance_handler,
    catmodel_update_instance_handler {
    /** @var array[] Calls recorded so far, each entry ['callback' => string, 'instance' => stdClass]. */
    public static array $calls = [];

    /**
     * Forgets everything recorded so far.
     */
    public static function reset(): void {
        self::$calls = [];
    }

    /**
     * Returns the names of the callbacks recorded so far, in call order.
     *
     * @return string[]
     */
    public static function called(): array {
        return array_column(self::$calls, 'callback');
    }

    /**
     * Records that the host created an activity instance.
     *
     * @param stdClass $adaptivequiz Submitted instance data from mod_form.
     * @param mod_adaptivequiz_mod_form|null $form The form the data came from, optional.
     */
    public function add_instance_callback(stdClass $adaptivequiz, ?mod_adaptivequiz_mod_form $form = null): void {
        self::$calls[] = ['callback' => 'add_instance_callback', 'instance' => $adaptivequiz];
    }

    /**
     * Records that the host updated an activity instance.
     *
     * @param stdClass $adaptivequiz Submitted instance data from mod_form.
     * @param mod_adaptivequiz_mod_form|null $form The form the data came from, optional.
     */
    public function update_instance_callback(stdClass $adaptivequiz, ?mod_adaptivequiz_mod_form $form = null): void {
        self::$calls[] = ['callback' => 'update_instance_callback', 'instance' => $adaptivequiz];
    }

    /**
     * Records that the host deleted an activity instance.
     *
     * @param stdClass $adaptivequiz The instance record being deleted.
     */
    public function delete_instance_callback(stdClass $adaptivequiz): void {
        self::$calls[] = ['callback' => 'delete_instance_callback', 'instance' => $adaptivequiz];
    }
}
