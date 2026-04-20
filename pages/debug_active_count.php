<?php
/**
 * Debug: Active student count discrepancy
 * Compares absence_dashboard.php logic vs academicpanel.php logic.
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$now = time();

echo $OUTPUT->header();
echo '<style>
body{font-family:monospace;font-size:13px}
h2{font-size:16px;margin:20px 0 6px;padding:6px 10px;border-radius:4px}
h3{font-size:14px;margin:14px 0 4px;color:#374151}
table{border-collapse:collapse;width:100%;font-size:12px;margin-bottom:16px}
th{background:#1e293b;color:#fff;padding:5px 8px;text-align:left}
td{padding:4px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top}
tr:hover td{background:#f8fafc}
.chip{display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:700}
.green{background:#dcfce7;color:#166534}
.red{background:#fee2e2;color:#991b1b}
.yellow{background:#fef9c3;color:#92400e}
.grey{background:#f1f5f9;color:#475569}
.blue{background:#dbeafe;color:#1e40af}
.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px;margin-bottom:16px}
.diff-only-abs{background:#fef9c3}
.diff-only-panel{background:#dbeafe}
</style>';

// ── Helpers ───────────────────────────────────────────────────────────────────

function dac_chip(string $label, string $color = 'grey'): string {
    return "<span class=\"chip $color\">$label</span>";
}

function dac_llu_status(int $userid): array {
    global $DB;
    $rows = $DB->get_records_sql(
        "SELECT llu.id, llu.status, lp.name AS planname
           FROM {local_learning_users} llu
           JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
          WHERE llu.userid = :uid AND llu.userrolename = 'student'",
        ['uid' => $userid]
    );
    return array_values($rows);
}

function dac_student_status_field(int $userid, int $fieldid): string {
    global $DB;
    if (!$fieldid) return '(sin campo)';
    return (string)($DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $fieldid]) ?: '');
}

function dac_active_classes(int $userid): array {
    global $DB, $now;
    return $DB->get_records_sql(
        "SELECT gc.id, gc.name AS classname, c.fullname AS coursename, cp.status AS cp_status
           FROM {gmk_course_progre} cp
           JOIN {gmk_class} gc ON gc.id = cp.classid
           LEFT JOIN {course} c ON c.id = gc.corecourseid
          WHERE cp.userid = :uid
            AND cp.status IN (1,2,3)
            AND gc.approved = 1 AND gc.closed = 0 AND gc.enddate > :now",
        ['uid' => $userid, 'now' => $now]
    );
}

function dac_all_class_progre(int $userid): array {
    global $DB;
    return $DB->get_records_sql(
        "SELECT cp.id, cp.classid, cp.status AS cp_status, gc.name AS classname, gc.closed, gc.approved, gc.enddate
           FROM {gmk_course_progre} cp
           JOIN {gmk_class} gc ON gc.id = cp.classid
          WHERE cp.userid = :uid",
        ['uid' => $userid]
    );
}

// ── Field IDs ─────────────────────────────────────────────────────────────────
$statusfieldid = (int)($DB->get_field('user_info_field', 'id', ['shortname' => 'studentstatus']) ?: 0);
$docfieldid    = (int)($DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']) ?: 0);

// ════════════════════════════════════════════════════════════════════════════
// MÉTODO A: absence_dashboard.php
// Replica la lógica exacta del career_tree del dashboard:
//   - Solo planes con al menos un estudiante activo no suspendido (misma query que plan_rows)
//   - Solo clases activas en esos planes
//   - Excluye docentes: solo users con local_learning_users.userroleid=5
//   - Activo: llu.status='activo'|'' para el plan de la clase
// ════════════════════════════════════════════════════════════════════════════

// Step 1: planids válidos (misma lógica que $plan_rows en absence_dashboard).
$valid_planids = [];
foreach ($DB->get_records_sql(
    "SELECT DISTINCT llu.learningplanid AS planid
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userroleid = 5 AND llu.status = 'activo'
      WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2"
) as $_pr) {
    $valid_planids[(int)$_pr->planid] = true;
}

// Step 2: clases activas en esos planes.
$active_classes_all = $DB->get_records_sql(
    "SELECT gc.id, gc.learningplanid
       FROM {gmk_class} gc
      WHERE gc.approved=1 AND gc.closed=0 AND gc.enddate>:now",
    ['now' => $now]
);
$active_classes = array_filter($active_classes_all, function($cls) use ($valid_planids) {
    return isset($valid_planids[(int)($cls->learningplanid ?? 0)]);
});

$absd_all_uids      = [];   // uid => true
$absd_active_uids   = [];   // uid => true
$absd_inactive_uids = [];   // uid => true
$absd_uid_reasons   = [];   // uid => [reason strings]

foreach ($active_classes as $cls) {
    $cid    = (int)$cls->id;
    $planid = (int)($cls->learningplanid ?? 0);

    $enrolled = $DB->get_fieldset_sql(
        "SELECT DISTINCT userid FROM {gmk_course_progre} WHERE classid=:cid AND status IN (1,2,3)",
        ['cid' => $cid]
    );

    if (empty($enrolled)) continue;

    [$in_sql, $in_params] = $DB->get_in_or_equal($enrolled, SQL_PARAMS_NAMED, 'su');

    // Only count users registered as students (any plan) — same fix as absence_dashboard.
    $student_uids_set = [];
    foreach ($DB->get_records_sql(
        "SELECT DISTINCT userid FROM {local_learning_users}
          WHERE userroleid=5 AND userid $in_sql",
        $in_params
    ) as $_s) {
        $student_uids_set[(int)$_s->userid] = true;
    }

    $st_map = [];
    if ($planid > 0) {
        foreach ($DB->get_records_sql(
            "SELECT userid, status FROM {local_learning_users}
              WHERE userroleid=5 AND learningplanid=:planid AND userid $in_sql",
            array_merge(['planid' => $planid], $in_params)
        ) as $sr) {
            $st_map[(int)$sr->userid] = (string)$sr->status;
        }
    }

    foreach ($enrolled as $uid) {
        $uid = (int)$uid;
        if (!isset($student_uids_set[$uid])) continue; // teacher/non-student
        $absd_all_uids[$uid] = true;
        $st = $st_map[$uid] ?? 'activo';
        if ($st === 'activo' || $st === '') {
            $absd_active_uids[$uid] = true;
        } else {
            $absd_inactive_uids[$uid] = true;
            $absd_uid_reasons[$uid][] = "llu.status='$st' (plan $planid, clase $cid)";
        }
    }
}

// A student inactive in ANY class => inactive (same as dashboard dedup).
foreach ($absd_inactive_uids as $uid => $_) {
    unset($absd_active_uids[$uid]);
}

$absd_active_count = count($absd_active_uids);
$absd_total_count  = count($absd_all_uids);

// ════════════════════════════════════════════════════════════════════════════
// MÉTODO B: academicpanel.php / get_student_info::execute_optimized()
// Universo: local_learning_users (userrolename='student')
// Activo:   NOT EXISTS lpu con status != 'activo'
//           AND EXISTS gmk_course_progre(status IN 1,2,3) + clase activa
// ════════════════════════════════════════════════════════════════════════════

$panel_candidates = $DB->get_fieldset_sql(
    "SELECT DISTINCT u.id
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
      WHERE u.deleted = 0"
);

$panel_all_uids      = [];
$panel_active_uids   = [];
$panel_inactive_uids = [];
$panel_uid_reasons   = [];

foreach ($panel_candidates as $uid) {
    $uid = (int)$uid;
    $panel_all_uids[$uid] = true;

    // Check 1: all llu rows activo?
    $bad_rows = $DB->get_records_sql(
        "SELECT llu.id, llu.status, lp.name AS planname
           FROM {local_learning_users} llu
           JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
          WHERE llu.userid=:uid AND llu.userrolename='student'
            AND COALESCE(llu.status,'activo') <> 'activo'",
        ['uid' => $uid]
    );

    if (!empty($bad_rows)) {
        $panel_inactive_uids[$uid] = true;
        foreach ($bad_rows as $br) {
            $panel_uid_reasons[$uid][] = "llu.status='{$br->status}' (plan: {$br->planname})";
        }
        continue;
    }

    // Check 2: enrolled in at least one active class?
    $has_active_class = $DB->record_exists_sql(
        "SELECT 1 FROM {gmk_course_progre} cp
           JOIN {gmk_class} gc ON gc.id = cp.classid
          WHERE cp.userid=:uid AND cp.status IN (1,2,3)
            AND gc.approved=1 AND gc.closed=0 AND gc.enddate>:now",
        ['uid' => $uid, 'now' => $now]
    );

    if (!$has_active_class) {
        $panel_inactive_uids[$uid] = true;
        $panel_uid_reasons[$uid][] = 'Sin matrícula en clase activa (gmk_course_progre)';
        continue;
    }

    $panel_active_uids[$uid] = true;
}

$panel_active_count = count($panel_active_uids);
$panel_total_count  = count($panel_all_uids);

// ════════════════════════════════════════════════════════════════════════════
// DIFF
// ════════════════════════════════════════════════════════════════════════════

$only_in_absd  = array_diff_key($absd_active_uids,  $panel_active_uids);  // absd active, panel no
$only_in_panel = array_diff_key($panel_active_uids, $absd_active_uids);   // panel active, absd no
$in_both       = array_intersect_key($absd_active_uids, $panel_active_uids);

// Helper: get name + doc for a user
function dac_user_info(int $uid): array {
    global $DB, $docfieldid;
    $u = $DB->get_record('user', ['id' => $uid], 'id,firstname,lastname,email,suspended', IGNORE_MISSING);
    if (!$u) return ['name' => "(id:$uid)", 'doc' => '', 'email' => '', 'suspended' => 0];
    $doc = $docfieldid ? (string)($DB->get_field('user_info_data', 'data', ['userid' => $uid, 'fieldid' => $docfieldid]) ?: '') : '';
    return [
        'name'      => fullname($u),
        'doc'       => $doc,
        'email'     => $u->email,
        'suspended' => (int)$u->suspended,
    ];
}

// ════════════════════════════════════════════════════════════════════════════
// OUTPUT
// ════════════════════════════════════════════════════════════════════════════
?>
<div style="max-width:1400px;margin:0 auto;padding:20px">
<h1 style="font-size:18px;font-weight:900;margin-bottom:4px">Debug: Diferencia en conteo de estudiantes activos</h1>
<p style="color:#64748b;font-size:12px;margin-bottom:20px">Generado: <?php echo date('Y-m-d H:i:s'); ?></p>

<!-- Resumen -->
<div class="box">
<h2 style="background:#1e293b;color:#fff;margin:0 0 12px">Resumen</h2>
<table>
<tr>
    <th>Métrica</th>
    <th>absence_dashboard (Método A)</th>
    <th>academicpanel (Método B)</th>
    <th>Diferencia</th>
</tr>
<tr>
    <td><b>Universo (todos)</b></td>
    <td><?php echo $absd_total_count; ?> (estudiantes en clases de planes válidos)</td>
    <td><?php echo $panel_total_count; ?> (en local_learning_users, rol student)</td>
    <td><?php echo abs($panel_total_count - $absd_total_count); ?></td>
</tr>
<tr>
    <td><b>Activos</b></td>
    <td><?php echo dac_chip($absd_active_count, 'green'); ?></td>
    <td><?php echo dac_chip($panel_active_count, 'green'); ?></td>
    <td><?php echo dac_chip(abs($panel_active_count - $absd_active_count), $panel_active_count !== $absd_active_count ? 'red' : 'green'); ?></td>
</tr>
<tr>
    <td><b>Solo en Método A (absd activo, panel NO)</b></td>
    <td colspan="2"><?php echo count($only_in_absd); ?> estudiantes</td>
    <td><?php echo dac_chip(count($only_in_absd), count($only_in_absd) ? 'yellow' : 'green'); ?></td>
</tr>
<tr>
    <td><b>Solo en Método B (panel activo, absd NO)</b></td>
    <td colspan="2"><?php echo count($only_in_panel); ?> estudiantes</td>
    <td><?php echo dac_chip(count($only_in_panel), count($only_in_panel) ? 'blue' : 'green'); ?></td>
</tr>
<tr>
    <td><b>En ambos activos</b></td>
    <td colspan="2"><?php echo count($in_both); ?> estudiantes</td>
    <td><?php echo dac_chip(count($in_both), 'green'); ?></td>
</tr>
</table>
</div>

<?php if (!empty($only_in_absd)): ?>
<!-- Activos en absd pero NO en panel -->
<div class="box">
<h2 style="background:#92400e;color:#fff;margin:0 0 12px">
    ⚠ Activos en absence_dashboard pero NO en academicpanel (<?php echo count($only_in_absd); ?>)
</h2>
<p style="font-size:11px;color:#64748b;margin-bottom:8px">
    Estos estudiantes cuentan como activos en el dashboard de inasistencias pero el panel académico NO los cuenta.
</p>
<table>
<tr>
    <th>Nombre</th><th>Cédula</th><th>Email</th>
    <th>Suspendido (user)</th>
    <th>LLU status (todos los planes)</th>
    <th>studentstatus (perfil)</th>
    <th>Clases activas (cp)</th>
    <th>Razón panel lo excluye</th>
</tr>
<?php foreach (array_keys($only_in_absd) as $uid): $uid = (int)$uid;
    $info       = dac_user_info($uid);
    $llu_rows   = dac_llu_status($uid);
    $act_classes = dac_active_classes($uid);
    $st_field   = dac_student_status_field($uid, $statusfieldid);
    $panel_reason = implode('; ', $panel_uid_reasons[$uid] ?? ['(sin razón registrada)']);
?>
<tr class="diff-only-abs">
    <td><?php echo htmlspecialchars($info['name']); ?></td>
    <td><?php echo htmlspecialchars($info['doc']); ?></td>
    <td><?php echo htmlspecialchars($info['email']); ?></td>
    <td><?php echo $info['suspended'] ? dac_chip('SÍ', 'red') : dac_chip('no', 'green'); ?></td>
    <td>
        <?php foreach ($llu_rows as $lr): ?>
            <?php $c = ($lr->status === 'activo' || $lr->status === '') ? 'green' : 'red'; ?>
            <?php echo dac_chip(htmlspecialchars($lr->status ?: 'activo'), $c); ?>
            <small style="color:#64748b"><?php echo htmlspecialchars($lr->planname); ?></small><br>
        <?php endforeach; ?>
        <?php if (empty($llu_rows)): ?><span style="color:#94a3b8">Sin filas llu</span><?php endif; ?>
    </td>
    <td><?php echo $st_field ? dac_chip(htmlspecialchars($st_field), $st_field === 'Activo' ? 'green' : 'red') : dac_chip('(vacío)', 'grey'); ?></td>
    <td>
        <?php if (empty($act_classes)): ?>
            <?php echo dac_chip('ninguna', 'red'); ?>
        <?php else: ?>
            <?php foreach ($act_classes as $ac): ?>
                <small><?php echo htmlspecialchars(mb_substr($ac->coursename ?: $ac->classname, 0, 40)); ?></small><br>
            <?php endforeach; ?>
        <?php endif; ?>
    </td>
    <td style="color:#991b1b"><?php echo htmlspecialchars($panel_reason); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php if (!empty($only_in_panel)): ?>
<!-- Activos en panel pero NO en absd -->
<div class="box">
<h2 style="background:#1e40af;color:#fff;margin:0 0 12px">
    ℹ Activos en academicpanel pero NO en absence_dashboard (<?php echo count($only_in_panel); ?>)
</h2>
<p style="font-size:11px;color:#64748b;margin-bottom:8px">
    Estos estudiantes cuentan en el panel académico pero el dashboard de inasistencias NO los incluye.
</p>
<table>
<tr>
    <th>Nombre</th><th>Cédula</th><th>Email</th>
    <th>Suspendido (user)</th>
    <th>LLU status (todos los planes)</th>
    <th>studentstatus (perfil)</th>
    <th>Clases activas (cp)</th>
    <th>Razón absd lo excluye</th>
</tr>
<?php foreach (array_keys($only_in_panel) as $uid): $uid = (int)$uid;
    $info        = dac_user_info($uid);
    $llu_rows    = dac_llu_status($uid);
    $act_classes = dac_active_classes($uid);
    $st_field    = dac_student_status_field($uid, $statusfieldid);

    // Why absent from absd: not in absd_all_uids? or in inactive?
    if (!isset($absd_all_uids[$uid])) {
        $absd_reason = 'No aparece en gmk_course_progre de ninguna clase activa';
        // Check if they have any course_progre entries at all
        $any_progre = dac_all_class_progre($uid);
        if (!empty($any_progre)) {
            $details = [];
            foreach ($any_progre as $pr) {
                $details[] = "clase {$pr->classid} cp_status={$pr->cp_status} aprov={$pr->approved} closed={$pr->closed} ended=" . ($pr->enddate < $now ? 'SÍ' : 'no');
            }
            $absd_reason .= ': ' . implode('; ', $details);
        } else {
            $absd_reason .= ' (sin registros en gmk_course_progre)';
        }
    } elseif (isset($absd_inactive_uids[$uid])) {
        $absd_reason = 'Marcado inactivo por absd: ' . implode('; ', $absd_uid_reasons[$uid] ?? []);
    } else {
        $absd_reason = '(desconocido)';
    }
?>
<tr class="diff-only-panel">
    <td><?php echo htmlspecialchars($info['name']); ?></td>
    <td><?php echo htmlspecialchars($info['doc']); ?></td>
    <td><?php echo htmlspecialchars($info['email']); ?></td>
    <td><?php echo $info['suspended'] ? dac_chip('SÍ', 'red') : dac_chip('no', 'green'); ?></td>
    <td>
        <?php foreach ($llu_rows as $lr): ?>
            <?php $c = ($lr->status === 'activo' || $lr->status === '') ? 'green' : 'red'; ?>
            <?php echo dac_chip(htmlspecialchars($lr->status ?: 'activo'), $c); ?>
            <small style="color:#64748b"><?php echo htmlspecialchars($lr->planname); ?></small><br>
        <?php endforeach; ?>
        <?php if (empty($llu_rows)): ?><span style="color:#94a3b8">Sin filas llu</span><?php endif; ?>
    </td>
    <td><?php echo $st_field ? dac_chip(htmlspecialchars($st_field), $st_field === 'Activo' ? 'green' : 'red') : dac_chip('(vacío)', 'grey'); ?></td>
    <td>
        <?php if (empty($act_classes)): ?>
            <?php echo dac_chip('ninguna', 'red'); ?>
        <?php else: ?>
            <?php foreach ($act_classes as $ac): ?>
                <small><?php echo htmlspecialchars(mb_substr($ac->coursename ?: $ac->classname, 0, 40)); ?></small><br>
            <?php endforeach; ?>
        <?php endif; ?>
    </td>
    <td style="color:#1e40af"><?php echo htmlspecialchars($absd_reason); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php if (empty($only_in_absd) && empty($only_in_panel)): ?>
<div class="box" style="border-color:#86efac;background:#f0fdf4">
    <strong style="color:#166534">✓ Los conteos son idénticos. No hay diferencias.</strong>
</div>
<?php endif; ?>

</div>
<?php
echo $OUTPUT->footer();
