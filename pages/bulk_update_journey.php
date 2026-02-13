<?php
require_once('../../../config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/bulk_update_journey.php'));
$PAGE->set_context($context);
$PAGE->set_title('Actualización Masiva de Jornada');
$PAGE->set_heading('Actualización Masiva de Jornada');

echo $OUTPUT->header();

$log = [];
$successCount = 0;
$failCount = 0;

if ($data = data_submitted() && confirm_sesskey()) {
    if (!empty($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/importer_helper.php');
        
        try {
            $tmpFilePath = $_FILES['import_file']['tmp_name'];
            $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($tmpFilePath);
            $sheet = $spreadsheet->getSheet(0);
            $rows = $sheet->toArray();
            
            if (count($rows) < 2) {
                echo $OUTPUT->notification('El archivo parece estar vacío o solo tiene cabecera.', 'warning');
            } else {
                $headers = array_map('trim', array_map('strtolower', $rows[0]));
                
                // Flexible header search
                $docIdx = -1;
                $journeyIdx = -1;
                
                foreach ($headers as $idx => $h) {
                    if (strpos($h, 'documentnumber') !== false || strpos($h, 'cédula') !== false || strpos($h, 'cedula') !== false || $h === 'id') $docIdx = $idx;
                    if (strpos($h, 'jornada') !== false || strpos($h, 'journey') !== false) $journeyIdx = $idx;
                }
                
                if ($docIdx === -1 || $journeyIdx === -1) {
                    echo $OUTPUT->notification('No se encontraron las columnas necesarias. Se requiere "documentnumber" (o Cédula) y "jornada".', 'error');
                } else {
                    // Get 'gmkjourney' field ID
                    $fieldJourney = $DB->get_record('user_info_field', ['shortname' => 'gmkjourney']);
                    if (!$fieldJourney) {
                        throw new Exception("El campo de perfil 'gmkjourney' no existe en el sistema.");
                    }
                    
                    // Helper to get 'documentnumber' field ID
                    $fieldDoc = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
                    
                    // Process rows
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        $docNum = trim($row[$docIdx]);
                        $journeyVal = trim($row[$journeyIdx]);
                        
                        if (empty($docNum)) continue;
                        
                        // Find User by Document Number (Profile Field)
                        $user = null;
                        if ($fieldDoc) {
                             $sql = "SELECT u.id, u.firstname, u.lastname 
                                     FROM {user} u
                                     JOIN {user_info_data} uid ON uid.userid = u.id
                                     WHERE uid.fieldid = :fieldid AND uid.data = :docnum AND u.deleted = 0";
                             $user = $DB->get_record_sql($sql, ['fieldid' => $fieldDoc->id, 'docnum' => $docNum]);
                        }
                        
                        // Fallback: Check idnumber or username if not found by doc number
                        if (!$user) {
                            $user = $DB->get_record('user', ['idnumber' => $docNum, 'deleted' => 0], 'id, firstname, lastname');
                        }
             
                        if (!$user) {
                            $log[] = "Fila " . ($i+1) . ": Usuario con Cédula/ID '$docNum' no encontrado.";
                            $failCount++;
                            continue;
                        }
                        
                        // Update Journey
                        // Check if data exists
                        $existingData = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldJourney->id]);
                        if ($existingData) {
                            $existingData->data = $journeyVal;
                            $DB->update_record('user_info_data', $existingData);
                        } else {
                            $newData = new stdClass();
                            $newData->userid = $user->id;
                            $newData->fieldid = $fieldJourney->id;
                            $newData->data = $journeyVal;
                            $newData->dataformat = 0; // Default
                            $DB->insert_record('user_info_data', $newData);
                        }
                        
                        $successCount++;
                         // Optional: detailed log success
                         // $log[] = "Fila " . ($i+1) . ": Actualizado $docNum -> $journeyVal";
                    }
                    
                    echo $OUTPUT->notification("Proceso completado. Registros actualizados: $successCount. Errores: $failCount.", 'success');
                }
            }
            
        } catch (Exception $e) {
            echo $OUTPUT->notification('Error procesando archivo: ' . $e->getMessage(), 'error');
        }
    } else {
        echo $OUTPUT->notification('Error al subir el archivo.', 'error');
    }
}

echo '<div class="card p-3 mb-3">';
echo '<h3>Importar Datos de Jornada</h3>';
echo '<p>Suba un archivo Excel (.xlsx) con las siguientes columnas (la cabecera es ignorada en la búsqueda, pero ayuda a identificar):</p>';
echo '<ul><li><b>documentnumber</b> (o Cédula, ID)</li><li><b>jornada</b> (o Journey)</li></ul>';
echo '<form action="" method="post" enctype="multipart/form-data" class="m-3">';
echo '<div class="form-group">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<label for="import_file" class="mr-2">Archivo Excel:</label>';
echo '<input type="file" name="import_file" id="import_file" accept=".xlsx, .xls" required>';
echo '</div>';
echo '<button type="submit" class="btn btn-primary mt-2">Procesar Actualización</button>';
echo '</form>';
echo '</div>';

if (!empty($log)) {
    echo '<div class="card p-3">';
    echo '<h4>Log de Errores/Detalles</h4>';
    echo '<pre style="max-height: 300px; overflow-y: auto; background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6;">';
    echo implode("\n", $log);
    echo '</pre>';
    echo '</div>';
}

echo $OUTPUT->footer();
