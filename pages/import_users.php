<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/externallib.php');

// Permissions
admin_externalpage_setup('grupomakro_core_import_users');

$PAGE->set_url('/local/grupomakro_core/pages/import_users.php');
$PAGE->set_title('Importar Usuarios Masivamente');
$PAGE->set_heading('Importar Usuarios desde Excel');

$action = optional_param('action', '', PARAM_TEXT);

if ($action === 'download_template') {
    $filename = 'plantilla_usuarios_grupomakro.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $fp = fopen('php://output', 'w');
    // Bom for Excel
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers based on importer helper mapping
    // [1]: DocType, [2]: DocNum/Username, [3]: ?, [4]: Name, [5]: Lastname, [6]: Email
    // [7]: Phone2, [8]: Phone1, [9]: Birthdate, [10]: Country, [11]: Address, [12]: Genre, [13]: Status, [14]: Journey
    $headers = [
        'TipoDocumento', 
        'Documento_Usuario', 
        'Column_3_Unused', 
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
        '', 
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

    try {
        $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($filepath);
        $sheet = $spreadsheet->getSheet(0); // Assume first sheet
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        echo "<p>Total filas detectadas: $highestRow</p>";

        $table = new html_table();
        $table->head = ['Fila', 'Usuario', 'Estado', 'Detalle'];
        $table->data = [];

        for ($row = 2; $row <= $highestRow; $row++) { // Skip header
            $rowData = [];
            
            // Helper expects index 1-based, let's map manual array
            // migrate.php accessed data by column index 1-based.
            // Row data in migrate.php was array $rowData[$col] = value
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);
                $val = $cell->getValue();
                // Date check logic from migrate.php could be here if using getCell...
                // But helper handles parsing if we pass raw value or handle mapping
                $rowData[$col] = $val;
            }
            
            // Required Check
            if (empty($rowData[2])) continue; // Username empty

            try {
                // Construct Entity
                $verifyUsername = strtolower(trim($rowData[2]));
                $exists = $DB->get_record('user', ['username' => $verifyUsername, 'deleted' => 0]);
                
                $studentData = \local_grupomakro_core\local\importer_helper::construct_student_entity($rowData);

                if ($exists) {
                     // Update Logic
                     $studentData['id'] = $exists->id;
                     // Only update fields provided? core_user_external::update_users
                     // For now, let's assume we skip or update. 
                     // Let's UPDATE suspended status based on Col 13 if provided
                     if (!empty($rowData[13]) && $rowData[13] === 'inactivo') {
                         $studentData['suspended'] = 1;
                     }
                     core_user_external::update_users([$studentData]);
                     $status = 'Actualizado';
                     $msg = 'Usuario ya existía. Datos actualizados.';
                     $class = 'text-info';

                } else {
                     // Create Logic
                     if (!empty($rowData[13]) && $rowData[13] === 'inactivo') {
                         $studentData['suspended'] = 1;
                     }
                     core_user_external::create_users([$studentData]);
                     $status = 'Creado';
                     $msg = 'Usuario creado exitosamente.';
                     $class = 'text-success';
                }

                $table->data[] = [$row, $verifyUsername, new html_table_cell($status), $msg];
                $table->data[count($table->data)-1]->cells[2]->attributes = ['class' => $class];

            } catch (Exception $e) {
                $errorMsg = property_exists($e, 'debuginfo') ? $e->debuginfo : $e->getMessage();
                $table->data[] = [$row, $rowData[2] ?? '?', new html_table_cell('Error'), $errorMsg];
                 $table->data[count($table->data)-1]->cells[2]->attributes = ['class' => 'text-danger'];
            }
        }

        echo html_writer::table($table);

    } catch (Exception $e) {
        echo $OUTPUT->notification('Error crítico al leer archivo: ' . $e->getMessage(), 'error');
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
