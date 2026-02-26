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
$financial_status = optional_param('financial_status', '', PARAM_TEXT); 
$withgrades = optional_param('withgrades', 1, PARAM_INT);
$search = optional_param('search', '', PARAM_RAW);

// Course Status Mapping
$statusLabels = [
    0 => 'No disponible',
    1 => 'Disponible',
    2 => 'Cursando',
    3 => 'Completado',
    4 => 'Aprobada',
    5 => 'Reprobada',
    6 => 'Pendiente Revalida',
    7 => 'Revalidando curso',
    99 => 'Migración Pendiente'
];

$fieldStatus = $DB->get_record('user_info_field', ['shortname' => 'studentstatus']);
$fieldDoc = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);

$sqlParams = [];

if ($withgrades) {
    // --- MODE 1: Course-based (Granular with grades) ---
    // We base the query on local_learning_users to ensure even those without progress/class appear
    $sqlConditions = ["u.deleted = 0", "lpu.userrolename = 'student'"];
    
    if (!empty($planid)) {
        $planids = array_filter(explode(',', $planid), 'is_numeric');
        if (!empty($planids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'plan');
            $sqlConditions[] = "lpu.learningplanid $insql";
            $sqlParams = array_merge($sqlParams, $inparams);
        }
    }
    if (!empty($periodid)) {
        $periodidArray = array_filter(explode(',', $periodid), 'is_numeric');
        if (!empty($periodidArray)) {
            list($insql, $inparams) = $DB->get_in_or_equal($periodidArray, SQL_PARAMS_NAMED, 'period');
            $sqlConditions[] = "lpu.currentperiodid $insql";
            $sqlParams = array_merge($sqlParams, $inparams);
        }
    }

    if (!empty($financial_status)) {
        $sqlConditions[] = "fs.status = :financial_status";
        $sqlParams['financial_status'] = $financial_status;
    }

    $whereClause = "WHERE " . implode(' AND ', $sqlConditions);
    $query = "
        SELECT u.id as userid, u.firstname, u.lastname, u.email, u.idnumber,
               lp.name as career, per.name as periodname,
               COALESCE(c.fullname, c.shortname, cp.coursename, '(Sin curso activo)') as coursename,
               cp.grade, cp.status as coursestatus, fs.status as financial_status,
               cp.courseid, gg.feedback
        FROM {user} u
        JOIN {local_learning_users} lpu ON lpu.userid = u.id
        JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
        LEFT JOIN {local_learning_periods} per ON per.id = lpu.currentperiodid
        LEFT JOIN {gmk_course_progre} cp ON (cp.userid = u.id AND cp.learningplanid = lp.id)
        LEFT JOIN {course} c ON c.id = cp.courseid
        LEFT JOIN {gmk_financial_status} fs ON (fs.userid = u.id)
        LEFT JOIN {grade_items} gi ON (gi.courseid = c.id AND gi.itemtype = 'course')
        LEFT JOIN {grade_grades} gg ON (gg.itemid = gi.id AND gg.userid = u.id)
        $whereClause
        ORDER BY lp.name, per.id, u.firstname";

    $recordset = $DB->get_recordset_sql($query, $sqlParams);

    $columns = ['id', 'fullname', 'email', 'identification', 'career', 'period', 'course', 'grade', 'student_status', 'financial_status', 'course_status', 'feedback'];
    $headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Carrera', 'Cuatrimestre', 'Curso', 'Nota', 'Estado Estudiante', 'Estado Financiero', 'Estado Curso', 'Feedback'];
    
    $data = [];
    $studentStatusCache = [];

    foreach ($recordset as $cp) {
        if (!isset($studentStatusCache[$cp->userid])) {
            $sStatus = 'Activo';
            if ($fieldStatus) {
                $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldStatus->id, 'userid' => $cp->userid]);
                if ($val !== false && !empty($val)) $sStatus = $val;
            }
            $studentStatusCache[$cp->userid] = $sStatus;
        }
        $currentStudentStatus = $studentStatusCache[$cp->userid];
        
        // Filter by Status (Exact matching to avoid Inactivo matching Activo)
        if (!empty($status)) {
            if (trim(strtolower($currentStudentStatus)) !== trim(strtolower($status))) {
                continue;
            }
        }

        $docNumber = '';
        if ($fieldDoc) {
            $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldDoc->id, 'userid' => $cp->userid]);
            if ($val !== false && !empty($val)) $docNumber = $val;
        }
        $finalID = !empty($docNumber) ? $docNumber : $cp->idnumber;

        // Search filter matching JS
        if (!empty($search)) {
            $fullName = $cp->firstname . ' ' . $cp->lastname;
            $match = (
                stripos($fullName, $search) !== false ||
                stripos($cp->email, $search) !== false ||
                stripos($currentStudentStatus, $search) !== false ||
                stripos((string)$finalID, $search) !== false ||
                stripos($cp->career, $search) !== false
            );
            if (!$match) continue;
        }

        $row = new stdClass();
        $row->id = $cp->userid;
        $row->fullname = $cp->firstname . ' ' . $cp->lastname;
        $row->email = $cp->email;
        $row->identification = $finalID;
        $row->career = $cp->career;
        $row->period = $cp->periodname;
        $row->course = $cp->coursename;
        $row->grade = ($cp->grade !== null) ? number_format($cp->grade, 2) : '--';
        $row->student_status = $currentStudentStatus;
        $row->financial_status = $cp->financial_status ?: 'Pendiente';
        $row->course_status = $statusLabels[$cp->coursestatus] ?? '--';
        $row->feedback = $cp->feedback ?: '';
        $data[] = $row;
    }
    $recordset->close();
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
    
    if (!empty($financial_status)) {
        $sqlConditions[] = "fs.status = :financial_status";
        $sqlParams['financial_status'] = $financial_status;
    }

    $whereClause = "WHERE " . implode(' AND ', $sqlConditions);
    $query = "
        SELECT lpu.id, u.id as userid, u.email, u.idnumber, u.firstname, u.lastname,
               lp.name as career, per.name as periodname, sub.name as subperiodname,
               lpu.learningplanid as planid, lpu.currentperiodid as periodid, fs.status as financial_status
        FROM {user} u
        JOIN {local_learning_users} lpu ON lpu.userid = u.id
        LEFT JOIN {gmk_financial_status} fs ON (fs.userid = u.id)
        JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
        LEFT JOIN {local_learning_periods} per ON per.id = lpu.currentperiodid
        LEFT JOIN {local_learning_subperiods} sub ON sub.id = lpu.currentsubperiodid
        $whereClause
        ORDER BY u.firstname, lp.name";

    $recordset = $DB->get_recordset_sql($query, $sqlParams);

    $userData = [];
    foreach ($recordset as $user) {
        $sStatus = 'Activo';
        if ($fieldStatus) {
            $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldStatus->id, 'userid' => $user->userid]);
            if ($val !== false && !empty($val)) $sStatus = $val;
        }
        
        // Filter by Status (Exact matching)
        if (!empty($status)) {
            if (trim(strtolower($sStatus)) !== trim(strtolower($status))) {
                continue;
            }
        }

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
                stripos($user->email, $search) !== false ||
                stripos($sStatus, $search) !== false ||
                stripos((string)$finalID, $search) !== false ||
                stripos($user->career, $search) !== false
            );
            if (!$match) continue;
        }

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
            $row->financial_status = $user->financial_status ?: 'Pendiente';
            $userData[$user->userid] = $row;
        }
        $userData[$user->userid]->careers[] = $user->career;
        $userData[$user->userid]->periods[] = ($user->periodname ?: '--');
    }
    $recordset->close();

    $data = [];
    foreach ($userData as $row) {
        $row->careers = implode(', ', array_unique($row->careers));
        $row->periods = implode(', ', array_unique($row->periods));
        $data[] = $row;
    }

    $columns = ['id', 'fullname', 'email', 'identification', 'careers', 'periods', 'subperiods', 'student_status', 'financial_status'];
    $headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Carreras', 'Cuatrimestres', 'Bloque', 'Estado Estudiante', 'Estado Financiero'];
}

$columnsWithHeaders = array_combine($columns, $headers);

// Clear any accidental output (like warnings or notices) that might have been buffered.
if (ob_get_length()) {
    ob_clean();
}

\core\dataformat::download_data(
    'listado_estudiantes_' . date('Y-m-d'),
    'excel', 
    $columnsWithHeaders,
    $data
);
die();
