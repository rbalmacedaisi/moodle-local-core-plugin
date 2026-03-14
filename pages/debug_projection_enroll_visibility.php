<?php
// Debug page: diagnose why projected/published classes are not visible for enrollment
// and why pending students do not appear in schedules.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_learning_plan_pensum.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_projection_enroll_visibility.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Projection Enroll Visibility');
$PAGE->set_heading('Debug Projection Enroll Visibility');
$PAGE->set_pagelayout('admin');

$periodid = optional_param('periodid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$studentquery = optional_param('student', '', PARAM_RAW_TRIMMED);
$classnames = optional_param(
    'classnames',
    "2026-II (N) INGLES I (PRESENCIAL) E\n2026-II (N) INFORMATICA APLICADA (PRESENCIAL) E",
    PARAM_RAW_TRIMMED
);

/**
 * Safe HTML escaping.
 * @param mixed $value
 * @return string
 */
function dbg_es($value): string {
    return s((string)$value);
}

/**
 * Normalize text for accent-insensitive comparisons.
 * @param string $text
 * @return string
 */
function dbg_norm(string $text): string {
    $txt = trim($text);
    if ($txt === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($ascii !== false && $ascii !== '') {
        $txt = $ascii;
    }
    $txt = core_text::strtolower($txt);
    $txt = preg_replace('/[^a-z0-9]+/', ' ', $txt);
    $txt = preg_replace('/\s+/', ' ', $txt);
    return trim((string)$txt);
}

/**
 * Contains check using normalized text.
 * @param string $candidate
 * @param string $target
 * @return bool
 */
function dbg_contains_norm(string $candidate, string $target): bool {
    $a = dbg_norm($candidate);
    $b = dbg_norm($target);
    if ($a === '' || $b === '') {
        return false;
    }
    return strpos($a, $b) !== false;
}

/**
 * Parse class names input (one per line).
 * @param string $raw
 * @return array
 */
function dbg_parse_class_queries(string $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $out = [];
    foreach ($lines as $line) {
        $q = trim((string)$line);
        if ($q === '') {
            continue;
        }
        $out[$q] = $q;
    }
    return array_values($out);
}

/**
 * Check truthy values from mixed payload.
 * @param mixed $v
 * @return bool
 */
function dbg_truthy($v): bool {
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) || is_float($v)) {
        return ((int)$v) === 1;
    }
    $n = core_text::strtolower(trim((string)$v));
    return in_array($n, ['1', 'true', 'yes', 'y', 'si', 'on'], true);
}

/**
 * Frontend rule from grademodal.js: allowed status labels for enroll.
 * @param string $statuslabel
 * @return bool
 */
function dbg_allowed_status_for_enroll(string $statuslabel): bool {
    $x = dbg_norm($statuslabel);
    return $x === 'disponible' || $x === 'no disponible' || $x === 'reprobada';
}

/**
 * Build active classes list using the same rules as ajax.php local_grupomakro_get_active_classes_for_course.
 * @param int $userid
 * @param int $corecourseid
 * @param int $learningcourseid
 * @param int $learningplanid
 * @return array
 */
function dbg_get_active_classes_like_api(int $userid, int $corecourseid, int $learningcourseid, int $learningplanid): array {
    global $DB;

    $now = time();
    $baseWhere = "c.approved = 1 AND c.closed = 0 AND c.enddate >= :now";
    $baseParams = ['now' => $now];

    $buildClasses = function(string $whereSql, array $params) use ($DB, $userid): array {
        $sql = "SELECT
                    c.id,
                    c.name,
                    c.classroomcapacity,
                    c.groupid,
                    c.instructorid,
                    c.initdate,
                    c.enddate,
                    c.corecourseid,
                    c.learningplanid,
                    c.courseid
                FROM {gmk_class} c
                WHERE {$whereSql}
                ORDER BY c.initdate ASC, c.inittimets ASC, c.id ASC";
        $rows = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($rows as $row) {
            $enrolled = 0;
            $alreadyenrolled = false;
            if (!empty($row->groupid)) {
                $enrolled = (int)$DB->count_records_select(
                    'groups_members',
                    'groupid = :gid AND userid <> :instructorid',
                    ['gid' => (int)$row->groupid, 'instructorid' => (int)$row->instructorid]
                );
                $alreadyenrolled = groups_is_member((int)$row->groupid, $userid);
            } else {
                $enrolled = (int)$DB->count_records_select(
                    'gmk_course_progre',
                    'classid = :classid AND userid <> :instructorid',
                    ['classid' => (int)$row->id, 'instructorid' => (int)$row->instructorid]
                );
                $alreadyenrolled = $DB->record_exists('gmk_course_progre', ['classid' => (int)$row->id, 'userid' => $userid]);
            }

            $result[] = [
                'id' => (int)$row->id,
                'name' => (string)$row->name,
                'learningplanid' => (int)$row->learningplanid,
                'courseid' => (int)$row->courseid,
                'corecourseid' => (int)$row->corecourseid,
                'groupid' => (int)$row->groupid,
                'classroomcapacity' => (int)$row->classroomcapacity,
                'enrolled' => $enrolled,
                'alreadyenrolled' => $alreadyenrolled,
            ];
        }
        return $result;
    };

    $activeclasses = [];
    if ($learningcourseid > 0) {
        $where = $baseWhere . " AND c.courseid = :learningcourseid";
        $params = $baseParams + ['learningcourseid' => $learningcourseid];
        if ($learningplanid > 0) {
            $where .= " AND (c.learningplanid = :learningplanid OR EXISTS (
                          SELECT 1
                            FROM {local_learning_courses} lpcmap
                           WHERE lpcmap.id = c.courseid
                             AND lpcmap.learningplanid = :learningplanidmap
                        ))";
            $params['learningplanid'] = $learningplanid;
            $params['learningplanidmap'] = $learningplanid;
        }
        $activeclasses = $buildClasses($where, $params);
    }

    if (empty($activeclasses)) {
        $where = $baseWhere . " AND c.corecourseid = :corecourseid";
        $params = $baseParams + ['corecourseid' => $corecourseid];
        if ($learningplanid > 0) {
            $where .= " AND (c.learningplanid = :learningplanid OR EXISTS (
                          SELECT 1
                            FROM {local_learning_courses} lpcmap
                           WHERE lpcmap.id = c.courseid
                             AND lpcmap.learningplanid = :learningplanidmap
                        ))";
            $params['learningplanid'] = $learningplanid;
            $params['learningplanidmap'] = $learningplanid;
        }
        $activeclasses = $buildClasses($where, $params);
    }

    if (empty($activeclasses) && $learningplanid > 0) {
        $currentperiodid = (int)$DB->get_field_sql(
            "SELECT MAX(lu.currentperiodid)
               FROM {local_learning_users} lu
              WHERE lu.userid = :userid
                AND lu.learningplanid = :learningplanid
                AND (lu.userroleid = :studentrole OR lu.userrolename = :studentrolename)",
            [
                'userid' => $userid,
                'learningplanid' => $learningplanid,
                'studentrole' => 5,
                'studentrolename' => 'student',
            ]
        );

        if ($currentperiodid > 0) {
            $where = $baseWhere . " AND c.corecourseid = :corecourseid AND c.periodid = :periodid";
            $params = $baseParams + [
                'corecourseid' => $corecourseid,
                'periodid' => $currentperiodid,
            ];
            $activeclasses = $buildClasses($where, $params);
        }
    }

    if (empty($activeclasses)) {
        $where = $baseWhere . " AND c.corecourseid = :corecourseid";
        $params = $baseParams + ['corecourseid' => $corecourseid];
        $activeclasses = $buildClasses($where, $params);
    }

    return $activeclasses;
}

echo $OUTPUT->header();
?>
<style>
.dbg-wrap { max-width: 1800px; margin: 12px auto; }
.dbg-card { background: #fff; border: 1px solid #d7dce1; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.dbg-sub { margin: 0 0 10px 0; }
.dbg-form { display: grid; grid-template-columns: 220px 1fr 1fr auto; gap: 8px; align-items: start; }
.dbg-form label { font-size: 12px; color: #444; margin-bottom: 4px; display: block; }
.dbg-form input, .dbg-form select, .dbg-form textarea { width: 100%; padding: 6px 8px; border: 1px solid #c7ced6; border-radius: 6px; font-size: 13px; }
.dbg-form textarea { min-height: 70px; resize: vertical; }
.dbg-btn { background: #1158d3; color: #fff; border: 0; padding: 8px 12px; border-radius: 6px; cursor: pointer; }
.dbg-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.dbg-table th, .dbg-table td { border: 1px solid #dde3ea; padding: 6px 8px; font-size: 12px; text-align: left; vertical-align: top; }
.dbg-table th { background: #f6f8fb; }
.dbg-ok { color: #0a8a39; font-weight: 700; }
.dbg-bad { color: #b31919; font-weight: 700; }
.dbg-warn { color: #a15d00; font-weight: 700; }
</style>
<div class="dbg-wrap">
<?php
$periods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id,name,startdate,enddate');
if ($periodid <= 0 && !empty($periods)) {
    $first = reset($periods);
    $periodid = (int)$first->id;
}

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Debug Projection Enroll Visibility</h2>';
echo '<form method="get" action="">';
echo '<input type="hidden" name="userid" value="' . (int)$userid . '">';
echo '<div class="dbg-form">';
echo '<div><label>Period</label><select name="periodid">';
foreach ($periods as $p) {
    $sel = ((int)$p->id === (int)$periodid) ? ' selected' : '';
    $label = (string)$p->name . ' (id=' . (int)$p->id . ')';
    echo '<option value="' . (int)$p->id . '"' . $sel . '>' . dbg_es($label) . '</option>';
}
echo '</select></div>';
echo '<div><label>Student (name/idnumber/email/username)</label><input type="text" name="student" value="' . dbg_es($studentquery) . '"></div>';
echo '<div><label>Class names (one per line)</label><textarea name="classnames">' . dbg_es($classnames) . '</textarea></div>';
echo '<div><label>&nbsp;</label><button class="dbg-btn" type="submit">Diagnose</button></div>';
echo '</div>';
echo '</form>';
echo '</div>';

$selecteduser = null;
$usermatches = [];

if ($userid > 0) {
    $selecteduser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,username,email,idnumber,suspended');
} else if ($studentquery !== '') {
    $like = '%' . $DB->sql_like_escape($studentquery) . '%';
    $sql = "SELECT id, firstname, lastname, username, email, idnumber, suspended
              FROM {user}
             WHERE deleted = 0
               AND (
                    " . $DB->sql_like('firstname', ':q1', false) . "
                 OR " . $DB->sql_like('lastname', ':q2', false) . "
                 OR " . $DB->sql_like('username', ':q3', false) . "
                 OR " . $DB->sql_like('email', ':q4', false) . "
                 OR " . $DB->sql_like('idnumber', ':q5', false) . "
               )
          ORDER BY lastname ASC, firstname ASC, id ASC";
    $params = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
    $usermatches = $DB->get_records_sql($sql, $params, 0, 100);
}

if (!$selecteduser && !empty($usermatches)) {
    if (count($usermatches) === 1) {
        $selecteduser = reset($usermatches);
    } else {
        echo '<div class="dbg-card">';
        echo '<h3 class="dbg-sub">Student resolution</h3>';
        echo '<table class="dbg-table"><thead><tr><th>User ID</th><th>Name</th><th>Username</th><th>Email</th><th>ID Number</th><th>Suspended</th><th>Action</th></tr></thead><tbody>';
        foreach ($usermatches as $um) {
            $pickurl = new moodle_url('/local/grupomakro_core/pages/debug_projection_enroll_visibility.php', [
                'periodid' => $periodid,
                'student' => $studentquery,
                'classnames' => $classnames,
                'userid' => (int)$um->id,
            ]);
            echo '<tr>';
            echo '<td>' . (int)$um->id . '</td>';
            echo '<td>' . dbg_es(trim($um->firstname . ' ' . $um->lastname)) . '</td>';
            echo '<td>' . dbg_es($um->username) . '</td>';
            echo '<td>' . dbg_es($um->email) . '</td>';
            echo '<td>' . dbg_es($um->idnumber ?: '-') . '</td>';
            echo '<td>' . (!empty($um->suspended) ? '<span class="dbg-warn">YES</span>' : '<span class="dbg-ok">NO</span>') . '</td>';
            echo '<td><a href="' . dbg_es($pickurl->out(false)) . '">Use</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }
}

if (!$selecteduser) {
    echo '<div class="dbg-card"><span class="dbg-warn">Select a student to run diagnostics.</span></div>';
    echo $OUTPUT->footer();
    exit;
}

$userid = (int)$selecteduser->id;
$idnumber = trim((string)$selecteduser->idnumber);
$fullname = trim($selecteduser->firstname . ' ' . $selecteduser->lastname);

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Selected student</h3>';
echo '<p><strong>User ID:</strong> ' . $userid
    . ' | <strong>Name:</strong> ' . dbg_es($fullname)
    . ' | <strong>ID Number:</strong> ' . dbg_es($idnumber === '' ? '-' : $idnumber)
    . ' | <strong>Email:</strong> ' . dbg_es($selecteduser->email ?: '-')
    . '</p>';
echo '</div>';

$studentplansrows = $DB->get_records('local_learning_users', ['userid' => $userid], 'id ASC', 'id,learningplanid,currentperiodid,currentsubperiodid,status,userroleid,userrolename');
$studentplanids = [];
$lluByPlan = [];
foreach ($studentplansrows as $row) {
    $isstudent = ((int)$row->userroleid === 5) || (core_text::strtolower((string)$row->userrolename) === 'student');
    if (!$isstudent) {
        continue;
    }
    $pid = (int)$row->learningplanid;
    if ($pid <= 0) {
        continue;
    }
    $studentplanids[$pid] = $pid;
    $lluByPlan[$pid] = $row;
}
$studentplanids = array_values($studentplanids);
sort($studentplanids);

$planNames = [];
if (!empty($studentplanids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($studentplanids, SQL_PARAMS_NAMED, 'pl');
    $planNames = $DB->get_records_select_menu('local_learning_plans', "id {$insql}", $inparams, '', 'id,name');
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Student plans</h3>';
echo '<table class="dbg-table"><thead><tr><th>Plan ID</th><th>Plan Name</th><th>Status</th><th>Current Period</th><th>Current Subperiod</th></tr></thead><tbody>';
if (empty($studentplanids)) {
    echo '<tr><td colspan="5" class="dbg-bad">No student rows in local_learning_users.</td></tr>';
} else {
    foreach ($studentplanids as $pid) {
        $row = $lluByPlan[$pid];
        echo '<tr>';
        echo '<td>' . (int)$pid . '</td>';
        echo '<td>' . dbg_es($planNames[$pid] ?? ('Plan ' . $pid)) . '</td>';
        echo '<td>' . dbg_es($row->status) . '</td>';
        echo '<td>' . (int)$row->currentperiodid . '</td>';
        echo '<td>' . (int)$row->currentsubperiodid . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';
echo '</div>';

$classqueries = dbg_parse_class_queries($classnames);
$periodclasses = $DB->get_records('gmk_class', ['periodid' => $periodid], 'name ASC');
$matchedByQuery = [];
$matchedClasses = [];

foreach ($classqueries as $q) {
    $matchedByQuery[$q] = [];
    foreach ($periodclasses as $c) {
        $name = (string)($c->name ?? '');
        if (dbg_contains_norm($name, $q) || dbg_contains_norm($q, $name)) {
            $matchedByQuery[$q][] = (int)$c->id;
            $matchedClasses[(int)$c->id] = $c;
        }
    }
}

$targetClassPlanIds = [];
foreach ($matchedClasses as $mc) {
    $pid = (int)($mc->learningplanid ?? 0);
    if ($pid > 0) {
        $targetClassPlanIds[$pid] = $pid;
    }
}
$targetClassPlanNames = [];
if (!empty($targetClassPlanIds)) {
    list($cpsql, $cpparams) = $DB->get_in_or_equal(array_values($targetClassPlanIds), SQL_PARAMS_NAMED, 'cp');
    $targetClassPlanNames = $DB->get_records_select_menu('local_learning_plans', "id {$cpsql}", $cpparams, '', 'id,name');
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Target class resolution in selected period</h3>';
echo '<table class="dbg-table"><thead><tr><th>Query</th><th>Matches</th><th>Class IDs</th></tr></thead><tbody>';
foreach ($matchedByQuery as $q => $ids) {
    echo '<tr>';
    echo '<td>' . dbg_es($q) . '</td>';
    echo '<td>' . count($ids) . '</td>';
    echo '<td>' . dbg_es(empty($ids) ? '-' : implode(', ', $ids)) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
if (!empty($targetClassPlanIds)) {
    echo '<h4 class="dbg-sub">Target class plan names</h4>';
    echo '<table class="dbg-table"><thead><tr><th>Plan ID</th><th>Plan Name</th><th>Student has this plan?</th></tr></thead><tbody>';
    foreach ($targetClassPlanIds as $pid) {
        $has = in_array((int)$pid, $studentplanids, true);
        echo '<tr>';
        echo '<td>' . (int)$pid . '</td>';
        echo '<td>' . dbg_es($targetClassPlanNames[$pid] ?? '-') . '</td>';
        echo '<td>' . ($has ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

if (empty($matchedClasses)) {
    echo '<div class="dbg-card"><span class="dbg-bad">No classes matched the provided names in this period.</span></div>';
    echo $OUTPUT->footer();
    exit;
}

$boardRaw = [];
$boardByClassId = [];
$boardError = '';
try {
    $boardRaw = \local_grupomakro_core\external\admin\scheduler::get_generated_schedules($periodid, true);
    if (is_string($boardRaw)) {
        $decoded = json_decode($boardRaw, true);
        $boardRaw = is_array($decoded) ? $decoded : [];
    }
    if (is_array($boardRaw)) {
        foreach ($boardRaw as $item) {
            if (is_array($item)) {
                $cid = (int)($item['id'] ?? 0);
                if ($cid > 0) {
                    $boardByClassId[$cid] = $item;
                }
            } else if (is_object($item)) {
                $cid = (int)($item->id ?? 0);
                if ($cid > 0) {
                    $boardByClassId[$cid] = (array)$item;
                }
            }
        }
    }
} catch (Throwable $e) {
    $boardError = $e->getMessage();
}

$draftraw = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
$draftitems = [];
$draftDecodeState = 'EMPTY';
if (!empty($draftraw)) {
    $decoded = json_decode((string)$draftraw, true);
    if (is_array($decoded)) {
        $draftitems = $decoded;
        $draftDecodeState = 'OK';
    } else {
        $draftDecodeState = 'JSON_ERROR: ' . json_last_error_msg();
    }
}

$pensumByPlan = [];
foreach ($studentplanids as $pid) {
    try {
        $res = \local_grupomakro_core\external\student\get_student_learning_plan_pensum::execute($userid, (int)$pid);
        $pensum = [];
        if (!empty($res['pensum'])) {
            $tmp = json_decode((string)$res['pensum'], true);
            if (is_array($tmp)) {
                $pensum = $tmp;
            }
        }
        $pensumByPlan[$pid] = $pensum;
    } catch (Throwable $e) {
        $pensumByPlan[$pid] = ['_error' => $e->getMessage()];
    }
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Draft and board status</h3>';
echo '<p><strong>Draft decode:</strong> ' . dbg_es($draftDecodeState) . ' | <strong>Draft items:</strong> ' . count($draftitems) . ' | <strong>Board rows:</strong> ' . (is_array($boardRaw) ? count($boardRaw) : 0) . '</p>';
if ($boardError !== '') {
    echo '<p class="dbg-bad">get_generated_schedules error: ' . dbg_es($boardError) . '</p>';
}
echo '</div>';

foreach ($matchedClasses as $classid => $class) {
    $classid = (int)$classid;
    $corecourseid = (int)($class->corecourseid ?? 0);
    $groupid = (int)($class->groupid ?? 0);
    $classplanid = (int)($class->learningplanid ?? 0);
    $classplanname = $targetClassPlanNames[$classplanid] ?? '-';

    $session = $DB->get_record('gmk_class_schedules', ['classid' => $classid], 'id,day,start_time,end_time', IGNORE_MULTIPLE);
    $classday = $session ? (string)$session->day : 'N/A';
    $classstart = $session ? (string)$session->start_time : '00:00';
    $classend = $session ? (string)$session->end_time : '00:00';

    $lpcmap = null;
    if (!empty($class->courseid)) {
        $lpcmap = $DB->get_record('local_learning_courses', ['id' => (int)$class->courseid], 'id,learningplanid,periodid,courseid', IGNORE_MISSING);
    }
    if (!$lpcmap && $corecourseid > 0) {
        $find = ['courseid' => $corecourseid];
        if ($classplanid > 0) {
            $find['learningplanid'] = $classplanid;
        }
        $lpcmap = $DB->get_record('local_learning_courses', $find, 'id,learningplanid,periodid,courseid', IGNORE_MULTIPLE);
    }

    $inprereg = $DB->record_exists('gmk_class_pre_registration', ['classid' => $classid, 'userid' => $userid]);
    $inqueue = $DB->record_exists('gmk_class_queue', ['classid' => $classid, 'userid' => $userid]);
    $inprogreclass = $DB->record_exists('gmk_course_progre', ['classid' => $classid, 'userid' => $userid]);
    $ingroup = ($groupid > 0) ? groups_is_member($groupid, $userid) : false;
    $isenrolled = $ingroup || $inprogreclass;
    $ispending = ($inprereg || $inqueue) && !$isenrolled;

    $preregcount = (int)$DB->count_records('gmk_class_pre_registration', ['classid' => $classid]);
    $queuecount = (int)$DB->count_records('gmk_class_queue', ['classid' => $classid]);
    $progrecount = (int)$DB->count_records('gmk_course_progre', ['classid' => $classid]);
    $groupcount = 0;
    if ($groupid > 0) {
        $groupcount = (int)$DB->count_records_select(
            'groups_members',
            'groupid = :gid AND userid <> :instructorid',
            ['gid' => $groupid, 'instructorid' => (int)($class->instructorid ?? 0)]
        );
    }

    $board = $boardByClassId[$classid] ?? null;
    $boardStudentIds = [];
    if (is_array($board) && !empty($board['studentIds']) && is_array($board['studentIds'])) {
        $boardStudentIds = $board['studentIds'];
    }
    $boardHasStudent = false;
    if (!empty($boardStudentIds)) {
        foreach ($boardStudentIds as $sid) {
            $token = trim((string)$sid);
            if ($token === '') {
                continue;
            }
            if ($idnumber !== '' && $token === $idnumber) {
                $boardHasStudent = true;
                break;
            }
            if ($token === (string)$userid) {
                $boardHasStudent = true;
                break;
            }
        }
    }

    $draftMatches = [];
    foreach ($draftitems as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemid = (int)($item['id'] ?? 0);
        $itemcore = (int)($item['corecourseid'] ?? 0);
        $itemshift = dbg_norm((string)($item['shift'] ?? ''));
        $itemday = dbg_norm((string)($item['day'] ?? ''));
        $sameid = ($itemid > 0 && $itemid === $classid);
        $signature = (
            $itemcore === $corecourseid &&
            $itemshift === dbg_norm((string)($class->shift ?? '')) &&
            ($itemday === dbg_norm($classday) || $itemday === 'n a' || dbg_norm($classday) === 'n a')
        );
        if (!$sameid && !$signature) {
            continue;
        }
        $studentids = [];
        if (!empty($item['studentIds']) && is_array($item['studentIds'])) {
            $studentids = $item['studentIds'];
        }
        $drafthasstudent = false;
        foreach ($studentids as $sid) {
            $token = trim((string)$sid);
            if ($token === '') {
                continue;
            }
            if ($idnumber !== '' && $token === $idnumber) {
                $drafthasstudent = true;
                break;
            }
            if ($token === (string)$userid) {
                $drafthasstudent = true;
                break;
            }
        }
        $draftMatches[] = [
            'idx' => (int)$idx,
            'id' => $itemid,
            'subjectName' => (string)($item['subjectName'] ?? ''),
            'day' => (string)($item['day'] ?? ''),
            'start' => (string)($item['start'] ?? ''),
            'end' => (string)($item['end'] ?? ''),
            'shift' => (string)($item['shift'] ?? ''),
            'programmed' => dbg_truthy($item['programmed'] ?? false),
            'external' => dbg_truthy($item['isExternal'] ?? false),
            'studentCount' => is_array($studentids) ? count($studentids) : 0,
            'studentPresent' => $drafthasstudent,
        ];
    }

    echo '<div class="dbg-card">';
    echo '<h3 class="dbg-sub">Class #' . $classid . ' - ' . dbg_es((string)$class->name) . '</h3>';
    echo '<table class="dbg-table"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>';
    echo '<tr><td>corecourseid</td><td>' . $corecourseid . '</td></tr>';
    echo '<tr><td>courseid map (gmk_class.courseid)</td><td>' . (int)($class->courseid ?? 0) . '</td></tr>';
    echo '<tr><td>learningplanid</td><td>' . $classplanid . ' - ' . dbg_es($classplanname) . '</td></tr>';
    echo '<tr><td>shift</td><td>' . dbg_es((string)($class->shift ?? '')) . '</td></tr>';
    echo '<tr><td>day/start/end</td><td>' . dbg_es($classday . ' ' . $classstart . ' - ' . $classend) . '</td></tr>';
    echo '<tr><td>approved/closed</td><td>' . (int)($class->approved ?? 0) . '/' . (int)($class->closed ?? 0) . '</td></tr>';
    echo '<tr><td>groupid</td><td>' . $groupid . '</td></tr>';
    echo '<tr><td>local_learning_courses map</td><td>' . ($lpcmap ? ('id=' . (int)$lpcmap->id . ' plan=' . (int)$lpcmap->learningplanid . ' core=' . (int)$lpcmap->courseid) : '-') . '</td></tr>';
    echo '</tbody></table>';

    echo '<h4 class="dbg-sub">Student enrollment/pending state for this class</h4>';
    echo '<table class="dbg-table"><thead><tr><th>in_prereg</th><th>in_queue</th><th>in_progre(classid)</th><th>in_group</th><th>is_enrolled</th><th>is_pending</th></tr></thead><tbody>';
    echo '<tr>';
    echo '<td>' . ($inprereg ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
    echo '<td>' . ($inqueue ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
    echo '<td>' . ($inprogreclass ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
    echo '<td>' . ($ingroup ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
    echo '<td>' . ($isenrolled ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
    echo '<td>' . ($ispending ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
    echo '</tr>';
    echo '</tbody></table>';

    echo '<table class="dbg-table"><thead><tr><th>pre_registration count</th><th>queue count</th><th>progre count</th><th>group members count</th></tr></thead><tbody>';
    echo '<tr><td>' . $preregcount . '</td><td>' . $queuecount . '</td><td>' . $progrecount . '</td><td>' . $groupcount . '</td></tr>';
    echo '</tbody></table>';

    echo '<h4 class="dbg-sub">Board row (scheduler::get_generated_schedules)</h4>';
    if (!$board) {
        echo '<p class="dbg-bad">Class not found in board API result.</p>';
    } else {
        echo '<table class="dbg-table"><thead><tr><th>pendingEnrollmentCount</th><th>preRegisteredCount</th><th>queuedCount</th><th>enrolledCount</th><th>studentCount</th><th>studentIds has this student</th></tr></thead><tbody>';
        echo '<tr>';
        echo '<td>' . (int)($board['pendingEnrollmentCount'] ?? 0) . '</td>';
        echo '<td>' . (int)($board['preRegisteredCount'] ?? 0) . '</td>';
        echo '<td>' . (int)($board['queuedCount'] ?? 0) . '</td>';
        echo '<td>' . (int)($board['enrolledCount'] ?? 0) . '</td>';
        echo '<td>' . (int)($board['studentCount'] ?? 0) . '</td>';
        echo '<td>' . ($boardHasStudent ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
        echo '</tr></tbody></table>';
    }

    echo '<h4 class="dbg-sub">Draft rows related to this class</h4>';
    echo '<table class="dbg-table"><thead><tr><th>idx</th><th>id</th><th>subjectName</th><th>day</th><th>start</th><th>end</th><th>shift</th><th>programmed</th><th>external</th><th>studentIds count</th><th>student present</th></tr></thead><tbody>';
    if (empty($draftMatches)) {
        echo '<tr><td colspan="11" class="dbg-bad">No matching rows found in draft_schedules for this class signature.</td></tr>';
    } else {
        foreach ($draftMatches as $dm) {
            echo '<tr>';
            echo '<td>' . (int)$dm['idx'] . '</td>';
            echo '<td>' . (int)$dm['id'] . '</td>';
            echo '<td>' . dbg_es($dm['subjectName']) . '</td>';
            echo '<td>' . dbg_es($dm['day']) . '</td>';
            echo '<td>' . dbg_es($dm['start']) . '</td>';
            echo '<td>' . dbg_es($dm['end']) . '</td>';
            echo '<td>' . dbg_es($dm['shift']) . '</td>';
            echo '<td>' . ($dm['programmed'] ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
            echo '<td>' . ($dm['external'] ? '<span class="dbg-warn">YES</span>' : '<span class="dbg-ok">NO</span>') . '</td>';
            echo '<td>' . (int)$dm['studentCount'] . '</td>';
            echo '<td>' . ($dm['studentPresent'] ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    echo '<h4 class="dbg-sub">Notas modal checks (pensum + active classes)</h4>';
    echo '<table class="dbg-table"><thead><tr><th>Plan</th><th>Pensum has subject?</th><th>StatusLabel</th><th>ActiveClassCount (pensum)</th><th>Allowed status?</th><th>API active classes</th><th>API includes this class?</th><th>Reason</th></tr></thead><tbody>';

    if (empty($studentplanids)) {
        echo '<tr><td colspan="8" class="dbg-bad">No student plans.</td></tr>';
    } else {
        foreach ($studentplanids as $pid) {
            $planname = $planNames[$pid] ?? ('Plan ' . $pid);
            $pensum = $pensumByPlan[$pid] ?? [];
            $pensummatch = null;

            $candidateLpc = $DB->get_record(
                'local_learning_courses',
                ['learningplanid' => $pid, 'courseid' => $corecourseid],
                'id,learningplanid,courseid',
                IGNORE_MULTIPLE
            );
            $candidateLpcId = $candidateLpc ? (int)$candidateLpc->id : 0;

            if (is_array($pensum)) {
                foreach ($pensum as $bucket) {
                    if (!is_array($bucket)) {
                        continue;
                    }
                    $courses = (isset($bucket['courses']) && is_array($bucket['courses'])) ? $bucket['courses'] : [];
                    foreach ($courses as $pc) {
                        $pcore = (int)($pc['courseid'] ?? 0);
                        $plc = (int)($pc['learningcourseid'] ?? 0);
                        if ($pcore === $corecourseid || ($candidateLpcId > 0 && $plc === $candidateLpcId)) {
                            $pensummatch = $pc;
                            break 2;
                        }
                    }
                }
            }

            $statusLabel = $pensummatch ? (string)($pensummatch['statusLabel'] ?? '') : '';
            $activeclasscount = $pensummatch ? (int)($pensummatch['activeclasscount'] ?? 0) : 0;
            $allowedstatus = dbg_allowed_status_for_enroll($statusLabel);

            $apiClasses = dbg_get_active_classes_like_api($userid, $corecourseid, $candidateLpcId, $pid);
            $apiHasClass = false;
            foreach ($apiClasses as $ac) {
                if ((int)$ac['id'] === $classid) {
                    $apiHasClass = true;
                    break;
                }
            }

            $reason = 'eligible';
            if (!$pensummatch) {
                $reason = 'not_in_pensum';
            } else if (!$allowedstatus) {
                $reason = 'status_not_allowed';
            } else if ($activeclasscount <= 0) {
                $reason = 'no_active_classes_in_pensum';
            } else if (!$apiHasClass) {
                $reason = 'api_active_classes_missing_target';
            }

            echo '<tr>';
            echo '<td>' . (int)$pid . ' - ' . dbg_es($planname) . '</td>';
            echo '<td>' . ($pensummatch ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
            echo '<td>' . dbg_es($statusLabel === '' ? '-' : $statusLabel) . '</td>';
            echo '<td>' . $activeclasscount . '</td>';
            echo '<td>' . ($allowedstatus ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
            echo '<td>' . count($apiClasses) . '</td>';
            echo '<td>' . ($apiHasClass ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
            echo '<td>' . dbg_es($reason) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';

    $gcpRows = $DB->get_records_sql(
        "SELECT id, learningplanid, status, classid, groupid, timemodified
           FROM {gmk_course_progre}
          WHERE userid = :userid
            AND courseid = :courseid
       ORDER BY learningplanid ASC, id ASC",
        ['userid' => $userid, 'courseid' => $corecourseid]
    );
    echo '<h4 class="dbg-sub">gmk_course_progre rows for this core course</h4>';
    echo '<table class="dbg-table"><thead><tr><th>id</th><th>learningplanid</th><th>status</th><th>classid</th><th>groupid</th><th>modified</th></tr></thead><tbody>';
    if (empty($gcpRows)) {
        echo '<tr><td colspan="6" class="dbg-warn">No progress rows for this student/core course.</td></tr>';
    } else {
        foreach ($gcpRows as $r) {
            echo '<tr>';
            echo '<td>' . (int)$r->id . '</td>';
            echo '<td>' . (int)$r->learningplanid . '</td>';
            echo '<td>' . (int)$r->status . '</td>';
            echo '<td>' . (int)$r->classid . '</td>';
            echo '<td>' . (int)$r->groupid . '</td>';
            echo '<td>' . userdate((int)$r->timemodified) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    echo '</div>';
}
?>
</div>
<?php
echo $OUTPUT->footer();
