<?php
require_once(__DIR__ . '/../../../config.php');

global $DB, $PAGE, $OUTPUT, $CFG, $USER;

$context = context_system::instance();
require_login();

$PAGE->set_url('/local/grupomakro_core/pages/debug_teacher_dashboard.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Teacher Dashboard');
$PAGE->set_heading('Debug: Teacher Dashboard');
$PAGE->set_pagelayout('admin');

$teacherid = optional_param('teacherid', $USER->id, PARAM_INT);

echo $OUTPUT->header();
?>

<style>
    .debug-container { max-width: 1600px; margin: 20px auto; font-family: monospace; }
    .section { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #007bff; }
    .info-box { background: #e7f3ff; padding: 15px; border-left: 4px solid #007bff; margin: 15px 0; }
    .warning-box { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; }
    .error-box { background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 15px 0; }
    .success-box { background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 15px 0; }
    .code-block { background: #f4f4f4; padding: 12px; border-left: 3px solid #007bff; margin: 10px 0; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
    .table-debug { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 12px; }
    .table-debug th { background: #343a40; color: white; padding: 10px; text-align: left; }
    .table-debug td { padding: 8px; border-bottom: 1px solid #dee2e6; }
    .table-debug tr:hover { background: #f8f9fa; }
    h2 { color: #007bff; margin-top: 30px; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
    h3 { color: #495057; margin-top: 20px; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; margin: 2px; display: inline-block; }
    .badge-success { background: #28a745; color: white; }
    .badge-danger { background: #dc3545; color: white; }
    .badge-warning { background: #ffc107; color: black; }
    .badge-info { background: #17a2b8; color: white; }
</style>

<div class="debug-container">
    <h1>🔍 Debug: Teacher Dashboard</h1>

    <div class="info-box">
        <strong>Teacher ID:</strong> <?php echo $teacherid; ?><br>
        <?php
        $teacher = $DB->get_record('user', ['id' => $teacherid], 'id, firstname, lastname, email');
        if ($teacher) {
            echo "<strong>Nombre:</strong> {$teacher->firstname} {$teacher->lastname}<br>";
            echo "<strong>Email:</strong> {$teacher->email}";
        }
        ?>
    </div>

<?php

$now = time();
$is_admin = is_siteadmin($teacherid);

// ============================================================================
// STEP 1: All Classes Assigned to Teacher
// ============================================================================
echo "<h2>📚 PASO 1: Clases Asignadas al Docente</h2>";

$where_instructor = $is_admin ? "" : " AND instructorid = :instructorid";
$sql_all = "SELECT * FROM {gmk_class} WHERE 1=1 $where_instructor ORDER BY id DESC";
$params_all = $is_admin ? [] : ['instructorid' => $teacherid];
$all_classes = $DB->get_records_sql($sql_all, $params_all);

if (empty($all_classes)) {
    echo "<div class='warning-box'>";
    echo "<h3>⚠️ No hay clases asignadas a este docente</h3>";
    echo "<p>No se encontraron registros en gmk_class con instructorid = {$teacherid}</p>";
    echo "</div>";
} else {
    echo "<div class='success-box'>";
    echo "<p>✅ Se encontraron <strong>" . count($all_classes) . "</strong> clases asignadas</p>";
    echo "</div>";

    echo "<table class='table-debug'>";
    echo "<thead><tr><th>Class ID</th><th>Name</th><th>Course ID</th><th>Group ID</th><th>Closed</th><th>End Date</th><th>Issues</th></tr></thead>";
    echo "<tbody>";
    foreach ($all_classes as $class) {
        $closed_badge = $class->closed ? "<span class='badge badge-danger'>Cerrado</span>" : "<span class='badge badge-success'>Abierto</span>";

        $issues = [];
        if ($class->closed == 1) {
            $issues[] = "Closed=1";
        }
        if ($class->enddate < $now) {
            $issues[] = "Expired (" . date('Y-m-d', $class->enddate) . ")";
        }
        if (empty($class->groupid)) {
            $issues[] = "No Group";
        }

        $issues_text = !empty($issues) ? "<span class='badge badge-warning'>" . implode(', ', $issues) . "</span>" : "✅";

        echo "<tr>";
        echo "<td>{$class->id}</td>";
        echo "<td>{$class->name}</td>";
        echo "<td>{$class->courseid}</td>";
        echo "<td>" . ($class->groupid ?: 'NULL') . "</td>";
        echo "<td>{$closed_badge}</td>";
        echo "<td>" . date('Y-m-d H:i', $class->enddate) . "</td>";
        echo "<td>{$issues_text}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

// ============================================================================
// STEP 2: Check gmk_bbb_attendance_relation
// ============================================================================
echo "<h2>🔗 PASO 2: Verificar Relación con Asistencia (gmk_bbb_attendance_relation)</h2>";

echo "<div class='info-box'>";
echo "<p><strong>Condición Crítica:</strong> El Teacher Dashboard solo muestra clases que tienen registros en la tabla <code>gmk_bbb_attendance_relation</code></p>";
echo "<p>Esta tabla vincula las clases con las sesiones de asistencia de BigBlueButton.</p>";
echo "</div>";

if (!empty($all_classes)) {
    $class_ids = array_keys($all_classes);
    list($in_sql, $in_params) = $DB->get_in_or_equal($class_ids, SQL_PARAMS_NAMED);

    $sql_rel = "SELECT * FROM {gmk_bbb_attendance_relation} WHERE classid $in_sql";
    $relations = $DB->get_records_sql($sql_rel, $in_params);

    echo "<h3>Clases con Relación de Asistencia</h3>";
    if (empty($relations)) {
        echo "<div class='error-box'>";
        echo "<h3>❌ PROBLEMA ENCONTRADO: No hay relaciones de asistencia</h3>";
        echo "<p>Ninguna de las clases del docente tiene registros en <code>gmk_bbb_attendance_relation</code></p>";
        echo "<p><strong>Esto explica por qué no aparecen en el Teacher Dashboard</strong></p>";
        echo "</div>";
    } else {
        echo "<div class='success-box'>";
        echo "<p>✅ Se encontraron <strong>" . count($relations) . "</strong> relaciones</p>";
        echo "</div>";

        echo "<table class='table-debug'>";
        echo "<thead><tr><th>Relation ID</th><th>Class ID</th><th>Class Name</th><th>Attendance Session ID</th><th>BBB Activity ID</th></tr></thead>";
        echo "<tbody>";
        foreach ($relations as $rel) {
            $class_name = isset($all_classes[$rel->classid]) ? $all_classes[$rel->classid]->name : 'Unknown';
            echo "<tr>";
            echo "<td>{$rel->id}</td>";
            echo "<td>{$rel->classid}</td>";
            echo "<td>{$class_name}</td>";
            echo "<td>" . ($rel->attendancesessionid ?: 'NULL') . "</td>";
            echo "<td>" . ($rel->bbbactivityid ?: 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

    // Show classes WITHOUT relation
    $classes_without_relation = [];
    foreach ($all_classes as $class) {
        $has_relation = false;
        foreach ($relations as $rel) {
            if ($rel->classid == $class->id) {
                $has_relation = true;
                break;
            }
        }
        if (!$has_relation) {
            $classes_without_relation[] = $class;
        }
    }

    if (!empty($classes_without_relation)) {
        echo "<h3>❌ Clases SIN Relación de Asistencia (No aparecerán en el dashboard)</h3>";
        echo "<table class='table-debug'>";
        echo "<thead><tr><th>Class ID</th><th>Name</th><th>Course ID</th><th>Closed</th><th>End Date</th></tr></thead>";
        echo "<tbody>";
        foreach ($classes_without_relation as $class) {
            $closed_badge = $class->closed ? "<span class='badge badge-danger'>Cerrado</span>" : "<span class='badge badge-success'>Abierto</span>";
            echo "<tr>";
            echo "<td>{$class->id}</td>";
            echo "<td>{$class->name}</td>";
            echo "<td>{$class->courseid}</td>";
            echo "<td>{$closed_badge}</td>";
            echo "<td>" . date('Y-m-d H:i', $class->enddate) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
}

// ============================================================================
// STEP 3: Simulate Web Service Query
// ============================================================================
echo "<h2>🔌 PASO 3: Simular Consulta del Web Service</h2>";

$where_instructor = $is_admin ? "" : " AND c.instructorid = :instructorid";
$sql = "SELECT c.*
        FROM {gmk_class} c
        WHERE c.closed = 0
          AND c.enddate >= :now
          $where_instructor
          AND EXISTS (
              SELECT 1 FROM {gmk_bbb_attendance_relation} r
              WHERE r.classid = c.id
          )";

$query_params = ['now' => $now];
if (!$is_admin) {
    $query_params['instructorid'] = $teacherid;
}

echo "<div class='code-block'>";
echo "SQL Query:\n";
echo $sql . "\n\n";
echo "Params:\n";
print_r($query_params);
echo "</div>";

$dashboard_classes = $DB->get_records_sql($sql, $query_params);

if (empty($dashboard_classes)) {
    echo "<div class='error-box'>";
    echo "<h3>❌ La consulta retornó 0 clases</h3>";
    echo "<p>Esto es exactamente lo que ve el Teacher Dashboard</p>";
    echo "</div>";
} else {
    echo "<div class='success-box'>";
    echo "<h3>✅ La consulta retornó " . count($dashboard_classes) . " clase(s)</h3>";
    echo "</div>";

    echo "<table class='table-debug'>";
    echo "<thead><tr><th>Class ID</th><th>Name</th><th>Course ID</th><th>Group ID</th></tr></thead>";
    echo "<tbody>";
    foreach ($dashboard_classes as $class) {
        echo "<tr>";
        echo "<td>{$class->id}</td>";
        echo "<td>{$class->name}</td>";
        echo "<td>{$class->courseid}</td>";
        echo "<td>" . ($class->groupid ?: 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

// ============================================================================
// STEP 4: Recommendations
// ============================================================================
echo "<h2>💡 PASO 4: Diagnóstico y Soluciones</h2>";

echo "<div class='info-box'>";
echo "<h3>Condiciones para que una clase aparezca en el Teacher Dashboard:</h3>";
echo "<ol>";
echo "<li>✅ <code>closed = 0</code> (La clase debe estar abierta)</li>";
echo "<li>✅ <code>enddate >= NOW()</code> (La clase no debe haber expirado)</li>";
echo "<li>✅ <code>instructorid = {$teacherid}</code> (Debe estar asignada al docente)</li>";
echo "<li>❗ <strong>DEBE existir un registro en gmk_bbb_attendance_relation</strong></li>";
echo "</ol>";

echo "<h3>¿Cómo se crea la relación gmk_bbb_attendance_relation?</h3>";
echo "<p>Esta relación se crea cuando:</p>";
echo "<ul>";
echo "<li>Se configuran sesiones de asistencia con BigBlueButton para la clase</li>";
echo "<li>Se vincula el módulo de asistencia (attendance) con la clase</li>";
echo "<li>Se crean actividades de BBB asociadas a la clase</li>";
echo "</ul>";

if (!empty($classes_without_relation)) {
    echo "<h3>🔧 Solución para las clases sin relación:</h3>";
    echo "<ol>";
    echo "<li>Verifica que cada clase tenga configurado el módulo de asistencia</li>";
    echo "<li>Asegúrate de que las sesiones de BigBlueButton estén vinculadas</li>";
    echo "<li>O bien, modifica la consulta en <code>get_dashboard_data.php</code> para no requerir esta relación (si no es necesaria)</li>";
    echo "</ol>";

    echo "<h4>Clases que necesitan configuración:</h4>";
    echo "<ul>";
    foreach ($classes_without_relation as $class) {
        echo "<li><strong>{$class->name}</strong> (ID: {$class->id}, Course ID: {$class->courseid})</li>";
    }
    echo "</ul>";
}

echo "</div>";

?>

<div class="section">
    <h3>🔗 Enlaces Útiles</h3>
    <ul>
        <li><a href="teacher_dashboard.php">← Volver al Teacher Dashboard</a></li>
        <li><a href="?teacherid=<?php echo $teacherid; ?>">🔄 Recargar Debug</a></li>
    </ul>
</div>

</div>

<?php
echo $OUTPUT->footer();
?>
