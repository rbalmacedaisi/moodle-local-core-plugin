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
 * Returns the list of available psychology slot occurrences between two
 * unix timestamps. Materialises recurring slots and excludes already-booked
 * occurrences. Capability: local/grupomakro_core:view_wellness.
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

class get_psychology_slots extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'psychologist_userid' => new external_value(PARAM_INT, 'Specialist userid; 0 = default (titular or first suplente)', VALUE_DEFAULT, 0),
            'from'                => new external_value(PARAM_INT, 'Start unix ts (inclusive); 0 = now', VALUE_DEFAULT, 0),
            'to'                  => new external_value(PARAM_INT, 'End unix ts (inclusive); 0 = now + 30 days', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($psychologistUserid = 0, $from = 0, $to = 0) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'psychologist_userid' => $psychologistUserid,
            'from' => $from, 'to' => $to,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $psychologist = (int)$params['psychologist_userid'];
        if ($psychologist <= 0) {
            $psychologist = \local_grupomakro_core\local\wellness_psychology_manager::default_psychologist_userid();
        }
        $now = time();
        $from = (int)$params['from'] ?: $now;
        $to   = (int)$params['to']   ?: ($now + 30 * 86400);
        if ($to < $from) {
            $to = $from + 86400;
        }
        // F-27: cap the window so a hostile / careless client can't make
        // us iterate one day at a time over years of slots.
        $maxend = $from + 90 * 86400;
        if ($to > $maxend) {
            $to = $maxend;
        }

        $rows = \local_grupomakro_core\local\wellness_psychology_manager::get_open_slots(
            $psychologist, $from, $to, $now);

        return [
            'psychologist_userid' => $psychologist,
            'from'  => $from,
            'to'    => $to,
            'slots' => array_values(array_map(function ($r) {
                return [
                    'slotid'              => (int)$r['slotid'],
                    'psychologist_userid' => (int)$r['psychologist_userid'],
                    'weekday'             => (int)$r['weekday'],
                    'starttime'           => (string)$r['starttime'],
                    'endtime'             => (string)$r['endtime'],
                    'modality'            => (string)$r['modality'],
                    'duration_minutes'    => (int)$r['duration_minutes'],
                    'location'            => (string)$r['location'],
                    'occurrence_start'    => (int)$r['occurrence_start'],
                    'occurrence_end'      => (int)$r['occurrence_end'],
                ];
            }, $rows)),
        ];
    }

    public static function execute_returns() {
        $slot = new external_single_structure([
            'slotid'              => new external_value(PARAM_INT,  'Slot id'),
            'psychologist_userid' => new external_value(PARAM_INT,  'Specialist userid'),
            'weekday'             => new external_value(PARAM_INT,  '0-6 weekday'),
            'starttime'           => new external_value(PARAM_TEXT, 'HH:MM'),
            'endtime'             => new external_value(PARAM_TEXT, 'HH:MM'),
            'modality'            => new external_value(PARAM_TEXT, 'presencial|virtual|mixto'),
            'duration_minutes'    => new external_value(PARAM_INT,  'Duration per appointment'),
            'location'            => new external_value(PARAM_TEXT, 'Office or virtual room'),
            'occurrence_start'    => new external_value(PARAM_INT,  'Unix ts of the slot occurrence'),
            'occurrence_end'      => new external_value(PARAM_INT,  'Unix ts of the slot end'),
        ]);
        return new external_single_structure([
            'psychologist_userid' => new external_value(PARAM_INT, 'Echoed specialist userid (resolved when input was 0).'),
            'from'                => new external_value(PARAM_INT, 'Echoed range start.'),
            'to'                  => new external_value(PARAM_INT, 'Echoed range end.'),
            'slots'               => new external_multiple_structure($slot, 'Materialised open occurrences.'),
        ]);
    }
}