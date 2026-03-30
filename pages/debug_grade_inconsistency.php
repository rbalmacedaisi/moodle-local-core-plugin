<?php
/**
 * debug_grade_inconsistency.php — Diagnóstico de inconsistencias entre gmk_course_progre y nota real del panel
 * ELIMINAR ESTE ARCHIVO DESPUÉS DE USARLO
 *
 * Modos:
 *   - Escaneo global (GET): muestra todos los estudiantes/cursos con inconsistencias
 *   - Detalle individual (POST username): muestra comparación completa para un estudiante
 *
 * @package    local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB;

// ── Constantes ─────────────────────────────────────────────────────────────
define('PASSING_THRESHOLD', 71);

$STATUS_LABELS = [
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

// ── Parámetros de entrada ───────────────────────────────────────────────────
$mode          = $_POST['mode']     ?? $_GET['mode']     ?? 'scan';   // 'scan' | 'student'
$username_input = trim($_POST['username'] ?? '');
$filter_plan   = (int)($_POST['filter_plan']   ?? $_GET['filter_plan']   ?? 0);
$filter_period = (int)($_POST['filter_period'] ?? $_GET['filter_period'] ?? 0);
$show_ok       = !empty($_POST['show_ok'] ?? $_GET['show_ok'] ?? '');
$action        = $_POST['action'] ?? '';

// ── Helpers ─────────────────────────────────────────────────────────────────
function expected_status(float $grade): ?int {
    if ($grade >= PASSING_THRESHOLD) return 4;
    if ($grade > 0)                  return 5;
    return null;
}

function badge_html(string $text, string $type): string {
    $colors = [
        'ok'   => '#155724;background:#d4edda',
        'warn' => '#856404;background:#fff3cd',
        'err'  => '#721c24;background:#f8d7da',
        'info' => '#004085;background:#cce5ff',
        'dup'  => '#4a148c;background:#f3e5f5',
    ];
    $s = $colors[$type] ?? $colors['info'];
    return "<span style='display:inline-block;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;color:{$s}'>{$text}</span>";
}

// ── Bulk-resolución de notas del panel (igual que get_student_learning_plan_pensum) ──────
// Devuelve [userid_courseid => ['grade'=>float,'source'=>string]]
function resolve_panel_grades_bulk(array $useridFilter = []): array {
    global $DB;

    $userWhere  = '';
    $userParams = [];
    if (!empty($useridFilter)) {
        [$inSql, $userParams] = $DB->get_in_or_equal($useridFilter, SQL_PARAMS_NAMED, 'uid');
        $userWhere = "AND gg.userid $inSql";
    }

    $result = [];

    // Priority 3 (lowest): course total BETWEEN 0-100
    $rs = $DB->get_recordset_sql(
        "SELECT gi.courseid, gg.userid,
                MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                         THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval
           FROM {grade_items} gi
      LEFT JOIN {grade_grades} gg ON (gg.itemid = gi.id)
          WHERE gi.itemtype = 'course' $userWhere
       GROUP BY gi.courseid, gg.userid",
        $userParams
    );
    foreach ($rs as $r) {
        if ($r->gradeval === null || $r->userid === null) continue;
        $key = (int)$r->userid . '_' . (int)$r->courseid;
        $result[$key] = ['grade' => round((float)$r->gradeval, 2), 'source' => 'Total del curso'];
    }
    $rs->close();

    // Priority 2: class category grade (gmk_class.gradecategoryid)
    $rs = $DB->get_recordset_sql(
        "SELECT c.corecourseid, gg.userid,
                MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                         THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval
           FROM {gmk_class} c
           JOIN {grade_items} gi ON (gi.courseid = c.corecourseid
                                    AND gi.itemtype = 'category'
                                    AND gi.iteminstance = c.gradecategoryid)
      LEFT JOIN {grade_grades} gg ON (gg.itemid = gi.id)
          WHERE c.gradecategoryid > 0 $userWhere
       GROUP BY c.corecourseid, gg.userid",
        $userParams
    );
    foreach ($rs as $r) {
        if ($r->gradeval === null || $r->userid === null) continue;
        $key = (int)$r->userid . '_' . (int)$r->corecourseid;
        $result[$key] = ['grade' => round((float)$r->gradeval, 2), 'source' => 'Categoría de clase'];
    }
    $rs->close();

    // Priority 1 (highest): Nota Final Integrada
    $rs = $DB->get_recordset_sql(
        "SELECT gi.courseid, gg.userid,
                MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                         THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval
           FROM {grade_items} gi
      LEFT JOIN {grade_grades} gg ON (gg.itemid = gi.id)
          WHERE (gi.itemname LIKE '%Nota Final Integrada%'
                 OR gi.itemname LIKE '%Final Integrada%'
                 OR gi.itemname LIKE '%Nota Final%') $userWhere
       GROUP BY gi.courseid, gg.userid",
        $userParams
    );
    foreach ($rs as $r) {
        if ($r->gradeval === null || $r->userid === null) continue;
        $key = (int)$r->userid . '_' . (int)$r->courseid;
        $result[$key] = ['grade' => round((float)$r->gradeval, 2), 'source' => 'Nota Final Integrada'];
    }
    $rs->close();

    return $result;
}

// Feedback por (userid, courseid)
function get_feedback_bulk(array $useridFilter = []): array {
    global $DB;
    $userWhere  = '';
    $userParams = [];
    if (!empty($useridFilter)) {
        [$inSql, $userParams] = $DB->get_in_or_equal($useridFilter, SQL_PARAMS_NAMED, 'uid');
        $userWhere = "AND gg.userid $inSql";
    }
    $rs = $DB->get_recordset_sql(
        "SELECT gi.courseid, gg.userid, gg.feedback
           FROM {grade_items} gi
           JOIN {grade_grades} gg ON (gg.itemid = gi.id)
          WHERE gi.itemtype = 'course' $userWhere",
        $userParams
    );
    $map = [];
    foreach ($rs as $r) {
        $key = (int)$r->userid . '_' . (int)$r->courseid;
        if (!isset($map[$key])) {
            $map[$key] = $r->feedback;
        }
    }
    $rs->close();
    return $map;
}

// ── Listas para filtros ─────────────────────────────────────────────────────
$plans   = $DB->get_records('local_learning_plans',  [], 'name', 'id,name');
$periods = $DB->get_records('local_learning_periods', [], 'name', 'id,name');

// ══════════════════════════════════════════════════════════════════════════════
// MODO: DETALLE DE UN ESTUDIANTE
// ══════════════════════════════════════════════════════════════════════════════
$student_result = null;
if ($mode === 'student' && $username_input !== '') {
    $uclean = core_text::strtolower($username_input);
    $user   = $DB->get_record('user', ['username' => $uclean, 'deleted' => 0], '*', IGNORE_MISSING);
    if (!$user) {
        $student_result = ['error' => "Usuario <strong>" . htmlspecialchars($uclean) . "</strong> no encontrado."];
    } else {
        $lpu_records = $DB->get_records_sql("
            SELECT lpu.*, lp.name as planname, per.name as currentperiodname
              FROM {local_learning_users} lpu
              JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
         LEFT JOIN {local_learning_periods} per ON per.id = lpu.currentperiodid
             WHERE lpu.userid = ? AND lpu.userrolename = 'student'
        ", [$user->id]);

        $cpWhere  = "gcp.userid = ?";
        $cpParams = [$user->id];
        if ($filter_plan)   { $cpWhere .= " AND gcp.learningplanid = ?"; $cpParams[] = $filter_plan; }
        if ($filter_period) { $cpWhere .= " AND gcp.periodid = ?";       $cpParams[] = $filter_period; }

        $progre_records = $DB->get_records_sql("
            SELECT gcp.*, c.fullname as coursefullname,
                   lp.name as planname, per.name as periodname_from_table
              FROM {gmk_course_progre} gcp
         LEFT JOIN {course} c ON c.id = gcp.courseid
         LEFT JOIN {local_learning_plans} lp ON lp.id = gcp.learningplanid
         LEFT JOIN {local_learning_periods} per ON per.id = gcp.periodid
             WHERE $cpWhere
          ORDER BY gcp.learningplanid, gcp.periodid, c.fullname
        ", $cpParams);

        $panel_grades = resolve_panel_grades_bulk([$user->id]);
        $feedbacks    = get_feedback_bulk([$user->id]);

        $course_plan_count = [];
        foreach ($progre_records as $r) {
            $k = $r->courseid . '_' . $r->learningplanid;
            $course_plan_count[$k] = ($course_plan_count[$k] ?? 0) + 1;
        }

        $rows = [];
        foreach ($progre_records as $rec) {
            $pkey      = (int)$rec->userid . '_' . (int)$rec->courseid;
            $pentry    = $panel_grades[$pkey] ?? null;
            $pgr       = $pentry ? (float)$pentry['grade'] : null;
            $psource   = $pentry ? $pentry['source'] : null;
            $cpgr      = (float)$rec->grade;
            $feedback  = $feedbacks[$pkey] ?? null;
            $diff      = $pgr !== null ? abs($pgr - $cpgr) : null;
            $exp_panel = $pgr !== null ? expected_status($pgr) : null;
            $exp_cp    = $cpgr > 0    ? expected_status($cpgr) : null;
            $is_dup    = ($course_plan_count[$rec->courseid . '_' . $rec->learningplanid] ?? 1) > 1;
            $stat_mis  = $exp_panel !== null && (int)$rec->status !== $exp_panel;
            $note_dif  = $diff !== null && $diff > 0.05;

            if (!$show_ok && !$is_dup && !$stat_mis && !$note_dif) continue;

            $rows[] = compact('rec','pgr','psource','cpgr','diff','feedback','exp_panel','exp_cp','is_dup','stat_mis','note_dif');
        }

        $student_result = [
            'user' => $user, 'lpu' => array_values($lpu_records),
            'rows' => $rows,
            'total_cp'  => count($progre_records),
            'total_inc' => count(array_filter($rows, fn($r) => $r['stat_mis'] || $r['note_dif'] || $r['is_dup'])),
        ];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// CORRECCIÓN MASIVA — se ejecuta ANTES del escaneo para que el scan refleje el estado post-fix
// ══════════════════════════════════════════════════════════════════════════════
$fix_result = null;
if ($action === 'fix' && $mode === 'scan') {
    // Obtener todos los registros elegibles (mismo filtro que el scan)
    $fixWhere  = "gcp.status NOT IN (0, 1, 2, 99)";
    $fixParams = [];
    if ($filter_plan)   { $fixWhere .= " AND gcp.learningplanid = :planid";  $fixParams['planid']   = $filter_plan; }
    if ($filter_period) { $fixWhere .= " AND gcp.periodid = :periodid";      $fixParams['periodid'] = $filter_period; }

    $fix_records = $DB->get_records_sql("
        SELECT gcp.id, gcp.userid, gcp.courseid, gcp.grade, gcp.status
          FROM {gmk_course_progre} gcp
          JOIN {user} u ON u.id = gcp.userid AND u.deleted = 0
         WHERE $fixWhere
    ", $fixParams);

    $fix_userids      = array_unique(array_map(fn($r) => (int)$r->userid, $fix_records));
    $fix_panel_grades = resolve_panel_grades_bulk($fix_userids);

    $fx_updated = 0;
    $fx_skipped = 0;
    $fx_errors  = [];
    $fx_changed = [];  // para mostrar resumen

    foreach ($fix_records as $rec) {
        $pkey   = (int)$rec->userid . '_' . (int)$rec->courseid;
        $pentry = $fix_panel_grades[$pkey] ?? null;
        $pgr    = $pentry ? (float)$pentry['grade'] : null;
        $cpgr   = (float)$rec->grade;

        if ($pgr === null) { $fx_skipped++; continue; }

        $diff     = abs($pgr - $cpgr);
        $exp      = expected_status($pgr);
        $stat_mis = $exp !== null && (int)$rec->status !== $exp;
        $note_dif = $diff > 0.05;

        if (!$stat_mis && !$note_dif) continue;  // sin inconsistencia, ignorar
        if ($exp === null) { $fx_skipped++; continue; }  // nota 0, no aplica

        try {
            $DB->execute(
                "UPDATE {gmk_course_progre} SET grade = ?, status = ? WHERE id = ?",
                [$pgr, $exp, $rec->id]
            );
            $fx_updated++;
            $fx_changed[] = [
                'id'         => $rec->id,
                'userid'     => $rec->userid,
                'courseid'   => $rec->courseid,
                'old_grade'  => $cpgr,
                'new_grade'  => $pgr,
                'old_status' => (int)$rec->status,
                'new_status' => $exp,
            ];
        } catch (Exception $e) {
            $fx_errors[] = 'gmk ID ' . $rec->id . ': ' . $e->getMessage();
        }
    }

    $fix_result = [
        'updated' => $fx_updated,
        'skipped' => $fx_skipped,
        'errors'  => $fx_errors,
        'changed' => $fx_changed,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// MODO: ESCANEO GLOBAL
// ══════════════════════════════════════════════════════════════════════════════
$scan_result = null;
if ($mode === 'scan') {
    $cpWhere  = "1=1";
    $cpParams = [];
    if ($filter_plan)   { $cpWhere .= " AND gcp.learningplanid = :planid";   $cpParams['planid']   = $filter_plan; }
    if ($filter_period) { $cpWhere .= " AND gcp.periodid = :periodid";       $cpParams['periodid'] = $filter_period; }

    // Exclude statuses that can't meaningfully be compared (Disponible, No disponible, Migración Pendiente, Cursando)
    $cpWhere .= " AND gcp.status NOT IN (0, 1, 2, 99)";

    $progre_all = $DB->get_records_sql("
        SELECT gcp.id, gcp.userid, gcp.courseid, gcp.learningplanid, gcp.periodid,
               gcp.grade, gcp.status, gcp.coursename,
               c.fullname as coursefullname,
               u.firstname, u.lastname, u.username, u.email,
               lp.name as planname,
               per.name as periodname
          FROM {gmk_course_progre} gcp
          JOIN {user} u ON u.id = gcp.userid AND u.deleted = 0
     LEFT JOIN {course} c ON c.id = gcp.courseid
     LEFT JOIN {local_learning_plans} lp ON lp.id = gcp.learningplanid
     LEFT JOIN {local_learning_periods} per ON per.id = gcp.periodid
         WHERE $cpWhere
      ORDER BY u.lastname, u.firstname, lp.name, c.fullname
    ", $cpParams);

    // Collect unique user IDs for bulk grade resolution
    $userids = array_unique(array_map(fn($r) => (int)$r->userid, $progre_all));

    $panel_grades = resolve_panel_grades_bulk($userids);
    $feedbacks    = get_feedback_bulk($userids);

    $inconsistencies = [];
    $summary_by_user = [];

    foreach ($progre_all as $rec) {
        $pkey     = (int)$rec->userid . '_' . (int)$rec->courseid;
        $pentry   = $panel_grades[$pkey] ?? null;
        $pgr      = $pentry ? (float)$pentry['grade'] : null;
        $psource  = $pentry ? $pentry['source'] : null;
        $cpgr     = (float)$rec->grade;
        $feedback = $feedbacks[$pkey] ?? null;
        $diff     = $pgr !== null ? abs($pgr - $cpgr) : null;
        $exp      = $pgr !== null ? expected_status($pgr) : null;
        $stat_mis = $exp !== null && (int)$rec->status !== $exp;
        $note_dif = $diff !== null && $diff > 0.05;

        if (!$stat_mis && !$note_dif) continue;

        $uid = (int)$rec->userid;
        if (!isset($summary_by_user[$uid])) {
            $summary_by_user[$uid] = [
                'uid'      => $uid,
                'name'     => $rec->firstname . ' ' . $rec->lastname,
                'username' => $rec->username,
                'email'    => $rec->email,
                'stat_mis' => 0, 'note_dif' => 0, 'total' => 0,
            ];
        }
        if ($stat_mis) $summary_by_user[$uid]['stat_mis']++;
        if ($note_dif) $summary_by_user[$uid]['note_dif']++;
        $summary_by_user[$uid]['total']++;

        $inconsistencies[] = compact('rec','pgr','psource','cpgr','diff','feedback','exp','stat_mis','note_dif');
    }

    $scan_result = [
        'summary'         => array_values($summary_by_user),
        'inconsistencies' => $inconsistencies,
        'total_records'   => count($progre_all),
        'total_inc'       => count($inconsistencies),
        'total_students'  => count($summary_by_user),
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Debug: Inconsistencias de Notas — gmk_course_progre</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #f4f6f9; color: #333; padding: 20px 12px; font-size: 13px; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 20px 24px; max-width: 1400px; margin: 0 auto 16px; }
h1 { font-size: 1.15rem; margin-bottom: 2px; }
h2 { font-size: .95rem; margin-bottom: 10px; color: #444; }
.subtitle { font-size: .8rem; color: #888; margin-bottom: 16px; }
.tabs { display: flex; gap: 4px; margin-bottom: 14px; }
.tab { padding: 7px 18px; border-radius: 6px 6px 0 0; border: 1px solid #dde; background: #f0f2f5; cursor: pointer; font-weight: 600; font-size: .85rem; text-decoration: none; color: #555; }
.tab.active { background: #fff; border-bottom-color: #fff; color: #1a73e8; }
.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 14px; }
.filters label { font-size: .8rem; font-weight: 600; display: block; margin-bottom: 3px; }
.filters select, .filters input[type=text] { padding: 7px 10px; border: 1px solid #ccd; border-radius: 6px; font-size: .85rem; }
.btn { padding: 7px 18px; border: none; border-radius: 6px; font-size: .85rem; font-weight: 600; cursor: pointer; background: #1a73e8; color: #fff; }
.btn:hover { background: #1558b0; }
.btn-sm { padding: 4px 12px; font-size: .78rem; }
.btn-outline { background: #fff; color: #1a73e8; border: 1px solid #1a73e8; }
table { width: 100%; border-collapse: collapse; font-size: .8rem; }
th { background: #f0f2f5; font-weight: 700; text-align: left; padding: 7px 9px; border-bottom: 2px solid #dde; white-space: nowrap; }
td { padding: 6px 9px; border-bottom: 1px solid #eee; vertical-align: top; }
tr:hover td { background: #fafbfd; }
.row-err  td { background: #fff0f0; }
.row-warn td { background: #fffde7; }
.row-both td { background: #fff3e0; }
.row-dup  td { background: #f3e5f5; }
.num { font-family: monospace; }
.stat-card { display: inline-flex; flex-direction: column; align-items: center; padding: 12px 20px; border-radius: 8px; min-width: 120px; margin: 0 6px 6px 0; }
.stat-num { font-size: 1.6rem; font-weight: 700; }
.stat-lbl { font-size: .75rem; color: #555; margin-top: 2px; text-align: center; }
.error-box { background: #fff0f0; border: 1px solid #fcc; border-radius: 6px; padding: 12px 16px; }
.notice { background: #e8f4fd; border-left: 4px solid #1a73e8; padding: 10px 14px; border-radius: 4px; font-size: .82rem; margin-bottom: 12px; }
.delete-notice { font-size: .78rem; color: #c0392b; font-weight: 600; text-align: center; margin-top: 10px; }
.search-box { width: 100%; margin-bottom: 8px; padding: 6px 10px; border: 1px solid #ccd; border-radius: 6px; font-size: .82rem; }
</style>
</head>
<body>

<div class="card">
  <h1>Diagnóstico de Inconsistencias: <code>gmk_course_progre</code> vs Nota Real del Panel</h1>
  <p class="subtitle">Compara la nota almacenada en gmk con la nota que el panel resuelve (categoría de clase / Nota Final Integrada / total 0-100). Excluye cursos con estado: Disponible, No disponible, Cursando, Migración Pendiente.</p>

  <div class="tabs">
    <a class="tab <?= $mode === 'scan' ? 'active' : '' ?>"
       href="?mode=scan&filter_plan=<?= $filter_plan ?>&filter_period=<?= $filter_period ?>">
       Escaneo global
    </a>
    <a class="tab <?= $mode === 'student' ? 'active' : '' ?>"
       href="?mode=student">
       Detalle por estudiante
    </a>
  </div>

  <form method="POST">
    <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
    <div class="filters">
      <div>
        <label>Carrera (plan)</label>
        <select name="filter_plan">
          <option value="0">Todas las carreras</option>
          <?php foreach ($plans as $p): ?>
            <option value="<?= $p->id ?>" <?= $filter_plan == $p->id ? 'selected' : '' ?>>
              <?= htmlspecialchars($p->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Período (cuatrimestre)</label>
        <select name="filter_period">
          <option value="0">Todos los períodos</option>
          <?php foreach ($periods as $p): ?>
            <option value="<?= $p->id ?>" <?= $filter_period == $p->id ? 'selected' : '' ?>>
              <?= htmlspecialchars($p->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($mode === 'student'): ?>
      <div>
        <label>Username / cédula</label>
        <input type="text" name="username" value="<?= htmlspecialchars($username_input) ?>"
               placeholder="Ej: 3-759-782" autocomplete="off">
      </div>
      <div>
        <label style="visibility:hidden">_</label>
        <label style="display:flex;align-items:center;gap:6px;padding:7px 0">
          <input type="checkbox" name="show_ok" value="1" <?= $show_ok ? 'checked' : '' ?>>
          Mostrar también OK
        </label>
      </div>
      <?php endif; ?>
      <div>
        <label style="visibility:hidden">_</label>
        <button type="submit" class="btn"><?= $mode === 'student' ? 'Diagnosticar' : 'Escanear' ?></button>
      </div>
    </div>
  </form>
</div>

<?php
// ══════════════════════════════════════════════════════════════════════════════
// RENDER: ESCANEO GLOBAL
// ══════════════════════════════════════════════════════════════════════════════
if ($mode === 'scan' && $scan_result !== null):
  $sr = $scan_result;
?>

<div class="card">

  <?php if ($fix_result !== null): ?>
  <div style="background:<?= empty($fix_result['errors']) ? '#f0fff4' : '#fff5f5' ?>;border:1px solid <?= empty($fix_result['errors']) ? '#b7ebc4' : '#fcc' ?>;border-radius:6px;padding:14px 18px;margin-bottom:16px">
    <strong><?= empty($fix_result['errors']) ? '✅ Corrección masiva completada' : '⚠ Corrección parcial' ?></strong>
    <div style="margin-top:8px;font-size:.85rem">
      <span style="margin-right:16px">Registros actualizados: <strong><?= $fix_result['updated'] ?></strong></span>
      <span style="margin-right:16px">Sin nota panel (omitidos): <strong><?= $fix_result['skipped'] ?></strong></span>
      <?php if (!empty($fix_result['errors'])): ?>
        <span style="color:#b71c1c">Errores: <strong><?= count($fix_result['errors']) ?></strong></span>
        <ul style="margin-top:6px;padding-left:18px;color:#b71c1c">
          <?php foreach ($fix_result['errors'] as $err): ?>
            <li style="font-size:.8rem"><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php if (!empty($fix_result['changed'])): ?>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;font-size:.8rem;color:#555">Ver detalle de cambios (<?= count($fix_result['changed']) ?>)</summary>
      <table style="margin-top:8px;font-size:.78rem">
        <tr><th>gmk ID</th><th>userid</th><th>courseid</th><th>Nota anterior</th><th>Nota nueva</th><th>Estado anterior</th><th>Estado nuevo</th></tr>
        <?php foreach ($fix_result['changed'] as $ch): ?>
        <tr>
          <td class="num"><?= $ch['id'] ?></td>
          <td class="num"><?= $ch['userid'] ?></td>
          <td class="num"><?= $ch['courseid'] ?></td>
          <td class="num"><?= number_format($ch['old_grade'], 2) ?></td>
          <td class="num"><strong><?= number_format($ch['new_grade'], 2) ?></strong></td>
          <td><?= htmlspecialchars($STATUS_LABELS[$ch['old_status']] ?? '??' . $ch['old_status']) ?></td>
          <td><strong><?= htmlspecialchars($STATUS_LABELS[$ch['new_status']] ?? '??' . $ch['new_status']) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <h2>Resumen del escaneo</h2>
  <div style="margin-bottom:14px">
    <span class="stat-card" style="background:#e8f5e9">
      <span class="stat-num"><?= $sr['total_records'] ?></span>
      <span class="stat-lbl">Registros gmk<br>analizados</span>
    </span>
    <span class="stat-card" style="background:#fff3e0">
      <span class="stat-num"><?= $sr['total_students'] ?></span>
      <span class="stat-lbl">Estudiantes<br>con inconsistencias</span>
    </span>
    <span class="stat-card" style="background:#fff0f0">
      <span class="stat-num"><?= $sr['total_inc'] ?></span>
      <span class="stat-lbl">Registros<br>inconsistentes</span>
    </span>
  </div>

  <?php if ($sr['total_students'] > 0): ?>
  <h2>Estudiantes afectados</h2>
  <input class="search-box" type="text" id="student-search" placeholder="Buscar por nombre, username o email..." oninput="filterStudents(this.value)">
  <table id="student-table">
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Username</th>
        <th>Email</th>
        <th>Estado≠Panel</th>
        <th>Nota≠Panel</th>
        <th>Total</th>
        <th>Acción</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sr['summary'] as $s): ?>
    <tr>
      <td><?= htmlspecialchars($s['name']) ?></td>
      <td class="num"><?= htmlspecialchars($s['username']) ?></td>
      <td><?= htmlspecialchars($s['email']) ?></td>
      <td class="num" style="color:<?= $s['stat_mis'] > 0 ? '#b71c1c' : '#2e7d32' ?>;font-weight:700"><?= $s['stat_mis'] ?></td>
      <td class="num" style="color:<?= $s['note_dif'] > 0 ? '#e65100' : '#2e7d32' ?>;font-weight:700"><?= $s['note_dif'] ?></td>
      <td class="num"><strong><?= $s['total'] ?></strong></td>
      <td>
        <a href="?mode=student&filter_plan=<?= $filter_plan ?>&filter_period=<?= $filter_period ?>"
           onclick="document.querySelector('[name=username]') && (document.querySelector('[name=username]').value='<?= htmlspecialchars($s['username']) ?>')"
           class="btn btn-sm btn-outline"
           target="_self">Ver detalle</a>
        <form method="POST" style="display:inline">
          <input type="hidden" name="mode" value="student">
          <input type="hidden" name="username" value="<?= htmlspecialchars($s['username']) ?>">
          <input type="hidden" name="filter_plan" value="<?= $filter_plan ?>">
          <input type="hidden" name="filter_period" value="<?= $filter_period ?>">
          <button type="submit" class="btn btn-sm btn-outline">Ver detalle</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="color:#2e7d32;font-weight:600;padding:12px 0">✅ No se detectaron inconsistencias con los filtros actuales.</div>
  <?php endif; ?>
</div>

<?php if (!empty($sr['inconsistencies'])): ?>
<div class="card" style="border-left:4px solid #e65100">
  <h2>Corrección masiva</h2>
  <p style="font-size:.85rem;color:#555;margin-bottom:12px">
    Actualiza <code>gmk_course_progre.grade</code> con la nota real del panel y recalcula
    <code>gmk_course_progre.status</code> usando el umbral de aprobación (≥<?= PASSING_THRESHOLD ?>).
    Solo se modifican los <strong><?= $sr['total_inc'] ?></strong> registros inconsistentes donde la nota del panel está disponible.
    Esta operación <strong>no se puede deshacer automáticamente</strong>.
  </p>
  <form method="POST"
        onsubmit="return confirm('¿Ejecutar corrección masiva sobre <?= $sr['total_inc'] ?> registros inconsistentes?\n\nEsta acción actualiza grade y status en gmk_course_progre.\nNo se puede deshacer automáticamente.')">
    <input type="hidden" name="mode" value="scan">
    <input type="hidden" name="action" value="fix">
    <input type="hidden" name="filter_plan" value="<?= $filter_plan ?>">
    <input type="hidden" name="filter_period" value="<?= $filter_period ?>">
    <button type="submit" class="btn" style="background:#e65100">
      Ejecutar corrección masiva (<?= $sr['total_inc'] ?> registros)
    </button>
  </form>
</div>

<div class="card">
  <h2>Detalle de todos los registros inconsistentes</h2>
  <div style="margin-bottom:8px;font-size:.8rem;color:#666">
    <?= badge_html('ESTADO≠PANEL', 'err') ?> Estado en gmk no coincide con lo que la nota real indicaría &nbsp;
    <?= badge_html('NOTA≠PANEL', 'warn') ?> Nota en gmk difiere Δ&gt;0.05 de la nota resuelta del panel
  </div>
  <input class="search-box" type="text" id="detail-search" placeholder="Buscar curso, estudiante..." oninput="filterDetail(this.value)">
  <table id="detail-table">
    <thead>
      <tr>
        <th>Estudiante</th>
        <th>Curso</th>
        <th>Carrera</th>
        <th>Nota gmk<br><small>reporte usa esta</small></th>
        <th>Nota panel (0-100)<br><small>nota real</small></th>
        <th>Fuente</th>
        <th>Δ</th>
        <th>Estado gmk</th>
        <th>Estado correcto<br>(por nota panel)</th>
        <th>Feedback</th>
        <th>Flags</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sr['inconsistencies'] as $row):
      $rec      = $row['rec'];
      $pgr      = $row['pgr'];
      $cpgr     = $row['cpgr'];
      $diff     = $row['diff'];
      $feedback = $row['feedback'];
      $exp      = $row['exp'];

      if ($row['stat_mis'] && $row['note_dif']) $rc = 'row-both';
      elseif ($row['stat_mis'])                 $rc = 'row-err';
      else                                      $rc = 'row-warn';

      $exp_label = $exp !== null ? ($STATUS_LABELS[$exp] ?? '??') : '—';
      $st_label  = $STATUS_LABELS[(int)$rec->status] ?? '??(' . $rec->status . ')';
    ?>
    <tr class="<?= $rc ?>">
      <td><?= htmlspecialchars($rec->firstname . ' ' . $rec->lastname) ?><br>
          <span style="color:#888;font-size:.75rem"><?= htmlspecialchars($rec->username) ?></span></td>
      <td><?= htmlspecialchars($rec->coursefullname ?: $rec->coursename ?: 'ID:' . $rec->courseid) ?></td>
      <td style="font-size:.75rem"><?= htmlspecialchars($rec->planname ?: '—') ?></td>
      <td class="num"><strong><?= number_format($cpgr, 2) ?></strong></td>
      <td class="num"><strong><?= $pgr !== null ? number_format($pgr, 2) : '—' ?></strong></td>
      <td style="font-size:.72rem;color:#666"><?= htmlspecialchars($row['psource'] ?? '—') ?></td>
      <td class="num"><?= $row['note_dif'] ? "<strong style='color:#b71c1c'>" . number_format($diff, 2) . "</strong>" : ($diff !== null ? number_format($diff, 2) : '—') ?></td>
      <td><?= htmlspecialchars($st_label) ?></td>
      <td><?= $row['stat_mis'] ? "<strong style='color:#b71c1c'>" . htmlspecialchars($exp_label) . "</strong>" : htmlspecialchars($exp_label) ?></td>
      <td style="max-width:160px;word-break:break-word;font-size:.72rem"><?= htmlspecialchars($feedback ?? '—') ?></td>
      <td>
        <?= $row['stat_mis'] ? badge_html('ESTADO≠PANEL', 'err')  : '' ?>
        <?= $row['note_dif'] ? badge_html('NOTA≠PANEL',   'warn') : '' ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════════════════════════════
// RENDER: DETALLE DE ESTUDIANTE
// ══════════════════════════════════════════════════════════════════════════════
elseif ($mode === 'student'):
  if ($student_result === null): ?>
  <div class="card">
    <div class="notice">Ingresa un username o cédula y presiona <strong>Diagnosticar</strong>.</div>
  </div>
  <?php elseif (isset($student_result['error'])): ?>
  <div class="card"><div class="error-box"><?= $student_result['error'] ?></div></div>
  <?php else:
    $u    = $student_result['user'];
    $lpu  = $student_result['lpu'];
    $rows = $student_result['rows'];
  ?>
  <div class="card">
    <h2>Estudiante: <?= htmlspecialchars($u->firstname . ' ' . $u->lastname) ?></h2>
    <table style="max-width:580px;margin-bottom:14px">
      <tr><td style="color:#666">ID Moodle</td><td class="num"><?= (int)$u->id ?></td></tr>
      <tr><td style="color:#666">Username</td><td><?= htmlspecialchars($u->username) ?></td></tr>
      <tr><td style="color:#666">Email</td><td><?= htmlspecialchars($u->email) ?></td></tr>
      <tr><td style="color:#666">Registros gmk analizados</td><td class="num"><?= $student_result['total_cp'] ?></td></tr>
      <tr><td style="color:#666">Con inconsistencias</td>
          <td class="num" style="color:<?= $student_result['total_inc'] > 0 ? '#b71c1c' : '#2e7d32' ?>;font-weight:700">
            <?= $student_result['total_inc'] ?>
          </td>
      </tr>
    </table>

    <?php if (!empty($lpu)): ?>
    <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:6px">Matrículas activas</div>
    <table style="max-width:700px;margin-bottom:14px">
      <tr><th>Carrera</th><th>currentperiodid</th><th>Período actual</th><th>Rol</th></tr>
      <?php foreach ($lpu as $l): ?>
      <tr>
        <td><?= htmlspecialchars($l->planname) ?></td>
        <td class="num"><?= (int)$l->currentperiodid ?></td>
        <td><?= htmlspecialchars($l->currentperiodname ?: '—') ?></td>
        <td><?= htmlspecialchars($l->userrolename) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
    <div style="color:#2e7d32;font-weight:600">✅ Sin inconsistencias detectadas<?= $show_ok ? '' : ' (activa "Mostrar también OK" para ver todos los registros)' ?>.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Curso</th>
          <th>Período (gmk)</th>
          <th>Nota gmk<br><small>reporte</small></th>
          <th>Nota panel (0-100)<br><small>nota real</small></th>
          <th>Fuente</th>
          <th>Δ</th>
          <th>Estado gmk</th>
          <th>Estado correcto<br>(por nota panel)</th>
          <th>Feedback</th>
          <th>Flags</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row):
        $rec     = $row['rec'];
        $pgr     = $row['pgr'];
        $cpgr    = $row['cpgr'];

        if ($row['is_dup'])                      $rc = 'row-dup';
        elseif ($row['stat_mis'] && $row['note_dif']) $rc = 'row-both';
        elseif ($row['stat_mis'])                $rc = 'row-err';
        elseif ($row['note_dif'])                $rc = 'row-warn';
        else                                     $rc = '';

        $exp_label = $row['exp_panel'] !== null ? ($STATUS_LABELS[$row['exp_panel']] ?? '??') : '—';
        $st_label  = $STATUS_LABELS[(int)$rec->status] ?? '??(' . $rec->status . ')';
        $diff_str  = $row['diff'] !== null ? number_format($row['diff'], 2) : '—';
      ?>
      <tr class="<?= $rc ?>">
        <td><?= htmlspecialchars($rec->coursefullname ?: $rec->coursename ?: 'ID:' . $rec->courseid) ?></td>
        <td style="font-size:.75rem"><?= htmlspecialchars($rec->periodname_from_table ?: '(id:' . (int)$rec->periodid . ')') ?></td>
        <td class="num"><strong><?= number_format($cpgr, 2) ?></strong></td>
        <td class="num"><strong><?= $pgr !== null ? number_format($pgr, 2) : '—' ?></strong></td>
        <td style="font-size:.72rem;color:#666"><?= htmlspecialchars($row['psource'] ?? '—') ?></td>
        <td class="num"><?= $row['note_dif'] ? "<strong style='color:#b71c1c'>{$diff_str}</strong>" : $diff_str ?></td>
        <td><?= htmlspecialchars($st_label) ?></td>
        <td><?= $row['stat_mis'] ? "<strong style='color:#b71c1c'>" . htmlspecialchars($exp_label) . "</strong>" : htmlspecialchars($exp_label) ?></td>
        <td style="max-width:160px;word-break:break-word;font-size:.75rem"><?= htmlspecialchars($row['feedback'] ?? '—') ?></td>
        <td>
          <?= $row['is_dup']    ? badge_html('DUPLICADO',    'dup')  : '' ?>
          <?= $row['stat_mis']  ? badge_html('ESTADO≠PANEL', 'err')  : '' ?>
          <?= $row['note_dif']  ? badge_html('NOTA≠PANEL',   'warn') : '' ?>
          <?php if (!$row['is_dup'] && !$row['stat_mis'] && !$row['note_dif']): ?>
            <?= badge_html('OK', 'ok') ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>

<p class="delete-notice">⚠ Eliminar este archivo del servidor después de usarlo</p>

<script>
function filterStudents(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#student-table tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
function filterDetail(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#detail-table tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>
