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
            // 1. Get ALL students enrolled in learning plans, regardless of whether they have progress records yet.
            $sql = "SELECT lpu.id, lpu.userid, lpu.learningplanid, lpu.userroleid
                    FROM {local_learning_users} lpu
                    WHERE lpu.userroleid = :studentroleid";
            
            $enrolledStudents = $DB->get_records_sql($sql, ['studentroleid' => $studentRoleId]);
            $totalEnrolled = count($enrolledStudents);
            file_put_contents($logFile, "Total de estudiantes inscritos encontrados: $totalEnrolled\n", FILE_APPEND);
            
            if ($totalEnrolled === 0) {
                 file_put_contents($logFile, "AVISO: No se encontraron estudiantes inscritos.\n", FILE_APPEND);
                 return $result;
            }

            // 2. Ensure all enrolled students have their progress records initialized.
            file_put_contents($logFile, "Paso 1: Verificando/Inicializando registros faltantes...\n", FILE_APPEND);
            foreach ($enrolledStudents as $s) {
                // This method internally checks if records exist and creates them if not.
                local_grupomakro_progress_manager::create_learningplan_user_progress($s->userid, $s->learningplanid, $s->userroleid);
            }

            // 3. Now get all records from gmk_course_progre (which should now be complete).
            $sql = "SELECT gcp.id, gcp.userid, gcp.learningplanid, gcp.courseid
                    FROM {gmk_course_progre} gcp
                    JOIN {local_learning_users} lpu ON (lpu.userid = gcp.userid AND lpu.learningplanid = gcp.learningplanid)
                    WHERE lpu.userroleid = :studentroleid";
                    
            $records = $DB->get_records_sql($sql, ['studentroleid' => $studentRoleId]);
            $total = count($records);
            file_put_contents($logFile, "Paso 2: Iniciando procesamiento de $total registros de materia...\n", FILE_APPEND);

            // Release session lock so the poller can read the log file.
            \core\session\manager::write_close();

            $syncedUserPlans = []; // Keep track of (user, plan) pairs already synced for period

            foreach ($records as $index => $record) {
                try {
                    $current = $index + 1;
                    $user = $DB->get_record('user', ['id' => $record->userid], 'firstname, lastname');
                    $userName = $user ? "$user->firstname $user->lastname" : "Desconocido";

                    // Force progress and grade update in our local tables.
                    // This now also handles Moodle native completion.
                    local_grupomakro_progress_manager::update_course_progress($record->courseid, $record->userid, $record->learningplanid, $logFile);

                    // Sync Period (once per user/plan)
                    $userPlanKey = $record->userid . '_' . $record->learningplanid;
                    if (!isset($syncedUserPlans[$userPlanKey])) {
                        local_grupomakro_progress_manager::sync_student_period($record->userid, $record->learningplanid, $logFile);
                        $syncedUserPlans[$userPlanKey] = true;
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
