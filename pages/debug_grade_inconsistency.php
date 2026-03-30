<?php
/**
 * debug_grade_inconsistency.php — Diagnóstico de inconsistencias entre gmk_course_progre y grade_grades
 * ELIMINAR ESTE ARCHIVO DESPUÉS DE USARLO
 *
 * @package    local_grupomakro_core
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../config.php');
require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB;

$statusLabels = [
    0  => 'No disponible',
    1  => 'Disponible',
    2  => 'Cursando',
    3  => 'Completado',
    4  => 'Aprobada',
    5  => 'Reprobada',
    6  => 'Pendiente Revalida',
    7  => 'Revalidando',
    99 => 'Migración Pendiente',
];

$PASSING_THRESHOLD = 71;

$username_input = trim($_POST['username'] ?? '');
$result = null;

if ($username_input !== '') {
    $username_clean = core_text::strtolower($username_input);
    $user = $DB->get_record('user', ['username' => $username_clean, 'deleted' => 0], '*', IGNORE_MISSING);

    if (!$user) {
        $result = ['error' => "Usuario <strong>" . htmlspecialchars($username_clean) . "</strong> no encontrado."];
    } else {
        // 1. All local_learning_users entries (what plan/period the student is in)
        $lpu_records = $DB->get_records_sql("
            SELECT lpu.*, lp.name as planname, per.name as currentperiodname
              FROM {local_learning_users} lpu
              JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
         LEFT JOIN {local_learning_periods} per ON per.id = lpu.currentperiodid
             WHERE lpu.userid = ? AND lpu.userrolename = 'student'
        ", [$user->id]);

        // 2. All gmk_course_progre records for this student
        $progre_records = $DB->get_records_sql("
            SELECT gcp.*, c.fullname as coursefullname,
                   lp.name as planname, per.name as periodname_from_table
              FROM {gmk_course_progre} gcp
         LEFT JOIN {course} c ON c.id = gcp.courseid
         LEFT JOIN {local_learning_plans} lp ON lp.id = gcp.learningplanid
         LEFT JOIN {local_learning_periods} per ON per.id = gcp.periodid
             WHERE gcp.userid = ?
          ORDER BY gcp.learningplanid, gcp.periodid, c.fullname
        ", [$user->id]);

        // 3. Actual grades from Moodle gradebook (grade_grades + grade_items type=course)
        $gradebook_grades = $DB->get_records_sql("
            SELECT gi.courseid, c.fullname as coursefullname,
                   gg.finalgrade, gg.rawgrade, gg.feedback,
                   gg.timemodified
              FROM {grade_items} gi
              JOIN {grade_grades} gg ON (gg.itemid = gi.id AND gg.userid = ?)
         LEFT JOIN {course} c ON c.id = gi.courseid
             WHERE gi.itemtype = 'course'
          ORDER BY c.fullname
        ", [$user->id]);

        // Index gradebook by courseid for easy lookup
        $gradebook_by_courseid = [];
        foreach ($gradebook_grades as $gg) {
            $gradebook_by_courseid[(int)$gg->courseid] = $gg;
        }

        // 4. Detect duplicates (same courseid + learningplanid appearing more than once)
        $course_plan_count = [];
        foreach ($progre_records as $rec) {
            $key = $rec->courseid . '_' . $rec->learningplanid;
            $course_plan_count[$key] = ($course_plan_count[$key] ?? 0) + 1;
        }

        // 5. Build comparison rows
        $comparison = [];
        foreach ($progre_records as $rec) {
            $gg = $gradebook_by_courseid[(int)$rec->courseid] ?? null;
            $gb_grade = $gg ? (float)$gg->finalgrade : null;
            $cp_grade = (float)$rec->grade;

            $grade_diff = ($gb_grade !== null) ? abs($gb_grade - $cp_grade) : null;

            // What status SHOULD be based on cp.grade
            $expected_status_from_cp = null;
            if ($cp_grade >= $PASSING_THRESHOLD) {
                $expected_status_from_cp = 4; // Aprobada
            } elseif ($cp_grade > 0) {
                $expected_status_from_cp = 5; // Reprobada
            }

            // What status SHOULD be based on gradebook grade
            $expected_status_from_gb = null;
            if ($gb_grade !== null) {
                if ($gb_grade >= $PASSING_THRESHOLD) {
                    $expected_status_from_gb = 4;
                } elseif ($gb_grade > 0) {
                    $expected_status_from_gb = 5;
                }
            }

            $key = $rec->courseid . '_' . $rec->learningplanid;
            $is_duplicate = ($course_plan_count[$key] ?? 1) > 1;

            $comparison[] = [
                'rec'                     => $rec,
                'gb'                      => $gg,
                'gb_grade'                => $gb_grade,
                'cp_grade'                => $cp_grade,
                'grade_diff'              => $grade_diff,
                'status_label'            => $statusLabels[$rec->status] ?? '??',
                'expected_from_cp'        => $expected_status_from_cp,
                'expected_from_gb'        => $expected_status_from_gb,
                'is_duplicate'            => $is_duplicate,
                'status_vs_gb_mismatch'   => ($expected_status_from_gb !== null && (int)$rec->status !== $expected_status_from_gb),
                'grade_significant_diff'  => ($grade_diff !== null && $grade_diff > 0.05),
            ];
        }

        // 6. What the REPORT would show vs PANEL (summary)
        // Report uses: cp.grade, cp.status, lpu.currentperiodid (NOT cp.periodid)
        // Panel resolves: actual gradebook grade + cp.status

        $result = [
            'user'       => $user,
            'lpu'        => array_values($lpu_records),
            'comparison' => $comparison,
            'total_cp'   => count($progre_records),
            'total_gb'   => count($gradebook_grades),
        ];
    }
}

function badge(string $text, string $type): string {
    $colors = [
        'ok'   => '#155724;background:#d4edda',
        'warn' => '#856404;background:#fff3cd',
        'err'  => '#721c24;background:#f8d7da',
        'info' => '#004085;background:#cce5ff',
    ];
    $style = $colors[$type] ?? $colors['info'];
    return "<span style=\"display:inline-block;padding:2px 8px;border-radius:10px;font-size:.78rem;font-weight:600;color:{$style}\">{$text}</span>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Debug Inconsistencias de Notas</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #f4f6f9; color: #333; padding: 24px 16px; font-size: 14px; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 24px 28px; max-width: 1280px; margin: 0 auto 20px; }
h1 { font-size: 1.2rem; margin-bottom: 4px; }
h2 { font-size: 1rem; margin-bottom: 12px; color: #444; }
.subtitle { font-size: .82rem; color: #888; margin-bottom: 20px; }
label { font-size: .85rem; font-weight: 600; display: block; margin-bottom: 6px; }
input[type=text] { width: 300px; padding: 9px 12px; border: 1px solid #ccd; border-radius: 6px; font-size: .95rem; }
.btn { padding: 9px 20px; border: none; border-radius: 6px; font-size: .9rem; font-weight: 600; cursor: pointer; background: #4f8ef7; color: #fff; }
.btn:hover { background: #3a7ce0; }
table { width: 100%; border-collapse: collapse; font-size: .82rem; }
th { background: #f0f2f5; font-weight: 700; text-align: left; padding: 8px 10px; border-bottom: 2px solid #dde; white-space: nowrap; }
td { padding: 7px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
tr:hover td { background: #fafbfd; }
.row-warn td { background: #fffde7; }
.row-err  td { background: #fff0f0; }
.row-dup  td { background: #f3e5f5; }
.section-title { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #888; margin: 16px 0 8px; }
.error-box { background: #fff0f0; border: 1px solid #fcc; border-radius: 6px; padding: 12px 16px; }
.legend { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; font-size: .8rem; }
.legend-item { display: flex; align-items: center; gap: 6px; }
.legend-color { width: 14px; height: 14px; border-radius: 3px; }
.delete-notice { font-size: .78rem; color: #c0392b; font-weight: 600; text-align: center; margin-top: 12px; }
.num { font-family: monospace; }
</style>
</head>
<body>

<div class="card">
  <h1>Diagnóstico de Inconsistencias de Notas</h1>
  <p class="subtitle">Compara <code>gmk_course_progre</code> (fuente del reporte) con <code>grade_grades</code> (fuente real del gradebook) para identificar discrepancias.</p>
  <form method="POST">
    <label for="username">Usuario (cédula / username):</label>
    <input type="text" id="username" name="username"
           placeholder="Ej: 3-759-782"
           value="<?= htmlspecialchars($username_input) ?>"
           autocomplete="off" autofocus>
    <button type="submit" class="btn" style="margin-left:10px">Diagnosticar</button>
  </form>
</div>

<?php if ($result !== null): ?>

<?php if (isset($result['error'])): ?>
  <div class="card"><div class="error-box"><?= $result['error'] ?></div></div>

<?php else:
  $u   = $result['user'];
  $lpu = $result['lpu'];
  $cmp = $result['comparison'];

  $has_grade_diff  = count(array_filter($cmp, fn($r) => $r['grade_significant_diff']));
  $has_status_mis  = count(array_filter($cmp, fn($r) => $r['status_vs_gb_mismatch']));
  $has_duplicates  = count(array_filter($cmp, fn($r) => $r['is_duplicate']));
?>

<div class="card">
  <h2>Información del estudiante</h2>
  <table style="max-width:640px">
    <tr><th>Campo</th><th>Valor</th></tr>
    <tr><td>ID Moodle</td><td class="num"><?= (int)$u->id ?></td></tr>
    <tr><td>Username</td><td><?= htmlspecialchars($u->username) ?></td></tr>
    <tr><td>Nombre</td><td><?= htmlspecialchars($u->firstname . ' ' . $u->lastname) ?></td></tr>
    <tr><td>Email</td><td><?= htmlspecialchars($u->email) ?></td></tr>
    <tr><td>Registros en gmk_course_progre</td><td class="num"><?= $result['total_cp'] ?></td></tr>
    <tr><td>Cursos en grade_grades (gradebook)</td><td class="num"><?= $result['total_gb'] ?></td></tr>
  </table>

  <?php if (!empty($lpu)): ?>
  <div class="section-title">Matrículas (local_learning_users) — fuente del "Cuatrimestre" en el reporte</div>
  <table>
    <tr>
      <th>Plan / Carrera</th>
      <th>Cuatrimestre actual (currentperiodid)</th>
      <th>Período actual (nombre)</th>
      <th>Rol</th>
    </tr>
    <?php foreach ($lpu as $l): ?>
    <tr>
      <td><?= htmlspecialchars($l->planname) ?></td>
      <td class="num"><?= (int)$l->currentperiodid ?></td>
      <td><?= htmlspecialchars($l->currentperiodname ?: '—') ?></td>
      <td><?= htmlspecialchars($l->userrolename) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="font-size:.8rem;color:#666;margin-top:6px">
    ⚠ El reporte usa <strong>lpu.currentperiodid</strong> para el "Cuatrimestre", no el <code>periodid</code> de cada curso en gmk_course_progre.
    Si el estudiante está en Cuatrimestre 3 pero sus cursos migrados tienen periodid de Cuatrimestre 1, el reporte
    <strong>muestra todos los cursos bajo el período actual del estudiante</strong>.
  </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Comparación por curso: gmk_course_progre vs grade_grades</h2>

  <div class="legend">
    <div class="legend-item"><div class="legend-color" style="background:#fff0f0"></div> Estado de gmk difiere del gradebook real</div>
    <div class="legend-item"><div class="legend-color" style="background:#fffde7"></div> Nota significativamente diferente entre tablas</div>
    <div class="legend-item"><div class="legend-color" style="background:#f3e5f5"></div> Registro duplicado (mismo curso + carrera)</div>
  </div>

  <?php if ($has_duplicates > 0): ?>
  <p style="color:#8e24aa;font-weight:600;margin-bottom:10px">
    ⚠ Se detectaron <?= $has_duplicates ?> registro(s) duplicado(s) en gmk_course_progre para el mismo curso + carrera.
    Esto puede causar que el reporte muestre filas repetidas con datos de migración incorrectos.
  </p>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Curso</th>
        <th>Carrera (plan)</th>
        <th>Period ID (gmk)</th>
        <th>Período (gmk)</th>
        <th>Nota gmk<br><small>← usa el reporte</small></th>
        <th>Nota gradebook<br><small>← usa el panel</small></th>
        <th>Diferencia</th>
        <th>Estado gmk</th>
        <th>Estado esperado<br>(por nota gmk)</th>
        <th>Estado esperado<br>(por nota gradebook)</th>
        <th>Feedback (gradebook)</th>
        <th>Flags</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($cmp as $row):
      $rec = $row['rec'];
      $gg  = $row['gb'];

      $rowClass = '';
      if ($row['is_duplicate'])          $rowClass = 'row-dup';
      elseif ($row['status_vs_gb_mismatch']) $rowClass = 'row-err';
      elseif ($row['grade_significant_diff']) $rowClass = 'row-warn';

      $cp_note = number_format($row['cp_grade'], 2);
      $gb_note = $row['gb_grade'] !== null ? number_format($row['gb_grade'], 2) : '—';

      $diff_str = '—';
      if ($row['grade_diff'] !== null) {
          $diff_str = number_format($row['grade_diff'], 2);
      }

      $status_label      = $statusLabels[$rec->status] ?? '??(' . $rec->status . ')';
      $exp_cp_label      = $row['expected_from_cp'] !== null ? ($statusLabels[$row['expected_from_cp']] ?? '??') : '—';
      $exp_gb_label      = $row['expected_from_gb'] !== null ? ($statusLabels[$row['expected_from_gb']] ?? '??') : '—';

      $status_ok = ((int)$rec->status === ($row['expected_from_gb'] ?? $rec->status));
    ?>
    <tr class="<?= $rowClass ?>">
      <td><?= htmlspecialchars($rec->coursefullname ?: $rec->coursename ?: '(ID: ' . $rec->courseid . ')') ?></td>
      <td><?= htmlspecialchars($rec->planname ?: '—') ?></td>
      <td class="num"><?= (int)$rec->periodid ?></td>
      <td><?= htmlspecialchars($rec->periodname_from_table ?: '—') ?></td>
      <td class="num"><strong><?= $cp_note ?></strong></td>
      <td class="num"><strong><?= $gb_note ?></strong></td>
      <td class="num"><?= $row['grade_significant_diff'] ? "<strong style='color:#b71c1c'>{$diff_str}</strong>" : $diff_str ?></td>
      <td><?= htmlspecialchars($status_label) ?></td>
      <td><?= htmlspecialchars($exp_cp_label) ?></td>
      <td><?= $row['status_vs_gb_mismatch']
              ? "<strong style='color:#b71c1c'>" . htmlspecialchars($exp_gb_label) . '</strong>'
              : htmlspecialchars($exp_gb_label) ?>
      </td>
      <td style="max-width:200px;word-break:break-word"><?= htmlspecialchars($gg->feedback ?? '—') ?></td>
      <td>
        <?= $row['is_duplicate']          ? badge('DUPLICADO', 'err')  : '' ?>
        <?= $row['status_vs_gb_mismatch'] ? badge('ESTADO↔GB', 'err')  : '' ?>
        <?= $row['grade_significant_diff']? badge('NOTA≠GB',   'warn') : '' ?>
        <?php if (!$row['is_duplicate'] && !$row['status_vs_gb_mismatch'] && !$row['grade_significant_diff']): ?>
          <?= badge('OK', 'ok') ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Resumen del diagnóstico</h2>
  <table style="max-width:600px">
    <tr><th>Problema</th><th>Cantidad</th><th>Impacto</th></tr>
    <tr>
      <td>Nota en gmk_course_progre ≠ nota en gradebook (Δ > 0.05)</td>
      <td class="num"><?= $has_grade_diff ?></td>
      <td>El reporte muestra nota distinta a la que el panel usa</td>
    </tr>
    <tr>
      <td>Estado en gmk ≠ estado esperado por nota del gradebook</td>
      <td class="num"><?= $has_status_mis ?></td>
      <td>El estado (Aprobada/Reprobada) del reporte no corresponde a la nota real</td>
    </tr>
    <tr>
      <td>Registros duplicados (mismo curso + carrera)</td>
      <td class="num"><?= $has_duplicates ?></td>
      <td>El reporte genera filas repetidas con datos contradictorios</td>
    </tr>
  </table>

  <div style="margin-top:16px;padding:14px;background:#e8f5e9;border-radius:6px;font-size:.84rem">
    <strong>Causa raíz probable:</strong>
    La migración Q10 importó notas como 70.00 con <em>feedback "Migracion Q10 - Aprobado"</em>, pero
    <code>import_grades.php</code> recalculó el estado usando umbral <strong>≥ 71 = Aprobada</strong>,
    por lo que cursos con nota 70 quedaron marcados como <strong>Reprobada</strong> en gmk_course_progre,
    aunque en Q10 el estudiante estaba aprobado (Q10 usa ≥ 70).
    El panel del administrador muestra la nota actual del gradebook (nota real ≠ 70) y el estado de gmk
    puede diferir. El reporte usa <code>cp.grade</code> (la nota migrada 70) y <code>lpu.currentperiodid</code>
    (período actual del estudiante, no el período por curso), produciendo datos incorrectos.
  </div>
</div>

<?php endif; ?>
<?php endif; ?>

<p class="delete-notice">⚠ Eliminar este archivo del servidor después de usarlo</p>
</body>
</html>
