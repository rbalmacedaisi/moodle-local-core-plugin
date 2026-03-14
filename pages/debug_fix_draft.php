<?php
// Página de diagnóstico y reparación del draft de planificación:
//   1. Detecta entradas duplicadas en draft_schedules y deduplica (conserva la más reciente por id).
//   2. Detecta grupos Moodle huérfanos (sin gmk_class activa asociada) en cursos gestionados
//      por el plugin, y los elimina junto con su sección de curso.

$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_fix_draft.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Fix Draft & Grupos Huérfanos');
$PAGE->set_heading('Debug: Fix Draft & Grupos Huérfanos');

$action   = optional_param('action',   '', PARAM_ALPHA);
$periodid = optional_param('periodid', 0,  PARAM_INT);

// ── AJAX: Deduplica el draft del período ──────────────────────────────────────
if ($action === 'fixdraft') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    if (!$periodid) {
        echo json_encode(['ok' => false, 'msg' => 'periodid requerido.']);
        exit;
    }

    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
    if (!$period || empty($period->draft_schedules)) {
        echo json_encode(['ok' => false, 'msg' => 'Período no encontrado o draft vacío.']);
        exit;
    }

    $draft = json_decode($period->draft_schedules, true);
    if (!is_array($draft)) {
        echo json_encode(['ok' => false, 'msg' => 'Draft no es un array JSON válido.']);
        exit;
    }

    // Agrupar por clave corecourseid|shift|day, conservar el de mayor id
    $byKey   = [];
    $removed = 0;
    foreach ($draft as $entry) {
        $key = ($entry['corecourseid'] ?? '') . '|' . ($entry['shift'] ?? '') . '|' . ($entry['day'] ?? '');
        if (!isset($byKey[$key])) {
            $byKey[$key] = $entry;
        } else {
            // Conservar el que tiene el id más alto (publicado más recientemente)
            $currentId  = (int)($byKey[$key]['id'] ?? 0);
            $incomingId = (int)($entry['id'] ?? 0);
            if ($incomingId > $currentId) {
                $byKey[$key] = $entry;
            }
            $removed++;
        }
    }

    $newDraft = array_values($byKey);
    $DB->set_field('gmk_academic_periods', 'draft_schedules', json_encode($newDraft), ['id' => $periodid]);

    echo json_encode([
        'ok'      => true,
        'msg'     => "Draft reparado: $removed entradas duplicadas eliminadas. Quedaron " . count($newDraft) . " clases únicas.",
        'removed' => $removed,
        'kept'    => count($newDraft),
    ]);
    exit;
}

// ── AJAX: Elimina una sección huérfana (con todas sus actividades) ────────────
if ($action === 'deletesection') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    require_once($CFG->dirroot . '/course/lib.php');

    $sectionid     = required_param('sectionid',     PARAM_INT);
    $courseid      = required_param('courseid',      PARAM_INT);
    $sectionnumber = required_param('sectionnumber', PARAM_INT);

    // Doble-check: no debe tener gmk_class activa
    if ($DB->record_exists('gmk_class', ['coursesectionid' => $sectionid])) {
        echo json_encode(['ok' => false, 'msg' => "Sección $sectionid tiene gmk_class activa; no se elimina."]);
        exit;
    }
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        echo json_encode(['ok' => false, 'msg' => "Curso $courseid no existe."]);
        exit;
    }
    try {
        course_delete_section($courseid, $sectionnumber, true, true);
        echo json_encode(['ok' => true, 'msg' => "Sección id=$sectionid eliminada con todas sus actividades."]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => "ERROR: " . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Elimina un grupo huérfano (y su sección si existe) ─────────────────
if ($action === 'deletegroup') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    require_once($CFG->dirroot . '/group/lib.php');
    require_once($CFG->dirroot . '/course/lib.php');

    $groupid  = required_param('groupid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $log      = [];

    $group = $DB->get_record('groups', ['id' => $groupid]);
    if (!$group) {
        echo json_encode(['ok' => false, 'msg' => "Grupo $groupid no encontrado (ya eliminado)."]);
        exit;
    }

    // Verificar que sigue sin gmk_class asociada (evita carreras de condición)
    if ($DB->record_exists('gmk_class', ['groupid' => $groupid])) {
        echo json_encode(['ok' => false, 'msg' => "Grupo $groupid ahora tiene una gmk_class activa; no se elimina."]);
        exit;
    }

    try {
        // Buscar sección cuyo name coincide con el nombre del grupo
        if ($DB->record_exists('course', ['id' => $courseid])) {
            $section = $DB->get_record_sql(
                "SELECT id, section FROM {course_sections}
                  WHERE course = :cid AND name = :gname
                  LIMIT 1",
                ['cid' => $courseid, 'gname' => $group->name]
            );
            if ($section) {
                course_delete_section($courseid, $section->section, true, true);
                $log[] = "Sección '{$group->name}' (id={$section->id}) eliminada con sus actividades";
            }
        }

        // Eliminar el grupo (también quita membresías automáticamente)
        groups_delete_group($groupid);
        $log[] = "Grupo $groupid ('{$group->name}') eliminado";

        echo json_encode(['ok' => true, 'msg' => implode('; ', $log)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => "ERROR: " . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();

echo '<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 10px; }
  th { background: #1a73e8; color: white; }
  tr:nth-child(even) { background: #f9f9f9; }
  .ok   { color: green; font-weight: bold; }
  .err  { color: red; font-weight: bold; }
  .warn { color: orange; font-weight: bold; }
  .box { padding: 10px 14px; border-radius: 4px; margin: 8px 0; border: 1px solid; }
  .box.ok    { background:#dfd; border-color:green; }
  .box.err   { background:#fde; border-color:red; }
  .box.warn  { background:#fff3cd; border-color:#ffc107; }
  .box.info  { background:#e8f0fe; border-color:#1a73e8; }
  .section { margin: 22px 0 8px; font-size: 16px; font-weight: bold;
             border-bottom: 2px solid #1a73e8; padding-bottom: 4px; }
  .subsection { margin: 14px 0 6px; font-size: 14px; font-weight: bold; color: #555; }
  button, .btn { padding: 7px 18px; background:#1a73e8; color:white; border:none;
                 border-radius:3px; cursor:pointer; font-size:13px; display:inline-block;
                 text-decoration:none; }
  button:hover, .btn:hover { background:#1558b0; }
  .btn-danger { background:#c0392b; }
  .btn-danger:hover { background:#962d22; }
  .btn-sm { padding: 4px 12px; font-size: 12px; }
  select { padding: 7px 12px; border: 1px solid #ccc; border-radius:4px; font-size:14px; min-width:320px; }
  code { background:#f0f0f0; padding: 1px 5px; border-radius:3px; font-size:12px; }
  #progress-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
                      z-index:9999; align-items:center; justify-content:center; }
  #progress-box { background:#fff; border-radius:8px; padding:28px 32px; width:580px;
                  max-width:95vw; box-shadow:0 8px 32px rgba(0,0,0,.3); }
  #prog-bar-wrap { background:#e9ecef; border-radius:4px; height:18px; overflow:hidden; margin-bottom:8px; }
  #prog-bar { height:100%; background:#1a73e8; width:0%; transition:width .3s; }
  #prog-log { font-size:12px; line-height:1.9; max-height:300px; overflow-y:auto;
              border:1px solid #ddd; border-radius:4px; padding:8px 12px;
              background:#f8f9fa; margin-top:8px; font-family:monospace; }
  .row-log { font-size:12px; color:#555; font-family:monospace; margin-left:6px; }
</style>';

// ── Selector de período ───────────────────────────────────────────────────────
$periods = $DB->get_records_sql(
    "SELECT id, name FROM {gmk_academic_periods}
      WHERE draft_schedules IS NOT NULL AND draft_schedules != ''
      ORDER BY id DESC"
);

echo "<div class='section'>1. Duplicados en el Draft de Planificación</div>";

echo "<div style='margin:12px 0; display:flex; gap:10px; align-items:center;'>
  <select id='period-select'><option value=''>— Selecciona un período —</option>";
foreach ($periods as $p) {
    $sel = ($p->id == $periodid) ? 'selected' : '';
    echo "<option value='{$p->id}' $sel>" . htmlspecialchars($p->name) . "</option>";
}
echo "  </select>
  <button class='btn' onclick='loadPeriod()'>Analizar</button>
</div>";

// ── Análisis del draft ────────────────────────────────────────────────────────
if ($periodid > 0) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);

    if (!$period || empty($period->draft_schedules)) {
        echo "<div class='box warn'>⚠ El período no tiene draft guardado.</div>";
    } else {
        $draft = json_decode($period->draft_schedules, true);

        if (!is_array($draft)) {
            echo "<div class='box err'>✘ El campo draft_schedules no contiene un JSON válido.</div>";
        } else {
            $total  = count($draft);
            $byKey  = [];
            foreach ($draft as $idx => $entry) {
                $key = ($entry['corecourseid'] ?? '') . '|' . ($entry['shift'] ?? '') . '|' . ($entry['day'] ?? '');
                $byKey[$key][] = ['idx' => $idx, 'entry' => $entry];
            }

            $duplicateGroups = array_filter($byKey, fn($g) => count($g) > 1);
            $dupCount        = count($duplicateGroups);
            $totalDuplicates = array_sum(array_map(fn($g) => count($g) - 1, $duplicateGroups));

            if ($dupCount === 0) {
                echo "<div class='box ok'>✔ El draft no tiene duplicados. Total: $total clases únicas.</div>";
            } else {
                echo "<div class='box warn'>⚠ Se encontraron <b>$dupCount claves duplicadas</b>
                ($totalDuplicates entradas extras de $total totales).</div>";

                echo "<table>
                <thead><tr>
                  <th>Clave (corecourseid|shift|day)</th>
                  <th>Copias</th>
                  <th>IDs en draft</th>
                  <th>Se conservará</th>
                </tr></thead><tbody>";

                foreach ($duplicateGroups as $key => $group) {
                    $ids    = array_map(fn($g) => $g['entry']['id'] ?? '(sin id)', $group);
                    $maxId  = max(array_map(fn($g) => (int)($g['entry']['id'] ?? 0), $group));
                    $keepId = $maxId ?: end($ids);
                    echo "<tr>
                      <td><code>" . htmlspecialchars($key) . "</code></td>
                      <td>" . count($group) . "</td>
                      <td><code>" . htmlspecialchars(implode(', ', $ids)) . "</code></td>
                      <td><code>id=$keepId</code></td>
                    </tr>";
                }
                echo "</tbody></table>";

                echo "<p>
                  <button class='btn-danger btn' onclick='fixDraft($periodid)'>
                    🔧 Reparar Draft (eliminar duplicados)
                  </button>
                </p>";
            }
        }
    }
}

// ── Sección 2: Grupos huérfanos ───────────────────────────────────────────────
echo "<div class='section' style='margin-top:32px;'>2. Grupos Moodle Huérfanos</div>";

echo "<div class='box info'>
  Grupos en <b>cursos gestionados por el plugin</b> (con <code>idnumber</code> asignado por el plugin)
  que <b>no tienen ninguna clase activa asociada</b> (<code>gmk_class.groupid</code>).
  Grupos manuales sin <code>idnumber</code> (ej. «Revalida») se excluyen automáticamente.
</div>";

$orphanedGroups = $DB->get_records_sql(
    "SELECT g.id AS groupid, g.name AS groupname, g.idnumber AS groupidnumber, g.courseid,
            c.fullname AS coursename, c.shortname AS courseshortname
       FROM {groups} g
       JOIN {course} c ON c.id = g.courseid
      WHERE g.courseid IN (
              SELECT DISTINCT corecourseid FROM {gmk_class}
               WHERE corecourseid IS NOT NULL AND corecourseid > 0
            )
        AND g.idnumber IS NOT NULL AND g.idnumber != ''
        AND NOT EXISTS (SELECT 1 FROM {gmk_class} WHERE groupid = g.id)
      ORDER BY c.fullname, g.name"
);

if (empty($orphanedGroups)) {
    echo "<div class='box ok'>✔ No se encontraron grupos huérfanos en cursos del plugin.</div>";
} else {
    $orphanCount = count($orphanedGroups);
    echo "<div class='box warn'>⚠ Se encontraron <b>$orphanCount grupo(s) huérfano(s)</b>.</div>";

    $byCourse = [];
    foreach ($orphanedGroups as $og) {
        $byCourse[$og->courseid][] = $og;
    }

    $allGroupsJson = json_encode(array_values(array_map(
        fn($og) => ['groupid' => (int)$og->groupid, 'courseid' => (int)$og->courseid],
        $orphanedGroups
    )));

    echo "<p>
      <button class='btn-danger btn' onclick='deleteAllGroups($allGroupsJson)'>
        🗑 Eliminar todos los grupos huérfanos ($orphanCount)
      </button>
    </p>";

    foreach ($byCourse as $cid => $groups) {
        $first = $groups[0];
        echo "<div class='subsection'>Curso: " . htmlspecialchars($first->coursename) .
             " <small style='color:#888'>(" . htmlspecialchars($first->courseshortname) . ", id=$cid)</small></div>";

        echo "<table><thead><tr>
          <th>Group ID</th><th>Nombre del grupo</th><th>idnumber</th><th>Acción</th>
        </tr></thead><tbody>";

        foreach ($groups as $og) {
            $groupJson = json_encode(['groupid' => (int)$og->groupid, 'courseid' => (int)$og->courseid]);
            echo "<tr id='grow-{$og->groupid}'>
              <td>{$og->groupid}</td>
              <td>" . htmlspecialchars($og->groupname) . "</td>
              <td><code style='font-size:11px'>" . htmlspecialchars($og->groupidnumber) . "</code></td>
              <td>
                <button class='btn btn-danger btn-sm' onclick='deleteOneGroup($groupJson, this)'>Eliminar</button>
                <span id='gstatus-{$og->groupid}' class='row-log'></span>
              </td>
            </tr>";
        }
        echo "</tbody></table>";
    }
}

// ── Sección 3: Secciones de curso huérfanas ───────────────────────────────────
echo "<div class='section' style='margin-top:32px;'>3. Secciones de Curso Huérfanas (con actividades)</div>";

echo "<div class='box info'>
  Secciones de curso en cursos del plugin que <b>tienen actividades</b> pero
  <b>ninguna gmk_class</b> apunta a ellas (<code>gmk_class.coursesectionid</code>).
  Esto ocurre cuando el grupo fue eliminado pero la sección quedó sin limpiar
  (muestra «grupo que falta» en el curso).
</div>";

$orphanedSections = $DB->get_records_sql(
    "SELECT cs.id AS sectionid, cs.name AS sectionname, cs.section AS sectionnumber,
            cs.course AS courseid, c.fullname AS coursename, c.shortname AS courseshortname,
            COUNT(cm.id) AS module_count
       FROM {course_sections} cs
       JOIN {course} c ON c.id = cs.course
  LEFT JOIN {course_modules} cm ON cm.section = cs.id
      WHERE cs.course IN (
              SELECT DISTINCT corecourseid FROM {gmk_class}
               WHERE corecourseid IS NOT NULL AND corecourseid > 0
            )
        AND cs.section > 0
        AND cs.name IS NOT NULL AND cs.name != ''
        AND NOT EXISTS (SELECT 1 FROM {gmk_class} WHERE coursesectionid = cs.id)
      GROUP BY cs.id, cs.name, cs.section, cs.course, c.fullname, c.shortname
     HAVING COUNT(cm.id) > 0
      ORDER BY c.fullname, cs.name"
);

if (empty($orphanedSections)) {
    echo "<div class='box ok'>✔ No se encontraron secciones huérfanas con actividades.</div>";
} else {
    $secCount = count($orphanedSections);
    echo "<div class='box warn'>⚠ Se encontraron <b>$secCount sección(es) huérfana(s) con actividades</b>.</div>";

    $byCourseS = [];
    foreach ($orphanedSections as $os) {
        $byCourseS[$os->courseid][] = $os;
    }

    $allSectionsJson = json_encode(array_values(array_map(
        fn($os) => [
            'sectionid'     => (int)$os->sectionid,
            'courseid'      => (int)$os->courseid,
            'sectionnumber' => (int)$os->sectionnumber,
        ],
        $orphanedSections
    )));

    echo "<p>
      <button class='btn-danger btn' onclick='deleteAllSections($allSectionsJson)'>
        🗑 Eliminar todas las secciones huérfanas ($secCount)
      </button>
    </p>";

    foreach ($byCourseS as $cid => $sections) {
        $first = $sections[0];
        echo "<div class='subsection'>Curso: " . htmlspecialchars($first->coursename) .
             " <small style='color:#888'>(" . htmlspecialchars($first->courseshortname) . ", id=$cid)</small></div>";

        echo "<table><thead><tr>
          <th>Section ID</th><th>Nombre de la sección</th><th>Actividades</th><th>Acción</th>
        </tr></thead><tbody>";

        foreach ($sections as $os) {
            $secJson = json_encode([
                'sectionid'     => (int)$os->sectionid,
                'courseid'      => (int)$os->courseid,
                'sectionnumber' => (int)$os->sectionnumber,
            ]);
            echo "<tr id='srow-{$os->sectionid}'>
              <td>{$os->sectionid}</td>
              <td>" . htmlspecialchars($os->sectionname) . "</td>
              <td>{$os->module_count}</td>
              <td>
                <button class='btn btn-danger btn-sm' onclick='deleteOneSection($secJson, this)'>Eliminar</button>
                <span id='sstatus-{$os->sectionid}' class='row-log'></span>
              </td>
            </tr>";
        }
        echo "</tbody></table>";
    }
}

// ── Overlay de progreso ───────────────────────────────────────────────────────
echo "
<div id='progress-overlay'>
  <div id='progress-box'>
    <div style='font-size:16px;font-weight:bold;margin-bottom:14px;' id='prog-title'>Procesando...</div>
    <div id='prog-bar-wrap'><div id='prog-bar'></div></div>
    <div style='font-size:13px;color:#555;margin-bottom:4px;' id='prog-counter'></div>
    <div id='prog-log'></div>
    <div style='margin-top:16px;text-align:right;'>
      <button id='prog-reload' onclick='window.location.reload()' class='btn'
              style='display:none;background:#28a745;'>✔ Listo — Recargar</button>
    </div>
  </div>
</div>";

$sesskey = sesskey();
echo "<script>
var SESS = '$sesskey';
var BASE = window.location.pathname;

function loadPeriod() {
    var pid = document.getElementById('period-select').value;
    if (!pid) return;
    window.location.href = BASE + '?periodid=' + pid;
}

function logLine(msg, ok) {
    var d = document.getElementById('prog-log');
    var line = document.createElement('div');
    line.style.color = ok ? '#2e7d32' : '#c62828';
    line.textContent = (ok ? '✔ ' : '✘ ') + msg;
    d.appendChild(line);
    d.scrollTop = d.scrollHeight;
}

function showOverlay(title) {
    document.getElementById('prog-title').textContent = title;
    document.getElementById('prog-log').innerHTML = '';
    document.getElementById('prog-counter').textContent = '';
    document.getElementById('prog-bar').style.width = '0%';
    document.getElementById('prog-bar').style.background = '#1a73e8';
    document.getElementById('prog-reload').style.display = 'none';
    document.getElementById('progress-overlay').style.display = 'flex';
}

function finishOverlay(ok) {
    document.getElementById('prog-bar').style.background = ok ? '#28a745' : '#fd7e14';
    document.getElementById('prog-reload').style.display = 'inline-block';
}

// ── Fix draft ─────────────────────────────────────────────────────────────────
async function fixDraft(periodid) {
    if (!confirm('¿Desduplicar el draft del período ' + periodid + '?\\nSe conservará el ID más alto por cada clave única.')) return;
    showOverlay('Reparando draft...');
    try {
        var resp = await fetch(BASE + '?action=fixdraft&periodid=' + periodid + '&sesskey=' + SESS, { method: 'POST' });
        var data = await resp.json();
        logLine(data.msg, data.ok);
        finishOverlay(data.ok);
    } catch(e) {
        logLine('Error de red: ' + e.message, false);
        finishOverlay(false);
    }
}

// ── Eliminar un grupo ─────────────────────────────────────────────────────────
async function deleteOneGroup(info, btn) {
    if (!confirm('¿Eliminar grupo ' + info.groupid + ' (\"' + (btn.closest ? btn.closest('tr').children[1].textContent : '') + '\")?')) return;
    btn.disabled = true;
    var statusEl = document.getElementById('gstatus-' + info.groupid);
    statusEl.textContent = ' Eliminando...';
    try {
        var resp = await fetch(
            BASE + '?action=deletegroup&groupid=' + info.groupid + '&courseid=' + info.courseid + '&sesskey=' + SESS,
            { method: 'POST' }
        );
        var data = await resp.json();
        if (data.ok) {
            statusEl.textContent = ' ✔ ' + data.msg;
            statusEl.style.color = 'green';
            document.getElementById('grow-' + info.groupid).style.opacity = '0.4';
        } else {
            statusEl.textContent = ' ✘ ' + data.msg;
            statusEl.style.color = 'red';
            btn.disabled = false;
        }
    } catch(e) {
        statusEl.textContent = ' ✘ Error de red: ' + e.message;
        statusEl.style.color = 'red';
        btn.disabled = false;
    }
}

// ── Eliminar una sección ──────────────────────────────────────────────────────
async function deleteOneSection(info, btn) {
    if (!confirm('¿Eliminar sección id=' + info.sectionid + ' con todas sus actividades?')) return;
    btn.disabled = true;
    var statusEl = document.getElementById('sstatus-' + info.sectionid);
    statusEl.textContent = ' Eliminando...';
    try {
        var resp = await fetch(
            BASE + '?action=deletesection&sectionid=' + info.sectionid +
                   '&courseid=' + info.courseid + '&sectionnumber=' + info.sectionnumber +
                   '&sesskey=' + SESS,
            { method: 'POST' }
        );
        var data = await resp.json();
        if (data.ok) {
            statusEl.textContent = ' ✔ ' + data.msg;
            statusEl.style.color = 'green';
            document.getElementById('srow-' + info.sectionid).style.opacity = '0.4';
        } else {
            statusEl.textContent = ' ✘ ' + data.msg;
            statusEl.style.color = 'red';
            btn.disabled = false;
        }
    } catch(e) {
        statusEl.textContent = ' ✘ Error de red: ' + e.message;
        statusEl.style.color = 'red';
        btn.disabled = false;
    }
}

// ── Eliminar todas las secciones huérfanas ────────────────────────────────────
async function deleteAllSections(sections) {
    if (!confirm('¿Eliminar ' + sections.length + ' sección(es) con todas sus actividades?\\nEsta acción no se puede deshacer.')) return;
    showOverlay('Eliminando ' + sections.length + ' secciones...');
    var bar = document.getElementById('prog-bar');
    var counter = document.getElementById('prog-counter');
    var done = 0, errors = 0, total = sections.length;

    for (var i = 0; i < sections.length; i++) {
        var s = sections[i];
        counter.textContent = (i + 1) + ' / ' + total;
        try {
            var resp = await fetch(
                BASE + '?action=deletesection&sectionid=' + s.sectionid +
                       '&courseid=' + s.courseid + '&sectionnumber=' + s.sectionnumber +
                       '&sesskey=' + SESS,
                { method: 'POST' }
            );
            var data = await resp.json();
            logLine(data.msg, data.ok);
            if (data.ok) done++; else errors++;
        } catch(e) {
            logLine('sección ' + s.sectionid + ': Error de red — ' + e.message, false);
            errors++;
        }
        bar.style.width = Math.round(((i + 1) / total) * 100) + '%';
    }

    document.getElementById('prog-title').textContent =
        'Completado: ' + done + ' eliminada(s)' + (errors > 0 ? ', ' + errors + ' error(es)' : '') + '.';
    finishOverlay(errors === 0);
}

// ── Eliminar todos los grupos huérfanos ───────────────────────────────────────
async function deleteAllGroups(groups) {
    if (!confirm('¿Eliminar ' + groups.length + ' grupo(s) huérfano(s)?\\nEsta acción no se puede deshacer.')) return;
    showOverlay('Eliminando ' + groups.length + ' grupos...');
    var bar     = document.getElementById('prog-bar');
    var counter = document.getElementById('prog-counter');
    var done = 0, errors = 0, total = groups.length;

    for (var i = 0; i < groups.length; i++) {
        var g = groups[i];
        counter.textContent = (i + 1) + ' / ' + total;
        try {
            var resp = await fetch(
                BASE + '?action=deletegroup&groupid=' + g.groupid + '&courseid=' + g.courseid + '&sesskey=' + SESS,
                { method: 'POST' }
            );
            var data = await resp.json();
            logLine(data.msg, data.ok);
            if (data.ok) done++; else errors++;
        } catch(e) {
            logLine('grupo ' + g.groupid + ': Error de red — ' + e.message, false);
            errors++;
        }
        bar.style.width = Math.round(((i + 1) / total) * 100) + '%';
    }

    document.getElementById('prog-title').textContent =
        'Completado: ' + done + ' eliminado(s)' + (errors > 0 ? ', ' + errors + ' error(es)' : '') + '.';
    finishOverlay(errors === 0);
}
</script>";

echo $OUTPUT->footer();
