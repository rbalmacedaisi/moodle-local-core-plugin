<?php
// Detecta y corrige estudiantes con status=2 en gmk_course_progre para cursos que
// ya aprobaron. Busca la nota en 4 fuentes:
//   1) gmk_course_progre.grade >= 70 (sync_progress guarda exactamente 70, no > 71)
//   2) Total del curso en Moodle (grade_items.itemtype='course') >= 70
//   3) "Nota Final Integrada" en grade_items >= 70
//   4) Total de categoría de clase (gmk_class.gradecategoryid) >= 70
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
</style>';

$PASSING = 70.0;

// ── Obtener la mejor nota disponible en Moodle para un estudiante/curso ───
// Retorna [grade => float|null, source => string]
function get_best_grade_with_source($DB, $userid, $courseid, $learningplanid, $passing) {
    // Fuente 1: gmk_course_progre.grade (sync_progress guarda 70.0 si aprobó)
    $stored = $DB->get_field('gmk_course_progre', 'grade', [
        'userid' => $userid, 'courseid' => $courseid, 'learningplanid' => $learningplanid
    ]);
    if ($stored !== false && $stored !== null && (float)$stored >= $passing) {
        return ['grade' => round((float)$stored, 2), 'source' => 'gmk_course_progre.grade'];
    }

    // Fuente 2: Total del curso en Moodle (itemtype='course') — igual que gmk_get_user_passed_course_map_fast
    $courseTotal = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {grade_items}  gi
           JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.itemtype = 'course' AND gi.courseid = :cid",
        ['uid' => $userid, 'cid' => $courseid]
    );
    if ($courseTotal !== false && $courseTotal !== null && (float)$courseTotal >= $passing) {
        return ['grade' => round((float)$courseTotal, 2), 'source' => 'Moodle course total'];
    }

    // Fuente 3: Nota Final Integrada
    $nfi = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {grade_items}  gi
           JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.courseid = :cid
            AND (gi.itemname LIKE :n1 OR gi.itemname LIKE :n2)
            AND COALESCE(gg.finalgrade, gg.rawgrade) >= :pass",
        ['uid' => $userid, 'cid' => $courseid,
         'n1' => '%Nota Final Integrada%', 'n2' => '%Final Integrada%', 'pass' => $passing]
    );
    if ($nfi !== false && $nfi !== null) {
        return ['grade' => round((float)$nfi, 2), 'source' => 'Nota Final Integrada'];
    }

    // Fuente 4: Categoría de clase (gradecategoryid)
    $cat = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {gmk_class}    cls
           JOIN {grade_items}  gi  ON gi.courseid    = cls.corecourseid
                                  AND gi.itemtype     = 'category'
                                  AND gi.iteminstance = cls.gradecategoryid
           JOIN {grade_grades} gg  ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE cls.corecourseid   = :cid
            AND cls.learningplanid = :lpid
            AND cls.gradecategoryid > 0
            AND COALESCE(gg.finalgrade, gg.rawgrade) >= :pass",
        ['uid' => $userid, 'cid' => $courseid, 'lpid' => $learningplanid, 'pass' => $passing]
    );
    if ($cat !== false && $cat !== null) {
        return ['grade' => round((float)$cat, 2), 'source' => 'class category'];
    }

    return ['grade' => null, 'source' => 'ninguna'];
}

// ── Consulta principal: todos los status=2 con alguna señal de aprobación ─
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
            -- Señal 1: sync_progress ya guardó grade >= 70 pero status no fue actualizado
            gcp.grade >= :stored_pass
            OR
            -- Señal 2: total del curso en Moodle >= 70
            EXISTS (
                SELECT 1
                  FROM {grade_items}  gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = gcp.userid
                 WHERE gi.itemtype = 'course'
                   AND gi.courseid = gcp.courseid
                   AND COALESCE(gg.finalgrade, gg.rawgrade) >= :course_pass
            )
            OR
            -- Señal 3: Nota Final Integrada
            EXISTS (
                SELECT 1
                  FROM {grade_items}  gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = gcp.userid
                 WHERE gi.courseid = gcp.courseid
                   AND (gi.itemname LIKE :nfi1 OR gi.itemname LIKE :nfi2)
                   AND COALESCE(gg.finalgrade, gg.rawgrade) >= :nfi_pass
            )
            OR
            -- Señal 4: categoría de clase
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

// ── ACCIÓN: aplicar corrección ────────────────────────────────────────────
if ($action === 'fix') {
    if (empty($candidates)) {
        echo "<div class='box ok'>No hay registros que corregir.</div>";
        echo "<p><a href='?' class='btn'>← Volver</a></p>";
        echo $OUTPUT->footer();
        exit;
    }

    $fixed = 0;
    $errors = 0;
    $log = [];

    foreach ($candidates as $row) {
        $result = get_best_grade_with_source($DB, $row->userid, $row->courseid, $row->learningplanid, $PASSING);
        $grade  = $result['grade'];
        $source = $result['source'];

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
                ['grade' => $grade, 'now' => time(), 'id' => $row->progre_id]
            );
            $fixed++;
            $log[] = "<span class='ok'>✔ {$row->firstname} {$row->lastname} — {$row->coursename} → status=4, grade={$grade} (fuente: {$source})</span>";
        } catch (Exception $e) {
            $errors++;
            $log[] = "<span class='err'>✘ Error id={$row->progre_id}: " . $e->getMessage() . "</span>";
        }
    }

    echo "<div class='section'>Resultado de la corrección</div>";
    echo "<div class='box " . ($errors === 0 ? 'ok' : 'warn') . "'>";
    echo "<b>$fixed corregidos</b>" . ($errors > 0 ? ", <b class='err'>$errors sin nota</b>" : "") . "</div>";
    echo "<div style='font-size:13px;line-height:1.9;'>" . implode('<br>', $log) . "</div>";
    echo "<p style='margin-top:16px;'><a href='?' class='btn'>← Volver al análisis</a></p>";
    echo $OUTPUT->footer();
    exit;
}

// ── VISTA: análisis ───────────────────────────────────────────────────────
echo "<div class='box info'><b>¿Qué detecta esta página?</b><br>
Registros en <code>gmk_course_progre</code> con <code>status=2</code> donde hay evidencia de aprobación
en al menos una de estas 4 fuentes:
<ol style='margin:6px 0 0 18px;'>
<li><b>gmk_course_progre.grade ≥ 70</b> — sync_progress guardó 70.0 pero no actualizó el status (threshold ≥ 71)</li>
<li><b>Total del curso en Moodle</b> (grade_items.itemtype='course') ≥ 70</li>
<li><b>Nota Final Integrada</b> en grade_grades ≥ 70</li>
<li><b>Categoría de clase</b> (gmk_class.gradecategoryid) en grade_grades ≥ 70</li>
</ol></div>";

$total = count($candidates);

if ($total === 0) {
    echo "<div class='box ok'><b>✔ No se encontraron registros con esta anomalía.</b></div>";

    // Diagnóstico adicional: mostrar cuántos status=2 existen en total
    $totalStatus2 = $DB->count_records('gmk_course_progre', ['status' => 2]);
    echo "<div class='box warn'>Hay <b>$totalStatus2 registros con status=2</b> en total. Si esperas encontrar casos, verifica que las notas están en Moodle o que sync_progress se ejecutó recientemente.</div>";
    echo $OUTPUT->footer();
    exit;
}

echo "<div class='box warn'>⚠ Se encontraron <b>$total registros</b> con status=2 y evidencia de aprobación. Se corregirán a status=4 (Aprobada).</div>";

echo "<div class='section'>Registros a corregir ($total)</div>";
echo "<table>
<tr>
  <th>#</th><th>Estudiante</th><th>Materia</th>
  <th>periodid</th><th>classid</th>
  <th>grade en BD</th><th>Mejor nota encontrada</th><th>Fuente</th>
</tr>";

$i = 0;
$fixable = 0;
foreach ($candidates as $row) {
    $i++;
    $result = get_best_grade_with_source($DB, $row->userid, $row->courseid, $row->learningplanid, $PASSING);
    $grade  = $result['grade'];
    $source = $result['source'];

    if ($grade !== null) {
        $fixable++;
        $gradeHtml  = "<span class='ok'>$grade</span>";
        $sourceHtml = "<span class='source-tag'>{$source}</span>";
        $fixHtml    = "→ status=<b>4</b>, grade=<b>$grade</b>, progress=<b>100</b>";
    } else {
        $gradeHtml  = "<span class='err'>no encontrada</span>";
        $sourceHtml = '';
        $fixHtml    = "<span class='err'>requiere revisión manual</span>";
    }

    echo "<tr>
        <td>$i</td>
        <td>{$row->firstname} {$row->lastname}<br><small style='color:#666'>uid={$row->userid}</small></td>
        <td>{$row->coursename}<br><small style='color:#666'>cid={$row->courseid}</small></td>
        <td>{$row->periodid}</td>
        <td>{$row->current_classid}</td>
        <td class='" . ((float)$row->stored_grade >= $PASSING ? 'ok' : 'err') . "'>{$row->stored_grade}</td>
        <td>$gradeHtml</td>
        <td>$sourceHtml $fixHtml</td>
    </tr>";
}
echo "</table>";

$unfixable = $total - $fixable;
if ($unfixable > 0) {
    echo "<div class='box warn'>⚠ <b>$unfixable registros</b> no tienen nota accesible y no se corregirán automáticamente.</div>";
}

if ($fixable > 0) {
    echo "<form method='get' style='margin-top:16px;'>
        <input type='hidden' name='action' value='fix'>
        <button type='submit' class='btn-danger btn'
            onclick=\"return confirm('¿Corregir $fixable registros? status → 4, grade → valor real, progress → 100')\">
            🔧 Corregir $fixable registros ahora
        </button>
        &nbsp; <a href='?' class='btn' style='background:#6c757d'>↺ Reanalizar</a>
    </form>";
} else {
    echo "<div class='box err'>No hay registros corregibles automáticamente.</div>";
}

echo $OUTPUT->footer();
