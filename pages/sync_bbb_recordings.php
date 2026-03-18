<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/sync_bbb_recordings.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Sincronizar Grabaciones BBB');
$PAGE->set_heading('Sincronizar Grabaciones BBB');

$action     = '';
$taskresult = '';
$taskstatus = '';
$tasklog    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);

    ob_start();
    try {
        if ($action === 'pending') {
            $task = new \mod_bigbluebuttonbn\task\check_pending_recordings();
            $task->execute();
            $taskstatus = 'success';
            $taskresult = 'Tarea "check_pending_recordings" ejecutada correctamente.';
        } else if ($action === 'dismissed') {
            $task = new \mod_bigbluebuttonbn\task\check_dismissed_recordings();
            $task->execute();
            $taskstatus = 'success';
            $taskresult = 'Tarea "check_dismissed_recordings" ejecutada correctamente.';
        } else {
            $taskstatus = 'error';
            $taskresult = 'Acción desconocida: ' . s($action);
        }
    } catch (Throwable $e) {
        $taskstatus = 'error';
        $taskresult = 'Error al ejecutar la tarea: ' . s($e->getMessage());
    }
    $tasklog = trim(ob_get_clean());
}

// Status map (mirrors mod_bigbluebuttonbn\recording constants)
$status_labels = [
    0 => ['label' => 'AWAITING',   'cls' => 'warning'],
    1 => ['label' => 'DISMISSED',  'cls' => 'secondary'],
    2 => ['label' => 'PROCESSED',  'cls' => 'success'],
    3 => ['label' => 'NOTIFIED',   'cls' => 'success'],
    4 => ['label' => 'RESET',      'cls' => 'info'],
    5 => ['label' => 'DELETED',    'cls' => 'danger'],
];

// Retrieve last run time for a scheduled task.
function sbbb_last_run(string $classname): string {
    global $DB;
    // The DB stores classnames with and without leading backslash depending on Moodle version.
    $bare = '\\' . ltrim($classname, '\\');
    $last = $DB->get_field_select('task_scheduled', 'lastruntime', $DB->sql_like('classname', ':cls', false), ['cls' => $bare]);
    if (!$last) {
        // Try without leading backslash.
        $bare2 = ltrim($classname, '\\');
        $last  = $DB->get_field_select('task_scheduled', 'lastruntime', $DB->sql_like('classname', ':cls', false), ['cls' => $bare2]);
    }
    return $last ? userdate((int)$last, get_string('strftimedatetimeshort', 'core_langconfig')) : 'Nunca';
}

$recordings = $DB->get_records_sql(
    "SELECT r.id, r.bigbluebuttonbnid, r.recordingid, r.status, r.timecreated, r.timemodified,
            b.name AS bbbname, c.fullname AS coursename
       FROM {bigbluebuttonbn_recordings} r
  LEFT JOIN {bigbluebuttonbn} b ON b.id = r.bigbluebuttonbnid
  LEFT JOIN {course} c ON c.id = b.course
   ORDER BY r.timecreated DESC
      LIMIT 200"
);

$pending_last   = sbbb_last_run('mod_bigbluebuttonbn\task\check_pending_recordings');
$dismissed_last = sbbb_last_run('mod_bigbluebuttonbn\task\check_dismissed_recordings');

$counts = array_fill(0, 6, 0);
foreach ($recordings as $r) {
    $s = (int)$r->status;
    if (isset($counts[$s])) {
        $counts[$s]++;
    }
}

echo $OUTPUT->header();
?>
<style>
.sbbb-wrap { max-width:1200px; }
.sbbb-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:20px; margin-bottom:20px; }
.sbbb-card h4 { margin:0 0 14px; font-size:16px; }
.sbbb-table { width:100%; border-collapse:collapse; font-size:13px; }
.sbbb-table th { background:#1f2937; color:#fff; padding:8px 10px; text-align:left; white-space:nowrap; }
.sbbb-table td { border-bottom:1px solid #e5e7eb; padding:7px 10px; vertical-align:top; }
.sbbb-table tr:hover td { background:#f9fafb; }
.sbbb-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
.sbbb-badge-success   { background:#dcfce7; color:#166534; }
.sbbb-badge-warning   { background:#fef9c3; color:#854d0e; }
.sbbb-badge-secondary { background:#e5e7eb; color:#374151; }
.sbbb-badge-danger    { background:#fee2e2; color:#991b1b; }
.sbbb-badge-info      { background:#dbeafe; color:#1e40af; }
.sbbb-btn { display:inline-flex; align-items:center; gap:5px; padding:8px 14px; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
.sbbb-btn-blue  { background:#2563eb; color:#fff; }
.sbbb-btn-blue:hover  { background:#1d4ed8; }
.sbbb-btn-gray  { background:#6b7280; color:#fff; }
.sbbb-btn-gray:hover  { background:#4b5563; }
.sbbb-alert-ok  { background:#dcfce7; border:1px solid #bbf7d0; color:#166534; padding:12px 16px; border-radius:6px; margin-bottom:16px; }
.sbbb-alert-err { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:12px 16px; border-radius:6px; margin-bottom:16px; }
pre.sbbb-log { background:#111827; color:#d1fae5; padding:12px; border-radius:6px; font-size:12px; max-height:180px; overflow-y:auto; white-space:pre-wrap; margin:10px 0 0; }
.sbbb-stat { display:inline-block; text-align:center; padding:10px 18px; border-radius:8px; margin:0 6px 8px 0; border:1px solid #e5e7eb; }
.sbbb-stat .num { font-size:22px; font-weight:700; }
.sbbb-stat .lbl { font-size:11px; color:#6b7280; }
code.sbbb-tiny { font-size:11px; word-break:break-all; }
</style>

<div class="sbbb-wrap">

<?php if ($taskstatus === 'success'): ?>
    <div class="sbbb-alert-ok">&#10003; <?php echo $taskresult; ?></div>
<?php elseif ($taskstatus === 'error'): ?>
    <div class="sbbb-alert-err">&#10005; <?php echo $taskresult; ?></div>
<?php endif; ?>
<?php if ($tasklog !== ''): ?>
    <pre class="sbbb-log"><?php echo s($tasklog); ?></pre>
<?php endif; ?>

<!-- Summary stats -->
<div class="sbbb-card">
    <h4>&#128202; Resumen de grabaciones</h4>
    <?php
    $stat_defs = [
        [0, 'AWAITING',  'warning'],
        [1, 'DISMISSED', 'secondary'],
        [2, 'PROCESSED', 'success'],
        [3, 'NOTIFIED',  'success'],
        [4, 'RESET',     'info'],
        [5, 'DELETED',   'danger'],
    ];
    foreach ($stat_defs as [$s, $lbl, $cls]):
    ?>
    <div class="sbbb-stat">
        <div class="num"><?php echo (int)$counts[$s]; ?></div>
        <div class="lbl"><span class="sbbb-badge sbbb-badge-<?php echo $cls; ?>"><?php echo $lbl; ?></span></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Task runner -->
<div class="sbbb-card">
    <h4>&#9889; Ejecutar sincronización manual</h4>
    <p style="color:#6b7280; margin:0 0 14px; font-size:13px">
        Ejecuta las tareas programadas directamente sin necesidad de SSH ni CLI.<br>
        <strong>check_pending_recordings</strong>: consulta el servidor BBB y actualiza grabaciones en estado AWAITING a PROCESSED/NOTIFIED.<br>
        <strong>check_dismissed_recordings</strong>: re-procesa grabaciones DISMISSED que ya puedan estar disponibles.
    </p>
    <table style="border-collapse:collapse; width:100%">
        <thead>
            <tr style="background:#f3f4f6">
                <th style="padding:8px 10px; text-align:left; font-size:13px">Tarea</th>
                <th style="padding:8px 10px; text-align:left; font-size:13px">&#128336; Último run</th>
                <th style="padding:8px 10px; text-align:left; font-size:13px">Acción</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding:10px; border-bottom:1px solid #e5e7eb">
                    <code class="sbbb-tiny">mod_bigbluebuttonbn\task\check_pending_recordings</code>
                </td>
                <td style="padding:10px; border-bottom:1px solid #e5e7eb; white-space:nowrap">
                    <?php echo s($pending_last); ?>
                </td>
                <td style="padding:10px; border-bottom:1px solid #e5e7eb">
                    <form method="post" style="margin:0">
                        <input type="hidden" name="action" value="pending">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <button type="submit" class="sbbb-btn sbbb-btn-blue">&#9654; Ejecutar</button>
                    </form>
                </td>
            </tr>
            <tr>
                <td style="padding:10px">
                    <code class="sbbb-tiny">mod_bigbluebuttonbn\task\check_dismissed_recordings</code>
                </td>
                <td style="padding:10px; white-space:nowrap">
                    <?php echo s($dismissed_last); ?>
                </td>
                <td style="padding:10px">
                    <form method="post" style="margin:0">
                        <input type="hidden" name="action" value="dismissed">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <button type="submit" class="sbbb-btn sbbb-btn-gray">&#9654; Ejecutar</button>
                    </form>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- Recordings table -->
<div class="sbbb-card">
    <h4>&#128250; Grabaciones en base de datos (últimas 200)</h4>
    <?php if (empty($recordings)): ?>
        <p style="color:#6b7280">No hay grabaciones registradas en la base de datos.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="sbbb-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Instancia BBB</th>
                <th>Curso</th>
                <th>Recording ID</th>
                <th>Estado</th>
                <th>Creado</th>
                <th>Modificado</th>
                <th>&#128249;</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recordings as $rec):
            $info = $status_labels[(int)$rec->status] ?? ['label' => 'UNKNOWN(' . (int)$rec->status . ')', 'cls' => 'secondary'];
            $valid = in_array((int)$rec->status, [2, 3]);
        ?>
            <tr>
                <td><?php echo (int)$rec->id; ?></td>
                <td>
                    <?php echo s($rec->bbbname ?? '—'); ?><br>
                    <small style="color:#9ca3af">id=<?php echo (int)$rec->bigbluebuttonbnid; ?></small>
                </td>
                <td><small><?php echo s($rec->coursename ?? '—'); ?></small></td>
                <td>
                    <code class="sbbb-tiny"><?php echo s(substr((string)$rec->recordingid, 0, 30) . (strlen((string)$rec->recordingid) > 30 ? '…' : '')); ?></code>
                </td>
                <td><span class="sbbb-badge sbbb-badge-<?php echo $info['cls']; ?>"><?php echo $info['label']; ?></span></td>
                <td style="white-space:nowrap"><?php echo $rec->timecreated ? userdate((int)$rec->timecreated, '%d/%m/%Y %H:%M') : '—'; ?></td>
                <td style="white-space:nowrap"><?php echo $rec->timemodified ? userdate((int)$rec->timemodified, '%d/%m/%Y %H:%M') : '—'; ?></td>
                <td>
                    <?php if ($valid): ?>
                        <a href="https://bbb.isi.edu.pa/playback/presentation/2.3/<?php echo s($rec->recordingid); ?>"
                           target="_blank" style="font-size:12px; color:#2563eb">&#9654; Ver</a>
                    <?php else: ?>
                        <span style="color:#d1d5db">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div>

<?php
echo $OUTPUT->footer();
