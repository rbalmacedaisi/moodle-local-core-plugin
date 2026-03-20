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
    return implode(', ', $parts);
}

function pop_house_svg(): string {
    return '<svg viewBox="0 0 64 64" width="40" height="40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <polygon points="32,6 60,30 56,30 56,58 36,58 36,40 28,40 28,58 8,58 8,30 4,30" stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.12"/>
        <polygon points="32,6 60,30 4,30" stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.25"/>
    </svg>';
}

// ── Fetch field IDs ──────────────────────────────────────────────────────────

$jornada_fieldid = (int)($DB->get_field('user_info_field',  'id', ['shortname' => 'gmkjourney'])     ?: 0);
$tc_fieldid      = (int)($DB->get_field('customfield_field','id', ['shortname' => 'tc'])            ?: 0);
$doc_fieldid     = (int)($DB->get_field('user_info_field',  'id', ['shortname' => 'documentnumber']) ?: 0);

// ── Build group index ────────────────────────────────────────────────────────

$planid_to_gidx = [];
foreach ($pop_groups as $gidx => $group) {
    foreach ($group['planids'] as $pid) {
        $planid_to_gidx[(int)$pid] = $gidx;
    }
}

// $total_active se calcula más abajo, después de procesar los buckets de clases.

// ── Students per career (distinct, for career header badges) ─────────────────

$per_career_distinct = $DB->get_records_sql(
    "SELECT llu.learningplanid AS planid,
            COUNT(DISTINCT u.id) AS student_count
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id
                                       AND llu.userroleid = 5
                                       AND llu.status = 'activo'
      WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2
      GROUP BY llu.learningplanid"
);
$career_distinct_count = [];
foreach ($per_career_distinct as $pcd) {
    $career_distinct_count[(int)$pcd->planid] = (int)$pcd->student_count;
}

// ── Group distinct counts (true dedup across merged plans) ───────────────────

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
                AND u.deleted = 0 AND u.suspended = 0 AND u.id > 2",
            $inparams
        );
    }
}

// ── Plan names (for career tree and group UI) ────────────────────────────────

$pop_rows = $DB->get_records_sql(
    "SELECT DISTINCT llu.learningplanid AS planid,
            lp.name                     AS planname
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id
                                       AND llu.userroleid = 5
                                       AND llu.status = 'activo'
       JOIN {local_learning_plans} lp  ON lp.id = llu.learningplanid
      WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2
      ORDER BY lp.name"
);

$available_plans = [];
foreach ($pop_rows as $row) {
    $available_plans[(int)$row->planid] = trim((string)$row->planname);
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
               gc.corecourseid, c.fullname, u.firstname, u.lastname
      ORDER BY gc.career_label, gc.shift, c.fullname",
    ['now' => $now]
);

$tc_classes = $tc_fieldid ? $DB->get_records_sql(
    "$class_sql_select $tc_join2
      WHERE gc.approved = 1 AND gc.closed = 0 AND gc.enddate > :now
      GROUP BY gc.id, gc.name, gc.shift, gc.career_label, gc.learningplanid,
               gc.corecourseid, c.fullname, u.firstname, u.lastname
      ORDER BY c.fullname, gc.shift",
    ['now' => $now]
) : [];

// (house student counts computed after class assignment — see below)

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
        // table may not exist yet
    }
}

// ── Build career tree (structure from plan names, counts from class data) ─────

$career_tree    = [];  // key → ['planid', 'planids', 'is_group', 'gidx', 'group_name', 'shifts']
$planid_to_name = [];  // planid → tree key

foreach ($pop_rows as $row) {
    $planid = (int)$row->planid;
    if (isset($planid_to_gidx[$planid])) {
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
    } else {
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
    }
}

// ── Assign regular classes to career tree (creates shift buckets) ─────────────

foreach ($regular_classes as $cls) {
    $planid  = (int)$cls->learningplanid;
    $shift   = pop_normalize_shift((string)($cls->classshift ?? ''));
    $treeKey = $planid_to_name[$planid] ?? null;

    // Fallback: career_label text match
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

// ── Compute distinct students per shift bucket (post-assignment) ──────────────
// We use the actual class IDs in each bucket — avoids planid/career_label mismatch.

// 1. Map every assigned classid → (careerKey, shiftName)
$classid_to_bucket = [];
foreach ($career_tree as $key => $cdata) {
    foreach ($cdata['shifts'] as $shift => $shiftData) {
        foreach ($shiftData['classes'] as $cls) {
            $classid_to_bucket[(int)$cls->id] = ['key' => $key, 'shift' => $shift];
        }
    }
}

// 2. Single query: enrolled userids — only for academically active students
//    (llu.status = 'activo' ensures the student is still active in their plan)
$bucket_users = [];   // [careerKey . '||' . shift] => [userid => true]
if (!empty($classid_to_bucket)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($classid_to_bucket));
    $rs = $DB->get_recordset_sql(
        "SELECT DISTINCT gcp.classid, gcp.userid
           FROM {gmk_course_progre} gcp
           JOIN {gmk_class} gc ON gc.id = gcp.classid
           JOIN {local_learning_users} llu ON llu.userid = gcp.userid
                                          AND llu.learningplanid = gc.learningplanid
                                          AND llu.userroleid = 5
                                          AND llu.status = 'activo'
          WHERE gcp.classid $insql AND gcp.status IN (1,2,3)",
        $inparams
    );
    foreach ($rs as $row) {
        $cid = (int)$row->classid;
        $uid = (int)$row->userid;
        if (!isset($classid_to_bucket[$cid])) continue;
        $b    = $classid_to_bucket[$cid];
        $bkey = $b['key'] . '||' . $b['shift'];
        $bucket_users[$bkey][$uid] = true;
    }
    $rs->close();
}

// 3. Push counts into career tree
foreach ($career_tree as $key => &$cdata) {
    foreach ($cdata['shifts'] as $shift => &$shiftData) {
        $bkey = $key . '||' . $shift;
        $shiftData['student_count'] = isset($bucket_users[$bkey]) ? count($bucket_users[$bkey]) : 0;
    }
    unset($shiftData);
}
unset($cdata);

// ── Build uid → careers label map ────────────────────────────────────────────

$uid_to_careers = [];   // uid => ['Carrera / Jornada', ...]
foreach ($bucket_users as $bkey => $bkt_users) {
    [$bCareerKey, $bShift] = explode('||', $bkey, 2);
    $bLabel = isset($career_tree[$bCareerKey])
        ? ($career_tree[$bCareerKey]['is_group']
            ? $career_tree[$bCareerKey]['group_name']
            : $bCareerKey)
        : $bCareerKey;
    foreach ($bkt_users as $buid => $_) {
        $uid_to_careers[$buid][$bLabel . ' / ' . $bShift] = true;
    }
}

// ── Total activo + lista de estudiantes (dedup por idnumber) ─────────────────

$_all_uids = [];
foreach ($bucket_users as $_users) {
    foreach ($_users as $_uid => $_) {
        $_all_uids[$_uid] = true;
    }
}

$total_active  = 0;
$student_list  = [];   // ident_key => [idnumber, name, email, careers[]]

if (!empty($_all_uids)) {
    [$_insql, $_inparams] = $DB->get_in_or_equal(array_keys($_all_uids));

    // Join user_info_data to get the actual document number (cedula)
    $_doc_join  = $doc_fieldid
        ? "LEFT JOIN {user_info_data} _uid_doc ON _uid_doc.userid = u.id AND _uid_doc.fieldid = $doc_fieldid"
        : '';
    $_doc_select = $doc_fieldid ? ", COALESCE(_uid_doc.data, '') AS documentnumber" : ", '' AS documentnumber";

    $_rs = $DB->get_recordset_sql(
        "SELECT u.id, u.idnumber, u.firstname, u.lastname, u.email $_doc_select
           FROM {user} u $_doc_join
          WHERE u.id $_insql
          ORDER BY u.lastname, u.firstname",
        $_inparams
    );
    foreach ($_rs as $_row) {
        $_uid = (int)$_row->id;
        // documentnumber (cedula) is preferred; fallback to idnumber, then uid
        $_doc   = trim((string)$_row->documentnumber);
        $_idn   = trim((string)$_row->idnumber);
        $_ident = $_doc !== '' ? 'doc:' . $_doc
                : ($_idn !== '' ? 'idn:' . $_idn : 'u:' . $_uid);
        if (!isset($student_list[$_ident])) {
            $student_list[$_ident] = [
                'idnumber' => $_doc ?: ($_idn ?: '—'),
                'name'     => trim($_row->firstname . ' ' . $_row->lastname),
                'email'    => (string)$_row->email,
                'careers'  => [],
            ];
        }
        foreach (array_keys($uid_to_careers[$_uid] ?? []) as $_car) {
            $student_list[$_ident]['careers'][$_car] = true;
        }
    }
    $_rs->close();
    // Flatten careers sets to sorted arrays
    foreach ($student_list as &$_sl) {
        $_sl['careers'] = array_keys($_sl['careers']);
        sort($_sl['careers']);
    }
    unset($_sl);
    $total_active = count($student_list);
}
unset($_all_uids, $uid_to_careers);

// ── Sort shifts ───────────────────────────────────────────────────────────────

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
?>
<style>
/* ── Layout ─────────────────────────────────────────────────────── */
.pop-page { max-width: 1400px; margin: 0 auto; padding: 16px 20px; font-family: 'Segoe UI', Arial, sans-serif; }

/* ── Top bar ─────────────────────────────────────────────────────── */
.pop-topbar {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 16px; margin-bottom: 8px; flex-wrap: wrap;
}
.pop-total-block { text-align: right; }
.pop-total-label  { font-size: 14px; font-weight: 600; color: #2d3748; }
.pop-total-number {
    font-size: 42px; font-weight: 900; color: #1a56a4; line-height: 1; display: inline-block;
    cursor: pointer; border-bottom: 2px dashed #93c5fd; transition: color 0.15s;
}
.pop-total-number:hover { color: #1e40af; }
.pop-disclaimer   { font-size: 11px; color: #64748b; font-style: italic; margin-top: 3px; }

/* ── Student list modal ───────────────────────────────────────────── */
.pop-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 9000;
    align-items: center; justify-content: center;
}
.pop-modal-overlay.pop-modal-open { display: flex; }
.pop-modal {
    background: #fff; border-radius: 12px; width: 92%; max-width: 900px;
    max-height: 88vh; display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.pop-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1.5px solid #e2e8f0; flex-shrink: 0;
}
.pop-modal-header h2 { font-size: 15px; font-weight: 800; color: #1e293b; margin: 0; }
.pop-modal-header-actions { display: flex; gap: 8px; align-items: center; }
.pop-modal-close {
    background: none; border: none; font-size: 20px; cursor: pointer;
    color: #64748b; line-height: 1; padding: 2px 6px; border-radius: 4px;
}
.pop-modal-close:hover { background: #f1f5f9; color: #1e293b; }
.pop-modal-toolbar {
    padding: 12px 20px; border-bottom: 1px solid #f1f5f9; flex-shrink: 0;
    display: flex; gap: 10px; align-items: center;
}
.pop-modal-search {
    flex: 1; border: 1.5px solid #e2e8f0; border-radius: 7px;
    padding: 7px 12px; font-size: 13px;
}
.pop-modal-search:focus { outline: none; border-color: #93c5fd; }
.pop-modal-body { overflow-y: auto; flex: 1; }
.pop-student-table {
    width: 100%; border-collapse: collapse; font-size: 12px;
}
.pop-student-table thead th {
    background: #f8fafc; color: #374151; font-weight: 700; font-size: 11px;
    text-transform: uppercase; letter-spacing: 0.5px;
    padding: 8px 12px; text-align: left; position: sticky; top: 0;
    border-bottom: 1.5px solid #e2e8f0;
}
.pop-student-table tbody tr:nth-child(even) { background: #fafafa; }
.pop-student-table tbody tr:hover { background: #eff6ff; }
.pop-student-table tbody td { padding: 7px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.pop-student-table .td-idn { font-weight: 700; color: #1a56a4; white-space: nowrap; }
.pop-student-table .td-careers { color: #475569; font-size: 10.5px; line-height: 1.5; }
.pop-modal-footer {
    padding: 10px 20px; border-top: 1.5px solid #e2e8f0; flex-shrink: 0;
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; color: #64748b;
}

/* ── Buttons ──────────────────────────────────────────────────────── */
.pop-group-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: #1a56a4; color: #fff; border: none; border-radius: 8px;
    padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
    text-decoration: none; white-space: nowrap;
}
.pop-group-btn:hover { background: #144280; color: #fff; }
.pop-group-btn-sm {
    font-size: 11px; padding: 4px 10px; border-radius: 6px;
}
.pop-group-btn-outline {
    background: #f1f5f9; color: #374151; border: 1.5px solid #e2e8f0;
}
.pop-group-btn-outline:hover { background: #e2e8f0; }

/* ── Group panel ─────────────────────────────────────────────────── */
.pop-group-panel {
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 18px 20px; margin-bottom: 24px; display: none;
}
.pop-group-panel.pop-open { display: block; }
.pop-group-panel > h3 {
    font-size: 13px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.5px; color: #1e293b; margin: 0 0 14px 0;
}
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
.pop-new-group-form { border-top: 1.5px solid #e2e8f0; padding-top: 16px; }
.pop-new-group-form > label { font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 5px; }
.pop-new-group-form input[type=text] {
    width: 100%; max-width: 340px; border: 1.5px solid #e2e8f0; border-radius: 6px;
    padding: 7px 10px; font-size: 13px; margin-bottom: 12px; box-sizing: border-box;
}
.pop-plans-checkboxes { display: flex; flex-wrap: wrap; gap: 5px 10px; margin-bottom: 14px; }
.pop-plans-checkboxes label {
    display: inline-flex; align-items: center; gap: 5px; font-weight: 400;
    font-size: 12px; color: #374151; cursor: pointer;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 4px 10px; margin: 0; transition: background 0.15s;
}
.pop-plans-checkboxes label:hover { background: #eff6ff; border-color: #93c5fd; }
.pop-plans-checkboxes input[type=checkbox] { accent-color: #1a56a4; }

/* ── Career section ──────────────────────────────────────────────── */
.pop-career-section { margin-bottom: 28px; }
.pop-career-title {
    font-size: 12px; font-weight: 800; letter-spacing: 1px;
    color: #2d3748; text-transform: uppercase;
    margin: 0 0 10px 0; padding-bottom: 5px;
    border-bottom: 2px solid #e2e8f0;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.pop-career-title.pop-is-group { border-bottom-color: #93c5fd; color: #1e40af; }
.pop-career-badge {
    font-size: 11px; font-weight: 700; letter-spacing: 0; text-transform: none;
    background: #e2e8f0; color: #374151; border-radius: 12px;
    padding: 2px 10px; white-space: nowrap; flex-shrink: 0;
}
.pop-career-title.pop-is-group .pop-career-badge {
    background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;
}
.pop-group-label {
    font-size: 9px; font-weight: 700; letter-spacing: 1px;
    background: #1a56a4; color: #fff; border-radius: 3px;
    padding: 1px 5px; margin-right: 6px; text-transform: uppercase; vertical-align: middle;
}

/* ── House card — FULL WIDTH, chips in grid ──────────────────────── */
.pop-houses-row { display: flex; flex-direction: column; gap: 12px; }

.pop-house-card {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 14px 16px; width: 100%; box-sizing: border-box;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.pop-house-card.pop-active {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-color: #86efac; color: #14532d;
}

.pop-house-header {
    display: flex; align-items: center; gap: 12px; margin-bottom: 12px;
}
.pop-house-icon { flex-shrink: 0; }
.pop-house-icon svg { color: inherit; opacity: 0.85; }
.pop-house-meta { flex: 1; min-width: 0; }
.pop-house-shift {
    font-size: 13px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.5px; color: inherit; line-height: 1.2;
}
.pop-house-count {
    font-size: 26px; font-weight: 900; line-height: 1.1; color: inherit;
}
.pop-house-count small {
    font-size: 12px; font-weight: 500; opacity: 0.7; margin-left: 4px;
}

/* ── Class chips — responsive grid ──────────────────────────────── */
.pop-classes-inner {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 8px;
}
.pop-class-chip {
    background: rgba(255,255,255,0.80); border: 1px solid rgba(0,0,0,0.09);
    border-radius: 7px; padding: 8px 10px; font-size: 11px;
    backdrop-filter: blur(4px); min-width: 0;
}
.pop-class-chip-name {
    font-weight: 700; color: #1a3a5c; font-size: 11.5px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pop-class-chip-teacher { color: #475569; font-size: 10px; margin-top: 2px; }
.pop-class-chip-row {
    display: flex; justify-content: space-between; align-items: center;
    gap: 6px; margin-top: 5px;
}
.pop-class-chip-sched { color: #374151; font-size: 10px; line-height: 1.35; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pop-class-chip-count {
    background: #1a56a4; color: #fff; border-radius: 4px;
    padding: 2px 7px; font-size: 10px; font-weight: 700; white-space: nowrap; flex-shrink: 0;
}

/* ── TRONCO COMÚN ────────────────────────────────────────────────── */
.pop-tc-section { margin-bottom: 28px; }
.pop-tc-title {
    font-size: 12px; font-weight: 800; letter-spacing: 1px;
    color: #2d3748; text-transform: uppercase;
    margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid #e2e8f0;
}
.pop-tc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}
.pop-tc-house-card {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px;
    overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.pop-tc-house-header {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border-bottom: 1.5px solid #90caf9;
    padding: 10px 12px; display: flex; align-items: center; gap: 8px; color: #0d3c6b;
}
.pop-tc-house-title { font-size: 12px; font-weight: 700; }
.pop-tc-house-body  { padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.pop-tc-chip { border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 9px; font-size: 11px; background: #f8fafc; }
.pop-tc-chip-teacher { color: #475569; font-size: 10px; }
.pop-tc-chip-row { display: flex; justify-content: space-between; align-items: center; gap: 6px; margin-top: 4px; }
.pop-tc-chip-sched { color: #374151; font-size: 10px; line-height: 1.35; }
.pop-tc-chip-count {
    background: #0d3c6b; color: #fff; border-radius: 4px;
    padding: 2px 7px; font-size: 10px; font-weight: 700; white-space: nowrap; flex-shrink: 0;
}

/* ── Misc ────────────────────────────────────────────────────────── */
.pop-empty { color: #94a3b8; font-size: 12px; font-style: italic; padding: 2px 0; }
</style>

<div class="pop-page">

    <!-- Top bar ─────────────────────────────────────────────────────── -->
    <div class="pop-topbar">
        <button class="pop-group-btn"
            onclick="var p=document.getElementById('popGroupPanel');p.classList.toggle('pop-open')">
            &#9776;&nbsp; Gestionar grupos
        </button>
        <div class="pop-total-block">
            <span class="pop-total-label">Total estudiantes activos:</span><br>
            <span class="pop-total-number" onclick="popOpenModal()" title="Ver listado de estudiantes">
                <?php echo $total_active; ?>
            </span>
            <div class="pop-disclaimer">
                * Deduplicado por cédula. Clic para ver listado.
            </div>
        </div>
    </div>

    <!-- Group management panel ──────────────────────────────────────── -->
    <div id="popGroupPanel" class="pop-group-panel <?php echo !empty($pop_groups) ? 'pop-open' : ''; ?>">
        <h3>Grupos de planes de estudio</h3>

        <?php if (!empty($pop_groups)): ?>
        <div class="pop-groups-list">
            <?php foreach ($pop_groups as $gidx => $group): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="pop_action" value="remove_group">
                <input type="hidden" name="sesskey"    value="<?php echo sesskey(); ?>">
                <input type="hidden" name="group_idx"  value="<?php echo (int)$gidx; ?>">
                <span class="pop-group-tag">
                    <?php echo s($group['name']); ?>
                    <span style="opacity:0.65;font-weight:400;font-size:10px">
                        (<?php echo implode(' + ', array_map(fn($p) => s($available_plans[$p] ?? "Plan $p"), $group['planids'])); ?>)
                    </span>
                    <button type="submit" title="Eliminar">&#10005;</button>
                </span>
            </form>
            <?php endforeach; ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="pop_action" value="clear_groups">
                <input type="hidden" name="sesskey"    value="<?php echo sesskey(); ?>">
                <button type="submit" class="pop-group-btn pop-group-btn-sm pop-group-btn-outline">
                    Limpiar todos
                </button>
            </form>
        </div>
        <?php endif; ?>

        <div class="pop-new-group-form">
            <form method="post">
                <input type="hidden" name="pop_action" value="add_group">
                <input type="hidden" name="sesskey"    value="<?php echo sesskey(); ?>">
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
                <button type="submit" class="pop-group-btn">Crear grupo</button>
            </form>
        </div>
    </div>

    <!-- Career sections ─────────────────────────────────────────────── -->
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
            $hasClasses  = !empty($shiftData['classes']);
            $hasStudents = $shiftData['student_count'] > 0;
            $isActive    = $hasStudents || $hasClasses;
        ?>
            <div class="pop-house-card <?php echo $isActive ? 'pop-active' : ''; ?>">

                <div class="pop-house-header">
                    <div class="pop-house-icon"><?php
                        echo str_replace('width="40" height="40"',
                            'width="36" height="36"', pop_house_svg()); ?></div>
                    <div class="pop-house-meta">
                        <div class="pop-house-shift"><?php echo s($shiftName); ?></div>
                        <div class="pop-house-count">
                            <?php echo $shiftData['student_count']; ?>
                            <small>estudiantes en clases activas</small>
                        </div>
                    </div>
                </div>

                <?php if ($hasClasses): ?>
                <div class="pop-classes-inner">
                    <?php foreach ($shiftData['classes'] as $cls):
                        $schedHtml = pop_format_schedule($schedules_by_class[(int)$cls->id] ?? []);
                        $cname     = trim((string)($cls->coursefullname ?: $cls->classname));
                    ?>
                    <div class="pop-class-chip">
                        <div class="pop-class-chip-name" title="<?php echo s($cname); ?>"><?php echo s($cname); ?></div>
                        <div class="pop-class-chip-teacher"><?php echo s(trim($cls->teachername)); ?></div>
                        <div class="pop-class-chip-row">
                            <div class="pop-class-chip-sched" title="<?php echo $schedHtml; ?>">
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

    <!-- TRONCO COMÚN ────────────────────────────────────────────────── -->
    <?php if (!empty($tc_classes)):
        $tc_by_course = [];
        foreach ($tc_classes as $cls) {
            $cname = trim((string)($cls->coursefullname ?: $cls->classname));
            $tc_by_course[$cname][] = $cls;
        }
    ?>
    <div class="pop-tc-section">
        <h2 class="pop-tc-title">Tronco Común</h2>
        <div class="pop-tc-grid">
        <?php foreach ($tc_by_course as $courseName => $groups): ?>
            <div class="pop-tc-house-card">
                <div class="pop-tc-house-header">
                    <svg viewBox="0 0 64 64" width="28" height="28" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <polygon points="32,6 60,30 56,30 56,58 36,58 36,40 28,40 28,58 8,58 8,30 4,30" stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.12"/>
                        <polygon points="32,6 60,30 4,30" stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.25"/>
                    </svg>
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
                                <span style="background:#dbeafe;color:#1e40af;border-radius:3px;padding:1px 5px;font-size:9px;font-weight:700;margin-right:3px;"><?php echo s($shift); ?></span>
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

<!-- ── Student list modal ──────────────────────────────────────────── -->
<div id="popStudentModal" class="pop-modal-overlay" onclick="if(event.target===this)popCloseModal()">
    <div class="pop-modal">
        <div class="pop-modal-header">
            <h2 id="popModalTitle">Estudiantes activos</h2>
            <div class="pop-modal-header-actions">
                <button class="pop-group-btn pop-group-btn-sm" onclick="popExportExcel()" style="background:#1e7e34">
                    &#8595; Exportar Excel
                </button>
                <button class="pop-modal-close" onclick="popCloseModal()">&#10005;</button>
            </div>
        </div>
        <div class="pop-modal-toolbar">
            <input type="text" class="pop-modal-search" id="popStudentSearch"
                placeholder="Buscar por nombre, cédula o correo..."
                oninput="popFilterTable()">
            <span id="popStudentCount" style="font-size:12px;color:#64748b;white-space:nowrap"></span>
        </div>
        <div class="pop-modal-body">
            <table class="pop-student-table" id="popStudentTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cédula / ID</th>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Carrera / Jornada</th>
                    </tr>
                </thead>
                <tbody id="popStudentTbody"></tbody>
            </table>
        </div>
        <div class="pop-modal-footer">
            <span>* Solo estudiantes con estado activo en su plan de estudio</span>
            <span id="popModalVisibleCount"></span>
        </div>
    </div>
</div>

<script>
(function() {
    // Student data from PHP
    var popStudents = <?php echo json_encode(array_values($student_list), JSON_UNESCAPED_UNICODE); ?>;

    function renderTable(students) {
        var tbody = document.getElementById('popStudentTbody');
        var html  = '';
        students.forEach(function(s, i) {
            html += '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td class="td-idn">' + esc(s.idnumber) + '</td>' +
                '<td><strong>' + esc(s.name) + '</strong></td>' +
                '<td>' + esc(s.email) + '</td>' +
                '<td class="td-careers">' + s.careers.map(esc).join('<br>') + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
        document.getElementById('popModalVisibleCount').textContent =
            'Mostrando ' + students.length + ' de ' + popStudents.length;
    }

    function esc(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    var popFiltered = popStudents.slice();

    window.popOpenModal = function() {
        popFiltered = popStudents.slice();
        renderTable(popFiltered);
        document.getElementById('popStudentSearch').value = '';
        document.getElementById('popStudentCount').textContent =
            popStudents.length + ' estudiantes';
        document.getElementById('popStudentModal').classList.add('pop-modal-open');
        document.getElementById('popStudentSearch').focus();
    };

    window.popCloseModal = function() {
        document.getElementById('popStudentModal').classList.remove('pop-modal-open');
    };

    window.popFilterTable = function() {
        var q = document.getElementById('popStudentSearch').value.toLowerCase().trim();
        popFiltered = q
            ? popStudents.filter(function(s) {
                return s.name.toLowerCase().includes(q) ||
                       s.idnumber.toLowerCase().includes(q) ||
                       s.email.toLowerCase().includes(q) ||
                       s.careers.join(' ').toLowerCase().includes(q);
              })
            : popStudents.slice();
        renderTable(popFiltered);
        document.getElementById('popStudentCount').textContent =
            popStudents.length + ' estudiantes';
    };

    window.popExportExcel = function() {
        var rows = [['#','Cédula/ID','Nombre','Correo','Carrera / Jornada']];
        popFiltered.forEach(function(s, i) {
            rows.push([i + 1, s.idnumber, s.name, s.email, s.careers.join(' | ')]);
        });
        var csv = rows.map(function(r) {
            return r.map(function(cell) {
                var c = String(cell).replace(/"/g,'""');
                return '"' + c + '"';
            }).join(',');
        }).join('\r\n');

        // BOM for Excel UTF-8 compatibility
        var bom  = '\uFEFF';
        var blob = new Blob([bom + csv], {type: 'text/csv;charset=utf-8;'});
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = 'estudiantes_activos.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') popCloseModal();
    });
})();
</script>

<?php echo $OUTPUT->footer(); ?>
