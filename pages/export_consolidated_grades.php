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

// A full "export todo" spans ~28k pensum rows that are buffered, run through the
// in-memory grade resolver (which pre-loads grade_items/grade_grades/classes) and
// then serialised into an .xlsx workbook in memory — the Excel writer alone holds
// every cell. The default 128M php memory_limit blows up mid-build (fatal at the
// second pass). Raise to MEMORY_HUGE (2G) and lift the time cap; this is an
// admin-only (site:config) export run rarely, so the headroom is acceptable.
raise_memory_limit(MEMORY_HUGE);
\core_php_time_limit::raise();

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
$fieldJourney = $DB->get_record('user_info_field', ['shortname' => 'gmkjourney']);

$sqlParams = [];

if ($withgrades) {
    // --- MODE 1: Course-based (Granular with grades) ---
    // IMPORTANT: this mode is PENSUM-DRIVEN (FROM local_learning_courses), exactly
    // like the academic panel grades modal (get_student_learning_plan_pensum). It
    // enumerates EVERY course of the student's plan and LEFT JOINs gmk_course_progre,
    // instead of driving FROM gmk_course_progre. Rationale: a subject can live in the
    // plan pensum but have NO progress row yet (courses not started, or graded outside
    // the normal class flow — modules, homologations, manual grades). Driving from
    // gmk_course_progre silently dropped those subjects from the export while the modal
    // still showed them, causing the export ≠ modal mismatch. The resolver fills in the
    // nota/estado for rows without a progre row, matching the modal 1:1.
    $sqlConditions = ["u.deleted = 0"];

    if (!empty($planid)) {
        $planids = array_filter(explode(',', $planid), 'is_numeric');
        if (!empty($planids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'plan');
            $sqlConditions[] = "lpc.learningplanid $insql";
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
        // El JS envía tanto valores crudos de fs.status (al_dia, mora,
        // sin_contrato_o_usuario) como nombres lógicos de las cards
        // financieras (up_to_date, in_arrears, pending, active). Usamos el
        // mismo helper que el endpoint de la tabla para traducir los nombres
        // lógicos a SQL, así el export refleja 1:1 lo que el usuario ve.
        list($fsClause, $fsParams) = local_grupomakro_translate_financial_filter($financial_status);
        if ($fsClause !== null) {
            $sqlConditions[] = $fsClause;
            $sqlParams = array_merge($sqlParams, $fsParams);
        }

        // Cualquier filtro financiero no vacio dispara el "universo activo"
        // en la tabla — replica esa logica aca. Sin esto, el export retornaba
        // TODOS los lpu con student en lugar del subconjunto activo cuando
        // el usuario seleccionaba "Estudiantes Activos" / "Al dia" / etc.
        if (local_grupomakro_needs_active_universe($financial_status)) {
            list($auClause, $auParams) = local_grupomakro_active_universe_clause();
            $sqlConditions[] = $auClause;
            $sqlParams = array_merge($sqlParams, $auParams);
        }
    }

    $whereClause = "WHERE " . implode(' AND ', $sqlConditions);

    // Grade and course_status are resolved per-row using course_grade_resolver,
    // which is the same cascade used by the academic panel grades modal
    // (get_student_learning_plan_pensum). This ensures the Excel export shows the
    // same nota/estado that the teacher sees on screen — including module grades,
    // class category aggregation and virtual approval.
    //
    // The `enr` subquery collapses each (student, plan) enrollment to a single row
    // (MAX currentperiodid, mirroring the modal) so the pensum JOIN never multiplies
    // rows when a user has duplicate local_learning_users records.
    //
    // Course name comes from the authoritative {course}.fullname (c.fullname), NOT the
    // denormalized gmk_course_progre.coursename snapshot. That column has a DB default
    // of 'unnamed' (NOT NULL) so insert paths that don't set it leave 'unnamed', and it
    // also goes stale when a course is later renamed. Using c.fullname matches the modal
    // (which reads course.fullname) and fixes both the 'unnamed' and stale-snapshot rows.
    $query = "
        SELECT u.id as userid, u.firstname, u.lastname, u.email, u.idnumber,
               lp.name as career, per.name as periodname,
               c.fullname as coursename,
               lpc.courseid, lpc.learningplanid,
               COALESCE(cp.periodid, lpc.periodid) as periodid,
               cp.classid, cp.groupid, cp.progress,
               cp.status as coursestatus, cp.grade as cpgrade, cp.practicalhours,
               enr.currentperiodid,
               fs.status as financial_status,
               lpc.position as lpcposition
        FROM {local_learning_courses} lpc
        JOIN {course} c ON c.id = lpc.courseid
        JOIN (
            SELECT lpu.userid, lpu.learningplanid, MAX(lpu.currentperiodid) AS currentperiodid
              FROM {local_learning_users} lpu
             WHERE lpu.userrolename = 'student'
          GROUP BY lpu.userid, lpu.learningplanid
        ) enr ON enr.learningplanid = lpc.learningplanid
        JOIN {user} u ON u.id = enr.userid
        JOIN {local_learning_plans} lp ON lp.id = lpc.learningplanid
        LEFT JOIN {gmk_course_progre} cp
               ON (cp.userid = enr.userid AND cp.courseid = lpc.courseid AND cp.learningplanid = lpc.learningplanid)
        LEFT JOIN {local_learning_periods} per ON per.id = COALESCE(cp.periodid, lpc.periodid)
        LEFT JOIN {gmk_financial_status} fs ON (fs.userid = u.id)
        $whereClause
        ORDER BY lp.name, per.id, u.firstname, lpc.position";

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
    unset($resolverInput); // free the intermediate copy before building the final rows.

    // Second pass: build the final row objects using the bulk resolutions.
    foreach ($pending as $i => $p) {
        $cp = $p['cp'];
        $resolution = $resolutions[(string)$i] ?? ['grade' => null, 'status' => (int)($cp->coursestatus ?? 0)];
        unset($pending[$i], $resolutions[(string)$i]); // release each row as it is consumed.

        $row = new stdClass();
        $row->id = $cp->userid;
        $row->fullname = $cp->firstname . ' ' . $cp->lastname;
        $row->email = $cp->email;
        $row->identification = $p['docNumber'];
        $row->career = $cp->career;
        $row->period = $cp->periodname;
        $row->course = $cp->coursename;

        $resolvedGrade = $resolution['grade'];
        $resolvedStatus = (int)$resolution['status'];
        $row->grade = ($resolvedGrade !== null) ? number_format((float)$resolvedGrade, 2) : '--';
        $row->student_status = $p['student_status'];
        $row->financial_status = $p['financial_status'];
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
        // El JS envía tanto valores crudos de fs.status (al_dia, mora,
        // sin_contrato_o_usuario) como nombres lógicos de las cards
        // financieras (up_to_date, in_arrears, pending, active). Usamos el
        // mismo helper que el endpoint de la tabla para traducir los nombres
        // lógicos a SQL, así el export refleja 1:1 lo que el usuario ve.
        list($fsClause, $fsParams) = local_grupomakro_translate_financial_filter($financial_status);
        if ($fsClause !== null) {
            $sqlConditions[] = $fsClause;
            $sqlParams = array_merge($sqlParams, $fsParams);
        }

        // Cualquier filtro financiero no vacio dispara el "universo activo"
        // en la tabla — replica esa logica aca. Sin esto, el export retornaba
        // TODOS los lpu con student en lugar del subconjunto activo cuando
        // el usuario seleccionaba "Estudiantes Activos" / "Al dia" / etc.
        if (local_grupomakro_needs_active_universe($financial_status)) {
            list($auClause, $auParams) = local_grupomakro_active_universe_clause();
            $sqlConditions[] = $auClause;
            $sqlParams = array_merge($sqlParams, $auParams);
        }
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
            // Journey (mismo custom field que la tabla: shortname=gmkjourney).
            $journey = '';
            if ($fieldJourney) {
                $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldJourney->id, 'userid' => $user->userid]);
                if ($val !== false && !empty($val)) $journey = $val;
            }

            $row = new stdClass();
            $row->id = $user->userid;
            $row->fullname = $user->firstname . ' ' . $user->lastname;
            $row->email = $user->email;
            $row->identification = $finalID;
            $row->journey = $journey;
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

    $columns = ['id', 'fullname', 'email', 'identification', 'journey', 'careers', 'periods', 'subperiods', 'student_status', 'financial_status'];
    $headers = ['ID Moodle', 'Nombre Completo', 'Email', 'Identificación', 'Jornada', 'Carreras', 'Cuatrimestres', 'Bloque', 'Estado Estudiante', 'Estado Financiero'];
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
