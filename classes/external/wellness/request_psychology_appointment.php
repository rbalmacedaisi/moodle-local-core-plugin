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
 * Books a psychology appointment for the calling student. Fires the
 * staff + student notification emails (RF-03.3, RF-03.4).
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
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_psychology_manager.php');

class request_psychology_appointment extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'slotid'            => new external_value(PARAM_INT,  'Slot id to book', VALUE_REQUIRED),
            'occurrence_start'  => new external_value(PARAM_INT,  'Unix ts of the slot occurrence', VALUE_REQUIRED),
            'reason'            => new external_value(PARAM_RAW,  'Free-text reason', VALUE_REQUIRED),
            'modality'          => new external_value(PARAM_ALPHA,'presencial|virtual|mixto (only when slot.modality=mixto)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($slotid, $occurrenceStart, $reason, $modality = '') {
        $params = self::validate_parameters(self::execute_parameters(), [
            'slotid' => $slotid,
            'occurrence_start' => $occurrenceStart,
            'reason' => $reason,
            'modality' => $modality,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $r = \local_grupomakro_core\local\wellness_psychology_manager::request_appointment(
            (int)$params['slotid'],
            (int)$params['occurrence_start'],
            (string)$params['reason'],
            (string)$params['modality']
        );

        if (!empty($r['ok']) && !empty($r['appointment'])) {
            $a = $r['appointment'];
            $r['appointment'] = [
                'id'                => (int)$a->id,
                'status'            => (string)$a->status,
                'appointment_at'    => (int)$a->appointment_at,
                'modality'          => (string)$a->modality,
                'duration_minutes'  => (int)$a->duration_minutes,
                'psychologist_userid'=> (int)$a->psychologist_userid,
            ];
        }
        return $r + ['slotid' => (int)$params['slotid']];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'          => new external_value(PARAM_BOOL, 'True when the booking succeeded'),
            'duplicate'   => new external_value(PARAM_BOOL, 'True when the student had a pendiente request for the same slot'),
            'error'       => new external_value(PARAM_TEXT, 'Error code when ok=false'),
            'slotid'      => new external_value(PARAM_INT,  'Echoed slot id'),
            'appointment' => new external_single_structure([
                'id'                 => new external_value(PARAM_INT, 'Appointment id'),
                'status'             => new external_value(PARAM_TEXT,'pendiente|confirmada|modificada|cancelada|atendida|no_asistio'),
                'appointment_at'     => new external_value(PARAM_INT, 'Unix ts'),
                'modality'           => new external_value(PARAM_TEXT,'presencial|virtual|mixto'),
                'duration_minutes'   => new external_value(PARAM_INT, 'Duration'),
                'psychologist_userid' => new external_value(PARAM_INT, 'Specialist userid'),
            ], 'null when ok=false'),
        ]);
    }
}