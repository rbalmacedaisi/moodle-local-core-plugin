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
            foreach (['action', 'sesskey'] as $field) {
                if (isset($jsonData[$field])) {
                    $_POST[$field] = $_REQUEST[$field] = $jsonData[$field];
                }
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
            $result = \local_grupomakro_core\external\teacher\get_pending_grading::execute($USER->id, $classid);
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
            $response = ['status' => 'success', 'data' => $result];
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

        case 'local_grupomakro_get_periods':
            $planid = optional_param('planid', 0, PARAM_INT);
            if ($planid > 0) {
                $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $planid], 'id ASC', 'id, name');
            } else {
                $periods = $DB->get_records('local_learning_periods', [], 'name ASC', 'id, name');
            }
            $response = ['status' => 'success', 'periods' => array_values($periods)];
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

            // 2. Fetch Grade Categories (Columns/Rubrics)
            // We want the direct children of the course category or manual items
            require_once($CFG->libdir . '/gradelib.php');
            
            $course_category = \grade_category::fetch_course_category($courseid);
            $grade_items = \grade_item::fetch_all(['courseid' => $courseid]);
            
            $columns = [];
            $item_ids = [];

            foreach ($grade_items as $gi) {
                // Filter out course total or unwanted items if necessary
                // For now, we show all 'manual' or 'mod' items that are not course total
                if ($gi->itemtype == 'course') continue; 
                
                $columns[] = [
                    'id' => $gi->id,
                    'title' => $gi->itemname ?: $gi->itemtype,
                    'max_grade' => $gi->grademax,
                    'weight' => $gi->aggregationcoef
                ];
                $item_ids[] = $gi->id;
            }

            // 3. Fetch Grades (Cells)
            $grades_data = [];
            foreach ($students as $student) {
                $student_row = [
                    'id' => $student->id,
                    'fullname' => $student->firstname . ' ' . $student->lastname,
                    'email' => $student->email,
                    'grades' => []
                ];

                foreach ($item_ids as $iid) {
                    $grade = \grade_grade::fetch(['itemid' => $iid, 'userid' => $student->id]);
                    $student_row['grades'][$iid] = $grade ? $grade->finalgrade : '-';
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
            $sql = "SELECT q.id, q.name, q.questiontext, q.qtype, s.slot
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
                    'slot' => $q->slot
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

                if (!$data) throw new Exception('Invalid JSON data');

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
                
                // Get default category for this quiz context
                $cat = question_get_default_category($context->id);
                if (!$cat) {
                     // Try course context if module context has no category
                     $course_context = context_course::instance($cm->course);
                     $cat = question_get_default_category($course_context->id);
                }
                if (!$cat) throw new Exception('No question category found for this quiz context.');

                // Prepare Question Object
                $question = new stdClass();
                $question->qtype = $data->type;
                $question->name = $data->name;
                $question->questiontext = ['text' => isset($data->questiontext) ? $data->questiontext : (isset($data->text) ? $data->text : ''), 'format' => FORMAT_HTML];
                $question->defaultmark = isset($data->defaultmark) ? $data->defaultmark : 1;
                $question->category = $cat->id;
                $question->stamp = make_unique_id_code();
                $question->version = make_unique_id_code();
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
                    $question->answer = []; // Text
                    $question->choicegroup = []; // Group
                    $question->choices = []; // Silence PHP Notice about undefined property

                    foreach ($data->answers as $ans) {
                        $question->answer[] = ['text' => $ans->text, 'format' => FORMAT_HTML];
                        $question->choicegroup[] = isset($ans->group) ? $ans->group : 1;
                    }
                    
                    // Combined Feedback Defaults
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;
                }
                elseif ($data->type === 'multianswer') {
                     // No specific extra fields, code is in questiontext
                }
                elseif ($data->type === 'randomsamatch') {
                    $question->choose = isset($data->choose) ? $data->choose : 2;
                    $question->subcats = isset($data->subcats) && $data->subcats ? 1 : 0;
                    $question->shuffleanswers = 1;
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
                            $question->fraction[] = 1.0; // Default to correct
                            $question->tolerance[] = isset($ans->tolerance) ? $ans->tolerance : 0.01;
                            $question->tolerancetype[] = 1; // Relative
                            $question->correctanswerlength[] = 2; 
                            $question->correctanswerformat[] = 1; // Decimals
                            $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                        }
                     }
                     // Unit support
                     $question->unit = [isset($data->unit) ? $data->unit : ''];
                     $question->multiplier = [1.0];
                     
                     // Datasets (Simple Mapping)
                     // Note: Full dataset handling requires creating separate definition records.
                     // This simple implementation relies on Moodle parsing the wildcards if definitions exist, 
                     // or creating defaults if allowed. For robust "Wizard" style, we'd need more logic.
                     $question->synchronize = 0;
                }
                elseif ($data->type === 'ddimageortext' || $data->type === 'ddmarker') {
                    $question->shuffleanswers = 1;
                    
                    // Create $form_data following Moodle's internal form structure
                    $form_data = clone $question;
                    $form_data->drags = [];
                    $form_data->drops = [];
                    $form_data->draglabel = []; // Specific for ddimageortext
                    $form_data->dragitem = [];  // Specific for ddimageortext

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
                    }

                    // Process Draggables
                    if (isset($data->draggables) && is_array($data->draggables)) {
                        foreach ($data->draggables as $idx => $drag) {
                            $no = $idx; // The diagnostic shows: foreach (array_keys($formdata->drags) as $dragno) ... $drag->no = $dragno + 1;
                                       // This means $dragno is the KEY. We'll use 0-based keys.
                            
                            $label = !empty($drag->text) ? (string)$drag->text : ' ';
                            
                            if ($data->type === 'ddimageortext') {
                                // ddimageortext expects draglabel and dragitem as top-level arrays
                                $form_data->draglabel[$no] = $label;
                                $form_data->dragitem[$no] = 0; // Draft ID for images, 0 for text
                                $form_data->drags[$no] = [
                                    'draggroup' => isset($drag->group) ? (int)$drag->group : 1,
                                    'infinite' => !empty($drag->infinite) ? 1 : 0,
                                    'dragitemtype' => 'text' // We are sending text
                                ];
                            } else {
                                // ddmarker expects label inside drags
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
                            $no = $idx; // Again, diagnostic uses index as $dropno
                            
                            if ($data->type === 'ddimageortext') {
                                $form_data->drops[$no] = [
                                    'choice' => (int)$d->choice,
                                    'xleft' => (int)$d->x,
                                    'ytop' => (int)$d->y,
                                    'droplabel' => 'drop' . ($no + 1) // CRITICAL: It was droplabel, not label!
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
                elseif ($data->type === 'description') {
                    // Just name and questiontext (intro) are needed, already set.
                }

                // SAVE using correct API
                $qtypeobj = question_bank::get_qtype($question->qtype);
                $newq = $qtypeobj->save_question($question, $form_data);
                
                // ADD TO QUIZ
                quiz_add_quiz_question($newq->id, $quiz);

                $response = ['status' => 'success', 'id' => $newq->id];

            } catch (Throwable $e) {
                // Return clear error message
                $response = ['status' => 'error', 'message' => 'Error al guardar: ' . $e->getMessage()];
                if (debugging()) {
                    $response['debug'] = $e->getTraceAsString();
                }
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
            $response = [
                'status' => 'success',
                'categories' => $formatted_cats
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
        
        case 'local_grupomakro_get_planning_data':
        $periodid = required_param('periodid', PARAM_INT);
        require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
        $data = \local_grupomakro_core\local\planning_manager::get_planning_data($periodid);
        $response = ['data' => $data, 'error' => false];
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
