<?php
define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../../config.php');

global $DB;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$target_plan = 13; // Acuicultura
$period_id = 1;

if ($action == 'purge' && confirm_sesskey()) {
    $DB->delete_records('gmk_academic_planning', ['learningplanid' => $target_plan, 'academicperiodid' => $period_id]);
    redirect(new moodle_url('/local/grupomakro_core/pages/inspect_demand.php'), 'Todos los registros para Plan 13 en el periodo 1 han sido eliminados.', 3);
}

echo "<html><body style='font-family:sans-serif; background:#f4f4f9; padding:20px;'>";
echo "<h1>Inspección de Demanda v5 - Hora: " . date('H:i:s') . "</h1>";

// 1. Resumen Estudiantes Reales
echo "<h2>1. Estudiantes Reales Matriculados (local_learning_users)</h2>";
$sql = "SELECT lp.id, lp.name, COUNT(llu.id) as total 
        FROM {local_learning_plans} lp
        JOIN {local_learning_users} llu ON llu.learningplanid = lp.id
        WHERE llu.userrolename = 'student' AND llu.status = 'activo'
        GROUP BY lp.id, lp.name";
$counts = $DB->get_records_sql($sql);
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
echo "<tr style='background:#eee;'><th>ID Plan</th><th>Nombre del Plan</th><th>Alumnos Activos</th></tr>";
foreach ($counts as $c) {
    echo "<tr><td>$c->id</td><td>$c->name</td><td>$c->total</td></tr>";
}
echo "</table>";

// 2. Planificación Manual (Matriz) Diferenciada
echo "<h2>2. Planificación Manual / Matriz (gmk_academic_planning)</h2>";
$sql = "SELECT ap.*, lp.name as plan_name, c.fullname as course_name 
        FROM {gmk_academic_planning} ap
        LEFT JOIN {local_learning_plans} lp ON lp.id = ap.learningplanid
        LEFT JOIN {local_learning_courses} llc ON llc.id = ap.courseid
        LEFT JOIN {course} c ON c.id = llc.courseid
        WHERE ap.academicperiodid = $period_id
        ORDER BY ap.projected_students DESC, ap.learningplanid ASC";
$plannings = $DB->get_records_sql($sql);

if ($plannings) {
    $acu_count = 0;
    foreach($plannings as $p) if($p->learningplanid == 13) $acu_count++;

    if ($acu_count > 0) {
        $purge_url = new moodle_url('/local/grupomakro_core/pages/inspect_demand.php', ['action' => 'purge', 'sesskey' => sesskey()]);
        echo "<div style='background:#fee; padding:15px; border:1px solid red; margin-bottom:10px;'>";
        echo "Hay $acu_count registros de Acuicultura. ";
        echo " <a href='$purge_url' style='background:red; color:white; padding:5px 10px; text-decoration:none; border-radius:3px;'>ELIMINAR TODO ACUICULTURA EN ESTE PERIODO</a>";
        echo "</div>";
    }

    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
    echo "<tr style='background:#eee;'><th>ID</th><th>Plan</th><th>Materia</th><th>Cant. Planificada</th><th>Status</th></tr>";
    foreach ($plannings as $pp) {
        $color = ($pp->learningplanid == 13) ? "background:#ffcccc;" : "";
        if ($pp->projected_students > 0) $color = "background:#ccffcc;";
        echo "<tr style='$color'><td>$pp->id</td><td>[$pp->learningplanid] $pp->plan_name</td><td>$pp->course_name</td><td><b>$pp->projected_students</b></td><td>$pp->status</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay registros de planificación.</p>";
}

// 3. Estudiantes por Materia Especifica
echo "<h2>3. Detalle de Estudiantes por Materia (TOP 50 Materias con más alumnos)</h2>";
$sql = "SELECT c.id as moodle_id, c.fullname, lp.id as plan_id, lp.name as plan_name, COUNT(llu.id) as student_count
        FROM {course} c
        JOIN {local_learning_courses} llc ON llc.courseid = c.id
        JOIN {local_learning_plans} lp ON lp.id = llc.learningplanid
        JOIN {local_learning_users} llu ON llu.learningplanid = lp.id
        WHERE llu.userrolename = 'student' AND llu.status = 'activo'
        GROUP BY c.id, c.fullname, lp.id, lp.name
        ORDER BY student_count DESC
        LIMIT 50";
$course_stats = $DB->get_records_sql($sql);
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
echo "<tr style='background:#eee;'><th>Materia Moodle</th><th>Plan con Alumnos</th><th>Cant. Alumnos</th></tr>";
foreach ($course_stats as $cs) {
    echo "<tr><td>$cs->fullname (ID: $cs->moodle_id)</td><td>[$cs->plan_id] $cs->plan_name</td><td><b>$cs->student_count</b></td></tr>";
}
echo "</table>";

echo "</body></html>";
