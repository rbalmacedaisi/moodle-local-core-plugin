<?php
/**
 * Debug: Estado de publicación de horarios
 *
 * Muestra qué clases de un periodo tienen grupos/secciones/actividades creadas
 * y cuáles les falta, permitiendo re-crear las estructuras faltantes.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir  . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$ajax = optional_param('ajax', '', PARAM_ALPHANUMEXT);

// ── AJAX: re-crear estructuras Moodle para una clase ─────────────────────────
if ($ajax === 'recreate') {
    $PAGE->set_context(context_system::instance());
    ob_start(); // buffer all output so debug messages don't contaminate JSON
    header('Content-Type: application/json');
    try {
        $classid = required_param('classid', PARAM_INT);
        require_sesskey();

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $log   = [];

        // 1. Grupo
        if (empty($class->groupid)) {
            try {
                $groupId = create_class_group($class);
                $DB->set_field('gmk_class', 'groupid', $groupId, ['id' => $classid]);
                $class->groupid = $groupId;
                $log[] = "✓ Grupo creado: id=$groupId";
            } catch (Throwable $e) {
                ob_end_clean();
                echo json_encode(['status' => 'error', 'message' => 'Error creando grupo: ' . $e->getMessage(), 'log' => $log]);
                exit;
            }
        } else {
            $log[] = "— Grupo ya existe: id={$class->groupid}";
        }

        // 2. Sección
        if (empty($class->coursesectionid)) {
            try {
                $sectionId = create_class_section($class);
                $DB->set_field('gmk_class', 'coursesectionid', $sectionId, ['id' => $classid]);
                $class->coursesectionid = $sectionId;
                $log[] = "✓ Sección creada: id=$sectionId";
            } catch (Throwable $e) {
                $log[] = "⚠ Error creando sección: " . $e->getMessage();
            }
        } else {
            $log[] = "— Sección ya existe: id={$class->coursesectionid}";
        }

        // 3. Actividades (attendance + BBB)
        $hasActivities = !empty($class->attendancemoduleid);
        try {
            create_class_activities($class, $hasActivities);
            // Re-read to get updated fields
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            $log[] = "✓ Actividades creadas/actualizadas: attendancemoduleid={$class->attendancemoduleid}";
        } catch (Throwable $e) {
            $log[] = "⚠ Error creando actividades: " . $e->getMessage();
        }

        // Re-read final state
        $class = $DB->get_record('gmk_class', ['id' => $classid]);
        ob_end_clean();
        echo json_encode([
            'status'  => 'success',
            'log'     => $log,
            'groupid'         => $class->groupid,
            'coursesectionid' => $class->coursesectionid,
            'attendancemoduleid' => $class->attendancemoduleid,
        ]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: re-crear TODAS las clases incompletas del periodo ──────────────────
if ($ajax === 'recreate_all') {
    $PAGE->set_context(context_system::instance());
    ob_start(); // buffer all output so debug messages don't contaminate JSON
    header('Content-Type: application/json');
    try {
        $periodid = required_param('periodid', PARAM_INT);
        require_sesskey();
        raise_memory_limit(MEMORY_HUGE);
        core_php_time_limit::raise(600);

        // Clases con alguna estructura faltante
        $classes = $DB->get_records_sql(
            "SELECT * FROM {gmk_class}
              WHERE periodid = :pid
                AND (groupid = 0 OR groupid IS NULL
                     OR coursesectionid = 0 OR coursesectionid IS NULL
                     OR attendancemoduleid = 0 OR attendancemoduleid IS NULL)",
            ['pid' => $periodid]
        );

        $results = ['ok' => 0, 'errors' => [], 'skipped' => 0];

        foreach ($classes as $class) {
            try {
                if (empty($class->groupid)) {
                    $groupId = create_class_group($class);
                    $DB->set_field('gmk_class', 'groupid', $groupId, ['id' => $class->id]);
                    $class->groupid = $groupId;
                }
                if (empty($class->coursesectionid)) {
                    $sectionId = create_class_section($class);
                    $DB->set_field('gmk_class', 'coursesectionid', $sectionId, ['id' => $class->id]);
                    $class->coursesectionid = $sectionId;
                }
                $hasActivities = !empty($class->attendancemoduleid);
                create_class_activities($class, $hasActivities);
                $results['ok']++;
            } catch (Throwable $e) {
                $results['errors'][] = ['id' => $class->id, 'name' => $class->name, 'error' => $e->getMessage()];
            }
        }

        ob_end_clean();
        echo json_encode(['status' => 'success', 'data' => $results, 'total' => count($classes)]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Parámetros ───────────────────────────────────────────────────────────────
$periodid = optional_param('periodid', 0, PARAM_INT);
$allPeriods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id, name');
if (!$periodid && !empty($allPeriods)) {
    $first = reset($allPeriods);
    $periodid = $first->id;
}

$PAGE->set_url('/local/grupomakro_core/pages/debug_publish_status.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Estado de publicación de horarios');
echo $OUTPUT->header();
?>
<style>
body{font-size:13px}
h2{margin-top:20px;border-bottom:2px solid #ddd;padding-bottom:6px}
table{border-collapse:collapse;width:100%;margin:8px 0;font-size:12px}
th,td{border:1px solid #ddd;padding:5px 8px;vertical-align:middle}
th{background:#f2f2f2;font-weight:bold;position:sticky;top:0;z-index:1}
.ok   {background:#d4edda}
.warn {background:#fff3cd}
.err  {background:#f8d7da}
.info-box{background:#d1ecf1;border:1px solid #bee5eb;border-radius:4px;padding:8px 12px;margin:6px 0;font-size:12px}
.warn-box{background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px 12px;margin:6px 0}
.err-box {background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;padding:8px 12px;margin:6px 0}
.ok-box  {background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;padding:8px 12px;margin:6px 0}
.btn{padding:4px 12px;border:none;border-radius:4px;cursor:pointer;color:#fff;font-size:12px}
.btn-primary{background:#007bff}.btn-success{background:#28a745}.btn-danger{background:#dc3545}.btn-warning{background:#fd7e14}
.btn:disabled{opacity:.5;cursor:not-allowed}
.check{color:green;font-weight:bold}.cross{color:red;font-weight:bold}.dash{color:#aaa}
.badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:bold;color:#fff}
.badge-ok{background:#28a745}.badge-warn{background:#fd7e14}.badge-err{background:#dc3545}
.log-out{font-family:monospace;font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:8px;border-radius:4px;white-space:pre-wrap;max-height:200px;overflow-y:auto;margin-top:4px;display:none}
.spinner{display:inline-block;width:12px;height:12px;border:2px solid #eee;border-top-color:#007bff;border-radius:50%;animation:spin 1s linear infinite;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<h1>Debug: Estado de Publicación de Horarios</h1>

<!-- Selector de periodo -->
<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
  <label><strong>Periodo:</strong></label>
  <select name="periodid" style="padding:4px 8px;border-radius:4px;border:1px solid #ccc">
    <?php foreach ($allPeriods as $p): ?>
      <option value="<?php echo $p->id ?>" <?php echo ($p->id == $periodid) ? 'selected' : '' ?>>
        <?php echo htmlspecialchars($p->name) ?> (ID:<?php echo $p->id ?>)
      </option>
    <?php endforeach ?>
  </select>
  <button type="submit" class="btn btn-primary">Ver</button>
</form>

<?php if (!$periodid): ?>
<div class="warn-box">Selecciona un periodo.</div>
<?php echo $OUTPUT->footer(); exit; ?>
<?php endif ?>

<?php
// ── Cargar todas las clases del periodo ──────────────────────────────────────
$classes = $DB->get_records_sql(
    "SELECT c.*,
            co.fullname  AS coursename,
            lp.name      AS planname,
            u.firstname  AS instr_first,
            u.lastname   AS instr_last
       FROM {gmk_class} c
       LEFT JOIN {course}               co ON co.id = c.corecourseid
       LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
       LEFT JOIN {user}                  u ON u.id  = c.instructorid
      WHERE c.periodid = :pid
      ORDER BY lp.name, co.fullname, c.name",
    ['pid' => $periodid]
);

$total    = count($classes);
$complete = 0; // group + section + activities
$noGroup  = 0;
$noSection= 0;
$noActivities = 0;
$incomplete = 0;

foreach ($classes as $c) {
    $g  = !empty($c->groupid);
    $s  = !empty($c->coursesectionid);
    $a  = !empty($c->attendancemoduleid);
    if ($g && $s && $a) { $complete++; } else { $incomplete++; }
    if (!$g) $noGroup++;
    if (!$s) $noSection++;
    if (!$a) $noActivities++;
}
?>

<!-- Resumen -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
  <div class="info-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $total ?></div>
    <div>Total clases</div>
  </div>
  <div class="ok-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $complete ?></div>
    <div>Completas</div>
  </div>
  <div class="<?php echo $incomplete > 0 ? 'err-box' : 'ok-box' ?>" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $incomplete ?></div>
    <div>Incompletas</div>
  </div>
  <div class="warn-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $noGroup ?></div>
    <div>Sin grupo</div>
  </div>
  <div class="warn-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $noSection ?></div>
    <div>Sin sección</div>
  </div>
  <div class="warn-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $noActivities ?></div>
    <div>Sin actividades</div>
  </div>
</div>

<?php if ($incomplete > 0): ?>
<div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
  <button class="btn btn-danger" onclick="recreateAll()">
    ▶ Re-crear estructuras faltantes en las <?php echo $incomplete ?> clases incompletas
  </button>
  <span id="recreate-all-result" style="font-size:12px;font-weight:bold"></span>
</div>
<?php endif ?>

<!-- Filtro -->
<div style="margin-bottom:8px;display:flex;gap:8px;align-items:center">
  <label style="font-size:12px">Filtrar:</label>
  <button class="btn btn-primary" style="padding:2px 8px;font-size:11px" onclick="filterRows('all')">Todas (<?php echo $total ?>)</button>
  <button class="btn btn-danger"  style="padding:2px 8px;font-size:11px" onclick="filterRows('incomplete')">Incompletas (<?php echo $incomplete ?>)</button>
  <button class="btn btn-success" style="padding:2px 8px;font-size:11px" onclick="filterRows('complete')">Completas (<?php echo $complete ?>)</button>
</div>

<!-- Tabla principal -->
<table id="main-table">
  <thead>
    <tr>
      <th>ID</th>
      <th>Clase</th>
      <th>Plan / Curso</th>
      <th>Instructor</th>
      <th title="Moodle course ID">CID</th>
      <th title="groupid en gmk_class">Grupo</th>
      <th title="coursesectionid en gmk_class">Sección</th>
      <th title="attendancemoduleid en gmk_class">Attendance</th>
      <th title="bbbmoduleids en gmk_class">BBB</th>
      <th>Estado</th>
      <th>Acción</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($classes as $c):
      $hasGroup  = !empty($c->groupid);
      $hasSect   = !empty($c->coursesectionid);
      $hasAtt    = !empty($c->attendancemoduleid);
      $hasBBB    = !empty($c->bbbmoduleids);
      $allOk     = $hasGroup && $hasSect && $hasAtt;
      $rowClass  = $allOk ? 'ok' : ((!$hasGroup) ? 'err' : 'warn');

      // Verificar que groupid realmente existe en BD de Moodle
      $groupExists = $hasGroup ? $DB->record_exists('groups', ['id' => $c->groupid]) : false;
      $sectExists  = $hasSect  ? $DB->record_exists('course_sections', ['id' => $c->coursesectionid]) : false;
      $attExists   = $hasAtt   ? $DB->record_exists('course_modules', ['id' => $c->attendancemoduleid]) : false;

      // Count BBB modules
      $bbbCount = 0;
      if (!empty($c->bbbmoduleids)) {
          $bbbIds = array_filter(explode(',', $c->bbbmoduleids));
          $bbbCount = count($bbbIds);
      }

      $statusBadge = $allOk
          ? '<span class="badge badge-ok">OK</span>'
          : '<span class="badge badge-err">INCOMPLETA</span>';

      // Attendance sessions count: attendancemoduleid is a course_modules.id (cmid).
      // course_modules.instance → attendance.id → attendance_sessions.attendanceid
      $attSessions = 0;
      if ($hasAtt && $attExists) {
          $attInstanceId = $DB->get_field('course_modules', 'instance', ['id' => $c->attendancemoduleid]);
          if ($attInstanceId) {
              $attSessions = $DB->count_records('attendance_sessions', ['attendanceid' => $attInstanceId]);
          }
      }
  ?>
  <tr class="<?php echo $rowClass ?>" data-complete="<?php echo $allOk ? '1' : '0' ?>" id="row-<?php echo $c->id ?>">
    <td><?php echo $c->id ?></td>
    <td>
      <strong><?php echo htmlspecialchars($c->name) ?></strong><br>
      <small style="color:#888">approved=<?php echo $c->approved ?> | type=<?php echo $c->type ?></small>
    </td>
    <td>
      <span style="font-size:11px;color:#555"><?php echo htmlspecialchars($c->planname ?? '—') ?></span><br>
      <span style="font-size:11px"><?php echo htmlspecialchars($c->coursename ?? 'ID:'.$c->corecourseid) ?></span>
    </td>
    <td style="font-size:11px"><?php echo $c->instructorid ? htmlspecialchars($c->instr_first . ' ' . $c->instr_last) : '—' ?></td>
    <td><?php echo $c->corecourseid ?></td>
    <td class="<?php echo $hasGroup ? ($groupExists ? '' : 'warn') : 'err' ?>">
      <?php if ($hasGroup): ?>
        <?php echo $groupExists ? '<span class="check">✓</span>' : '<span class="cross">⚠</span>' ?>
        <small><?php echo $c->groupid ?></small>
      <?php else: ?>
        <span class="cross">✗</span>
      <?php endif ?>
    </td>
    <td class="<?php echo $hasSect ? ($sectExists ? '' : 'warn') : 'err' ?>">
      <?php if ($hasSect): ?>
        <?php echo $sectExists ? '<span class="check">✓</span>' : '<span class="cross">⚠</span>' ?>
        <small><?php echo $c->coursesectionid ?></small>
      <?php else: ?>
        <span class="cross">✗</span>
      <?php endif ?>
    </td>
    <td class="<?php echo $hasAtt ? ($attExists ? '' : 'warn') : 'err' ?>">
      <?php if ($hasAtt): ?>
        <?php echo $attExists ? '<span class="check">✓</span>' : '<span class="cross">⚠</span>' ?>
        <small><?php echo $c->attendancemoduleid ?></small>
        <?php if ($attSessions > 0): ?>
          <br><small style="color:#28a745"><?php echo $attSessions ?> sesiones</small>
        <?php else: ?>
          <br><small style="color:#dc3545">0 sesiones</small>
        <?php endif ?>
      <?php else: ?>
        <span class="cross">✗</span>
      <?php endif ?>
    </td>
    <td>
      <?php if ($hasBBB): ?>
        <span class="check">✓</span> <small><?php echo $bbbCount ?></small>
      <?php else: ?>
        <span class="dash">—</span>
      <?php endif ?>
    </td>
    <td><?php echo $statusBadge ?></td>
    <td>
      <?php if (!$allOk || !$groupExists || !$sectExists || !$attExists): ?>
      <button class="btn btn-warning" style="padding:2px 8px;font-size:11px"
        onclick="recreateOne(<?php echo $c->id ?>, this)">Re-crear</button>
      <?php else: ?>
      <span style="color:#28a745;font-size:11px">OK</span>
      <?php endif ?>
      <div class="log-out" id="log-<?php echo $c->id ?>"></div>
    </td>
  </tr>
  <?php endforeach ?>
  </tbody>
</table>

<script>
const SESSKEY  = <?php echo json_encode(sesskey()) ?>;
const AJAX_URL = <?php echo json_encode((new moodle_url('/local/grupomakro_core/pages/debug_publish_status.php'))->out(false)) ?>;
const PERIODID = <?php echo (int)$periodid ?>;

function filterRows(mode) {
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        if (mode === 'all') {
            tr.style.display = '';
        } else if (mode === 'complete') {
            tr.style.display = tr.dataset.complete === '1' ? '' : 'none';
        } else if (mode === 'incomplete') {
            tr.style.display = tr.dataset.complete === '0' ? '' : 'none';
        }
    });
}

async function recreateOne(classid, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    const logEl = document.getElementById('log-' + classid);
    logEl.style.display = 'block';
    logEl.textContent = 'Procesando...';

    const fd = new FormData();
    fd.append('ajax', 'recreate');
    fd.append('classid', classid);
    fd.append('sesskey', SESSKEY);

    try {
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const text = await res.text();
        let d;
        try { d = JSON.parse(text); } catch(e) { logEl.textContent = 'No JSON:\n' + text; btn.disabled = false; btn.textContent = 'Re-crear'; return; }

        logEl.textContent = d.log ? d.log.join('\n') : (d.message || JSON.stringify(d));

        if (d.status === 'success') {
            btn.textContent = '✓ OK';
            btn.style.background = '#28a745';
            // Actualizar fila
            const row = document.getElementById('row-' + classid);
            if (row) {
                row.dataset.complete = '1';
                row.className = 'ok';
            }
        } else {
            btn.disabled = false;
            btn.textContent = 'Re-crear';
        }
    } catch(e) {
        logEl.textContent = 'Error JS: ' + e.message;
        btn.disabled = false;
        btn.textContent = 'Re-crear';
    }
}

async function recreateAll() {
    if (!confirm('¿Re-crear estructuras faltantes en TODAS las clases incompletas del periodo?\nEsto puede tardar varios minutos.')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Procesando...';
    const resEl = document.getElementById('recreate-all-result');
    resEl.textContent = '';

    const fd = new FormData();
    fd.append('ajax', 'recreate_all');
    fd.append('periodid', PERIODID);
    fd.append('sesskey', SESSKEY);

    try {
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const text = await res.text();
        let d;
        try { d = JSON.parse(text); } catch(e) { resEl.textContent = 'No JSON: ' + text; btn.disabled = false; return; }

        if (d.status === 'success') {
            const r = d.data;
            resEl.style.color = r.errors.length > 0 ? '#fd7e14' : '#28a745';
            resEl.textContent = `✓ ${r.ok}/${d.total} procesadas correctamente.`
                + (r.errors.length > 0 ? ` ${r.errors.length} errores: ` + r.errors.map(e => `[${e.id}] ${e.error}`).join(' | ') : '');
            // Recargar la página para ver estado actualizado
            setTimeout(() => location.reload(), 2000);
        } else {
            resEl.style.color = '#dc3545';
            resEl.textContent = 'Error: ' + d.message;
        }
        btn.disabled = false;
        btn.textContent = '▶ Re-crear estructuras faltantes';
    } catch(e) {
        resEl.textContent = 'Error JS: ' + e.message;
        btn.disabled = false;
        btn.textContent = '▶ Re-crear estructuras faltantes';
    }
}
</script>

<?php echo $OUTPUT->footer() ?>
