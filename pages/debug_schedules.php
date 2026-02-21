<?php
// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../../config.php';
}
require_once($config_path);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_schedules.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Generación de Horarios');

echo $OUTPUT->header();

echo "<h2>Estado de las Tablas (Revisión de Guardado)</h2>";

global $DB;

$periods = $DB->get_records('gmk_academic_periods');
echo "<h3>Estructura de las Tablas</h3>";
echo "<h4>Columnas de gmk_class</h4>";
$class_cols = $DB->get_columns('gmk_class');
echo "<ul>";
foreach ($class_cols as $col) {
    echo "<li>{$col->name} ({$col->type})</li>";
}
echo "</ul>";

echo "<h4>Muestra de Usuarios (Tabla {user})</h4>";
$sample_users = $DB->get_records('user', [], 'id ASC', 'id, username, firstname, lastname', 0, 5);
echo "<ul>";
foreach ($sample_users as $u) {
    echo "<li>ID: <strong>{$u->id}</strong> | Username: {$u->username} | Nombre: {$u->firstname} {$u->lastname}</li>";
}
echo "</ul>";

echo "<h4>Columnas de gmk_class_schedules</h4>";
$sched_cols = $DB->get_columns('gmk_class_schedules');
echo "<ul>";
foreach ($sched_cols as $col) {
    echo "<li>{$col->name} ({$col->type})</li>";
}
echo "</ul>";

echo "<h3>Periodos Activos con Clases</h3>";
echo "<ul>";
foreach ($periods as $p) {
    $count = $DB->count_records('gmk_class', ['periodid' => $p->id]);
    $session_sql = "SELECT COUNT(*) FROM {gmk_class_schedules} s JOIN {gmk_class} c ON c.id = s.classid WHERE c.periodid = ?";
    $session_count = $DB->count_records_sql($session_sql, [$p->id]);
    echo "<li>Periodo {$p->id} ({$p->name}): <strong>{$count}</strong> clases y <strong>{$session_count}</strong> sesiones de horario.</li>";
}
echo "</ul>";

echo "<h3>Últimas 10 Clases Guardadas (Cualquier periodo)</h3>";
$latest_classes = $DB->get_records('gmk_class', [], 'id DESC', '*', 0, 10);
if ($latest_classes) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Periodo</th><th>Curso</th><th>Nombre</th><th>Docente</th><th>Jornada</th><th>Nivel</th><th>Alumnos</th><th>Modificado</th></tr>";
    foreach ($latest_classes as $c) {
        $dates = date('Y-m-d H:i:s', $c->timemodified);
        $student_count = $DB->count_records('gmk_class_queue', ['classid' => $c->id]);
        echo "<tr>";
        echo "<td>{$c->id}</td>";
        echo "<td>{$c->periodid}</td>";
        echo "<td>{$c->courseid}</td>";
        echo "<td>{$c->name}</td>";
        echo "<td>{$c->instructorid}</td>";
        echo "<td>" . ($c->shift ?? '-') . "</td>";
        echo "<td>" . ($c->level_label ?? '-') . "</td>";
        echo "<td><strong>{$student_count}</strong></td>";
        echo "<td>{$dates}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay clases en gmk_class.</p>";
}

echo "<h3>Últimos 10 Horarios Asociados (Días/Horas)</h3>";
$latest_schedules = $DB->get_records('gmk_class_schedules', [], 'id DESC', '*', 0, 10);
if ($latest_schedules) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Class ID</th><th>Día</th><th>Inicio</th><th>Fin</th><th>Aula</th></tr>";
    foreach ($latest_schedules as $s) {
        echo "<tr>";
        echo "<td>{$s->id}</td>";
        echo "<td>{$s->classid}</td>";
        echo "<td>{$s->day}</td>";
        echo "<td>{$s->start_time}</td>";
        echo "<td>{$s->end_time}</td>";
        echo "<td>{$s->classroomid}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay vínculos en gmk_class_schedules.</p>";
}

echo $OUTPUT->footer();
