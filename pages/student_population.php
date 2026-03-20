<?php
/**
 * Población Estudiantil — distribución por carrera, jornada y horarios activos
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/student_population.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Población Estudiantil');
$PAGE->set_heading('Población Estudiantil');
$PAGE->set_pagelayout('admin');

// ── Session: group management ────────────────────────────────────────────────

global $SESSION;
if (!isset($SESSION->pop_groups) || !is_array($SESSION->pop_groups)) {
    $SESSION->pop_groups = [];
}

$pop_action = optional_param('pop_action', '', PARAM_ALPHA);
if ($pop_action && confirm_sesskey()) {
    if ($pop_action === 'add_group') {
        $gname   = trim(optional_param('group_name', '', PARAM_TEXT));
        $planids = optional_param_array('planids', [], PARAM_INT);
        $planids = array_values(array_filter(array_unique($planids)));
        if ($gname !== '' && count($planids) >= 2) {
            $SESSION->pop_groups[] = ['name' => $gname, 'planids' => $planids];
        }
    } elseif ($pop_action === 'remove_group') {
        $idx = optional_param('group_idx', -1, PARAM_INT);
        if (isset($SESSION->pop_groups[$idx])) {
            array_splice($SESSION->pop_groups, $idx, 1);
        }
    } elseif ($pop_action === 'clear_groups') {
        $SESSION->pop_groups = [];
    }
    redirect($PAGE->url);
}

$pop_groups = $SESSION->pop_groups;

// ── Helpers ──────────────────────────────────────────────────────────────────

function pop_normalize_shift(string $s): string {
    $s = strtolower(trim($s));
    if (in_array($s, ['d', 'diurno', 'diurna', 'dia', 'mañana', 'manana'])) return 'Diurno';
    if (in_array($s, ['n', 'nocturno', 'nocturna', 'noche']))               return 'Nocturno';
    if (in_array($s, ['s', 'sabatino', 'sabatina', 'sabado', 'sábado']))    return 'Sabatino';
    return $s !== '' ? ucfirst($s) : 'Sin jornada';
}

function pop_format_schedule(array $rows): string {
    if (empty($rows)) return '';
    $dayMap = [
        '1'=>'Lun','2'=>'Mar','3'=>'Mié','4'=>'Jue','5'=>'Vie','6'=>'Sáb','7'=>'Dom',
        'L'=>'Lun','M'=>'Mar','X'=>'Mié','J'=>'Jue','V'=>'Vie','S'=>'Sáb','D'=>'Dom',
        'Lunes'=>'Lun','Martes'=>'Mar','Miércoles'=>'Mié','Jueves'=>'Jue',
        'Viernes'=>'Vie','Sábado'=>'Sáb','Domingo'=>'Dom',
    ];
    $grouped = [];
    foreach ($rows as $r) {
        $start    = substr((string)($r->start_time ?? ''), 0, 5);
        $end      = substr((string)($r->end_time   ?? ''), 0, 5);
        $timeKey  = "$start–$end";
        $dayLabel = $dayMap[(string)($r->day ?? '')] ?? (string)($r->day ?? '');
        $grouped[$timeKey][$dayLabel] = true;
    }
    $parts = [];
    foreach ($grouped as $time => $days) {
        $parts[] = implode('/', array_keys($days)) . ' ' . $time;
    }
    return implode('<br>', $parts);
}

function pop_house_svg(): string {
    return '<svg viewBox="0 0 64 64" width="52" height="52" fill="none" xmlns="http://www.w3.org/2000/svg">
        <polygon points="32,6 60,30 56,30 56,58 36,58 36,40 28,40 28,58 8,58 8,30 4,30" stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.12"/>
        <polygon points="32,6 60,30 4,30" stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.25"/>
    </svg>';
}

// ── Fetch field IDs ──────────────────────────────────────────────────────────

$jornada_fieldid = (int)($DB->get_field('user_info_field', 'id', ['shortname' => 'gmkjourney']) ?: 0);
$tc_fieldid      = (int)($DB->get_field('customfield_field', 'id', ['shortname' => 'tc']) ?: 0);

// ── Build group index (planid → group index) ─────────────────────────────────

$planid_to_gidx = [];
foreach ($pop_groups as $gidx => $group) {
    foreach ($group['planids'] as $pid) {
        $planid_to_gidx[(int)$pid] = $gidx;
    }
}

// ── Total active students (distinct) ─────────────────────────────────────────

$total_active = (int)$DB->get_field_sql(
    "SELECT COUNT(DISTINCT u.id)
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id
                                       AND llu.userroleid = 5
                                       AND llu.status = 'activo'
      WHERE u.deleted = 0 AND u.suspended = 0"
);

// ── Students per career (distinct, for header badges) ────────────────────────

$per_career_distinct = $DB->get_records_sql(
    "SELECT llu.learningplanid AS planid,
            COUNT(DISTINCT u.id) AS student_count
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id
                                       AND llu.userroleid = 5
                                       AND llu.status = 'activo'
      WHERE u.deleted = 0 AND u.suspended = 0
      GROUP BY llu.learningplanid"
);
$career_distinct_count = [];
foreach ($per_career_distinct as $pcd) {
    $career_distinct_count[(int)$pcd->planid] = (int)$pcd->student_count;
}

// ── Group distinct counts (true deduplicated across merged plans) ─────────────

$group_distinct_count = [];
foreach ($pop_groups as $gidx => $group) {
    $gpids = array_filter(array_map('intval', $group['planids']));
    if (!empty($gpids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($gpids);
        $group_distinct_count[$gidx] = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT u.id)
               FROM {user} u
               JOIN {local_learning_users} llu ON llu.userid = u.id
                                               AND llu.userroleid = 5
                                               AND llu.status = 'activo'
              WHERE llu.learningplanid $insql
                AND u.deleted = 0 AND u.suspended = 0",
            $inparams
        );
    }
}

// ── Students per career + jornada ────────────────────────────────────────────

$jfield   = $jornada_fieldid ?: -1;
$pop_rows = $DB->get_records_sql(
    "SELECT llu.learningplanid                  AS planid,
            lp.name                             AS planname,
            COALESCE(uid_j.data, '')            AS shift,
            COUNT(DISTINCT u.id)                AS student_count
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id
                                       AND llu.userroleid = 5
                                       AND llu.status = 'activo'
       JOIN {local_learning_plans} lp  ON lp.id = llu.learningplanid
       LEFT JOIN {user_info_data} uid_j ON uid_j.userid  = u.id
                                       AND uid_j.fieldid = :jfield
      WHERE u.deleted = 0 AND u.suspended = 0
      GROUP BY llu.learningplanid, lp.name, uid_j.data
      ORDER BY lp.name, uid_j.data",
    ['jfield' => $jfield]
);

// ── Build available plans list (for group UI) ────────────────────────────────

$available_plans = [];  // planid => planname
foreach ($pop_rows as $row) {
    $pid = (int)$row->planid;
    if (!isset($available_plans[$pid])) {
        $available_plans[$pid] = trim((string)$row->planname);
    }
}
asort($available_plans);

// ── Build career tree ─────────────────────────────────────────────────────────
// Grouped plans merge under a '__GROUP_N' key; individual plans use plan name.

$career_tree    = [];  // key → ['planid'|null, 'planids', 'is_group', 'gidx', 'group_name', 'shifts']
$planid_to_name = [];  // planid → career key (for class assignment)

foreach ($pop_rows as $row) {
    $planid = (int)$row->planid;
    $shift  = pop_normalize_shift((string)$row->shift);

    if (isset($planid_to_gidx[$planid])) {
        // This plan belongs to a group
        $gidx = $planid_to_gidx[$planid];
        $key  = '__GROUP_' . $gidx;
        if (!isset($career_tree[$key])) {
            $career_tree[$key] = [
                'planid'     => null,
                'planids'    => $pop_groups[$gidx]['planids'],
                'is_group'   => true,
                'gidx'       => $gidx,
                'group_name' => $pop_groups[$gidx]['name'],
                'shifts'     => [],
            ];
        }
        $planid_to_name[$planid] = $key;
        if (!isset($career_tree[$key]['shifts'][$shift])) {
            $career_tree[$key]['shifts'][$shift] = ['student_count' => 0, 'classes' => []];
        }
        // Add distinct count per plan (may slightly double-count cross-plan students, group badge is authoritative)
        $career_tree[$key]['shifts'][$shift]['student_count'] += (int)$row->student_count;
    } else {
        // Individual career
        $career = trim((string)$row->planname);
        if (!isset($career_tree[$career])) {
            $career_tree[$career] = [
                'planid'   => $planid,
                'planids'  => [$planid],
                'is_group' => false,
                'shifts'   => [],
            ];
            $planid_to_name[$planid] = $career;
        }
        $career_tree[$career]['shifts'][$shift] = ['student_count' => (int)$row->student_count, 'classes' => []];
    }
}

// ── Active classes ────────────────────────────────────────────────────────────

$now = time();

$tc_join  = $tc_fieldid ? "LEFT JOIN {customfield_data} _cfd ON _cfd.instanceid = gc.corecourseid AND _cfd.fieldid = $tc_fieldid" : '';
$tc_where = $tc_fieldid ? "AND (_cfd.value IS NULL OR _cfd.value <> '1')"                                                          : '';
$tc_join2 = $tc_fieldid ? "JOIN {customfield_data} _cfd ON _cfd.instanceid = gc.corecourseid AND _cfd.fieldid = $tc_fieldid AND _cfd.value = '1'" : '';

$class_sql_select = "SELECT gc.id,
            gc.name           AS classname,
            gc.shift          AS classshift,
            gc.career_label   AS career_label,
            gc.learningplanid AS learningplanid,
            gc.corecourseid   AS corecourseid,
            gc.initdate       AS initdate,
            gc.enddate        AS enddate,
            c.fullname        AS coursefullname,
            CONCAT(u.firstname, ' ', u.lastname) AS teachername,
            COUNT(DISTINCT gcp.userid) AS student_count
       FROM {gmk_class} gc
       LEFT JOIN {course} c              ON c.id    = gc.corecourseid
       LEFT JOIN {user} u                ON u.id    = gc.instructorid
       LEFT JOIN {gmk_course_progre} gcp ON gcp.classid = gc.id AND gcp.status IN (1,2,3)";

$regular_classes = $DB->get_records_sql(
    "$class_sql_select $tc_join
      WHERE gc.approved = 1 AND gc.closed = 0 AND gc.enddate > :now $tc_where
      GROUP BY gc.id, gc.name, gc.shift, gc.career_label, gc.learningplanid,
               gc.corecourseid, gc.initdate, gc.enddate, c.fullname, u.firstname, u.lastname
      ORDER BY gc.career_label, gc.shift, c.fullname",
    ['now' => $now]
);

$tc_classes = $tc_fieldid ? $DB->get_records_sql(
    "$class_sql_select $tc_join2
      WHERE gc.approved = 1 AND gc.closed = 0 AND gc.enddate > :now
      GROUP BY gc.id, gc.name, gc.shift, gc.career_label, gc.learningplanid,
               gc.corecourseid, gc.initdate, gc.enddate, c.fullname, u.firstname, u.lastname
      ORDER BY c.fullname, gc.shift",
    ['now' => $now]
) : [];

// ── Load schedules ────────────────────────────────────────────────────────────

$all_ids = array_merge(array_keys($regular_classes), array_keys($tc_classes));
$schedules_by_class = [];
if (!empty($all_ids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($all_ids);
    try {
        $sched_rows = $DB->get_records_sql(
            "SELECT id, classid, day, start_time, end_time
               FROM {gmk_class_schedules}
              WHERE classid $insql
              ORDER BY classid, day, start_time",
            $inparams
        );
        foreach ($sched_rows as $sr) {
            $schedules_by_class[(int)$sr->classid][] = $sr;
        }
    } catch (Exception $e) {
        // table may not exist
    }
}

// ── Assign classes to career tree ─────────────────────────────────────────────

foreach ($regular_classes as $cls) {
    $planid = (int)$cls->learningplanid;
    $shift  = pop_normalize_shift((string)($cls->classshift ?? ''));

    $treeKey = $planid_to_name[$planid] ?? null;

    // Fallback: text match on career_label
    if (!$treeKey && !empty($cls->career_label)) {
        foreach (array_keys($career_tree) as $cn) {
            if (stripos($cn, $cls->career_label) !== false || stripos($cls->career_label, $cn) !== false) {
                $treeKey = $cn;
                break;
            }
        }
    }

    if (!$treeKey) continue;

    if (!isset($career_tree[$treeKey]['shifts'][$shift])) {
        $career_tree[$treeKey]['shifts'][$shift] = ['student_count' => 0, 'classes' => []];
    }
    $career_tree[$treeKey]['shifts'][$shift]['classes'][] = $cls;
}

// ── Sort shifts within each career ───────────────────────────────────────────

$shift_order = ['Diurno' => 1, 'Nocturno' => 2, 'Sabatino' => 3];
foreach ($career_tree as &$cdata) {
    uksort($cdata['shifts'], function($a, $b) use ($shift_order) {
        return ($shift_order[$a] ?? 9) <=> ($shift_order[$b] ?? 9);
    });
}
unset($cdata);

// ── Output ────────────────────────────────────────────────────────────────────

echo $OUTPUT->header();

$sesskey = sesskey();
$pageurl = $PAGE->url->out(false);
?>
<style>
/* ── Layout ────────────────────────────────────────────── */
.pop-page { max-width: 1400px; margin: 0 auto; padding: 16px 20px; font-family: 'Segoe UI', Arial, sans-serif; }

/* ── Top bar ───────────────────────────────────────────── */
.pop-topbar {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 16px; margin-bottom: 6px; flex-wrap: wrap;
}
.pop-total-block { text-align: right; }
.pop-total-label { font-size: 14px; font-weight: 600; color: #2d3748; }
.pop-total-number { font-size: 40px; font-weight: 900; color: #1a56a4; line-height: 1; display: block; }
.pop-disclaimer { font-size: 11px; color: #64748b; font-style: italic; margin-top: 3px; }

/* ── Group management button ───────────────────────────── */
.pop-group-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: #1a56a4; color: #fff; border: none; border-radius: 8px;
    padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
    text-decoration: none; white-space: nowrap;
}
.pop-group-btn:hover { background: #144280; color: #fff; text-decoration: none; }
.pop-group-btn-secondary {
    background: #f1f5f9; color: #374151; border: 1.5px solid #e2e8f0;
}
.pop-group-btn-secondary:hover { background: #e2e8f0; color: #374151; }

/* ── Group panel ───────────────────────────────────────── */
.pop-group-panel {
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 18px 20px; margin-bottom: 24px; display: none;
}
.pop-group-panel.pop-open { display: block; }
.pop-group-panel h3 { font-size: 13px; font-weight: 800; color: #1e293b; margin: 0 0 14px 0; text-transform: uppercase; letter-spacing: 0.5px; }

/* Existing groups list */
.pop-groups-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
.pop-group-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;
    border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;
}
.pop-group-tag button {
    background: none; border: none; color: #1e40af; cursor: pointer;
    padding: 0; line-height: 1; font-size: 14px; opacity: 0.7;
}
.pop-group-tag button:hover { opacity: 1; }

/* New group form */
.pop-new-group-form { border-top: 1.5px solid #e2e8f0; padding-top: 16px; }
.pop-new-group-form label { font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 6px; }
.pop-new-group-form input[type=text] {
    width: 100%; max-width: 340px; border: 1.5px solid #e2e8f0; border-radius: 6px;
    padding: 7px 10px; font-size: 13px; margin-bottom: 12px;
}
.pop-plans-checkboxes {
    display: flex; flex-wrap: wrap; gap: 6px 14px; margin-bottom: 14px;
}
.pop-plans-checkboxes label {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 400; color: #374151; cursor: pointer;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 4px 10px; margin: 0; transition: background 0.15s;
}
.pop-plans-checkboxes label:hover { background: #eff6ff; border-color: #93c5fd; }
.pop-plans-checkboxes input[type=checkbox] { accent-color: #1a56a4; }
.pop-form-actions { display: flex; gap: 8px; align-items: center; }

/* ── Career section ────────────────────────────────────── */
.pop-career-section { margin-bottom: 32px; }
.pop-career-title {
    font-size: 12px; font-weight: 800; letter-spacing: 1px;
    color: #2d3748; text-transform: uppercase;
    margin: 0 0 10px 0; padding-bottom: 4px;
    border-bottom: 2px solid #e2e8f0;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.pop-career-badge {
    font-size: 11px; font-weight: 700; letter-spacing: 0; text-transform: none;
    background: #e2e8f0; color: #374151; border-radius: 12px;
    padding: 2px 10px; white-space: nowrap; flex-shrink: 0;
}
.pop-career-title.pop-is-group {
    border-bottom-color: #93c5fd; color: #1e40af;
}
.pop-career-title.pop-is-group .pop-career-badge {
    background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;
}
.pop-group-label {
    font-size: 9px; font-weight: 700; letter-spacing: 1px;
    background: #1a56a4; color: #fff; border-radius: 3px;
    padding: 1px 5px; margin-right: 6px; text-transform: uppercase; vertical-align: middle;
}
.pop-houses-row { display: flex; flex-wrap: wrap; gap: 14px; }

/* ── House card ────────────────────────────────────────── */
.pop-house-card {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 14px; min-width: 200px; max-width: 280px; flex: 1 1 200px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); transition: box-shadow 0.2s;
}
.pop-house-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.10); }
.pop-house-card.pop-active {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-color: #66bb6a; color: #1b5e20;
}
.pop-house-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.pop-house-icon svg { color: inherit; opacity: 0.85; }
.pop-house-meta { flex: 1; }
.pop-house-shift { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: inherit; line-height: 1.2; }
.pop-house-count { font-size: 22px; font-weight: 900; line-height: 1.1; color: inherit; }
.pop-house-count small { font-size: 12px; font-weight: 500; opacity: 0.75; margin-left: 3px; }

/* ── Class chips inside house ──────────────────────────── */
.pop-classes-inner { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; }
.pop-class-chip {
    background: rgba(255,255,255,0.70); border: 1px solid rgba(0,0,0,0.10);
    border-radius: 6px; padding: 6px 9px; font-size: 11px; backdrop-filter: blur(4px);
}
.pop-class-chip-name {
    font-weight: 700; color: #1a3a5c; font-size: 11px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;
}
.pop-class-chip-teacher { color: #475569; font-size: 10px; margin-top: 1px; }
.pop-class-chip-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; margin-top: 3px; }
.pop-class-chip-sched { color: #374151; font-size: 10px; line-height: 1.35; }
.pop-class-chip-count {
    background: #1a56a4; color: #fff; border-radius: 4px;
    padding: 1px 6px; font-size: 10px; font-weight: 700; white-space: nowrap; flex-shrink: 0;
}

/* ── TRONCO COMÚN ──────────────────────────────────────── */
.pop-tc-section { margin-bottom: 32px; }
.pop-tc-title {
    font-size: 12px; font-weight: 800; letter-spacing: 1px;
    color: #2d3748; text-transform: uppercase;
    margin: 0 0 10px 0; padding-bottom: 4px; border-bottom: 2px solid #e2e8f0;
}
.pop-tc-house-card {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px;
    overflow: hidden; min-width: 240px; max-width: 320px;
    flex: 1 1 240px; box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.pop-tc-house-header {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border-bottom: 1.5px solid #90caf9;
    padding: 10px 12px; display: flex; align-items: center; gap: 8px; color: #0d3c6b;
}
.pop-tc-house-title { font-size: 12px; font-weight: 700; }
.pop-tc-house-body  { padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.pop-tc-chip { border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 9px; font-size: 11px; background: #f8fafc; }
.pop-tc-chip-teacher { color: #475569; font-size: 10px; margin-top: 1px; }
.pop-tc-chip-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; margin-top: 3px; }
.pop-tc-chip-sched { color: #374151; font-size: 10px; line-height: 1.35; }
.pop-tc-chip-count {
    background: #0d3c6b; color: #fff; border-radius: 4px;
    padding: 1px 6px; font-size: 10px; font-weight: 700; white-space: nowrap; flex-shrink: 0;
}

/* ── Empty ──────────────────────────────────────────────── */
.pop-empty { color: #94a3b8; font-size: 12px; font-style: italic; padding: 4px 0; }
</style>

<div class="pop-page">

    <!-- Top bar ──────────────────────────────────────────────────── -->
    <div class="pop-topbar">
        <button class="pop-group-btn" onclick="document.getElementById('popGroupPanel').classList.toggle('pop-open')">
            &#9776; Gestionar grupos
        </button>
        <div class="pop-total-block">
            <span class="pop-total-label">Total estudiantes activos:</span>
            <span class="pop-total-number"><?php echo $total_active; ?></span>
            <div class="pop-disclaimer">* Sin duplicados. Un estudiante en varias carreras aparece en cada una.</div>
        </div>
    </div>

    <!-- Group management panel ───────────────────────────────────── -->
    <div id="popGroupPanel" class="pop-group-panel <?php echo !empty($pop_groups) ? 'pop-open' : ''; ?>">
        <h3>Grupos de planes de estudio</h3>

        <?php if (!empty($pop_groups)): ?>
        <div class="pop-groups-list">
            <?php foreach ($pop_groups as $gidx => $group): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="pop_action"  value="remove_group">
                <input type="hidden" name="sesskey"     value="<?php echo $sesskey; ?>">
                <input type="hidden" name="group_idx"   value="<?php echo (int)$gidx; ?>">
                <span class="pop-group-tag">
                    <?php echo s($group['name']); ?>
                    <small style="opacity:0.7;font-weight:400">
                        (<?php echo implode(' + ', array_map(fn($p) => s($available_plans[$p] ?? "Plan $p"), $group['planids'])); ?>)
                    </small>
                    <button type="submit" title="Eliminar grupo">&#10005;</button>
                </span>
            </form>
            <?php endforeach; ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="pop_action" value="clear_groups">
                <input type="hidden" name="sesskey"    value="<?php echo $sesskey; ?>">
                <button type="submit" class="pop-group-btn pop-group-btn-secondary" style="font-size:11px;padding:4px 10px">
                    Limpiar todos
                </button>
            </form>
        </div>
        <?php endif; ?>

        <div class="pop-new-group-form">
            <form method="post">
                <input type="hidden" name="pop_action" value="add_group">
                <input type="hidden" name="sesskey"    value="<?php echo $sesskey; ?>">
                <label>Nombre del grupo</label>
                <input type="text" name="group_name" placeholder="Ej: Ingeniería + Sistemas" required>
                <label>Selecciona 2 o más planes</label>
                <div class="pop-plans-checkboxes">
                    <?php foreach ($available_plans as $pid => $pname): ?>
                    <label>
                        <input type="checkbox" name="planids[]" value="<?php echo (int)$pid; ?>">
                        <?php echo s($pname); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="pop-form-actions">
                    <button type="submit" class="pop-group-btn">Crear grupo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Career sections ──────────────────────────────────────────── -->
    <?php if (empty($career_tree)): ?>
        <div class="alert alert-warning">No hay estudiantes activos registrados.</div>
    <?php else: foreach ($career_tree as $careerKey => $careerData): ?>

    <div class="pop-career-section">
        <?php if ($careerData['is_group']): ?>
        <h2 class="pop-career-title pop-is-group">
            <span>
                <span class="pop-group-label">grupo</span>
                <?php echo s(strtoupper($careerData['group_name'])); ?>
            </span>
            <?php $gTotal = $group_distinct_count[$careerData['gidx']] ?? 0; ?>
            <?php if ($gTotal > 0): ?>
            <span class="pop-career-badge"><?php echo $gTotal; ?> estudiantes</span>
            <?php endif; ?>
        </h2>
        <?php else: ?>
        <h2 class="pop-career-title">
            <span><?php echo s(strtoupper($careerKey)); ?></span>
            <?php $cTotal = $career_distinct_count[$careerData['planid']] ?? 0; ?>
            <?php if ($cTotal > 0): ?>
            <span class="pop-career-badge"><?php echo $cTotal; ?> estudiantes</span>
            <?php endif; ?>
        </h2>
        <?php endif; ?>

        <div class="pop-houses-row">
        <?php foreach ($careerData['shifts'] as $shiftName => $shiftData):
            $isActive = $shiftData['student_count'] > 0 || !empty($shiftData['classes']);
        ?>
            <div class="pop-house-card <?php echo $isActive ? 'pop-active' : ''; ?>">
                <div class="pop-house-header">
                    <div class="pop-house-icon"><?php echo pop_house_svg(); ?></div>
                    <div class="pop-house-meta">
                        <div class="pop-house-shift"><?php echo s($shiftName); ?></div>
                        <div class="pop-house-count">
                            <?php echo $shiftData['student_count']; ?>
                            <small>estudiantes</small>
                        </div>
                    </div>
                </div>

                <?php if (!empty($shiftData['classes'])): ?>
                <div class="pop-classes-inner">
                    <?php foreach ($shiftData['classes'] as $cls):
                        $schedHtml = pop_format_schedule($schedules_by_class[(int)$cls->id] ?? []);
                        $cname = trim((string)($cls->coursefullname ?: $cls->classname));
                    ?>
                    <div class="pop-class-chip">
                        <div class="pop-class-chip-name" title="<?php echo s($cname); ?>"><?php echo s($cname); ?></div>
                        <div class="pop-class-chip-teacher"><?php echo s(trim($cls->teachername)); ?></div>
                        <div class="pop-class-chip-row">
                            <div class="pop-class-chip-sched">
                                <?php echo $schedHtml ?: '<span style="color:#94a3b8">Sin horario</span>'; ?>
                            </div>
                            <div class="pop-class-chip-count"><?php echo (int)$cls->student_count; ?> est.</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="pop-empty">Sin clases activas</div>
                <?php endif; ?>
            </div><!-- /pop-house-card -->
        <?php endforeach; ?>
        </div><!-- /pop-houses-row -->
    </div><!-- /pop-career-section -->

    <?php endforeach; endif; ?>

    <!-- TRONCO COMÚN ─────────────────────────────────────────────── -->
    <?php if (!empty($tc_classes)):
        $tc_by_course = [];
        foreach ($tc_classes as $cls) {
            $coursename = trim((string)($cls->coursefullname ?: $cls->classname));
            $tc_by_course[$coursename][] = $cls;
        }
    ?>
    <div class="pop-tc-section">
        <h2 class="pop-tc-title">Tronco Común</h2>
        <div class="pop-houses-row">
        <?php foreach ($tc_by_course as $courseName => $groups): ?>
            <div class="pop-tc-house-card">
                <div class="pop-tc-house-header">
                    <?php echo pop_house_svg(); ?>
                    <div class="pop-tc-house-title"><?php echo s($courseName); ?></div>
                </div>
                <div class="pop-tc-house-body">
                <?php foreach ($groups as $cls):
                    $schedHtml = pop_format_schedule($schedules_by_class[(int)$cls->id] ?? []);
                    $shift     = pop_normalize_shift((string)($cls->classshift ?? ''));
                ?>
                    <div class="pop-tc-chip">
                        <div class="pop-tc-chip-teacher"><?php echo s(trim($cls->teachername)); ?></div>
                        <div class="pop-tc-chip-row">
                            <div class="pop-tc-chip-sched">
                                <span style="background:#e2e8f0;border-radius:3px;padding:1px 5px;font-size:9px;font-weight:700;margin-right:3px;"><?php echo s($shift); ?></span>
                                <?php echo $schedHtml ?: '<span style="color:#94a3b8">Sin horario</span>'; ?>
                            </div>
                            <div class="pop-tc-chip-count"><?php echo (int)$cls->student_count; ?> est.</div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /pop-page -->
<?php echo $OUTPUT->footer(); ?>
