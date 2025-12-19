<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();

// Check permissions if necessary (e.g., is teacher/manager)
// $context = context_system::instance();
// require_capability('moodle/site:config', $context);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=estudiantes_grupomakro_' . date('Y-m-d') . '.csv');

// Open output stream
$output = fopen('php://output', 'w');

// BOM for Excel UTF-8 compatibility
fputs($output, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));

// Column Headers
fputcsv($output, ['ID Moodle', 'Nombre Completo', 'Email', 'Carrera', 'Cuatrimestre', 'Bloque', 'Estado']);

global $DB;

// Query - same logic as get_student_info.php
$query = 
    'SELECT lpu.id, lpu.currentperiodid as periodid, lpu.currentsubperiodid as subperiodid, lp.id as planid, 
    lp.name as career, u.id as userid, u.email as email,
    u.firstname as firstname, u.lastname as lastname
    FROM {local_learning_plans} lp
    JOIN {local_learning_users} lpu ON (lpu.learningplanid = lp.id)
    JOIN {user} u ON (u.id = lpu.userid)
    WHERE lpu.userrolename = :userrolename
    ORDER BY u.firstname';

try {
    $infoUsers = $DB->get_records_sql($query, array('userrolename' => 'student'));
} catch (Exception $e) {
    // Fallback query if 'currentsubperiodid' column does not exist
    $query = 
    'SELECT lpu.id, lpu.currentperiodid as periodid, lp.id as planid, 
    lp.name as career, u.id as userid, u.email as email,
    u.firstname as firstname, u.lastname as lastname
    FROM {local_learning_plans} lp
    JOIN {local_learning_users} lpu ON (lpu.learningplanid = lp.id)
    JOIN {user} u ON (u.id = lpu.userid)
    WHERE lpu.userrolename = :userrolename
    ORDER BY u.firstname';
    $infoUsers = $DB->get_records_sql($query, array('userrolename' => 'student'));
}

// Prepare cache for periods/subperiods/fields to avoid N+1 queries ideally, 
// but for now keeping it simple or using Moodle's caching.
$field = $DB->get_record('user_info_field', array('shortname' => 'studentstatus'));

foreach ($infoUsers as $user) {
    // Status
    $status = 'Activo'; // Default
    if ($field) {
        $user_info_data = $DB->get_record_sql("
            SELECT d.data
            FROM {user_info_data} d
            JOIN {user} u ON u.id = d.userid
            WHERE d.fieldid = ? AND u.deleted = 0 AND d.userid = ?
        ", array($field->id, $user->userid));
        
        if ($user_info_data && !empty($user_info_data->data)) {
            $status = $user_info_data->data;
        }
    }

    // Period
    $periodname = '';
    if (!empty($user->periodid)) {
        $period = $DB->get_record('local_learning_periods', array('id' => $user->periodid));
        if ($period) $periodname = $period->name;
    }

    // Subperiod/Block
    $subperiodname = '';
    if (!empty($user->subperiodid)) { // Property check valid since we use get_records_sql
        $subperiod = $DB->get_record('local_learning_subperiods', array('id' => $user->subperiodid));
        if ($subperiod) $subperiodname = $subperiod->name;
    }
    
    // Output Row
    fputcsv($output, [
        $user->userid,
        $user->firstname . ' ' . $user->lastname,
        $user->email,
        $user->career,
        $periodname,
        $subperiodname,
        $status
    ]);
}

fclose($output);
exit();
