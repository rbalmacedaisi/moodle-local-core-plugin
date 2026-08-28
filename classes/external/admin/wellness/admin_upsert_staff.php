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
 * Admin: upsert a wellness staff role (RF-03, RF-09.3).
 * Records every change into gmk_wellness_staff_audit so RR.HH. can see
 * who swapped Dulce for someone else and when.
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
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_staff_manager.php');

class admin_upsert_staff extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'rolekey'           => new external_value(PARAM_TEXT, 'psicologo_titular|psicologo_suplente|talento_humano|bienestar_jefe|bienestar_asistente', VALUE_REQUIRED),
            'role_label'        => new external_value(PARAM_TEXT, 'Display label', VALUE_DEFAULT, ''),
            'userid'            => new external_value(PARAM_INT,  'Linked Moodle userid (0 to clear)', VALUE_DEFAULT, 0),
            'email_override'    => new external_value(PARAM_TEXT, 'Explicit email override; empty = use user.email', VALUE_DEFAULT, ''),
            'notify_on_request' => new external_value(PARAM_BOOL, 'Receive notifications on new appointments', VALUE_DEFAULT, true),
            'notify_on_change'  => new external_value(PARAM_BOOL, 'Receive notifications on status changes', VALUE_DEFAULT, true),
        ]);
    }

    public static function execute(
        $rolekey, $roleLabel = '', $userid = 0, $emailOverride = '',
        $notifyOnRequest = true, $notifyOnChange = true
    ) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'rolekey' => $rolekey, 'role_label' => $roleLabel,
            'userid' => $userid, 'email_override' => $emailOverride,
            'notify_on_request' => $notifyOnRequest, 'notify_on_change' => $notifyOnChange,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_psychology_appointments', $context);

        try {
            $id = \local_grupomakro_core\local\wellness_staff_manager::upsert(
                (string)$params['rolekey'],
                (string)$params['role_label'],
                (int)$params['userid'],
                (string)$params['email_override'],
                (bool)$params['notify_on_request'],
                (bool)$params['notify_on_change'],
                (int)$USER->id
            );
        } catch (\moodle_exception $e) {
            throw new Exception($e->getMessage());
        }
        return ['ok' => true, 'id' => (int)$id];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True on success'),
            'id' => new external_value(PARAM_INT,  'Row id'),
        ]);
    }
}