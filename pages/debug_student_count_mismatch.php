<?php
/**
 * Debug page for student count mismatches between board cards and enrolled list.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_student_count_mismatch.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Student Count Mismatch');
$PAGE->set_heading('Debug Student Count Mismatch');

$periodid = optional_param('periodid', 0, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$subject = optional_param('subject', 'MATEMATICA II', PARAM_TEXT);
$shift = optional_param('shift', 'Diurna', PARAM_TEXT);
$maxrows = optional_param('maxrows', 1000, PARAM_INT);
if ($maxrows < 50) {
    $maxrows = 50;
}
if ($maxrows > 5000) {
    $maxrows = 5000;
}

/**
 * Normalize tokens for accent/case resilient matching.
 *
 * @param string $value
 * @return string
 */
function gmk_dbg_stc_norm(string $value): string {
    $value = trim(core_text::strtolower($value));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && is_string($ascii)) {
        $value = $ascii;
    }
    $value = preg_replace('/\s+/', ' ', $value);
    if ($value === null) {
        return '';
    }
    return trim($value);
}

/**
 * Return unique positive int ids.
 *
 * @param array $ids
 * @return array
 */
function gmk_dbg_stc_unique_ids(array $ids): array {
    $out = [];
    foreach ($ids as $raw) {
        $id = (int)$raw;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

/**
 * Fetch class user ids from source table with classid.
 *
 * @param string $table
 * @param int $classid
 * @param int $excludeuserid
 * @return array
 */
function gmk_dbg_stc_fetch_class_source_ids(string $table, int $classid, int $excludeuserid = 0): array {
    global $DB;

    $sql = "SELECT DISTINCT s.userid
              FROM {{$table}} s
              JOIN {user} u ON u.id = s.userid
             WHERE s.classid = :cid
               AND u.deleted = 0";
    $params = ['cid' => $classid];
    if ($excludeuserid > 0) {
        $sql .= " AND s.userid <> :uid";
        $params['uid'] = $excludeuserid;
    }
    $ids = $DB->get_fieldset_sql($sql, $params);
    return gmk_dbg_stc_unique_ids($ids);
}

/**
 * Fetch group member user ids.
 *
 * @param int $groupid
 * @param int $excludeuserid
 * @return array
 */
function gmk_dbg_stc_fetch_group_ids(int $groupid, int $excludeuserid = 0): array {
    global $DB;

    if ($groupid <= 0) {
        return [];
    }

    $sql = "SELECT DISTINCT gm.userid
              FROM {groups_members} gm
              JOIN {user} u ON u.id = gm.userid
             WHERE gm.groupid = :gid
               AND u.deleted = 0";
    $params = ['gid' => $groupid];
    if ($excludeuserid > 0) {
        $sql .= " AND gm.userid <> :uid";
        $params['uid'] = $excludeuserid;
    }
    $ids = $DB->get_fieldset_sql($sql, $params);
    return gmk_dbg_stc_unique_ids($ids);
}

/**
 * Build a basic shift token from class data.
 *
 * @param stdClass $class
 * @return string
 */
function gmk_dbg_stc_class_shift_token($class): string {
    $shift = gmk_dbg_stc_norm((string)($class->shift ?? ''));
    if ($shift !== '') {
        if (in_array($shift, ['d', 'diurna', 'dia', 'day'], true)) {
            return 'diurna';
        }
        if (in_array($shift, ['n', 'nocturna', 'noche', 'night'], true)) {
            return 'nocturna';
        }
        if (in_array($shift, ['s', 'sabado', 'sabatina', 'weekend'], true)) {
            return 'sabado';
        }
        return $shift;
    }

    $name = gmk_dbg_stc_norm((string)($class->name ?? ''));
    if ($name === '') {
        return '';
    }
    if (strpos($name, '(d)') !== false || strpos($name, 'diurna') !== false) {
        return 'diurna';
    }
    if (strpos($name, '(n)') !== false || strpos($name, 'nocturna') !== false) {
        return 'nocturna';
    }
    if (strpos($name, '(s)') !== false || strpos($name, 'sabado') !== false) {
        return 'sabado';
    }
    return $shift;
}

echo $OUTPUT->header();
?>
<style>
table.gmkdbg {
    border-collapse: collapse;
    width: 100%;
    font-size: 12px;
}
table.gmkdbg th, table.gmkdbg td {
    border: 1px solid #d6dbe1;
    padding: 6px 8px;
    vertical-align: top;
}
table.gmkdbg th {
    background: #f3f6f9;
    text-align: left;
}
.gmk-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 12px;
}
.gmk-muted {
    color: #667085;
}
.gmk-ok {
    color: #137333;
    font-weight: 600;
}
.gmk-warn {
    color: #b26a00;
    font-weight: 600;
}
.gmk-err {
    color: #b00020;
    font-weight: 600;
}
.gmk-chip {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    background: #eef3f8;
    color: #344054;
    margin-right: 4px;
}
.gmk-chip.miss { background: #ffe9ec; color: #8a1c2d; }
.gmk-chip.wait { background: #e8f7ff; color: #0c4a6e; }
.gmk-chip.ok { background: #e8f5e9; color: #14532d; }
</style>

<h2>Debug Student Count Mismatch</h2>
<p class="gmk-muted">
Compara los conteos de ficha vs lista de inscritos para una clase y muestra exactamente
que estudiantes explican la diferencia.
</p>

<form method="get" class="gmk-row">
    <label>periodid <input type="number" name="periodid" value="<?php echo (int)$periodid; ?>"></label>
    <label>classid <input type="number" name="classid" value="<?php echo (int)$classid; ?>"></label>
    <label>subject <input type="text" name="subject" value="<?php echo s($subject); ?>" style="min-width:240px;"></label>
    <label>shift <input type="text" name="shift" value="<?php echo s($shift); ?>" style="min-width:120px;"></label>
    <label>maxrows <input type="number" name="maxrows" value="<?php echo (int)$maxrows; ?>"></label>
    <button type="submit" class="btn btn-primary">Diagnose</button>
</form>

<?php
global $DB;

$where = [];
$params = [];
if ($periodid > 0) {
    $where[] = 'c.periodid = :periodid';
    $params['periodid'] = $periodid;
}
if ($classid > 0) {
    $where[] = 'c.id = :classid';
    $params['classid'] = $classid;
}
$wsql = '';
if (!empty($where)) {
    $wsql = 'WHERE ' . implode(' AND ', $where);
}

$classsql = "SELECT c.id, c.name, c.periodid, c.shift, c.approved, c.closed, c.groupid,
                    c.corecourseid, c.courseid, c.learningplanid, c.instructorid,
                    co.fullname AS corefullname, co.shortname AS coreshortname
               FROM {gmk_class} c
          LEFT JOIN {course} co ON co.id = c.corecourseid
               {$wsql}
           ORDER BY c.id DESC";

$allclasses = $DB->get_records_sql($classsql, $params, 0, $maxrows);
$subjecttoken = gmk_dbg_stc_norm($subject);
$shifttoken = gmk_dbg_stc_norm($shift);

$matches = [];
foreach ($allclasses as $c) {
    if ($classid > 0) {
        $matches[] = $c;
        continue;
    }
    $hay = gmk_dbg_stc_norm((string)$c->name . ' ' . (string)($c->corefullname ?? '') . ' ' . (string)($c->coreshortname ?? ''));
    $classtoken = gmk_dbg_stc_class_shift_token($c);
    $subjectok = ($subjecttoken === '') || (strpos($hay, $subjecttoken) !== false);
    $shiftok = ($shifttoken === '') || (strpos($classtoken, $shifttoken) !== false);
    if ($subjectok && $shiftok) {
        $matches[] = $c;
    }
}

echo '<p><b>Classes scanned:</b> ' . count($allclasses) . ' | <b>Matches:</b> ' . count($matches) . '</p>';

if (!empty($matches)) {
    echo '<h3>Matching classes</h3>';
    echo '<table class="gmkdbg"><thead><tr>';
    echo '<th>ID</th><th>Name</th><th>Shift</th><th>Period</th><th>Approved/Closed</th><th>Group</th><th>Action</th>';
    echo '</tr></thead><tbody>';
    foreach ($matches as $m) {
        $u = new moodle_url('/local/grupomakro_core/pages/debug_student_count_mismatch.php', [
            'periodid' => $periodid,
            'classid' => (int)$m->id,
            'subject' => $subject,
            'shift' => $shift,
            'maxrows' => $maxrows
        ]);
        echo '<tr>';
        echo '<td>' . (int)$m->id . '</td>';
        echo '<td>' . s((string)$m->name) . '</td>';
        echo '<td>' . s((string)($m->shift ?? '')) . '</td>';
        echo '<td>' . (int)$m->periodid . '</td>';
        echo '<td>' . (int)$m->approved . '/' . (int)$m->closed . '</td>';
        echo '<td>' . (int)($m->groupid ?? 0) . '</td>';
        echo '<td><a class="btn btn-secondary btn-sm" href="' . $u->out(false) . '">Use</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

$selected = null;
if ($classid > 0) {
    foreach ($matches as $m) {
        if ((int)$m->id === (int)$classid) {
            $selected = $m;
            break;
        }
    }
    if ($selected === null && !empty($allclasses[$classid])) {
        $selected = $allclasses[$classid];
    }
} else if (!empty($matches)) {
    $selected = reset($matches);
}

if (!$selected) {
    echo '<p class="gmk-muted">No class selected. Choose one from the list above.</p>';
    echo $OUTPUT->footer();
    exit;
}

$selectedid = (int)$selected->id;
$instructorid = (int)($selected->instructorid ?? 0);
$groupid = (int)($selected->groupid ?? 0);
$approved = (int)($selected->approved ?? 0);

$groupids = gmk_dbg_stc_fetch_group_ids($groupid, $instructorid);
$progreids = gmk_dbg_stc_fetch_class_source_ids('gmk_course_progre', $selectedid, $instructorid);
$preregids = gmk_dbg_stc_fetch_class_source_ids('gmk_class_pre_registration', $selectedid, $instructorid);
$queueids = gmk_dbg_stc_fetch_class_source_ids('gmk_class_queue', $selectedid, $instructorid);

// Counter similar to planning board card (scheduler get_generated_schedules):
// union(group + progre + prereg + queue) excluding instructor and deleted users.
$boardids = gmk_dbg_stc_unique_ids(array_merge($groupids, $progreids, $preregids, $queueids));

// Enrolled list used by userslist.js modal (only enroledStudents):
// - if group exists => group members
// - if no group and approved => course_progre by classid
// - otherwise empty
$enrolledids = [];
if ($groupid > 0) {
    $enrolledids = $groupids;
} else if ($approved > 0) {
    $enrolledids = $progreids;
}
$enrolledset = array_flip($enrolledids);

// Planned students dialog source (local_grupomakro_get_planned_students).
$planneddialogids = gmk_dbg_stc_unique_ids(array_merge($preregids, $queueids, $progreids));
$plannedset = array_flip($planneddialogids);

$allids = gmk_dbg_stc_unique_ids(array_merge($boardids, $enrolledids, $preregids, $queueids, $progreids));
$users = [];
if (!empty($allids)) {
    $users = $DB->get_records_list('user', 'id', $allids, '', 'id,firstname,lastname,email,idnumber,deleted,suspended');
}

$boardset = array_flip($boardids);
$groupset = array_flip($groupids);
$progreset = array_flip($progreids);
$preregset = array_flip($preregids);
$queueset = array_flip($queueids);

$missingfromenrolled = [];
foreach ($boardids as $uid) {
    if (!isset($enrolledset[$uid])) {
        $missingfromenrolled[] = $uid;
    }
}

echo '<hr>';
echo '<h3>Selected class</h3>';
echo '<p><b>ID:</b> ' . $selectedid . ' | <b>Name:</b> ' . s((string)$selected->name) .
    ' | <b>Shift:</b> ' . s((string)($selected->shift ?? '')) .
    ' | <b>Period:</b> ' . (int)$selected->periodid .
    ' | <b>Approved/Closed:</b> ' . (int)$selected->approved . '/' . (int)$selected->closed .
    ' | <b>Group:</b> ' . $groupid .
    ' | <b>Instructor:</b> ' . $instructorid .
    '</p>';

$plannerCount = count($boardids);
$enrolledCount = count($enrolledids);
$plannedDialogCount = count($planneddialogids);
$missingCount = count($missingfromenrolled);
$statusclass = ($plannerCount === $enrolledCount) ? 'gmk-ok' : 'gmk-warn';

echo '<p>';
echo '<span class="gmk-chip">Card/planner count: ' . $plannerCount . '</span>';
echo '<span class="gmk-chip">Userslist enrolled count: ' . $enrolledCount . '</span>';
echo '<span class="gmk-chip">Planned dialog count: ' . $plannedDialogCount . '</span>';
echo '<span class="gmk-chip ' . ($missingCount > 0 ? 'miss' : 'ok') . '">Difference: ' . $missingCount . '</span>';
echo '</p>';
echo '<p class="' . $statusclass . '">';
if ($missingCount > 0) {
    echo 'Mismatch detected: there are ' . $missingCount . ' students in card/planner count that are not shown in enrolled list.';
} else {
    echo 'No mismatch detected for this class.';
}
echo '</p>';

echo '<h3>Source counts</h3>';
echo '<table class="gmkdbg"><thead><tr><th>Source</th><th>Count</th><th>Logic</th></tr></thead><tbody>';
echo '<tr><td>groups_members</td><td>' . count($groupids) . '</td><td>Actual enrolled group members (without instructor).</td></tr>';
echo '<tr><td>gmk_course_progre</td><td>' . count($progreids) . '</td><td>Progress rows linked to classid.</td></tr>';
echo '<tr><td>gmk_class_pre_registration</td><td>' . count($preregids) . '</td><td>Pre-registered students.</td></tr>';
echo '<tr><td>gmk_class_queue</td><td>' . count($queueids) . '</td><td>Queue students.</td></tr>';
echo '<tr><td>Card/planner (union)</td><td>' . $plannerCount . '</td><td>Unique union of group + progre + prereg + queue.</td></tr>';
echo '<tr><td>Userslist modal</td><td>' . $enrolledCount . '</td><td>Only enroledStudents (group or progre when no group + approved).</td></tr>';
echo '</tbody></table>';

if ($missingCount > 0) {
    echo '<h3>Students counted on card but missing in enrolled list</h3>';
    echo '<table class="gmkdbg"><thead><tr>';
    echo '<th>User</th><th>ID Number</th><th>Email</th><th>In pre_registration</th><th>In queue</th><th>In progre</th><th>In group</th><th>Reason</th>';
    echo '</tr></thead><tbody>';
    foreach ($missingfromenrolled as $uid) {
        $u = $users[$uid] ?? null;
        $fullname = $u ? fullname($u) : ('User ' . $uid);
        $idnumber = $u ? (string)$u->idnumber : '';
        $email = $u ? (string)$u->email : '';

        $inprereg = isset($preregset[$uid]);
        $inqueue = isset($queueset[$uid]);
        $inprogre = isset($progreset[$uid]);
        $ingroup = isset($groupset[$uid]);

        $reasons = [];
        if ($inprereg) {
            $reasons[] = 'pending_prereg';
        }
        if ($inqueue) {
            $reasons[] = 'pending_queue';
        }
        if ($inprogre && !$ingroup && $groupid > 0) {
            $reasons[] = 'progre_without_group';
        }
        if (empty($reasons)) {
            $reasons[] = 'not_in_enrolled_source';
        }

        echo '<tr>';
        echo '<td>' . s($fullname) . ' (uid=' . (int)$uid . ')</td>';
        echo '<td>' . s($idnumber) . '</td>';
        echo '<td>' . s($email) . '</td>';
        echo '<td>' . ($inprereg ? 'YES' : 'NO') . '</td>';
        echo '<td>' . ($inqueue ? 'YES' : 'NO') . '</td>';
        echo '<td>' . ($inprogre ? 'YES' : 'NO') . '</td>';
        echo '<td>' . ($ingroup ? 'YES' : 'NO') . '</td>';
        echo '<td><span class="gmk-chip miss">' . s(implode(', ', $reasons)) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo '<h3>Full union detail</h3>';
echo '<table class="gmkdbg"><thead><tr>';
echo '<th>User</th><th>ID Number</th><th>Sources</th><th>Visible in userslist</th><th>Visible in planned dialog</th>';
echo '</tr></thead><tbody>';
foreach ($boardids as $uid) {
    $u = $users[$uid] ?? null;
    $fullname = $u ? fullname($u) : ('User ' . $uid);
    $idnumber = $u ? (string)$u->idnumber : '';

    $chips = [];
    if (isset($groupset[$uid])) {
        $chips[] = '<span class="gmk-chip ok">group</span>';
    }
    if (isset($progreset[$uid])) {
        $chips[] = '<span class="gmk-chip">progre</span>';
    }
    if (isset($preregset[$uid])) {
        $chips[] = '<span class="gmk-chip wait">pre_registration</span>';
    }
    if (isset($queueset[$uid])) {
        $chips[] = '<span class="gmk-chip wait">queue</span>';
    }
    if (empty($chips)) {
        $chips[] = '<span class="gmk-chip">none</span>';
    }

    $visEnroll = isset($enrolledset[$uid]) ? 'YES' : 'NO';
    $visPlan = isset($plannedset[$uid]) ? 'YES' : 'NO';

    echo '<tr>';
    echo '<td>' . s($fullname) . ' (uid=' . (int)$uid . ')</td>';
    echo '<td>' . s($idnumber) . '</td>';
    echo '<td>' . implode(' ', $chips) . '</td>';
    echo '<td>' . $visEnroll . '</td>';
    echo '<td>' . $visPlan . '</td>';
    echo '</tr>';
}
if (empty($boardids)) {
    echo '<tr><td colspan="5" class="gmk-muted">No students found in any source for this class.</td></tr>';
}
echo '</tbody></table>';

echo $OUTPUT->footer();
