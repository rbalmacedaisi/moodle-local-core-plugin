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
 * Admin agenda view for psychology appointments (RF-09.3).
 * Filters by psychologist, status and date range. Joins the student's
 * firstname/lastname so the back-office can show who booked without an
 * extra round-trip.
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

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_psychology_manager.php');

class admin_list_psychology_appointments extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'psychologist_userid' => new external_value(PARAM_INT,  '0 = all specialists', VALUE_DEFAULT, 0),
            'status'              => new external_value(PARAM_ALPHA,'pendiente|confirmada|modificada|cancelada|atendida|no_asistio; empty = all', VALUE_DEFAULT, ''),
            'from'                => new external_value(PARAM_INT,  'Start unix ts (inclusive); 0 = now - 7 days', VALUE_DEFAULT, 0),
            'to'                  => new external_value(PARAM_INT,  'End unix ts (inclusive); 0 = now + 60 days', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($psychologistUserid = 0, $status = '', $from = 0, $to = 0) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'psychologist_userid' => $psychologistUserid, 'status' => $status,
            'from' => $from, 'to' => $to,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_psychology_appointments', $context);

        $now = time();
        $from = (int)$params['from'] ?: ($now - 7 * 86400);
        $to   = (int)$params['to']   ?: ($now + 60 * 86400);

        $rows = \local_grupomakro_core\local\wellness_psychology_manager::list_admin(
            (int)$params['psychologist_userid'] ?: null,
            (string)$params['status'],
            $from, $to
        );

        // Join student names in a separate query so the manager stays simple.
        global $DB;
        $userIds = array_values(array_unique(array_filter(array_map(function ($r) { return (int)$r->userid; }, $rows))));
        $students = [];
        if (!empty($userIds)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userIds, SQL_PARAMS_NAMED, 'uid');
            foreach ($DB->get_records_sql(
                "SELECT id, firstname, lastname, email FROM {user} WHERE id $insql", $inparams
            ) as $u) {
                $students[(int)$u->id] = [
                    'firstname' => (string)$u->firstname,
                    'lastname'  => (string)$u->lastname,
                    'email'     => (string)$u->email,
                ];
            }
        }

        $appts = array_values(array_map(function ($r) use ($students) {
            $s = $students[(int)$r->userid] ?? null;
            return [
                'id'                  => (int)$r->id,
                'userid'              => (int)$r->userid,
                'student_firstname'   => (string)($s['firstname'] ?? ''),
                'student_lastname'    => (string)($s['lastname'] ?? ''),
                'student_email'       => (string)($s['email'] ?? ''),
                'slotid'              => (int)$r->slotid,
                'psychologist_userid' => (int)$r->psychologist_userid,
                'psychologist_name'   => (string)$r->psychologist_name,
                'appointment_at'      => (int)$r->appointment_at,
                'duration_minutes'    => (int)$r->duration_minutes,
                'modality'            => (string)$r->modality,
                'reason'              => (string)$r->reason,
                'status'              => (string)$r->status,
                'cancel_reason'       => (string)($r->cancel_reason ?? ''),
                'attendees_notes'     => (string)($r->attendees_notes ?? ''),
                'status_changed_at'  => (int)$r->status_changed_at,
                'status_changed_by'  => (int)$r->status_changed_by,
                'student_notified_at' => (int)$r->student_notified_at,
                'staff_notified_at'   => (int)$r->staff_notified_at,
                'timecreated'         => (int)$r->timecreated,
            ];
        }, $rows));

        return [
            'psychologist_userid' => (int)$params['psychologist_userid'],
            'status'              => (string)$params['status'],
            'from'                => $from,
            'to'                  => $to,
            'appointments'        => $appts,
        ];
    }

    public static function execute_returns() {
        $row = new external_single_structure([
            'id'                  => new external_value(PARAM_INT,  'Appointment id'),
            'userid'              => new external_value(PARAM_INT,  'Student userid'),
            'student_firstname'   => new external_value(PARAM_TEXT,'Student firstname'),
            'student_lastname'    => new external_value(PARAM_TEXT,'Student lastname'),
            'student_email'       => new external_value(PARAM_TEXT,'Student email'),
            'slotid'              => new external_value(PARAM_INT,  'Slot id (0 when rescheduled ad-hoc)'),
            'psychologist_userid' => new external_value(PARAM_INT,  'Specialist userid'),
            'psychologist_name'   => new external_value(PARAM_TEXT,'Specialist display name'),
            'appointment_at'      => new external_value(PARAM_INT,  'Unix ts'),
            'duration_minutes'    => new external_value(PARAM_INT,  'Duration'),
            'modality'            => new external_value(PARAM_TEXT,'presencial|virtual|mixto'),
            'reason'              => new external_value(PARAM_RAW,  'Free-text reason'),
            'status'              => new external_value(PARAM_TEXT,'pendiente|confirmada|modificada|cancelada|atendida|no_asistio'),
            'cancel_reason'       => new external_value(PARAM_RAW,  'Cancel reason'),
            'attendees_notes'     => new external_value(PARAM_RAW,  'Notes from the specialist'),
            'status_changed_at'  => new external_value(PARAM_INT,  'Unix ts'),
            'status_changed_by'  => new external_value(PARAM_INT,  'Userid of the actor'),
            'student_notified_at' => new external_value(PARAM_INT,  'Unix ts'),
            'staff_notified_at'   => new external_value(PARAM_INT,  'Unix ts'),
            'timecreated'         => new external_value(PARAM_INT,  'Unix ts'),
        ]);
        return new external_single_structure([
            'psychologist_userid' => new external_value(PARAM_INT, 'Echoed filter'),
            'status'              => new external_value(PARAM_TEXT,'Echoed filter'),
            'from'                => new external_value(PARAM_INT, 'Echoed range start'),
            'to'                  => new external_value(PARAM_INT, 'Echoed range end'),
            'appointments'        => new external_multiple_structure($row, 'Appointments'),
        ]);
    }
}