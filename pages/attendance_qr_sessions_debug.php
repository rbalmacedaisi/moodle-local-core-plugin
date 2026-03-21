<?php
/**
 * Debug: QR attendance session settings
 * Lists upcoming attendance sessions for active classes and shows whether
 * they are correctly configured for student QR self-marking.
 * Allows bulk-fixing studentscanmark, autoassignstatus and studentsearlyopentime.
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/attendance_qr_sessions_debug.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug QR Asistencia — Sesiones');
$PAGE->set_heading('Debug QR Asistencia — Sesiones');
$PAGE->set_pagelayout('admin');

// ── POST: apply fix ───────────────────────────────────────────────────────────

$action = optional_param('bulk_action', '', PARAM_ALPHA);
if ($action && confirm_sesskey()) {
    $sessids_raw = optional_param_array('fix_sessids', [], PARAM_INT);
    $sessids     = array_values(array_filter(array_unique(array_map('intval', $sessids_raw))));

    $early_open  = optional_param('early_open_minutes', 30, PARAM_INT); // minutes
    $early_secs  = max(0, (int)$early_open) * 60;

    $fixed = 0;
    if (!empty($sessids) && $action === 'fix') {
        foreach ($sessids as $sid) {
            $row = $DB->get_record('attendance_sessions', ['id' => $sid], 'id,studentscanmark,autoassignstatus,studentsearlyopentime');
            if (!$row) continue;
            $upd = new stdClass();
            $upd->id                   = $sid;
            $upd->studentscanmark      = 1;
            $upd->autoassignstatus     = 1;
            $upd->studentsearlyopentime = ($row->studentsearlyopentime > 0)
                ? $row->studentsearlyopentime
                : $early_secs;
            $DB->update_record('attendance_sessions', $upd);
            $fixed++;
        }
    }

    redirect(
        new moodle_url('/local/grupomakro_core/pages/attendance_qr_sessions_debug.php'),
        "$fixed sesiones actualizadas correctamente.",
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Filters ───────────────────────────────────────────────────────────────────

$filter_class  = optional_param('filter_class',  0,        PARAM_INT);
$filter_status = optional_param('filter_status', 'broken', PARAM_ALPHA); // all | broken | ok
$filter_days   = optional_param('filter_days',   30,       PARAM_INT);   // days forward

$now       = time();
$day_start = mktime(0, 0, 0, date('n'), date('j'), date('Y'));
$day_end   = $day_start + max(1, (int)$filter_days) * 86400;

// ── Main query ────────────────────────────────────────────────────────────────
// Join: gmk_class → course_modules → attendance → attendance_sessions
// Left join gmk_bbb_attendance_relation to flag BBB-origin sessions.

$sql = "SELECT
            ats.id              AS sessid,
            ats.sessdate,
            ats.studentscanmark,
            ats.autoassignstatus,
            ats.studentsearlyopentime,
            ats.studentpassword,
            ats.rotateqrcode,
            att.id              AS attid,
            att.name            AS attname,
            cm.id               AS cmid,
            gc.id               AS classid,
            gc.name             AS classname,
            gc.shift            AS classshift,
            c.fullname          AS coursename,
            c.id                AS courseid,
            (CASE WHEN EXISTS (
                SELECT 1 FROM {gmk_bbb_attendance_relation} bbar
                 WHERE bbar.attendanceid = att.id AND bbar.classid = gc.id
            ) THEN 1 ELSE 0 END) AS is_bbb
       FROM {attendance_sessions} ats
       JOIN {attendance}     att  ON att.id = ats.attendanceid
       JOIN {course_modules} cm   ON cm.instance = att.id
                                  AND cm.deletioninprogress = 0
       JOIN {modules}        mod  ON mod.id = cm.module AND mod.name = 'attendance'
       JOIN {course}         c    ON c.id = att.course
       JOIN {gmk_class}      gc   ON gc.attendancemoduleid = cm.id
                                  AND gc.approved = 1
                                  AND gc.closed   = 0
                                  AND gc.enddate  > :now
      WHERE ats.sessdate BETWEEN :day_start AND :day_end";

$params = [
    'now'       => $now,
    'day_start' => $day_start,
    'day_end'   => $day_end,
];

if ($filter_class > 0) {
    $sql   .= ' AND gc.id = :classid';
    $params['classid'] = $filter_class;
}

$sql .= ' ORDER BY ats.sessdate ASC, gc.name ASC';

$rows = $DB->get_records_sql($sql, $params);

// Apply status filter in PHP (simpler than SQL CASE)
$broken_count = 0;
$ok_count     = 0;
$display_rows = [];

foreach ($rows as $row) {
    $is_broken = ((int)$row->studentscanmark !== 1 || (int)$row->autoassignstatus !== 1);
    if ($is_broken) $broken_count++; else $ok_count++;

    if ($filter_status === 'broken' && !$is_broken) continue;
    if ($filter_status === 'ok'     &&  $is_broken) continue;
    $display_rows[] = $row;
}

// ── Active classes list (for filter dropdown) ─────────────────────────────────

$all_classes = $DB->get_records_sql(
    "SELECT gc.id, gc.name FROM {gmk_class} gc
      WHERE gc.approved = 1 AND gc.closed = 0 AND gc.enddate > :now
      ORDER BY gc.name",
    ['now' => $now]
);

// ── Output ────────────────────────────────────────────────────────────────────

echo $OUTPUT->header();

$sesskey = sesskey();
?>

<style>
.qrd-page  { max-width: 1400px; margin: 0 auto; padding: 16px 20px; font-family: 'Segoe UI', Arial, sans-serif; }
.qrd-filter-bar {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 14px 18px; margin-bottom: 20px;
}
.qrd-filter-bar label  { font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 4px; }
.qrd-filter-bar select,
.qrd-filter-bar input  {
    border: 1.5px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; font-size: 13px;
}
.qrd-stat-bar {
    display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 18px;
}
.qrd-stat {
    display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600;
    padding: 7px 14px; border-radius: 8px;
}
.qrd-stat-broken { background: #fef2f2; color: #b91c1c; border: 1.5px solid #fecaca; }
.qrd-stat-ok     { background: #f0fdf4; color: #166534; border: 1.5px solid #bbf7d0; }
.qrd-stat-total  { background: #f1f5f9; color: #374151; border: 1.5px solid #e2e8f0; }

.qrd-actions {
    display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    margin-bottom: 14px;
}
.qrd-btn {
    display: inline-flex; align-items: center; gap: 6px;
    border: none; border-radius: 7px; padding: 9px 16px;
    font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
}
.qrd-btn-primary { background: #1a56a4; color: #fff; }
.qrd-btn-primary:hover { background: #144280; color: #fff; }
.qrd-btn-danger  { background: #dc2626; color: #fff; }
.qrd-btn-outline { background: #f1f5f9; color: #374151; border: 1.5px solid #e2e8f0; }
.qrd-btn-sm { font-size: 11px; padding: 5px 11px; }

table.qrd-table { width: 100%; border-collapse: collapse; font-size: 12px; }
table.qrd-table thead th {
    background: #f8fafc; color: #374151; font-weight: 700; font-size: 11px;
    text-transform: uppercase; letter-spacing: 0.4px;
    padding: 9px 10px; text-align: left; border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
table.qrd-table tbody tr:nth-child(even) { background: #fafafa; }
table.qrd-table tbody tr.qrd-row-broken  { background: #fff5f5; }
table.qrd-table tbody tr.qrd-row-broken:nth-child(even) { background: #fee2e2; }
table.qrd-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.qrd-badge {
    display: inline-block; padding: 2px 8px; border-radius: 4px;
    font-size: 10px; font-weight: 700;
}
.qrd-ok     { background: #dcfce7; color: #166534; }
.qrd-bad    { background: #fecaca; color: #991b1b; }
.qrd-warn   { background: #fef9c3; color: #854d0e; }
.qrd-bbb    { background: #dbeafe; color: #1e40af; }
.qrd-early  { font-size: 11px; color: #475569; }
.qrd-empty  { color: #94a3b8; font-size: 12px; font-style: italic; padding: 20px; text-align: center; }
.qrd-early-input { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; }
.qrd-early-input input { width: 60px; padding: 4px 7px; border: 1.5px solid #e2e8f0; border-radius: 5px; }
</style>

<div class="qrd-page">

<h2 style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:16px;">
    🔍 Debug QR Asistencia — Sesiones de clases activas
</h2>

<!-- Filter bar -->
<form method="get" action="">
<div class="qrd-filter-bar">
    <div>
        <label>Clase</label>
        <select name="filter_class" style="min-width:220px">
            <option value="0" <?php if ($filter_class == 0) echo 'selected'; ?>>— Todas las clases —</option>
            <?php foreach ($all_classes as $cls): ?>
            <option value="<?php echo (int)$cls->id; ?>" <?php if ($filter_class == $cls->id) echo 'selected'; ?>>
                <?php echo s($cls->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Estado</label>
        <select name="filter_status">
            <option value="broken" <?php if ($filter_status === 'broken') echo 'selected'; ?>>Solo con problemas</option>
            <option value="all"    <?php if ($filter_status === 'all')    echo 'selected'; ?>>Todas</option>
            <option value="ok"     <?php if ($filter_status === 'ok')     echo 'selected'; ?>>Solo correctas</option>
        </select>
    </div>
    <div>
        <label>Días hacia adelante</label>
        <input type="number" name="filter_days" value="<?php echo (int)$filter_days; ?>" min="1" max="365" style="width:80px">
    </div>
    <div style="padding-top:18px">
        <button type="submit" class="qrd-btn qrd-btn-outline">Filtrar</button>
    </div>
</div>
</form>

<!-- Stat bar -->
<div class="qrd-stat-bar">
    <div class="qrd-stat qrd-stat-broken">
        ⚠️ <?php echo $broken_count; ?> con problemas
    </div>
    <div class="qrd-stat qrd-stat-ok">
        ✅ <?php echo $ok_count; ?> correctas
    </div>
    <div class="qrd-stat qrd-stat-total">
        <?php echo count($rows); ?> sesiones en rango
    </div>
    <div style="font-size:11px;color:#64748b;align-self:center">
        Rango: <?php echo date('d/m/Y', $day_start); ?> –
               <?php echo date('d/m/Y', $day_end); ?>
    </div>
</div>

<!-- Bulk fix form -->
<?php if (!empty($display_rows)): ?>
<form method="post" action="" id="qrdFixForm">
    <input type="hidden" name="sesskey" value="<?php echo $sesskey; ?>">
    <input type="hidden" name="bulk_action" value="fix">

    <div class="qrd-actions">
        <button type="button" onclick="qrdSelectAll(true)"  class="qrd-btn qrd-btn-outline qrd-btn-sm">Seleccionar todo</button>
        <button type="button" onclick="qrdSelectAll(false)" class="qrd-btn qrd-btn-outline qrd-btn-sm">Deseleccionar</button>
        <button type="button" onclick="qrdSelectBroken()"  class="qrd-btn qrd-btn-outline qrd-btn-sm" style="border-color:#fecaca;color:#b91c1c">Seleccionar con problemas</button>
        <div class="qrd-early-input">
            <label>Apertura anticipada:</label>
            <input type="number" name="early_open_minutes" value="30" min="0" max="240"> min
            <small style="color:#64748b">(solo si estaba en 0)</small>
        </div>
        <button type="submit" class="qrd-btn qrd-btn-primary" onclick="return confirm('¿Aplicar corrección a las sesiones seleccionadas?')">
            ⚡ Aplicar corrección
        </button>
    </div>

    <div style="overflow-x:auto">
    <table class="qrd-table" id="qrdTable">
        <thead>
            <tr>
                <th style="width:36px"><input type="checkbox" onchange="qrdSelectAll(this.checked)" title="Todo"></th>
                <th>Fecha / Hora</th>
                <th>Clase</th>
                <th>Jornada</th>
                <th>Curso</th>
                <th>Módulo asistencia</th>
                <th>Origen</th>
                <th title="studentscanmark">Permite auto-marcado</th>
                <th title="autoassignstatus">Auto-asigna estado</th>
                <th title="studentsearlyopentime">Apertura anticipada</th>
                <th title="studentpassword">Contraseña QR</th>
                <th title="rotateqrcode">QR rotativo</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($display_rows as $row):
            $is_broken = ((int)$row->studentscanmark !== 1 || (int)$row->autoassignstatus !== 1);
            $rowclass  = $is_broken ? 'qrd-row-broken' : '';
            $sessdate  = userdate((int)$row->sessdate, '%d/%m/%Y %H:%M');
            $is_past   = (int)$row->sessdate < $now;
        ?>
        <tr class="<?php echo $rowclass; ?>" data-broken="<?php echo $is_broken ? '1' : '0'; ?>">
            <td>
                <input type="checkbox" name="fix_sessids[]" value="<?php echo (int)$row->sessid; ?>"
                    class="qrd-chk"
                    <?php echo $is_broken ? 'checked' : ''; ?>>
            </td>
            <td style="white-space:nowrap;<?php echo $is_past ? 'color:#94a3b8' : ''; ?>">
                <?php echo $sessdate; ?>
                <?php if ($is_past): ?>
                    <span class="qrd-badge" style="background:#f1f5f9;color:#64748b">pasada</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/classmanagement.php', ['id' => $row->classid]))->out(); ?>"
                   target="_blank" style="color:#1a56a4;font-weight:700">
                    <?php echo s($row->classname); ?>
                </a>
            </td>
            <td><?php echo s($row->classshift); ?></td>
            <td><?php echo s($row->coursename); ?></td>
            <td style="font-size:10.5px;color:#475569">
                <a href="<?php echo (new moodle_url('/mod/attendance/view.php', ['id' => $row->cmid]))->out(); ?>"
                   target="_blank"><?php echo s($row->attname); ?></a>
            </td>
            <td>
                <?php if ($row->is_bbb): ?>
                    <span class="qrd-badge qrd-bbb">BBB</span>
                <?php else: ?>
                    <span class="qrd-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0">Regular</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center">
                <?php if ((int)$row->studentscanmark === 1): ?>
                    <span class="qrd-badge qrd-ok">✓ Sí</span>
                <?php else: ?>
                    <span class="qrd-badge qrd-bad">✗ No</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center">
                <?php if ((int)$row->autoassignstatus === 1): ?>
                    <span class="qrd-badge qrd-ok">✓ Sí</span>
                <?php else: ?>
                    <span class="qrd-badge qrd-bad">✗ No</span>
                <?php endif; ?>
            </td>
            <td class="qrd-early">
                <?php
                $early = (int)$row->studentsearlyopentime;
                if ($early <= 0): ?>
                    <span class="qrd-badge qrd-warn">0 min</span>
                <?php elseif ($early < 60): ?>
                    <?php echo $early; ?> seg
                <?php else: ?>
                    <?php echo round($early / 60); ?> min
                <?php endif; ?>
            </td>
            <td style="font-size:10.5px;color:#475569">
                <?php echo trim((string)$row->studentpassword) !== '' ? '<span class="qrd-badge qrd-warn">🔑 Con clave</span>' : '—'; ?>
            </td>
            <td style="text-align:center">
                <?php echo (int)$row->rotateqrcode === 1
                    ? '<span class="qrd-badge qrd-bbb">Rotativo</span>'
                    : '<span style="color:#94a3b8;font-size:11px">No</span>'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- overflow-x -->

</form>
<?php else: ?>
<div class="qrd-empty">
    <?php if (count($rows) === 0): ?>
        No se encontraron sesiones de asistencia en el rango seleccionado.
    <?php else: ?>
        ✅ Todas las sesiones en el rango están correctamente configuradas para QR.
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- qrd-page -->

<script>
function qrdSelectAll(checked) {
    document.querySelectorAll('.qrd-chk').forEach(function(cb) { cb.checked = !!checked; });
}
function qrdSelectBroken() {
    document.querySelectorAll('[data-broken]').forEach(function(tr) {
        var cb = tr.querySelector('.qrd-chk');
        if (cb) cb.checked = (tr.dataset.broken === '1');
    });
}
</script>

<?php echo $OUTPUT->footer(); ?>
