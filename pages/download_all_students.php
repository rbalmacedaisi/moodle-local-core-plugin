<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/vendor/autoload.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

// SQL to get all students with their core data and academic configuration
$sql = "SELECT 
            llu.id as recordid,
            u.id as userid,
            u.username, 
            u.firstname, 
            u.lastname, 
            u.email,
            u.idnumber,
            u.phone1,
            u.phone2,
            u.institution,
            u.department,
            u.city,
            u.country,
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
        AND llu.userrolename = 'student'
        ORDER BY lp.name ASC, per.id ASC, u.lastname ASC";

$students = $DB->get_records_sql($sql);

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Exportación Estudiantes');

// Headers
$headers = [
    'Username', 
    'Nombres', 
    'Apellidos', 
    'Email Moodle',
    'ID Number (Expediente)', 
    'Institución',
    'Facultad/Depto',
    'Teléfono 1',
    'Teléfono 2',
    'Ciudad',
    'Plan de Aprendizaje', 
    'Nivel (Periodo)', 
    'Subperiodo', 
    'Periodo Académico', 
    'Bloque (Grupo)', 
    'Estado Académico',
    'Tipo Documento',
    'Número Documento',
    'Email Personal',
    'Gestor/Cuenta',
    'Tipo Usuario'
];
$sheet->fromArray($headers, null, 'A1');

// Style headers
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];
$sheet->getStyle('A1:U1')->applyFromArray($headerStyle);

// Add data rows
$row = 2;
foreach ($students as $s) {
    // Load custom profile fields
    profile_load_custom_fields($s);
    
    $sheet->setCellValue('A' . $row, $s->username);
    $sheet->setCellValue('B' . $row, $s->firstname);
    $sheet->setCellValue('C' . $row, $s->lastname);
    $sheet->setCellValue('D' . $row, $s->email);
    $sheet->setCellValue('E' . $row, $s->idnumber);
    $sheet->setCellValue('F' . $row, $s->institution);
    $sheet->setCellValue('G' . $row, $s->department);
    $sheet->setCellValue('H' . $row, $s->phone1);
    $sheet->setCellValue('I' . $row, $s->phone2);
    $sheet->setCellValue('J' . $row, $s->city);
    $sheet->setCellValue('K' . $row, $s->plan_name);
    $sheet->setCellValue('L' . $row, $s->level_name);
    $sheet->setCellValue('M' . $row, $s->subperiod_name);
    $sheet->setCellValue('N' . $row, $s->academic_name);
    $sheet->setCellValue('O' . $row, $s->groupname);
    $sheet->setCellValue('P' . $row, $s->status);
    
    // Custom Fields
    $sheet->setCellValue('Q' . $row, $s->profile_field_documenttype ?? '');
    $sheet->setCellValue('R' . $row, $s->profile_field_documentnumber ?? '');
    $sheet->setCellValue('S' . $row, $s->profile_field_personalemail ?? '');
    $sheet->setCellValue('T' . $row, $s->profile_field_accountmanager ?? '');
    $sheet->setCellValue('U' . $row, $s->profile_field_usertype ?? '');
    
    $row++;
}

// Auto-size columns
foreach (range('A', 'U') as $col) {
    if ($col === 'U') break; // Loop through A to U
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
// Manually handle the last few columns if range loop is tricky with double letters (not needed yet as we are only at U)
$sheet->getColumnDimension('U')->setAutoSize(true);

// Clean all buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Output file
$filename = 'exportacion_estudiantes_completo_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
