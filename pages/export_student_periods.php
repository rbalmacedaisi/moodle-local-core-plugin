<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_login();
require_capability('moodle/site:config', context_system::instance());

$planid = optional_param('planid', '', PARAM_RAW);
$periodid = optional_param('periodid', '', PARAM_RAW);
$status = optional_param('status', '', PARAM_TEXT);
$search = optional_param('search', '', PARAM_RAW);

global $DB;

$where = ["u.deleted = 0", "u.suspended = 0"];
$params = [];

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

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY u.lastname, u.firstname";

$recordset = $DB->get_recordset_sql($sql, $params);

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Headers
$headers = ['ID Number', 'Nombre Completo', 'Plan de Estudio', 'Periodo Actual']; // Period last or logical?
// User said "Modifique la columna 'Periodo Actual'".
$sheet->setCellValue('A1', 'ID Number');
$sheet->setCellValue('B1', 'Nombre Completo');
$sheet->setCellValue('C1', 'Plan de Estudio');
$sheet->setCellValue('D1', 'Bloque');

// Style Header
$sheet->getStyle('A1:D1')->getFont()->setBold(true);

$rowNum = 2;
foreach ($recordset as $record) {
    $sheet->setCellValue('A' . $rowNum, $record->idnumber);
    $sheet->setCellValue('B' . $rowNum, $record->firstname . ' ' . $record->lastname);
    $sheet->setCellValue('C' . $rowNum, $record->planname);
    $sheet->setCellValue('D' . $rowNum, $record->periodname);
    $rowNum++;
}

$recordset->close();

// AutoSize Columns
foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output
$filename = 'estudiantes_periodos_' . date('YmdHi') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
