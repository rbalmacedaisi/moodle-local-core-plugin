<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_export_grades.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Notas Export');

global $DB;

// ── params ───────────────────────────────────────────────────────────────────
$planid   = optional_param('planid',   0, PARAM_INT);
$periodid = optional_param('periodid', 0, PARAM_INT);
$search   = optional_param('search',   '', PARAM_TEXT);

// ── listas para dropdowns ────────────────────────────────────────────────────
$plans   = $DB->get_records('local_learning_plans',  [], 'name ASC', 'id, name');
$periods = $DB->get_records('local_learning_periods', [], 'name ASC', 'id, name');

// ── query solo si hay al menos un filtro ─────────────────────────────────────
$rows = [];
$ran  = false;

if ($planid || $periodid || !empty($search)) {
    $ran = true;
    $sqlParams  = [];
    $conditions = ['u.deleted = 0', "lpu.userrolename = 'student'"];

    if ($planid) {
        $conditions[]         = 'cp.learningplanid = :planid';
        $sqlParams['planid']  = $planid;
    }
    if ($periodid) {
        $conditions[]          = 'cp.periodid = :periodid';
        $sqlParams['periodid'] = $periodid;
    }
    if (!empty($search)) {
        $like1 = $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), ':srch1', false);
        $like2 = $DB->sql_like('u.email', ':srch2', false);
        $conditions[]        = "($like1 OR $like2)";
        $sqlParams['srch1']  = '%' . $search . '%';
        $sqlParams['srch2']  = '%' . $search . '%';
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $mainRows = $DB->get_records_sql("
        SELECT cp.id AS cpid,
               u.id  AS userid,
               u.firstname, u.lastname, u.email,
               lp.name AS career,
               per.name AS periodname,
               COALESCE(cp.coursename, '(Sin curso)') AS coursename,
               cp.grade  AS cp_grade,
               cp.status AS cp_status,
               cp.groupid, cp.classid
        FROM {gmk_course_progre} cp
        JOIN {user} u ON u.id = cp.userid
        JOIN {local_learning_users} lpu ON (lpu.userid = u.id AND lpu.learningplanid = cp.learningplanid)
        JOIN {local_learning_plans} lp ON lp.id = cp.learningplanid
        LEFT JOIN {local_learning_periods} per ON per.id = cp.periodid
        $where
        ORDER BY lp.name, per.id, u.firstname
    ", $sqlParams, 0, 500);

    $statusLabels = [0=>'No disponible',1=>'Disponible',2=>'Cursando',3=>'Completado',
                     4=>'Aprobada',5=>'Reprobada',6=>'Pend.Reválida',7=>'Revalidando',99=>'Migración'];

    foreach ($mainRows as $r) {
        $row = [
            'nombre'    => $r->firstname . ' ' . $r->lastname,
            'email'     => $r->email,
            'carrera'   => $r->career,
            'periodo'   => $r->periodname ?? '—',
            'curso'     => $r->coursename,
            'estado'    => $statusLabels[$r->cp_status] ?? '?',
            'A_cp'      => $r->cp_grade !== null ? round((float)$r->cp_grade, 2) : null,
            'B_moodle'  => null,
            'C_categ'   => null,
            'needsupdate' => null,
        ];

        // resolver gmk_class via groupid o classid
        $cls = null;
        if (!empty($r->groupid) && (int)$r->groupid > 0) {
            $cls = $DB->get_record_sql(
                "SELECT id, corecourseid, gradecategoryid FROM {gmk_class}
                  WHERE groupid = :g AND gradecategoryid > 0 AND corecourseid > 0 LIMIT 1",
                ['g' => (int)$r->groupid]
            );
        }
        if (!$cls && !empty($r->classid) && (int)$r->classid > 0) {
            $cls = $DB->get_record_sql(
                "SELECT id, corecourseid, gradecategoryid FROM {gmk_class}
                  WHERE id = :c AND gradecategoryid > 0 AND corecourseid > 0 LIMIT 1",
                ['c' => (int)$r->classid]
            );
        }

        if ($cls) {
            $cid = (int)$cls->corecourseid;
            $uid = (int)$r->userid;

            // B: nota total del curso Moodle
            $nu = $DB->get_field_select('grade_items', 'needsupdate',
                "courseid = :c AND itemtype = 'course'", ['c' => $cid]);
            $row['needsupdate'] = ($nu !== false) ? (int)$nu : null;

            $bRow = $DB->get_record_sql(
                "SELECT CASE WHEN gi.grademax > 0
                             THEN ROUND((gg.finalgrade / gi.grademax) * 100, 2)
                             ELSE NULL END AS grade
                   FROM {grade_items} gi
                   LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :u
                  WHERE gi.courseid = :c AND gi.itemtype = 'course'",
                ['u' => $uid, 'c' => $cid]
            );
            $row['B_moodle'] = ($bRow && $bRow->grade !== null) ? (float)$bRow->grade : null;

            // C: nota de la categoría de la clase
            $gi = $DB->get_record_sql(
                "SELECT gi.grademax, gg.finalgrade, gg.rawgrade
                   FROM {grade_items} gi
                   LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :u
                  WHERE gi.itemtype = 'category' AND gi.iteminstance = :cat AND gi.courseid = :c",
                ['u' => $uid, 'cat' => (int)$cls->gradecategoryid, 'c' => $cid]
            );
            if ($gi) {
                $fg = $gi->finalgrade ?? $gi->rawgrade ?? null;
                if ($fg !== null && $gi->grademax > 0) {
                    $row['C_categ'] = round((float)$fg / (float)$gi->grademax * 100, 2);
                }
            }
        }

        $rows[] = $row;
    }
}

// ── helpers de salida ─────────────────────────────────────────────────────────
function xh($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }

function cell($val) {
    if ($val === null) return '<td style="color:#bbb;text-align:center;">—</td>';
    return '<td style="text-align:center;font-weight:600;">' . number_format($val, 2) . '</td>';
}

function diff_cell($a, $b) {
    if ($a === null || $b === null) return '<td style="text-align:center;color:#bbb;">—</td>';
    $d = abs($a - $b);
    if ($d < 0.05) return '<td style="text-align:center;background:#d4edda;color:#155724;font-weight:600;">✓ igual</td>';
    $bg  = $d < 5 ? '#fff3cd' : '#f8d7da';
    $clr = $d < 5 ? '#856404' : '#721c24';
    return '<td style="text-align:center;background:' . $bg . ';color:' . $clr . ';font-weight:600;">Δ ' . number_format($d, 2) . '</td>';
}

echo $OUTPUT->header();
?>
<style>
h2 { color:#1a73e8; margin-bottom:4px; }
.filtros { background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; padding:16px 20px; margin-bottom:20px; display:flex; gap:20px; align-items:flex-end; flex-wrap:wrap; }
.filtros label { display:block; font-size:12px; font-weight:600; color:#555; margin-bottom:4px; }
.filtros select, .filtros input { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; }
.filtros button { padding:7px 20px; background:#1a73e8; color:#fff; border:none; border-radius:4px; font-size:13px; cursor:pointer; font-weight:600; }
.filtros button:hover { background:#1557b0; }
table { border-collapse:collapse; width:100%; font-size:12.5px; }
th { background:#343a40; color:#fff; padding:7px 10px; text-align:left; white-space:nowrap; }
td { padding:5px 10px; border-bottom:1px solid #e9ecef; vertical-align:middle; }
tr:hover td { background:#f0f4ff; }
.tip { font-size:11px; color:#888; }
</style>

<h2>Debug: Notas del Export</h2>
<p style="color:#666;margin-top:0;">Compara <b>cp.grade</b> (lo que exporta el Excel) contra las notas vivas de Moodle.</p>

<div class="filtros">
    <form method="get" style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label>Carrera</label>
            <select name="planid">
                <option value="0">— Todas —</option>
                <?php foreach ($plans as $p): ?>
                    <option value="<?= $p->id ?>" <?= $planid == $p->id ? 'selected' : '' ?>><?= xh($p->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Cuatrimestre</label>
            <select name="periodid">
                <option value="0">— Todos —</option>
                <?php foreach ($periods as $p): ?>
                    <option value="<?= $p->id ?>" <?= $periodid == $p->id ? 'selected' : '' ?>><?= xh($p->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Estudiante</label>
            <input name="search" value="<?= xh($search) ?>" placeholder="Nombre o email" style="width:200px;">
        </div>
        <button type="submit">Buscar</button>
    </form>
</div>

<?php if (!$ran): ?>
<p style="color:#888;">Selecciona una carrera, cuatrimestre o escribe un nombre para ver resultados.</p>

<?php elseif (empty($rows)): ?>
<p style="color:#888;">Sin resultados.</p>

<?php else:
    $nTotal  = count($rows);
    $nSinCls = count(array_filter($rows, fn($r) => $r['B_moodle'] === null && $r['C_categ'] === null));
    $nDifAB  = count(array_filter($rows, fn($r) => $r['A_cp'] !== null && $r['B_moodle'] !== null && abs($r['A_cp'] - $r['B_moodle']) >= 0.05));
    $nDifAC  = count(array_filter($rows, fn($r) => $r['A_cp'] !== null && $r['C_categ']  !== null && abs($r['A_cp'] - $r['C_categ'])  >= 0.05));
?>

<p><b><?= $nTotal ?></b> registros &nbsp;|&nbsp; <b><?= $nSinCls ?></b> sin clase Moodle &nbsp;|&nbsp;
   <b style="color:<?= $nDifAB > 0 ? '#c62828' : '#2e7d32' ?>"><?= $nDifAB ?> diferencias A↔B</b> &nbsp;|&nbsp;
   <b style="color:<?= $nDifAC > 0 ? '#c62828' : '#2e7d32' ?>"><?= $nDifAC ?> diferencias A↔C</b>
</p>

<table>
<thead>
<tr>
    <th>Estudiante</th>
    <th>Carrera</th>
    <th>Cuatrimestre</th>
    <th>Curso</th>
    <th>Estado</th>
    <th title="cp.grade — lo que sale en el Excel">A — cp.grade<br><span class="tip">(Excel)</span></th>
    <th title="grade_grades itemtype=course — nota total de Moodle">B — Moodle total<br><span class="tip">(needsupdate)</span></th>
    <th title="grade_grades de la categoría de la clase">C — Categoría clase<br><span class="tip">(modal)</span></th>
    <th>A vs B</th>
    <th>A vs C</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= xh($r['nombre']) ?><br><span class="tip"><?= xh($r['email']) ?></span></td>
    <td><?= xh($r['carrera']) ?></td>
    <td><?= xh($r['periodo']) ?></td>
    <td><?= xh($r['curso']) ?></td>
    <td><?= xh($r['estado']) ?></td>
    <?= cell($r['A_cp']) ?>
    <td style="text-align:center;">
        <?php if ($r['B_moodle'] !== null): ?>
            <b><?= number_format($r['B_moodle'], 2) ?></b>
            <?php if ($r['needsupdate']): ?><br><span style="color:#e65100;font-size:10px;">⚠ needsupdate=1</span><?php endif; ?>
        <?php else: ?><span style="color:#bbb;">—</span><?php endif; ?>
    </td>
    <?= cell($r['C_categ']) ?>
    <?= diff_cell($r['A_cp'], $r['B_moodle']) ?>
    <?= diff_cell($r['A_cp'], $r['C_categ']) ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

<?php echo $OUTPUT->footer(); ?>
