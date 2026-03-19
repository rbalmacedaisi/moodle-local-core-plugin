<?php
// =============================================================================
// DEBUG: Inconsistencias en gmk_course_progre — aprobado + huérfano pendiente
//
// Detecta estudiantes que tienen, para el mismo userid+courseid:
//   - Al menos 1 registro con status IN (3,4) → Completada / Aprobada
//   - Al menos 1 registro con status IN (0,1,5) → No disponible / Disponible / Reprobada
//
// Acción de limpieza: elimina los registros huérfanos (status 0/1/5) conservando
// siempre el registro aprobado (status 3/4).
// =============================================================================
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_progre_approved_orphans.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug: Aprobados con huérfanos pendientes');
$PAGE->set_heading('Inconsistencias gmk_course_progre: aprobado + registro huérfano');

// ─── Constantes de estado ────────────────────────────────────────────────────
$STATUS_LABELS = [
    0  => ['txt' => 'No disponible', 'cls' => 'secondary'],
    1  => ['txt' => 'Disponible',    'cls' => 'info'],
    2  => ['txt' => 'Cursando',      'cls' => 'primary'],
    3  => ['txt' => 'Completada',    'cls' => 'success'],
    4  => ['txt' => 'Aprobada',      'cls' => 'success'],
    5  => ['txt' => 'Reprobada',     'cls' => 'danger'],
    6  => ['txt' => 'Pend. Revalida','cls' => 'warning'],
    7  => ['txt' => 'Revalidando',   'cls' => 'warning'],
    99 => ['txt' => 'Migración',     'cls' => 'secondary'],
];

// ─── Acción: eliminar huérfanos ──────────────────────────────────────────────
$action   = optional_param('action', '', PARAM_ALPHA);
$actionLog = [];
$deleteCount = 0;

// Un registro es "huérfano" si:
//   a) tiene status no-terminal (0/1/2/5)
//   b) el estudiante NO está matriculado en ese learningplanid (no existe en local_learning_users con rol estudiante)
//   c) Y para ese mismo userid+courseid existe al menos un registro aprobado (status 3/4)
$ORPHAN_CONDITION = "
    cp.status IN (0, 1, 2, 5)
    AND NOT EXISTS (
        SELECT 1 FROM {local_learning_users} lu
         WHERE lu.userid        = cp.userid
           AND lu.learningplanid = cp.learningplanid
           AND lu.userroleid    = 5
    )
    AND EXISTS (
        SELECT 1 FROM {gmk_course_progre} cp_ok
         WHERE cp_ok.userid   = cp.userid
           AND cp_ok.courseid = cp.courseid
           AND cp_ok.status  IN (3, 4)
    )
";

if ($action === 'cleanselected') {
    require_sesskey();
    $selectedIds = optional_param_array('delids', [], PARAM_INT);
    $selectedIds = array_values(array_filter(array_map('intval', $selectedIds)));

    if (empty($selectedIds)) {
        $actionLog[] = ['error', 'No seleccionaste ningún registro.'];
    } else {
        // Allow deleting any selected record (admin has full control)
        list($in, $inParams) = $DB->get_in_or_equal($selectedIds, SQL_PARAMS_NAMED);
        $toDelete = $DB->get_fieldset_sql(
            "SELECT id FROM {gmk_course_progre} WHERE id $in",
            $inParams
        );
        if (!empty($toDelete)) {
            list($in2, $params2) = $DB->get_in_or_equal($toDelete, SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {gmk_course_progre} WHERE id $in2", $params2);
            $deleteCount = count($toDelete);
            $actionLog[] = ['ok', "Se eliminaron $deleteCount registros seleccionados."];
        } else {
            $actionLog[] = ['info', 'No se encontraron los registros indicados.'];
        }
    }
}

if ($action === 'cleanall') {
    require_sesskey();

    $orphanIds = $DB->get_fieldset_sql(
        "SELECT cp.id FROM {gmk_course_progre} cp WHERE $ORPHAN_CONDITION",
        []
    );

    if (!empty($orphanIds)) {
        list($in, $params) = $DB->get_in_or_equal($orphanIds, SQL_PARAMS_NAMED);
        $DB->execute("DELETE FROM {gmk_course_progre} WHERE id $in", $params);
        $deleteCount = count($orphanIds);
        $actionLog[] = ['ok', "Se eliminaron $deleteCount registros huérfanos."];
    } else {
        $actionLog[] = ['info', 'No se encontraron registros huérfanos para eliminar.'];
    }
}

if ($action === 'cleanone') {
    require_sesskey();
    $uid = required_param('uid', PARAM_INT);
    $cid = required_param('cid', PARAM_INT);

    // Verify there IS an approved record before deleting
    $hasApproved = $DB->record_exists_select(
        'gmk_course_progre',
        'userid = :uid AND courseid = :cid AND status IN (3, 4)',
        ['uid' => $uid, 'cid' => $cid]
    );
    if (!$hasApproved) {
        $actionLog[] = ['error', "uid=$uid courseid=$cid no tiene registro aprobado — no se eliminó nada."];
    } else {
        $orphanIds = $DB->get_fieldset_sql(
            "SELECT cp.id FROM {gmk_course_progre} cp
              WHERE cp.userid   = :uid
                AND cp.courseid = :cid
                AND $ORPHAN_CONDITION",
            ['uid' => $uid, 'cid' => $cid]
        );
        if (!empty($orphanIds)) {
            list($in, $params) = $DB->get_in_or_equal($orphanIds, SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {gmk_course_progre} WHERE id $in", $params);
            $deleteCount = count($orphanIds);
        }
        $actionLog[] = ['ok', "Eliminados $deleteCount registros huérfanos para uid=$uid courseid=$cid."];
    }
}

// ─── Consulta: pares (userid, courseid) inconsistentes ──────────────────────
// Un par es inconsistente si tiene al menos un registro huérfano (según $ORPHAN_CONDITION)
$pairsSql = "
    SELECT CONCAT(cp.userid, '_', cp.courseid) AS ukey,
           cp.userid, cp.courseid,
           u.firstname, u.lastname, u.username,
           c.fullname AS coursename, c.shortname AS courseshort
      FROM {gmk_course_progre} cp
      JOIN {user}   u ON u.id = cp.userid   AND u.deleted = 0
      JOIN {course} c ON c.id = cp.courseid
     WHERE $ORPHAN_CONDITION
     GROUP BY cp.userid, cp.courseid, u.firstname, u.lastname, u.username,
              c.fullname, c.shortname
     ORDER BY u.lastname, u.firstname, c.fullname
";
$pairs = $DB->get_records_sql($pairsSql, []);

// For each pair, load all records
$details = [];
foreach ($pairs as $pair) {
    $recs = $DB->get_records(
        'gmk_course_progre',
        ['userid' => $pair->userid, 'courseid' => $pair->courseid],
        'status DESC, timemodified DESC'
    );
    $details[] = [
        'pair'    => $pair,
        'records' => $recs,
    ];
}

$totalPairs   = count($details);
$totalOrphans = 0;
foreach ($details as $d) {
    foreach ($d['records'] as $r) {
        if (in_array((int)$r->status, [0, 1, 2, 5])) {
            $totalOrphans++;
        }
    }
}

echo $OUTPUT->header();
?>
<style>
.dpa-wrap  { max-width:1300px; }
.dpa-card  { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px 20px; margin-bottom:20px; }
.dpa-card h4 { margin:0 0 12px; font-size:15px; font-weight:700; }
.dpa-stat  { display:inline-block; text-align:center; padding:12px 20px; border-radius:8px; margin:0 8px 8px 0; border:1px solid #e5e7eb; }
.dpa-stat .num { font-size:26px; font-weight:700; }
.dpa-stat .lbl { font-size:11px; color:#6b7280; }
.dpa-tbl   { width:100%; border-collapse:collapse; font-size:13px; }
.dpa-tbl th { background:#1f2937; color:#fff; padding:7px 10px; text-align:left; white-space:nowrap; }
.dpa-tbl td { border-bottom:1px solid #e5e7eb; padding:6px 10px; vertical-align:middle; }
.dpa-tbl tr.row-keep td   { background:#f0fdf4; }
.dpa-tbl tr.row-orphan td { background:#fef2f2; }
.dpa-badge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:600; }
.badge-success   { background:#dcfce7; color:#166534; }
.badge-danger    { background:#fee2e2; color:#991b1b; }
.badge-primary   { background:#dbeafe; color:#1e40af; }
.badge-info      { background:#e0f2fe; color:#0369a1; }
.badge-warning   { background:#fef9c3; color:#854d0e; }
.badge-secondary { background:#e5e7eb; color:#374151; }
.dpa-section   { border:1px solid #e5e7eb; border-radius:8px; margin-bottom:16px; overflow:hidden; }
.dpa-sec-head  { background:#f8fafc; padding:10px 14px; font-size:13px; font-weight:600; display:flex; justify-content:space-between; align-items:center; }
.dpa-btn       { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border:none; border-radius:5px; cursor:pointer; font-size:12px; font-weight:600; text-decoration:none; }
.dpa-btn-red   { background:#dc2626; color:#fff; }
.dpa-btn-red:hover  { background:#b91c1c; color:#fff; }
.dpa-btn-blue  { background:#2563eb; color:#fff; }
.dpa-btn-blue:hover { background:#1d4ed8; color:#fff; }
.dpa-alert-ok  { background:#dcfce7; border:1px solid #bbf7d0; color:#166534; padding:12px 16px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.dpa-alert-err { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:12px 16px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.dpa-alert-info{ background:#dbeafe; border:1px solid #bfdbfe; color:#1e40af; padding:12px 16px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.dpa-explain   { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:14px 16px; font-size:13px; line-height:1.7; margin-bottom:20px; }
</style>

<div class="dpa-wrap">
<h2 style="font-size:20px;font-weight:700;margin-bottom:6px;">&#128269; Inconsistencias: aprobado + huérfano en gmk_course_progre</h2>

<?php foreach ($actionLog as [$cls, $msg]): ?>
    <div class="dpa-alert-<?php echo $cls === 'ok' ? 'ok' : ($cls === 'error' ? 'err' : 'info'); ?>">
        <?php echo $cls === 'ok' ? '&#10003;' : ($cls === 'error' ? '&#10005;' : 'ℹ'); ?> <?php echo s($msg); ?>
    </div>
<?php endforeach; ?>

<!-- Explicación -->
<div class="dpa-explain">
    <strong>&#9888;&#65039; ¿Qué detecta esta página?</strong><br>
    Estudiantes que tienen para el <strong>mismo curso</strong>:
    <ul style="margin:6px 0 0 18px; padding:0;">
        <li>&#10003; Un registro <strong>aprobado</strong> (status 3 = Completada o 4 = Aprobada)</li>
        <li>&#10005; Y además un registro <strong>huérfano</strong>: status 0/1/2/5 cuyo <code>learningplanid</code> <strong>no corresponde a ningún plan en que el estudiante esté actualmente matriculado</strong> (<code>local_learning_users</code>)</li>
    </ul>
    <br>
    <strong>&#128165; Causas habituales:</strong>
    <ul style="margin:4px 0 0 18px; padding:0;">
        <li><strong>Cambio de plan académico</strong>: el curso fue aprobado en el Plan A; al asignar al Plan B (otro <code>learningplanid</code>), <code>create_learningplan_user_progress</code> inserta un registro nuevo con status=0/1 porque solo verifica duplicados por <code>userid+courseid+learningplanid</code>, sin cruzar contra planes anteriores.</li>
        <li><strong>Re-matrícula con <code>forceInProgress</code></strong>: la función <code>assign_class_to_course_progress</code> puede seleccionar un registro no-aprobado como "canónico" y actualizarlo a Cursando, dejando el registro aprobado intacto en paralelo.</li>
        <li><strong>Importaciones manuales</strong> (<code>import_grades</code>, <code>debug_external_enrollment</code>): insertan filas sin verificar registros aprobados en otro plan.</li>
    </ul>
    <br>
    <strong>Acción segura:</strong> eliminar los registros huérfanos (status 0/1/5) conservando siempre el registro aprobado. Esto corrige la vista en <em>Demanda académica</em> y en el LXP.
</div>

<!-- Stats -->
<div class="dpa-card">
    <h4>&#128202; Resumen</h4>
    <div class="dpa-stat" style="border-color:#fca5a5">
        <div class="num" style="color:#dc2626"><?php echo $totalPairs; ?></div>
        <div class="lbl">Pares (estudiante + curso) inconsistentes</div>
    </div>
    <div class="dpa-stat" style="border-color:#fca5a5">
        <div class="num" style="color:#dc2626"><?php echo $totalOrphans; ?></div>
        <div class="lbl">Registros huérfanos a eliminar</div>
    </div>
</div>

<?php if ($totalPairs > 0): ?>
<!-- Botón limpiar todo -->
<div class="dpa-card">
    <h4>&#129529; Limpieza masiva</h4>
    <p style="font-size:13px;color:#6b7280;margin:0 0 12px">
        Elimina todos los registros huérfanos (status 0, 1, 5) de los <?php echo $totalPairs; ?> pares identificados.<br>
        Los registros aprobados (status 3/4) <strong>no serán modificados</strong>.
    </p>
    <form method="post" onsubmit="return confirm('¿Eliminar <?php echo $totalOrphans; ?> registros huérfanos en <?php echo $totalPairs; ?> pares? Los registros aprobados NO se tocan.');">
        <input type="hidden" name="action"  value="cleanall">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <button type="submit" class="dpa-btn dpa-btn-red">
            &#128465; Limpiar los <?php echo $totalOrphans; ?> registros huérfanos
        </button>
    </form>
</div>

<!-- Detalle por par — todo dentro de un único form con checkboxes -->
<form method="post" id="form-selected">
<input type="hidden" name="action"  value="cleanselected">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

<!-- Barra flotante de selección -->
<div id="dpa-floatbar" style="display:none;position:sticky;top:0;z-index:100;background:#1f2937;color:#fff;
     padding:10px 16px;border-radius:8px;margin-bottom:12px;display:none;align-items:center;gap:12px;flex-wrap:wrap;">
    <span id="dpa-sel-count" style="font-size:13px;font-weight:600">0 seleccionados</span>
    <button type="submit" class="dpa-btn dpa-btn-red">
        &#128465; Eliminar seleccionados
    </button>
    <button type="button" class="dpa-btn" style="background:#4b5563"
            onclick="document.querySelectorAll('.dpa-cb').forEach(cb=>cb.checked=false);updateBar();">
        Deseleccionar todo
    </button>
</div>

<div class="dpa-card">
    <h4>&#128203; Detalle por estudiante y curso</h4>
    <?php
    // Preload all learningplan names once to avoid N+1 queries
    $planNames = $DB->get_records_menu('local_learning_plans', null, '', 'id, name');

    foreach ($details as $d):
        $pair = $d['pair'];
        $recs = $d['records'];
        // Student's active plans
        $stuPlans = $DB->get_fieldset_select(
            'local_learning_users', 'learningplanid',
            'userid = :uid AND userroleid = 5',
            ['uid' => (int)$pair->userid]
        );
        $stuPlans = array_map('intval', $stuPlans);
        $orphanCount = 0;
        foreach ($recs as $r) {
            $lpid = (int)$r->learningplanid;
            if (!in_array((int)$r->status, [3,4]) && !in_array($lpid, $stuPlans)) {
                $orphanCount++;
            }
        }
    ?>
    <div class="dpa-section">
        <div class="dpa-sec-head">
            <span>
                &#128100; <strong><?php echo s($pair->firstname . ' ' . $pair->lastname); ?></strong>
                <small style="color:#6b7280">(uid=<?php echo (int)$pair->userid; ?> / <?php echo s($pair->username); ?>)</small>
                &nbsp;&mdash;&nbsp;
                &#128218; <?php echo s($pair->coursename); ?>
                <small style="color:#6b7280">(cid=<?php echo (int)$pair->courseid; ?> / <?php echo s($pair->courseshort); ?>)</small>
            </span>
            <span style="font-size:12px;color:#6b7280">
                <?php
                $stuPlanLabels = array_map(fn($pid) => $planNames[$pid] ?? "plan $pid", $stuPlans);
                echo '&#127891; Plan activo: ' . (empty($stuPlanLabels) ? '—' : implode(', ', array_map('s', $stuPlanLabels)));
                ?>
            </span>
        </div>
        <table class="dpa-tbl">
            <thead>
                <tr>
                    <th style="width:32px"><input type="checkbox" class="dpa-cb-group" title="Seleccionar huérfanos de este par" onchange="toggleGroup(this)"></th>
                    <th>ID</th>
                    <th>Estado</th>
                    <th>Nota</th>
                    <th>Progreso</th>
                    <th>Plan del registro</th>
                    <th>classid</th>
                    <th>periodid</th>
                    <th>Creado</th>
                    <th>Modificado</th>
                    <th>Decisión</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recs as $rec):
                $st         = (int)$rec->status;
                $lpid       = (int)$rec->learningplanid;
                $info       = $STATUS_LABELS[$st] ?? ['txt' => "status=$st", 'cls' => 'secondary'];
                $isApproved = in_array($st, [3, 4]);
                $inPlan     = in_array($lpid, $stuPlans);
                $isOrphan   = !$isApproved && !$inPlan;
                $rowCls     = $isApproved ? 'row-keep' : ($isOrphan ? 'row-orphan' : '');
                $planLabel  = $lpid > 0 ? (isset($planNames[$lpid]) ? s($planNames[$lpid]) : "ID $lpid") : '—';
            ?>
                <tr class="<?php echo $rowCls; ?>">
                    <td style="text-align:center">
                        <input type="checkbox"
                               class="dpa-cb <?php echo $isApproved ? 'dpa-cb-approved' : ''; ?>"
                               name="delids[]"
                               value="<?php echo (int)$rec->id; ?>"
                               onchange="updateBar()"
                               <?php echo $isApproved ? 'title="⚠ Este registro está aprobado. Marcalo solo si estás seguro."' : ''; ?>>
                    </td>
                    <td style="font-family:monospace;font-weight:600"><?php echo (int)$rec->id; ?></td>
                    <td>
                        <span class="dpa-badge badge-<?php echo $info['cls']; ?>"><?php echo $info['txt']; ?></span>
                    </td>
                    <td style="font-weight:<?php echo $isApproved ? '700' : '400'; ?>">
                        <?php echo $rec->grade !== null ? number_format((float)$rec->grade, 2) : '—'; ?>
                    </td>
                    <td><?php echo $rec->progress !== null ? (int)$rec->progress . '%' : '—'; ?></td>
                    <td>
                        <?php echo $planLabel; ?>
                        <?php if ($lpid > 0): ?>
                            <?php if ($inPlan): ?>
                                <span class="dpa-badge badge-success" style="font-size:10px">activo</span>
                            <?php else: ?>
                                <span class="dpa-badge badge-danger" style="font-size:10px">sin plan</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo (int)$rec->classid ?: '—'; ?></small></td>
                    <td><small><?php echo (int)$rec->periodid ?: '—'; ?></small></td>
                    <td style="font-size:11px;white-space:nowrap">
                        <?php echo $rec->timecreated ? date('Y-m-d H:i', (int)$rec->timecreated) : '—'; ?>
                    </td>
                    <td style="font-size:11px;white-space:nowrap">
                        <?php echo $rec->timemodified ? date('Y-m-d H:i', (int)$rec->timemodified) : '—'; ?>
                    </td>
                    <td style="font-weight:700;font-size:12px">
                        <?php if ($isApproved): ?>
                            <span style="color:#166534">&#10003; CONSERVAR</span>
                        <?php elseif ($isOrphan): ?>
                            <span style="color:#dc2626">&#128465; huérfano</span>
                        <?php else: ?>
                            <span style="color:#d97706">&#9888; en plan activo</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
</div>
</form>

<script>
function updateBar() {
    var all      = document.querySelectorAll('.dpa-cb:checked');
    var approved = document.querySelectorAll('.dpa-cb-approved:checked');
    var n = all.length, a = approved.length;
    var bar = document.getElementById('dpa-floatbar');
    bar.style.display = n > 0 ? 'flex' : 'none';
    var txt = n + ' seleccionado' + (n !== 1 ? 's' : '');
    if (a > 0) txt += ' <span style="color:#fca5a5;font-weight:700">⚠ ' + a + ' aprobado' + (a !== 1 ? 's' : '') + '</span>';
    document.getElementById('dpa-sel-count').innerHTML = txt;
}
function toggleGroup(masterCb) {
    var tbody = masterCb.closest('table').querySelector('tbody');
    tbody.querySelectorAll('.dpa-cb').forEach(cb => { cb.checked = masterCb.checked; });
    updateBar();
}
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('form-selected').addEventListener('submit', function(e) {
        var n = document.querySelectorAll('.dpa-cb:checked').length;
        var a = document.querySelectorAll('.dpa-cb-approved:checked').length;
        if (!n) { alert('Selecciona al menos un registro.'); e.preventDefault(); return; }
        var msg = '¿Eliminar ' + n + ' registro(s)?\n';
        if (a > 0) msg += '\n⚠ ATENCIÓN: ' + a + ' de ellos tiene status Aprobado/Completado.\n';
        msg += '\nEsta acción no se puede deshacer.';
        if (!confirm(msg)) e.preventDefault();
    });
});
</script>

<?php else: ?>
<div class="dpa-card">
    <div class="dpa-alert-ok">&#10003; No se encontraron inconsistencias. Todos los pares (userid, courseid) son consistentes.</div>
</div>
<?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();
