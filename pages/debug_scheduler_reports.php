<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

error_reporting(E_ALL);
ini_set('display_errors', 1);

$periodid = optional_param('periodid', 0, PARAM_INT);
if (!$periodid) {
    $period = $DB->get_record('gmk_academic_periods', ['status' => 1], '*', IGNORE_MULTIPLE);
    if ($period) $periodid = $period->id;
}

echo "<h1>Diagnóstico de Reportes de Planificación</h1>";
echo "<p>Periodo ID: $periodid</p>";

if (!$periodid) {
    echo "<p style='color:red;'>No se encontró un periodo activo. Especifique ?periodid=X</p>";
    exit;
}

// Check specific student
$check_id = '2025-IV-00000068';
$match = $DB->get_record_sql("SELECT id, idnumber, username FROM {user} WHERE idnumber = ? OR username = ?", [$check_id, $check_id]);
if ($match) {
    echo "<p style='background:yellow; padding:10px;'><b>Búsqueda Directa:</b> El ID '$check_id' coincide con el usuario Moodle ID: {$match->id} (Field: " . ($match->idnumber == $check_id ? 'idnumber' : 'username') . ")</p>";
} else {
    echo "<p style='background:orange; padding:10px;'><b>Búsqueda Directa:</b> No se encontró coincidencia para '$check_id' en idnumber o username.</p>";
}

// 1. Obtener datos de demanda (Lista global de estudiantes)
$demand = \local_grupomakro_core\external\admin\scheduler::get_demand_data($periodid);
$all_students = $demand['student_list'];
$student_map = [];
foreach ($all_students as $st) {
    $student_map[(string)$st['id']] = $st['name'];
}

echo "<h2>1. Lista Global de Estudiantes (desde get_demand_data)</h2>";
echo "<p>Total estudiantes encontrados: " . count($all_students) . "</p>";
if (count($all_students) > 0) {
    echo "<ul>";
    for ($i = 0; $i < min(5, count($all_students)); $i++) {
        $st = $all_students[$i];
        $stu_info = $DB->get_record('user', ['id' => $st['id']], 'id, idnumber, username');
        $idnum = $stu_info ? $stu_info->idnumber : 'N/A';
        $uname = $stu_info ? $stu_info->username : 'N/A';
        echo "<li>Moodle ID: {$st['id']} - <b>IDNumber: {$idnum}</b> - Username: {$uname} - Nombre: {$st['name']}</li>";
    }
    echo "<li>...</li>";
    echo "</ul>";
} else {
    echo "<p style='color:orange;'>AVERTENCIA: La lista global de estudiantes está VACÍA.</p>";
}

// 2. Obtener horarios generados
$schedules = \local_grupomakro_core\external\admin\scheduler::get_generated_schedules($periodid);

echo "<h2>2. Horarios y Estudiantes Asignados (desde get_generated_schedules)</h2>";
echo "<p>Total clases encontradas: " . count($schedules) . "</p>";

$total_assigned_students = 0;
$mismatch_count = 0;
$mismatches = [];

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr style='background:#eee;'><th>Clase</th><th>Est. Count</th><th>IDs en studentIds</th><th>¿Coinciden con lista global?</th></tr>";

foreach ($schedules as $s) {
    $ids = $s['studentIds'];
    $count = $s['studentCount'];
    $total_assigned_students += count($ids);
    
    $check_results = [];
    foreach ($ids as $id) {
        if (isset($student_map[(string)$id])) {
            $check_results[] = "<span style='color:green;'>$id (Ok)</span>";
        } else {
            $check_results[] = "<span style='color:red;'>$id (Falta)</span>";
            $mismatch_count++;
            $mismatches[] = "Clase: {$s['subjectName']} - Estudiante ID: $id";
        }
    }
    
    echo "<tr>";
    echo "<td>{$s['subjectName']}</td>";
    echo "<td>$count</td>";
    echo "<td>" . implode(', ', $ids) . "</td>";
    echo "<td>" . (empty($check_results) ? "Sin alumnos" : implode(', ', array_slice($check_results, 0, 10))) . (count($check_results) > 10 ? '...' : '') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h2>3. Resultados del Análisis</h2>";
if ($mismatch_count > 0) {
    echo "<p style='color:red; font-weight:bold;'>ERROR DETECTADO: Hay $mismatch_count asignaciones que no existen en la lista de estudiantes global.</p>";
    echo "<h3>Muestras de Desajustes:</h3><ul>";
    foreach (array_slice($mismatches, 0, 10) as $m) {
        echo "<li>$m</li>";
    }
    echo "</ul>";
} else if (count($schedules) > 0 && $total_assigned_students > 0) {
    echo "<p style='color:green; font-weight:bold;'>Datos consistentes a nivel de servidor. Si el error persiste en el navegador, podría ser un problema de caché o de tipos de datos en el Javascript.</p>";
} else {
    echo "<p style='color:orange;'>No se encontraron asignaciones para analizar.</p>";
}

echo "<h3>Recomendaciones:</h3>";
echo "<ul>
    <li>Si 'Total estudiantes encontrados' es 0 en el punto 1, el problema está en la consulta SQL de get_demand_data que obtiene estudiantes activos.</li>
    <li>Si hay ID rojos en el punto 2, significa que hay alumnos en la cola de la clase (`gmk_class_queue`) que no fueron devueltos por la consulta de estudiantes activos del punto 1.</li>
    <li>Si todo sale verde pero el PDF falla, intente limpiar la caché de Moodle y verifique la consola del navegador (F12) para ver errores de JS.</li>
</ul>";
