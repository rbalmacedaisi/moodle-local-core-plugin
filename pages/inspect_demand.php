<?php
define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../../config.php');

global $DB;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$target_plan = 13; // Acuicultura

if ($action == 'purge' && confirm_sesskey()) {
    $DB->delete_records('gmk_academic_planning', ['learningplanid' => $target_plan, 'projected_students' => 0]);
    redirect(new moodle_url('/local/grupomakro_core/pages/inspect_demand.php'), 'Registros de planificación con 0 alumnos para Plan 13 eliminados.', 3);
}

echo "<html><body style='font-family:sans-serif; background:#f4f4f9; padding:20px;'>";
echo "<h1>Diagnóstico de Demanda v4 (Con Limpieza)</h1>";

// 1. Estudiantes por Plan
echo "<h2>1. Estudiantes Activos por Plan</h2>";
$sql = "SELECT lp.id, lp.name, COUNT(llu.id) as total 
        FROM {local_learning_plans} lp
        JOIN {local_learning_users} llu ON llu.learningplanid = lp.id
        WHERE llu.userrolename = 'student' AND llu.status = 'activo'
        GROUP BY lp.id, lp.name";
$counts = $DB->get_records_sql($sql);
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
echo "<tr style='background:#eee;'><th>ID Plan</th><th>Nombre del Plan</th><th>Estudiantes Reales</th></tr>";
foreach ($counts as $c) {
    echo "<tr><td>$c->id</td><td>$c->name</td><td>$c->total</td></tr>";
}
echo "</table>";

// 2. Proyecciones
echo "<h2>2. Proyecciones Manuales</h2>";
$sql = "SELECT p.* FROM {gmk_academic_projections} p WHERE p.academicperiodid = 1";
$projs = $DB->get_records_sql($sql);
if ($projs) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%; margin-top:10px;'>";
    echo "<tr><th>ID</th><th>Carrera</th><th>Shift</th><th>Count</th></tr>";
    foreach ($projs as $p) {
        echo "<tr><td>$p->id</td><td>$p->career</td><td>$p->shift</td><td>$p->count</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay proyecciones.</p>";
}

// 3. Planificación Manual (Matriz)
echo "<h2>3. Planificación Manual (Muestra de todos los registros)</h2>";
$sql = "SELECT ap.*, lp.name as plan_name, c.fullname as course_name 
        FROM {gmk_academic_planning} ap
        LEFT JOIN {local_learning_plans} lp ON lp.id = ap.learningplanid
        LEFT JOIN {local_learning_courses} llc ON llc.id = ap.courseid
        LEFT JOIN {course} c ON c.id = llc.courseid
        WHERE ap.academicperiodid = 1
        ORDER BY ap.learningplanid ASC";
$plannings = $DB->get_records_sql($sql);

if ($plannings) {
    $acu_count = 0;
    foreach($plannings as $p) if($p->learningplanid == 13) $acu_count++;
    
    echo "<p>Total registros: " . count($plannings) . " (Acuicultura: $acu_count)</p>";
    
    if ($acu_count > 0) {
        $purge_url = new moodle_url('/local/grupomakro_core/pages/inspect_demand.php', ['action' => 'purge', 'sesskey' => sesskey()]);
        echo "<div style='background:#fee; padding:15px; border:1px solid red; margin-bottom:10px;'>";
        echo "Hay $acu_count registros de Acuicultura en la planificación manual.";
        echo " <a href='$purge_url' style='background:red; color:white; padding:5px 10px; text-decoration:none; border-radius:3px;'>Eliminar registros de Acuicultura con 0 alumnos</a>";
        echo "</div>";
    }

    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
    echo "<tr style='background:#eee;'><th>ID</th><th>Plan</th><th>Materia</th><th>Estudiantes</th></tr>";
    foreach ($plannings as $pp) {
        $color = ($pp->learningplanid == 13) ? "background:#ffcccc;" : "";
        echo "<tr style='$color'><td>$pp->id</td><td>[$pp->learningplanid] $pp->plan_name</td><td>$pp->course_name</td><td>$pp->projected_students</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay planificación manual.</p>";
}

echo "</body></html>";
