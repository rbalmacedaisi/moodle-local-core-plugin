<?php
define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../../config.php');

global $DB;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

echo "<html><body style='font-family:sans-serif; background:#f4f4f9; padding:20px;'>";
echo "<h1>Diagnóstico de Demanda FINAL - Hora Servidor: " . date('H:i:s') . "</h1>";
echo "<p style='color:blue;'>Versión: 3 (Sin Joins problemáticos)</p>";

// 1. Estudiantes por Plan
echo "<h2>1. Estudiantes Activos por Plan (Tabla: local_learning_users)</h2>";
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
echo "<h2>2. Proyecciones Manuales (Tabla: gmk_academic_projections)</h2>";
$sql = "SELECT p.* FROM {gmk_academic_projections} p WHERE p.academicperiodid = 1";
$projs = $DB->get_records_sql($sql);
if ($projs) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%; margin-top:10px;'>";
    echo "<tr style='background:#eee;'><th>ID</th><th>Nombre Carrera (Texto)</th><th>Jornada</th><th>Cantidad Inscritos</th></tr>";
    foreach ($projs as $p) {
        $color = (stripos($p->career, 'Acuicultura') !== false) ? "background:#ffcccc;" : "";
        echo "<tr style='$color'><td>$p->id</td><td>$p->career</td><td>$p->shift</td><td>$p->count</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay proyecciones manuales (nuevos ingresos) registradas.</p>";
}

// 3. Planificación Manual (Matriz)
echo "<h2>3. Planificación Manual / Matriz (Tabla: gmk_academic_planning)</h2>";
$sql = "SELECT ap.*, lp.name as plan_name 
        FROM {gmk_academic_planning} ap
        LEFT JOIN {local_learning_plans} lp ON lp.id = ap.learningplanid
        WHERE ap.academicperiodid = 1";
$plannings = $DB->get_records_sql($sql);
if ($plannings) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%; margin-top:10px;'>";
    echo "<tr style='background:#eee;'><th>ID</th><th>Plan</th><th>SubjectID (LLC)</th><th>Estudiantes</th></tr>";
    foreach ($plannings as $pp) {
        $color = ($pp->learningplanid == 13) ? "background:#ffcccc;" : "";
        echo "<tr style='$color'><td>$pp->id</td><td>[$pp->learningplanid] $pp->plan_name</td><td>$pp->courseid</td><td>$pp->projected_students</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay registros en la matriz de planificación manual.</p>";
}

echo "<hr><p>Si ves filas en <b>ROJO</b>, esos son los registros que están causando que el sistema asigne 'Acuicultura' a tus clases.</p>";
echo "</body></html>";
