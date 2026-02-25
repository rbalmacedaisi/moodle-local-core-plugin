<?php
// Excel file download using PhpSpreadsheet
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

// Get all users without roles
$sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.timecreated
        FROM {user} u
        LEFT JOIN {role_assignments} ra ON ra.userid = u.id
        WHERE u.deleted = 0
        AND ra.id IS NULL
        ORDER BY u.timecreated DESC";

$users_no_roles = $DB->get_records_sql($sql);

// Get all learning plans
$plans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
$plan_names = array_map(function($p) { return $p->name; }, $plans);

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Estudiantes');

// Headers
$headers = ['Username (Cédula)', 'Nombre Completo', 'Email', 'ID Number (Expediente)', 'Plan de Aprendizaje'];
$sheet->fromArray($headers, null, 'A1');

// Style headers
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];
$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

// Add data rows
$row = 2;
foreach ($users_no_roles as $user) {
    $sheet->setCellValue('A' . $row, $user->username);
    $sheet->setCellValue('B' . $row, $user->firstname . ' ' . $user->lastname);
    $sheet->setCellValue('C' . $row, $user->email);
    $sheet->setCellValue('D' . $row, $user->idnumber);
    $sheet->setCellValue('E' . $row, ''); // Empty for user to fill
    $row++;
}

// Add instructions
$row += 2;
$sheet->setCellValue('A' . $row, 'INSTRUCCIONES:');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$row++;
$sheet->setCellValue('A' . $row, '1. Complete la columna "Plan de Aprendizaje" con el nombre EXACTO del plan');
$row++;
$sheet->setCellValue('A' . $row, '2. NO modifique las otras columnas');
$row++;
$sheet->setCellValue('A' . $row, '3. Planes disponibles: ' . implode(', ', $plan_names));

// Auto-size columns
foreach (range('A', 'E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Clean all buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Output file
$filename = 'plantilla_reparacion_estudiantes_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
