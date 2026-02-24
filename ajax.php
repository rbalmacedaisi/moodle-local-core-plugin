<?php

define('AJAX_SCRIPT', true);

// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../config.php';
}

require_once($config_path);
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use local_grupomakro_core\external\teacher\create_express_activity;
use local_grupomakro_core\external\teacher\get_pending_grading;
use local_grupomakro_core\external\teacher\save_grade;
use local_grupomakro_core\external\student\get_student_info;
use local_grupomakro_core\external\student\update_status;
use local_grupomakro_core\external\student\sync_progress;
use local_grupomakro_core\external\teacher\get_dashboard_data;
use PhpOffice\PhpSpreadsheet\IOFactory;


if (!function_exists('gmk_log')) {
    // Defined in locallib.php
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
header('Content-Type: application/json'); // Enforce JSON for this AJAX script

// JSON Request Handling (for Axios)
if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if ($jsonData && isset($jsonData['action'])) {
            $action = clean_param($jsonData['action'], PARAM_ALPHANUMEXT);
            
            // Extract core fields
            // Extract all root fields for compatibility with required_param/optional_param
            foreach ($jsonData as $key => $value) {
                $_POST[$key] = $_REQUEST[$key] = $value;
            }

            // Flatten 'args' for compatibility with required_param/optional_param
            if (isset($jsonData['args']) && is_array($jsonData['args'])) {
                foreach ($jsonData['args'] as $key => $value) {
                    $_POST[$key] = $_REQUEST[$key] = $value;
                }
            }
        }
    }
}

require_login();
$context = context_system::instance();
$PAGE->set_context($context);

$response = [
    'status' => 'error',
    'message' => 'Invalid action.'
];

// Ensure we don't have any output before header
ob_start();

try {
    switch ($action) {
        case 'local_grupomakro_sync_progress':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/sync_progress.php');
            $response = \local_grupomakro_core\external\student\sync_progress::execute();
            break;
        
        case 'local_grupomakro_update_student_status':
            $userid = required_param('userid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/update_status.php');
            $result = \local_grupomakro_core\external\student\update_status::execute($userid);
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_sync_financial_bulk':
            raise_memory_limit(MEMORY_HUGE);
            core_php_time_limit::raise(300);
            
            // This function already handles batching (default 50) and prioritization
            $result = local_grupomakro_sync_financial_status([]); 
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_pending_grading':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_pending_grading.php');
            $classid = optional_param('classid', 0, PARAM_INT);
            $status = optional_param('status', 'pending', PARAM_ALPHA);
            $result = \local_grupomakro_core\external\teacher\get_pending_grading::execute($USER->id, $classid, $status);
            $response = ['status' => 'success', 'tasks' => $result];
            break;

        case 'local_grupomakro_save_grade':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/save_grade.php');
            $args = required_param('args', PARAM_RAW);
            $data = json_decode($args, true);
            
            if (!$data) {
                throw new moodle_exception('invalidjson');
            }
            
            $result = \local_grupomakro_core\external\teacher\save_grade::execute(
                $data['assignmentid'], 
                $data['studentid'], 
                $data['grade'], 
                isset($data['feedback']) ? $data['feedback'] : ''
            );
            $response = [
                'status' => (isset($result['status']) && $result['status'] === 'error') ? 'error' : 'success',
                'message' => isset($result['message']) ? $result['message'] : '',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_quiz_attempt_data':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_quiz_attempt_data.php');
            $attemptid = required_param('attemptid', PARAM_INT);
            $result = \local_grupomakro_core\external\teacher\get_quiz_attempt_data::execute($attemptid);
            $response = ['status' => 'success', 'data' => $result];
            break;

        case 'local_grupomakro_save_quiz_grading':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/save_quiz_grading.php');
            $args = required_param('args', PARAM_RAW);
            $data = json_decode($args, true);
            if (!$data) throw new moodle_exception('invalidjson');

            $result = \local_grupomakro_core\external\teacher\save_quiz_grading::execute(
                $data['attemptid'],
                $data['slot'],
                $data['mark'],
                isset($data['comment']) ? $data['comment'] : ''
            );
            $response = [
                'status' => (isset($result['status']) && $result['status'] === 'error') ? 'error' : 'success',
                'message' => isset($result['message']) ? $result['message'] : '',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_update_period':
            $userid = required_param('userid', PARAM_INT);
            $planid = required_param('planid', PARAM_INT);
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            $success = \local_grupomakro_progress_manager::update_student_period($userid, $planid, $periodid);
            if ($success) {
                $response = ['status' => 'success', 'message' => 'Periodo actualizado correctamente.'];
            } else {
                $response = ['status' => 'error', 'message' => 'No se pudo actualizar el periodo.'];
            }
            break;

        case 'local_grupomakro_update_academic_period':
            $userid = required_param('userid', PARAM_INT);
            $planid = required_param('planid', PARAM_INT);
            $academicperiodid = required_param('academicperiodid', PARAM_INT);
            
            $lpUser = $DB->get_record('local_learning_users', ['userid' => $userid, 'learningplanid' => $planid]);
            if ($lpUser) {
                $lpUser->academicperiodid = $academicperiodid;
                $lpUser->timemodified = time();
                if ($DB->update_record('local_learning_users', $lpUser)) {
                    $response = ['status' => 'success', 'message' => 'Periodo Lectivo actualizado correctamente.'];
                } else {
                    $response = ['status' => 'error', 'message' => 'Error al actualizar base de datos.'];
                }
            } else {
                $response = ['status' => 'error', 'message' => 'Inscripción no encontrada.'];
            }
            break;

        case 'local_grupomakro_get_all_academic_periods':
            $periods = $DB->get_records('gmk_academic_periods', [], 'startdate DESC', 'id, name, status, startdate, enddate');
            // Format dates for UI
            $data = [];
            foreach ($periods as $p) {
                $p->formatted_start = userdate($p->startdate, get_string('strftimedate', 'langconfig'));
                $p->formatted_end = userdate($p->enddate, get_string('strftimedate', 'langconfig'));
                $data[] = $p;
            }
            $response = ['status' => 'success', 'data' => array_values($data)];
            break;

        case 'local_grupomakro_sync_migrated_periods':
            raise_memory_limit(MEMORY_HUGE);
            core_php_time_limit::raise(300); // 5 minutes per batch

            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            $logFile = make_temp_directory('grupomakro') . '/sync_progress.log';
            
            $offset = optional_param('offset', 0, PARAM_INT);
            $limit = 100; // Batch size
            
            if ($offset == 0) {
                file_put_contents($logFile, "--- Inicio Sincronización Periodos (Migrados) " . date('Y-m-d H:i:s') . " ---\n", FILE_APPEND);
            }

            $studentRoleId = 5; 
            // Count total first
            $totalCount = $DB->count_records('local_learning_users', ['userroleid' => $studentRoleId]);
            
            // Get batch
            $students = $DB->get_records('local_learning_users', ['userroleid' => $studentRoleId], 'id ASC', 'userid, learningplanid', $offset, $limit);
            
            $countUpdated = 0;
            $processedInBatch = 0;

            foreach ($students as $s) {
                try {
                    $processedInBatch++;
                    if (\local_grupomakro_progress_manager::sync_student_period_by_count($s->userid, $s->learningplanid, $logFile)) {
                        $countUpdated++;
                    }
                } catch (Exception $e) {
                    file_put_contents($logFile, "[FATAL] Error con usuario $s->userid: " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }

            $newOffset = $offset + count($students);
            $finished = ($newOffset >= $totalCount || empty($students));

            if ($finished) {
                file_put_contents($logFile, "--- Fin Sincronización Periodos. ---\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, "[BATCH] Procesado bloque hasta índice $newOffset de $totalCount...\n", FILE_APPEND);
            }

            $response = [
                'status' => 'success', 
                'message' => $finished ? "Sincronización finalizada." : "Procesando bloque...",
                'offset' => $newOffset,
                'total' => $totalCount,
                'finished' => $finished,
                'countUpdated' => $countUpdated
            ];
            break;

        case 'local_grupomakro_bulk_update_periods_json':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            $data = required_param('data', PARAM_RAW);
            $items = json_decode($data, true);
            
            if (!$items) {
                $response = ['status' => 'error', 'message' => 'Invalid JSON data'];
                break;
            }

            $log = [];
            $successCount = 0;
            $failCount = 0;
            
            // Cache period names to IDs map
            $allPeriods = $DB->get_records('local_learning_periods');
            $periodMap = []; // Name -> ID
            foreach ($allPeriods as $p) {
                $periodMap[strtoupper(trim($p->name))] = $p;
            }
            
            foreach ($items as $row) {
                $idnumber = trim($row['idnumber']);
                $periodName = strtoupper(trim($row['period']));
                
                if (empty($idnumber) || empty($periodName)) continue;
                
                // Find User
                $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0], 'id, firstname, lastname');
                if (!$user) {
                    $log[] = "Error: Usuario con ID $idnumber no encontrado.";
                    $failCount++;
                    continue;
                }
                
                // Find Period
                if (!isset($periodMap[$periodName])) {
                     $log[] = "Error: Periodo '$periodName' no existe.";
                     $failCount++;
                     continue;
                }
                $targetPeriod = $periodMap[$periodName];
                
                // Find Learning Plan for User (Assuming active student)
                $lpUser = $DB->get_record('local_learning_users', ['userid' => $user->id, 'userrolename' => 'student']);
                if (!$lpUser) {
                    $log[] = "Error: Usuario $idnumber no está inscrito en plan de estudio.";
                    $failCount++;
                    continue;
                }
                
                // Check if period belongs to plan? (Optional safety check)
                if ($targetPeriod->learningplanid != $lpUser->learningplanid) {
                     $log[] = "Error: Periodo '$periodName' no pertenece al plan del usuario $idnumber.";
                     $failCount++;
                     continue;
                }
                
                // Update
                if (\local_grupomakro_progress_manager::update_student_period($user->id, $lpUser->learningplanid, $targetPeriod->id)) {
                    $successCount++;
                } else {
                    $log[] = "Aviso: No se requirió cambio para $idnumber.";
                    $successCount++; // Count as handled
                }
            }
            
            $response = [
                'status' => 'success',
                'message' => "Proceso finalizado. Actualizados/Verificados: $successCount. Errores: $failCount.",
                'log' => implode("\n", $log)
            ];
            break;

        case 'local_grupomakro_bulk_update_periods_excel':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            
            // Check file upload
            if (empty($_FILES['import_file'])) {
                $response = ['status' => 'error', 'message' => 'No se recibió ningún archivo.'];
                break;
            }
            
            $file = $_FILES['import_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $response = ['status' => 'error', 'message' => 'Error al subir el archivo.'];
                break;
            }

            $tmpFilePath = $file['tmp_name'];
            
            try {
                $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($tmpFilePath);
                $sheet = $spreadsheet->getSheet(0);
                $rows = $sheet->toArray();
                
                if (count($rows) < 2) {
                    $response = ['status' => 'error', 'message' => 'El archivo parece estar vacío (o solo tiene cabecera).'];
                    break;
                }
                
                $headers = array_map('trim', array_map('strtolower', $rows[0]));
                $idIdx = -1;
                $bloqueIdx = -1;
                
                // Flexible header search
                foreach ($headers as $idx => $h) {
                    if (strpos($h, 'id number') !== false || strpos($h, 'identificación') !== false || $h === 'idnumber') $idIdx = $idx;
                    // Look for Bloque, Bimestre, Subperiodo
                    if (strpos($h, 'bloque') !== false || strpos($h, 'bimestre') !== false || strpos($h, 'subperiod') !== false) $bloqueIdx = $idx;
                }
                
                if ($idIdx === -1 || $bloqueIdx === -1) {
                    $response = ['status' => 'error', 'message' => 'No se encontraron las columnas necesarias (ID Number, Bloque).'];
                    break;
                }
                
                $log = [];
                $successCount = 0;
                $failCount = 0;
                
                // Cache Subperiods Map: [PlanID][NormalizedName] => SubperiodObject
                // This is efficient.
                // Join Periods to get PlanID
                $sql = "SELECT sp.id, sp.name, sp.periodid, p.learningplanid
                        FROM {local_learning_subperiods} sp
                        JOIN {local_learning_periods} p ON p.id = sp.periodid";
                $allSubperiods = $DB->get_records_sql($sql);
                
                $subperiodMap = []; // [planid][UPPER(name)] = sp
                foreach ($allSubperiods as $sp) {
                    $nameKey = strtoupper(trim($sp->name));
                    $subperiodMap[$sp->learningplanid][$nameKey] = $sp;
                }
                
                // Start from row 1 (second row)
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    $idnumber = trim($row[$idIdx]);
                    $bloqueName = strtoupper(trim($row[$bloqueIdx] ?? ''));
                    
                    if (empty($idnumber)) continue;
                    if (empty($bloqueName)) {
                         // Maybe clearing bloque? For now skip
                         continue;
                    }
                    
                     // Find User
                    $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0], 'id, firstname, lastname');
                    
                    // Fallback: Check documentnumber profile field
                    if (!$user) {
                        $sql = "SELECT u.id, u.firstname, u.lastname 
                                FROM {user} u
                                JOIN {user_info_data} uid ON uid.userid = u.id
                                JOIN {user_info_field} uif ON uif.id = uid.fieldid
                                WHERE uif.shortname = 'documentnumber' 
                                AND uid.data = :docnum 
                                AND u.deleted = 0";
                        $user = $DB->get_record_sql($sql, ['docnum' => $idnumber]);
                    }

                    if (!$user) {
                        $log[] = "Fila " . ($i+1) . ": Usuario con ID/Cédula $idnumber no encontrado.";
                        $failCount++;
                        continue;
                    }
                    
                    // Find Learning Plan for User (Assuming active student)
                    $lpUser = $DB->get_record('local_learning_users', ['userid' => $user->id, 'userrolename' => 'student']);
                    if (!$lpUser) {
                        $log[] = "Fila " . ($i+1) . ": Usuario $idnumber no está inscrito en plan de estudio.";
                        $failCount++;
                        continue;
                    }
                    
                    $planid = $lpUser->learningplanid;
                    
                    // Find Target Subperiod
                    if (!isset($subperiodMap[$planid][$bloqueName])) {
                         $log[] = "Fila " . ($i+1) . ": Bloque '$bloqueName' no existe para el plan del usuario.";
                         $failCount++;
                         continue;
                    }
                    
                    $targetSubperiod = $subperiodMap[$planid][$bloqueName];
                    
                    // Update Subperiod (and Period)
                    // Use new helper method
                    if (\local_grupomakro_progress_manager::update_student_subperiod($user->id, $planid, $targetSubperiod->id)) {
                        $successCount++;
                    } else {
                        // Could be no change or error, assume success/no-op
                        $successCount++;
                    }
                }
                
                $response = [
                    'status' => 'success',
                    'message' => "Proceso finalizado. Filas procesadas: $successCount. Errores: $failCount.",
                    'log' => implode("\n", $log)
                ];

            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => 'Excepción procesando archivo: ' . $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_planning_data':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_planning_data($periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_save_planning':
            $academicperiodid = required_param('academicperiodid', PARAM_INT);
            $selections = required_param('selections', PARAM_RAW);
            $deferredGroups = optional_param('deferredGroups', '{}', PARAM_RAW);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $res = \local_grupomakro_core\external\admin\planning::save_planning($academicperiodid, $selections, $deferredGroups);
            $response = ['status' => 'success', 'data' => $res];
            break;

        case 'local_grupomakro_save_period_mappings':
            $baseperiodid = required_param('baseperiodid', PARAM_INT);
            $mappings = required_param('mappings', PARAM_RAW);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $res = \local_grupomakro_core\external\admin\planning::save_period_mappings($baseperiodid, $mappings);
            $response = ['status' => 'success', 'data' => $res];
            break;

        case 'local_grupomakro_get_academic_periods':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $periods = \local_grupomakro_core\external\admin\planning::get_periods();
            $response = ['status' => 'success', 'data' => $periods];
            break;

        case 'local_grupomakro_save_academic_period':
            $id = optional_param('id', 0, PARAM_INT);
            $name = required_param('name', PARAM_TEXT);
            $startdate = required_param('startdate', PARAM_INT);
            $enddate = required_param('enddate', PARAM_INT);
            $status = optional_param('status', 1, PARAM_INT);
            $learningplans = optional_param('learningplans', '', PARAM_RAW); // Expecting JSON array string
            $detailsParam = optional_param('details', '', PARAM_RAW); // Expecting JSON object
            
            $lpArray = json_decode($learningplans, true) ?: [];
            $detailsArray = json_decode($detailsParam, true) ?: [];
            
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $resId = \local_grupomakro_core\external\admin\planning::save_period($id, $name, $startdate, $enddate, $status, $lpArray, $detailsArray);
            $response = ['status' => 'success', 'data' => ['id' => $resId]];
            break;

        case 'local_grupomakro_delete_academic_period':
            $id = required_param('id', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            \local_grupomakro_core\external\admin\planning::delete_period($id);
            $response = ['status' => 'success', 'data' => true];
            break;

        case 'local_grupomakro_get_periods':
            $planid = optional_param('planid', 0, PARAM_INT);
            if ($planid > 0) {
                $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $planid], 'id ASC', 'id, name');
            } else {
                $periods = $DB->get_records('local_learning_periods', [], 'name ASC', 'id, name');
            }
            $response = ['status' => 'success', 'periods' => array_values($periods)];
            break;

        case 'local_grupomakro_odoo_status_sync':
            $userIdOrVat = optional_param('userid', null, PARAM_INT);
            if (!$userIdOrVat) {
                $userIdOrVat = required_param('document_number', PARAM_RAW);
            }
            $action = required_param('action', PARAM_ALPHA);
            $reason = optional_param('reason', '', PARAM_TEXT);
            $targetPeriodId = optional_param('target_period_id', null, PARAM_INT);

            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            $res = \local_grupomakro_progress_manager::update_external_status($userIdOrVat, $action, $reason, $targetPeriodId);
            $response = $res;
            break;

        case 'local_grupomakro_get_all_learning_plans':
            $plans = $DB->get_records('local_learning_plans', [], 'name ASC', 'id, name');
            $response = ['status' => 'success', 'data' => array_values($plans)];
            break;

        case 'local_grupomakro_get_plan_subperiods':
            $planid = required_param('planid', PARAM_INT);
            $sql = "SELECT sp.id, sp.name, sp.periodid, p.name as periodname
                    FROM {local_learning_subperiods} sp
                    JOIN {local_learning_periods} p ON p.id = sp.periodid
                    WHERE p.learningplanid = :planid
                    ORDER BY p.id ASC, sp.id ASC";
            $subperiods = $DB->get_records_sql($sql, ['planid' => $planid]);
            $response = ['status' => 'success', 'subperiods' => array_values($subperiods)];
            break;

        case 'local_grupomakro_update_subperiod':
            $userid = required_param('userid', PARAM_INT);
            $planid = required_param('planid', PARAM_INT);
            $subperiodid = required_param('subperiodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            
            $errorMsg = '';
            $success = \local_grupomakro_progress_manager::update_student_subperiod($userid, $planid, $subperiodid, null, $errorMsg);
            if ($success) {
                $response = ['status' => 'success', 'message' => 'Bloque actualizado correctamente.'];
            } else {
                $response = ['status' => 'error', 'message' => 'No se pudo actualizar el bloque: ' . $errorMsg];
            }
            break;

        case 'local_grupomakro_get_plans':
            $plans = $DB->get_records('local_learning_plans', [], 'name ASC', 'id, name');
            $response = ['status' => 'success', 'plans' => array_values($plans)];
            break;
        
        case 'local_grupomakro_import_grade_chunk':
            require_once($CFG->libdir . '/gradelib.php');
            raise_memory_limit(MEMORY_HUGE);
            set_time_limit(300);

            $tmpfilename = required_param('filename', PARAM_FILE);
            $offset = required_param('offset', PARAM_INT);
            $limit = required_param('limit', PARAM_INT);
            
            $filepath = make_temp_directory('grupomakro_imports') . '/' . $tmpfilename;
            if (!file_exists($filepath)) {
                throw new Exception("Archivo temporal no encontrado ($tmpfilename).");
            }
            
            $jsonfilepath = $filepath . '.json';
            $dataRows = [];
            
            if (!file_exists($jsonfilepath)) {
                // First time: Load Excel and cache as JSON for performance
                $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($filepath);
                $sheet = $spreadsheet->getSheet(0);
                $highestRow = $sheet->getHighestDataRow();
                
                for ($row = 2; $row <= $highestRow; $row++) {
                    $rowData = [
                        'row'      => $row,
                        'username' => strtolower(trim($sheet->getCellByColumnAndRow(1, $row)->getValue())),
                        'planName' => trim($sheet->getCellByColumnAndRow(2, $row)->getValue()),
                        'course'   => trim($sheet->getCellByColumnAndRow(3, $row)->getValue()),
                        'grade'    => floatval($sheet->getCellByColumnAndRow(4, $row)->getValue()),
                        'feedback' => trim($sheet->getCellByColumnAndRow(5, $row)->getValue())
                    ];
                    if (!empty($rowData['username']) && !empty($rowData['planName'])) {
                        $dataRows[] = $rowData;
                    }
                }
                file_put_contents($jsonfilepath, json_encode($dataRows));
            } else {
                // Subsequent calls: Read from faster JSON cache
                $dataRows = json_decode(file_get_contents($jsonfilepath), true);
            }

            $totalCount = count($dataRows);
            $chunk = array_slice($dataRows, $offset, $limit);
            
            $results = [];
            $toSyncPeriods = [];
            
            $rowLogFile = make_temp_directory('grupomakro_imports') . '/last_import_rows.log';
            file_put_contents($rowLogFile, "--- Procesando Chunk: Offset $offset, Limit $limit ---\n", FILE_APPEND);

            foreach ($chunk as $rowItem) {
                 $username      = $rowItem['username'];
                 $planName      = $rowItem['planName'];
                 $courseShort   = $rowItem['course'];
                 $gradeVal      = $rowItem['grade'];
                 $feedback      = $rowItem['feedback'];
                 $rowIndex      = $rowItem['row'];

                 if (empty($username) || empty($planName)) continue;

                 file_put_contents($rowLogFile, "[ROW $rowIndex] User: $username, Plan: $planName, Course: $courseShort\n", FILE_APPEND);

                 $res = [
                     'row' => $rowIndex,
                     'username' => $username,
                     'course' => $courseShort,
                     'status' => 'OK',
                     'error' => ''
                 ];

                 try {
                    // 1. Enroll
                    $enrollResult = \local_grupomakro_core\external\odoo\enroll_student::execute($planName, $username);
                    
                    // 2. Resolve Course
                    $acc_course = $DB->get_record('course', ['shortname' => $courseShort]);
                    if (!$acc_course) throw new Exception("Curso '$courseShort' no existe");

                    if (empty($feedback)) $feedback = 'Nota migrada de Q10';

                    // 3. Update Grade
                    $grade_item = \grade_item::fetch(array('courseid' => $acc_course->id, 'itemtype' => 'manual', 'itemname' => 'Nota Final Integrada'));
                    if (!$grade_item) {
                         $grade_item = new \grade_item(array('courseid' => $acc_course->id, 'itemtype' => 'manual', 'itemname' => 'Nota Final Integrada', 'grademin'=>0, 'grademax'=>100));
                         $grade_item->insert('manual');
                    }

                    $lookupUsername = \core_text::strtolower($username);
                    $user = $DB->get_record('user', ['username' => $lookupUsername, 'deleted' => 0], 'id');
                    if (!$user) throw new Exception("Usuario '$username' (mapeado a $lookupUsername) no encontrado");
                    
                    $grade_item->update_final_grade($user->id, $gradeVal, 'import', $feedback, FORMAT_HTML);
                    
                    // 4. Update Progress
                    \local_grupomakro_progress_manager::update_course_progress($acc_course->id, $user->id);

                    // 5. Track for period sync
                    $userPlanKey = $user->id . '_' . $enrollResult['plan_id'];
                    $toSyncPeriods[$userPlanKey] = ['userid' => $user->id, 'planid' => $enrollResult['plan_id']];

                 } catch (\Throwable $e) {
                     $res['status'] = 'ERROR';
                     $res['error'] = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                     file_put_contents($rowLogFile, "[ERROR ROW $rowIndex] " . $res['error'] . "\n", FILE_APPEND);
                 }
                 $results[] = $res;
            }
            
            // Sync periods for this chunk
            foreach ($toSyncPeriods as $syncData) {
                try {
                    \local_grupomakro_progress_manager::sync_student_period($syncData['userid'], $syncData['planid']);
                } catch (\Throwable $e) {
                     file_put_contents($rowLogFile, "[ERROR SYNC User " . $syncData['userid'] . "] " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
            
            $response = [
                'status' => 'success',
                'results' => $results,
                'progress' => [
                    'offset' => $offset,
                    'processed' => count($results),
                    'total' => $totalCount,
                    'finished' => ($offset + count($results) >= $totalCount)
                ]
            ];
            break;

        case 'local_grupomakro_import_grade_cleanup':
            $tmpfilename = required_param('filename', PARAM_FILE);
            $filepath = make_temp_directory('grupomakro_imports') . '/' . $tmpfilename;
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            $jsonfilepath = $filepath . '.json';
            if (file_exists($jsonfilepath)) {
                @unlink($jsonfilepath);
            }
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_get_teacher_dashboard_data':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_dashboard_data.php');
            $userid = optional_param('userid', $USER->id, PARAM_INT);
            $response = [
                'status' => 'success',
                'data' => \local_grupomakro_core\external\teacher\get_dashboard_data::execute($userid)
            ];
            break;

        case 'local_grupomakro_get_student_learning_plan_pensum':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_learning_plan_pensum.php');
            $userid = required_param('userId', PARAM_INT);
            $learningplanid = required_param('learningPlanId', PARAM_INT);
            
            $result = \local_grupomakro_core\external\student\get_student_learning_plan_pensum::execute($userid, $learningplanid);
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_student_course_pensum_activities':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_course_pensum_activities.php');
            $userid = required_param('userId', PARAM_INT);
            $classid = required_param('classId', PARAM_INT);
            
            // We need courseId from classId
            $courseid = $DB->get_field('gmk_class', 'courseid', ['id' => $classid]);
            
            if (!$courseid) {
                $response = ['status' => 'error', 'message' => 'Class not found'];
                break;
            }

            $result = \local_grupomakro_core\external\student\get_student_course_pensum_activities::execute($userid, $courseid);
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_student_attendance_details':
            $userid = required_param('userId', PARAM_INT);
            $classid = required_param('classId', PARAM_INT);
            
            $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
            
            // Find attendance instance
            $all_atts = $DB->get_records('attendance', ['course' => $class->courseid], '', 'id');
            if (empty($all_atts) && !empty($class->corecourseid)) {
                $all_atts = $DB->get_records('attendance', ['course' => $class->corecourseid], '', 'id');
            }
            
            if (empty($all_atts)) {
                 $response = ['status' => 'error', 'message' => 'No se encontró actividad de asistencia.'];
                 break;
            }
            
            $att = reset($all_atts);
            
            // Get all sessions for this group
            $sessions = $DB->get_records('attendance_sessions', ['attendanceid' => $att->id, 'groupid' => $class->groupid], 'sessdate ASC');
            
            // Get all statuses for this attendance
            $statuses = $DB->get_records('attendance_statuses', ['attendanceid' => $att->id], '', 'id, acronym, description, grade');
            
            // Get all logs for this user in these sessions
            $logs = $DB->get_records('attendance_log', ['studentid' => $userid], '', 'sessionid, statusid');
            
            $details = [];
            foreach ($sessions as $s) {
                $statusid = isset($logs[$s->id]) ? $logs[$s->id]->statusid : null;
                $statusObj = $statusid && isset($statuses[$statusid]) ? $statuses[$statusid] : null;
                
                $details[] = [
                    'id' => $s->id,
                    'date' => userdate($s->sessdate, get_string('strftimedatefullshort', 'langconfig')),
                    'time' => userdate($s->sessdate, '%H:%M'),
                    'description' => $s->description,
                    'status' => $statusObj ? $statusObj->description : 'Sin registrar',
                    'acronym' => $statusObj ? $statusObj->acronym : '-',
                    'grade' => $statusObj ? $statusObj->grade : null,
                    'is_absence' => $statusObj ? ($statusObj->grade <= 0) : ($s->sessdate < time())
                ];
            }
            
            $response = ['status' => 'success', 'details' => $details];
            break;

        case 'local_grupomakro_get_student_info':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_info.php');
            
            // Map params from request
            $page = optional_param('page', 0, PARAM_INT);
            $resultsperpage = optional_param('resultsperpage', 15, PARAM_INT);
            $search = optional_param('search', '', PARAM_RAW);
            $planid = optional_param('planid', '', PARAM_RAW);
            $periodid = optional_param('periodid', '', PARAM_RAW);
            $status = optional_param('status', '', PARAM_TEXT);
            $financial_status = optional_param('financial_status', '', PARAM_TEXT);
            $classid = optional_param('classid', 0, PARAM_INT);

            // Execute
            $result = \local_grupomakro_core\external\student\get_student_info::execute(
                $page, $resultsperpage, $search, $planid, $periodid, $status, $classid, $financial_status
            );
            
            // Retrieve actual values from external_value structure if needed, or if array is returned directly
            // Moodle external functions return arrays/stdClasses.
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_class_details':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            require_once($CFG->dirroot . '/calendar/lib.php');
            
            // Fetch events for the class group
            // We fetch events from -1 month to +6 months to show relevant history and future
            $tstart = strtotime('-1 month');
            $tend = strtotime('+6 months');
            
            // calendar_get_events($tstart, $tend, $users, $groups, $courses, $withduration, $ignorehidden)
            // Direct SQL to bypass potential API filtering issues
            // Fetch ALL events for the course and filter in PHP (matching debug script logic)
            $sql = "SELECT e.*
                    FROM {event} e
                    WHERE e.courseid = :courseid
                    ORDER BY e.timestart ASC";
            
            $params = [
                'courseid' => $class->corecourseid
            ];
            
            $events = $DB->get_records_sql($sql, $params);

            // Pre-calculate attendance times for deduplication
            $attendanceTimes = [];
            foreach ($events as $e) {
                // Apply same group filter as main loop to ensure we only track relevant attendance
                if (!empty($e->groupid) && $e->groupid != $class->groupid) {
                    continue;
                }
                if ($e->modulename === 'attendance') {
                    $attendanceTimes[] = $e->timestart;
                }
            }

            $formatted_sessions = [];
            foreach ($events as $e) {
                // Debug individual events
                $eGroupId = isset($e->groupid) ? $e->groupid : 'NULL';
                $cGroupId = $class->groupid;
                
                try {
                // Filter by Group
                if (!empty($e->groupid) && $e->groupid != $class->groupid) {
                    continue;
                }

                // Filter by Module
                // Allow bigbluebuttonbn module (lowercase)
                if ($e->modulename !== 'attendance' && $e->modulename !== 'bigbluebuttonbn') {
                   continue; 
                }

                // Deduplicate BBB events (fuzzy match 10 mins)
                if ($e->modulename === 'bigbluebuttonbn') {
                    $isDuplicate = false;
                    foreach ($attendanceTimes as $attTime) {
                        if (abs($attTime - $e->timestart) <= 601) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if ($isDuplicate) {
                        continue;
                    }
                }

                $session_data = new stdClass();
                $session_data->id = $e->id; // Calendar event ID
                $session_data->startdate = $e->timestart;
                $session_data->enddate = $e->timestart + $e->timeduration;
                $session_data->name = $e->name; // e.g. "Asistencia..." or "Clase..."
                $session_data->type = ($class->type == 1 ? 'virtual' : 'physical'); // Default to class type
                $session_data->join_url = '';

                // Logic to enhance data based on event type
                if ($e->modulename === 'attendance') {
                     // Try to find if this attendance session is linked to a BBB activity
                     // Link: attendance_sessions.caleventid -> gmk_bbb_attendance_relation
                     $sql = "SELECT rel.bbbid, sess.id as sessionid
                             FROM {attendance_sessions} sess
                             JOIN {gmk_bbb_attendance_relation} rel ON rel.attendancesessionid = sess.id
                             WHERE sess.caleventid = :caleventid";
                     $rel = $DB->get_record_sql($sql, ['caleventid' => $e->id]);
                     
                     if ($rel && $rel->bbbid) {
                         $session_data->type = 'virtual';
                         try {
                              $cm = get_coursemodule_from_instance('bigbluebuttonbn', $rel->bbbid);
                              if ($cm) {
                                  // requires mod/bigbluebuttonbn/locallib.php if needed? usually autoloaded
                                  $session_data->join_url = \mod_bigbluebuttonbn\external\get_join_url::execute($cm->id)['join_url'] ?? '#';
                                  
                                  // Check for recordings
                                  $recordingId = $DB->get_field('bigbluebuttonbn_recordings', 'recordingid', ['bigbluebuttonbnid' => $rel->bbbactivityid]);
                                  if ($recordingId) {
                                      $session_data->recording_url = "https://bbb.isi.edu.pa/playback/presentation/2.3/" . $recordingId;
                                  }
                              }
                         } catch (\Throwable $ex) { 
                             $session_data->debug_error = $ex->getMessage();
                             // Fallback or log?
                         }
                     }
                } elseif ($e->modulename === 'bigbluebuttonbn') {
                    $session_data->type = 'virtual';
                    // It's a direct BBB event
                    if ($e->instance) {
                        try {
                             $cm = get_coursemodule_from_instance('bigbluebuttonbn', $e->instance);
                             if ($cm) {
                                 // Fetch guest status
                                 // Note: We avoid full mod_bigbluebuttonbn\locallib loading if possible, or use DB directly for speed in this list
                                 $bbb = $DB->get_record('bigbluebuttonbn', ['id' => $e->instance]);
                                 if ($bbb && !empty($bbb->guest)) {
                                     $session_data->guest_url = $CFG->wwwroot . '/mod/bigbluebuttonbn/guest_login.php?id=' . $cm->id;
                                 }
                                 
                                 $session_data->join_url = \mod_bigbluebuttonbn\external\get_join_url::execute($cm->id)['join_url'] ?? '#';
                             }
                        } catch (\Throwable $ex) { 
                            $session_data->debug_error = $ex->getMessage();
                        }
                    }
                }

                $formatted_sessions[] = $session_data;

                } catch (\Throwable $t) {
                    // Log error if needed, but keeping it silent for production or use standard logging
                }
            }
            
            // Sort by start date ASC
            usort($formatted_sessions, function($a, $b) {
                return $a->startdate - $b->startdate;
            });

            $response = [
                'status' => 'success',
                'data' => [
                    'class' => $class,
                    'sessions' => array_values($formatted_sessions) // Send as 'sessions' like ManageClass.js expects
                ]
            ];
            break;

        case 'local_grupomakro_get_class_grades':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            $courseid = $class->corecourseid;
            $groupid = $class->groupid;

            // 1. Fetch Students (Rows)
            $students = $DB->get_records_sql("
                SELECT u.id, u.firstname, u.lastname, u.email, u.idnumber
                FROM {groups_members} gm
                JOIN {user} u ON u.id = gm.userid
                WHERE gm.groupid = :groupid
                ORDER BY u.lastname, u.firstname
            ", ['groupid' => $groupid]);

            if (empty($students)) {
                $response = ['status' => 'success', 'data' => ['columns' => [], 'students' => []]];
                break;
            }

            $userids = array_keys($students);

            // 2. Fetch Grade Items (Columns)
            require_once($CFG->libdir . '/gradelib.php');
            $grade_items = \grade_item::fetch_all(['courseid' => $courseid]);
            
            $columns = [];
            $item_ids = [];

            // Sort items so total is at the end
            usort($grade_items, function($a, $b) {
                if ($a->itemtype === 'course') return 1;
                if ($b->itemtype === 'course') return -1;
                return $a->sortorder - $b->sortorder;
            });

            foreach ($grade_items as $gi) {
                // Determine if it's a total
                $is_total = ($gi->itemtype === 'course' || $gi->itemtype === 'category');
                
                $columns[] = [
                    'id' => $gi->id,
                    'title' => $gi->itemname ?: ($gi->itemtype === 'course' ? "Total del Curso" : $gi->itemtype),
                    'max_grade' => (float)$gi->grademax % 1 === 0 ? (int)$gi->grademax : round($gi->grademax, 1),
                    'weight' => $gi->aggregationcoef,
                    'is_total' => $is_total,
                    'itemtype' => $gi->itemtype
                ];
                $item_ids[] = (int)$gi->id;
            }

            // 3. Fetch Grades (Cells) - BULK QUERY for performance
            list($userinsql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            list($iteminsql, $itemparams) = $DB->get_in_or_equal($item_ids, SQL_PARAMS_NAMED, 'i');
            
            $sql = "SELECT id, itemid, userid, finalgrade 
                    FROM {grade_grades} 
                    WHERE userid $userinsql AND itemid $iteminsql";
            
            $all_grades = $DB->get_records_sql($sql, array_merge($userparams, $itemparams));
            
            // Map grades to [userid][itemid]
            $grades_map = [];
            foreach ($all_grades as $g) {
                $grades_map[$g->userid][$g->itemid] = $g->finalgrade;
            }

            $grades_data = [];
            foreach ($students as $student) {
                $student_row = [
                    'id' => $student->id,
                    'fullname' => $student->firstname . ' ' . $student->lastname,
                    'email' => $student->email,
                    'grades' => []
                ];

                foreach ($item_ids as $iid) {
                    $val = isset($grades_map[$student->id][$iid]) ? $grades_map[$student->id][$iid] : '-';
                    $student_row['grades'][$iid] = $val;
                }
                $grades_data[] = $student_row;
            }

            $response = [
                'status' => 'success',
                'data' => [
                    'columns' => $columns,
                    'students' => $grades_data
                ]
            ];
            break;

        case 'local_grupomakro_get_gradebook_structure':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            $courseid = $class->corecourseid;
            
            require_once($CFG->libdir . '/gradelib.php');
            
            // Get course category to determine aggregation method
            $target_cat = \grade_category::fetch_course_category($courseid);
            // If class has a specific category, use it for aggregation context
            if (!empty($class->gradecategoryid)) {
                $class_cat = \grade_category::fetch(['id' => $class->gradecategoryid]);
                if ($class_cat) $target_cat = $class_cat;
            }
            $aggregation = $target_cat->aggregation; 

            // Fetch ALL items in course
            $grade_items = \grade_item::fetch_all(['courseid' => $courseid]);
            
            $items = [];
            $total_weight = 0;
            $items_for_calc = [];

            foreach ($grade_items as $gi) {
                if ($gi->itemtype == 'course' || $gi->itemtype == 'category') continue;
                
                // HIDE "Nota Final Integrada" from the UI list as requested.
                // It will be managed automatically in the backend.
                if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) {
                    continue;
                }

                $weight = 0;
                $parent_cat = $gi->get_parent_category();
                $is_natural = ($parent_cat && $parent_cat->aggregation == 13);
                
                if ($is_natural) {
                   $weight = (float)$gi->aggregationcoef2;
                } else {
                   $weight = (float)$gi->aggregationcoef;
                }

                $items[] = [
                    'id' => $gi->id,
                    'itemname' => $gi->itemname ?: ($gi->itemtype . ' ' . $gi->itemmodule),
                    'itemtype' => $gi->itemtype,
                    'itemmodule' => $gi->itemmodule,
                    'weight' => $weight,
                    'grademax' => (float)$gi->grademax,
                    'locked' => $gi->locked,
                    'hidden' => (int)$gi->hidden,
                    'aggregationcoef2' => (float)$gi->aggregationcoef2, // For debugging/reference
                    'is_natural' => $is_natural
                ];
            }

            // If Natural and total weight is 0 (all auto), or mixed, we might want to 
            // return the calculated weights?
            // Actually, frontend calculates total. If it returns 0s, frontend shows 0s.
            // If the user wants to EDIT, they set a value.
            // But user says "Moodle shows 19.231".
            // That means Moodle is calculating it.
            // We should try to provide that calculated value for reference or init.
            
            // Calculate referential percentage for initial display
            $sum_max = 0;
            $sum_weights = 0;
            foreach ($items as $it) {
                $sum_max += $it['grademax'];
                $sum_weights += $it['weight'];
            }

            foreach ($items as &$it) {
                if ($sum_weights > 0) {
                    $it['percentage'] = ($it['weight'] / $sum_weights) * 100;
                } else if ($sum_max > 0) {
                    // Estimate if all weights are zero
                    $it['percentage'] = ($it['grademax'] / $sum_max) * 100;
                } else {
                    $it['percentage'] = 0;
                }
            }

            $response = [
                'status' => 'success',
                'items' => $items,
                'total_weight' => ($sum_weights > 0) ? 100 : 0, // Total refers to percentage sum
                'aggregation' => $aggregation
            ];
            break;

        case 'local_grupomakro_update_grade_weights':
            $classid = required_param('classid', PARAM_INT);
            $weights_json = required_param('weights', PARAM_RAW);
            $weights = json_decode($weights_json, true);
            
            // New: Optional sort order
            $sort_order_json = optional_param('sortorder', '', PARAM_RAW);
            $sort_order = !empty($sort_order_json) ? json_decode($sort_order_json, true) : null;

            if (!is_array($weights)) throw new Exception("Datos inválidos.");

            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");

            require_once($CFG->libdir . '/gradelib.php');

            // Determine aggregation method using same logic as fetch
            $target_cat = \grade_category::fetch_course_category($class->corecourseid);
            if (!empty($class->gradecategoryid)) {
                $class_cat = \grade_category::fetch(['id' => $class->gradecategoryid]);
                if ($class_cat) $target_cat = $class_cat;
            }
            $aggregation = $target_cat->aggregation; 
            $is_natural = ($aggregation == 13);

            $tx = $DB->start_delegated_transaction();
            try {
                // 1. Update Weights and Visibility
                foreach ($weights as $w) {
                    $gi = \grade_item::fetch(['id' => $w['id'], 'courseid' => $class->corecourseid]);
                    if ($gi) {
                        // Update Weight based on parent category aggregation
                        $parent_cat = $gi->get_parent_category();
                        $is_natural = ($parent_cat && $parent_cat->aggregation == 13);

                        if ($is_natural) {
                            $gi->aggregationcoef2 = (float)$w['weight'];
                            $gi->weightoverride = 1; 
                            $gi->update('aggregationcoef2');
                            $gi->update('weightoverride');
                        } else {
                            $gi->aggregationcoef = (float)$w['weight'];
                            $gi->update('aggregationcoef');
                        }

                        // Update Visibility if provided
                        if (isset($w['hidden'])) {
                            $gi->set_hidden($w['hidden'] ? 1 : 0);
                        }

                        // ENFORCE Grademax = 100 (Except for "Nota Final Integrada" which is migrated data)
                        $is_migrated = ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false);
                        if (!$is_migrated && $gi->grademax != 100) {
                            $gi->grademax = 100;
                            $gi->update('grademax');
                        }
                    }
                }

                // 2. AUTOMATIC AGGREGATION FOR MIGRATED GRADES
                // If "Nota Final Integrada" exists, enforce "Highest Grade" (8) at the root
                // so migrated grades act as a competitor/alternative to the current category.
                $all_gi = \grade_item::fetch_all(['courseid' => $class->corecourseid]);
                foreach ($all_gi as $gi) {
                    if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) {
                        // Ensure it has a weight so it's not and-ed out if we were in Weighted Mean
                        if ($gi->aggregationcoef <= 0) {
                            $gi->aggregationcoef = 1.0;
                            $gi->update('aggregationcoef');
                        }
                        if ($gi->aggregationcoef2 <= 0) {
                            $gi->aggregationcoef2 = 1.0;
                            $gi->update('aggregationcoef2');
                        }
                        
                        // Set Root to Highest Grade
                        $root_cat = \grade_category::fetch_course_category($class->corecourseid);
                        if ($root_cat && $root_cat->aggregation != 8) {
                            $root_cat->aggregation = 8;
                            $root_cat->update();
                        }
                        break;
                    }
                }

                // 3. Update Sort Order if provided
                if (is_array($sort_order)) {
                    $anchor_sortorder = 0; // Move to beginning of course sequence
                    foreach ($sort_order as $itemid) {
                        $gi = \grade_item::fetch(['id' => $itemid, 'courseid' => $class->corecourseid]);
                        if ($gi) {
                            $gi->move_after_sortorder($anchor_sortorder);
                            // After move, the item's sortorder is updated. Use it as next anchor.
                            $anchor_sortorder = $gi->sortorder;
                        }
                    }
                }

                $tx->allow_commit();
                
                // FORCE REGRADE
                \grade_regrade_final_grades($class->corecourseid);

                $response = ['status' => 'success', 'message' => 'Configuración actualizada.'];
            } catch (Exception $e) {
                $tx->rollback($e);
                throw $e;
            }
            break;

        case 'local_grupomakro_create_manual_grade_item':
            $classid = required_param('classid', PARAM_INT);
            $name = required_param('name', PARAM_TEXT);
            $maxmark = optional_param('maxmark', 100, PARAM_INT); // Default to 100 as requested

            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");

            require_once($CFG->libdir . '/gradelib.php');

            // Find the class grade category to put this item in
            // We usually put it in the main course category or a specific one?
            // The logic in create_class creates a category for the class (gradecategoryid)
            // Let's use that if available to keep things organized.
            
            $parent_category_id = null;
            if (!empty($class->gradecategoryid)) {
                $parent_category_id = $class->gradecategoryid;
            } else {
                // Fallback to course default
                 $course_cat = \grade_category::fetch_course_category($class->corecourseid);
                 $parent_category_id = $course_cat->id;
            }

            $grade_item = new \grade_item();
            $grade_item->courseid = $class->corecourseid;
            $grade_item->categoryid = $parent_category_id;
            $grade_item->itemname = $name;
            $grade_item->itemtype = 'manual';
            $grade_item->grademax = $maxmark;
            $grade_item->grademin = 0;
            $grade_item->aggregationcoef = 0; // Default 0 weight
            $grade_item->insert();

            $response = ['status' => 'success', 'message' => 'Columna creada.', 'id' => $grade_item->id];
            break;

        case 'local_grupomakro_delete_grade_item':
            $itemid = required_param('itemid', PARAM_INT);
            require_once($CFG->libdir . '/gradelib.php');

            $gi = \grade_item::fetch(['id' => $itemid]);
            if (!$gi) throw new Exception("Ítem no encontrado.");
            
            // Security check: Only manual items? Or allow deleting activities?
            // Safer to allow only manual for now, deleting activities deletes the module which is dangerous here.
            if ($gi->itemtype !== 'manual') {
                throw new Exception("Solo se pueden eliminar ítems manuales desde aquí.");
            }

            $gi->delete();
            $response = ['status' => 'success', 'message' => 'Ítem eliminado.'];
            break;

        case 'local_grupomakro_get_all_activities':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            // Set context for get_icon_url and other core functions
            $context = context_course::instance($class->corecourseid);
            $PAGE->set_context($context);
            
            require_once($CFG->libdir . '/modinfolib.php');
            $modinfo = get_fast_modinfo($class->corecourseid);
            $cms = $modinfo->get_cms();
            
            // Get excluded BBB instances (those used in timeline/attendance)
            $excluded_instances = $DB->get_fieldset_select('gmk_bbb_attendance_relation', 'bbbid', 'classid = :classid AND bbbid IS NOT NULL', ['classid' => $class->id]);
            // Ensure we have an array
            if (!$excluded_instances) {
                $excluded_instances = [];
            }

            $activities = [];
            
            foreach ($cms as $cm) {
                if (!$cm->uservisible) continue;
                // Exclude label
                if ($cm->modname === 'label') continue;
                
                // Exclude class BBB sessions linked to attendance
                if ($cm->modname === 'bigbluebuttonbn' && in_array($cm->instance, $excluded_instances)) {
                    continue;
                }

                $tags = \core_tag_tag::get_item_tags('core', 'course_modules', $cm->id);
                $tagNames = array_map(function($t) { return $t->rawname; }, $tags);

                $activities[] = [
                    'id' => $cm->id,
                    'name' => $cm->name,
                    'modname' => $cm->modname,
                    'modicon' => $cm->get_icon_url()->out(),
                    'url' => $cm->url ? $cm->url->out(false) : '',
                    'tags' => array_values($tagNames) // Ensure array for JSON
                ];
            }
            
            $response = ['status' => 'success', 'activities' => $activities];
            break;

        case 'local_grupomakro_get_available_modules':
            $modules = $DB->get_records('modules', ['visible' => 1], 'name ASC');
            $available = [];
            $exclude = ['label', 'forum', 'quiz']; // These are already handled or special? 
            // Actually user wants "Others" to show the rest. If we show all, we duplicate.
            // But having a full list is safer for "Generic" selector. 
            // Let's just return all and let frontend decide or just show all in the dropdown.
            
            foreach ($modules as $m) {
                try {
                    $label = get_string('modulename', $m->name);
                } catch (Exception $e) {
                    $label = $m->name;
                }
                $available[] = [
                    'name' => $m->name,
                    'label' => $label
                ];
            }
            
            usort($available, function($a, $b) {
                return strcmp($a['label'], $b['label']);
            });
            
            $response = ['status' => 'success', 'modules' => $available];
            break;

        case 'local_grupomakro_get_question_details':
            $questionid = required_param('questionid', PARAM_INT);
            try {
                require_once($CFG->libdir . '/questionlib.php');
                $qdata = question_bank::load_question_data($questionid);
                if (!$qdata) throw new Exception("Pregunta no encontrada.");

                // Map to frontend structure
                $details = [
                    'id' => $qdata->id,
                    'type' => $qdata->qtype,
                    'name' => $qdata->name,
                    'questiontext' => $qdata->questiontext,
                    'defaultmark' => (float)$qdata->defaultmark,
                    'answers' => [],
                    'subquestions' => [],
                    'draggables' => [],
                    'drops' => [],
                    'dataset' => []
                ];

                // Type Specific Mapping
                $raw_answers = [];
                if (($qdata->qtype === 'ddwtos' || $qdata->qtype === 'gapselect') && isset($qdata->options->choices)) {
                    $raw_answers = $qdata->options->choices;
                } elseif (isset($qdata->answers) && !empty($qdata->answers)) {
                    $raw_answers = $qdata->answers;
                } elseif (isset($qdata->options->answers)) {
                    $raw_answers = $qdata->options->answers;
                } elseif (isset($qdata->options->choices)) {
                    $raw_answers = $qdata->options->choices;
                }

                foreach ($raw_answers as $ans) {
                    $group = 1;
                    $infinite = 0;
                    
                    // Try standard fields first
                    if (isset($ans->draggroup)) $group = (int)$ans->draggroup;
                    elseif (isset($ans->choicegroup)) $group = (int)$ans->choicegroup;
                    elseif (isset($ans->selectgroup)) $group = (int)$ans->selectgroup;
                    elseif (isset($ans->group)) $group = (int)$ans->group;
                    
                    // Fallback for objects returned by load_question_data
                    if ($group === 1) {
                         if (isset($ans->options) && isset($ans->options->selectgroup)) $group = (int)$ans->options->selectgroup;
                         elseif (isset($ans->selectgroup)) $group = (int)$ans->selectgroup;
                         elseif (isset($ans->choicegroup)) $group = (int)$ans->choicegroup;
                         elseif (isset($ans->draggroup)) $group = (int)$ans->draggroup;
                    }

                    // Fallback: Check for serialized data in feedback
                    if (isset($ans->feedback) && ($qdata->qtype === 'ddwtos' || $qdata->qtype === 'gapselect')) {
                         $fb_text = is_string($ans->feedback) ? $ans->feedback : ($ans->feedback->text ?? '');
                         if (!empty($fb_text)) {
                              if (($settings = @unserialize($fb_text)) !== false) {
                                   if (isset($settings->draggroup)) $group = (int)$settings->draggroup;
                                   if (isset($settings->selectgroup)) $group = (int)$settings->selectgroup;
                                   if (isset($settings->choicegroup)) $group = (int)$settings->choicegroup;
                                   if (isset($settings->infinite)) $infinite = (int)$settings->infinite;
                              } elseif (is_numeric($fb_text)) {
                                   // GapSelect usually stores the group number directly in feedback text in some cases
                                   $group = (int)$fb_text;
                              }
                         }
                    }

                    $ans_text = (string)($ans->answer ?? ($ans->text ?? ''));

                    $details['answers'][] = [
                        'id' => $ans->id,
                        'text' => $ans_text,
                        'fraction' => (float)($ans->fraction ?? 0),
                        'tolerance' => isset($ans->tolerance) ? (float)$ans->tolerance : 0,
                        'feedback' => '', 
                        'group' => $group,
                        'infinite' => $infinite
                    ];
                }

                if ($qdata->qtype === 'match' && isset($qdata->options->subquestions)) {
                    foreach ($qdata->options->subquestions as $sub) {
                        $details['subquestions'][] = [
                            'text' => $sub->questiontext,
                            'answer' => $sub->answertext
                        ];
                    }
                }

                if ($qdata->qtype === 'ddimageortext' || $qdata->qtype === 'ddmarker') {
                    // Background Image URL
                    $fs = get_file_storage();
                    $filearea = 'bgimage';
                    $component = 'qtype_' . $qdata->qtype;
                    $files = $fs->get_area_files($qdata->contextid, $component, $filearea, $qdata->id, 'itemid, filepath, filename', false);
                    if (!empty($files)) {
                        $file = reset($files);
                        $content = $file->get_content();
                        $mimetype = $file->get_mimetype();
                        $details['ddbase64'] = 'data:' . $mimetype . ';base64,' . base64_encode($content);
                    }

                    if (isset($qdata->options->drags)) {
                        foreach ($qdata->options->drags as $drag) {
                            $details['draggables'][] = [
                                'type' => $drag->dragitemtype ?? 'text',
                                'text' => $drag->label ?? '',
                                'group' => (int)($drag->draggroup ?? 1),
                                'infinite' => isset($drag->noofdrags) ? ((int)$drag->noofdrags === 0) : (bool)($drag->infinite ?? true)
                            ];
                        }
                    }
                    if (isset($qdata->options->drops)) {
                        foreach ($qdata->options->drops as $drop) {
                            $x = 0; $y = 0;
                            if ($qdata->qtype === 'ddmarker' && !empty($drop->coords)) {
                                $parts = explode(';', $drop->coords);
                                $coords = explode(',', $parts[0]);
                                $x = (int)$coords[0];
                                $y = (int)$coords[1];
                            } else {
                                $x = (int)($drop->xleft ?? $drop->x ?? 0);
                                $y = (int)($drop->ytop ?? $drop->y ?? 0);
                            }
                            $details['drops'][] = [
                                'choice' => (int)$drop->choice,
                                'x' => $x,
                                'y' => $y
                            ];
                        }
                    }
                }

                // Reconstruct Cloze text if applicable (Moodle stores markers like {#1})
                if ($qdata->qtype === 'multianswer' && isset($qdata->options->questions)) {
                    $text = $qdata->questiontext;
                    foreach ($qdata->options->questions as $seq => $subq) {
                        $text = str_replace('{#'.$seq.'}', $subq->questiontext, $text);
                    }
                    $details['questiontext'] = $text;
                }

                // Detect if question belongs to a course-level category
                $cat_context = context::instance_by_id($qdata->contextid);
                $details['save_to_course'] = ($cat_context->contextlevel == CONTEXT_COURSE);

                $response = ['status' => 'success', 'question' => $details];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_quiz_questions':
            $cmid = required_param('cmid', PARAM_INT);
            $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
            $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
            
            // Validate context (teacher)
            $context = context_module::instance($cmid);
            
            // Permission Logic with Fallback
            if (!has_capability('mod/quiz:manage', $context)) {
                $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
                $is_gmk_instructor = $DB->record_exists('gmk_class', ['corecourseid' => $course->id, 'instructorid' => $USER->id]);
                if (!$is_gmk_instructor) {
                    require_capability('mod/quiz:manage', $context);
                }
            }
            
            // Moodle 4.0+ Compatible Query (quiz_slots -> references -> versions -> question)
            $sql = "SELECT q.id, q.name, q.questiontext, q.qtype, s.slot, s.maxmark
                    FROM {quiz_slots} s
                    JOIN {question_references} qr ON qr.itemid = s.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                    JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    JOIN {question} q ON q.id = qv.questionid
                    WHERE s.quizid = :quizid
                    AND qr.component = 'mod_quiz'
                    AND qr.questionarea = 'slot'
                    AND qv.version = (
                        SELECT MAX(v.version)
                        FROM {question_versions} v
                        WHERE v.questionbankentryid = qbe.id
                    )
                    ORDER BY s.slot";
            
            $questions = $DB->get_records_sql($sql, ['quizid' => $quiz->id]);
            
            // Clean up content
            $clean_questions = [];
            foreach ($questions as $q) {
                $clean_questions[] = [
                    'id' => $q->id,
                    'name' => $q->name,
                    'questiontext' => strip_tags($q->questiontext), // Plain text preview
                    'qtype' => $q->qtype,
                    'slot' => $q->slot,
                    'maxmark' => (float)$q->maxmark
                ];
            }
            
            $response = ['status' => 'success', 'questions' => $clean_questions];
            break;

        case 'local_grupomakro_add_question':
            try {
                global $USER, $CFG; // Ensure $CFG is available
                require_once($CFG->libdir . '/questionlib.php');
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                // FIXED: Missing includes for question editing functions
                require_once($CFG->dirroot . '/question/editlib.php'); 
                require_once($CFG->dirroot . '/question/lib.php');

                $cmid = required_param('cmid', PARAM_INT);
                $qjson = required_param('question_data', PARAM_RAW);
                $data = json_decode($qjson);

                if (!$data) {
                    error_log("GMK_QUIZ_ERROR: Invalid JSON received: " . $qjson);
                    throw new Exception('Invalid JSON data');
                }
                
                // DEBUG: Log the type and indices for GapSelect investigation
                if ($data->type === 'gapselect' || $data->type === 'ddwtos') {
                    error_log("GMK_QUIZ_DEBUG: Saving {$data->type}. Question text: " . ($data->questiontext ?? 'EMPTY'));
                }

                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                $context = context_module::instance($cmid);
                
                // Permission Logic with Fallback
                if (!has_capability('mod/quiz:manage', $context)) {
                    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
                    // FIXED: Removed closed => 0 constraint to allow editing in closed classes if needed by instructor
                    $is_gmk_instructor = $DB->record_exists('gmk_class', ['corecourseid' => $course->id, 'instructorid' => $USER->id]);
                    if (!$is_gmk_instructor) {
                        require_capability('mod/quiz:manage', $context);
                    }
                }
                
                // Get target category based on 'save_to_course' flag
                $course_context = context_course::instance($cm->course);
                $save_to_course = isset($data->save_to_course) && $data->save_to_course;
                
                if ($save_to_course) {
                    $cat = question_get_default_category($course_context->id);
                } else {
                    $cat = question_get_default_category($context->id);
                    if (!$cat) {
                        $cat = question_get_default_category($course_context->id);
                    }
                }
                
                if (!$cat) throw new Exception('No question category found for the selected context.');

                // Prepare Question Object
                $question = new stdClass();
                if (!empty($data->id)) {
                    $question->id = (int)$data->id;
                    $old_question = question_bank::load_question_data($question->id);
                    $question->stamp = $old_question->stamp;
                    $question->version = $old_question->version;
                } else {
                    $question->stamp = make_unique_id_code();
                    $question->version = make_unique_id_code();
                }

                $question->qtype = $data->type;
                $question->name = $data->name;
                $question->questiontext = ['text' => isset($data->questiontext) ? $data->questiontext : (isset($data->text) ? $data->text : ''), 'format' => FORMAT_HTML];
                $question->defaultmark = isset($data->defaultmark) ? $data->defaultmark : 1;
                $question->category = $cat->id;
                $question->timecreated = time();
                $question->timemodified = time();
                $question->contextid = $cat->contextid;
                $question->context = context::instance_by_id($cat->contextid);
                $question->createdby = $USER->id;
                $question->modifiedby = $USER->id;

                $form_data = $question;

                // Type Specific Handling
                if ($data->type === 'truefalse') {
                    $question->correctanswer = $data->correctAnswer; 
                    $question->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
                    $question->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
                } 
                elseif ($data->type === 'multichoice') {
                    $question->single = isset($data->single) && $data->single ? 1 : 0;
                    $question->shuffleanswers = 1;
                    $question->answernumbering = 'abc';
                    
                    $question->answer = [];
                    $question->fraction = [];
                    $question->feedback = [];
                    
                    foreach ($data->answers as $ans) {
                        $question->answer[] = ['text' => $ans->text, 'format' => FORMAT_HTML];
                        $question->fraction[] = $ans->fraction;
                        $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                    }

                    // Combined Feedback Defaults
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;
                }
                elseif ($data->type === 'shortanswer') {
                     $question->usecase = 0; // Case insensitive
                     $question->answer = [];
                     $question->fraction = [];
                     $question->feedback = [];
 
                     foreach ($data->answers as $ans) {
                         $question->answer[] = $ans->text; // Plain string for shortanswer
                         $question->fraction[] = $ans->fraction;
                         $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                     }
                }
                elseif ($data->type === 'essay') {
                    $question->responseformat = 'editor';
                    $question->responsefieldlines = 15;
                    $question->attachments = 0;
                    $question->responserequired = 0; // Optional by default
                    $question->attachmentsrequired = 0;
                    $question->graderinfo = ['text' => '', 'format' => FORMAT_HTML];
                    $question->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];
                }
                elseif ($data->type === 'numerical') {
                    $question->answer = [];
                    $question->fraction = [];
                    $question->tolerance = [];
                    $question->feedback = [];

                    foreach ($data->answers as $ans) {
                        $question->answer[] = $ans->text; // Numerical value
                        $question->fraction[] = $ans->fraction;
                        $question->tolerance[] = isset($ans->tolerance) ? $ans->tolerance : 0;
                        $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                    }
                    if (!empty($data->unit)) {
                        $question->unit = [$data->unit]; // Basic unit support
                        $question->multiplier = [1.0];
                    }
                }
                elseif ($data->type === 'match') {
                    $question->shuffleanswers = isset($data->shuffleanswers) && $data->shuffleanswers ? 1 : 0;
                    $question->subquestions = [];
                    $question->subanswers = [];
                    
                    foreach ($data->subquestions as $sub) {
                        if (!empty($sub->text) && !empty($sub->answer)) {
                            $question->subquestions[] = ['text' => $sub->text, 'format' => FORMAT_HTML];
                            $question->subanswers[] = $sub->answer;
                        }
                    }

                    // Combined Feedback Defaults (Required for Match)
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;
                }
                elseif ($data->type === 'gapselect' || $data->type === 'ddwtos') {
                    $question->shuffleanswers = isset($data->shuffleanswers) && $data->shuffleanswers ? 1 : 0;
                    
                    // Create $form_data following Moodle's internal form structure
                    $form_data = clone $question;
                    $form_data->choices = [];
                    // Flattened arrays for compatibility with internal save_question transformations
                    $form_data->draglabel = [];
                    $form_data->draggroup = [];
                    $form_data->infinite = [];

                    if (isset($data->answers) && is_array($data->answers)) {
                        $question->answer = [];
                        $question->feedback = [];
                        $question->fraction = [];
                        $question->selectgroup = []; // Added for 0-indexed gapselect groups
                        $question->draggroup = [];   // Added for 0-indexed ddwtos groups

                        foreach ($data->answers as $idx => $ans) {
                            $no = $idx + 1; // Moodle forms usually use 1-based indexing
                            $text = is_string($ans->text) ? $ans->text : ($ans->text->text ?? '');
                            $group = isset($ans->group) ? (int)$ans->group : 1;

                            $choice_entry = [
                                'answer' => $text,
                                'choicegroup' => $group,
                                'selectgroup' => $group,
                                'draggroup' => $group,
                                'infinite' => 0,
                                'choiceno' => $no
                            ];
                            if (!empty($ans->id)) $choice_entry['id'] = $ans->id;

                            // Debug log choice
                            error_log("GMK_QUIZ_DEBUG: Choice #$no (Group $group): $text");

                            // Extensive mapping for all qtype variations
                            // CRITICAL FIX: Use 0-based indexing for form_data arrays to match question->answer
                            // This prevents Moodle from corrupting [[n]] markers during save_question validation.
                            $form_data->choices[$idx] = $choice_entry; // Was $no
                            $form_data->choice[$idx] = $choice_entry;
                            $form_data->drags[$idx] = [
                                'label' => $text,
                                'draggroup' => $group,
                                'infinite' => 0
                            ];

                            $form_data->selectgroup[$idx] = $group; // Was $no
                            $form_data->draggroup[$idx] = $group;   // Was $no
                            $form_data->choicegroup[$idx] = $group; // Was $no
                            $form_data->draglabel[$idx] = $text;    // Was $no

                            if ($data->type === 'gapselect') {
                                $question->answer[] = $text;
                                $question->feedback[] = $group; // GapSelect expects the group number directly
                                $question->selectgroup[] = $group; // 0-indexed for Moodle's loop
                            } else {
                                $extra_settings = new stdClass();
                                $extra_settings->draggroup = $group;
                                $extra_settings->selectgroup = $group;
                                $extra_settings->infinite = 0;
                                $question->answer[] = $text;
                                $question->feedback[] = serialize($extra_settings);
                                $question->draggroup[] = $group; // 0-indexed
                            }
                            $question->fraction[] = 0.0;
                        }
                    }
                    
                    // Specific fields to ensure save_question sees EVERYTHING
                    $question->choices = $form_data->choices;
                    $question->shuffleanswers = $form_data->shuffleanswers;
                    
                    // Combined Feedback Defaults
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;

                    $form_data->correctfeedback = $question->correctfeedback;
                    $form_data->partiallycorrectfeedback = $question->partiallycorrectfeedback;
                    $form_data->incorrectfeedback = $question->incorrectfeedback;
                    $form_data->shownumcorrect = 1;
                }
                elseif ($data->type === 'multianswer') {
                     // No specific extra fields, code is in questiontext
                }
                elseif ($data->type === 'randomsamatch') {
                    $question->choose = isset($data->choose) ? $data->choose : 2;
                    $question->subcats = isset($data->subcats) && $data->subcats ? 1 : 0;
                    $question->shuffleanswers = 1;
                    
                    // Combined Feedback Defaults (Required to avoid NULL errors in DB)
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;
                }
                elseif (strpos($data->type, 'calculated') === 0) {
                     // Basic support for Calculated types
                     $question->answers = [];
                     $question->fraction = [];
                     $question->tolerance = [];
                     $question->tolerancetype = [];
                     $question->correctanswerlength = [];
                     $question->correctanswerformat = [];
                     $question->feedback = [];
                     
                     if (isset($data->answers) && is_array($data->answers)) {
                        foreach ($data->answers as $ans) {
                            $question->answer[] = $ans->text; // Formula
                            $question->fraction[] = isset($ans->fraction) ? (float)$ans->fraction : 1.0;
                            $question->tolerance[] = isset($ans->tolerance) ? $ans->tolerance : 0.01;
                            $question->tolerancetype[] = 1; // Relative
                            $question->correctanswerlength[] = 2; 
                            $question->correctanswerformat[] = 1; // Decimals
                            $question->feedback[] = ['text' => isset($ans->feedback) ? $ans->feedback : '', 'format' => FORMAT_HTML];
                        }
                     }
                     // Unit support
                     $question->unit = [isset($data->unit) ? $data->unit : ''];
                     $question->multiplier = [1.0];
                     
                     // Dataset Mapping (FIX: prevent corruption/missing items)
                     // Always initialize dataset array to avoid "undefined" behavior in save_question
                     $form_data->dataset = [];
                     
                     // 1. Auto-detect wildcards in formulas AND question text ALWAYS
                     // We merge this with any existing data to ensure nothing is lost during edits.
                     $detected_wildcards = [];
                     
                     // Scan Answer Formulas
                     foreach ($question->answer as $formula) {
                         if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $formula, $matches)) {
                             foreach ($matches[1] as $wildcard) {
                                 $detected_wildcards[$wildcard] = true;
                             }
                         }
                     }
                     // Scan Question Text (Crucial so students can see the variables)
                     if (isset($question->questiontext['text'])) {
                         if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $question->questiontext['text'], $matches)) {
                             foreach ($matches[1] as $wildcard) {
                                 $detected_wildcards[$wildcard] = true;
                             }
                         }
                     }
                     
                     // Merge detected wildcards into $data->dataset
                     if (!isset($data->dataset) || !is_array($data->dataset)) {
                         $data->dataset = [];
                     }
                     
                     // Map existing dataset names to avoid duplicates
                     $existing_names = [];
                     foreach ($data->dataset as $ds) {
                         if (isset($ds->name)) $existing_names[$ds->name] = true;
                     }
                     
                     // Add any newly detected wildcards that aren't in the dataset list
                     foreach (array_keys($detected_wildcards) as $wc) {
                         if (!isset($existing_names[$wc])) {
                             $ds = new stdClass();
                             $ds->name = $wc;
                             $ds->min = 1;
                             $ds->max = 10;
                             $ds->items = []; 
                             $data->dataset[] = $ds;
                             $existing_names[$wc] = true; // Mark as added
                         }
                     }

                     // Now process the dataset array to populate form_data
                     if (isset($data->dataset) && is_array($data->dataset)) {
                         foreach ($data->dataset as $ds) {
                              $name = $ds->name; // e.g. {x}
                              $form_data->dataset[] = $name;
                              
                              // We simulate the form data properties Moodle expects for each wildcard
                              $form_data->{"dataset_$name"} = '0'; // 0 = Reuse existing
                              $form_data->{"number_$name"} = (isset($ds->items) && !empty($ds->items)) ? count($ds->items) : 10;
                              $form_data->{"options_$name"} = 'uniform'; 
                              $form_data->{"calcmin_$name"} = isset($ds->min) ? $ds->min : 1;
                              $form_data->{"calcmax_$name"} = isset($ds->max) ? $ds->max : 10;
                              $form_data->{"calclength_$name"} = 1;
                              $form_data->{"calcdistribution_$name"} = isset($ds->distribution) ? $ds->distribution : 'uniform';
                         }
                     }
                     
                     // Options fields (FIXED: Required by qtype_calculated_options table)
                     $question->synchronize = 0;
                     $question->single = ($data->type === 'calculatedmulti') ? (isset($data->single) ? ($data->single ? 1 : 0) : 1) : 1;
                     $question->answernumbering = 'abc';
                     $question->shuffleanswers = isset($data->shuffleanswers) ? ($data->shuffleanswers ? 1 : 0) : 0;
                     
                     // Combined Feedback (Required fields for calculated_options)
                     $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                     $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                     $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                     $question->shownumcorrect = 1;
                }
                elseif ($data->type === 'ddimageortext' || $data->type === 'ddmarker') {
                    $question->shuffleanswers = 1;
                    
                    // Create $form_data following Moodle's internal form structure
                    $form_data = clone $question;
                    $form_data->drags = [];
                    $form_data->drops = [];
                    $form_data->draglabel = []; // Specific for ddimageortext
                    $form_data->dragitem = [];  // Specific for ddimageortext

                    // Ensure context compatibility
                    if (!empty($data->id)) {
                        $question->contextid = $old_question->contextid;
                        $form_data->contextid = $old_question->contextid;
                    }

                    // Background Image
                    if (!empty($_FILES['bgimage'])) {
                        $draftitemid = file_get_unused_draft_itemid();
                        $usercontext = context_user::instance($USER->id);
                        $fs = get_file_storage();
                        $filerecord = array(
                            'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
                            'itemid' => $draftitemid, 'filepath' => '/', 'filename' => $_FILES['bgimage']['name']
                        );
                        $fs->create_file_from_pathname($filerecord, $_FILES['bgimage']['tmp_name']);
                        $question->bgimage = $draftitemid;
                        $form_data->bgimage = $draftitemid;
                    } elseif (!empty($data->id)) {
                        // Keep existing image if editing
                        $fs = get_file_storage();
                        $old_files = $fs->get_area_files($old_question->contextid, 'qtype_'.$data->type, 'bgimage', $old_question->id, 'id, itemid, filepath, filename', false);
                        if (!empty($old_files)) {
                            $old_file = reset($old_files);
                            $draftitemid = file_get_unused_draft_itemid();
                            $usercontext = context_user::instance($USER->id);
                            $filerecord = array(
                                'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
                                'itemid' => $draftitemid, 'filepath' => '/', 'filename' => $old_file->get_filename()
                            );
                            $fs->create_file_from_storedfile($filerecord, $old_file);
                            $question->bgimage = $draftitemid;
                            $form_data->bgimage = $draftitemid;
                        }
                    }

                    // Process Draggables
                    if (isset($data->draggables) && is_array($data->draggables)) {
                        foreach ($data->draggables as $idx => $drag) {
                            $no = $idx;
                            $label = !empty($drag->text) ? (string)$drag->text : ' ';
                            
                            if ($data->type === 'ddimageortext') {
                                $form_data->draglabel[$no] = $label;
                                $form_data->dragitem[$no] = 0;
                                $form_data->drags[$no] = [
                                    'draggroup' => isset($drag->group) ? (int)$drag->group : 1,
                                    'infinite' => !empty($drag->infinite) ? 1 : 0,
                                    'dragitemtype' => 'text'
                                ];
                            } else {
                                $form_data->drags[$no] = [
                                    'label' => $label,
                                    'noofdrags' => !empty($drag->infinite) ? 0 : 1
                                ];
                            }
                        }
                    }

                    // Process Drops
                    if (isset($data->drops) && is_array($data->drops)) {
                        foreach ($data->drops as $idx => $d) {
                            $no = $idx;
                            if ($data->type === 'ddimageortext') {
                                $form_data->drops[$no] = [
                                    'choice' => (int)$d->choice,
                                    'xleft' => (int)$d->x,
                                    'ytop' => (int)$d->y,
                                    'droplabel' => 'drop' . ($no + 1)
                                ];
                            } else {
                                $form_data->drops[$no] = [
                                    'choice' => (int)$d->choice,
                                    'shape' => 'circle',
                                    'coords' => sprintf('%d,%d;15', (int)$d->x, (int)$d->y)
                                ];
                            }
                        }
                    }
                    
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;

                    $form_data->correctfeedback = $question->correctfeedback;
                    $form_data->partiallycorrectfeedback = $question->partiallycorrectfeedback;
                    $form_data->incorrectfeedback = $question->incorrectfeedback;
                    $form_data->shownumcorrect = 1;
                    
                    if ($data->type === 'ddmarker') {
                        $form_data->showmisplaced = 1;
                    }
                }
                // ADD LOGGING: Final state of questiontext before saving
                error_log("GMK_QUIZ_DEBUG: Final Question Text to DB: " . (isset($question->questiontext['text']) ? $question->questiontext['text'] : 'NOT SET'));

                // SAVE or USE EXISTING (Duplicate detection)
                $newq = null;
                if (empty($data->id)) {
                    // Search for identical question in the target category (Duplicate detection)
                    $existing = $DB->get_record_sql("
                        SELECT q.id 
                        FROM {question} q
                        JOIN {question_versions} qv ON qv.questionid = q.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                        WHERE qbe.questioncategoryid = :categoryid
                        AND q.qtype = :qtype
                        AND q.questiontext = :questiontext
                        AND q.parent = 0
                        ORDER BY qv.version DESC
                        LIMIT 1", [
                            'categoryid' => $question->category,
                            'qtype' => $question->qtype,
                            'questiontext' => $question->questiontext['text']
                        ]);

                    if ($existing) {
                        $newq = $existing;
                    }
                }

                if (!$newq) {
                    gmk_log("Calling save_question for type: " . $question->qtype);
                    $qtypeobj = question_bank::get_qtype($question->qtype);
                    $newq = $qtypeobj->save_question($question, $form_data);
                    gmk_log("save_question returned ID: " . ($newq ? $newq->id : 'NULL'));
                }

                // Post-Save: Generate Items for Calculated Questions
                // SELF-HEALING LOGIC: Detects what SHOULD be there and ensures it exists in DB.
                // Relaxed condition: Check question object type OR input data type
                $is_calculated = (isset($question->qtype) && strpos($question->qtype, 'calculated') === 0) 
                              || (isset($data->type) && strpos($data->type, 'calculated') === 0);
                
                gmk_log("Checking Self-Healing Condition: IsCalculated=" . ($is_calculated ? 'YES' : 'NO') . ", NewQ=" . ($newq ? 'YES' : 'NO'));

                if ($newq && $is_calculated) {
                    gmk_log("GMK_QUIZ_DEBUG: Entering Calculated Self-Healing for QID " . $newq->id);
                    
                    // STRATEGY: Find ALL versions of this question (siblings) and fix them ALL.
                    // This handles cases where Moodle creates a new version (ID+1) that we missed.
                    $all_versions = [];
                    $all_versions[] = $newq->id; // Always include the current one
                    
                    try {
                        // Find Question Bank Entry ID AND Category (Moodle 4.x)
                        $entry_data = $DB->get_record_sql("
                            SELECT qv.questionbankentryid, qbe.questioncategoryid 
                            FROM {question_versions} qv 
                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                            WHERE qv.questionid = :qid", ['qid' => $newq->id]);
                            
                        if ($entry_data) {
                            $entry_id = $entry_data->questionbankentryid;
                            $master_category = $entry_data->questioncategoryid;
                            
                            $siblings = $DB->get_records_sql("
                                SELECT questionid 
                                FROM {question_versions} 
                                WHERE questionbankentryid = :entryid
                                ORDER BY version DESC
                                LIMIT 5", ['entryid' => $entry_id]); 
                                
                            foreach ($siblings as $sib) {
                                $all_versions[] = $sib->questionid;
                            }
                        } else {
                            $master_category = $newq->category; // Fallback
                        }
                    } catch (Exception $e) {
                        gmk_log("Error fetching siblings/category: " . $e->getMessage());
                        $master_category = $newq->category; // Fallback
                    }
                    
                    $all_versions = array_unique($all_versions);
                    gmk_log("Targeting Versions for Repair: " . implode(', ', $all_versions) . " (Category: $master_category)");

                    // LOOP THROUGH ALL VERSIONS
                    foreach ($all_versions as $target_qid) {
                        gmk_log("--- Repairing QID: $target_qid ---");
                        
                        // DIRECT DB QUERY STRATEGY
                        $expected_wildcards = [];
                        
                        // 1. Scan Answers directly from DB (Answer + Feedback)
                        $db_answers = $DB->get_records('question_answers', ['question' => $target_qid]);
                        
                        if ($db_answers) {
                            foreach ($db_answers as $ans) {
                                 // Check Answer Formula
                                 if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $ans->answer, $matches)) {
                                     foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                                 }
                                 // Check Feedback (Crucial for Calculated Multi)
                                 if (isset($ans->feedback) && preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $ans->feedback, $matches)) {
                                     foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                                 }
                            }
                        }
                        
                        // 2. Scan Question Text + General Feedback directly from DB
                        // corrected: 'category' is NOT in {question} table in Moodle 4+
                        $db_q = $DB->get_record('question', ['id' => $target_qid], 'questiontext, generalfeedback'); 
                        if ($db_q) {
                             if (isset($db_q->questiontext) && preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $db_q->questiontext, $matches)) {
                                 foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                             }
                             if (isset($db_q->generalfeedback) && preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $db_q->generalfeedback, $matches)) {
                                 foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                             }
                        }
                        
                        // Log detection for debugging
                        gmk_log("Detected wildcards for QID {$target_qid}: " . implode(', ', array_keys($expected_wildcards)));
    
                        foreach (array_keys($expected_wildcards) as $name) {
                             // 1. Find Definition
                             $def = $DB->get_record_sql("
                                SELECT * FROM {question_dataset_definitions} 
                                WHERE name = ? AND (category = 0 OR category = ?)
                                ORDER BY id DESC LIMIT 1
                             ", [$name, $master_category]);
                             
                             // 2. Create Definition if missing
                             if (!$def) {
                                 $def = new stdClass();
                                 $def->xmlid = '';
                                 $def->category = 0; 
                                 $def->name = $name;
                                 $def->type = 1; 
                                 $def->options = 'uniform'; 
                                 $def->itemcount = 0;
                                 $def->id = $DB->insert_record('question_dataset_definitions', $def);
                                 gmk_log("Created NEW Definition '{$name}' (ID: {$def->id})");
                             }
                             
                             // 3. Ensure Link exists
                             if (!$DB->record_exists('question_datasets', ['question' => $target_qid, 'datasetdefinition' => $def->id])) {
                                $link = new stdClass();
                                $link->question = $target_qid;
                                $link->datasetdefinition = $def->id;
                                $DB->insert_record('question_datasets', $link);
                                gmk_log("Restored Link Q {$target_qid} -> Def {$def->id}");
                             }
                             
                             // 4. Ensure Items exist
                             if ($DB->count_records('question_dataset_items', ['definition' => $def->id]) == 0) {
                                 // ... (Generation logic) ...
                                 // Simplified generation for siblings (using defaults)
                                 for ($i = 1; $i <= 10; $i++) {
                                     $val = 1.0 + (9.0 * (mt_rand() / mt_getrandmax()));
                                     $val = round($val, 1);
                                     $item = new stdClass();
                                     $item->definition = $def->id;
                                     $item->itemnumber = $i;
                                     $item->value = $val;
                                     $DB->insert_record('question_dataset_items', $item);
                                 }
                                 $DB->set_field('question_dataset_definitions', 'itemcount', 10, ['id' => $def->id]);
                                 gmk_log("Generated items for '{$name}'");
                             }
                        }
                    } // End foreach version
                    
                    // Force refresh main question instance
                    $newq = question_bank::load_question($newq->id);
                }

                // If editing (id is present), ensure the question_bank_entry is moved if category changed
                if (!empty($data->id)) {
                    // In Moodle 4.0+, the category is in question_bank_entries
                    $entryid = $DB->get_field_sql("
                        SELECT qv.questionbankentryid 
                        FROM {question_versions} qv 
                        WHERE qv.questionid = :questionid", ['questionid' => $newq->id]);
                    
                    if ($entryid) {
                        $DB->set_field('question_bank_entries', 'questioncategoryid', $question->category, ['id' => $entryid]);
                    }
                }
                
                // ADD TO QUIZ (Only if new to the quiz)
                if (empty($data->id)) {
                    // Moodle 4.0+: Check if question is already in the quiz
                    $already_in_quiz = $DB->record_exists_sql("
                        SELECT s.id 
                        FROM {quiz_slots} s
                        JOIN {question_references} qr ON qr.itemid = s.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                        JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                        WHERE s.quizid = :quizid 
                        AND qv.questionid = :questionid
                        AND qr.component = 'mod_quiz'
                        AND qr.questionarea = 'slot'
                    ", ['quizid' => $quiz->id, 'questionid' => $newq->id]);

                    if (!$already_in_quiz) {
                        quiz_add_quiz_question($newq->id, $quiz, 0, $question->defaultmark);
                        
                        // Force update sumgrades
                        quiz_update_sumgrades($quiz);
                    }
                }

                $response = ['status' => 'success', 'id' => $newq->id];

            } catch (Throwable $e) {
                // Return clear error message
                $response = ['status' => 'error', 'message' => 'Error al guardar: ' . $e->getMessage()];
                if (debugging()) {
                    $response['debug'] = $e->getTraceAsString();
                }
            }
            break;

        case 'local_grupomakro_sync_quiz_grades':
            $cmid = required_param('cmid', PARAM_INT);
            try {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                
                // Get all slots and their questions
                $sql = "SELECT s.id as slotid, q.defaultmark
                        FROM {quiz_slots} s
                        JOIN {question_references} qr ON qr.itemid = s.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                        JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                        JOIN {question} q ON q.id = qv.questionid
                        WHERE s.quizid = :quizid
                        AND qr.component = 'mod_quiz'
                        AND qr.questionarea = 'slot'
                        AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v WHERE v.questionbankentryid = qbe.id)";
                
                $slots = $DB->get_records_sql($sql, ['quizid' => $quiz->id]);
                foreach ($slots as $s) {
                    $DB->set_field('quiz_slots', 'maxmark', $s->defaultmark, ['id' => $s->slotid]);
                }
                
                quiz_update_sumgrades($quiz);
                
                $response = ['status' => 'success'];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_categories':
            try {
                $cmid = required_param('cmid', PARAM_INT);
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $course_context = context_course::instance($cm->course);
                $system_context = context_system::instance();
                
                // Fetch categories from course and system contexts
                $sql = "SELECT id, name, parent FROM {question_categories} 
                        WHERE contextid IN (:coursecontext, :systemcontext)
                        ORDER BY name ASC";
                $categories = $DB->get_records_sql($sql, [
                    'coursecontext' => $course_context->id,
                    'systemcontext' => $system_context->id
                ]);
                
                // Add question count for each category
                foreach ($categories as $cat) {
                    $cat->questioncount = $DB->count_records('question_bank_entries', ['questioncategoryid' => $cat->id]);
                }
                
                $response = ['status' => 'success', 'categories' => array_values($categories)];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_bank_questions':
            try {
                $categoryid = required_param('categoryid', PARAM_INT);
                
                // Moodle 4.0 Query for questions in category
                $sql = "SELECT q.id, q.name, q.questiontext, q.qtype
                        FROM {question} q
                        JOIN {question_versions} qv ON qv.questionid = q.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                        WHERE qbe.questioncategoryid = :categoryid
                        AND qv.version = (
                            SELECT MAX(v.version)
                            FROM {question_versions} v
                            WHERE v.questionbankentryid = qbe.id
                        )
                        AND q.parent = 0
                        ORDER BY q.name ASC";
                
                $questions = $DB->get_records_sql($sql, ['categoryid' => $categoryid]);
                
                $clean_questions = [];
                foreach ($questions as $q) {
                    $clean_questions[] = [
                        'id' => $q->id,
                        'name' => $q->name,
                        'questiontext' => strip_tags($q->questiontext),
                        'qtype' => $q->qtype
                    ];
                }
                
                $response = ['status' => 'success', 'questions' => $clean_questions];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_add_bank_question':
            try {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                $cmid = required_param('cmid', PARAM_INT);
                $questionid = required_param('questionid', PARAM_INT);
                
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                
                // Add to quiz using standard API
                quiz_add_quiz_question($questionid, $quiz);
                
                quiz_update_sumgrades($quiz);
                
                $response = ['status' => 'success'];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_remove_quiz_question':
            $cmid = required_param('cmid', PARAM_INT);
            $slot = required_param('slot', PARAM_INT);
            try {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
                
                // Moodle 4.0+ Compatible Slot Removal: Requires a 'quiz' object wrapper
                $quizobj = new quiz($quiz, $cm, $course);
                $structure = \mod_quiz\structure::create_for_quiz($quizobj);
                $structure->remove_slot($slot);
                
                $response = ['status' => 'success'];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;


        case 'local_grupomakro_create_express_activity':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/create_express_activity.php');
            $classid = required_param('classid', PARAM_INT);
            $type = required_param('type', PARAM_ALPHA);
            $name = required_param('name', PARAM_TEXT);
            $intro = optional_param('intro', '', PARAM_RAW);
            $duedate = optional_param('duedate', 0, PARAM_INT);
            $save_as_template = optional_param('save_as_template', false, PARAM_BOOL);
            $tags = optional_param('tags', '', PARAM_TEXT); // Receive tags as comma-separated string or array
            $gradecat = optional_param('gradecat', 0, PARAM_INT);
            $guest = optional_param('guest', false, PARAM_BOOL);
            
            // Normalize tags if passed as string
            $tagList = [];
            if (!empty($tags)) {
                if (is_string($tags)) {
                    $tagList = explode(',', $tags);
                } else if (is_array($tags)) {
                   $tagList = $tags;
                }
            }

            try {
                $result = \local_grupomakro_core\external\teacher\create_express_activity::execute(
                    $classid, $type, $name, $intro, $duedate, $save_as_template, $tagList, $gradecat, $guest
                );
                $response = ['status' => 'success', 'data' => $result];
            } catch (Exception $e) {
                 $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_attendance_sessions':
             require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');
             $classid = required_param('classid', PARAM_INT);
             try {
                $response = \local_grupomakro_core\external\teacher\attendance_manager::get_sessions($classid);
             } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
             }
             break;

        case 'local_grupomakro_get_session_qr':
             require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');
             $sessionid = required_param('sessionid', PARAM_INT);
             try {
                $response = \local_grupomakro_core\external\teacher\attendance_manager::get_qr($sessionid);
             } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
             }
             break;

        case 'get_sync_log':
            $logFile = make_temp_directory('grupomakro') . '/sync_progress.log';
            if (file_exists($logFile)) {
                $response['status'] = 'success';
                $response['log'] = file_get_contents($logFile);
                $response['message'] = 'Log retrieved.';
            } else {
                $response['status'] = 'success';
                $response['log'] = 'No hay logs disponibles.';
            }
            break;

        case 'local_grupomakro_get_course_grade_categories':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            require_once($CFG->libdir . '/gradelib.php');
            $categories = \grade_category::fetch_all(['courseid' => $class->corecourseid]);
            
            $formatted_cats = [];
            foreach ($categories as $cat) {
                 // Skip course total category itself if desired, or keep all
                 $formatted_cats[] = [
                     'id' => $cat->id,
                     'name' => $cat->get_name(),
                     'fullname' => $cat->get_formatted_name()
                 ];
            }
            // Sort by name
            usort($formatted_cats, function($a, $b) {
                return strcmp($a['fullname'], $b['fullname']);
            });

            $response = [
                'status' => 'success',
                'categories' => $formatted_cats
            ];
            break;

        case 'local_grupomakro_get_course_tags':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            $tags = gmk_get_course_tags($class->corecourseid);
            $response = [
                'status' => 'success',
                'tags' => $tags
            ];
            break;

        case 'local_grupomakro_get_activity_details':
            $cmid = required_param('cmid', PARAM_INT);
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            
            // Set context
            $context = context_module::instance($cm->id);
            $PAGE->set_context($context);

            $module_instance = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);
            
            $tags = core_tag_tag::get_item_tags('core', 'course_modules', $cm->id);
            $tagNames = array_map(function($t) { return $t->rawname; }, $tags);

            // Determine intro/description field (usually 'intro')
            $intro = isset($module_instance->intro) ? $module_instance->intro : '';
            
            $response = [
                'status' => 'success',
                'activity' => [
                    'id' => $cm->id,
                    'name' => $cm->name,
                    'modname' => $cm->modname, // For frontend logic if needed
                    'intro' => $intro,
                    'visible' => (bool)$cm->visible,
                    'tags' => array_values($tagNames),
                    'duedate' => isset($module_instance->duedate) ? (int)$module_instance->duedate : 0,
                    'timeopen' => isset($module_instance->timeopen) ? (int)$module_instance->timeopen : 0,
                    'timeclose' => isset($module_instance->timeclose) ? (int)$module_instance->timeclose : 0,
                    'attempts' => isset($module_instance->attempts) ? (int)$module_instance->attempts : 0,
                ]
            ];
            break;

        case 'local_grupomakro_get_guest_meetings':
            require_capability('moodle/site:config', context_system::instance());
            
            // Get all BBB activities with guest=1
            // We assume they are in site context (course 1) usually, but we can list all.
            // Joining course_modules to ensure they exist and get cmid
            // Get all BBB activities in Site Course (ID 1) as proxy for "Guest Meetings"
            // Since guest column is missing, we assume Admin created meetings are on Front Page
            $sql = "SELECT b.id, b.name, b.intro, b.timecreated, cm.id as cmid
                    FROM {bigbluebuttonbn} b
                    JOIN {course_modules} cm ON cm.instance = b.id
                    JOIN {modules} m ON m.id = cm.module
                    WHERE m.name = 'bigbluebuttonbn'
                    AND b.course = 1
                    ORDER BY b.timecreated DESC";
            
            $meetings = $DB->get_records_sql($sql);
            $result = [];
            foreach ($meetings as $m) {
                // Construct guest link using standard BBB plugin logic if guestlink is empty or just standard pattern
                // The pattern is usually /mod/bigbluebuttonbn/guest_login.php?id=[cmid]
                // OR checking if secret is used. But internal guest=1 usually enables the route.
                // CORRECTION: Plugin version is old and lacks guest_login.php. We typically redirect to our custom handler.
                $guest_url = $CFG->wwwroot . '/local/grupomakro_core/pages/guest_join.php?id=' . $m->cmid;
                
                $result[] = [
                    'id' => $m->id,
                    'cmid' => $m->cmid,
                    'name' => $m->name,
                    'timecreated' => $m->timecreated,
                    'guest_url' => $guest_url
                ];
            }
            $response = ['status' => 'success', 'meetings' => $result];
            break;

        case 'local_grupomakro_delete_guest_meeting':
            require_capability('moodle/site:config', context_system::instance());
            $cmid = required_param('cmid', PARAM_INT);
            course_delete_module($cmid);
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_update_activity':
            $cmid = required_param('cmid', PARAM_INT);
            $name = required_param('name', PARAM_TEXT);
            $intro = optional_param('intro', '', PARAM_RAW);
            $tags = optional_param('tags', [], PARAM_DEFAULT); // Array or comma list
            $visible = required_param('visible', PARAM_BOOL);
            
            // New optional params
            $duedate = optional_param('duedate', null, PARAM_INT);
            $timeopen = optional_param('timeopen', null, PARAM_INT);
            $timeclose = optional_param('timeclose', null, PARAM_INT);
            $attempts = optional_param('attempts', null, PARAM_INT);

            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $context = context_module::instance($cm->id);
            $PAGE->set_context($context);

            // Update specific module table (name, intro)
            $module_record = new stdClass();
            $module_record->id = $cm->instance;
            $module_record->name = $name;

            // Check columns to avoid errors
            $columns = $DB->get_columns($cm->modname);
            if (isset($columns['intro'])) {
                $module_record->intro = $intro;
            }
            if (isset($columns['timemodified'])) {
                $module_record->timemodified = time();
            }

            // Specific fields
            if ($cm->modname === 'assign' && $duedate !== null) {
                $module_record->duedate = $duedate;
            }
            if ($cm->modname === 'quiz') {
                if ($timeopen !== null) $module_record->timeopen = $timeopen;
                if ($timeclose !== null) $module_record->timeclose = $timeclose;
                if ($attempts !== null) $module_record->attempts = $attempts;
            }

            $DB->update_record($cm->modname, $module_record);

            // Update visibility
            set_coursemodule_visible($cmid, $visible ? 1 : 0);

            // Update Tags
            if (!is_array($tags)) {
                $tags = explode(',', $tags);
            }
            // Clean empty tags
            $tags = array_filter($tags, function($t) { return trim($t) !== ''; });
            core_tag_tag::set_item_tags('local_grupomakro_core', 'course_modules', $cm->id, $context, $tags);

            // Rebuild cache
            rebuild_course_cache($cm->course);

            $response = ['status' => 'success'];
            break;
        
        case 'local_grupomakro_get_learning_plan_list':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/learningPlan/get_learning_plan_list.php');
            $learningplanid = optional_param('learningPlanId', 0, PARAM_INT);
            $result = \local_grupomakro_core\external\learningPlan\get_learning_plan_list::execute($learningplanid);
            if (isset($result['learningPlans'])) {
                $response = ['status' => 'success', 'data' => json_decode($result['learningPlans'], true)];
            } else {
                $response = ['status' => 'error', 'message' => isset($result['message']) ? $result['message'] : 'Error loading plans'];
            }
            break;

        case 'local_grupomakro_get_planning_data':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_planning_data($periodid);
            $response = ['data' => $data, 'error' => false];
            break;

        case 'local_grupomakro_get_scheduler_context':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_scheduler_context($periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_get_teachers_disponibility':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/disponibility/get_teachers_disponibility.php');
            $instructorId = optional_param('instructorId', null, PARAM_TEXT);
            $initTime = optional_param('initTime', null, PARAM_TEXT);
            $endTime = optional_param('endTime', null, PARAM_TEXT);
            
            $result = \local_grupomakro_core\external\disponibility\get_teachers_disponibility::execute($instructorId, $initTime, $endTime);
            if (isset($result['status']) && $result['status'] == 1) {
                // Return decoded json array
                $data = json_decode($result['teacherAvailabilityRecords'], true);
                $response = ['status' => 'success', 'data' => $data];
            } else {
                $response = ['status' => 'error', 'message' => isset($result['message']) ? $result['message'] : 'Error loading disponibility'];
            }
            break;

        case 'local_grupomakro_save_scheduler_config':
            $periodid = required_param('periodid', PARAM_INT);
            $holidays = isset($_POST['holidays']) && is_array($_POST['holidays']) ? $_POST['holidays'] : [];
            $loads = isset($_POST['loads']) && is_array($_POST['loads']) ? $_POST['loads'] : [];
            $configsettings = optional_param('configsettings', '', PARAM_RAW);
            
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $result = \local_grupomakro_core\external\admin\scheduler::save_scheduler_config($periodid, $holidays, $loads, $configsettings);
            
            if ($result) {
                $response = ['status' => 'success'];
            } else {
                $response = ['status' => 'error', 'message' => 'Error al guardar la configuración'];
            }
            break;

        case 'local_grupomakro_get_demand_data':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_demand_data($periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_save_projections':
            $periodid = required_param('periodid', PARAM_INT);
            $projections_json = required_param('projections', PARAM_RAW);
            $projections = json_decode($projections_json, true);
            if (!is_array($projections)) $projections = [];

            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $result = \local_grupomakro_core\external\admin\scheduler::save_projections($periodid, $projections);
            if ($result) {
                $response = ['status' => 'success'];
            } else {
                $response = ['status' => 'error', 'message' => 'Error al guardar proyecciones'];
            }
            break;

        case 'local_grupomakro_get_generated_schedules':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $data = \local_grupomakro_core\external\admin\scheduler::get_generated_schedules($periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_save_generation_result':
            $periodid = required_param('periodid', PARAM_INT);
            $schedules_json = required_param('schedules', PARAM_RAW);
            $schedules = json_decode($schedules_json, true);
            if (!is_array($schedules)) $schedules = [];

            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $result = \local_grupomakro_core\external\admin\scheduler::save_generation_result($periodid, $schedules);
            if ($result === true) {
                $response = ['status' => 'success'];
            } else {
                $err = is_string($result) ? $result : 'Error al guardar estructura matricial';
                $response = ['status' => 'error', 'message' => $err];
            }
            break;

        case 'local_grupomakro_get_classrooms':
            $classrooms = $DB->get_records('gmk_classrooms', [], 'name ASC');
            $response = ['status' => 'success', 'data' => array_values($classrooms)];
            break;

        case 'local_grupomakro_save_classroom':
            $id = optional_param('id', 0, PARAM_INT);
            $record = new stdClass();
            $record->name = required_param('name', PARAM_TEXT);
            $record->capacity = required_param('capacity', PARAM_INT);
            $record->type = optional_param('type', 'general', PARAM_TEXT);
            $record->active = optional_param('active', 1, PARAM_INT);
            $record->usermodified = $USER->id;
            $record->timemodified = time();

            if ($id > 0) {
                $record->id = $id;
                $DB->update_record('gmk_classrooms', $record);
            } else {
                $record->timecreated = time();
                $id = $DB->insert_record('gmk_classrooms', $record);
            }
            $response = ['status' => 'success', 'data' => ['id' => $id]];
            break;

        case 'local_grupomakro_delete_classroom':
            $id = required_param('id', PARAM_INT);
            $DB->delete_records('gmk_classrooms', ['id' => $id]);
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_get_scheduler_context':
            $periodid = required_param('periodid', PARAM_INT);
            $ctxData = \local_grupomakro_core\external\admin\scheduler::get_scheduler_context($periodid);
            // Format holidays with formatted_date for calendar
            if (!empty($ctxData['holidays'])) {
                foreach ($ctxData['holidays'] as &$h) {
                    if (isset($h->date) && !isset($h->formatted_date)) {
                        $h->formatted_date = date('Y-m-d', $h->date);
                    }
                }
                unset($h);
            }
            $response = ['status' => 'success', 'data' => $ctxData];
            break;

        case 'local_grupomakro_get_holidays':
            $periodid = required_param('academicperiodid', PARAM_INT);
            $holidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $periodid], 'date ASC');
            $data = [];
            foreach ($holidays as $h) {
                $h->formatted_date = date('Y-m-d', $h->date);
                $data[] = $h;
            }
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_save_holiday':
            $id = optional_param('id', 0, PARAM_INT);
            $record = new stdClass();
            $record->academicperiodid = required_param('academicperiodid', PARAM_INT);
            $record->date = required_param('date', PARAM_INT); // Expecting timestamp
            $record->name = optional_param('name', '', PARAM_TEXT);
            $record->type = optional_param('type', 'feriado', PARAM_TEXT);
            $record->usermodified = $USER->id;
            $record->timemodified = time();

            if ($id > 0) {
                $record->id = $id;
                $DB->update_record('gmk_holidays', $record);
            } else {
                $record->timecreated = time();
                $id = $DB->insert_record('gmk_holidays', $record);
            }
            $response = ['status' => 'success', 'data' => ['id' => $id]];
            break;

        case 'local_grupomakro_delete_holiday':
            $id = required_param('id', PARAM_INT);
            $DB->delete_records('gmk_holidays', ['id' => $id]);
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_save_subject_loads':
            $periodid = required_param('academicperiodid', PARAM_INT);
            $loadsJson = required_param('loads', PARAM_RAW);
            $loads = json_decode($loadsJson, true);
            
            if (!is_array($loads)) {
                throw new Exception('Formato de cargas inválido.');
            }
            
            // Wipe existing loads for this period and insert new ones
            $DB->delete_records('gmk_subject_loads', ['academicperiodid' => $periodid]);
            $count = 0;
            foreach ($loads as $l) {
                $rec = new stdClass();
                $rec->academicperiodid = $periodid;
                $rec->subjectname = trim($l['subjectName'] ?? '');
                $rec->total_hours = floatval($l['totalHours'] ?? 0);
                $rec->intensity = floatval($l['intensity'] ?? 0);
                $rec->usermodified = $USER->id;
                $rec->timecreated = time();
                $rec->timemodified = time();
                if (!empty($rec->subjectname)) {
                    $DB->insert_record('gmk_subject_loads', $rec);
                    $count++;
                }
            }
            $response = ['status' => 'success', 'data' => ['saved' => $count]];
            break;

        case 'local_grupomakro_save_draft':
            raise_memory_limit(MEMORY_HUGE);
            core_php_time_limit::raise(300);

            $periodid = required_param('periodid', PARAM_INT);
            $schedulesJson = isset($_POST['schedules']) ? $_POST['schedules'] : null;
            $source = 'POST';

            if ($schedulesJson === null && !empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                $schedulesJson = $decoded['schedules'] ?? null;
                $source = 'RAW_INPUT_VAR';
            }
            
            $len = $schedulesJson ? strlen($schedulesJson) : 0;
            error_log("DEBUG: Save draft for period $periodid. Source: $source. Length: $len chars.");

            if (!$schedulesJson || $len < 2) {
                $response = [
                    'status' => 'error', 
                    'message' => 'No schedules data received', 
                    'source' => $source,
                    'input_len' => strlen($rawInput ?? '')
                ];
                break;
            }

            if (!$DB->record_exists('gmk_academic_periods', ['id' => $periodid])) {
                $response = ['status' => 'error', 'message' => 'Period not found'];
                break;
            }

            try {
                $DB->set_field('gmk_academic_periods', 'draft_schedules', $schedulesJson, ['id' => $periodid]);
                $storedLen = $DB->get_field_sql("SELECT LENGTH(draft_schedules) FROM {gmk_academic_periods} WHERE id = ?", [$periodid]);
                
                $response = [
                    'status' => 'success',
                    'data' => [
                        'received_length' => $len,
                        'stored_length' => (int)$storedLen,
                        'source' => $source
                    ]
                ];
                error_log("DEBUG: Update result for $periodid. Stored length: $storedLen");
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_load_draft':
            $periodid = required_param('periodid', PARAM_INT);
            $draft = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
            
            $data = [];
            if ($draft) {
                $decoded = json_decode($draft, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                } else {
                    error_log("DEBUG: JSON DECODE FAILED for period $periodid. Error: " . json_last_error_msg());
                }
            }

            $response = [
                'status' => 'success',
                'data' => $data
            ];
            break;

        case 'local_grupomakro_upload_holidays_excel':
            $periodid = required_param('academicperiodid', PARAM_INT);
            
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibió un archivo válido.');
            }
            
            $tmpPath = $_FILES['file']['tmp_name'];
            $spreadsheet = IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            
            // Detect header row and column mapping
            $headerRow = array_shift($rows);
            $colMap = [];
            foreach ($headerRow as $col => $val) {
                $normalized = mb_strtolower(trim($val ?? ''));
                if (strpos($normalized, 'fecha') !== false) $colMap['date'] = $col;
                if (strpos($normalized, 'festividad') !== false || strpos($normalized, 'nombre') !== false) $colMap['name'] = $col;
                if (strpos($normalized, 'tipo') !== false) $colMap['type'] = $col;
            }
            
            if (empty($colMap['date'])) {
                throw new Exception('No se encontró la columna "Fecha" en el Excel.');
            }
            
            // Get existing dates to skip duplicates
            $existingHolidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $periodid], '', 'id, date');
            $existingDates = [];
            foreach ($existingHolidays as $eh) {
                $existingDates[] = date('Y-m-d', $eh->date);
            }
            
            $imported = 0;
            $skipped = 0;
            
            foreach ($rows as $row) {
                $rawDate = trim($row[$colMap['date']] ?? '');
                if (empty($rawDate)) continue;
                
                // Try multiple date formats
                $ts = 0;
                if (is_numeric($rawDate)) {
                    // Excel serial date
                    $ts = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($rawDate);
                } else {
                    // Try common date string formats
                    $parsed = strtotime($rawDate);
                    if ($parsed !== false) {
                        $ts = $parsed;
                    } else {
                        // Try MM/DD/YYYY format
                        $parts = preg_split('/[\/\-]/', $rawDate);
                        if (count($parts) === 3) {
                            $ts = mktime(12, 0, 0, intval($parts[0]), intval($parts[1]), intval($parts[2]));
                        }
                    }
                }
                
                if ($ts <= 0) continue;
                
                // Normalize to noon to avoid timezone issues
                $dateStr = date('Y-m-d', $ts);
                $ts = strtotime($dateStr . ' 12:00:00');
                
                // Skip duplicates
                if (in_array($dateStr, $existingDates)) {
                    $skipped++;
                    continue;
                }
                
                $record = new stdClass();
                $record->academicperiodid = $periodid;
                $record->date = $ts;
                $record->name = trim($row[$colMap['name'] ?? ''] ?? 'Feriado');
                
                // Map type from Excel to short code
                $rawType = mb_strtolower(trim($row[$colMap['type'] ?? ''] ?? ''));
                if (strpos($rawType, 'nacional') !== false || strpos($rawType, 'feriado') !== false) {
                    $record->type = 'feriado';
                } else if (strpos($rawType, 'institucional') !== false) {
                    $record->type = 'institucional';
                } else if (strpos($rawType, 'duelo') !== false) {
                    $record->type = 'feriado';
                } else {
                    $record->type = 'otro';
                }
                
                $record->usermodified = $USER->id;
                $record->timecreated = time();
                $record->timemodified = time();
                
                $DB->insert_record('gmk_holidays', $record);
                $existingDates[] = $dateStr;
                $imported++;
            }
            
            $response = ['status' => 'success', 'data' => ['imported' => $imported, 'skipped' => $skipped]];
            break;

        default:
            $response['message'] = 'Action not found: ' . $action;
            break;
    }
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
}

$output = ob_get_clean();
// If there was some unexpected output, we might want to log it or ignore it.
// For now, prioritize returning clean JSON.

header('Content-Type: application/json');
echo json_encode($response);
die();
