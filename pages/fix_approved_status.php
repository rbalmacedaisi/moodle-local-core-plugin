<?php
require_once(__DIR__ . '/../../../config.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/fix_approved_status.php');
$PAGE->set_context($context);
$PAGE->set_title('Corregir Status de Materias Aprobadas');
$PAGE->set_heading('Corrección Masiva de Status');
$PAGE->set_pagelayout('admin');

$action = optional_param('action', '', PARAM_ALPHA);

echo $OUTPUT->header();
?>

<style>
    .fix-container { max-width: 1200px; margin: 20px auto; }
    .info-box { background: #e7f3ff; padding: 20px; border-left: 4px solid #007bff; margin-bottom: 20px; }
    .warning-box { background: #fff3cd; padding: 20px; border-left: 4px solid #ffc107; margin-bottom: 20px; }
    .success-box { background: #d4edda; padding: 20px; border-left: 4px solid #28a745; margin-bottom: 20px; }
    .error-box { background: #f8d7da; padding: 20px; border-left: 4px solid #dc3545; margin-bottom: 20px; }
    .stats-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    .stats-table th { background: #343a40; color: white; padding: 12px; text-align: left; }
    .stats-table td { padding: 10px; border-bottom: 1px solid #dee2e6; }
    .stats-table tr:hover { background: #f8f9fa; }
    .btn { padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; }
    .btn-primary { background: #007bff; color: white; }
    .btn-primary:hover { background: #0056b3; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-danger:hover { background: #c82333; }
    .code-block { background: #f4f4f4; padding: 15px; border-left: 3px solid #007bff; margin: 15px 0; font-family: monospace; font-size: 13px; overflow-x: auto; }
</style>

<div class="fix-container">
    <h1>🔧 Corrección de Status de Materias Aprobadas</h1>

    <div class="info-box">
        <h3>📋 Problema Detectado</h3>
        <p>Se ha identificado que algunas materias tienen:</p>
        <ul>
            <li><strong>Nota aprobatoria</strong> (≥ 71 puntos)</li>
            <li><strong>Status incorrecto</strong> (Status 1 "Disponible" o Status 3 "Completada" en lugar de Status 4 "Aprobada")</li>
        </ul>
        <p>Esto causa que el Motor de Proyección cuente incorrectamente a estos estudiantes en la demanda.</p>
    </div>

<?php

if ($action === '') {
    // Show statistics
    echo "<div class='warning-box'>";
    echo "<h3>⚠️ Análisis Previo</h3>";
    echo "<p>Antes de ejecutar la corrección, revise los registros que serán afectados:</p>";
    echo "</div>";

    // Query 1: Records with grade >= 71 but status != 4
    $sql1 = "SELECT COUNT(id) as count
             FROM {gmk_course_progre}
             WHERE grade >= 71 AND status != 4 AND status != 5";
    $count1 = $DB->count_records_sql($sql1);

    // Query 2: Records with grade < 71, progress = 100 but status != 5
    $sql2 = "SELECT COUNT(id) as count
             FROM {gmk_course_progre}
             WHERE grade > 0 AND grade < 71 AND progress >= 100 AND status != 5";
    $count2 = $DB->count_records_sql($sql2);

    // Query 3: Sample records that will be fixed
    $sampleSql = "SELECT gcp.id, gcp.userid, gcp.courseid, gcp.grade, gcp.progress, gcp.status,
                         u.firstname, u.lastname, c.fullname as coursename
                  FROM {gmk_course_progre} gcp
                  JOIN {user} u ON u.id = gcp.userid
                  JOIN {course} c ON c.id = gcp.courseid
                  WHERE gcp.grade >= 71 AND gcp.status != 4 AND gcp.status != 5
                  LIMIT 10";
    $sampleRecords = $DB->get_records_sql($sampleSql);

    echo "<table class='stats-table'>";
    echo "<thead><tr><th>Categoría</th><th>Cantidad</th><th>Acción</th></tr></thead>";
    echo "<tbody>";
    echo "<tr>";
    echo "<td><strong>Materias con nota ≥ 71 pero status ≠ 4 (Aprobada)</strong></td>";
    echo "<td><strong style='color: #dc3545; font-size: 18px;'>{$count1}</strong></td>";
    echo "<td>Se actualizará a Status 4 (APROBADA)</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td><strong>Materias con nota < 71, progreso 100% pero status ≠ 5 (Reprobada)</strong></td>";
    echo "<td><strong style='color: #dc3545; font-size: 18px;'>{$count2}</strong></td>";
    echo "<td>Se actualizará a Status 5 (REPROBADA)</td>";
    echo "</tr>";
    echo "</tbody>";
    echo "</table>";

    if ($count1 > 0) {
        echo "<h3>📄 Muestra de Registros a Corregir (primeros 10)</h3>";
        echo "<table class='stats-table'>";
        echo "<thead><tr><th>ID</th><th>Estudiante</th><th>Materia</th><th>Nota</th><th>Progreso</th><th>Status Actual</th></tr></thead>";
        echo "<tbody>";
        foreach ($sampleRecords as $rec) {
            $statusLabel = '';
            switch ($rec->status) {
                case 0: $statusLabel = '0: No Disponible'; break;
                case 1: $statusLabel = '1: Disponible'; break;
                case 2: $statusLabel = '2: En Curso'; break;
                case 3: $statusLabel = '3: Completada'; break;
                case 4: $statusLabel = '4: Aprobada'; break;
                case 5: $statusLabel = '5: Reprobada'; break;
                default: $statusLabel = $rec->status . ': Otro';
            }
            echo "<tr>";
            echo "<td>{$rec->id}</td>";
            echo "<td>{$rec->firstname} {$rec->lastname}</td>";
            echo "<td>{$rec->coursename}</td>";
            echo "<td><strong style='color: #28a745;'>{$rec->grade}</strong></td>";
            echo "<td>{$rec->progress}%</td>";
            echo "<td><span style='background: #ffc107; color: black; padding: 3px 8px; border-radius: 4px; font-size: 11px;'>{$statusLabel}</span></td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
    }

    echo "<div class='code-block'>";
    echo "<strong>Consultas SQL que se ejecutarán:</strong><br><br>";
    echo "-- 1. Actualizar a APROBADA (status 4) si nota >= 71<br>";
    echo "UPDATE {gmk_course_progre}<br>";
    echo "SET status = 4, progress = 100<br>";
    echo "WHERE grade >= 71 AND status != 4 AND status != 5;<br><br>";

    echo "-- 2. Actualizar a REPROBADA (status 5) si nota < 71 y progreso 100%<br>";
    echo "UPDATE {gmk_course_progre}<br>";
    echo "SET status = 5<br>";
    echo "WHERE grade > 0 AND grade < 71 AND progress >= 100 AND status != 5;";
    echo "</div>";

    if ($count1 > 0 || $count2 > 0) {
        echo "<div style='text-align: center; margin: 30px 0;'>";
        echo "<a href='?action=fix' class='btn btn-danger' onclick='return confirm(\"¿Está seguro de ejecutar la corrección masiva? Se actualizarán " . ($count1 + $count2) . " registros.\")'>🔧 EJECUTAR CORRECCIÓN MASIVA</a>";
        echo "</div>";
    } else {
        echo "<div class='success-box'>";
        echo "<h3>✅ No hay registros para corregir</h3>";
        echo "<p>Todos los registros tienen el status correcto.</p>";
        echo "</div>";
    }

} elseif ($action === 'fix') {
    // Execute the fix
    echo "<div class='info-box'>";
    echo "<h3>⚙️ Ejecutando Corrección...</h3>";
    echo "</div>";

    $fixed1 = 0;
    $fixed2 = 0;

    try {
        // Fix 1: Update to APPROVED (status 4) if grade >= 71
        $sql1 = "UPDATE {gmk_course_progre}
                 SET status = 4, progress = 100
                 WHERE grade >= 71 AND status != 4 AND status != 5";
        $DB->execute($sql1);
        $fixed1 = $DB->count_records_sql("SELECT COUNT(id) FROM {gmk_course_progre} WHERE grade >= 71 AND status = 4");

        // Fix 2: Update to FAILED (status 5) if grade < 71 and progress 100%
        $sql2 = "UPDATE {gmk_course_progre}
                 SET status = 5
                 WHERE grade > 0 AND grade < 71 AND progress >= 100 AND status != 5";
        $DB->execute($sql2);
        $fixed2 = $DB->count_records_sql("SELECT COUNT(id) FROM {gmk_course_progre} WHERE grade > 0 AND grade < 71 AND status = 5");

        echo "<div class='success-box'>";
        echo "<h3>✅ Corrección Completada</h3>";
        echo "<ul>";
        echo "<li><strong>{$fixed1}</strong> registros actualizados a Status 4 (APROBADA)</li>";
        echo "<li><strong>{$fixed2}</strong> registros actualizados a Status 5 (REPROBADA)</li>";
        echo "</ul>";
        echo "</div>";

        echo "<div class='info-box'>";
        echo "<h3>📌 Próximos Pasos</h3>";
        echo "<ol>";
        echo "<li>Verifique que la estudiante <strong>Anyinela Marielys Sanjur Ramos</strong> ahora tenga status 4 en sus materias aprobadas usando el <a href='debug_planning_student.php' target='_blank'>Debug de Estudiante</a></li>";
        echo "<li>Ejecute la <strong>Sincronización de Progreso</strong> completa para recalcular disponibilidades</li>";
        echo "<li>Recargue la página de <strong>Planificación Académica</strong> y verifique que ya no cuente estudiantes con materias aprobadas</li>";
        echo "</ol>";
        echo "</div>";

    } catch (Exception $e) {
        echo "<div class='error-box'>";
        echo "<h3>❌ Error al Ejecutar Corrección</h3>";
        echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
        echo "</div>";
    }

    echo "<div style='text-align: center; margin: 30px 0;'>";
    echo "<a href='?' class='btn btn-primary'>← Volver al Inicio</a>";
    echo "</div>";
}

?>

</div>

<?php
echo $OUTPUT->footer();
?>
