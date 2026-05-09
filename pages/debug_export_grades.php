<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_export_grades.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Notas Export');

global $DB;

$planid   = optional_param('planid',   0, PARAM_INT);
$periodid = optional_param('periodid', 0, PARAM_INT);
$search   = optional_param('search',   '', PARAM_TEXT);

$plans   = $DB->get_records('local_learning_plans',  [], 'name ASC', 'id, name');
$periods = $DB->get_records('local_learning_periods', [], 'name ASC', 'id, name');

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
               cp.groupid, cp.classid,
               cp.courseid AS courseid
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
        $uid = (int)$r->userid;
        $cid = (int)$r->courseid; // Moodle course ID (= gmk_class.corecourseid)

        $row = [
            'nombre'      => $r->firstname . ' ' . $r->lastname,
            'email'       => $r->email,
            'carrera'     => $r->career,
            'periodo'     => $r->periodname ?? '—',
            'curso'       => $r->coursename,
            'estado'      => $statusLabels[$r->cp_status] ?? '?',
            'A_cp'        => $r->cp_grade !== null ? round((float)$r->cp_grade, 2) : null,
            'B_nfi'       => null,   // Nota Final Integrada
            'C_categ'     => null,   // Categoría clase via groups_members
            'D_total'     => null,   // Total del curso Moodle
            'source'      => 'cp',   // fuente ganadora
            'resolved'    => null,   // nota resuelta final (misma lógica que pensum)
        ];

        if ($cid > 0) {
            // ── B: Nota Final Integrada ──────────────────────────────────────
            $nfi = $DB->get_record_sql(
                "SELECT gg.finalgrade
                   FROM {grade_items} gi
                   JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :u
                  WHERE gi.courseid = :c
                    AND gg.finalgrade BETWEEN 0 AND 100
                    AND (gi.itemname LIKE '%Nota Final Integrada%'
                         OR gi.itemname LIKE '%Final Integrada%'
                         OR gi.itemname LIKE '%Nota Final%')
               ORDER BY gg.finalgrade DESC
                  LIMIT 1",
                ['u' => $uid, 'c' => $cid]
            );
            if ($nfi) $row['B_nfi'] = round((float)$nfi->finalgrade, 2);

            // ── C: Categoría de la clase via groups_members ──────────────────
            $catg = $DB->get_record_sql(
                "SELECT gg.finalgrade
                   FROM {groups_members} gm
                   JOIN {gmk_class} cls ON cls.groupid = gm.groupid
                        AND cls.corecourseid = :cid
                        AND cls.gradecategoryid > 0
                   JOIN {grade_items} gi ON gi.itemtype = 'category'
                        AND gi.iteminstance = cls.gradecategoryid
                        AND gi.courseid = cls.corecourseid
                   JOIN {grade_grades} gg ON gg.itemid = gi.id
                        AND gg.userid = :u
                        AND gg.finalgrade BETWEEN 0 AND 100
                  WHERE gm.userid = :u2
                  LIMIT 1",
                ['cid' => $cid, 'u' => $uid, 'u2' => $uid]
            );
            if ($catg) $row['C_categ'] = round((float)$catg->finalgrade, 2);

            // ── D: Total del curso Moodle ────────────────────────────────────
            $tot = $DB->get_record_sql(
                "SELECT ROUND((gg.finalgrade / gi.grademax) * 100, 2) AS grade
                   FROM {grade_items} gi
                   JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :u
                  WHERE gi.courseid = :c AND gi.itemtype = 'course'
                    AND gi.grademax > 0 AND gg.finalgrade IS NOT NULL
                  LIMIT 1",
                ['u' => $uid, 'c' => $cid]
            );
            if ($tot) $row['D_total'] = (float)$tot->grade;
        }

        // Resolver nota con misma prioridad que el pensum
        if ($row['B_nfi'] !== null) {
            $row['resolved'] = $row['B_nfi'];
            $row['source']   = 'Nota Final Integrada';
        } elseif ($row['C_categ'] !== null) {
            $row['resolved'] = $row['C_categ'];
            $row['source']   = 'Categoría clase';
        } elseif ($row['D_total'] !== null && $row['D_total'] >= 0 && $row['D_total'] <= 100) {
            $row['resolved'] = $row['D_total'];
            $row['source']   = 'Total Moodle';
        } elseif ($row['A_cp'] !== null) {
            $row['resolved'] = $row['A_cp'];
            $row['source']   = 'cp.grade';
        }

        $rows[] = $row;
    }
}

function xh($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }

function cell($val) {
    if ($val === null) return '<td style="text-align:center;color:#ccc;">—</td>';
    return '<td style="text-align:center;font-weight:600;">' . number_format($val, 2) . '</td>';
}

function match_cell($a, $b) {
    if ($a === null || $b === null) return '<td style="text-align:center;color:#ccc;">—</td>';
    $d = abs($a - $b);
    if ($d < 0.05) return '<td style="text-align:center;background:#d4edda;color:#155724;">✓</td>';
    return '<td style="text-align:center;background:#f8d7da;color:#721c24;font-weight:600;">Δ' . number_format($d,1) . '</td>';
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
table { border-collapse:collapse; width:100%; font-size:12px; }
th { background:#343a40; color:#fff; padding:6px 10px; text-align:left; white-space:nowrap; }
td { padding:5px 10px; border-bottom:1px solid #e9ecef; vertical-align:middle; }
tr:hover td { background:#f0f4ff; }
.tip { font-size:11px; color:#888; display:block; }
.src { display:inline-block; padding:1px 7px; border-radius:10px; font-size:10px; font-weight:700; }
.src-nfi   { background:#d1ecf1; color:#0c5460; }
.src-categ { background:#d4edda; color:#155724; }
.src-total { background:#fff3cd; color:#856404; }
.src-cp    { background:#f8d7da; color:#721c24; }
</style>

<h2>Debug: Fuentes de Nota</h2>
<p style="color:#666;margin-top:0;">La columna <b>Nota Resuelta</b> usa la misma prioridad que el studenttable/pensum.</p>

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
<p style="color:#888;">Selecciona una carrera, cuatrimestre o busca un estudiante.</p>

<?php elseif (empty($rows)): ?>
<p style="color:#888;">Sin resultados.</p>

<?php else:
    $nTotal  = count($rows);
    $nCp     = count(array_filter($rows, fn($r) => $r['source'] === 'cp.grade'));
    $nNfi    = count(array_filter($rows, fn($r) => $r['source'] === 'Nota Final Integrada'));
    $nCateg  = count(array_filter($rows, fn($r) => $r['source'] === 'Categoría clase'));
    $nTotal2 = count(array_filter($rows, fn($r) => $r['source'] === 'Total Moodle'));
?>
<p style="font-size:13px;">
    <b><?= $nTotal ?></b> registros &nbsp;|&nbsp;
    Fuente ganadora: &nbsp;
    <span class="src src-nfi"><?= $nNfi ?> Nota Final Integrada</span> &nbsp;
    <span class="src src-categ"><?= $nCateg ?> Categoría clase</span> &nbsp;
    <span class="src src-total"><?= $nTotal2 ?> Total Moodle</span> &nbsp;
    <span class="src src-cp"><?= $nCp ?> cp.grade</span>
</p>

<div style="overflow-x:auto;">
<table>
<thead>
<tr>
    <th>Estudiante</th>
    <th>Carrera</th>
    <th>Cuatrim.</th>
    <th>Curso</th>
    <th>Estado</th>
    <th title="cp.grade — valor guardado en gmk_course_progre">A — cp.grade</th>
    <th title="Nota Final Integrada — grade item prioritario">B — Nota Final Integrada</th>
    <th title="Categoría clase via groups_members">C — Categ. clase</th>
    <th title="Total del curso Moodle">D — Total Moodle</th>
    <th title="Nota final usando la misma prioridad del pensum: B→C→D→A"><b>Nota Resuelta</b></th>
    <th>A vs Resuelta</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r):
    $srcClass = ['Nota Final Integrada'=>'src-nfi','Categoría clase'=>'src-categ','Total Moodle'=>'src-total','cp.grade'=>'src-cp'][$r['source']] ?? '';
?>
<tr>
    <td><?= xh($r['nombre']) ?><span class="tip"><?= xh($r['email']) ?></span></td>
    <td><?= xh($r['carrera']) ?></td>
    <td><?= xh($r['periodo']) ?></td>
    <td><?= xh($r['curso']) ?></td>
    <td><?= xh($r['estado']) ?></td>
    <?= cell($r['A_cp']) ?>
    <?= cell($r['B_nfi']) ?>
    <?= cell($r['C_categ']) ?>
    <?= cell($r['D_total']) ?>
    <td style="text-align:center;">
        <?php if ($r['resolved'] !== null): ?>
            <b><?= number_format($r['resolved'], 2) ?></b>
            <span class="src <?= $srcClass ?>"><?= xh($r['source']) ?></span>
        <?php else: ?><span style="color:#ccc;">—</span><?php endif; ?>
    </td>
    <?= match_cell($r['A_cp'], $r['resolved']) ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php echo $OUTPUT->footer(); ?>
