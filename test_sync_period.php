<?php

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

global $DB;

// Find a student enrolled in a learning plan
$sql = "SELECT lpu.userid, lpu.learningplanid, u.firstname, u.lastname, lp.name as planname, lpu.currentperiodid
        FROM {local_learning_users} lpu
        JOIN {user} u ON (u.id = lpu.userid)
        JOIN {local_learning_plans} lp ON (lp.id = lpu.learningplanid)
        WHERE lpu.userroleid = 5
        LIMIT 1";

$student = $DB->get_record_sql($sql);

if (!$student) {
    echo "No se encontró ningún estudiante en un plan de aprendizaje.\n";
    exit;
}

echo "Probando sincronización para:\n";
echo "Estudiante: $student->firstname $student->lastname (ID: $student->userid)\n";
echo "Plan: $student->planname (ID: $student->learningplanid)\n";
echo "Periodo Actual (DB): $student->currentperiodid\n\n";

// Run sync
$res = \local_grupomakro_progress_manager::sync_student_period($student->userid, $student->learningplanid);

if ($res) {
    $updated = $DB->get_record('local_learning_users', ['userid' => $student->userid, 'learningplanid' => $student->learningplanid], 'currentperiodid');
    echo "Sincronización terminada.\n";
    echo "Nuevo Periodo Actual: " . $updated->currentperiodid . "\n";
    if ($updated->currentperiodid != $student->currentperiodid) {
        echo "¡EL PERIODO FUE ACTUALIZADO CORRECTAMENTE!\n";
    } else {
        echo "El periodo no cambió (ya estaba sincronizado o no hay progreso suficiente).\n";
    }
} else {
    echo "La sincronización falló o no se encontraron materias.\n";
}
