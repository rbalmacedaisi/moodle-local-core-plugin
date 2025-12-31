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

        case 'local_grupomakro_get_student_info':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_info.php');
            
            // Map params from request
            $page = optional_param('page', 0, PARAM_INT);
            $resultsperpage = optional_param('resultsperpage', 15, PARAM_INT);
            $search = optional_param('search', '', PARAM_RAW);
            $planid = optional_param('planid', '', PARAM_RAW);
            $periodid = optional_param('periodid', '', PARAM_RAW);
            $status = optional_param('status', '', PARAM_TEXT);
            $classid = optional_param('classid', 0, PARAM_INT);

            // Execute
            $result = \local_grupomakro_core\external\student\get_student_info::execute(
                $page, $resultsperpage, $search, $planid, $periodid, $status, $classid
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
            
            // DEBUG LOGGING
            $logMsg = "AJAX Timeline Request (All Course Events):\n";
            $logMsg .= "ClassID: $classid\n";
            $logMsg .= "CourseID: {$class->corecourseid}\n";
            $logMsg .= "GroupID: {$class->groupid}\n";
            $logMsg .= "SQL Params: " . json_encode($params) . "\n";
            $logMsg .= "Total Course Events Found: " . count($events) . "\n";
            file_put_contents($CFG->dirroot . '/local/grupomakro_core/timeline_ajax_debug.log', $logMsg, FILE_APPEND);
            
            // DEBUG LOGGING
            $logMsg = "AJAX Timeline Request:\n";
            $logMsg .= "ClassID: $classid\n";
            $logMsg .= "CourseID: {$class->corecourseid}\n";
            $logMsg .= "GroupID: {$class->groupid}\n";
            $logMsg .= "SQL Params: " . json_encode($params) . "\n";
            $logMsg .= "Raw Events Found: " . count($events) . "\n";
            file_put_contents($CFG->dirroot . '/local/grupomakro_core/timeline_ajax_debug.log', $logMsg, FILE_APPEND);

            $formatted_sessions = [];
            $logMsg .= "Processing Events Loop:\n";
            foreach ($events as $e) {
                // Debug individual events
                $eGroupId = isset($e->groupid) ? $e->groupid : 'NULL';
                $cGroupId = $class->groupid;
                
                // Filter by Group
                if (!empty($e->groupid) && $e->groupid != $class->groupid) {
                    $logMsg .= "  [SKIP] ID {$e->id}: Group mistmatch ($eGroupId != $cGroupId)\n";
                    continue;
                }

                // Filter by Module
                if ($e->modulename !== 'attendance' && $e->modulename !== 'bigbluebuttonbn') {
                   // $logMsg .= "  [SKIP] ID {$e->id}: Module mistmatch ({$e->modulename})\n";
                   continue; 
                }

                $logMsg .= "  [KEEP] ID {$e->id}: Matched ($eGroupId == $cGroupId) Mod: {$e->modulename}\n";

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
                     $sql = "SELECT rel.bbbactivityid, sess.id as sessionid
                             FROM {attendance_sessions} sess
                             JOIN {gmk_bbb_attendance_relation} rel ON rel.attendancesessionid = sess.id
                             WHERE sess.caleventid = :caleventid";
                     $rel = $DB->get_record_sql($sql, ['caleventid' => $e->id]);
                     
                     if ($rel && $rel->bbbactivityid) {
                         $session_data->type = 'virtual';
                         try {
                              $cm = get_coursemodule_from_instance('bigbluebuttonbn', $rel->bbbactivityid);
                              if ($cm) {
                                  $session_data->join_url = \mod_bigbluebuttonbn\external\get_join_url::execute($cm->id)['join_url'] ?? '#';
                                  
                                  // Check for recordings
                                  $recordingId = $DB->get_field('bigbluebuttonbn_recordings', 'recordingid', ['bigbluebuttonbnid' => $rel->bbbactivityid]);
                                  if ($recordingId) {
                                      $session_data->recording_url = "https://bbb.isi.edu.pa/playback/presentation/2.3/" . $recordingId;
                                  }
                              }
                         } catch (Exception $ex) { /* Ignore */ }
                     }
                } elseif ($e->modulename === 'bigbluebuttonbn') {
                    $session_data->type = 'virtual';
                    // It's a direct BBB event
                    if ($e->instance) {
                        try {
                             $cm = get_coursemodule_from_instance('bigbluebuttonbn', $e->instance);
                             if ($cm) {
                                 $session_data->join_url = \mod_bigbluebuttonbn\external\get_join_url::execute($cm->id)['join_url'] ?? '#';
                             }
                        } catch (Exception $ex) { /* Ignore */ }
                    }
                }

                $formatted_sessions[] = $session_data;
            }
            $logMsg .= "Final Formatted Sessions: " . count($formatted_sessions) . "\n";
            file_put_contents($CFG->dirroot . '/local/grupomakro_core/timeline_ajax_debug.log', $logMsg, FILE_APPEND);
            
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
