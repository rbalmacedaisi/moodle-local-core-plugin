<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

// SQL to get all students with their full learning configuration
$sql = "SELECT 
            u.username, 
            u.firstname, 
            u.lastname, 
            u.idnumber,
            lp.name as plan_name,
            per.name as level_name,
            sub.name as subperiod_name,
            ap.name as academic_name,
            llu.groupname,
            llu.status
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id
        LEFT JOIN {local_learning_plans} lp ON llu.learningplanid = lp.id
        LEFT JOIN {local_learning_periods} per ON llu.currentperiodid = per.id
        LEFT JOIN {local_learning_subperiods} sub ON llu.currentsubperiodid = sub.id
        LEFT JOIN {gmk_academic_periods} ap ON llu.academicperiodid = ap.id
        WHERE u.deleted = 0
        ORDER BY lp.name ASC, per.id ASC, u.lastname ASC";

$students = $DB->get_records_sql($sql);

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Listado Maestro Estudiantes');

// Headers
$headers = [
    'Username', 
    'Nombre Completo', 
    'ID Number (Expediente)', 
    'Plan de Aprendizaje', 
    'Nivel (Periodo)', 
    'Subperiodo', 
    'Periodo Académico', 
    'Bloque (Grupo)', 
    'Estado'
];
$sheet->fromArray($headers, null, 'A1');

// Style headers
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];
$sheet->getStyle('A1:I1')->applyFromArray($headerStyle);

// Add data rows
$row = 2;
foreach ($students as $s) {
    $sheet->setCellValue('A' . $row, $s->username);
    $sheet->setCellValue('B' . $row, $s->firstname . ' ' . $s->lastname);
    $sheet->setCellValue('C' . $row, $s->idnumber);
    $sheet->setCellValue('D' . $row, $s->plan_name);
    $sheet->setCellValue('E' . $row, $s->level_name);
    $sheet->setCellValue('F' . $row, $s->subperiod_name);
    $sheet->setCellValue('G' . $row, $s->academic_name);
    $sheet->setCellValue('H' . $row, $s->groupname);
    $sheet->setCellValue('I' . $row, $s->status);
    $row++;
}

// Auto-size columns
foreach (range('A', 'I') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Clean all buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Output file
$filename = 'listado_maestro_estudiantes_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
