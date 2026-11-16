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
    ORDER BY u.firstname, u.lastname, u.id, lp.name, lpu.id";

    $recordset = $DB->get_recordset_sql($query, $sqlParams);

$fieldStatus = $DB->get_record('user_info_field', array('shortname' => 'studentstatus'));
$fieldDoc = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));
$fieldJourney = $DB->get_record('user_info_field', array('shortname' => 'gmkjourney'));

// Columns (Jornada añadida entre Identificación y Carrera, mismo orden que la tabla).
$columns = ['id', 'fullname', 'email', 'identification', 'journey', 'career', 'period', 'block', 'status', 'academic_status', 'financial_status'];
$headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Jornada', 'Carrera', 'Cuatrimestre', 'Bloque', 'Estado', 'Estado Académico', 'Estado Financiero'];

// Una fila por PERSONA (igual que la tabla y las tarjetas, que cuentan
// personas distintas). Un estudiante con varias carreras aparece una sola
// vez, con sus carreras / cuatrimestres / bloques unidos en la misma celda.
$periodcache = [];
$subperiodcache = [];
$people = []; // userid => ['u' => primera fila, 'careers' => [], 'periods' => [], 'blocks' => [], 'acad' => []]
foreach ($recordset as $user) {
    $uid = (int)$user->userid;
    if (!isset($people[$uid])) {
        $people[$uid] = ['u' => $user, 'careers' => [], 'periods' => [], 'blocks' => [], 'acad' => []];
    }
    $p =& $people[$uid];

    $career = trim((string)$user->career);
    if ($career !== '' && !in_array($career, $p['careers'], true)) {
        $p['careers'][] = $career;
    }

    $periodname = '';
    if (!empty($user->periodid)) {
        if (!array_key_exists($user->periodid, $periodcache)) {
            $periodcache[$user->periodid] = (string)$DB->get_field('local_learning_periods', 'name', ['id' => $user->periodid]);
        }
        $periodname = $periodcache[$user->periodid];
    }
    if ($periodname !== '' && !in_array($periodname, $p['periods'], true)) {
        $p['periods'][] = $periodname;
    }

    $subperiodname = '';
    if (!empty($user->subperiodid)) {
        if (!array_key_exists($user->subperiodid, $subperiodcache)) {
            $subperiodcache[$user->subperiodid] = (string)$DB->get_field('local_learning_subperiods', 'name', ['id' => $user->subperiodid]);
        }
        $subperiodname = $subperiodcache[$user->subperiodid];
    }
    if ($subperiodname !== '' && !in_array($subperiodname, $p['blocks'], true)) {
        $p['blocks'][] = $subperiodname;
    }

    $acad = $user->academic_status ?: 'activo';
    if (!in_array($acad, $p['acad'], true)) {
        $p['acad'][] = $acad;
    }
    unset($p);
}
$recordset->close();

$data = [];
foreach ($people as $uid => $p) {
    $user = $p['u'];

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

    // Search filter matching JS (la carrera coincide si cualquiera de las suyas coincide).
    if (!empty($search)) {
        $fullName = $user->firstname . ' ' . $user->lastname;
        $match = (
            stripos($fullName, $search) !== false ||
            stripos((string)$user->email, $search) !== false ||
            stripos((string)$status, $search) !== false ||
            stripos((string)$finalID, $search) !== false ||
            stripos(implode(' ', $p['careers']), $search) !== false
        );
        if (!$match) continue;
    }

    $row = new stdClass();
    $row->id = $user->userid;
    $row->fullname = $user->firstname . ' ' . $user->lastname;
    $row->email = $user->email;
    $row->identification = $finalID;
    $row->journey = $journey;
    $row->career = implode(' | ', $p['careers']);
    $row->period = implode(' | ', $p['periods']);
    $row->block = implode(' | ', $p['blocks']);
    $row->status = $status;
    $row->academic_status = implode(' | ', $p['acad']);
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
