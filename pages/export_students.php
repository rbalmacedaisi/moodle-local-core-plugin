<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/dataformatlib.php');

require_login();
// Remove headers as download_as_dataformat handles them
// $context = context_system::instance();

global $DB;

// Query
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
    // Fallback
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

$field = $DB->get_record('user_info_field', array('shortname' => 'studentstatus'));

// Columns
$columns = ['id', 'fullname', 'email', 'career', 'period', 'block', 'status'];
$headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Carrera', 'Cuatrimestre', 'Bloque', 'Estado'];

// Prepare Iterator
$data = [];
foreach ($infoUsers as $user) {
    $row = new stdClass();
    $row->id = $user->userid;
    $row->fullname = $user->firstname . ' ' . $user->lastname;
    $row->email = $user->email;
    $row->career = $user->career;

    // Period
    $periodname = '';
    if (!empty($user->periodid)) {
        $period = $DB->get_record('local_learning_periods', array('id' => $user->periodid));
        if ($period) $periodname = $period->name;
    }
    $row->period = $periodname;

    // Block
    $subperiodname = '';
    if (!empty($user->subperiodid)) {
        $subperiod = $DB->get_record('local_learning_subperiods', array('id' => $user->subperiodid));
        if ($subperiod) $subperiodname = $subperiod->name;
    }
    $row->block = $subperiodname;

    // Status
    $status = 'Activo'; 
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
    $row->status = $status;
    
    $data[] = $row;
}

// Correct approach for Moodle dataformat:
// Columns should be [key => Label]
$columnsWithHeaders = array_combine($columns, $headers);

\core\dataformat::download_data(
    'estudiantes_grupomakro_' . date('Y-m-d'),
    'excel', 
    $columnsWithHeaders,
    $data
);
die();
