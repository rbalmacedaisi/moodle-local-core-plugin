<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/dataformatlib.php');

require_login();
require_capability('local/grupomakro_core:export_students', context_system::instance());
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
        // EXISTS sobre todas las matrículas del estudiante: un estudiante con dos
        // carreras que coincide en una debe listar TODAS sus carreras, no solo la
        // fila del plan cuyo cuatrimestre coincidió.
        list($insql, $inparams) = $DB->get_in_or_equal($periodids, SQL_PARAMS_NAMED, 'period');
        $sqlConditions[] = "EXISTS (
            SELECT 1 FROM {local_learning_users} lpu2
             WHERE lpu2.userid = u.id
               AND lpu2.userrolename = 'student'
               AND lpu2.currentperiodid $insql
        )";
        $sqlParams = array_merge($sqlParams, $inparams);
    }
}

if (!empty($financial_status_filter)) {
    // El JS envía tanto valores crudos de fs.status (al_dia, mora,
    // sin_contrato_o_usuario) como nombres lógicos de las cards
    // financieras (up_to_date, in_arrears, pending, active). Usamos el
    // mismo helper que el endpoint de la tabla para traducir los nombres
    // lógicos a SQL, así el export refleja 1:1 lo que el usuario ve.
    list($fsClause, $fsParams) = local_grupomakro_translate_financial_filter($financial_status_filter);
    if ($fsClause !== null) {
        $sqlConditions[] = $fsClause;
        $sqlParams = array_merge($sqlParams, $fsParams);
    }

    // Cualquier filtro financiero no vacio (incluyendo 'active' / 'up_to_date'
    // / card values) dispara el "universo activo" en la tabla — replica esa
    // misma logica aca. Sin esto, el export retornaba TODOS los lpu con
    // student (1478) en lugar del universo activo (~398) cuando el usuario
    // seleccionaba "Estudiantes Activos", incluyendo inactivos/retirados.
    if (local_grupomakro_needs_active_universe($financial_status_filter)) {
        list($auClause, $auParams) = local_grupomakro_active_universe_clause();
        $sqlConditions[] = $auClause;
        $sqlParams = array_merge($sqlParams, $auParams);
    }
}

$whereClause = "WHERE " . implode(' AND ', $sqlConditions);

// Query
$query = "
    SELECT lpu.id as recordid, lpu.currentperiodid as periodid, lpu.currentsubperiodid as subperiodid,
    lp.name as career, u.id as userid, u.email as email, u.idnumber,
    u.firstname as firstname, u.lastname as lastname, lpu.status as academic_status, fs.status as financial_status
    FROM {user} u
    JOIN {local_learning_users} lpu ON (lpu.userid = u.id)
    JOIN {local_learning_plans} lp ON (lpu.learningplanid = lp.id)
    LEFT JOIN {gmk_financial_status} fs ON (fs.userid = u.id)
    $whereClause
    ORDER BY u.firstname";

    $recordset = $DB->get_recordset_sql($query, $sqlParams);

$fieldStatus = $DB->get_record('user_info_field', array('shortname' => 'studentstatus'));
$fieldDoc = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));
$fieldJourney = $DB->get_record('user_info_field', array('shortname' => 'gmkjourney'));

// Columns (Jornada añadida entre Identificación y Carrera, mismo orden que la tabla).
$columns = ['id', 'fullname', 'email', 'identification', 'journey', 'career', 'period', 'block', 'status', 'academic_status', 'financial_status'];
$headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Jornada', 'Carrera', 'Cuatrimestre', 'Bloque', 'Estado', 'Estado Académico', 'Estado Financiero'];

// Prepare Iterator
$data = [];
foreach ($recordset as $user) {
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

    // Journey (mismo custom field que la tabla: shortname=gmkjourney).
    $journey = '';
    if ($fieldJourney) {
        $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldJourney->id, 'userid' => $user->userid]);
        if ($val !== false && !empty($val)) $journey = $val;
    }

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
    $row->journey = $journey;
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
    $row->academic_status = $user->academic_status ?: 'activo';
    $row->financial_status = $user->financial_status ?: 'Pendiente';

    $data[] = $row;
}
$recordset->close();

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
