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

/**
 * Admin: list the editable wellness staff roster (RF-03, RF-09.3).
 *
 * Capability: local/grupomakro_core:manage_psychology_appointments.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_staff_manager.php');

class admin_list_staff extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_psychology_appointments', $context);

        \local_grupomakro_core\local\wellness_staff_manager::seed_canonical_roles_if_empty();

        $rows = \local_grupomakro_core\local\wellness_staff_manager::list_with_resolved();
        $catalog = [];
        foreach (\local_grupomakro_core\local\wellness_staff_manager::SYSTEM_ROLES as $k => $label) {
            $catalog[] = ['rolekey' => (string)$k, 'label' => (string)$label];
        }
        return [
            'roles'   => $rows,
            'catalog' => $catalog,
        ];
    }

    public static function execute_returns() {
        $role = new external_single_structure([
            'id'                => new external_value(PARAM_INT,  'Row id'),
            'rolekey'           => new external_value(PARAM_TEXT,'rolekey'),
            'role_label'        => new external_value(PARAM_TEXT,'Display label'),
            'userid'            => new external_value(PARAM_INT,  'Linked Moodle userid'),
            'user_fullname'     => new external_value(PARAM_TEXT,'Linked user fullname'),
            'user_email'        => new external_value(PARAM_TEXT,'Linked user email'),
            'user_suspended'    => new external_value(PARAM_BOOL,'Linked user suspended flag'),
            'email_override'    => new external_value(PARAM_TEXT,'Explicit institutional email override'),
            'effective_email'   => new external_value(PARAM_TEXT,'override OR user.email, whichever is non-empty'),
            'notify_on_request' => new external_value(PARAM_INT, '0/1'),
            'notify_on_change'  => new external_value(PARAM_INT, '0/1'),
            'active'            => new external_value(PARAM_INT, '0/1'),
            'usermodified'      => new external_value(PARAM_INT, 'Unix ts'),
            'timecreated'       => new external_value(PARAM_INT, 'Unix ts'),
            'timemodified'      => new external_value(PARAM_INT, 'Unix ts'),
        ]);
        $cat = new external_single_structure([
            'rolekey' => new external_value(PARAM_TEXT,'Reserved rolekey'),
            'label'   => new external_value(PARAM_TEXT,'Default label for the rolekey'),
        ]);
        return new external_single_structure([
            'roles'   => new external_multiple_structure($role, 'Staff roster'),
            'catalog' => new external_multiple_structure($cat, 'Reserved rolekey catalogue'),
        ]);
    }
}