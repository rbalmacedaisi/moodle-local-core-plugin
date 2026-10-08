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
 * Apply a status transition to a psychology appointment from the admin panel.
 * - confirma/modifica → email to the student (RF-09.3).
 * - cancela → emails to the student AND the staff.
 * - atendida / no_asistio → silent (closes the cycle).
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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_psychology_manager.php');

class admin_update_psychology_appointment extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'appointmentid'        => new external_value(PARAM_INT,  'Appointment id', VALUE_REQUIRED),
            'status'               => new external_value(PARAM_ALPHA,'pendiente|confirmada|modificada|cancelada|atendida|no_asistio', VALUE_REQUIRED),
            'cancel_reason'        => new external_value(PARAM_RAW,  'Cancel reason (used when status=cancelada)', VALUE_DEFAULT, ''),
            'attendees_notes'      => new external_value(PARAM_RAW,  'Notes added by the specialist', VALUE_DEFAULT, ''),
            'new_appointment_at'   => new external_value(PARAM_INT,  'New unix ts (only when status=modificada)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($appointmentid, $status, $cancelReason = '', $attendeesNotes = '', $newAppointmentAt = 0) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'appointmentid'        => $appointmentid,
            'status'               => $status,
            'cancel_reason'        => $cancelReason,
            'attendees_notes'      => $attendeesNotes,
            'new_appointment_at'   => $newAppointmentAt,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_psychology_appointments', $context);

        return \local_grupomakro_core\local\wellness_psychology_manager::admin_update_status(
            (int)$params['appointmentid'],
            (string)$params['status'],
            (int)$USER->id,
            (string)$params['cancel_reason'],
            (string)$params['attendees_notes'],
            (int)$params['new_appointment_at']
        );
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'    => new external_value(PARAM_BOOL, 'True when the transition succeeded'),
            'noop'  => new external_value(PARAM_BOOL, 'True when the status did not change'),
            'error' => new external_value(PARAM_TEXT, 'Error code when ok=false'),
        ]);
    }
}