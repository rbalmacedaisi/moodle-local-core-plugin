<?php
// Detecta y corrige estudiantes con status=2 en gmk_course_progre para cursos que
// ya aprobaron (nota >= 70 en el gradebook de Moodle de una clase anterior).
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
</style>';

// ── Consulta principal: status=2 con nota aprobatoria en Moodle ──────────
// Busca en dos fuentes:
//   A) "Nota Final Integrada" (grade_items por nombre)
//   B) Total de categoría de clase (gmk_class.gradecategoryid)
$PASSING = 70.0;

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
            EXISTS (
                SELECT 1
                  FROM {grade_items}  gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = gcp.userid
                 WHERE gi.courseid = gcp.courseid
                   AND (gi.itemname LIKE :nfi1 OR gi.itemname LIKE :nfi2)
                   AND COALESCE(gg.finalgrade, gg.rawgrade) >= :pass1
            )
            OR
            EXISTS (
                SELECT 1
                  FROM {gmk_class}    cls
                  JOIN {grade_items}  gi  ON gi.courseid    = cls.corecourseid
                                        AND gi.itemtype     = 'category'
                                        AND gi.iteminstance = cls.gradecategoryid
                  JOIN {grade_grades} gg  ON gg.itemid = gi.id AND gg.userid = gcp.userid
                 WHERE cls.corecourseid  = gcp.courseid
                   AND cls.learningplanid = gcp.learningplanid
                   AND cls.gradecategoryid > 0
                   AND COALESCE(gg.finalgrade, gg.rawgrade) >= :pass2
            )
       )
     ORDER BY u.lastname, u.firstname, c.fullname
";
$candidates = $DB->get_records_sql($candidatesSql, [
    'nfi1'  => '%Nota Final Integrada%',
    'nfi2'  => '%Final Integrada%',
    'pass1' => $PASSING,
    'pass2' => $PASSING,
]);

// Para cada candidato, obtener la mejor nota disponible en Moodle
function get_best_moodle_grade($DB, $userid, $courseid, $learningplanid, $passing) {
    // Prioridad 1: Nota Final Integrada
    $nfi = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {grade_items}  gi
           JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.courseid = :cid
            AND (gi.itemname LIKE :n1 OR gi.itemname LIKE :n2)
            AND COALESCE(gg.finalgrade, gg.rawgrade) >= :pass",
        ['uid' => $userid, 'cid' => $courseid, 'n1' => '%Nota Final Integrada%',
         'n2' => '%Final Integrada%', 'pass' => $passing]
    );
    if ($nfi !== null && $nfi !== false) return round((float)$nfi, 2);

    // Prioridad 2: Categoría de clase
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
    if ($cat !== null && $cat !== false) return round((float)$cat, 2);

    return null;
}

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
        $grade = get_best_moodle_grade($DB, $row->userid, $row->courseid, $row->learningplanid, $PASSING);
        if ($grade === null) {
            $errors++;
            $log[] = "<span class='err'>✘ No se encontró nota para {$row->firstname} {$row->lastname} — {$row->coursename}</span>";
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
            $log[] = "<span class='ok'>✔ {$row->firstname} {$row->lastname} — {$row->coursename} → status=4, grade={$grade}</span>";
        } catch (Exception $e) {
            $errors++;
            $log[] = "<span class='err'>✘ Error en id={$row->progre_id}: " . $e->getMessage() . "</span>";
        }
    }

    echo "<div class='section'>Resultado de la corrección</div>";
    echo "<div class='box " . ($errors === 0 ? 'ok' : 'warn') . "'>";
    echo "<b>$fixed corregidos</b>" . ($errors > 0 ? ", <b>$errors errores</b>" : "") . "</div>";
    echo "<div style='font-size:13px;line-height:1.8;'>" . implode('<br>', $log) . "</div>";
    echo "<p style='margin-top:16px;'><a href='?' class='btn'>← Volver al análisis</a></p>";
    echo $OUTPUT->footer();
    exit;
}

// ── VISTA: análisis ───────────────────────────────────────────────────────
echo "<div class='box info'><b>¿Qué hace esta página?</b><br>
Busca registros en <code>gmk_course_progre</code> con <code>status=2</code> (en curso) donde
el estudiante ya tiene una nota aprobatoria (≥{$PASSING}) en el gradebook de Moodle
(por \"Nota Final Integrada\" o por categoría de clase anterior).
Esto ocurre cuando <code>assign_class_to_course_progress()</code> re-matricula a un estudiante
en una materia ya aprobada y sobreescribe el status y la nota.</div>";

$total = count($candidates);

if ($total === 0) {
    echo "<div class='box ok'><b>✔ No se encontraron registros con esta anomalía.</b></div>";
    echo $OUTPUT->footer();
    exit;
}

echo "<div class='box warn'>⚠ Se encontraron <b>$total registros</b> con status=2 pero nota aprobatoria en Moodle. Se corregirán a status=4 (Aprobada).</div>";

echo "<div class='section'>Registros a corregir ($total)</div>";
echo "<table>
<tr>
  <th>#</th><th>Estudiante</th><th>Materia (courseid)</th>
  <th>periodid</th><th>classid actual</th>
  <th>Nota en gmk_course_progre</th><th>Nota en Moodle</th><th>Corrección</th>
</tr>";

$i = 0;
foreach ($candidates as $row) {
    $i++;
    $grade = get_best_moodle_grade($DB, $row->userid, $row->courseid, $row->learningplanid, $PASSING);
    $gradeDisplay = $grade !== null
        ? "<span class='ok'>$grade</span>"
        : "<span class='err'>no encontrada</span>";
    $fixDisplay = $grade !== null
        ? "status=2 → <b>4</b>, grade={$row->stored_grade} → <b>$grade</b>, progress → <b>100</b>"
        : "<span class='err'>no se puede corregir</span>";
    echo "<tr>
        <td>$i</td>
        <td>{$row->firstname} {$row->lastname}<br><small>uid={$row->userid}</small></td>
        <td>{$row->coursename}<br><small>cid={$row->courseid}</small></td>
        <td>{$row->periodid}</td>
        <td>{$row->current_classid}</td>
        <td class='err'>{$row->stored_grade}</td>
        <td>$gradeDisplay</td>
        <td>$fixDisplay</td>
    </tr>";
}
echo "</table>";

$fixable   = count(array_filter((array)$candidates, function($r) use ($DB, $PASSING) {
    return get_best_moodle_grade($DB, $r->userid, $r->courseid, $r->learningplanid, $PASSING) !== null;
}));
$unfixable = $total - $fixable;

if ($unfixable > 0) {
    echo "<div class='box warn'>⚠ <b>$unfixable registros</b> no tienen nota en Moodle y no podrán corregirse automáticamente. Requieren revisión manual.</div>";
}

if ($fixable > 0) {
    echo "<form method='get' style='margin-top:16px;'>
        <input type='hidden' name='action' value='fix'>
        <button type='submit' class='btn-danger btn'
            onclick=\"return confirm('¿Corregir $fixable registros? Se actualizarán a status=4 con la nota real del gradebook.')\">
            🔧 Corregir $fixable registros ahora
        </button>
    </form>";
} else {
    echo "<div class='box err'>No hay registros corregibles automáticamente.</div>";
}

echo $OUTPUT->footer();
