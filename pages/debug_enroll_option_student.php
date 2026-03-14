<?php
// Debug page: why INSCRIBIR button is disabled for a student/subject in Academic Director modal.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_learning_plan_pensum.php');

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_enroll_option_student.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Enroll Option by Student');
$PAGE->set_heading('Debug Enroll Option by Student');
$PAGE->set_pagelayout('admin');

$userid = optional_param('userid', 0, PARAM_INT);
$studentquery = optional_param('student', 'Johanna Enith Gil Sanchez', PARAM_RAW_TRIMMED);
$subjectquery = optional_param('subject', 'GEOGRAFIA DE PANAMA', PARAM_RAW_TRIMMED);

/**
 * Escape html safely.
 * @param mixed $v
 * @return string
 */
function dbg_es($v): string {
    return s((string)$v);
}

/**
 * Normalize text for accent-insensitive matching.
 * @param string $text
 * @return string
 */
function dbg_norm(string $text): string {
    $t = trim($text);
    if ($t === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    if ($ascii !== false && $ascii !== '') {
        $t = $ascii;
    }
    $t = core_text::strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t);
    return trim((string)$t);
}

/**
 * Check if candidate contains target after normalization.
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
 * Map status code to label (matching pensum endpoint).
 * @param int $status
 * @return string
 */
function dbg_status_label(int $status): string {
    $map = [
        0 => 'No disponible',
        1 => 'Disponible',
        2 => 'Cursando',
        3 => 'Aprobada',
        4 => 'Aprobada',
        5 => 'Reprobada',
        6 => 'Revalida',
        7 => 'Reprobado',
        99 => 'Migracion pendiente',
    ];
    return $map[$status] ?? ('Status ' . $status);
}

/**
 * Returns true when frontend allows enroll for this status label.
 * Equivalent to grademodal.js hasAllowedStatusForEnroll().
 * @param string $statuslabel
 * @return bool
 */
function dbg_allowed_status_for_enroll(string $statuslabel): bool {
    $x = core_text::strtolower(trim($statuslabel));
    return $x === 'disponible' || $x === 'no disponible' || $x === 'reprobada';
}

/**
 * Build active classes list using same logic as ajax.php local_grupomakro_get_active_classes_for_course.
 * @param stdClass $classrow one local_learning_courses row with learningcourseid/corecourseid/learningplanid
 * @param int $userid
 * @return array
 */
function dbg_get_active_classes_like_api(stdClass $classrow, int $userid): array {
    global $DB;

    $now = time();
    $classes = [];

    $base = "c.approved = 1 AND c.closed = 0 AND c.enddate >= :now";
    $paramsbase = ['now' => $now];

    $fetch = function(string $where, array $params) use ($DB, $userid): array {
        $sql = "SELECT c.id, c.name, c.classroomcapacity, c.groupid, c.instructorid,
                       c.initdate, c.enddate, c.learningplanid, c.corecourseid, c.courseid
                  FROM {gmk_class} c
                 WHERE {$where}
              ORDER BY c.initdate ASC, c.inittimets ASC, c.id ASC";
        $rows = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r->groupid)) {
                $enrolled = (int)$DB->count_records_select(
                    'groups_members',
                    'groupid = :gid AND userid <> :iid',
                    ['gid' => (int)$r->groupid, 'iid' => (int)$r->instructorid]
                );
                $already = groups_is_member((int)$r->groupid, $userid);
            } else {
                $enrolled = (int)$DB->count_records_select(
                    'gmk_course_progre',
                    'classid = :cid AND userid <> :iid',
                    ['cid' => (int)$r->id, 'iid' => (int)$r->instructorid]
                );
                $already = $DB->record_exists('gmk_course_progre', ['classid' => (int)$r->id, 'userid' => $userid]);
            }
            $out[] = [
                'id' => (int)$r->id,
                'name' => (string)$r->name,
                'learningplanid' => (int)$r->learningplanid,
                'courseid' => (int)$r->courseid,
                'corecourseid' => (int)$r->corecourseid,
                'groupid' => (int)$r->groupid,
                'enrolled' => $enrolled,
                'alreadyenrolled' => (bool)$already,
                'initdate' => (int)$r->initdate,
                'enddate' => (int)$r->enddate,
            ];
        }
        return $out;
    };

    // Preferred path: by learningcourseid in this learning plan.
    $where = $base . ' AND c.courseid = :lcid AND c.learningplanid = :lpid';
    $params = $paramsbase + [
        'lcid' => (int)$classrow->learningcourseid,
        'lpid' => (int)$classrow->learningplanid,
    ];
    $classes = $fetch($where, $params);

    // Fallback path: by corecourseid in this learning plan.
    if (empty($classes)) {
        $where2 = $base . ' AND c.corecourseid = :ccid AND c.learningplanid = :lpid';
        $params2 = $paramsbase + [
            'ccid' => (int)$classrow->corecourseid,
            'lpid' => (int)$classrow->learningplanid,
        ];
        $classes = $fetch($where2, $params2);
    }

    return $classes;
}

echo $OUTPUT->header();
?>
<style>
.dbg-wrap { max-width: 1700px; margin: 16px auto; }
.dbg-card { background: #fff; border: 1px solid #d7dce1; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.dbg-title { margin: 0 0 10px 0; font-size: 20px; font-weight: 700; }
.dbg-sub { margin: 0 0 8px 0; font-size: 15px; font-weight: 700; }
.dbg-muted { color: #4b5563; font-size: 12px; }
.dbg-ok { color: #0f766e; font-weight: 700; }
.dbg-bad { color: #b91c1c; font-weight: 700; }
.dbg-warn { color: #92400e; font-weight: 700; }
table.dbg-table { width: 100%; border-collapse: collapse; font-size: 12px; }
table.dbg-table th, table.dbg-table td { border: 1px solid #d7dce1; padding: 6px; vertical-align: top; text-align: left; }
table.dbg-table th { background: #f3f4f6; }
.dbg-form-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.dbg-form-row input { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
.dbg-form-row button { padding: 6px 10px; border: 1px solid #2563eb; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; }
.dbg-pill { display: inline-block; padding: 2px 6px; border-radius: 10px; font-size: 11px; border: 1px solid #cbd5e1; background: #f8fafc; margin-right: 4px; margin-bottom: 2px; }
</style>

<div class="dbg-wrap">
  <div class="dbg-card">
    <h1 class="dbg-title">Debug Enroll Option by Student</h1>
    <form method="get" class="dbg-form-row">
      <label for="userid"><strong>User ID</strong></label>
      <input id="userid" name="userid" type="number" value="<?php echo (int)$userid; ?>" />
      <label for="student"><strong>Student name/idnumber</strong></label>
      <input id="student" name="student" type="text" size="40" value="<?php echo dbg_es($studentquery); ?>" />
      <label for="subject"><strong>Subject</strong></label>
      <input id="subject" name="subject" type="text" size="40" value="<?php echo dbg_es($subjectquery); ?>" />
      <button type="submit">Run</button>
    </form>
    <p class="dbg-muted">This page replicates the same rules as the INSCRIBIR button in grademodal.js.</p>
  </div>

<?php
$selecteduser = null;
$usermatches = [];

if ($userid > 0) {
    $selecteduser = $DB->get_record('user', ['id' => (int)$userid, 'deleted' => 0], 'id,firstname,lastname,email,idnumber', IGNORE_MISSING);
    if ($selecteduser) {
        $usermatches[$selecteduser->id] = $selecteduser;
    }
}

if (!$selecteduser && $studentquery !== '') {
    $likename = $DB->sql_like("CONCAT(u.firstname, ' ', u.lastname)", ':q1', false, false);
    $likeidn = $DB->sql_like('u.idnumber', ':q2', false, false);
    $sqlusers = "SELECT u.id, u.firstname, u.lastname, u.email, u.idnumber
                   FROM {user} u
                  WHERE u.deleted = 0
                    AND ({$likename} OR {$likeidn})
               ORDER BY u.firstname ASC, u.lastname ASC";
    $usermatches = $DB->get_records_sql($sqlusers, [
        'q1' => '%' . $studentquery . '%',
        'q2' => '%' . $studentquery . '%',
    ]);

    if (empty($usermatches)) {
        $allusers = $DB->get_records_select('user', 'deleted = 0', null, 'firstname ASC,lastname ASC', 'id,firstname,lastname,email,idnumber');
        foreach ($allusers as $au) {
            $full = trim($au->firstname . ' ' . $au->lastname);
            if (dbg_contains_norm($full, $studentquery) || dbg_contains_norm((string)$au->idnumber, $studentquery)) {
                $usermatches[$au->id] = $au;
            }
        }
    }

    if (count($usermatches) === 1) {
        $selecteduser = reset($usermatches);
    }
}

if (empty($usermatches)) {
    echo '<div class="dbg-card"><span class="dbg-bad">No student found with current query.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

if (!$selecteduser && count($usermatches) > 1) {
    echo '<div class="dbg-card">';
    echo '<h2 class="dbg-sub">Matching Students (' . count($usermatches) . ')</h2>';
    echo '<table class="dbg-table"><thead><tr><th>User ID</th><th>Name</th><th>ID Number</th><th>Email</th><th>Action</th></tr></thead><tbody>';
    foreach ($usermatches as $um) {
        $pickurl = new moodle_url('/local/grupomakro_core/pages/debug_enroll_option_student.php', [
            'userid' => (int)$um->id,
            'student' => $studentquery,
            'subject' => $subjectquery
        ]);
        echo '<tr>';
        echo '<td>' . (int)$um->id . '</td>';
        echo '<td>' . dbg_es(trim($um->firstname . ' ' . $um->lastname)) . '</td>';
        echo '<td>' . dbg_es($um->idnumber ?: '-') . '</td>';
        echo '<td>' . dbg_es($um->email ?: '-') . '</td>';
        echo '<td><a href="' . dbg_es($pickurl->out(false)) . '">Use this student</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

if (!$selecteduser) {
    $selecteduser = reset($usermatches);
}

$userid = (int)$selecteduser->id;
$fullname = trim($selecteduser->firstname . ' ' . $selecteduser->lastname);
echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Selected Student</h2>';
echo '<p><strong>User ID:</strong> ' . $userid . ' | <strong>Name:</strong> ' . dbg_es($fullname) . ' | <strong>ID Number:</strong> ' . dbg_es($selecteduser->idnumber ?: '-') . ' | <strong>Email:</strong> ' . dbg_es($selecteduser->email ?: '-') . '</p>';
echo '</div>';

$llurows = $DB->get_records('local_learning_users', ['userid' => $userid], 'id ASC', 'id,learningplanid,currentperiodid,currentsubperiodid,status,userroleid,userrolename');
$studentplanids = [];
$lluByPlan = [];
foreach ($llurows as $lr) {
    $isstudent = ((int)$lr->userroleid === 5) || (strtolower((string)$lr->userrolename) === 'student');
    if (!$isstudent) {
        continue;
    }
    $lpid = (int)$lr->learningplanid;
    if ($lpid > 0) {
        $studentplanids[$lpid] = $lpid;
        $lluByPlan[$lpid] = $lr;
    }
}
$studentplanids = array_values($studentplanids);
sort($studentplanids);

$plans = [];
if (!empty($studentplanids)) {
    list($plsql, $plparams) = $DB->get_in_or_equal($studentplanids, SQL_PARAMS_NAMED, 'pl');
    $plans = $DB->get_records_select('local_learning_plans', "id {$plsql}", $plparams, '', 'id,name');
}

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Student Plans (local_learning_users)</h2>';
echo '<table class="dbg-table"><thead><tr><th>Plan ID</th><th>Plan Name</th><th>Status</th><th>Current Period</th><th>Current Subperiod</th></tr></thead><tbody>';
if (empty($studentplanids)) {
    echo '<tr><td colspan="5" class="dbg-bad">No student enrollment rows found in local_learning_users.</td></tr>';
} else {
    foreach ($studentplanids as $lpid) {
        $lr = $lluByPlan[$lpid];
        $pname = isset($plans[$lpid]) ? $plans[$lpid]->name : '-';
        echo '<tr>';
        echo '<td>' . (int)$lpid . '</td>';
        echo '<td>' . dbg_es($pname) . '</td>';
        echo '<td>' . dbg_es($lr->status) . '</td>';
        echo '<td>' . (int)$lr->currentperiodid . '</td>';
        echo '<td>' . (int)$lr->currentsubperiodid . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';
echo '</div>';

// Fetch all plan-course rows for this student plans.
$subjectrowsinstudentplans = [];
if (!empty($studentplanids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($studentplanids, SQL_PARAMS_NAMED, 'sp');
    $sql = "SELECT lpc.id AS learningcourseid, lpc.learningplanid, lpc.periodid, lpc.courseid AS corecourseid,
                   c.fullname AS coursename, c.shortname
              FROM {local_learning_courses} lpc
              JOIN {course} c ON c.id = lpc.courseid
             WHERE lpc.learningplanid {$insql}
          ORDER BY lpc.learningplanid ASC, lpc.id ASC";
    $allplanrows = $DB->get_records_sql($sql, $inparams);
    foreach ($allplanrows as $apr) {
        if (dbg_contains_norm((string)$apr->coursename, $subjectquery) || dbg_contains_norm((string)$apr->shortname, $subjectquery)) {
            $subjectrowsinstudentplans[] = $apr;
        }
    }
}

// Also show where this subject exists globally (outside student plans).
$subjectrowsglobal = [];
$sqlglobal = "SELECT lpc.id AS learningcourseid, lpc.learningplanid, lpc.periodid, lpc.courseid AS corecourseid,
                     c.fullname AS coursename, c.shortname
                FROM {local_learning_courses} lpc
                JOIN {course} c ON c.id = lpc.courseid
            ORDER BY lpc.learningplanid ASC, lpc.id ASC";
$globalrows = $DB->get_records_sql($sqlglobal);
foreach ($globalrows as $gr) {
    if (dbg_contains_norm((string)$gr->coursename, $subjectquery) || dbg_contains_norm((string)$gr->shortname, $subjectquery)) {
        $subjectrowsglobal[] = $gr;
    }
}

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Subject Mapping</h2>';
echo '<p><strong>Subject query:</strong> ' . dbg_es($subjectquery) . '</p>';

echo '<h3 class="dbg-sub">In student plans</h3>';
echo '<table class="dbg-table"><thead><tr><th>Plan ID</th><th>Learning Course ID</th><th>Core Course ID</th><th>Course Name</th></tr></thead><tbody>';
if (empty($subjectrowsinstudentplans)) {
    echo '<tr><td colspan="4" class="dbg-bad">Subject not found in student plans.</td></tr>';
} else {
    foreach ($subjectrowsinstudentplans as $sr) {
        echo '<tr><td>' . (int)$sr->learningplanid . '</td><td>' . (int)$sr->learningcourseid . '</td><td>' . (int)$sr->corecourseid . '</td><td>' . dbg_es($sr->coursename) . '</td></tr>';
    }
}
echo '</tbody></table>';

echo '<h3 class="dbg-sub">Global plans with this subject</h3>';
echo '<table class="dbg-table"><thead><tr><th>Plan ID</th><th>Learning Course ID</th><th>Core Course ID</th><th>Course Name</th><th>In student plan?</th></tr></thead><tbody>';
if (empty($subjectrowsglobal)) {
    echo '<tr><td colspan="5" class="dbg-bad">Subject not found in local_learning_courses.</td></tr>';
} else {
    foreach ($subjectrowsglobal as $sr) {
        $in = in_array((int)$sr->learningplanid, $studentplanids, true);
        echo '<tr>';
        echo '<td>' . (int)$sr->learningplanid . '</td>';
        echo '<td>' . (int)$sr->learningcourseid . '</td>';
        echo '<td>' . (int)$sr->corecourseid . '</td>';
        echo '<td>' . dbg_es($sr->coursename) . '</td>';
        echo '<td>' . ($in ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-warn">NO</span>') . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';
echo '</div>';

// Build diagnostics per plan-subject row.
echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Enroll Button Diagnostics (same frontend rules)</h2>';
echo '<table class="dbg-table">';
echo '<thead><tr><th>Plan</th><th>Learning Course</th><th>Core Course</th><th>Pensum course found?</th><th>Status Label</th><th>ActiveClassCount</th><th>Allowed Status?</th><th>Has Active?</th><th>Can Enroll?</th><th>Reason</th></tr></thead><tbody>';

if (empty($subjectrowsinstudentplans)) {
    echo '<tr><td colspan="10" class="dbg-bad">No subject rows in student plans, button cannot be enabled.</td></tr>';
} else {
    foreach ($subjectrowsinstudentplans as $sr) {
        $planid = (int)$sr->learningplanid;
        $pensumresult = \local_grupomakro_core\external\student\get_student_learning_plan_pensum::execute($userid, $planid);
        $pensumparsed = [];
        if (!empty($pensumresult['pensum'])) {
            $decoded = json_decode((string)$pensumresult['pensum'], true);
            if (is_array($decoded)) {
                $pensumparsed = $decoded;
            }
        }

        $coursematch = null;
        foreach ($pensumparsed as $periodbucket) {
            $courses = is_array($periodbucket) && isset($periodbucket['courses']) && is_array($periodbucket['courses'])
                ? $periodbucket['courses']
                : [];
            foreach ($courses as $pc) {
                $pcore = (int)($pc['courseid'] ?? 0);
                $plc = (int)($pc['learningcourseid'] ?? 0);
                if ($pcore === (int)$sr->corecourseid || $plc === (int)$sr->learningcourseid) {
                    $coursematch = $pc;
                    break 2;
                }
            }
        }

        $statuslabel = $coursematch ? (string)($coursematch['statusLabel'] ?? '') : '';
        $activecount = $coursematch ? (int)($coursematch['activeclasscount'] ?? 0) : 0;
        $allowed = dbg_allowed_status_for_enroll($statuslabel);
        $hasactive = $activecount > 0;
        $canenroll = $allowed && $hasactive;

        $reason = '';
        if (!$coursematch) {
            $reason = 'course_not_returned_by_pensum';
        } else if (!$allowed) {
            $reason = 'status_not_allowed_for_enroll';
        } else if (!$hasactive) {
            $reason = 'no_active_classes';
        } else {
            $reason = 'eligible';
        }

        echo '<tr>';
        echo '<td>' . $planid . ' - ' . dbg_es(isset($plans[$planid]) ? $plans[$planid]->name : '-') . '</td>';
        echo '<td>' . (int)$sr->learningcourseid . '</td>';
        echo '<td>' . (int)$sr->corecourseid . '</td>';
        echo '<td>' . ($coursematch ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
        echo '<td>' . dbg_es($statuslabel ?: '-') . '</td>';
        echo '<td>' . (int)$activecount . '</td>';
        echo '<td>' . ($allowed ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
        echo '<td>' . ($hasactive ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
        echo '<td>' . ($canenroll ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>') . '</td>';
        echo '<td>' . dbg_es($reason) . '</td>';
        echo '</tr>';
    }
}

echo '</tbody></table>';
echo '</div>';

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Active Classes API-equivalent (per plan)</h2>';

if (empty($subjectrowsinstudentplans)) {
    echo '<p class="dbg-bad">Skipped: no subject rows in student plans.</p>';
} else {
    foreach ($subjectrowsinstudentplans as $sr) {
        $planid = (int)$sr->learningplanid;
        $classes = dbg_get_active_classes_like_api($sr, $userid);
        echo '<h3 class="dbg-sub">Plan ' . $planid . ' | learningcourseid ' . (int)$sr->learningcourseid . ' | corecourseid ' . (int)$sr->corecourseid . '</h3>';
        echo '<p><strong>Classes returned:</strong> ' . count($classes) . '</p>';
        echo '<table class="dbg-table"><thead><tr><th>Class ID</th><th>Name</th><th>Plan</th><th>CourseID map</th><th>Core Course</th><th>Group</th><th>Enrolled</th><th>Already Enrolled?</th><th>Init</th><th>End</th></tr></thead><tbody>';
        if (empty($classes)) {
            echo '<tr><td colspan="10" class="dbg-bad">No classes returned by API-equivalent query.</td></tr>';
        } else {
            foreach ($classes as $cl) {
                echo '<tr>';
                echo '<td>' . (int)$cl['id'] . '</td>';
                echo '<td>' . dbg_es($cl['name']) . '</td>';
                echo '<td>' . (int)$cl['learningplanid'] . '</td>';
                echo '<td>' . (int)$cl['courseid'] . '</td>';
                echo '<td>' . (int)$cl['corecourseid'] . '</td>';
                echo '<td>' . (int)$cl['groupid'] . '</td>';
                echo '<td>' . (int)$cl['enrolled'] . '</td>';
                echo '<td>' . ($cl['alreadyenrolled'] ? '<span class="dbg-warn">YES</span>' : '<span class="dbg-ok">NO</span>') . '</td>';
                echo '<td>' . userdate((int)$cl['initdate']) . '</td>';
                echo '<td>' . userdate((int)$cl['enddate']) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }
}
echo '</div>';

// Progress rows for this subject across all plans.
$coreids = [];
foreach ($subjectrowsglobal as $sg) {
    $coreids[(int)$sg->corecourseid] = (int)$sg->corecourseid;
}
$coreids = array_values($coreids);

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">gmk_course_progre for subject core courses</h2>';
echo '<table class="dbg-table"><thead><tr><th>gcp.id</th><th>Core Course</th><th>Learning Plan</th><th>Status</th><th>Status Label</th><th>Class ID</th><th>Group ID</th><th>Grade</th><th>Progress</th><th>Modified</th></tr></thead><tbody>';
if (empty($coreids)) {
    echo '<tr><td colspan="10" class="dbg-bad">No core course resolved for subject.</td></tr>';
} else {
    list($csql, $cparams) = $DB->get_in_or_equal($coreids, SQL_PARAMS_NAMED, 'cc');
    $gcp = $DB->get_records_sql(
        "SELECT id, courseid, learningplanid, status, classid, groupid, grade, progress, timemodified
           FROM {gmk_course_progre}
          WHERE userid = :uid
            AND courseid {$csql}
       ORDER BY courseid ASC, learningplanid ASC, id ASC",
        ['uid' => $userid] + $cparams
    );
    if (empty($gcp)) {
        echo '<tr><td colspan="10" class="dbg-bad">No progress rows for this student and subject.</td></tr>';
    } else {
        foreach ($gcp as $r) {
            $instudent = in_array((int)$r->learningplanid, $studentplanids, true);
            echo '<tr>';
            echo '<td>' . (int)$r->id . '</td>';
            echo '<td>' . (int)$r->courseid . '</td>';
            echo '<td>' . (int)$r->learningplanid . ($instudent ? ' <span class="dbg-ok">(student plan)</span>' : ' <span class="dbg-warn">(other plan)</span>') . '</td>';
            echo '<td>' . (int)$r->status . '</td>';
            echo '<td>' . dbg_es(dbg_status_label((int)$r->status)) . '</td>';
            echo '<td>' . (int)$r->classid . '</td>';
            echo '<td>' . (int)$r->groupid . '</td>';
            echo '<td>' . dbg_es($r->grade) . '</td>';
            echo '<td>' . dbg_es($r->progress) . '</td>';
            echo '<td>' . userdate((int)$r->timemodified) . '</td>';
            echo '</tr>';
        }
    }
}
echo '</tbody></table>';
echo '</div>';
?>
</div>
<?php
echo $OUTPUT->footer();

