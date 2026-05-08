<?php
/**
 * Debug page: compare all grade sources used (or candidates) in the Excel export.
 *
 * For each gmk_course_progre record shows:
 *   A) cp.grade              → current export value
 *   B) grade_grades course   → Moodle course-total (itemtype='course')
 *   C) grade_grades category → gmk_class category grade (via groupid → gmk_class.gradecategoryid)
 *   D) gmk_class.corecourseid / gradecategoryid metadata
 *
 * Access: /local/grupomakro_core/pages/debug_export_grades.php
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_export_grades.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Export Grades');

// ── params ──────────────────────────────────────────────────────────────────
$planid   = optional_param('planid',   '', PARAM_RAW);
$periodid = optional_param('periodid', '', PARAM_RAW);
$search   = optional_param('search',   '', PARAM_TEXT);
$limit    = optional_param('limit',    100, PARAM_INT);

global $DB;

// ── helpers ──────────────────────────────────────────────────────────────────
function xh($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }

function fmt_grade($v) {
    if ($v === null || $v === false || $v === '') return '<span style="color:#999">—</span>';
    return '<b>' . number_format((float)$v, 2) . '</b>';
}

function diff_class($a, $b) {
    if ($a === null || $b === null) return '';
    $d = abs((float)$a - (float)$b);
    if ($d < 0.05) return 'style="background:#d4edda"';   // match
    if ($d < 5)    return 'style="background:#fff3cd"';   // small diff
    return 'style="background:#f8d7da"';                  // large diff
}

// ── build filter ─────────────────────────────────────────────────────────────
$sqlParams = [];
$conditions = ['u.deleted = 0', "lpu.userrolename = 'student'"];

if (!empty($planid)) {
    $ids = array_filter(explode(',', $planid), 'is_numeric');
    if ($ids) {
        list($insql, $inp) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'plan');
        $conditions[] = "cp.learningplanid $insql";
        $sqlParams = array_merge($sqlParams, $inp);
    }
}
if (!empty($periodid)) {
    $ids = array_filter(explode(',', $periodid), 'is_numeric');
    if ($ids) {
        list($insql, $inp) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'per');
        $conditions[] = "cp.periodid $insql";
        $sqlParams = array_merge($sqlParams, $inp);
    }
}
if (!empty($search)) {
    $like1 = $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), ':srch1', false);
    $like2 = $DB->sql_like('u.email', ':srch2', false);
    $conditions[] = "($like1 OR $like2)";
    $sqlParams['srch1'] = '%' . $search . '%';
    $sqlParams['srch2'] = '%' . $search . '%';
}

$where = 'WHERE ' . implode(' AND ', $conditions);

// ── main query (same as export Mode 1) ───────────────────────────────────────
$mainRows = $DB->get_records_sql("
    SELECT cp.id AS cpid,
           u.id  AS userid,
           u.firstname, u.lastname, u.email,
           lp.name AS career,
           per.name AS periodname,
           COALESCE(cp.coursename, '(Sin curso)') AS coursename,
           cp.grade   AS cp_grade,
           cp.status  AS cp_status,
           cp.groupid AS groupid,
           cp.classid AS classid
    FROM {gmk_course_progre} cp
    JOIN {user} u ON u.id = cp.userid
    JOIN {local_learning_users} lpu ON (lpu.userid = u.id AND lpu.learningplanid = cp.learningplanid)
    JOIN {local_learning_plans} lp ON lp.id = cp.learningplanid
    LEFT JOIN {local_learning_periods} per ON per.id = cp.periodid
    $where
    ORDER BY lp.name, per.id, u.firstname
", $sqlParams, 0, $limit);

// ── for each row, fetch the extra grade sources ───────────────────────────────
$rows = [];
foreach ($mainRows as $r) {
    $row = (array)$r;

    // ── Source B: course-total from grade_grades (itemtype='course') ─────────
    $row['b_course_grade']    = null;
    $row['b_corecourseid']    = null;
    $row['b_needsupdate']     = null;

    // ── Source C: category grade from gmk_class ──────────────────────────────
    $row['c_cat_grade']       = null;
    $row['c_gradecategoryid'] = null;
    $row['c_corecourseid']    = null;
    $row['c_classid']         = null;

    // resolve gmk_class via groupid (same JOIN as old broken export)
    $cls = null;
    if (!empty($r->groupid) && (int)$r->groupid > 0) {
        $cls = $DB->get_record_sql(
            "SELECT id, corecourseid, gradecategoryid
               FROM {gmk_class}
              WHERE groupid = :gid AND gradecategoryid > 0 AND corecourseid > 0
              LIMIT 1",
            ['gid' => (int)$r->groupid]
        );
    }
    // fallback: via classid
    if (!$cls && !empty($r->classid) && (int)$r->classid > 0) {
        $cls = $DB->get_record_sql(
            "SELECT id, corecourseid, gradecategoryid
               FROM {gmk_class}
              WHERE id = :cid AND gradecategoryid > 0 AND corecourseid > 0
              LIMIT 1",
            ['cid' => (int)$r->classid]
        );
    }

    if ($cls) {
        $row['c_classid']         = $cls->id;
        $row['c_corecourseid']    = $cls->corecourseid;
        $row['c_gradecategoryid'] = $cls->gradecategoryid;

        // Source B: from corecourseid
        $row['b_corecourseid'] = $cls->corecourseid;
        $nu = $DB->get_field_select('grade_items', 'needsupdate',
            "courseid = :cid AND itemtype = 'course'", ['cid' => (int)$cls->corecourseid]);
        $row['b_needsupdate'] = ($nu !== false) ? (int)$nu : null;

        $bRow = $DB->get_record_sql(
            "SELECT CASE WHEN gi.grademax > 0
                         THEN ROUND((gg.finalgrade / gi.grademax) * 100, 2)
                         ELSE NULL END AS grade
               FROM {grade_items} gi
               LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
              WHERE gi.courseid = :cid AND gi.itemtype = 'course'",
            ['uid' => (int)$r->userid, 'cid' => (int)$cls->corecourseid]
        );
        $row['b_course_grade'] = ($bRow && $bRow->grade !== null) ? (float)$bRow->grade : null;

        // Source C: category-level
        $gi = $DB->get_record_sql(
            "SELECT gi.id, gi.grademax,
                    gg.finalgrade, gg.rawgrade
               FROM {grade_items} gi
               LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
              WHERE gi.itemtype = 'category'
                AND gi.iteminstance = :catid
                AND gi.courseid = :cid",
            ['uid'   => (int)$r->userid,
             'catid' => (int)$cls->gradecategoryid,
             'cid'   => (int)$cls->corecourseid]
        );
        if ($gi) {
            $fg = $gi->finalgrade ?? $gi->rawgrade ?? null;
            if ($fg !== null && $gi->grademax > 0) {
                $row['c_cat_grade'] = round((float)$fg / (float)$gi->grademax * 100, 2);
            }
        }
    }

    $rows[] = $row;
}

// ── STATUS LABELS ─────────────────────────────────────────────────────────────
$statusLabels = [0=>'No disponible',1=>'Disponible',2=>'Cursando',3=>'Completado',
                 4=>'Aprobada',5=>'Reprobada',6=>'Pend.Reválida',7=>'Revalidando',99=>'Migración'];

// ── COUNTS ────────────────────────────────────────────────────────────────────
$nTotal    = count($rows);
$nHasCls   = count(array_filter($rows, fn($r) => $r['c_classid'] !== null));
$nMatch_AB = 0;
$nMatch_AC = 0;
foreach ($rows as $r) {
    if ($r['cp_grade'] !== null && $r['b_course_grade'] !== null && abs((float)$r['cp_grade'] - (float)$r['b_course_grade']) < 0.05) $nMatch_AB++;
    if ($r['cp_grade'] !== null && $r['c_cat_grade']   !== null && abs((float)$r['cp_grade'] - (float)$r['c_cat_grade'])   < 0.05) $nMatch_AC++;
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
body { font-family: sans-serif; font-size: 13px; }
h2   { color: #1a73e8; }
.filters { background:#f5f5f5; padding:12px 16px; border-radius:6px; margin-bottom:16px; }
.filters input, .filters select { padding:4px 8px; margin-right:6px; }
.filters button { padding:5px 14px; background:#1a73e8; color:#fff; border:none; border-radius:4px; cursor:pointer; }
table.debug { border-collapse:collapse; font-size:11.5px; width:100%; }
table.debug th { background:#1a73e8; color:#fff; padding:5px 8px; text-align:left; white-space:nowrap; }
table.debug td { padding:4px 8px; border-bottom:1px solid #e0e0e0; vertical-align:top; white-space:nowrap; }
table.debug tr:hover td { background:#f0f4ff; }
.badge { display:inline-block; padding:1px 6px; border-radius:10px; font-size:10px; font-weight:600; }
.badge-ok  { background:#d4edda; color:#155724; }
.badge-warn{ background:#fff3cd; color:#856404; }
.badge-err { background:#f8d7da; color:#721c24; }
.stats { display:flex; gap:16px; margin-bottom:14px; flex-wrap:wrap; }
.stat { background:#f5f5f5; border-radius:6px; padding:8px 16px; text-align:center; min-width:120px; }
.stat b { display:block; font-size:22px; color:#1a73e8; }
.legend { background:#fffbea; border:1px solid #ffe082; border-radius:6px; padding:8px 14px; margin-bottom:12px; font-size:12px; }
</style>

<h2>Debug: Fuentes de Nota — Export vs Gradebook</h2>

<div class="filters">
    <form method="get">
        <b>Plan ID:</b> <input name="planid"   value="<?= xh($planid)   ?>" placeholder="1,2,3" style="width:90px;">
        <b>Periodo ID:</b> <input name="periodid" value="<?= xh($periodid) ?>" placeholder="5,6" style="width:90px;">
        <b>Buscar:</b> <input name="search"   value="<?= xh($search)   ?>" placeholder="Nombre o email" style="width:200px;">
        <b>Límite:</b>
        <select name="limit">
            <?php foreach ([50,100,250,500,1000] as $l): ?>
                <option value="<?= $l ?>" <?= $limit==$l?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Buscar</button>
    </form>
</div>

<div class="stats">
    <div class="stat"><b><?= $nTotal ?></b>Registros</div>
    <div class="stat"><b><?= $nHasCls ?></b>Con gmk_class</div>
    <div class="stat"><b><?= $nTotal - $nHasCls ?></b>Sin gmk_class</div>
    <div class="stat"><b><?= $nMatch_AB ?></b>A = B (cp = curso)</div>
    <div class="stat"><b><?= $nMatch_AC ?></b>A = C (cp = categ.)</div>
</div>

<div class="legend">
    <b>Columnas:</b>
    &nbsp;<span class="badge badge-ok">A</span> <b>cp.grade</b> (lo que exporta actualmente) &nbsp;|&nbsp;
    <span class="badge badge-warn">B</span> <b>grade_grades itemtype='course'</b> (total Moodle) &nbsp;|&nbsp;
    <span class="badge badge-err">C</span> <b>grade_grades categoría gmk_class</b> (lo que mostraba el modal) &nbsp;|&nbsp;
    Fondo <span style="background:#d4edda;padding:1px 6px;">verde</span> = coinciden &nbsp;
    <span style="background:#fff3cd;padding:1px 6px;">amarillo</span> = diff &lt;5 &nbsp;
    <span style="background:#f8d7da;padding:1px 6px;">rojo</span> = diff grande
</div>

<?php if (empty($planid) && empty($periodid) && empty($search)): ?>
<p style="color:#e65100;font-weight:600;">⚠ Especifica al menos un filtro (planid, periodid o búsqueda) para evitar cargar todos los registros.</p>
<?php else: ?>

<p>Mostrando <b><?= $nTotal ?></b> registros (límite: <?= $limit ?>)</p>

<div style="overflow-x:auto;">
<table class="debug">
<thead>
<tr>
    <th>#</th>
    <th>Estudiante</th>
    <th>Carrera</th>
    <th>Periodo</th>
    <th>Curso</th>
    <th>Estado (cp)</th>
    <th><span class="badge badge-ok">A</span> cp.grade</th>
    <th><span class="badge badge-warn">B</span> course grade_grades<br><small>needsupdate</small></th>
    <th><span class="badge badge-err">C</span> categ. grade_grades</th>
    <th>A vs B</th>
    <th>A vs C</th>
    <th>B vs C</th>
    <th>classid / groupid</th>
    <th>corecourseid</th>
    <th>gradecategoryid</th>
</tr>
</thead>
<tbody>
<?php
$i = 0;
foreach ($rows as $r):
    $i++;
    $stLabel = $statusLabels[$r['cp_status']] ?? ('?'.$r['cp_status']);

    $aVal = ($r['cp_grade'] !== null)    ? (float)$r['cp_grade']    : null;
    $bVal = ($r['b_course_grade'] !== null) ? (float)$r['b_course_grade'] : null;
    $cVal = ($r['c_cat_grade'] !== null)  ? (float)$r['c_cat_grade']  : null;

    $diffAB = ($aVal !== null && $bVal !== null) ? abs($aVal - $bVal) : null;
    $diffAC = ($aVal !== null && $cVal !== null) ? abs($aVal - $cVal) : null;
    $diffBC = ($bVal !== null && $cVal !== null) ? abs($bVal - $cVal) : null;

    function diff_badge($d) {
        if ($d === null) return '<span style="color:#999">—</span>';
        if ($d < 0.05) return '<span class="badge badge-ok">=' . number_format($d,2) . '</span>';
        if ($d < 5)    return '<span class="badge badge-warn">Δ' . number_format($d,2) . '</span>';
        return '<span class="badge badge-err">Δ' . number_format($d,2) . '</span>';
    }
?>
<tr>
    <td><?= $i ?></td>
    <td><?= xh($r['firstname'] . ' ' . $r['lastname']) ?><br><small style="color:#666"><?= xh($r['email']) ?></small></td>
    <td><?= xh($r['career']) ?></td>
    <td><?= xh($r['periodname'] ?? '—') ?></td>
    <td><?= xh($r['coursename']) ?></td>
    <td><?= xh($stLabel) ?></td>
    <td><?= fmt_grade($aVal) ?></td>
    <td <?= diff_class($aVal, $bVal) ?>><?= fmt_grade($bVal) ?><br><small><?= $r['b_needsupdate'] !== null ? ('needsupdate='.$r['b_needsupdate']) : '<span style="color:#999">sin gi</span>' ?></small></td>
    <td <?= diff_class($aVal, $cVal) ?>><?= fmt_grade($cVal) ?></td>
    <td><?= diff_badge($diffAB) ?></td>
    <td><?= diff_badge($diffAC) ?></td>
    <td><?= diff_badge($diffBC) ?></td>
    <td><small>cls=<?= xh($r['c_classid'] ?? '—') ?> / grp=<?= xh($r['groupid'] ?? '—') ?></small></td>
    <td><small><?= xh($r['c_corecourseid'] ?? '—') ?></small></td>
    <td><small><?= xh($r['c_gradecategoryid'] ?? '—') ?></small></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($nTotal === 0): ?>
<p style="color:#999;font-style:italic;">Sin resultados con los filtros aplicados.</p>
<?php endif; ?>

<?php endif; ?>

<?php echo $OUTPUT->footer(); ?>
