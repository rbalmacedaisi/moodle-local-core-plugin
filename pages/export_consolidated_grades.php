<?php
/**
 * Consolidated Grade Export Script.
 * 
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
require_once($CFG->libdir . '/dataformatlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context); 

global $DB;

$planid   = optional_param('planid', '', PARAM_RAW);
$periodid = optional_param('periodid', '', PARAM_RAW);
$status   = optional_param('status', '', PARAM_TEXT); 
$withgrades = optional_param('withgrades', 1, PARAM_INT);

// Course Status Mapping
$statusLabels = [
    0 => 'No disponible',
    1 => 'Disponible',
    2 => 'Cursando',
    3 => 'Completado',
    4 => 'Aprobada',
    5 => 'Reprobada',
    6 => 'Pendiente Revalida',
    7 => 'Revalidando curso'
];

$fieldStatus = $DB->get_record('user_info_field', ['shortname' => 'studentstatus']);
$fieldDoc = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);

$sqlParams = [];

if ($withgrades) {
    // --- MODE 1: Course-based (Granular with grades) ---
    $sqlConditions = ["u.deleted = 0"];
    
    if (!empty($planid)) {
        $planids = array_filter(explode(',', $planid), 'is_numeric');
        if (!empty($planids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'plan');
            $sqlConditions[] = "cp.learningplanid $insql";
            $sqlParams = array_merge($sqlParams, $inparams);
        }
    }
    if (!empty($periodid)) {
        $periodids = array_filter(explode(',', $periodid), 'is_numeric');
        if (!empty($periodids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($periodids, SQL_PARAMS_NAMED, 'period');
            $sqlConditions[] = "cp.periodid $insql";
            $sqlParams = array_merge($sqlParams, $inparams);
        }
    }

    $whereClause = "WHERE " . implode(' AND ', $sqlConditions);
    $query = "
        SELECT cp.id, u.id as userid, u.firstname, u.lastname, u.email, u.idnumber,
               lp.name as career, per.name as periodname, cp.coursename, cp.grade, cp.status as coursestatus
        FROM {gmk_course_progre} cp
        JOIN {user} u ON u.id = cp.userid
        JOIN {local_learning_plans} lp ON lp.id = cp.learningplanid
        JOIN {local_learning_periods} per ON per.id = cp.periodid
        $whereClause
        ORDER BY lp.name, per.id, u.firstname";

    $records = $DB->get_records_sql($query, $sqlParams);

    $columns = ['id', 'fullname', 'email', 'identification', 'career', 'period', 'course', 'grade', 'student_status', 'course_status'];
    $headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Carrera', 'Cuatrimestre', 'Curso', 'Nota', 'Estado Estudiante', 'Estado Curso'];
    
    $data = [];
    $studentStatusCache = [];

    foreach ($records as $cp) {
        if (!isset($studentStatusCache[$cp->userid])) {
            $sStatus = 'Activo';
            if ($fieldStatus) {
                $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldStatus->id, 'userid' => $cp->userid]);
                if ($val !== false && !empty($val)) $sStatus = $val;
            }
            $studentStatusCache[$cp->userid] = $sStatus;
        }
        $currentStudentStatus = $studentStatusCache[$cp->userid];
        if (!empty($status) && stripos($currentStudentStatus, $status) === false) continue;

        $docNumber = '';
        if ($fieldDoc) {
            $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldDoc->id, 'userid' => $cp->userid]);
            if ($val !== false && !empty($val)) $docNumber = $val;
        }
        $finalID = !empty($docNumber) ? $docNumber : $cp->idnumber;

        $row = new stdClass();
        $row->id = $cp->userid;
        $row->fullname = $cp->firstname . ' ' . $cp->lastname;
        $row->email = $cp->email;
        $row->identification = $finalID;
        $row->career = $cp->career;
        $row->period = $cp->periodname;
        $row->course = $cp->coursename;
        $row->grade = number_format($cp->grade, 2);
        $row->student_status = $currentStudentStatus;
        $row->course_status = $statusLabels[$cp->coursestatus] ?? 'Desconocido';
        $data[] = $row;
    }
} else {
    // --- MODE 2: Student-based (Consolidated WITHOUT grades, matching Panel) ---
    $sqlConditions = ["lpu.userrolename = :userrolename", "u.deleted = 0"];
    $sqlParams = ['userrolename' => 'student'];

    if (!empty($planid)) {
        $planids = array_filter(explode(',', $planid), 'is_numeric');
        if (!empty($planids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'plan');
            $sqlConditions[] = "lp.id $insql";
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

    $whereClause = "WHERE " . implode(' AND ', $sqlConditions);
    $query = "
        SELECT u.id as userid, u.email, u.idnumber, u.firstname, u.lastname,
               lp.name as career, per.name as periodname, sub.name as subperiodname,
               lpu.learningplanid as planid, lpu.currentperiodid as periodid
        FROM {user} u
        JOIN {local_learning_users} lpu ON lpu.userid = u.id
        JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
        LEFT JOIN {local_learning_periods} per ON per.id = lpu.currentperiodid
        LEFT JOIN {local_learning_subperiods} sub ON sub.id = lpu.currentsubperiodid
        $whereClause
        ORDER BY u.firstname, lp.name";

    $records = $DB->get_records_sql($query, $sqlParams);

    $userData = [];
    foreach ($records as $user) {
        $sStatus = 'Activo';
        if ($fieldStatus) {
            $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldStatus->id, 'userid' => $user->userid]);
            if ($val !== false && !empty($val)) $sStatus = $val;
        }
        if (!empty($status) && stripos($sStatus, $status) === false) continue;

        $docNumber = '';
        if ($fieldDoc) {
            $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldDoc->id, 'userid' => $user->userid]);
            if ($val !== false && !empty($val)) $docNumber = $val;
        }
        $finalID = !empty($docNumber) ? $docNumber : $user->idnumber;

        if (!isset($userData[$user->userid])) {
            $row = new stdClass();
            $row->id = $user->userid;
            $row->fullname = $user->firstname . ' ' . $user->lastname;
            $row->email = $user->email;
            $row->identification = $finalID;
            $row->careers = [];
            $row->periods = [];
            $row->subperiods = $user->subperiodname ?: '--';
            $row->student_status = $sStatus;
            $userData[$user->userid] = $row;
        }
        $userData[$user->userid]->careers[] = $user->career;
        $userData[$user->userid]->periods[] = ($user->periodname ?: '--');
    }

    $data = [];
    foreach ($userData as $row) {
        $row->careers = implode(', ', array_unique($row->careers));
        $row->periods = implode(', ', array_unique($row->periods));
        $data[] = $row;
    }

    $columns = ['id', 'fullname', 'email', 'identification', 'careers', 'periods', 'subperiods', 'student_status'];
    $headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Carreras', 'Cuatrimestres', 'Bloque', 'Estado Estudiante'];
}

$columnsWithHeaders = array_combine($columns, $headers);

\core\dataformat::download_data(
    'listado_estudiantes_' . date('Y-m-d'),
    'excel', 
    $columnsWithHeaders,
    $data
);
die();
