<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/test_period_sync.php'));
$PAGE->set_context($context);
$PAGE->set_title('Test Period Sync');
$PAGE->set_heading('Test Period Sync');

echo $OUTPUT->header();

global $DB;

// Find a student enrolled in a learning plan
$sql = "SELECT lpu.userid, lpu.learningplanid, u.firstname, u.lastname, lp.name as planname, lpu.currentperiodid
        FROM {local_learning_users} lpu
        JOIN {user} u ON (u.id = lpu.userid)
        JOIN {local_learning_plans} lp ON (lp.id = lpu.learningplanid)
        WHERE lpu.userroleid = 5
        ORDER BY lpu.id DESC
        LIMIT 5";

$students = $DB->get_records_sql($sql);

if (!$students) {
    echo $OUTPUT->notification('No se encontró ningún estudiante en un plan de aprendizaje.', 'notifyproblem');
} else {
    echo "<h3>Resultados de Sincronización</h3>";
    foreach ($students as $student) {
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin-bottom: 20px;'>";
        echo "<strong>Estudiante:</strong> $student->firstname $student->lastname (ID: $student->userid)<br>";
        echo "<strong>Plan:</strong> $student->planname (ID: $student->learningplanid)<br>";
        echo "<strong>Periodo Inicial:</strong> $student->currentperiodid<br>";

        // Run sync
        $res = \local_grupomakro_progress_manager::sync_student_period($student->userid, $student->learningplanid);

        if ($res) {
            $updated = $DB->get_record('local_learning_users', ['userid' => $student->userid, 'learningplanid' => $student->learningplanid], 'currentperiodid');
            echo "<strong>Nuevo Periodo Actual:</strong> " . $updated->currentperiodid . "<br>";
            if ($updated->currentperiodid != $student->currentperiodid) {
                echo "<span style='color: green; font-weight: bold;'>¡EL PERIODO FUE ACTUALIZADO CORRECTAMENTE!</span><br>";
            } else {
                echo "El periodo no cambió (ya estaba sincronizado o no hay progreso suficiente).<br>";
            }
        } else {
            echo "<span style='color: red;'>La sincronización falló o no se encontraron materias obligatorias.</span><br>";
        }
        echo "</div>";
    }
}

echo $OUTPUT->footer();
