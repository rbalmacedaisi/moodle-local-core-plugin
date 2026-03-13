<?php
// Elimina todo lo publicado de un período académico:
//   - gmk_class (y sus schedules, queue, pre_registration)
//   - Resetea gmk_course_progre que referencian esas clases
//   - Limpia el draft del período
// Deja el tablero listo para hacer una publicación limpia desde cero.

$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/reset_period_publish.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Reset: Limpiar Publicación de Período');
$PAGE->set_heading('Reset: Limpiar Publicación de Período');

$action   = optional_param('action',   '', PARAM_ALPHA);
$periodid = optional_param('periodid', 0,  PARAM_INT);

// ── Endpoint AJAX: resetea UN classid ─────────────────────────────────────
if ($action === 'resetone') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

    $classid = required_param('classid', PARAM_INT);
    $log     = [];

    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if (!$class) {
        echo json_encode(['ok' => false, 'msg' => "Clase $classid no encontrada (ya eliminada)."]);
        exit;
    }

    try {
        // 1. Grade category
        if (!empty($class->gradecategoryid) && !empty($class->corecourseid)
            && $DB->record_exists('course', ['id' => $class->corecourseid])) {
            $cat = grade_category::fetch(['id' => (int)$class->gradecategoryid, 'courseid' => (int)$class->corecourseid]);
            if ($cat) {
                $cat->delete();
                $log[] = "grade_category {$class->gradecategoryid} eliminada";
            }
        }

        // 2. Course section + todas sus actividades (attendance, BBB, etc.)
        if (!empty($class->coursesectionid) && !empty($class->corecourseid)
            && $DB->record_exists('course', ['id' => $class->corecourseid])) {
            $sectionNum = $DB->get_field('course_sections', 'section', ['id' => $class->coursesectionid]);
            if ($sectionNum !== false) {
                course_delete_section($class->corecourseid, $sectionNum, true, true);
                $log[] = "sección Moodle {$class->coursesectionid} eliminada (con actividades)";
            }
            $DB->delete_records('gmk_bbb_attendance_relation', ['classid' => $classid]);
        }

        // 3. Grupo Moodle
        if (!empty($class->groupid) && $DB->record_exists('groups', ['id' => $class->groupid])) {
            groups_delete_group($class->groupid);
            $log[] = "grupo Moodle {$class->groupid} eliminado";
        }

        // 4. Reset gmk_course_progre
        $progres = $DB->get_records('gmk_course_progre', ['classid' => $classid]);
        $progreCount = 0;
        foreach ($progres as $p) {
            $isTerminal = in_array((int)$p->status, [3, 4, 5]);
            if ($isTerminal) {
                $DB->execute("UPDATE {gmk_course_progre} SET classid=0, groupid=0, timemodified=:now WHERE id=:id",
                             ['now' => time(), 'id' => $p->id]);
            } else {
                $DB->execute("UPDATE {gmk_course_progre} SET classid=0, groupid=0, status=1, grade=0, progress=0, timemodified=:now WHERE id=:id",
                             ['now' => time(), 'id' => $p->id]);
            }
            $progreCount++;
        }
        if ($progreCount) $log[] = "$progreCount progre reseteados";

        // 5. Tablas plugin
        $DB->delete_records('gmk_class_pre_registration', ['classid' => $classid]);
        $DB->delete_records('gmk_class_queue',            ['classid' => $classid]);
        $DB->delete_records('gmk_class_schedules',        ['classid' => $classid]);

        // 6. Registro de eliminación y delete final
        $msg = new stdClass();
        $msg->classid         = $classid;
        $msg->deletionmessage = 'Reset período desde reset_period_publish.php';
        $msg->usermodified    = $USER->id;
        $msg->timecreated     = time();
        $msg->timemodified    = time();
        $DB->insert_record('gmk_class_deletion_message', $msg);

        $DB->delete_records('gmk_class', ['id' => $classid]);

        $summary = "Clase $classid (" . htmlspecialchars($class->name) . "): " . implode(', ', $log ?: ['eliminada']);
        echo json_encode(['ok' => true, 'msg' => $summary]);

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => "Clase $classid — ERROR: " . $e->getMessage()]);
    }
    exit;
}

// ── Endpoint AJAX: limpia el draft del período ─────────────────────────────
if ($action === 'cleardraft') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    if (!$periodid) {
        echo json_encode(['ok' => false, 'msg' => 'periodid requerido.']);
        exit;
    }
    $DB->set_field('gmk_academic_periods', 'draft_schedules', null, ['id' => $periodid]);
    echo json_encode(['ok' => true, 'msg' => "Draft del período $periodid limpiado."]);
    exit;
}

echo $OUTPUT->header();

echo '<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 10px; }
  th { background: #c0392b; color: white; }
  tr:nth-child(even) { background: #f9f9f9; }
  .ok   { color: green; font-weight: bold; }
  .err  { color: red; font-weight: bold; }
  .warn { color: orange; font-weight: bold; }
  .box { padding: 10px 14px; border-radius: 4px; margin: 8px 0; border: 1px solid; }
  .box.ok   { background:#dfd; border-color:green; }
  .box.err  { background:#fde; border-color:red; }
  .box.warn { background:#fff3cd; border-color:#ffc107; }
  .box.info { background:#e8f0fe; border-color:#1a73e8; }
  .box.danger { background:#fde; border-color:#c0392b; }
  .section { margin: 22px 0 8px; font-size: 15px; font-weight: bold;
             border-bottom: 2px solid #c0392b; padding-bottom: 3px; }
  button, .btn { padding: 8px 20px; background:#1a73e8; color:white; border:none;
                 border-radius:3px; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block; }
  button:hover, .btn:hover { background:#1558b0; }
  .btn-danger { background:#c0392b; }
  .btn-danger:hover { background:#962d22; }
  select { padding: 8px 12px; border: 1px solid #ccc; border-radius:4px; font-size:14px; min-width:300px; }
  #progress-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
                      z-index:9999; align-items:center; justify-content:center; }
  #progress-box { background:#fff; border-radius:8px; padding:28px 32px; width:560px;
                  max-width:95vw; box-shadow:0 8px 32px rgba(0,0,0,.3); }
  #prog-bar-wrap { background:#e9ecef; border-radius:4px; height:20px; overflow:hidden; margin-bottom:8px; }
  #prog-bar { height:100%; background:#c0392b; width:0%; transition:width .3s; }
  #prog-log { font-size:12px; line-height:1.8; max-height:280px; overflow-y:auto;
              border:1px solid #ddd; border-radius:4px; padding:8px 12px; background:#f8f9fa; margin-top:8px; }
</style>';

// ── Listar períodos disponibles ─────────────────────────────────────────────
$periods = $DB->get_records_sql(
    "SELECT ap.id, ap.name,
            COUNT(gc.id) AS class_count
       FROM {gmk_academic_periods} ap
  LEFT JOIN {gmk_class} gc ON gc.periodid = ap.id
      GROUP BY ap.id, ap.name
      ORDER BY ap.id DESC"
);

echo "<div class='box danger'>
⚠ <b>Esta acción elimina permanentemente todos los registros de clases del período seleccionado</b>
y resetea el estado de los estudiantes asociados. El tablero de planificación conservará su borrador
para que puedas volver a publicar inmediatamente.
</div>";

// ── Selector de período ──────────────────────────────────────────────────────
echo "<div style='margin:16px 0; display:flex; gap:12px; align-items:center;'>
  <select id='period-select'>
    <option value=''>— Selecciona un período —</option>";
foreach ($periods as $p) {
    $sel = ($p->id == $periodid) ? 'selected' : '';
    echo "<option value='{$p->id}' $sel>" . htmlspecialchars($p->name) . " ({$p->class_count} clases)</option>";
}
echo "  </select>
  <button class='btn' onclick='loadPeriod()'>Cargar</button>
</div>";

// ── Si hay período seleccionado, mostrar sus clases ─────────────────────────
if ($periodid > 0) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
    if (!$period) {
        echo "<div class='box err'>Período no encontrado.</div>";
        echo $OUTPUT->footer();
        exit;
    }

    $classes = $DB->get_records_sql(
        "SELECT gc.id, gc.name, gc.approved, gc.groupid, gc.attendancemoduleid,
                COUNT(DISTINCT gcp.id) AS progre_count,
                COUNT(DISTINCT gcs.id) AS schedule_count
           FROM {gmk_class} gc
      LEFT JOIN {gmk_course_progre} gcp ON gcp.classid = gc.id
      LEFT JOIN {gmk_class_schedules} gcs ON gcs.classid = gc.id
          WHERE gc.periodid = :pid
          GROUP BY gc.id, gc.name, gc.approved, gc.groupid, gc.attendancemoduleid
          ORDER BY gc.name",
        ['pid' => $periodid]
    );

    $totalClasses = count($classes);

    echo "<div class='section'>Período: " . htmlspecialchars($period->name) . " — $totalClasses clases</div>";

    if ($totalClasses === 0) {
        echo "<div class='box ok'>✔ Este período no tiene clases publicadas.</div>";
        echo "<p><button class='btn-danger btn' onclick='clearDraft()'>🗑 Limpiar Draft del Período</button></p>";
    } else {
        echo "<div class='box warn'>Se eliminarán <b>$totalClasses clases</b>. Los estudiantes en estado
        <b>Cursando</b> quedarán en <b>Disponible</b>. Los estudiantes con nota (Completada/Aprobada/Reprobada)
        conservarán su calificación pero se limpiará la referencia a la clase.</div>";

        echo "<table>
        <thead><tr>
          <th>#</th><th>Clase</th><th>Aprobada</th><th>Grupo Moodle</th>
          <th>Módulo Asistencia</th><th>Sesiones</th><th>Estudiantes en progre</th>
        </tr></thead><tbody>";

        $i = 0;
        $classIds = [];
        foreach ($classes as $cls) {
            $i++;
            $classIds[] = $cls->id;
            $approvedBadge = $cls->approved
                ? "<span style='color:green'>✔ Sí</span>"
                : "<span style='color:#aaa'>No</span>";
            echo "<tr>
              <td>$i</td>
              <td>" . htmlspecialchars($cls->name) . " <small style='color:#666'>id={$cls->id}</small></td>
              <td>$approvedBadge</td>
              <td>" . ($cls->groupid ? $cls->groupid : '<span style="color:#aaa">—</span>') . "</td>
              <td>" . ($cls->attendancemoduleid ? $cls->attendancemoduleid : '<span style="color:#aaa">—</span>') . "</td>
              <td>{$cls->schedule_count}</td>
              <td>" . ($cls->progre_count > 0 ? "<b>{$cls->progre_count}</b>" : '<span style="color:#aaa">0</span>') . "</td>
            </tr>";
        }
        echo "</tbody></table>";

        $classIdsJson = json_encode($classIds);
        echo "<div style='margin-top:16px; display:flex; gap:10px;'>
          <button class='btn-danger btn' onclick='startReset($classIdsJson)'>
            🗑 Eliminar todo y limpiar draft
          </button>
          <a href='?periodid=$periodid' class='btn' style='background:#6c757d'>↺ Recargar</a>
        </div>";
    }
}

// ── Overlay de progreso ──────────────────────────────────────────────────────
echo "
<div id='progress-overlay'>
  <div id='progress-box'>
    <div style='font-size:16px;font-weight:bold;margin-bottom:14px;' id='prog-title'>Eliminando clases...</div>
    <div id='prog-bar-wrap'><div id='prog-bar'></div></div>
    <div style='font-size:13px;color:#555;margin-bottom:4px;' id='prog-counter'>0 / 0</div>
    <div id='prog-log'></div>
    <div style='margin-top:16px;text-align:right;'>
      <button id='prog-close' onclick='closeOverlay()' class='btn' style='display:none;background:#28a745;'>
        ✔ Listo — Ir al tablero
      </button>
    </div>
  </div>
</div>";

$sesskey = sesskey();
echo "<script>
var RESET_URL = window.location.pathname + '?action=resetone&sesskey={$sesskey}';
var DRAFT_URL = window.location.pathname + '?action=cleardraft&sesskey={$sesskey}&periodid=' + " . (int)$periodid . ";

function loadPeriod() {
    var pid = document.getElementById('period-select').value;
    if (!pid) return;
    window.location.href = '?periodid=' + pid;
}

function logLine(msg, ok) {
    var d = document.getElementById('prog-log');
    var line = document.createElement('div');
    line.style.color = ok ? '#2e7d32' : '#c62828';
    line.textContent = (ok ? '✔ ' : '✘ ') + msg;
    d.appendChild(line);
    d.scrollTop = d.scrollHeight;
}

async function startReset(classIds) {
    if (!confirm('¿Seguro que deseas eliminar ' + classIds.length + ' clase(s) del período? Esta acción no se puede deshacer.')) return;

    var overlay = document.getElementById('progress-overlay');
    var bar     = document.getElementById('prog-bar');
    var counter = document.getElementById('prog-counter');
    var title   = document.getElementById('prog-title');
    var closeBtn= document.getElementById('prog-close');

    overlay.style.display = 'flex';
    bar.style.width = '0%';
    bar.style.background = '#c0392b';
    document.getElementById('prog-log').innerHTML = '';
    closeBtn.style.display = 'none';
    title.textContent = 'Eliminando ' + classIds.length + ' clase(s)...';

    var done = 0, errors = 0, total = classIds.length;

    for (var i = 0; i < classIds.length; i++) {
        counter.textContent = (i + 1) + ' / ' + total;
        try {
            var resp = await fetch(RESET_URL + '&classid=' + classIds[i], { method: 'POST' });
            var data = await resp.json();
            logLine(data.msg, data.ok);
            if (data.ok) done++; else errors++;
        } catch(e) {
            logLine('classid=' + classIds[i] + ': Error de red — ' + e.message, false);
            errors++;
        }
        bar.style.width = Math.round(((i + 1) / total) * 100) + '%';
    }

    // Limpiar draft
    logLine('Limpiando draft del período...', true);
    try {
        var dr = await fetch(DRAFT_URL, { method: 'POST' });
        var dd = await dr.json();
        logLine(dd.msg, dd.ok);
    } catch(e) {
        logLine('Error limpiando draft: ' + e.message, false);
    }

    bar.style.background = errors > 0 ? '#fd7e14' : '#28a745';
    title.textContent = 'Completado: ' + done + ' eliminadas' + (errors > 0 ? ', ' + errors + ' error(es)' : '') + '.';
    closeBtn.style.display = 'inline-block';
}

async function clearDraft() {
    if (!confirm('¿Limpiar el draft del período?')) return;
    var resp = await fetch(DRAFT_URL, { method: 'POST' });
    var data = await resp.json();
    alert(data.msg);
}

function closeOverlay() {
    window.location.href = '/local/grupomakro_core/pages/academic_planning.php';
}
</script>";

echo $OUTPUT->footer();
