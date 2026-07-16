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
 * Class definition for the local_grupomakro_copy_activity external function.
 *
 * Creates N copies of an existing attendance session to user-supplied target
 * dates/hours. Each copy produces its own attendance_sessions row, its own BBB
 * (if the source had one and/or the class is virtual/mixed), and its own
 * gmk_bbb_attendance_relation row.
 *
 * Strictly additive: never touches the source session, never deletes anything.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro / ISI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\activity;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->dirroot . '/local/grupomakro_core/locallib.php';

class copy_activity extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'classId'         => new external_value(PARAM_TEXT, 'Id of the class.', VALUE_REQUIRED),
            'sourceSessionId' => new external_value(PARAM_TEXT, 'Source attendance_session id.', VALUE_REQUIRED),
            'dates'           => new external_value(PARAM_RAW,  'JSON array of {date, initTime, endTime}.', VALUE_REQUIRED),
            'force'           => new external_value(PARAM_INT,  'Force despite conflicts (1|0).', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($classId, $sourceSessionId, $datesJson, $force) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'classId'         => $classId,
            'sourceSessionId' => $sourceSessionId,
            'dates'           => $datesJson,
            'force'           => $force,
        ]);

        try {
            $context = context_system::instance();
            self::validate_context($context);
            require_capability('local/grupomakro_core:manage_classes', $context);

            $dates = json_decode($params['dates'], true);
            if (!is_array($dates)) {
                return ['status' => -1, 'message' => 'Invalid dates JSON', 'hasConflicts' => false];
            }

            // If not forcing, re-check conflicts first.
            if (!$params['force']) {
                $conflictsByDate = [];
                foreach ($dates as $d) {
                    if (empty($d['date']) || empty($d['initTime']) || empty($d['endTime'])) {
                        continue;
                    }
                    $r = check_reschedule_conflicts([
                        'classId'  => $params['classId'],
                        'date'     => $d['date'],
                        'initTime' => $d['initTime'],
                        'endTime'  => $d['endTime'],
                    ]);
                    if (!empty($r['hasConflicts'])) {
                        $conflictsByDate[$d['date']] = $r['conflicts'];
                    }
                }
                if (!empty($conflictsByDate)) {
                    return [
                        'status'   => -1,
                        'message'  => json_encode($conflictsByDate),
                        'hasConflicts' => true,
                        'conflictsByDate' => json_encode($conflictsByDate),
                    ];
                }
            }

            $result = gmk_copy_class_activity([
                'classId'         => $params['classId'],
                'sourceSessionId' => $params['sourceSessionId'],
                'dates'           => $dates,
            ]);

            return [
                'status'   => 1,
                'message'  => json_encode($result),
                'hasConflicts' => false,
                'conflictsByDate' => '{}',
            ];

        } catch (\moodle_exception $e) {
            return ['status' => -1, 'message' => $e->getMessage(), 'hasConflicts' => false, 'conflictsByDate' => '{}'];
        } catch (\Throwable $e) {
            $detail = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
            return ['status' => -1, 'message' => $detail, 'hasConflicts' => false, 'conflictsByDate' => '{}'];
        }
    }

    public static function execute_returns(): external_description {
        return new external_single_structure([
            'status'          => new external_value(PARAM_INT,  '1 on success, -1 on error/conflicts.', VALUE_DEFAULT, 1),
            'message'         => new external_value(PARAM_TEXT, 'Result JSON or error message.', VALUE_DEFAULT, 'ok'),
            'hasConflicts'    => new external_value(PARAM_INT,  '1 if conflicts blocked the copy.', VALUE_DEFAULT, 0),
            'conflictsByDate' => new external_value(PARAM_TEXT, 'JSON object {date: [conflicts]}.', VALUE_DEFAULT, '{}'),
        ]);
    }
}
