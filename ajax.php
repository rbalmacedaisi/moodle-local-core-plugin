<?php

define('AJAX_SCRIPT', true);

// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../config.php';
}

require_once($config_path);
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

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

        case 'local_grupomakro_get_periods':
            $planid = required_param('planid', PARAM_INT);
            $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $planid], 'id ASC', 'id, name');
            $response = ['status' => 'success', 'periods' => array_values($periods)];
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

        case 'local_grupomakro_get_class_details':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            $sql = "SELECT asess.id, e.timestart as startdate, rel.bbbactivityid
                    FROM {attendance_sessions} asess
                    JOIN {event} e ON e.id = asess.caleventid
                    JOIN {gmk_bbb_attendance_relation} rel ON rel.attendancesessionid = asess.id
                    WHERE rel.classid = :classid
                    ORDER BY e.timestart ASC";
            
            $sessions = $DB->get_records_sql($sql, ['classid' => $classid]);
            $formatted_sessions = [];
            foreach ($sessions as $s) {
                $session_data = new stdClass();
                $session_data->id = $s->id;
                $session_data->startdate = $s->startdate;
                $session_data->type = ($class->type == 1 ? 'virtual' : 'physical');
                
                // BBB Logic
                if ($s->bbbactivityid) {
                    try {
                        $cm = get_coursemodule_from_instance('bigbluebuttonbn', $s->bbbactivityid);
                        if ($cm) {
                            $session_data->join_url = \mod_bigbluebuttonbn\external\get_join_url::execute($cm->id)['join_url'] ?? '#';
                            
                            // Check for recordings
                            $recordingId = $DB->get_field('bigbluebuttonbn_recordings', 'recordingid', ['bigbluebuttonbnid' => $s->bbbactivityid]);
                            if ($recordingId) {
                                $session_data->recording_url = "https://bbb.isi.edu.pa/playback/presentation/2.3/" . $recordingId;
                            }
                        }
                    } catch (Exception $e) {
                         // Ignore BBB errors to not break the whole list
                    }
                }
                
                $formatted_sessions[] = $session_data;
            }

            $response = [
                'status' => 'success',
                'data' => [
                    'class' => $class,
                    'sessions' => $formatted_sessions
                ]
            ];
            break;

        case 'local_grupomakro_create_express_activity':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/create_express_activity.php');
            $classid = required_param('classid', PARAM_INT);
            $type = required_param('type', PARAM_ALPHA);
            $name = required_param('name', PARAM_TEXT);
            $intro = optional_param('intro', '', PARAM_RAW);
            $duedate = optional_param('duedate', 0, PARAM_INT);
            $save_as_template = optional_param('save_as_template', false, PARAM_BOOL);
            
            $response = \local_grupomakro_core\external\teacher\create_express_activity::execute(
                $classid, $type, $name, $intro, $duedate, $save_as_template
            );
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
