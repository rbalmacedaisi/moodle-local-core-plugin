<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/dataformatlib.php');

require_login();
// Remove headers as download_as_dataformat handles them
// $context = context_system::instance();

global $DB;

$planid   = optional_param('planid', '', PARAM_RAW);
$periodid = optional_param('periodid', '', PARAM_RAW);
$status_filter = optional_param('status', '', PARAM_TEXT);
$financial_status_filter = optional_param('financial_status', '', PARAM_TEXT); 
$search = optional_param('search', '', PARAM_RAW);

$sqlParams = ['userrolename' => 'student'];
$sqlConditions = ["lpu.userrolename = :userrolename", "u.deleted = 0"];

if (!empty($planid)) {
    $planids = array_filter(explode(',', $planid), 'is_numeric');
    if (!empty($planids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'plan');
        $sqlConditions[] = "lpu.learningplanid $insql";
        $sqlParams = array_merge($sqlParams, $inparams);
    }
}
if (!empty($periodid)) {
    $periodids = array_filter(explode(',', $periodid), 'is_numeric');
    if (!empty($periodids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($periodids, SQL_PARAMS_NAMED, 'period');
        $sqlConditions[] = "lpu.currentperiodid $insql";
        $sqlParams = array_merge($sqlParams, $inparams);
    }
}

if (!empty($financial_status_filter)) {
    $sqlConditions[] = "fs.status = :financial_status_filter";
    $sqlParams['financial_status_filter'] = $financial_status_filter;
}

$whereClause = "WHERE " . implode(' AND ', $sqlConditions);

// Query
$query = "
    SELECT lpu.id as recordid, lpu.currentperiodid as periodid, lpu.currentsubperiodid as subperiodid, 
    lp.name as career, u.id as userid, u.email as email, u.idnumber,
    u.firstname as firstname, u.lastname as lastname, fs.status as financial_status
    FROM {user} u
    JOIN {local_learning_users} lpu ON (lpu.userid = u.id)
    JOIN {local_learning_plans} lp ON (lpu.learningplanid = lp.id)
    LEFT JOIN {gmk_financial_status} fs ON (fs.userid = u.id)
    $whereClause
    ORDER BY u.firstname";

$infoUsers = $DB->get_records_sql($query, $sqlParams);

$fieldStatus = $DB->get_record('user_info_field', array('shortname' => 'studentstatus'));
$fieldDoc = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));

// Columns
$columns = ['id', 'fullname', 'email', 'identification', 'career', 'period', 'block', 'status', 'financial_status'];
$headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Carrera', 'Cuatrimestre', 'Bloque', 'Estado', 'Estado Financiero'];

// Prepare Iterator
$data = [];
foreach ($infoUsers as $user) {
    // Status Logic
    $status = 'Activo'; 
    if ($fieldStatus) {
        $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldStatus->id, 'userid' => $user->userid]);
        if ($val !== false && !empty($val)) $status = $val;
    }

    // Filter by Status (Exact matching)
    if (!empty($status_filter)) {
        if (trim(strtolower($status)) !== trim(strtolower($status_filter))) {
            continue;
        }
    }

    // Identification Logic
    $docNumber = '';
    if ($fieldDoc) {
        $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldDoc->id, 'userid' => $user->userid]);
        if ($val !== false && !empty($val)) $docNumber = $val;
    }
    $finalID = !empty($docNumber) ? $docNumber : $user->idnumber;

    // Search filter matching JS
    if (!empty($search)) {
        $fullName = $user->firstname . ' ' . $user->lastname;
        $match = (
            stripos($fullName, $search) !== false ||
            stripos((string)$user->email, $search) !== false ||
            stripos((string)$status, $search) !== false ||
            stripos((string)$finalID, $search) !== false ||
            stripos((string)$user->career, $search) !== false
        );
        if (!$match) continue;
    }

    $row = new stdClass();
    $row->id = $user->userid;
    $row->fullname = $user->firstname . ' ' . $user->lastname;
    $row->email = $user->email;
    $row->identification = $finalID;
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

    $row->status = $status;
    $row->financial_status = $user->financial_status ?: 'Pendiente';

    $data[] = $row;
}
    
// Correct approach for Moodle dataformat:
// Columns should be [key => Label]
$columnsWithHeaders = array_combine($columns, $headers);

if (ob_get_length()) {
    ob_clean();
}

\core\dataformat::download_data(
    'estudiantes_grupomakro_' . date('Y-m-d'),
    'excel', 
    $columnsWithHeaders,
    $data
);
die();
