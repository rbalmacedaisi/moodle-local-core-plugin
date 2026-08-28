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
 * Returns the calling student's psychology appointments, newest first.
 * Capability: local/grupomakro_core:view_wellness.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_psychology_manager.php');

class get_my_psychology_appointments extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $rows = \local_grupomakro_core\local\wellness_psychology_manager::list_for_student((int)$USER->id);
        return [
            'appointments' => array_values(array_map(function ($r) {
                return [
                    'id'                  => (int)$r->id,
                    'status'              => (string)$r->status,
                    'appointment_at'      => (int)$r->appointment_at,
                    'duration_minutes'    => (int)$r->duration_minutes,
                    'modality'            => (string)$r->modality,
                    'reason'              => (string)$r->reason,
                    'cancel_reason'       => (string)($r->cancel_reason ?? ''),
                    'attendees_notes'     => (string)($r->attendees_notes ?? ''),
                    'psychologist_userid' => (int)$r->psychologist_userid,
                    'psychologist_name'   => (string)$r->psychologist_name,
                    'slotid'              => (int)$r->slotid,
                    'timecreated'         => (int)$r->timecreated,
                ];
            }, $rows)),
        ];
    }

    public static function execute_returns() {
        $row = new external_single_structure([
            'id'                  => new external_value(PARAM_INT,  'Appointment id'),
            'status'              => new external_value(PARAM_TEXT,'pendiente|confirmada|modificada|cancelada|atendida|no_asistio'),
            'appointment_at'      => new external_value(PARAM_INT,  'Unix ts'),
            'duration_minutes'    => new external_value(PARAM_INT,  'Duration'),
            'modality'            => new external_value(PARAM_TEXT,'presencial|virtual|mixto'),
            'reason'              => new external_value(PARAM_RAW,  'Free-text reason'),
            'cancel_reason'       => new external_value(PARAM_RAW,  'Cancel reason when status=cancelada'),
            'attendees_notes'     => new external_value(PARAM_RAW,  'Notes added by the specialist after atendida'),
            'psychologist_userid' => new external_value(PARAM_INT,  'Specialist userid'),
            'psychologist_name'   => new external_value(PARAM_TEXT,'Specialist display name'),
            'slotid'              => new external_value(PARAM_INT,  'Slot id (0 when rescheduled ad-hoc)'),
            'timecreated'         => new external_value(PARAM_INT,  'Unix ts'),
        ]);
        return new external_single_structure([
            'appointments' => new external_multiple_structure($row, 'Calling student appointments'),
        ]);
    }
}