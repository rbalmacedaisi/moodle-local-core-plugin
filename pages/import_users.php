<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/externallib.php');

// Permissions
admin_externalpage_setup('grupomakro_core_import_users');
if (!is_siteadmin()) {
    print_error('onlyadmins', 'error');
}

$PAGE->set_url('/local/grupomakro_core/pages/import_users.php');
$PAGE->set_title('Importar Usuarios Masivamente');
$PAGE->set_heading('Importar Usuarios desde Excel');

$action = optional_param('action', '', PARAM_TEXT);

// Template Download
if ($action === 'download_template') {
    $filename = 'plantilla_usuarios_grupomakro.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    $headers = [
        'TipoDocumento', 
        'Documento_Usuario', 
        'Numero_ID', 
        'Nombres', 
        'Apellidos', 
        'Email', 
        'Telefono2', 
        'Telefono1', 
        'FechaNacimiento (YYYY-MM-DD)', 
        'Pais (Panamá/Venezuela)', 
        'Direccion', 
        'Genero', 
        'Estado (activo/inactivo)', 
        'Jornada'
    ];
    fputcsv($fp, $headers);
    
    // Example Row
    $example = [
        'CEDULA', 
        '8-123-456', 
        '8-123-456', 
        'Juan', 
        'Perez', 
        'juan.perez@example.com', 
        '6000-0000', 
        '200-0000', 
        '1990-01-01', 
        'Panamá', 
        'Ciudad de Panama', 
        'Masculino', 
        'activo', 
        'Matutina'
    ];
    fputcsv($fp, $example);
    fclose($fp);
    die;
}

// Log Export Handler
if ($action === 'download_log') {
    $logid = optional_param('logid', '', PARAM_ALPHANUM);
    $tempdir = make_temp_directory('grupomakro_import_logs');
    $file = $tempdir . '/' . $logid . '.csv';

    if (file_exists($file)) {
        send_file($file, 'resultados_importacion_' . date('Ymd_His') . '.csv');
        die;
    } else {
        print_error('filenotfound', 'error');
    }
}

echo $OUTPUT->header();

// Form Handling
$mform = new \local_grupomakro_core\form\import_file_form(null, ['filetypes' => ['.xlsx', '.xls', '.csv']]);

if ($mform->is_cancelled()) {
    redirect($CFG->wwwroot);
} else if ($data = $mform->get_data()) {
    
    // File Processing
    $filename = $mform->get_new_filename('importfile');
    $filepath = $mform->save_temp_file('importfile');

    echo $OUTPUT->heading("Procesando archivo: " . $filename);

    $results = ['success' => [], 'error' => []];
    $logData = [['Fila', 'Usuario', 'Estado', 'Detalle']]; // RAM Log

    try {
        $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($filepath);
        $sheet = $spreadsheet->getSheet(0); 
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        echo "<p>Total filas detectadas: $highestRow (ignorando encabezado)</p>";

        $table = new html_table();
        $table->head = ['Fila', 'Usuario', 'Estado', 'Detalle'];
        $table->data = [];

        for ($row = 2; $row <= $highestRow; $row++) { 
            $rowData = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);
                $val = $cell->getValue();
                $rowData[$col] = $val;
            }
            
            if (empty($rowData[2])) continue; 

            $verifyUsername = strtolower(trim($rowData[2]));
            $status = '';
            $msg = '';
            $class = '';

            try {
                $exists = $DB->get_record('user', ['username' => $verifyUsername, 'deleted' => 0]);
                $studentData = \local_grupomakro_core\local\importer_helper::construct_student_entity($rowData);

                if ($exists) {
                     $studentData['id'] = $exists->id;
                     if (!empty($rowData[13]) && $rowData[13] === 'inactivo') {
                         $studentData['suspended'] = 1;
                     }
                     core_user_external::update_users([$studentData]);
                     $status = 'Actualizado';
                     $msg = 'Usuario actualizado.';
                     $class = 'text-info';
                } else {
                     if (!empty($rowData[13]) && $rowData[13] === 'inactivo') {
                         $studentData['suspended'] = 1;
                     }
                     core_user_external::create_users([$studentData]);
                     $status = 'Creado';
                     $msg = 'Usuario creado.';
                     $class = 'text-success';
                }
            } catch (Exception $e) {
                $status = 'Error';
                $msg = property_exists($e, 'debuginfo') ? $e->debuginfo : $e->getMessage();
                $class = 'text-danger';
            }

            // Add to Log Array
            $logData[] = [$row, $verifyUsername, $status, strip_tags($msg)];

            // Use Array syntax for maximum compatibility
            $statusCell = ['data' => $status, 'class' => $class];
            $table->data[] = [$row, $verifyUsername, $statusCell, $msg];
        }

        echo html_writer::table($table);

        // SAVE LOG TO TEMP AND SHOW BUTTON
        $logId = uniqid();
        $tempdir = make_temp_directory('grupomakro_import_logs');
        $csvFile = fopen($tempdir . '/' . $logId . '.csv', 'w');
        fprintf($csvFile, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
        foreach ($logData as $fields) {
            fputcsv($csvFile, $fields);
        }
        fclose($csvFile);

        echo '<div class="mt-3 text-center">';
        echo '  <a href="?action=download_log&logid='.$logId.'" class="btn btn-warning btn-lg"><i class="fa fa-download"></i> Descargar Log de Resultados (CSV)</a>';
        echo '</div>';

    } catch (Exception $e) {
        echo $OUTPUT->notification('Error crítico: ' . $e->getMessage(), 'error');
    }

    echo $OUTPUT->continue_button(new moodle_url('/local/grupomakro_core/pages/import_users.php'));

} else {
    echo '<div class="mb-3"><a href="?action=download_template" class="btn btn-outline-secondary"><i class="fa fa-download"></i> Descargar Plantilla CSV de Ejemplo</a></div>';
    $mform->display();
    echo "<hr>";
    echo "<h3>Instrucciones</h3>";
    echo "<p>Suba un archivo Excel (.xlsx) o CSV con el formato de la plantilla.</p>";
    echo "<ul><li>Col 2: Documento (Username)</li><li>Col 4: Nombre</li><li>Col 5: Apellidos</li><li>Col 6: Email</li></ul>";
}

echo $OUTPUT->footer();
