<?php

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

require_login();
$context = context_system::instance();

$response = [
    'status' => 'error',
    'message' => 'Invalid action.'
];

try {
    switch ($action) {
        case 'local_grupomakro_sync_progress':
            // Call the external function logic directly or through external_api
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
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            // Get all students in learning plans
            $students = $DB->get_records('local_learning_users', ['userroleid' => 5], '', 'userid, learningplanid');
            $count = 0;
            foreach ($students as $s) {
                if (\local_grupomakro_progress_manager::sync_student_period_by_count($s->userid, $s->learningplanid)) {
                    $count++;
                }
            }
            $response = ['status' => 'success', 'message' => "Sincronización terminada. $count registros actualizados."];
            break;

        case 'local_grupomakro_get_periods':
            $planid = required_param('planid', PARAM_INT);
            $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $planid], 'id ASC', 'id, name');
            $response = ['status' => 'success', 'periods' => array_values($periods)];
            break;
        
        case 'get_sync_log':
            $logFile = make_tempdir('grupomakro') . '/sync_progress.log';
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

header('Content-Type: application/json');
echo json_encode($response);
die();
