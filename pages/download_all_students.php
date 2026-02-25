<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/vendor/autoload.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

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
            llu.status as academic_status
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

// Validation data
$available_plans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
$plan_names = array_map(function($p) { return $p->name; }, $available_plans);

$available_academic = $DB->get_records('gmk_academic_periods', null, 'name ASC', 'id, name');
$academic_names = array_map(function($a) { return $a->name; }, $available_academic);

$valid_statuses = ['activo', 'aplazado', 'retirado', 'suspendido'];
$doc_types = ['Cédula de Ciudadanía', 'Cédula de Extranjería', 'Pasaporte'];
$user_types = ['Estudiante', 'Acudiente / Codeudor'];
$genders = ['Masculino', 'Femenino', 'Otro'];
$journeys = ['Diurna', 'Nocturna', 'Sabatina', 'Virtual'];
$yes_no = ['si', 'no'];

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Exportación Estudiantes');

// --- INSTRUCTIONS SECTION ---
$sheet->setCellValue('A1', 'INSTRUCCIONES DE IMPORTACIÓN:');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0000'));

$help_rows = [
    ['CAMPO', 'OPCIONES DISPONIBLES / REGLAS'],
    ['Estado Académico', implode(', ', $valid_statuses)],
    ['Tipo Documento', implode(', ', $doc_types)],
    ['Tipo Usuario', implode(', ', $user_types)],
    ['Género', implode(', ', $genders)],
    ['Jornada', implode(', ', $journeys)],
    ['Debe pagar matrícula', implode(', ', $yes_no)],
    ['Plan de Aprendizaje', 'Cualquiera de: ' . implode(' | ', $plan_names)],
    ['Regla General', 'Respete mayúsculas, minúsculas y tildes de las opciones de arriba.'],
    ['Nota', 'No modifique el nombre de las columnas ni el orden de la tabla de datos inferior.']
];

$curr_row = 2;
foreach ($help_rows as $h) {
    $sheet->setCellValue('A' . $curr_row, $h[0]);
    $sheet->setCellValue('C' . $curr_row, $h[1]); 
    $sheet->getStyle('A' . $curr_row)->getFont()->setBold(true);
    $curr_row++;
}

// Separate line
$curr_row += 1;
$data_start_row = $curr_row;

// Headers
$headers = [
    'Username', 'Nombres', 'Apellidos', 'Email Moodle', 'ID Number (Expediente)', 
    'Institución', 'Facultad/Depto', 'Teléfono 1', 'Teléfono 2', 'Ciudad',
    'Plan de Aprendizaje', 'Nivel (Periodo)', 'Subperiodo', 'Periodo Académico', 'Bloque (Grupo)', 
    'Estado Académico', 
    'Tipo Usuario', 'Asesor Comercial', 'Fecha Nacimiento', 'Tipo Documento', 'Número Documento', 
    'Paga Matrícula', 'Correo Personal', 'Estado Estudiante', 'Género', 'Jornada', 
    'Movil Personalizado', 'Periodo Ingreso'
];
$sheet->fromArray($headers, null, 'A' . $data_start_row);

// Style headers
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$last_col = 'AB'; // Calculated based on number of headers
$head_range = 'A' . $data_start_row . ':' . $last_col . $data_start_row;
$sheet->getStyle($head_range)->applyFromArray($headerStyle);

// Add data rows
$curr_row++;
foreach ($students as $s) {
    // Load custom profile fields
    $s->id = $s->userid;
    profile_load_custom_fields($s);
    
    $sheet->setCellValue('A' . $curr_row, $s->username);
    $sheet->setCellValue('B' . $curr_row, $s->firstname);
    $sheet->setCellValue('C' . $curr_row, $s->lastname);
    $sheet->setCellValue('D' . $curr_row, $s->email);
    $sheet->setCellValue('E' . $curr_row, $s->idnumber);
    $sheet->setCellValue('F' . $curr_row, $s->institution);
    $sheet->setCellValue('G' . $curr_row, $s->department);
    $sheet->setCellValue('H' . $curr_row, $s->phone1);
    $sheet->setCellValue('I' . $curr_row, $s->phone2);
    $sheet->setCellValue('J' . $curr_row, $s->city);
    $sheet->setCellValue('K' . $curr_row, $s->plan_name);
    $sheet->setCellValue('L' . $curr_row, $s->level_name);
    $sheet->setCellValue('M' . $curr_row, $s->subperiod_name);
    $sheet->setCellValue('N' . $curr_row, $s->academic_name);
    $sheet->setCellValue('O' . $curr_row, $s->groupname);
    $sheet->setCellValue('P' . $curr_row, $s->academic_status);
    
    // Custom Fields from Screenshot
    $sheet->setCellValue('Q' . $curr_row, $s->profile_field_usertype ?? '');
    $sheet->setCellValue('R' . $curr_row, $s->profile_field_accountmanager ?? '');
    
    // Format birthdate if it's a timestamp
    $bday = $s->profile_field_birthdate ?? '';
    if (is_numeric($bday) && $bday > 0) {
        $bday = date('Y-m-d', $bday);
    }
    $sheet->setCellValue('S' . $curr_row, $bday);
    $sheet->setCellValue('T' . $curr_row, $s->profile_field_documenttype ?? '');
    $sheet->setCellValue('U' . $curr_row, $s->profile_field_documentnumber ?? '');
    $sheet->setCellValue('V' . $curr_row, $s->profile_field_needfirsttuition ?? '');
    $sheet->setCellValue('W' . $curr_row, $s->profile_field_personalemail ?? '');
    $sheet->setCellValue('X' . $curr_row, $s->profile_field_studentstatus ?? '');
    $sheet->setCellValue('Y' . $curr_row, $s->profile_field_gmkgenre ?? '');
    $sheet->setCellValue('Z' . $curr_row, $s->profile_field_gmkjourney ?? '');
    $sheet->setCellValue('AA' . $curr_row, $s->profile_field_custom_phone ?? '');
    $sheet->setCellValue('AB' . $curr_row, $s->profile_field_periodo_ingreso ?? '');
    
    $curr_row++;
}

// Auto-size columns
for ($i = 0; $i < 28; $i++) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Clean all buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Output file
$filename = 'exportacion_total_estudiantes_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
