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

    $filename = $mform->get_new_filename('importfile');
    $filepath = $mform->save_temp_file('importfile');

    echo $OUTPUT->heading("Procesando Notas: " . $filename);

    \core\session\manager::write_close();
    set_time_limit(0);
    ini_set('memory_limit', '2048M');

    try {
        $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($filepath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();

        $toSyncPeriods = [];

        $table = new html_table();
        $table->head = ['Fila', 'Usuario', 'Curso', 'Acción Matricula', 'Acción Nota', 'Resultado'];
        $table->data = [];

        // Columns expected (1-based index)
        // 1: Username
        // 2: Product Name (Plan)
        // 3: Course Shortname
        // 4: Grade
        // 5: Feedback (Optional)

        for ($row = 2; $row <= $highestRow; $row++) { // Skip header
             $username      = trim($sheet->getCellByColumnAndRow(1, $row)->getValue());
             $planName      = trim($sheet->getCellByColumnAndRow(2, $row)->getValue());
             $courseShort   = trim($sheet->getCellByColumnAndRow(3, $row)->getValue());
             $gradeVal      = floatval($sheet->getCellByColumnAndRow(4, $row)->getValue());
             $feedback      = trim($sheet->getCellByColumnAndRow(5, $row)->getValue());

             if (empty($username) || empty($planName)) continue;

             $logEnroll = '-';
             $logGrade = '-';
             $status = 'OK';
             $rowClass = '';

             try {
                // 1. Enroll / Ensure Enrollment
                // Call external class logic directly
                // Note: execute returns array ['status' => ..., 'learning_user_id' => ...]
                try {
                     $enrollResult = \local_grupomakro_core\external\odoo\enroll_student::execute($planName, $username);
                     $logEnroll = ($enrollResult['status'] == 'success') ? 'Matriculado' : 'Ya en Plan';
                } catch (Exception $e) {
                     throw new Exception('Fallo Matricula: ' . $e->getMessage());
                }

                // 2. Resolve Course & Gradebook
                $acc_course = $DB->get_record('course', ['shortname' => $courseShort]);
                if (!$acc_course) {
                    throw new Exception("Curso '$courseShort' no existe");
                }

                if (empty($feedback)) $feedback = 'Nota migrada de Q10';

                // 3. Update Grade
                // Source: manual, item type: manual
                // But we want to update the COURSE TOTAL if possible or a MANUAL ITEM?
                // Requirements said: "dejando alguna nota que vean los usuarios... que indique que es una nota migrada"
                // Best practice: Create a Manual Grade Item named "Nota Histórica Q10" or update the Course Total override?
                // Updating Course Total directly can be locked. 
                // Let's create a specific Manual Item if it doesn't exist, or update it.
                
                $grade_item = \grade_item::fetch(array('courseid' => $acc_course->id, 'itemtype' => 'manual', 'itemname' => 'Nota Final Integrada'));
                if (!$grade_item) {
                     // Create it
                     $grade_item = new \grade_item(array('courseid' => $acc_course->id, 'itemtype' => 'manual', 'itemname' => 'Nota Final Integrada', 'grademin'=>0, 'grademax'=>100));
                     $grade_item->insert('manual');
                }

                $grade_grade = $grade_item->get_grade($enrollResult['learning_user_id']); // Wait, enroll returns learning_user_id, we need USER ID.
                // We better get user ID from username
                $user = $DB->get_record('user', ['username' => $username, 'deleted' =>0]);
                
                $grade_item->update_final_grade($user->id, $gradeVal, 'import', $feedback, FORMAT_HTML);
                
                // Force triggering progress update in grupomakro_core
                // based on observer analysis, we might need to manually call it
                \local_grupomakro_progress_manager::update_course_progress($acc_course->id, $user->id);

                // Track for period sync
                $userPlanKey = $user->id . '_' . $enrollResult['plan_id']; // We need plan_id from enrollResult or plan object
                $toSyncPeriods[$userPlanKey] = ['userid' => $user->id, 'planid' => $enrollResult['plan_id']];

                $logGrade = "Nota: $gradeVal";
                $rowClass = 'text-success';

             } catch (Exception $e) {
                 $status = $e->getMessage();
                 $rowClass = 'text-danger';
             }

             $table->data[] = [$row, $username, $courseShort, $logEnroll, $logGrade, new html_table_cell($status)];
             // Correctly access the last row (which is an array) and then the 6th element (index 5) which is the object
             $table->data[count($table->data)-1][5]->attributes = ['class' => $rowClass];
        }

        // Final Period Sync for processed users
        if (!empty($toSyncPeriods)) {
            foreach ($toSyncPeriods as $syncData) {
                \local_grupomakro_progress_manager::sync_student_period($syncData['userid'], $syncData['planid']);
            }
        }

        echo html_writer::table($table);

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
