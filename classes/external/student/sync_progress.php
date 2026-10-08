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
        return new external_function_parameters([
            'phase' => new external_value(PARAM_ALPHA, 'Phase of synchronization: init, process, final', VALUE_DEFAULT, 'init'),
            'offset' => new external_value(PARAM_INT, 'Offset for processing', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Number of records to process', VALUE_DEFAULT, 50)
        ]);
    }

    /**
     * Synchronize progress for all students.
     */
    public static function execute($phase = 'init', $offset = 0, $limit = 50) {
        global $DB;

        // Ensure user is logged in and has appropriate permissions.
        self::validate_context(\context_system::instance());
        require_capability('local/grupomakro_core:seeallorders', \context_system::instance());

        $result = [
            'status' => 'success',
            'message' => 'Fase completada.',
            'total' => 0,
            'processed' => 0,
            'unlocked' => 0,
            'finished' => false
        ];

        $logFile = \make_temp_directory('grupomakro') . '/sync_progress.log';
        $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);

        try {
            if ($phase === 'init') {
                file_put_contents($logFile, "--- INICIO DE SINCRONIZACIÓN: " . date('Y-m-d H:i:s') . " ---\n");
                
                // Phase 0: Fix students with missing role information.
                file_put_contents($logFile, "Fase 0: Corrigiendo información de roles...\n", FILE_APPEND);
                $DB->execute("UPDATE {local_learning_users} 
                              SET userroleid = :roleid, userrolename = 'student' 
                              WHERE (userroleid IS NULL OR userroleid = 0 OR userrolename IS NULL OR userrolename = '')", 
                              ['roleid' => $studentRoleId]);

                // 1. Get ALL students enrolled to ensure progress records exist.
                $sql = "SELECT lpu.userid, lpu.learningplanid, lpu.userroleid
                        FROM {local_learning_users} lpu
                        WHERE lpu.userroleid = :studentroleid";
                $enrolledStudents = $DB->get_records_sql($sql, ['studentroleid' => $studentRoleId]);
                
                file_put_contents($logFile, "Paso 1: Verificando/Inicializando malla de seguimiento...\n", FILE_APPEND);
                foreach ($enrolledStudents as $s) {
                    local_grupomakro_progress_manager::create_learningplan_user_progress($s->userid, $s->learningplanid, $s->userroleid);
                }

                // Count total records to process in phase 2.
                $sql_count = "SELECT COUNT(gcp.id)
                             FROM {gmk_course_progre} gcp
                             JOIN {local_learning_users} lpu ON (lpu.userid = gcp.userid AND lpu.learningplanid = gcp.learningplanid)
                             WHERE lpu.userroleid = :studentroleid";
                $totalRecords = $DB->count_records_sql($sql_count, ['studentroleid' => $studentRoleId]);

                $result['message'] = "Inicialización completada. $totalRecords registros por procesar.";
                $result['total'] = $totalRecords;
            } 
            else if ($phase === 'process') {
                // 3. Process a chunk of records.
                $sql_records = "SELECT gcp.id, gcp.userid, gcp.learningplanid, gcp.courseid
                                FROM {gmk_course_progre} gcp
                                JOIN {local_learning_users} lpu ON (lpu.userid = gcp.userid AND lpu.learningplanid = gcp.learningplanid)
                                WHERE lpu.userroleid = :studentroleid
                                ORDER BY gcp.id ASC";
                        
                $records = $DB->get_records_sql($sql_records, ['studentroleid' => $studentRoleId], $offset, $limit);
                
                foreach ($records as $record) {
                    local_grupomakro_progress_manager::update_course_progress($record->courseid, $record->userid, $record->learningplanid, $logFile);
                    $result['processed']++;
                }

                $result['message'] = "Procesados " . ($offset + $result['processed']) . " registros.";
                $result['finished'] = count($records) < $limit;
            }
            else if ($phase === 'final') {
                file_put_contents($logFile, "\n=== FINALIZACIÓN: Recalculando disponibilidad ===\n", FILE_APPEND);
                
                $sql = "SELECT DISTINCT lpu.userid, lpu.learningplanid
                        FROM {local_learning_users} lpu
                        WHERE lpu.userroleid = :studentroleid";
                $students = $DB->get_records_sql($sql, ['studentroleid' => $studentRoleId]);
                
                foreach ($students as $student) {
                    $availStats = local_grupomakro_progress_manager::recalculate_course_availability($student->userid, $student->learningplanid, $logFile);
                    $result['unlocked'] += $availStats['unlocked'];
                }

                $result['message'] = "Sincronización finalizada correctamente.";
                $result['finished'] = true;
                file_put_contents($logFile, "Fin: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
            }

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
            file_put_contents($logFile, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
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
            'total'   => new external_value(PARAM_INT, 'Total records to process.', VALUE_DEFAULT, 0),
            'processed' => new external_value(PARAM_INT, 'Records processed in this chunk.', VALUE_DEFAULT, 0),
            'unlocked' => new external_value(PARAM_INT, 'Number of courses unlocked.', VALUE_DEFAULT, 0),
            'finished' => new external_value(PARAM_BOOL, 'If the entire process is finished.', VALUE_DEFAULT, false)
        ]);
    }
}
