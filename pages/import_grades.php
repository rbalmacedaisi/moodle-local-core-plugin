<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

// Permissions
admin_externalpage_setup('grupomakro_core_import_grades');
if (!is_siteadmin()) {
    print_error('onlyadmins', 'error');
}

$PAGE->set_url('/local/grupomakro_core/pages/import_grades.php');
$PAGE->set_title('Importar Notas Históricas');
$PAGE->set_heading('Migración de Notas (Q10 -> Moodle)');

$action = optional_param('action', '', PARAM_TEXT);

if ($action === 'download_template') {
    $filename = 'plantilla_notas_q10.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
    
    // Headers
    $headers = ['Username', 'LearningPlanName', 'CourseShortname', 'Grade', 'Feedback'];
    fputcsv($fp, $headers);
    
    // Example
    $example = ['juan.perez', 'Soldadura Basica', 'SOLD-101', '85', 'Migrado 2023'];
    fputcsv($fp, $example);
    
    fclose($fp);
    die;
}

echo $OUTPUT->header();

echo '<div class="mb-4 d-flex" style="gap: 10px;">';
echo '<a href="grade_report.php" class="btn btn-info text-white"><i class="fa fa-list"></i> Ver Reporte de Discrepancias</a>';
echo '<a href="?action=download_template" class="btn btn-outline-secondary"><i class="fa fa-download"></i> Descargar Plantilla CSV de Ejemplo</a>';
echo '</div>';

$mform = new \local_grupomakro_core\form\import_file_form(null, ['filetypes' => ['.xlsx', '.xls']]);

if ($mform->is_cancelled()) {
     redirect($CFG->wwwroot);
} else if ($data = $mform->get_data()) {

    try {
        $filename = $mform->get_new_filename('importfile');
    $filepath = $mform->save_temp_file('importfile');

    echo $OUTPUT->heading("Procesando Notas: " . $filename);

    $filename = $mform->get_new_filename('importfile');
    $filepath = $mform->save_temp_file('importfile');

    // Stage file for AJAX chunks
    $tmpdir = make_temp_directory('grupomakro_imports');
    $tmpfilename = md5(time() . $filename) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $stagedPath = $tmpdir . '/' . $tmpfilename;
    copy($filepath, $stagedPath);

    // Get row count for progress initialization
    $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($stagedPath);
    $highestRow = $spreadsheet->getSheet(0)->getHighestDataRow();
    unset($spreadsheet); // Free memory

    echo $OUTPUT->heading("Panel de Importación Masiva: " . $filename);
    
    // Inject Vue container
    echo '
    <link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
    
    <div id="import-progress-app">
        <v-app class="transparent">
            <v-main>
                <import-progress 
                    filename="'.$tmpfilename.'" 
                    :total-rows="'.($highestRow - 1).'"
                    :chunk-size="50"
                ></import-progress>
            </v-main>
        </v-app>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
    <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
    ';

    $PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/import_progress_component.js?v=' . time()));
    $PAGE->requires->js_init_code("if(typeof initImportProgress === 'function') { initImportProgress(); } else { new Vue({ el: '#import-progress-app', vuetify: new Vuetify() }); }");

} catch (Exception $e) {
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
}
echo $OUTPUT->continue_button(new moodle_url('/local/grupomakro_core/pages/import_grades.php'));

} else {
    // Buttons already shown at top
    $mform->display();
    echo "<hr><h3>Formato Requerido (Excel sin encabezados o salta fila 1)</h3>";
    echo "<p>Col 1: Usuario | Col 2: Nombre Plan | Col 3: Shortname Curso | Col 4: Nota | Col 5: Feedback</p>";
}

echo $OUTPUT->footer();
