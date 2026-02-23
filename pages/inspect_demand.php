<?php
define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../../config.php');

global $DB;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

echo "<html><body style='font-family:sans-serif; background:#f4f4f9; padding:20px;'>";
echo "<h1>Diagnóstico de Demanda - Periodo Institucional: 1</h1>";

// 1. Estudiantes por Plan
echo "<h2>1. Estudiantes Activos por Plan</h2>";
$sql = "SELECT lp.id, lp.name, COUNT(llu.id) as count 
        FROM {local_learning_plans} lp
        JOIN {local_learning_users} llu ON llu.learningplanid = lp.id
        WHERE llu.userrolename = 'student' AND llu.status = 'activo'
        GROUP BY lp.id, lp.name";
$counts = $DB->get_records_sql($sql);
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
echo "<tr style='background:#eee;'><th>ID Plan</th><th>Nombre del Plan</th><th>Estudiantes</th></tr>";
foreach ($counts as $c) {
    echo "<tr><td>$c->id</td><td>$c->name</td><td>$c->count</td></tr>";
}
echo "</table>";

// 2. Proyecciones
echo "<h2>2. Proyecciones Activas (gmk_academic_projections)</h2>";
$sql = "SELECT p.*, lp.name as plan_name, c.fullname as course_name 
        FROM {gmk_academic_projections} p
        LEFT JOIN {local_learning_plans} lp ON lp.id = p.learningplanid
        LEFT JOIN {course} c ON c.id = p.courseid
        WHERE p.academicperiodid = 1";
$projs = $DB->get_records_sql($sql);
if ($projs) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
    echo "<tr style='background:#eee;'><th>Plan</th><th>Course</th><th>Materia</th><th>Cantidad</th><th>Status</th></tr>";
    foreach ($projs as $p) {
        echo "<tr><td>[$p->learningplanid] $p->plan_name</td><td>$p->courseid</td><td>$p->course_name</td><td>$p->count</td><td>$p->status</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay proyecciones para este periodo.</p>";
}

// 3. Planificación Manual (Matriz)
echo "<h2>3. Planificación Manual (gmk_academic_planning)</h2>";
$sql = "SELECT ap.*, lp.name as plan_name, c.fullname as course_name 
        FROM {gmk_academic_planning} ap
        LEFT JOIN {local_learning_plans} lp ON lp.id = ap.learningplanid
        LEFT JOIN {course} c ON c.id = (SELECT courseid FROM {local_learning_courses} WHERE id = ap.courseid)
        WHERE ap.academicperiodid = 1";
$plannings = $DB->get_records_sql($sql);
if ($plannings) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
    echo "<tr style='background:#eee;'><th>Plan</th><th>SubjectID (LLC)</th><th>Materia</th><th>Estudiantes Proyectados</th></tr>";
    foreach ($plannings as $pp) {
        $color = ($pp->learningplanid == 13) ? "background:#ffcccc;" : "";
        echo "<tr style='$color'><td>[$pp->learningplanid] $pp->plan_name</td><td>$pp->courseid</td><td>$pp->course_name</td><td>$pp->projected_students</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay planificación manual para este periodo.</p>";
}

echo "</body></html>";
