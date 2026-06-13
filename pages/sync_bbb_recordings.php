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

// Pre-cache editingteacher names per course to avoid N+1 queries.
function sbbb_teacher_names_for_courses(array $courseids): array {
    global $DB;
    if (empty($courseids)) {
        return [];
    }
    list($insql, $inparams) = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_NAMED, 'cid');
    $sql = "SELECT ctx.instanceid AS courseid, u.id, u.firstname, u.lastname
              FROM {user} u
              JOIN {role_assignments} ra ON ra.userid = u.id
              JOIN {context} ctx ON ctx.id = ra.contextid
              JOIN {role} r ON r.id = ra.roleid
             WHERE ctx.contextlevel = :ctxlvl
               AND r.shortname = :rname
               AND u.deleted = 0
               AND u.suspended = 0
               AND ctx.instanceid $insql
          ORDER BY u.lastname, u.firstname";
    $params = ['ctxlvl' => CONTEXT_COURSE, 'rname' => 'editingteacher'] + $inparams;
    $rows = $DB->get_records_sql($sql, $params);
    $out = [];
    foreach ($rows as $r) {
        $out[$r->courseid][] = trim($r->firstname . ' ' . $r->lastname);
    }
    return $out;
}

// Pagination params.
$perpage = optional_param('perpage', 50, PARAM_INT);
$perpage = max(10, min(200, $perpage));
$page    = optional_param('page', 0, PARAM_INT);
$page    = max(0, $page);
$offset  = $page * $perpage;

// Count total recordings (for pagination).
$total = $DB->count_records_sql(
    "SELECT COUNT(1)
       FROM {bigbluebuttonbn_recordings} r
  LEFT JOIN {bigbluebuttonbn} b ON b.id = r.bigbluebuttonbnid"
);

$recordings = $DB->get_records_sql(
    "SELECT r.id, r.bigbluebuttonbnid, r.recordingid, r.status, r.timecreated, r.timemodified,
            b.name AS bbbname, b.course AS courseid, c.fullname AS coursename
       FROM {bigbluebuttonbn_recordings} r
  LEFT JOIN {bigbluebuttonbn} b ON b.id = r.bigbluebuttonbnid
  LEFT JOIN {course} c ON c.id = b.course
   ORDER BY r.timecreated DESC",
    [],
    $offset,
    $perpage
);

// Pre-cache teacher names for all courses in the current page.
$courseids = [];
foreach ($recordings as $r) {
    if (!empty($r->courseid)) {
        $courseids[$r->courseid] = true;
    }
}
$teacher_names = sbbb_teacher_names_for_courses(array_keys($courseids));

$pending_last   = sbbb_last_run('mod_bigbluebuttonbn\task\check_pending_recordings');
$dismissed_last = sbbb_last_run('mod_bigbluebuttonbn\task\check_dismissed_recordings');

// Global counts (NOT just the current page).
$global_counts = $DB->get_records_sql(
    "SELECT status, COUNT(1) AS c FROM {bigbluebuttonbn_recordings} GROUP BY status"
);
$counts = array_fill(0, 6, 0);
foreach ($global_counts as $row) {
    $s = (int)$row->status;
    if (isset($counts[$s])) {
        $counts[$s] = (int)$row->c;
    }
}

$total_pages = ($perpage > 0) ? (int)ceil($total / $perpage) : 1;
$showing_from = ($total === 0) ? 0 : ($offset + 1);
$showing_to   = min($offset + $perpage, $total);

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
.sbbb-pager { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:14px; padding-top:14px; border-top:1px solid #e5e7eb; }
.sbbb-pager-info { font-size:12px; color:#6b7280; }
.sbbb-pager-nav { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
.sbbb-pager-nav a, .sbbb-pager-nav span { display:inline-block; padding:6px 10px; font-size:12px; border-radius:4px; border:1px solid #dee2e6; text-decoration:none; color:#374151; background:#fff; }
.sbbb-pager-nav a:hover { background:#f3f4f6; border-color:#9ca3af; }
.sbbb-pager-nav .current { background:#2563eb; color:#fff; border-color:#2563eb; font-weight:600; }
.sbbb-pager-nav .disabled { color:#d1d5db; pointer-events:none; }
.sbbb-perpage { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; }
.sbbb-perpage select { font-size:12px; padding:4px 6px; border:1px solid #dee2e6; border-radius:4px; background:#fff; }
.sbbb-teacher { font-size:12px; color:#374151; }
.sbbb-teacher small { color:#9ca3af; }
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
    <h4>&#128250; Grabaciones en base de datos (total: <?php echo (int)$total; ?>)</h4>
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
                <th>Docente(s)</th>
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
            $teachers = $teacher_names[(int)$rec->courseid] ?? [];
        ?>
            <tr>
                <td><?php echo (int)$rec->id; ?></td>
                <td>
                    <?php echo s($rec->bbbname ?? '—'); ?><br>
                    <small style="color:#9ca3af">id=<?php echo (int)$rec->bigbluebuttonbnid; ?></small>
                </td>
                <td><small><?php echo s($rec->coursename ?? '—'); ?></small></td>
                <td class="sbbb-teacher">
                    <?php if (!empty($teachers)): ?>
                        <?php echo s(implode(', ', $teachers)); ?>
                    <?php else: ?>
                        <small>—</small>
                    <?php endif; ?>
                </td>
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

    <!-- Paginación -->
    <div class="sbbb-pager">
        <div class="sbbb-pager-info">
            Mostrando <strong><?php echo $showing_from; ?>–<?php echo $showing_to; ?></strong>
            de <strong><?php echo (int)$total; ?></strong> grabaciones
        </div>

        <form method="get" class="sbbb-perpage">
            <label for="perpage">Por página:</label>
            <select name="perpage" id="perpage" onchange="this.form.submit()">
                <?php foreach ([25, 50, 100, 200] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo ($perpage === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="sbbb-pager-nav">
            <?php
            $baseurl = new moodle_url('/local/grupomakro_core/pages/sync_bbb_recordings.php', ['perpage' => $perpage]);
            $render_link = function($p) use ($baseurl, $page, $total_pages) {
                if ($p < 0 || $p >= $total_pages) {
                    return '<span class="disabled">&laquo;</span>';
                }
                if ($p === $page) {
                    return '<span class="current">' . ($p + 1) . '</span>';
                }
                $url = clone $baseurl;
                $url->param('page', $p);
                return '<a href="' . $url->out() . '">' . ($p + 1) . '</a>';
            };
            // First / Prev
            echo $page > 0
                ? '<a href="' . (clone $baseurl)->out() . '">&laquo;&laquo; Primera</a>'
                : '<span class="disabled">&laquo;&laquo; Primera</span>';
            echo $page > 0
                ? '<a href="' . (clone $baseurl)->param('page', $page - 1)->out() . '">&laquo; Anterior</a>'
                : '<span class="disabled">&laquo; Anterior</span>';
            // Pages around current
            $start = max(0, $page - 2);
            $end   = min($total_pages - 1, $page + 2);
            for ($p = $start; $p <= $end; $p++) {
                echo $render_link($p);
            }
            // Next / Last
            echo $page < $total_pages - 1
                ? '<a href="' . (clone $baseurl)->param('page', $page + 1)->out() . '">Siguiente &raquo;</a>'
                : '<span class="disabled">Siguiente &raquo;</span>';
            echo $page < $total_pages - 1
                ? '<a href="' . (clone $baseurl)->param('page', $total_pages - 1)->out() . '">Última &raquo;&raquo;</a>'
                : '<span class="disabled">Última &raquo;&raquo;</span>';
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

</div>

<?php
echo $OUTPUT->footer();
