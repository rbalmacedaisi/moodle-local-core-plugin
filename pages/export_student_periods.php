<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$planid = optional_param('planid', '', PARAM_RAW);
$periodid = optional_param('periodid', '', PARAM_RAW); // Not really used for export usually, but maybe to filter
$status = optional_param('status', '', PARAM_TEXT);
$search = optional_param('search', '', PARAM_RAW);

// Prepare filename
$filename = 'estudiantes_filt_' . date('YmdHi') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
// BOM for Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header
fputcsv($output, ['ID Number', 'Nombre Completo', 'Periodo Actual', 'Plan de Estudio']);

// We need to fetch students. 
// Reusing get_student_info logic might be heavy if paging is default, we want ALL.
// So let's construct the SQL. Similar to get_student_info but without paging.
// Query source: classes/external/student/get_student_info.php

global $DB;

$where = ["u.deleted = 0", "u.suspended = 0"];
$params = [];

// Base Query
// We need to join with local_learning_users to get plan/period
$sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.idnumber,
               lp.name as planname, p.name as periodname
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
        JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
        JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
";

if (!empty($planid)) {
    $planIds = explode(',', $planid);
    list($insql, $inparams) = $DB->get_in_or_equal($planIds, SQL_PARAMS_NAMED);
    $where[] = "llu.learningplanid $insql";
    $params = array_merge($params, $inparams);
}

if (!empty($periodid)) {
    $periodIds = explode(',', $periodid);
    list($insql, $inparams) = $DB->get_in_or_equal($periodIds, SQL_PARAMS_NAMED);
    $where[] = "llu.currentperiodid $insql";
    $params = array_merge($params, $inparams);
}

if (!empty($search)) {
    $where[] = "(u.firstname LIKE :search OR u.lastname LIKE :search OR u.email LIKE :search OR u.idnumber LIKE :search)";
    $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
}

// Status filtering logic from get_student_info is complex (calculated via Odoo/Progress).
// If we want simple DB status, we can use u.suspended.
// But get_student_info calculates 'Active', 'Graduate' based on progress.
// For now, let's just dump the queried students. The user can filter in Excel if status calc is too heavy.
// Or if status param is passed, we might need to filter PHP side if it's not in DB.
// Let's keep it simple: Export what matches filters we can apply easily.

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY u.lastname, u.firstname";

$recordset = $DB->get_recordset_sql($sql, $params);

foreach ($recordset as $record) {
    fputcsv($output, [
        $record->idnumber,
        $record->firstname . ' ' . $record->lastname,
        $record->periodname,
        $record->planname
    ]);
}

$recordset->close();
fclose($output);
exit;
