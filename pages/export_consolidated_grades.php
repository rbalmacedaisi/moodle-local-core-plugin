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
require_once($CFG->dirroot . '/local/grupomakro_core/classes/course_grade_resolver.php');
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
    $sqlConditions = ["u.deleted = 0", "lpu.userrolename = 'student'"];

    if (!empty($planid)) {
        $planids = array_filter(explode(',', $planid), 'is_numeric');
        if (!empty($planids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'plan');
            $sqlConditions[] = "cp.learningplanid $insql";
            $sqlParams = array_merge($sqlParams, $inparams);
        }
    }
    if (!empty($periodid)) {
        $periodidArray = array_filter(explode(',', $periodid), 'is_numeric');
        if (!empty($periodidArray)) {
            // Filter STUDENTS by their current cuatrimestre, same semantics as the
            // on-screen table. Uses EXISTS over ALL the student's enrollments: a
            // dual-career student matching the cuatrimestre in ONE career must
            // export the complete history of every selected career, not just the
            // rows of the plan whose enrollment matched (los periodos son por plan,
            // así que igualar lpu.currentperiodid fila a fila dejaba fuera la otra
            // carrera completa).
            list($insql, $inparams) = $DB->get_in_or_equal($periodidArray, SQL_PARAMS_NAMED, 'period');
            $sqlConditions[] = "EXISTS (
                SELECT 1 FROM {local_learning_users} lpu2
                 WHERE lpu2.userid = u.id
                   AND lpu2.userrolename = 'student'
                   AND lpu2.currentperiodid $insql
            )";
            $sqlParams = array_merge($sqlParams, $inparams);
        }
    }

    if (!empty($financial_status)) {
        $sqlConditions[] = "fs.status = :financial_status";
        $sqlParams['financial_status'] = $financial_status;
    }

    $whereClause = "WHERE " . implode(' AND ', $sqlConditions);

    // Grade and course_status are resolved per-row using course_grade_resolver,
    // which is the same cascade used by the academic panel grades modal
    // (get_student_learning_plan_pensum). This ensures the Excel export shows the
    // same nota/estado that the teacher sees on screen — including module grades,
    // class category aggregation and virtual approval.
    $query = "
        SELECT u.id as userid, u.firstname, u.lastname, u.email, u.idnumber,
               lp.name as career, per.name as periodname,
               COALESCE(cp.coursename, '(Sin curso activo)') as coursename,
               cp.courseid, cp.learningplanid, cp.periodid,
               cp.classid, cp.groupid, cp.progress,
               cp.status as coursestatus, cp.grade as cpgrade, cp.practicalhours,
               lpu.currentperiodid,
               fs.status as financial_status
        FROM {gmk_course_progre} cp
        JOIN {user} u ON u.id = cp.userid
        JOIN {local_learning_users} lpu ON (lpu.userid = u.id AND lpu.learningplanid = cp.learningplanid AND lpu.userrolename = 'student')
        JOIN {local_learning_plans} lp ON lp.id = cp.learningplanid
        LEFT JOIN {local_learning_periods} per ON per.id = cp.periodid
        LEFT JOIN {gmk_financial_status} fs ON (fs.userid = u.id)
        $whereClause
        ORDER BY lp.name, per.id, u.firstname";

    $recordset = $DB->get_recordset_sql($query, $sqlParams);

    $columns = ['id', 'fullname', 'email', 'identification', 'career', 'period', 'course', 'grade', 'student_status', 'financial_status', 'course_status'];
    $headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Carrera', 'Cuatrimestre', 'Curso', 'Nota', 'Estado Estudiante', 'Estado Financiero', 'Estado Curso'];

    $data = [];
    $studentStatusCache = [];

    // First pass: collect all cp rows and apply status/search filters.
    // We buffer them so the resolver can be called in bulk (one batched pre-load
    // per data type instead of N+1 queries per row).
    $pending = [];
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

        // Search filter
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

        $pending[] = [
            'cp' => $cp,
            'docNumber' => $finalID,
            'student_status' => $currentStudentStatus,
            'financial_status' => $cp->financial_status ?: 'Pendiente',
        ];
    }
    $recordset->close();

    // Bulk resolver call: one pre-load per data type for all rows.
    $resolverInput = [];
    foreach ($pending as $i => $p) {
        $cp = $p['cp'];
        $resolverInput[] = [
            'key'              => (string)$i,
            'userid'           => (int)$cp->userid,
            'courseid'         => (int)$cp->courseid,
            'learningplanid'   => (int)$cp->learningplanid,
            'baseStatus'       => (int)($cp->coursestatus ?? 0),
            'progressclassid'  => !empty($cp->classid) ? (int)$cp->classid : null,
            'progressgroupid'  => !empty($cp->groupid) ? (int)$cp->groupid : null,
            'currentperiodid'  => (int)($cp->currentperiodid ?? 0),
            'coursename'       => (string)($cp->coursename ?? ''),
            'baseProgress'     => $cp->progress !== null ? (float)$cp->progress : null,
        ];
    }
    $resolutions = \local_grupomakro_core\course_grade_resolver::bulk_resolve_for_records($resolverInput);

    // Second pass: build the final row objects using the bulk resolutions.
    foreach ($pending as $i => $p) {
        $cp = $p['cp'];
        $resolution = $resolutions[(string)$i] ?? ['grade' => null, 'status' => (int)($cp->coursestatus ?? 0)];

        $row = new stdClass();
        $row->id = $cp->userid;
        $row->fullname = $cp->firstname . ' ' . $cp->lastname;
        $row->email = $cp->email;
        $row->identification = $p['docNumber'];
        $row->career = $cp->career;
        $row->period = $cp->periodname;
        $row->course = $cp->coursename;
        $row->student_status = $p['student_status'];
        $row->financial_status = $p['financial_status'];

        $resolvedGrade = $resolution['grade'];
        $resolvedStatus = (int)$resolution['status'];
        $row->grade = ($resolvedGrade !== null) ? number_format((float)$resolvedGrade, 2) : '--';
        $row->course_status = \local_grupomakro_core\course_grade_resolver::STATUS_LABEL[$resolvedStatus] ?? 'No disponible';

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
            // Same EXISTS semantics as the grades mode: a dual-career student
            // matching in one career must list ALL their careers/periods.
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
