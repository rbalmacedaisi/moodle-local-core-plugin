<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

// Optional Autoload
if (file_exists($CFG->dirroot . '/vendor/autoload.php')) {
    require_once($CFG->dirroot . '/vendor/autoload.php');
}

// Permissions
admin_externalpage_setup('grupomakro_core_teachers_management');

$PAGE->set_url('/local/grupomakro_core/pages/teachers.php');
$PAGE->set_title(get_string('admin_teachers_management', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('admin_teachers_management', 'local_grupomakro_core'));
$PAGE->requires->jquery();

// Params
$filter_search = optional_param('search', '', PARAM_TEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

$pagingurl = new moodle_url('/local/grupomakro_core/pages/teachers.php');
if (!empty($filter_search)) {
    $pagingurl->param('search', $filter_search);
}

// SQL Construction
$sqlParams = ['userrolename' => 'teacher'];
$whereConditions = ["lpu.userrolename = :userrolename"];

if (!empty($filter_search)) {
    $whereConditions[] = "(" . $DB->sql_like('u.firstname', ':search1', false) . " OR " . 
                          $DB->sql_like('u.lastname', ':search2', false) . " OR " . 
                          $DB->sql_like('u.email', ':search3', false) . " OR " . 
                          $DB->sql_like('u.idnumber', ':search4', false) . " OR " . 
                          "EXISTS (SELECT 1 FROM {user_info_data} uid JOIN {user_info_field} uif ON uif.id = uid.fieldid WHERE uid.userid = u.id AND uif.shortname = 'documentnumber' AND " . $DB->sql_like('uid.data', ':search5', false) . "))";
    $sqlParams['search1'] = "%$filter_search%";
    $sqlParams['search2'] = "%$filter_search%";
    $sqlParams['search3'] = "%$filter_search%";
    $sqlParams['search4'] = "%$filter_search%";
    $sqlParams['search5'] = "%$filter_search%";
}

$whereSQL = implode(" AND ", $whereConditions);

$query = "
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.phone1, u.idnumber
    FROM {user} u
    JOIN {local_learning_users} lpu ON lpu.userid = u.id
    WHERE $whereSQL
    ORDER BY u.firstname, u.lastname";

$total_matching = $DB->count_records_sql("SELECT COUNT(DISTINCT u.id) FROM {user} u JOIN {local_learning_users} lpu ON lpu.userid = u.id WHERE $whereSQL", $sqlParams);

// EXPORT TO XLSX
if ($action === 'export') {
    $filename = 'reporte_docentes_' . date('Ymd_His') . '.xlsx';
    
    // Create Spreadsheet
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Docentes');
    
    // Headers
    $headers = ['ID', 'Nombre Completo', 'Documento/Cédula', 'Teléfono', 'Correo Electrónico'];
    $sheet->fromArray($headers, NULL, 'A1');
    
    // Fetch ALL matching teachers for export
    $export_teachers = $DB->get_records_sql($query, $sqlParams);
    
    $row = 2;
    $docField = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
    
    foreach ($export_teachers as $t) {
        $fullName = fullname($t);
        
        $docNumber = $t->idnumber;
        if ($docField) {
            $data = $DB->get_field('user_info_data', 'data', ['userid' => $t->id, 'fieldid' => $docField->id]);
            if (!empty($data)) {
                $docNumber = $data;
            }
        }

        $sheet->setCellValue('A' . $row, $t->id);
        $sheet->setCellValue('B' . $row, $fullName);
        $sheet->setCellValue('C' . $row, $docNumber);
        $sheet->setCellValue('D' . $row, $t->phone1 ?: '');
        $sheet->setCellValue('E' . $row, $t->email);
        $row++;
    }
    
    // Auto-size columns
    foreach(range('A','E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Output headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    die;
}

$teachers = $DB->get_records_sql($query, $sqlParams, $page * $perpage, $perpage);

echo $OUTPUT->header();

// Styles consistent with manage_courses.php
echo '
<link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<style>
    body { font-family: "Roboto", sans-serif; }
    .card-material {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border: none;
        margin-bottom: 20px;
    }
    .card-header-material {
        background: transparent;
        border-bottom: 1px solid #eee;
        padding: 15px 20px;
        font-weight: 500;
        color: #333;
        font-size: 1.1rem;
    }
    .table-material th { border-top: none; color: #666; font-weight: 500; }
    .btn-material {
        border-radius: 20px;
        text-transform: uppercase;
        font-weight: 500;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        padding: 8px 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        border: none;
    }
    .btn-material-primary { background: #1976D2; color: #fff; }
    .btn-material-primary:hover { background: #1565C0; color: #fff; text-decoration: none;}
</style>
';

echo '<div class="card-material">';
echo '<div class="card-header-material d-flex justify-content-between align-items-center">';
echo '  <span><i class="mdi mdi-account-tie"></i> ' . get_string('teachers', 'local_grupomakro_core') . '</span>';
echo '</div>';
echo '<div class="card-body">';

// Filter Form
echo '<form method="get" class="row mb-4 bg-light p-3 rounded mx-1">';
echo ' <div class="col-md-6 mb-2">';
echo '   <div class="input-group">';
echo '     <div class="input-group-prepend"><span class="input-group-text border-0 bg-white"><i class="mdi mdi-magnify"></i></span></div>';
echo '     <input type="text" name="search" class="form-control border-0" placeholder="'.get_string('search', 'local_grupomakro_core').'..." value="'.s($filter_search).'">';
echo '   </div>';
echo ' </div>';
echo ' <div class="col-md-6 mb-2 d-flex justify-content-end">';
echo '   <button type="submit" class="btn btn-material btn-material-primary mr-2">'.get_string('apply_filter', 'local_grupomakro_core').'</button>';
echo '   <button type="submit" name="action" value="export" class="btn btn-success"><i class="mdi mdi-file-excel"></i> Excel</button>';
echo ' </div>';
echo '</form>';

if ($teachers) {
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover table-material">';
    echo '<thead><tr>
            <th>'.get_string('fullname', 'local_grupomakro_core').'</th>
            <th>'.get_string('identification_number', 'local_grupomakro_core').'</th>
            <th>'.get_string('phone', 'local_grupomakro_core').'</th>
            <th>'.get_string('email', 'local_grupomakro_core').'</th>
            <th class="text-right">'.get_string('actions', 'local_grupomakro_core').'</th>
          </tr></thead>';
    echo '<tbody>';
    
    $docField = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);

    foreach ($teachers as $t) {
        $fullName = fullname($t);
        
        // Document Number
        $docNumber = $t->idnumber;
        if ($docField) {
            $data = $DB->get_field('user_info_data', 'data', ['userid' => $t->id, 'fieldid' => $docField->id]);
            if (!empty($data)) {
                $docNumber = $data;
            }
        }

        $viewUrl = new moodle_url('/user/view.php', ['id' => $t->id, 'course' => 1]);
        $editUrl = new moodle_url('/user/edit.php', ['id' => $t->id, 'course' => 1]);

        echo '<tr>';
        echo '<td><strong>'.s($fullName).'</strong></td>';
        echo '<td>'.s($docNumber).'</td>';
        echo '<td>'.s($t->phone1 ?: '--').'</td>';
        echo '<td>'.s($t->email).'</td>';
        echo '<td class="text-right">';
        echo '  <a href="'.$viewUrl.'" title="'.get_string('see', 'local_grupomakro_core').'" class="mr-2"><i class="mdi mdi-eye"></i></a>';
        echo '  <a href="'.$editUrl.'" title="'.get_string('edit', 'local_grupomakro_core').'"><i class="mdi mdi-pencil"></i></a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    
    echo $OUTPUT->paging_bar($total_matching, $page, $perpage, $pagingurl);

} else {
    echo '<div class="alert alert-info text-center p-4">'.get_string('there_no_data', 'local_grupomakro_core').'</div>';
}

echo '</div>'; // card-body
echo '</div>'; // card-material

echo $OUTPUT->footer();
