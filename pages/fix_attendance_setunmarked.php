<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/fix_attendance_setunmarked.php');
$PAGE->set_context($context);
$PAGE->set_title('Corregir setunmarked en Actividades de Asistencia');
$PAGE->set_heading('Corrección de Nota de Asistencia');
$PAGE->set_pagelayout('admin');

$action = optional_param('action', '', PARAM_ALPHA);

echo $OUTPUT->header();
?>

<style>
    .fix-container { max-width: 1100px; margin: 20px auto; }
    .info-box    { background: #e7f3ff; padding: 20px; border-left: 4px solid #007bff; margin-bottom: 20px; }
    .warning-box { background: #fff3cd; padding: 20px; border-left: 4px solid #ffc107; margin-bottom: 20px; }
    .success-box { background: #d4edda; padding: 20px; border-left: 4px solid #28a745; margin-bottom: 20px; }
    .error-box   { background: #f8d7da; padding: 20px; border-left: 4px solid #dc3545; margin-bottom: 20px; }
    .stats-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    .stats-table th { background: #343a40; color: white; padding: 10px 12px; text-align: left; }
    .stats-table td { padding: 9px 12px; border-bottom: 1px solid #dee2e6; }
    .stats-table tr:hover { background: #f8f9fa; }
    .btn { padding: 10px 22px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; }
    .btn-primary { background: #007bff; color: white; }
    .btn-primary:hover { background: #0056b3; }
    .btn-danger  { background: #dc3545; color: white; }
    .btn-danger:hover  { background: #c82333; }
    .badge-ok  { background: #28a745; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    .badge-bad { background: #dc3545; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    .code-block { background: #f4f4f4; padding: 15px; border-left: 3px solid #007bff; margin: 15px 0; font-family: monospace; font-size: 13px; overflow-x: auto; }
</style>

<div class="fix-container">
<h1>Corrección de <em>setunmarked</em> en Actividades de Asistencia</h1>

<div class="info-box">
    <h3>Problema</h3>
    <p>
        El módulo <code>mod_attendance</code> usa la tarea cron <strong>auto_mark</strong> para marcar como
        ausentes a los estudiantes que no registraron asistencia cuando una sesión se cierra
        (<code>ATTENDANCE_AUTOMARK_CLOSE = 2</code>).
        Para que esto funcione, <strong>exactamente un</strong> estado (<em>status</em>) de cada actividad de
        asistencia debe tener <code>setunmarked = 1</code>; ese estado es el que se asigna automáticamente a
        los ausentes.
    </p>
    <p>
        Si ningún estado tiene <code>setunmarked = 1</code>, el cron no hace nada y todos los estudiantes que
        no escanearon QR quedan sin registro — lo que hace que Moodle calcule su nota de asistencia como
        <strong>100 %</strong> (denominator = 0 sesiones tomadas).
    </p>
    <p>
        Esta herramienta detecta las actividades de asistencia con ese error y configura correctamente el
        estado de menor nota (ausente) como <code>setunmarked = 1</code>.
    </p>
</div>

<?php

// ── Análisis ─────────────────────────────────────────────────────────────────
$sql_bad = "
    SELECT a.id AS attid, a.name AS attname, c.fullname AS coursename,
           COUNT(s.id) AS status_count,
           SUM(CASE WHEN s.setunmarked = 1 THEN 1 ELSE 0 END) AS has_setunmarked
      FROM {attendance} a
      JOIN {course} c ON c.id = a.course
      LEFT JOIN {attendance_statuses} s
             ON s.attendanceid = a.id AND s.deleted = 0 AND s.setnumber = 0
     GROUP BY a.id, a.name, c.fullname
    HAVING has_setunmarked = 0
     ORDER BY c.fullname, a.name
";

$badAttendances = $DB->get_records_sql($sql_bad);
$totalBad       = count($badAttendances);
$totalAll       = $DB->count_records('attendance');

if ($action === '') {

    echo "<div class='warning-box'>";
    echo "<h3>Diagnóstico</h3>";
    echo "<p>Total de actividades de asistencia en el sistema: <strong>{$totalAll}</strong></p>";
    echo "<p>Actividades <em>sin</em> estado <code>setunmarked = 1</code>: <strong style='color:#dc3545;font-size:18px;'>{$totalBad}</strong></p>";
    echo "</div>";

    if ($totalBad > 0) {
        echo "<h3>Actividades afectadas (primeras 50)</h3>";
        echo "<table class='stats-table'>";
        echo "<thead><tr>
                <th>ID</th>
                <th>Actividad</th>
                <th>Curso</th>
                <th>Estados registrados</th>
                <th>setunmarked</th>
              </tr></thead><tbody>";

        $shown = 0;
        foreach ($badAttendances as $row) {
            if ($shown >= 50) break;
            echo "<tr>";
            echo "<td>{$row->attid}</td>";
            echo "<td>" . htmlspecialchars($row->attname) . "</td>";
            echo "<td>" . htmlspecialchars($row->coursename) . "</td>";
            echo "<td>{$row->status_count}</td>";
            echo "<td><span class='badge-bad'>Ninguno</span></td>";
            echo "</tr>";
            $shown++;
        }
        echo "</tbody></table>";

        if ($totalBad > 50) {
            echo "<p><em>... y " . ($totalBad - 50) . " más (se corregirán todas).</em></p>";
        }

        echo "<div class='code-block'>";
        echo "<strong>Acción que se ejecutará por cada actividad afectada:</strong><br><br>";
        echo "1. Obtener todos los estados (attendance_statuses) de la actividad.<br>";
        echo "2. Identificar el estado con la nota más baja (ausente).<br>";
        echo "3. Poner setunmarked = 0 en todos los estados de la actividad.<br>";
        echo "4. Poner setunmarked = 1 en el estado ausente identificado.<br>";
        echo "</div>";

        echo "<div style='text-align:center;margin:30px 0;'>";
        echo "<a href='?action=fix' class='btn btn-danger'"
             . " onclick='return confirm(\"¿Ejecutar la corrección en {$totalBad} actividades de asistencia?\")'>Ejecutar corrección</a>";
        echo "</div>";

    } else {
        echo "<div class='success-box'>";
        echo "<h3>Todo correcto</h3>";
        echo "<p>Todas las actividades de asistencia ya tienen el estado <code>setunmarked</code> configurado correctamente.</p>";
        echo "</div>";
    }

} elseif ($action === 'fix') {

    echo "<div class='info-box'><h3>Ejecutando corrección...</h3></div>";

    $fixed   = 0;
    $skipped = 0;
    $errors  = [];

    foreach ($badAttendances as $row) {
        try {
            gmk_attendance_ensure_setunmarked((int)$row->attid);
            $fixed++;
        } catch (Throwable $e) {
            $errors[] = "ID {$row->attid} ({$row->attname}): " . $e->getMessage();
            $skipped++;
        }
    }

    if (empty($errors)) {
        echo "<div class='success-box'>";
        echo "<h3>Corrección completada</h3>";
        echo "<ul>";
        echo "<li><strong>{$fixed}</strong> actividades de asistencia corregidas.</li>";
        echo "<li>El cron <em>auto_mark</em> ya puede marcar ausentes correctamente en las próximas sesiones.</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div class='warning-box'>";
        echo "<h3>Corrección parcial</h3>";
        echo "<p><strong>{$fixed}</strong> corregidas, <strong>{$skipped}</strong> con error:</p>";
        echo "<ul>";
        foreach ($errors as $err) {
            echo "<li>" . htmlspecialchars($err) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }

    echo "<div class='info-box'>";
    echo "<h3>Próximos pasos</h3>";
    echo "<ol>";
    echo "<li>El cron <strong>auto_mark</strong> corre automáticamente (tarea programada de Moodle). Al cierre de cada sesión de asistencia, marcará ausentes a quienes no registraron asistencia.</li>";
    echo "<li>Para sesiones ya cerradas sin registro de ausentes, es necesario re-ejecutar el automark manualmente o registrar la asistencia de forma retroactiva.</li>";
    echo "<li>Verifique en el <a href='debug_attendance_api.php'>Debug de Asistencia</a> que los estados de una clase de muestra tienen el flag correcto.</li>";
    echo "</ol>";
    echo "</div>";

    echo "<div style='text-align:center;margin:30px 0;'>";
    echo "<a href='?' class='btn btn-primary'>Volver al diagnóstico</a>";
    echo "</div>";
}
?>

</div>

<?php echo $OUTPUT->footer(); ?>
