<?php
/**
 * Debug: Attendance Grade Detail
 *
 * Shows per-student breakdown of attendance sessions vs grade_grades to identify
 * why the calculated grade differs from what the BBB session modal shows.
 *
 * Key columns:
 *   - Sesiones módulo   : ALL rows in attendance_sessions for this attendance module
 *   - Sesiones BBB      : sessions linked via gmk_bbb_attendance_relation
 *   - Sin vínculo BBB   : sessions in the module NOT linked to any BBB record
 *   - Presentes         : sessions where the student has a log with status.grade > 0
 *   - Nota calculada    : present / total_past_sessions  * grademax  (formula del LXP)
 *   - Nota guardada     : grade_grades.finalgrade (lo que realmente muestra el gradebook)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

$PAGE->set_url('/local/grupomakro_core/pages/debug_attendance_grade_detail.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Notas de Asistencia');

$classid = optional_param('classid', 0, PARAM_INT);
$search  = optional_param('search', '', PARAM_TEXT);

// ── Dropdowns ─────────────────────────────────────────────────────────────────
$classes = $DB->get_records_sql("
    SELECT c.id, c.classname, c.attendancemoduleid, c.corecourseid, c.groupid,
           per.name AS periodname
      FROM {gmk_class} c
      LEFT JOIN {gmk_academic_periods} per ON per.id = c.periodid
     WHERE c.attendancemoduleid > 0
     ORDER BY per.startdate DESC, c.classname ASC
");

// ── Main data ─────────────────────────────────────────────────────────────────
$classInfo   = null;
$attRecord   = null;
$gradeItem   = null;
$rows        = [];
$allSessions = [];
$bbbSids     = [];
$ran         = false;

if ($classid && isset($classes[$classid])) {
    $ran      = true;
    $classInfo = $classes[$classid];

    // Resolve attendance instance from cmid
    $cm = get_coursemodule_from_id('attendance', (int)$classInfo->attendancemoduleid, (int)$classInfo->corecourseid);
    if ($cm) {
        $attRecord = $DB->get_record('attendance', ['id' => $cm->instance], 'id, grade, course');
    }

    if ($attRecord) {
        $gradeItem = $DB->get_record('grade_items', [
            'courseid'     => $attRecord->course,
            'itemtype'     => 'mod',
            'itemmodule'   => 'attendance',
            'iteminstance' => $attRecord->id,
            'itemnumber'   => 0,
        ], 'id, grademax');

        // All attendance sessions
        $allSessions = $DB->get_records_sql(
            "SELECT s.id, s.sessdate, s.duration, s.description
               FROM {attendance_sessions} s
              WHERE s.attendanceid = :attid
              ORDER BY s.sessdate ASC",
            ['attid' => $attRecord->id]
        );

        // BBB-linked attendance session IDs for this class
        $bbbRels = $DB->get_records('gmk_bbb_attendance_relation',
            ['classid' => $classid, 'attendanceid' => $attRecord->id], '', 'id, attendancesessionid');
        foreach ($bbbRels as $rel) {
            $bbbSids[$rel->attendancesessionid] = true;
        }

        // Students in the class group
        $studentsQuery = "
            SELECT u.id, u.firstname, u.lastname, u.email
              FROM {groups_members} gm
              JOIN {user} u ON u.id = gm.userid
              JOIN {gmk_class} c ON c.groupid = gm.groupid AND c.id = :classid
             WHERE u.deleted = 0";
        $studentsParams = ['classid' => $classid];
        if (!empty($search)) {
            $studentsQuery .= " AND (" . $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), ':srch1', false)
                            . " OR " . $DB->sql_like('u.email', ':srch2', false) . ")";
            $studentsParams['srch1'] = '%' . $search . '%';
            $studentsParams['srch2'] = '%' . $search . '%';
        }
        $studentsQuery .= " ORDER BY u.firstname, u.lastname";
        $students = $DB->get_records_sql($studentsQuery, $studentsParams);

        $now        = time();
        $sessionIds = array_keys($allSessions);

        foreach ($students as $student) {
            // Fetch logs for this student in this attendance module
            $logsMap = [];
            if (!empty($sessionIds)) {
                list($insql, $inparams) = $DB->get_in_or_equal($sessionIds, SQL_PARAMS_NAMED, 'sid');
                $inparams['uid'] = $student->id;
                $logs = $DB->get_records_sql(
                    "SELECT al.sessionid, al.statusid, ast.grade AS status_grade, ast.acronym, ast.description AS status_desc
                       FROM {attendance_log} al
                       LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
                      WHERE al.studentid = :uid AND al.sessionid $insql",
                    $inparams
                );
                foreach ($logs as $l) {
                    $logsMap[$l->sessionid] = $l;
                }
            }

            $totalPast  = 0;
            $totalBBB   = 0;
            $totalNoBBB = 0;
            $present    = 0;
            $absent     = 0;
            $noLog      = 0;
            $detail     = [];

            foreach ($allSessions as $session) {
                $isPast  = ($session->sessdate + $session->duration) < $now;
                $isBBB   = isset($bbbSids[$session->id]);
                $log     = $logsMap[$session->id] ?? null;
                $isP     = $log && (int)$log->status_grade > 0;

                if ($isPast) {
                    $totalPast++;
                    if ($isBBB) $totalBBB++; else $totalNoBBB++;
                    if ($isP)        $present++;
                    elseif ($log)    $absent++;
                    else             $noLog++;
                }

                $detail[] = [
                    'sessdate'     => $session->sessdate,
                    'duration'     => $session->duration,
                    'description'  => $session->description,
                    'is_past'      => $isPast,
                    'is_bbb'       => $isBBB,
                    'log_acronym'  => $log ? ($log->acronym ?? '?') : null,
                    'log_grade'    => $log ? (int)$log->status_grade : null,
                    'status_desc'  => $log ? ($log->status_desc ?? '') : '',
                ];
            }

            // Grade from our formula (same as get_student_gradebook.php)
            $calcGrade   = null;
            $calcPercent = null;
            $grademax    = $gradeItem ? (float)$gradeItem->grademax : 100.0;
            if ($totalPast > 0) {
                $calcPercent = round(($present / $totalPast) * 100, 2);
                $calcGrade   = round($calcPercent * $grademax / 100, 2);
            }

            // Stored grade_grades value
            $storedGrade = null;
            if ($gradeItem) {
                $gg = $DB->get_record('grade_grades',
                    ['itemid' => $gradeItem->id, 'userid' => $student->id], 'finalgrade');
                if ($gg && $gg->finalgrade !== null) {
                    $storedGrade = round((float)$gg->finalgrade, 2);
                }
            }

            $rows[] = [
                'name'        => $student->firstname . ' ' . $student->lastname,
                'email'       => $student->email,
                'total_all'   => count($allSessions),
                'total_past'  => $totalPast,
                'total_bbb'   => $totalBBB,
                'total_nobbb' => $totalNoBBB,
                'present'     => $present,
                'absent'      => $absent,
                'no_log'      => $noLog,
                'calc_pct'    => $calcPercent,
                'calc_grade'  => $calcGrade,
                'stored_grade'=> $storedGrade,
                'grademax'    => $grademax,
                'detail'      => $detail,
            ];
        }
    }
}

function xh($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }

function diff_cell($calc, $stored) {
    if ($calc === null || $stored === null) {
        return '<td class="tc dim">—</td>';
    }
    $d = abs($calc - $stored);
    if ($d < 0.05) {
        return '<td class="tc ok">✓</td>';
    }
    return '<td class="tc bad">Δ' . number_format($d, 2) . '</td>';
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
.legend { display:flex; gap:16px; font-size:12px; margin-bottom:12px; flex-wrap:wrap; }
.legend span { display:inline-flex; align-items:center; gap:4px; }
.dot { display:inline-block; width:10px; height:10px; border-radius:50%; }
.dot-bbb   { background:#1a73e8; }
.dot-nobbb { background:#e53935; }
.dot-fut   { background:#bbb; }
table { border-collapse:collapse; width:100%; font-size:12px; }
th { background:#343a40; color:#fff; padding:6px 10px; text-align:left; white-space:nowrap; }
td { padding:5px 10px; border-bottom:1px solid #e9ecef; vertical-align:middle; }
tr:hover td { background:#f0f4ff; }
.tc { text-align:center; }
.ok  { text-align:center; background:#d4edda; color:#155724; font-weight:700; }
.bad { text-align:center; background:#f8d7da; color:#721c24; font-weight:700; }
.dim { text-align:center; color:#ccc; }
.tag { display:inline-block; padding:1px 7px; border-radius:10px; font-size:11px; font-weight:700; }
.tag-bbb   { background:#d1ecf1; color:#0c5460; }
.tag-nobbb { background:#f8d7da; color:#721c24; }
.tag-ok    { background:#d4edda; color:#155724; }
.tag-a     { background:#fff3cd; color:#856404; }
.tag-none  { background:#f0f0f0; color:#888; }
details > summary { cursor:pointer; user-select:none; color:#1a73e8; font-size:11px; }
.sessions-table { font-size:11px; margin-top:6px; border:1px solid #dee2e6; }
.sessions-table th { background:#6c757d; padding:4px 8px; }
.sessions-table td { padding:3px 8px; }
.row-bbb   { background:#e8f4fd; }
.row-nobbb { background:#ffeaea; }
.row-fut   { color:#bbb; font-style:italic; }
.info-box { background:#e8f4fd; border:1px solid #bee5eb; border-radius:6px; padding:10px 16px; margin-bottom:12px; font-size:13px; }
.info-box b { color:#0c5460; }
</style>

<h2>Debug: Notas de Asistencia vs Sesiones BBB</h2>
<p style="color:#666;margin-top:0;font-size:13px;">
    Compara las sesiones del módulo de asistencia contra las sesiones visibles en el modal de BBB.
    Identifica sesiones <b style="color:#e53935">sin vínculo BBB</b> que cuentan en la nota pero no aparecen en el modal.
</p>

<div class="filtros">
    <form method="get" style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label>Clase</label>
            <select name="classid" style="min-width:320px;">
                <option value="0">— Selecciona una clase —</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c->id ?>" <?= $classid == $c->id ? 'selected' : '' ?>>
                        [<?= xh($c->periodname ?? '—') ?>] <?= xh($c->classname) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Buscar estudiante</label>
            <input name="search" value="<?= xh($search) ?>" placeholder="Nombre o email" style="width:200px;">
        </div>
        <button type="submit">Buscar</button>
    </form>
</div>

<?php if (!$ran): ?>
<p style="color:#888;">Selecciona una clase para ver el detalle.</p>

<?php elseif (!$attRecord): ?>
<div style="color:#e53935;font-weight:600;padding:10px;">
    Error: no se pudo resolver el módulo de asistencia para esta clase (attendancemoduleid=<?= (int)$classInfo->attendancemoduleid ?>).
    Verifica que el cmid sea válido.
</div>

<?php else: ?>

<div class="info-box">
    <b>Clase:</b> <?= xh($classInfo->classname) ?> &nbsp;|&nbsp;
    <b>Attendance ID:</b> <?= $attRecord->id ?> &nbsp;|&nbsp;
    <b>Grademax:</b> <?= $gradeItem ? $gradeItem->grademax : '?' ?> &nbsp;|&nbsp;
    <b>Total sesiones en módulo:</b> <?= count($allSessions) ?> &nbsp;|&nbsp;
    <b>Sesiones BBB vinculadas:</b> <?= count($bbbSids) ?> &nbsp;|&nbsp;
    <b>Sin vínculo BBB:</b> <span style="color:<?= (count($allSessions) - count($bbbSids)) > 0 ? '#e53935' : '#155724' ?>;font-weight:700;">
        <?= count($allSessions) - count($bbbSids) ?>
    </span>
</div>

<div class="legend">
    <span><span class="dot dot-bbb"></span> Sesión vinculada a BBB (aparece en modal)</span>
    <span><span class="dot dot-nobbb"></span> Sesión SIN vínculo BBB (cuenta en nota, NO aparece en modal)</span>
    <span><span class="dot dot-fut"></span> Sesión futura (no cuenta)</span>
</div>

<?php if (empty($rows)): ?>
<p style="color:#888;">Sin estudiantes <?= !empty($search) ? 'que coincidan con "' . xh($search) . '"' : 'en esta clase' ?>.</p>
<?php else: ?>

<?php
// Summary: students with discrepancy
$totalDisc = 0;
foreach ($rows as $r) {
    if ($r['calc_grade'] !== null && $r['stored_grade'] !== null && abs($r['calc_grade'] - $r['stored_grade']) >= 0.05) {
        $totalDisc++;
    }
    if ($r['total_nobbb'] > 0) { /* has non-BBB sessions */ }
}
$withNoBBB = array_filter($rows, fn($r) => $r['total_nobbb'] > 0);
?>

<p style="font-size:13px;margin-bottom:8px;">
    <b><?= count($rows) ?></b> estudiantes &nbsp;|&nbsp;
    <span style="color:#e53935;font-weight:700;"><?= count($withNoBBB) ?></span> con sesiones sin vínculo BBB &nbsp;|&nbsp;
    <span style="color:#721c24;font-weight:700;"><?= $totalDisc ?></span> con discrepancia nota calculada vs guardada
</p>

<div style="overflow-x:auto;">
<table>
<thead>
<tr>
    <th>Estudiante</th>
    <th class="tc" title="Total sesiones en módulo (pasadas)">Ses. módulo<br><small>pasadas</small></th>
    <th class="tc" title="Sesiones pasadas vinculadas a BBB (visibles en modal)">Ses. BBB<br><small>vinculadas</small></th>
    <th class="tc" title="Sesiones pasadas SIN vínculo BBB — estas cuentan en la nota pero NO aparecen en el modal">Sin BBB<br><small style="color:#e53935">⚠ no visibles</small></th>
    <th class="tc">Presentes</th>
    <th class="tc">Ausentes</th>
    <th class="tc">Sin log</th>
    <th class="tc" title="present / total_past * 100">% calculado<br><small>(fórmula LXP)</small></th>
    <th class="tc" title="present / total_past * grademax — valor que escribe el recalc">Nota calc.<br><small>(/<?= $rows[0]['grademax'] ?? 100 ?>)</small></th>
    <th class="tc" title="Valor actual en grade_grades (lo que ve el gradebook)">Nota guardada<br><small>(grade_grades)</small></th>
    <th class="tc">Diferencia</th>
    <th class="tc">Detalle sesiones</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $row):
    $hasNoBBB  = $row['total_nobbb'] > 0;
    $hasDisc   = $row['calc_grade'] !== null && $row['stored_grade'] !== null && abs($row['calc_grade'] - $row['stored_grade']) >= 0.05;
    $rowStyle  = ($hasDisc || $hasNoBBB) ? 'background:#fff8e1;' : '';
?>
<tr style="<?= $rowStyle ?>">
    <td>
        <b><?= xh($row['name']) ?></b>
        <span style="display:block;font-size:11px;color:#888;"><?= xh($row['email']) ?></span>
    </td>
    <td class="tc"><?= $row['total_past'] ?> / <?= $row['total_all'] ?></td>
    <td class="tc"><?= $row['total_bbb'] ?></td>
    <td class="tc">
        <?php if ($row['total_nobbb'] > 0): ?>
            <b style="color:#e53935;"><?= $row['total_nobbb'] ?></b>
        <?php else: ?>
            <span class="dim">0</span>
        <?php endif; ?>
    </td>
    <td class="tc" style="color:#155724;font-weight:700;"><?= $row['present'] ?></td>
    <td class="tc" style="color:#856404;"><?= $row['absent'] ?></td>
    <td class="tc" style="color:#888;"><?= $row['no_log'] ?></td>
    <td class="tc"><?= $row['calc_pct'] !== null ? $row['calc_pct'] . '%' : '<span class="dim">—</span>' ?></td>
    <td class="tc font-weight-bold"><?= $row['calc_grade'] !== null ? $row['calc_grade'] : '<span class="dim">—</span>' ?></td>
    <td class="tc"><?= $row['stored_grade'] !== null ? $row['stored_grade'] : '<span class="dim">—</span>' ?></td>
    <?= diff_cell($row['calc_grade'], $row['stored_grade']) ?>
    <td class="tc">
        <details>
            <summary><?= count($row['detail']) ?> sesiones</summary>
            <table class="sessions-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Pasada</th>
                        <th>BBB</th>
                        <th>Log</th>
                        <th>Descripción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($row['detail'] as $i => $s):
                    $trClass = !$s['is_past'] ? 'row-fut' : ($s['is_bbb'] ? 'row-bbb' : 'row-nobbb');
                ?>
                <tr class="<?= $trClass ?>">
                    <td><?= $i + 1 ?></td>
                    <td><?= date('d/m/y', $s['sessdate']) ?></td>
                    <td><?= date('H:i', $s['sessdate']) ?></td>
                    <td class="tc"><?= $s['is_past'] ? '✓' : '—' ?></td>
                    <td class="tc">
                        <?php if ($s['is_bbb']): ?>
                            <span class="tag tag-bbb">BBB</span>
                        <?php elseif ($s['is_past']): ?>
                            <span class="tag tag-nobbb">sin BBB</span>
                        <?php else: ?>
                            <span class="dim">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="tc">
                        <?php if ($s['log_acronym'] !== null): ?>
                            <?php if ($s['log_grade'] > 0): ?>
                                <span class="tag tag-ok"><?= xh($s['log_acronym']) ?> ✓</span>
                            <?php else: ?>
                                <span class="tag tag-a"><?= xh($s['log_acronym']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="tag tag-none">sin log</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:200px;font-size:10px;"><?= xh(mb_strimwidth($s['description'] ?? '', 0, 60, '…')) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php endif; ?>
<?php endif; ?>

<?php echo $OUTPUT->footer(); ?>
