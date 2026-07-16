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
 * Class definition for the local_grupomakro_delete_session external function.
 *
 * Deletes an attendance_sessions row and (when present) its associated BBB
 * course_module + bigbluebuttonbn instance + gmk_bbb_attendance_relation row.
 *
 * Refuses when the session has attendance_log records unless force=1 is passed.
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

class delete_session extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'classId'   => new external_value(PARAM_TEXT, 'Id of the class.', VALUE_REQUIRED),
            'sessionId' => new external_value(PARAM_TEXT, 'attendance_session id to delete.', VALUE_REQUIRED),
            'force'     => new external_value(PARAM_INT,  'Force delete even if attendance_log exists (1|0).', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($classId, $sessionId, $force) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'classId'   => $classId,
            'sessionId' => $sessionId,
            'force'     => $force,
        ]);

        try {
            $context = context_system::instance();
            self::validate_context($context);
            require_capability('local/grupomakro_core:manage_classes', $context);

            // Pre-check: report log count so UI can warn before destructive delete.
            $logCount = (int)$DB->get_field_sql(
                "SELECT COUNT(*) FROM {attendance_log} WHERE sessionid = ?",
                [(int)$params['sessionId']]
            );

            if ($logCount > 0 && !$params['force']) {
                return [
                    'status'        => -1,
                    'message'       => get_string('error_session_has_logs', 'local_grupomakro_core', $logCount),
                    'hasLogs'       => 1,
                    'logCount'      => $logCount,
                    'deleted'       => 0,
                    'bbbCmid'       => 0,
                    'backupPath'    => '',
                ];
            }

            $result = gmk_delete_class_activity([
                'classId'   => $params['classId'],
                'sessionId' => $params['sessionId'],
                'force'     => $params['force'],
            ]);

            return [
                'status'     => 1,
                'message'    => 'ok',
                'hasLogs'    => 0,
                'logCount'   => $logCount,
                'deleted'    => 1,
                'bbbCmid'    => (int)($result['deleted']['bbbCmid'] ?? 0),
                'backupPath' => $result['backupPath'] ?? '',
            ];

        } catch (\moodle_exception $e) {
            return [
                'status'     => -1,
                'message'    => $e->getMessage(),
                'hasLogs'    => 0,
                'logCount'   => 0,
                'deleted'    => 0,
                'bbbCmid'    => 0,
                'backupPath' => '',
            ];
        } catch (\Throwable $e) {
            $detail = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
            return [
                'status'     => -1,
                'message'    => $detail,
                'hasLogs'    => 0,
                'logCount'   => 0,
                'deleted'    => 0,
                'bbbCmid'    => 0,
                'backupPath' => '',
            ];
        }
    }

    public static function execute_returns(): external_description {
        return new external_single_structure([
            'status'     => new external_value(PARAM_INT,  '1 on success, -1 on error or blocked.', VALUE_DEFAULT, 1),
            'message'    => new external_value(PARAM_TEXT, 'Result message.', VALUE_DEFAULT, 'ok'),
            'hasLogs'    => new external_value(PARAM_INT,  '1 if session had attendance_log records (blocked unless force).', VALUE_DEFAULT, 0),
            'logCount'   => new external_value(PARAM_INT,  'Number of attendance_log rows on the session.', VALUE_DEFAULT, 0),
            'deleted'    => new external_value(PARAM_INT,  '1 if deletion occurred.', VALUE_DEFAULT, 0),
            'bbbCmid'    => new external_value(PARAM_INT,  'CMID of the deleted BBB module (0 if no BBB).', VALUE_DEFAULT, 0),
            'backupPath' => new external_value(PARAM_TEXT, 'Path to JSON backup file.', VALUE_DEFAULT, ''),
        ]);
    }
}
