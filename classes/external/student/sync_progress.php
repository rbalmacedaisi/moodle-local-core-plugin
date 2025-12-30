<?php

namespace local_grupomakro_core\external\student;

use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;
use local_grupomakro_progress_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

/**
 * External function to synchronize progress for all students.
 */
class sync_progress extends external_api {

    /**
     * Describes parameters.
     */
    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Synchronize progress for all students.
     */
    public static function execute() {
        global $DB;

        // Ensure user is logged in and has appropriate permissions.
        self::validate_context(\context_system::instance());
        require_capability('local/grupomakro_core:seeallorders', \context_system::instance());

        $result = [
            'status' => 'success',
            'message' => 'Sincronización completada.',
            'count' => 0
        ];

        $logFile = \make_temp_directory('grupomakro') . '/sync_progress.log';
        file_put_contents($logFile, "Iniciando sincronización: " . date('Y-m-d H:i:s') . "\n");

        $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);
        file_put_contents($logFile, "Student Role ID: $studentRoleId\n", FILE_APPEND);

        try {
            // Get all students enrolled in learning plans.
            // Simplified query to ensure we get something first
            $sql = "SELECT DISTINCT lpu.userid, lpu.learningplanid, gcp.courseid
                    FROM {local_learning_users} lpu
                    JOIN {gmk_course_progre} gcp ON (gcp.userid = lpu.userid AND gcp.learningplanid = lpu.learningplanid)
                    WHERE lpu.userroleid = :studentroleid";
            
            $records = $DB->get_records_sql($sql, ['studentroleid' => $studentRoleId]);
            $total = count($records);
            file_put_contents($logFile, "Total de registros encontrados: $total\n", FILE_APPEND);
            
            if ($total === 0) {
                 file_put_contents($logFile, "AVISO: No se encontraron registros de progreso para estudiantes.\n", FILE_APPEND);
                 return $result;
            }

            // Release session lock so the poller can read the log file.
            \core\session\manager::write_close();

            foreach ($records as $index => $record) {
                try {
                    $current = $index + 1;
                    $user = $DB->get_record('user', ['id' => $record->userid], 'firstname, lastname');
                    $userName = $user ? "$user->firstname $user->lastname" : "Desconocido";

                    // Force progress and grade update in our local tables.
                    local_grupomakro_progress_manager::update_course_progress($record->courseid, $record->userid);
                    
                    // Trigger Moodle native course completion check.
                    $course = get_course($record->courseid);
                    $completion = new \completion_info($course);
                    if ($completion->is_enabled()) {
                        $completion->mark_course_completions_if_satisfied($record->userid);
                    }

                    $result['count']++;
                    if ($current % 5 == 0 || $current == $total) {
                        $msg = "[" . date('H:i:s') . "] Procesado: $current/$total ($userName)\n";
                        file_put_contents($logFile, $msg, FILE_APPEND);
                    }
                } catch (Exception $e) {
                    $msg = "[" . date('H:i:s') . "] Error en registro (User: $record->userid, Course: $record->courseid): " . $e->getMessage() . "\n";
                    file_put_contents($logFile, $msg, FILE_APPEND);
                    continue;
                }
            }
            file_put_contents($logFile, "Sincronización finalizada: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Describes return value.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_ALPHANUM, 'Status of the operation.'),
            'message' => new external_value(PARAM_TEXT, 'Result message.'),
            'count'   => new external_value(PARAM_INT, 'Number of records synchronized.')
        ]);
    }
}
