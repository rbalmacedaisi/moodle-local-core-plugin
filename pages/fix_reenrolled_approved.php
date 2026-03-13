<?php
// Detecta estudiantes con status=2 en gmk_course_progre que posiblemente ya aprobaron
// la materia. Muestra checkboxes para que el admin seleccione cuáles corregir.
// La nota a guardar se toma de Moodle (real), NO del valor binario 70/0 de sync_progress.
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/fix_reenrolled_approved.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Fix: Re-matriculados en materia aprobada');
$PAGE->set_heading('Corrección: Re-matriculados en Materia Aprobada');
echo $OUTPUT->header();

$action = optional_param('action', '', PARAM_ALPHA);

echo '<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 10px; }
  th { background: #1a73e8; color: white; }
  tr:nth-child(even) { background: #f9f9f9; }
  tr.selected { background: #e8f5e9 !important; }
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
  .source-tag { font-size:11px; background:#555; color:white; padding:1px 5px; border-radius:3px; }
  .cb { width: 18px; height: 18px; cursor: pointer; }
</style>';

$PASSING = 70.0;

// ── Obtener la nota REAL de Moodle (no el 70/0 de sync_progress) ──────────
// Prioridad: course total > NFI > class category
// Retorna [grade => float|null, source => string]
function get_real_moodle_grade($DB, $userid, $courseid, $learningplanid) {
    // 1) Total del curso en Moodle (grade_items.itemtype='course')
    $courseTotal = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {grade_items}  gi
           JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.itemtype = 'course' AND gi.courseid = :cid",
        ['uid' => $userid, 'cid' => $courseid]
    );
    if ($courseTotal !== false && $courseTotal !== null && (float)$courseTotal > 0) {
        return ['grade' => round((float)$courseTotal, 2), 'source' => 'Moodle course total'];
    }

    // 2) Nota Final Integrada
    $nfi = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {grade_items}  gi
           JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.courseid = :cid
            AND (gi.itemname LIKE :n1 OR gi.itemname LIKE :n2)",
        ['uid' => $userid, 'cid' => $courseid,
         'n1' => '%Nota Final Integrada%', 'n2' => '%Final Integrada%']
    );
    if ($nfi !== false && $nfi !== null && (float)$nfi > 0) {
        return ['grade' => round((float)$nfi, 2), 'source' => 'Nota Final Integrada'];
    }

    // 3) Categoría de clase
    $cat = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {gmk_class}    cls
           JOIN {grade_items}  gi  ON gi.courseid    = cls.corecourseid
                                  AND gi.itemtype     = 'category'
                                  AND gi.iteminstance = cls.gradecategoryid
           JOIN {grade_grades} gg  ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE cls.corecourseid   = :cid
            AND cls.learningplanid = :lpid
            AND cls.gradecategoryid > 0",
        ['uid' => $userid, 'cid' => $courseid, 'lpid' => $learningplanid]
    );
    if ($cat !== false && $cat !== null && (float)$cat > 0) {
        return ['grade' => round((float)$cat, 2), 'source' => 'class category'];
    }

    return ['grade' => null, 'source' => 'no encontrada'];
}

// ── Candidatos: status=2 con señal de aprobación ──────────────────────────
// La señal viene de gmk_course_progre.grade >= 70 (sync lo pone en 70 si pasó)
// O de grade_items itemtype='course' >= 70 en Moodle
$candidatesSql = "
    SELECT DISTINCT
           gcp.id          AS progre_id,
           gcp.userid,
           gcp.courseid,
           gcp.classid     AS current_classid,
           gcp.learningplanid,
           gcp.periodid,
           gcp.grade       AS stored_grade,
           u.firstname,
           u.lastname,
           c.fullname      AS coursename
      FROM {gmk_course_progre} gcp
      JOIN {user}   u ON u.id = gcp.userid   AND u.deleted = 0
      JOIN {course} c ON c.id = gcp.courseid
     WHERE gcp.status = 2
       AND (
            gcp.grade >= :stored_pass
            OR
            EXISTS (
                SELECT 1
                  FROM {grade_items}  gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = gcp.userid
                 WHERE gi.itemtype = 'course'
                   AND gi.courseid = gcp.courseid
                   AND COALESCE(gg.finalgrade, gg.rawgrade) >= :course_pass
            )
            OR
            EXISTS (
                SELECT 1
                  FROM {grade_items}  gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = gcp.userid
                 WHERE gi.courseid = gcp.courseid
                   AND (gi.itemname LIKE :nfi1 OR gi.itemname LIKE :nfi2)
                   AND COALESCE(gg.finalgrade, gg.rawgrade) >= :nfi_pass
            )
            OR
            EXISTS (
                SELECT 1
                  FROM {gmk_class}    cls
                  JOIN {grade_items}  gi  ON gi.courseid    = cls.corecourseid
                                        AND gi.itemtype     = 'category'
                                        AND gi.iteminstance = cls.gradecategoryid
                  JOIN {grade_grades} gg  ON gg.itemid = gi.id AND gg.userid = gcp.userid
                 WHERE cls.corecourseid   = gcp.courseid
                   AND cls.learningplanid = gcp.learningplanid
                   AND cls.gradecategoryid > 0
                   AND COALESCE(gg.finalgrade, gg.rawgrade) >= :cat_pass
            )
       )
     ORDER BY u.lastname, u.firstname, c.fullname
";
$candidates = $DB->get_records_sql($candidatesSql, [
    'stored_pass' => $PASSING,
    'course_pass' => $PASSING,
    'nfi1'        => '%Nota Final Integrada%',
    'nfi2'        => '%Final Integrada%',
    'nfi_pass'    => $PASSING,
    'cat_pass'    => $PASSING,
]);

// ── ACCIÓN: corregir los seleccionados ────────────────────────────────────
if ($action === 'fix') {
    $selectedIds = optional_param_array('ids', [], PARAM_INT);
    $selectedIds = array_filter($selectedIds, fn($id) => $id > 0);

    if (empty($selectedIds)) {
        echo "<div class='box warn'>No seleccionaste ningún registro.</div>";
        echo "<p><a href='?' class='btn'>← Volver</a></p>";
        echo $OUTPUT->footer();
        exit;
    }

    // Construir mapa id → row desde los candidatos
    $candidateMap = [];
    foreach ($candidates as $row) {
        $candidateMap[(int)$row->progre_id] = $row;
    }

    $fixed = 0;
    $errors = 0;
    $log = [];

    foreach ($selectedIds as $id) {
        if (!isset($candidateMap[$id])) {
            $log[] = "<span class='err'>✘ id=$id no encontrado en candidatos</span>";
            $errors++;
            continue;
        }
        $row    = $candidateMap[$id];
        $result = get_real_moodle_grade($DB, $row->userid, $row->courseid, $row->learningplanid);
        $grade  = $result['grade'];
        $source = $result['source'];

        // Si Moodle no tiene nota real, usar el stored_grade (70.0 de sync)
        if ($grade === null && (float)$row->stored_grade >= $PASSING) {
            $grade  = (float)$row->stored_grade;
            $source = 'gmk_course_progre.grade (sync)';
        }

        if ($grade === null) {
            $errors++;
            $log[] = "<span class='err'>✘ Sin nota para {$row->firstname} {$row->lastname} — {$row->coursename}</span>";
            continue;
        }

        try {
            $DB->execute(
                "UPDATE {gmk_course_progre}
                    SET status = 4, grade = :grade, progress = 100, timemodified = :now
                  WHERE id = :id",
                ['grade' => $grade, 'now' => time(), 'id' => $id]
            );
            $fixed++;
            $log[] = "<span class='ok'>✔ {$row->firstname} {$row->lastname} — {$row->coursename} → status=4, grade={$grade} (fuente: {$source})</span>";
        } catch (Exception $e) {
            $errors++;
            $log[] = "<span class='err'>✘ Error id={$id}: " . $e->getMessage() . "</span>";
        }
    }

    echo "<div class='section'>Resultado de la corrección</div>";
    echo "<div class='box " . ($errors === 0 ? 'ok' : 'warn') . "'>";
    echo "<b>$fixed corregidos</b>" . ($errors > 0 ? ", <b class='err'>$errors errores</b>" : "") . "</div>";
    echo "<div style='font-size:13px;line-height:1.9;'>" . implode('<br>', $log) . "</div>";
    echo "<p style='margin-top:16px;'><a href='?' class='btn'>← Volver al análisis</a></p>";
    echo $OUTPUT->footer();
    exit;
}

// ── VISTA: tabla con checkboxes ───────────────────────────────────────────
echo "<div class='box info'><b>¿Qué detecta esta página?</b><br>
Registros <code>status=2</code> en <code>gmk_course_progre</code> donde hay señal de aprobación previa.<br>
<b>Revisa cada fila</b> y marca solo las que realmente deben corregirse (desactiva los falsos positivos).
<br><br>
⚠ La nota a guardar es la <b>nota real de Moodle</b> (course total, NFI o categoría), NO el valor binario 70 de sync_progress.</div>";

$total = count($candidates);

if ($total === 0) {
    echo "<div class='box ok'><b>✔ No se encontraron registros con esta anomalía.</b></div>";
    $totalStatus2 = $DB->count_records('gmk_course_progre', ['status' => 2]);
    echo "<div class='box warn'>Hay <b>$totalStatus2 registros con status=2</b> en total. Si esperas encontrar casos, verifica que las notas estén en Moodle o que sync_progress se haya ejecutado.</div>";
    echo $OUTPUT->footer();
    exit;
}

echo "<div class='box warn'>⚠ Se encontraron <b>$total candidatos</b>. <b>Selecciona solo los que deben corregirse</b> y presiona el botón.</div>";

echo '<form method="post" action="?action=fix">';
echo "<div class='section'>Candidatos a revisar ($total)</div>";
echo "<table>
<tr>
  <th><input type='checkbox' id='chk-all' class='cb' title='Marcar/desmarcar todos'></th>
  <th>#</th><th>Estudiante</th><th>Materia</th>
  <th>periodid</th><th>classid</th>
  <th>grade en BD<br><small>(sync binario)</small></th>
  <th>Nota real en Moodle</th><th>Fuente</th>
</tr>";

$i = 0;
foreach ($candidates as $row) {
    $i++;
    $result = get_real_moodle_grade($DB, $row->userid, $row->courseid, $row->learningplanid);
    $grade  = $result['grade'];
    $source = $result['source'];

    // Nota que se usará al corregir (Moodle real, o stored como fallback)
    if ($grade !== null) {
        $correctionGrade = $grade;
        $correctionSource = $source;
    } elseif ((float)$row->stored_grade >= $PASSING) {
        $correctionGrade = (float)$row->stored_grade;
        $correctionSource = 'gmk_course_progre.grade (sync)';
    } else {
        $correctionGrade = null;
        $correctionSource = 'sin nota';
    }

    $gradeHtml = $correctionGrade !== null
        ? "<span class='ok'>$correctionGrade</span>"
        : "<span class='err'>no encontrada</span>";
    $sourceHtml = "<span class='source-tag'>" . htmlspecialchars($correctionSource) . "</span>";
    $canFix = $correctionGrade !== null;

    echo "<tr id='row-{$row->progre_id}'>
        <td><input type='checkbox' name='ids[]' value='{$row->progre_id}' class='cb row-cb' "
            . ($canFix ? '' : 'disabled') . "></td>
        <td>$i</td>
        <td>{$row->firstname} {$row->lastname}<br><small style='color:#666'>uid={$row->userid}</small></td>
        <td>{$row->coursename}<br><small style='color:#666'>cid={$row->courseid}</small></td>
        <td>{$row->periodid}</td>
        <td>{$row->current_classid}</td>
        <td class='" . ((float)$row->stored_grade >= $PASSING ? 'warn' : 'err') . "'>{$row->stored_grade}</td>
        <td>$gradeHtml</td>
        <td>$sourceHtml</td>
    </tr>";
}
echo "</table>";

echo "<div style='margin-top:12px;display:flex;gap:10px;align-items:center;'>
    <button type='submit' class='btn-danger btn'
        onclick=\"var n=document.querySelectorAll('.row-cb:checked').length;
                 if(n===0){alert('Selecciona al menos un registro.');return false;}
                 return confirm('¿Corregir '+n+' registro(s) seleccionado(s)? status → 4, grade → nota real, progress → 100');\">
        🔧 Corregir seleccionados
    </button>
    <a href='?' class='btn' style='background:#6c757d'>↺ Reanalizar</a>
</div>
</form>";

echo '<script>
document.getElementById("chk-all").addEventListener("change", function() {
    document.querySelectorAll(".row-cb:not(:disabled)").forEach(cb => cb.checked = this.checked);
    document.querySelectorAll("tr[id^=row-]").forEach(tr => {
        var cb = tr.querySelector(".row-cb");
        if (cb && !cb.disabled) tr.classList.toggle("selected", this.checked);
    });
});
document.querySelectorAll(".row-cb").forEach(cb => {
    cb.addEventListener("change", function() {
        var tr = this.closest("tr");
        if (tr) tr.classList.toggle("selected", this.checked);
    });
});
</script>';

echo $OUTPUT->footer();
