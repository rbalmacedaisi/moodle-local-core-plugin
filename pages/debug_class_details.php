<?php
require_once('../config.php');
global $DB;

$classid = optional_param('classid', 125, PARAM_INT);

echo "<h1>Detalles de Clase $classid</h1>";

// 1. gmk_class
$class = $DB->get_record('gmk_class', ['id' => $classid]);
echo "<h2>Tablar: gmk_class</h2>";
if ($class) {
    echo "<pre>" . print_r($class, true) . "</pre>";
} else {
    echo "<p>No se encontró en gmk_class.</p>";
}

// 2. gmk_class_schedules
$schedules = $DB->get_records('gmk_class_schedules', ['classid' => $classid]);
echo "<h2>Tabla: gmk_class_schedules (Sesiones)</h2>";
if ($schedules) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>
            <tr><th>Day</th><th>Start</th><th>End</th><th>Room ID</th></tr>";
    foreach ($schedules as $s) {
        echo "<tr><td>$s->day</td><td>$s->start_time</td><td>$s->end_time</td><td>$s->classroomid</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='text-danger'><b>¡OJO! No hay sesiones en gmk_class_schedules. Por esto no se ve en el tablero.</b></p>";
}

// 3. gmk_class_queue (Planned Students)
$queue = $DB->get_records('gmk_class_queue', ['classid' => $classid]);
echo "<h2>Tabla: gmk_class_queue (Estudiantes Planeados)</h2>";
echo "<p>Conteo: " . count($queue) . "</p>";

// 4. gmk_course_progre (Matriculated Students)
$progre = $DB->get_records('gmk_course_progre', ['classid' => $classid]);
echo "<h2>Tabla: gmk_course_progre (Estudiantes Matriculados)</h2>";
echo "<p>Conteo: " . count($progre) . "</p>";

echo "<hr>";
echo "<p>Use ?classid=XXX para ver otra clase.</p>";
