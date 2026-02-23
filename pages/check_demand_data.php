<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');

global $DB;

echo "--- CONTEO DE ESTUDIANTES POR PLAN (local_learning_users) ---\n";
$sql = "SELECT lp.id, lp.name, COUNT(llu.id) as count 
        FROM {local_learning_plans} lp
        JOIN {local_learning_users} llu ON llu.learningplanid = lp.id
        WHERE llu.userrolename = 'student' AND llu.status = 'activo'
        GROUP BY lp.id, lp.name";
$counts = $DB->get_records_sql($sql);
foreach ($counts as $c) {
    echo "Plan $c->id: $c->name -> $c->count estudiantes\n";
}

echo "\n--- PROYECCIONES ACTIVAS (gmk_academic_projections) ---\n";
$projs = $DB->get_records('gmk_academic_projections', ['academicperiodid' => 1]);
foreach ($projs as $p) {
    $lp = $DB->get_record('local_learning_plans', ['id' => $p->learningplanid]);
    echo "Plan $p->learningplanid (" . ($lp ? $lp->name : 'N/A') . "): Course $p->courseid, Count $p->count\n";
}

echo "\n--- PLANIFICACIÓN MANUAL (gmk_academic_planning) ---\n";
$plannings = $DB->get_records('gmk_academic_planning', ['academicperiodid' => 1]);
foreach ($plannings as $pp) {
    $lp = $DB->get_record('local_learning_plans', ['id' => $pp->learningplanid]);
    echo "Plan $pp->learningplanid (" . ($lp ? $lp->name : 'N/A') . "): Subject $pp->courseid, Students $pp->projected_students\n";
}
