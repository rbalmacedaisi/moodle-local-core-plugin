<?php
// Detecta registros en gmk_course_progre con classid apuntando a una gmk_class
// que ya no existe (clase eliminada). Muestra filtros + checkboxes para correcciÃ³n masiva.
// AcciÃ³n: classid=0, groupid=0, status=COURSE_AVAILABLE (1), grade=0, progress=0
// para los que estaban cursando (status=2). Registros en estado terminal (3/4/5)
// solo se les limpia classid/groupid sin tocar status/grade/progress.

$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);

require_login();
require_capability('moodle/site:config', context_system::instance());

if (!function_exists('get_manual_enroll')) {
    function get_manual_enroll($courseid) {
        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            if ($instance->enrol == 'manual') return $instance;
        }
        return false;
    }
}

/**
 * Envia JSON limpiando cualquier salida previa (debug HTML/warnings) para no romper fetch().
 *
 * @param array $payload
 * @param int $oblevel
 * @return void
 */
function gmk_fix_orphaned_send_json(array $payload, $oblevel = 0) {
    while (ob_get_level() > (int)$oblevel) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
    exit;
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// â”€â”€ Endpoint AJAX: procesa UN registro y devuelve JSON â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'fix_one') {
    $oblevel = ob_get_level();
    ob_start();
    $preverrorhandler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    try {
        require_sesskey();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $id = required_param('id', PARAM_INT);

        $statusLabels = [0=>'No disponible',1=>'Disponible',2=>'Cursando',
                         3=>'Completada',4=>'Aprobada',5=>'Reprobada',6=>'Revalidando'];

        $row = $DB->get_record_sql("
            SELECT gcp.id AS progre_id, gcp.userid, gcp.courseid,
                   gcp.classid AS orphaned_classid, gcp.groupid, gcp.status,
                   u.firstname, u.lastname, c.fullname AS coursename
              FROM {gmk_course_progre} gcp
              JOIN {user}   u   ON u.id  = gcp.userid  AND u.deleted = 0
              JOIN {course} c   ON c.id  = gcp.courseid
         LEFT JOIN {gmk_class} cls ON cls.id = gcp.classid
             WHERE gcp.id = :id AND gcp.classid > 0 AND cls.id IS NULL
        ", ['id' => $id]);

        if (!$row) {
            gmk_fix_orphaned_send_json(['ok' => false, 'msg' => "id=$id: no encontrado o ya corregido."], $oblevel);
        }

        $status     = (int)$row->status;
        $isTerminal = in_array($status, [3, 4, 5]);

        if (!empty($row->groupid) && $DB->record_exists('groups', ['id' => $row->groupid])) {
            if (groups_is_member($row->groupid, $row->userid)) {
                groups_remove_member($row->groupid, $row->userid);
            }
        }

        if (!$isTerminal) {
            $enrolplugin    = enrol_get_plugin('manual');
            $courseInstance = get_manual_enroll($row->courseid);
            if ($enrolplugin && $courseInstance) {
                $enrolplugin->unenrol_user($courseInstance, (int)$row->userid);
            }
        }

        if ($isTerminal) {
            $DB->execute("UPDATE {gmk_course_progre} SET classid=0, groupid=0, timemodified=:now WHERE id=:id",
                         ['now' => time(), 'id' => $id]);
            $desc = 'classid/groupid -> 0 (nota preservada)';
        } else {
            $DB->execute("UPDATE {gmk_course_progre} SET classid=0, groupid=0, status=1, grade=0, progress=0, timemodified=:now WHERE id=:id",
                         ['now' => time(), 'id' => $id]);
            $desc = 'Restablecido a Disponible';
        }

        $DB->delete_records('gmk_class_pre_registration', ['userid' => $row->userid, 'classid' => $row->orphaned_classid]);
        $DB->delete_records('gmk_class_queue',            ['userid' => $row->userid, 'classid' => $row->orphaned_classid]);

        $statusLabel = $statusLabels[$status] ?? "status=$status";
        gmk_fix_orphaned_send_json([
            'ok'  => true,
            'msg' => "{$row->firstname} {$row->lastname} - {$row->coursename} ({$statusLabel}) -> {$desc}",
        ], $oblevel);
    } catch (Throwable $e) {
        gmk_fix_orphaned_send_json(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()], $oblevel);
    } finally {
        if ($preverrorhandler !== null) {
            restore_error_handler();
        }
    }
    exit;
}

$PAGE->set_url('/local/grupomakro_core/pages/fix_orphaned_classid.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Fix: classid huerfano en gmk_course_progre');
$PAGE->set_heading('Correccion: Registros con Clase Eliminada');
echo $OUTPUT->header();

echo '<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 10px; }
  th { background: #1a73e8; color: white; }
  tr:nth-child(even) { background: #f9f9f9; }
  tr.selected { background: #e8f5e9 !important; }
  tr.hidden-row { display: none; }
  .ok   { color: green; font-weight: bold; }
  .err  { color: red; font-weight: bold; }
  .warn { color: orange; font-weight: bold; }
  .section { margin: 22px 0 8px; font-size: 15px; font-weight: bold;
             border-bottom: 2px solid #1a73e8; padding-bottom: 3px; }
  .box { padding: 10px 14px; border-radius: 4px; margin: 8px 0; border: 1px solid; }
  .box.ok   { background:#dfd; border-color:green; }
  .box.err  { background:#fde; border-color:red; }
  .box.warn { background:#fff3cd; border-color:#ffc107; }
  .box.info { background:#e8f0fe; border-color:#1a73e8; }
  button, .btn { padding: 8px 20px; background:#1a73e8; color:white; border:none;
                 border-radius:3px; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block; }
  button:hover, .btn:hover { background:#1558b0; }
  .btn-danger { background:#dc3545; }
  .btn-danger:hover { background:#b02a37; }
  .btn-sm { padding: 5px 12px; font-size: 12px; }
  .tag { font-size:11px; padding:2px 6px; border-radius:3px; color:white; font-weight:bold; }
  .tag-cursando  { background:#e65100; }
  .tag-aprobada  { background:#2e7d32; }
  .tag-reprobada { background:#c62828; }
  .tag-completada{ background:#1565c0; }
  .tag-otro      { background:#555; }
  .cb { width: 18px; height: 18px; cursor: pointer; }
  .filter-bar { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;
                background:#f1f3f4; border:1px solid #dadce0; border-radius:6px;
                padding:12px 16px; margin-bottom:12px; }
  .filter-bar label { font-size:12px; font-weight:bold; color:#555; display:block; margin-bottom:3px; }
  .filter-bar select, .filter-bar input[type=text] {
      padding:6px 10px; border:1px solid #ccc; border-radius:4px;
      font-size:13px; min-width:180px; }
  .filter-counter { font-size:13px; color:#555; align-self:center; margin-left:auto; }
  .filter-counter b { color:#1a73e8; }
</style>';

// â”€â”€ Constantes de status â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
define('STATUS_LABELS', [
    0 => 'No disponible',
    1 => 'Disponible',
    2 => 'Cursando',
    3 => 'Completada',
    4 => 'Aprobada',
    5 => 'Reprobada',
    6 => 'Revalidando',
]);
define('STATUS_TAGS', [
    2 => 'cursando',
    3 => 'completada',
    4 => 'aprobada',
    5 => 'reprobada',
]);

// â”€â”€ Obtener todos los candidatos â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$candidatesSql = "
    SELECT gcp.id        AS progre_id,
           gcp.userid,
           gcp.courseid,
           gcp.classid   AS orphaned_classid,
           gcp.groupid,
           gcp.status,
           gcp.grade,
           gcp.progress,
           gcp.learningplanid,
           gcp.periodid,
           u.firstname,
           u.lastname,
           u.email,
           c.fullname    AS coursename
      FROM {gmk_course_progre} gcp
      JOIN {user}   u ON u.id = gcp.userid   AND u.deleted = 0
      JOIN {course} c ON c.id = gcp.courseid
 LEFT JOIN {gmk_class} cls ON cls.id = gcp.classid
     WHERE gcp.classid > 0
       AND cls.id IS NULL
     ORDER BY u.lastname, u.firstname, c.fullname
";
$candidates = $DB->get_records_sql($candidatesSql);

// â”€â”€ ACCIÃ“N: corregir los seleccionados â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'fix') {
    $selectedIds = optional_param_array('ids', [], PARAM_INT);
    $selectedIds = array_filter($selectedIds, fn($id) => $id > 0);

    if (empty($selectedIds)) {
        echo "<div class='box warn'>No seleccionaste ningun registro.</div>";
        echo "<p><a href='?' class='btn'><- Volver</a></p>";
        echo $OUTPUT->footer();
        exit;
    }

    $candidateMap = [];
    foreach ($candidates as $row) {
        $candidateMap[(int)$row->progre_id] = $row;
    }

    $fixed  = 0;
    $errors = 0;
    $log    = [];

    foreach ($selectedIds as $id) {
        if (!isset($candidateMap[$id])) {
            $log[] = "<span class='err'>[ERR] id=$id no encontrado entre candidatos</span>";
            $errors++;
            continue;
        }
        $row    = $candidateMap[$id];
        $status = (int)$row->status;
        $isTerminal = in_array($status, [3, 4, 5]);

        try {
            // 1. Quitar del grupo Moodle si aÃºn existe.
            if (!empty($row->groupid)) {
                if ($DB->record_exists('groups', ['id' => $row->groupid])) {
                    if (groups_is_member($row->groupid, $row->userid)) {
                        groups_remove_member($row->groupid, $row->userid);
                    }
                }
            }

            // 2. Des-matricular del curso solo si estaba cursando.
            if (!$isTerminal) {
                $enrolplugin    = enrol_get_plugin('manual');
                $courseInstance = get_manual_enroll($row->courseid);
                if ($enrolplugin && $courseInstance) {
                    $enrolplugin->unenrol_user($courseInstance, (int)$row->userid);
                }
            }

            // 3. Actualizar el registro.
            if ($isTerminal) {
                $DB->execute(
                    "UPDATE {gmk_course_progre}
                        SET classid = 0, groupid = 0, timemodified = :now
                      WHERE id = :id",
                    ['now' => time(), 'id' => $id]
                );
                $action_desc = "classid/groupid -> 0 (status {$status} preservado)";
            } else {
                $DB->execute(
                    "UPDATE {gmk_course_progre}
                        SET classid = 0, groupid = 0, status = 1, grade = 0, progress = 0, timemodified = :now
                      WHERE id = :id",
                    ['now' => time(), 'id' => $id]
                );
                $action_desc = "status -> Disponible, classid/groupid/grade/progress -> 0";
            }

            // 4. Limpiar pre_registration y queue.
            $DB->delete_records('gmk_class_pre_registration', ['userid' => $row->userid, 'classid' => $row->orphaned_classid]);
            $DB->delete_records('gmk_class_queue',            ['userid' => $row->userid, 'classid' => $row->orphaned_classid]);

            $fixed++;
            $statusLabel = STATUS_LABELS[$status] ?? "status=$status";
            $log[] = "<span class='ok'>[OK] {$row->firstname} {$row->lastname} - " . htmlspecialchars($row->coursename) . " ({$statusLabel}) -> {$action_desc}</span>";
        } catch (Exception $e) {
            $errors++;
            $log[] = "<span class='err'>[ERR] Error id={$id}: " . htmlspecialchars($e->getMessage()) . "</span>";
        }
    }

    echo "<div class='section'>Resultado de la correccion</div>";
    echo "<div class='box " . ($errors === 0 ? 'ok' : 'warn') . "'>";
    echo "<b>$fixed corregidos</b>" . ($errors > 0 ? ", <b class='err'>$errors errores</b>" : "") . "</div>";
    echo "<div style='font-size:13px;line-height:1.9;'>" . implode('<br>', $log) . "</div>";
    echo "<p style='margin-top:16px;'><a href='?' class='btn'><- Volver al analisis</a></p>";
    echo $OUTPUT->footer();
    exit;
}

// â”€â”€ VISTA â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "<div class='box info'><b>Â¿QuÃ© detecta esta pÃ¡gina?</b><br>
Registros en <code>gmk_course_progre</code> cuyo <code>classid</code> apunta a una <code>gmk_class</code>
que ya <b>no existe</b> (fue eliminada). Esto causa errores al intentar retirar o gestionar al estudiante.<br><br>
<b>AcciÃ³n segÃºn el estado del registro:</b><br>
â€¢ <b>Cursando (2):</b> se des-matricula del curso Moodle, se limpia classid/groupid y se restablece a <em>Disponible</em>.<br>
â€¢ <b>Completada/Aprobada/Reprobada (3/4/5):</b> solo se limpia classid/groupid; se preservan status, nota y progreso.</div>";

$total = count($candidates);

if ($total === 0) {
    echo "<div class='box ok'><b>âœ” No se encontraron registros con classid huÃ©rfano.</b></div>";
    echo $OUTPUT->footer();
    exit;
}

// Resumen por estado y lista de materias Ãºnicas para los filtros.
$byStatus    = [];
$courseNames = [];
foreach ($candidates as $row) {
    $s = (int)$row->status;
    $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
    $courseNames[$row->coursename] = true;
}
ksort($byStatus);
$courseNames = array_keys($courseNames);
sort($courseNames);

echo "<div class='box warn'>âš  Se encontraron <b>$total registros</b> con classid huÃ©rfano.</div>";

echo "<div class='box info'><b>Resumen por estado:</b><br>";
foreach ($byStatus as $s => $cnt) {
    $label = STATUS_LABELS[$s] ?? "status=$s";
    $tag   = STATUS_TAGS[$s]  ?? 'otro';
    echo "<span class='tag tag-$tag'>$label</span> <b>$cnt</b> &nbsp; ";
}
echo "</div>";

echo '<form method="post" action="?action=fix" id="fix-form">';

// â”€â”€ Barra de filtros â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "<div class='filter-bar'>
  <div>
    <label for='filter-status'>Estado</label>
    <select id='filter-status'>
      <option value=''>â€” Todos los estados â€”</option>";
foreach ($byStatus as $s => $cnt) {
    $label = STATUS_LABELS[$s] ?? "status=$s";
    echo "<option value='$s'>$label ($cnt)</option>";
}
echo "    </select>
  </div>
  <div>
    <label for='filter-course'>Materia</label>
    <input type='text' id='filter-course' placeholder='Buscar materia...' autocomplete='off'>
  </div>
  <div>
    <label for='filter-student'>Estudiante</label>
    <input type='text' id='filter-student' placeholder='Buscar estudiante...' autocomplete='off'>
  </div>
  <div style='align-self:flex-end;display:flex;gap:6px;'>
    <button type='button' class='btn btn-sm' style='background:#6c757d' onclick='clearFilters()'>âœ• Limpiar</button>
    <button type='button' class='btn btn-sm' style='background:#28a745' onclick='selectVisible()'>âœ” Sel. visibles</button>
    <button type='button' class='btn btn-sm' style='background:#6c757d' onclick='deselectVisible()'>âœ• Desel. visibles</button>
  </div>
  <div class='filter-counter'>Mostrando <b id='visible-count'>$total</b> de <b>$total</b> &nbsp;|&nbsp; Marcados: <b id='checked-count'>0</b></div>
</div>";

// â”€â”€ Tabla â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
echo "<div class='section' id='table-heading'>Candidatos ($total)</div>";
echo "<table id='candidates-table'>
<thead>
<tr>
  <th><input type='checkbox' id='chk-all' class='cb' title='Marcar/desmarcar visibles'></th>
  <th>#</th><th>Estudiante</th><th>Materia</th>
  <th>classid huÃ©rfano</th><th>groupid</th>
  <th>Estado</th><th>Nota</th><th>Progreso</th>
  <th>AcciÃ³n que se aplicarÃ¡</th>
</tr>
</thead>
<tbody id='candidates-body'>";

$i = 0;
foreach ($candidates as $row) {
    $i++;
    $status      = (int)$row->status;
    $statusLabel = STATUS_LABELS[$status] ?? "status=$status";
    $tag         = STATUS_TAGS[$status]   ?? 'otro';
    $isTerminal  = in_array($status, [3, 4, 5]);
    $courseSafe  = htmlspecialchars($row->coursename);
    $studentName = htmlspecialchars($row->firstname . ' ' . $row->lastname);

    $actionDesc = $isTerminal
        ? "<span class='warn'>Limpiar classid/groupid (preservar nota/status)</span>"
        : "<span class='err'>Restablecer a Disponible (limpiar todo)</span>";

    echo "<tr id='row-{$row->progre_id}'
             data-status='{$status}'
             data-course='" . strtolower($courseSafe) . "'
             data-student='" . strtolower($studentName) . "'>
        <td><input type='checkbox' name='ids[]' value='{$row->progre_id}' class='cb row-cb'></td>
        <td class='row-num'>$i</td>
        <td>{$studentName}<br><small style='color:#666'>uid={$row->userid}</small></td>
        <td>{$courseSafe}<br><small style='color:#666'>cid={$row->courseid}</small></td>
        <td class='err'>{$row->orphaned_classid}</td>
        <td>" . ($row->groupid > 0 ? $row->groupid : '<span style="color:#aaa">0</span>') . "</td>
        <td><span class='tag tag-$tag'>$statusLabel</span></td>
        <td>" . (floatval($row->grade) > 0 ? "<span class='ok'>{$row->grade}</span>" : '<span style="color:#aaa">0</span>') . "</td>
        <td>" . (floatval($row->progress) > 0 ? "{$row->progress}%" : '<span style="color:#aaa">0%</span>') . "</td>
        <td>$actionDesc</td>
    </tr>";
}
echo "</tbody></table>";

echo "<div style='margin-top:12px;display:flex;gap:10px;align-items:center;'>
    <button type='button' class='btn-danger btn' onclick='startFix()'>
        ðŸ”§ Corregir seleccionados
    </button>
    <a href='?' class='btn' style='background:#6c757d'>â†º Reanalizar</a>
</div>
</form>

<!-- Progress overlay -->
<div id='fix-overlay' style='display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center;'>
  <div style='background:#fff;border-radius:8px;padding:28px 32px;width:540px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.3);'>
    <div style='font-size:16px;font-weight:bold;margin-bottom:14px;' id='fix-title'>Corrigiendo registros...</div>

    <div style='background:#e9ecef;border-radius:4px;height:20px;overflow:hidden;margin-bottom:8px;'>
      <div id='fix-bar' style='height:100%;background:#1a73e8;width:0%;transition:width .3s;'></div>
    </div>
    <div style='font-size:13px;color:#555;margin-bottom:12px;' id='fix-counter'>0 / 0</div>

    <div id='fix-log' style='font-size:12px;line-height:1.8;max-height:260px;overflow-y:auto;
         border:1px solid #ddd;border-radius:4px;padding:8px 12px;background:#f8f9fa;'></div>

    <div style='margin-top:16px;display:flex;gap:8px;justify-content:flex-end;'>
      <button id='fix-close-btn' onclick='closeFixOverlay()' class='btn' style='display:none;background:#28a745;'>âœ” Listo â€” Reanalizar</button>
    </div>
  </div>
</div>";

echo '<script>
(function() {
    var filterStatus  = document.getElementById("filter-status");
    var filterCourse  = document.getElementById("filter-course");
    var filterStudent = document.getElementById("filter-student");
    var tbody         = document.getElementById("candidates-body");
    var chkAll        = document.getElementById("chk-all");
    var visibleCount  = document.getElementById("visible-count");
    var checkedCount  = document.getElementById("checked-count");

    function applyFilters() {
        var status  = filterStatus.value;
        var course  = filterCourse.value.trim().toLowerCase();
        var student = filterStudent.value.trim().toLowerCase();
        var rows    = tbody.querySelectorAll("tr[id^=row-]");
        var shown   = 0;
        var n       = 1;

        rows.forEach(function(tr) {
            var matchStatus  = !status  || tr.dataset.status  === status;
            var matchCourse  = !course  || tr.dataset.course.indexOf(course)  !== -1;
            var matchStudent = !student || tr.dataset.student.indexOf(student) !== -1;
            var visible = matchStatus && matchCourse && matchStudent;
            tr.classList.toggle("hidden-row", !visible);
            if (visible) {
                var numCell = tr.querySelector(".row-num");
                if (numCell) numCell.textContent = n++;
                shown++;
            }
        });
        visibleCount.textContent = shown;
        updateCheckedCount();
    }

    function updateCheckedCount() {
        checkedCount.textContent = tbody.querySelectorAll(".row-cb:checked").length;
    }

    filterStatus.addEventListener("change", applyFilters);
    filterCourse.addEventListener("input",  applyFilters);
    filterStudent.addEventListener("input", applyFilters);

    // Marcar/desmarcar solo las filas visibles.
    chkAll.addEventListener("change", function() {
        tbody.querySelectorAll("tr[id^=row-]:not(.hidden-row) .row-cb").forEach(function(cb) {
            cb.checked = chkAll.checked;
            var tr = cb.closest("tr");
            if (tr) tr.classList.toggle("selected", cb.checked);
        });
        updateCheckedCount();
    });

    tbody.querySelectorAll(".row-cb").forEach(function(cb) {
        cb.addEventListener("change", function() {
            var tr = this.closest("tr");
            if (tr) tr.classList.toggle("selected", this.checked);
            updateCheckedCount();
        });
    });

    window.selectVisible = function() {
        tbody.querySelectorAll("tr[id^=row-]:not(.hidden-row) .row-cb").forEach(function(cb) {
            cb.checked = true;
            var tr = cb.closest("tr");
            if (tr) tr.classList.add("selected");
        });
        updateCheckedCount();
    };

    window.deselectVisible = function() {
        tbody.querySelectorAll("tr[id^=row-]:not(.hidden-row) .row-cb").forEach(function(cb) {
            cb.checked = false;
            var tr = cb.closest("tr");
            if (tr) tr.classList.remove("selected");
        });
        updateCheckedCount();
    };

    window.clearFilters = function() {
        filterStatus.value  = "";
        filterCourse.value  = "";
        filterStudent.value = "";
        applyFilters();
    };
})();
</script>';

$sesskey = sesskey();
echo "<script>
var FIX_URL     = window.location.pathname + '?action=fix_one&sesskey={$sesskey}';
var fixOverlay  = document.getElementById('fix-overlay');
var fixBar      = document.getElementById('fix-bar');
var fixCounter  = document.getElementById('fix-counter');
var fixLog      = document.getElementById('fix-log');
var fixTitle    = document.getElementById('fix-title');
var fixCloseBtn = document.getElementById('fix-close-btn');

function appendLog(msg, ok) {
    var line = document.createElement('div');
    line.style.color = ok ? '#2e7d32' : '#c62828';
    line.textContent = (ok ? 'âœ” ' : 'âœ˜ ') + msg;
    fixLog.appendChild(line);
    fixLog.scrollTop = fixLog.scrollHeight;
}

async function startFix() {
    var checked = Array.from(document.querySelectorAll('.row-cb:checked'));
    if (checked.length === 0) { alert('Selecciona al menos un registro.'); return; }
    if (!confirm('Â¿Corregir ' + checked.length + ' registro(s) seleccionado(s)?')) return;

    var ids = checked.map(function(cb) { return parseInt(cb.value); });

    fixOverlay.style.display = 'flex';
    fixBar.style.width = '0%';
    fixBar.style.background = '#1a73e8';
    fixLog.innerHTML = '';
    fixCloseBtn.style.display = 'none';
    fixTitle.textContent = 'Corrigiendo ' + ids.length + ' registro(s)...';

    var done = 0, errors = 0, total = ids.length;

    for (var i = 0; i < ids.length; i++) {
        var id = ids[i];
        fixCounter.textContent = (i + 1) + ' / ' + total;
        try {
            var resp = await fetch(FIX_URL + '&id=' + id, { method: 'POST' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var raw = await resp.text();
            var data;
            try {
                data = JSON.parse(raw);
            } catch (jsonErr) {
                throw new Error('Respuesta no JSON: ' + raw.substring(0, 200).replace(/\\s+/g, ' '));
            }
            appendLog(data.msg, data.ok);
            if (!data.ok) errors++;
            else done++;
        } catch(e) {
            appendLog('id=' + id + ': Error de red â€” ' + e.message, false);
            errors++;
        }
        fixBar.style.width = Math.round(((i + 1) / total) * 100) + '%';
    }

    fixCounter.textContent = total + ' / ' + total;
    fixBar.style.background = errors > 0 ? '#fd7e14' : '#28a745';
    fixTitle.textContent = 'Completado: ' + done + ' corregidos' + (errors > 0 ? ', ' + errors + ' error(es)' : '') + '.';
    fixCloseBtn.style.display = 'inline-block';
}

function closeFixOverlay() {
    fixOverlay.style.display = 'none';
    window.location.href = '?';
}
</script>";

echo $OUTPUT->footer();
