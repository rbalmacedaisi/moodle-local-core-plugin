<?php
// Debug page: why a student enrolled in a class is not shown as "Cursando" in Academic Director panel.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$pageurl = new moodle_url('/local/grupomakro_core/pages/debug_cursando_class.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title('Debug Cursando by Class');
$PAGE->set_heading('Debug Cursando by Class');
$PAGE->set_pagelayout('admin');

$classid = optional_param('classid', 0, PARAM_INT);
$classname = optional_param('classname', '2026-II (N) GEOGRAFIA DE PANAMA (PRESENCIAL) A', PARAM_RAW_TRIMMED);
$limit = optional_param('limit', 500, PARAM_INT);
$limit = max(1, min(5000, (int)$limit));

/**
 * Short HTML-escaped text.
 * @param mixed $value
 * @return string
 */
function dbg_h($value): string {
    return s((string)$value);
}

/**
 * Status label for gmk_course_progre.
 * @param int $status
 * @return string
 */
function dbg_status_label(int $status): string {
    $map = [
        0 => 'No disponible',
        1 => 'Disponible',
        2 => 'Cursando',
        3 => 'Completado',
        4 => 'Aprobada',
        5 => 'Reprobada',
        6 => 'Pendiente revalida',
        7 => 'Revalidando',
        99 => 'Migracion',
    ];
    return $map[$status] ?? ('Status ' . $status);
}

/**
 * Diagnostic reason label.
 * @param string $reason
 * @return string
 */
function dbg_reason_label(string $reason): string {
    $map = [
        'ok_visible' => 'OK visible as cursando',
        'no_student_plan' => 'No student plan rows in local_learning_users',
        'course_not_in_student_plans' => 'Course not linked to student plans',
        'no_progress_for_course' => 'No gmk_course_progre rows for this course',
        'progress_plan_mismatch' => 'Progress rows exist but in different plan',
        'status2_other_class' => 'Status=2 but linked to another class',
        'terminal_status' => 'Terminal status (3/4/5/6/7/99) in student plan',
        'status_available' => 'Status is 0/1 in student plan',
        'unknown' => 'Unknown',
    ];
    return $map[$reason] ?? $reason;
}

/**
 * CSV-like list.
 * @param array $values
 * @return string
 */
function dbg_list(array $values): string {
    if (empty($values)) {
        return '-';
    }
    return implode(', ', $values);
}

$matches = [];
$selectedclass = null;

if ($classid > 0) {
    $selectedclass = $DB->get_record('gmk_class', ['id' => $classid]);
    if ($selectedclass) {
        $matches[$selectedclass->id] = $selectedclass;
    }
}

if (!$selectedclass && $classname !== '') {
    $like = $DB->sql_like('name', ':name', false, false);
    $matches = $DB->get_records_select(
        'gmk_class',
        $like,
        ['name' => '%' . $classname . '%'],
        'id DESC',
        'id,name,corecourseid,courseid,learningplanid,groupid,instructorid,approved,closed,periodid,initdate,enddate'
    );
    if (count($matches) === 1) {
        $selectedclass = reset($matches);
    }
}

echo $OUTPUT->header();
?>
<style>
.dbg-wrap { max-width: 1600px; margin: 16px auto; }
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
code { font-size: 12px; }
.dbg-pill { display: inline-block; padding: 2px 6px; border-radius: 10px; font-size: 11px; border: 1px solid #cbd5e1; background: #f8fafc; margin-right: 4px; margin-bottom: 2px; }
.dbg-form-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.dbg-form-row input { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
.dbg-form-row button { padding: 6px 10px; border: 1px solid #2563eb; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; }
details { margin-top: 4px; }
</style>

<div class="dbg-wrap">
  <div class="dbg-card">
    <h1 class="dbg-title">Debug Cursando by Class</h1>
    <form method="get" class="dbg-form-row">
      <label for="classid"><strong>Class ID</strong></label>
      <input id="classid" name="classid" type="number" value="<?php echo (int)$classid; ?>" />
      <label for="classname"><strong>Class Name Contains</strong></label>
      <input id="classname" name="classname" type="text" size="70" value="<?php echo dbg_h($classname); ?>" />
      <label for="limit"><strong>Limit</strong></label>
      <input id="limit" name="limit" type="number" value="<?php echo (int)$limit; ?>" min="1" max="5000" />
      <button type="submit">Run</button>
    </form>
    <p class="dbg-muted">Tip: if multiple classes match by name, click one Class ID from the results table.</p>
  </div>

<?php
if (empty($matches)) {
    echo '<div class="dbg-card"><span class="dbg-bad">No class found with current filter.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

if (!$selectedclass && count($matches) > 1) {
    echo '<div class="dbg-card">';
    echo '<h2 class="dbg-sub">Matching Classes (' . count($matches) . ')</h2>';
    echo '<table class="dbg-table">';
    echo '<thead><tr><th>ID</th><th>Name</th><th>Core Course</th><th>CourseID(local_learning_courses.id)</th><th>Class Plan</th><th>Group</th><th>Approved</th><th>Closed</th><th>Action</th></tr></thead><tbody>';
    foreach ($matches as $m) {
        $pickurl = new moodle_url('/local/grupomakro_core/pages/debug_cursando_class.php', [
            'classid' => (int)$m->id,
            'classname' => $classname,
            'limit' => $limit
        ]);
        echo '<tr>';
        echo '<td>' . (int)$m->id . '</td>';
        echo '<td>' . dbg_h($m->name) . '</td>';
        echo '<td>' . (int)$m->corecourseid . '</td>';
        echo '<td>' . (int)$m->courseid . '</td>';
        echo '<td>' . (int)$m->learningplanid . '</td>';
        echo '<td>' . (int)$m->groupid . '</td>';
        echo '<td>' . (int)$m->approved . '</td>';
        echo '<td>' . (int)$m->closed . '</td>';
        echo '<td><a href="' . dbg_h($pickurl->out(false)) . '">Use this class</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

if (!$selectedclass) {
    $selectedclass = reset($matches);
}

$class = $DB->get_record('gmk_class', ['id' => (int)$selectedclass->id], '*', MUST_EXIST);
$corename = $DB->get_field('course', 'fullname', ['id' => (int)$class->corecourseid], IGNORE_MISSING);

$subjectbyid = null;
if (!empty($class->courseid)) {
    $subjectbyid = $DB->get_record('local_learning_courses', ['id' => (int)$class->courseid], 'id,learningplanid,periodid,courseid', IGNORE_MISSING);
}
$subjectbycourse = $DB->get_records('local_learning_courses', ['courseid' => (int)$class->corecourseid], 'learningplanid ASC, id ASC', 'id,learningplanid,periodid,courseid');
$courseplanids = [];
foreach ($subjectbycourse as $sb) {
    $courseplanids[(int)$sb->learningplanid] = (int)$sb->learningplanid;
}
$courseplanids = array_values($courseplanids);
$expectedplan = $subjectbyid ? (int)$subjectbyid->learningplanid : 0;
$classplan = (int)$class->learningplanid;
$classplanok = ($classplan > 0 && in_array($classplan, $courseplanids, true));
$courseidok = ($subjectbyid && (int)$subjectbyid->courseid === (int)$class->corecourseid);

$sameclassname = $DB->get_records('gmk_class', ['name' => (string)$class->name], 'id DESC', 'id,periodid,approved,closed,groupid,learningplanid');

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Selected Class</h2>';
echo '<table class="dbg-table"><tbody>';
echo '<tr><th>ID</th><td>' . (int)$class->id . '</td><th>Name</th><td>' . dbg_h($class->name) . '</td></tr>';
echo '<tr><th>Core Course</th><td>' . (int)$class->corecourseid . ' - ' . dbg_h($corename ?: '-') . '</td><th>Class Plan</th><td>' . $classplan . '</td></tr>';
echo '<tr><th>CourseID map</th><td>' . (int)$class->courseid . '</td><th>Expected Plan from CourseID</th><td>' . ($expectedplan ?: '-') . '</td></tr>';
echo '<tr><th>Group</th><td>' . (int)$class->groupid . '</td><th>Instructor</th><td>' . (int)$class->instructorid . '</td></tr>';
echo '<tr><th>Approved/Closed</th><td>' . (int)$class->approved . '/' . (int)$class->closed . '</td><th>Period</th><td>' . (int)$class->periodid . '</td></tr>';
echo '</tbody></table>';

echo '<p class="dbg-muted">Class plan in course-plan map: ';
echo $classplanok ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>';
echo ' | class.courseid -> local_learning_courses.courseid equals class.corecourseid: ';
echo $courseidok ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>';
echo '</p>';

echo '<p class="dbg-muted">Course appears in learning plans: ' . dbg_h(dbg_list($courseplanids)) . '</p>';
echo '<p class="dbg-muted">Classes with same exact name: ' . count($sameclassname) . '</p>';
if (count($sameclassname) > 1) {
    echo '<details><summary>Show classes with same name</summary>';
    echo '<table class="dbg-table"><thead><tr><th>ID</th><th>Period</th><th>Plan</th><th>Group</th><th>Approved</th><th>Closed</th></tr></thead><tbody>';
    foreach ($sameclassname as $sc) {
        echo '<tr>';
        echo '<td>' . (int)$sc->id . '</td>';
        echo '<td>' . (int)$sc->periodid . '</td>';
        echo '<td>' . (int)$sc->learningplanid . '</td>';
        echo '<td>' . (int)$sc->groupid . '</td>';
        echo '<td>' . (int)$sc->approved . '</td>';
        echo '<td>' . (int)$sc->closed . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</details>';
}
echo '</div>';

// Build enrolled user set from group + gmk_course_progre by classid.
$studentmap = [];
$allclassprogre = $DB->get_records('gmk_course_progre', ['classid' => (int)$class->id], 'id ASC', 'id,userid,learningplanid,status,classid,groupid,grade,progress,timemodified');
foreach ($allclassprogre as $pr) {
    $uid = (int)$pr->userid;
    if ($uid <= 0 || $uid === (int)$class->instructorid) {
        continue;
    }
    if (!isset($studentmap[$uid])) {
        $studentmap[$uid] = ['ingroup' => false, 'inprogre' => false, 'classprogre' => []];
    }
    $studentmap[$uid]['inprogre'] = true;
    $studentmap[$uid]['classprogre'][] = $pr;
}

if (!empty($class->groupid)) {
    $groupuids = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :gid', ['gid' => (int)$class->groupid]);
    foreach ($groupuids as $giduid) {
        $uid = (int)$giduid;
        if ($uid <= 0 || $uid === (int)$class->instructorid) {
            continue;
        }
        if (!isset($studentmap[$uid])) {
            $studentmap[$uid] = ['ingroup' => false, 'inprogre' => false, 'classprogre' => []];
        }
        $studentmap[$uid]['ingroup'] = true;
    }
}

$studentids = array_keys($studentmap);
sort($studentids);

$totalstudents = count($studentids);
if ($totalstudents > $limit) {
    $studentids = array_slice($studentids, 0, $limit);
}

$usersbyid = [];
if (!empty($studentids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'u');
    $usersbyid = $DB->get_records_sql(
        "SELECT id, firstname, lastname, email, idnumber
           FROM {user}
          WHERE deleted = 0
            AND id {$insql}",
        $inparams
    );
}

$rows = [];
$reasoncounts = [];

foreach ($studentids as $uid) {
    $u = $usersbyid[$uid] ?? null;
    if (!$u) {
        continue;
    }

    $llurows = $DB->get_records('local_learning_users', ['userid' => (int)$uid], 'id ASC', 'id,learningplanid,currentperiodid,currentsubperiodid,status,userroleid,userrolename');
    $studentplans = [];
    $studentplanstatus = [];
    foreach ($llurows as $lr) {
        $isstudentrole = ((int)$lr->userroleid === 5) || (strtolower((string)$lr->userrolename) === 'student');
        if ($isstudentrole) {
            $lpid = (int)$lr->learningplanid;
            if ($lpid > 0 && !in_array($lpid, $studentplans, true)) {
                $studentplans[] = $lpid;
                $studentplanstatus[$lpid] = (string)$lr->status;
            }
        }
    }
    sort($studentplans);

    $gcprows = $DB->get_records(
        'gmk_course_progre',
        ['userid' => (int)$uid, 'courseid' => (int)$class->corecourseid],
        'learningplanid ASC, id ASC',
        'id,learningplanid,status,classid,groupid,grade,progress,timemodified'
    );

    $panelplans = array_values(array_intersect($studentplans, $courseplanids));
    sort($panelplans);

    $hasanyinstudentplans = false;
    $hasstatus2exact = false;
    $hasstatus2otherclass = false;
    $hasterminalinstudentplan = false;
    $hasavailableinstudentplan = false;
    $planmismatchrows = false;
    $dupinplan = [];
    $bestmatchstatus = [];

    foreach ($gcprows as $gp) {
        $lpid = (int)$gp->learningplanid;
        if (!isset($dupinplan[$lpid])) {
            $dupinplan[$lpid] = 0;
        }
        $dupinplan[$lpid]++;

        $instudentplan = in_array($lpid, $studentplans, true);
        if ($instudentplan) {
            $hasanyinstudentplans = true;
            if (!isset($bestmatchstatus[$lpid])) {
                $bestmatchstatus[$lpid] = [];
            }
            $bestmatchstatus[$lpid][] = (int)$gp->status;
            if ((int)$gp->status === 2 && (int)$gp->classid === (int)$class->id) {
                $hasstatus2exact = true;
            } else if ((int)$gp->status === 2 && (int)$gp->classid !== (int)$class->id) {
                $hasstatus2otherclass = true;
            }
            if (in_array((int)$gp->status, [3, 4, 5, 6, 7, 99], true)) {
                $hasterminalinstudentplan = true;
            }
            if (in_array((int)$gp->status, [0, 1], true)) {
                $hasavailableinstudentplan = true;
            }
        } else {
            $planmismatchrows = true;
        }
    }

    $hasduplicateplanrows = false;
    foreach ($dupinplan as $cnt) {
        if ($cnt > 1) {
            $hasduplicateplanrows = true;
            break;
        }
    }

    $panelseescursando = false;
    foreach ($panelplans as $pp) {
        foreach ($gcprows as $gp) {
            if ((int)$gp->learningplanid === (int)$pp && (int)$gp->status === 2) {
                $panelseescursando = true;
                break 2;
            }
        }
    }

    $reason = 'unknown';
    if ($panelseescursando && $hasstatus2exact) {
        $reason = 'ok_visible';
    } else if (empty($studentplans)) {
        $reason = 'no_student_plan';
    } else if (empty($panelplans)) {
        $reason = 'course_not_in_student_plans';
    } else if (empty($gcprows)) {
        $reason = 'no_progress_for_course';
    } else if (!$hasanyinstudentplans && $planmismatchrows) {
        $reason = 'progress_plan_mismatch';
    } else if ($hasstatus2otherclass && !$hasstatus2exact) {
        $reason = 'status2_other_class';
    } else if ($hasterminalinstudentplan) {
        $reason = 'terminal_status';
    } else if ($hasavailableinstudentplan) {
        $reason = 'status_available';
    }

    if (!isset($reasoncounts[$reason])) {
        $reasoncounts[$reason] = 0;
    }
    $reasoncounts[$reason]++;

    $gcpcompact = [];
    foreach ($gcprows as $gp) {
        $gcpcompact[] = 'id=' . (int)$gp->id .
            ' lp=' . (int)$gp->learningplanid .
            ' st=' . (int)$gp->status .
            ' cls=' . (int)$gp->classid .
            ' grp=' . (int)$gp->groupid;
    }

    $rows[] = [
        'uid' => (int)$uid,
        'idnumber' => (string)($u->idnumber ?: ''),
        'name' => fullname($u),
        'source' => ($studentmap[$uid]['ingroup'] ? 'group' : '-') . '+' . ($studentmap[$uid]['inprogre'] ? 'progre' : '-'),
        'studentplans' => $studentplans,
        'panelplans' => $panelplans,
        'panelseescursando' => $panelseescursando,
        'hasstatus2exact' => $hasstatus2exact,
        'reason' => $reason,
        'hasduplicateplanrows' => $hasduplicateplanrows,
        'gcpcompact' => $gcpcompact,
        'gcprows' => $gcprows,
        'llurows' => $llurows,
    ];
}

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Enrollment Scope</h2>';
echo '<p>Total enrolled users detected from group/progre union: <strong>' . $totalstudents . '</strong>';
if ($totalstudents > $limit) {
    echo ' | Showing first ' . (int)$limit;
}
echo '</p>';
echo '<p class="dbg-muted">Group members considered: ' . (!empty($class->groupid) ? 'YES' : 'NO (class has no group)') . ' | Progre rows by classid considered: YES</p>';
echo '</div>';

arsort($reasoncounts);
echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Reason Summary</h2>';
echo '<table class="dbg-table"><thead><tr><th>Reason</th><th>Count</th></tr></thead><tbody>';
foreach ($reasoncounts as $reasonkey => $count) {
    $css = ($reasonkey === 'ok_visible') ? 'dbg-ok' : 'dbg-bad';
    echo '<tr>';
    echo '<td class="' . $css . '">' . dbg_h(dbg_reason_label($reasonkey)) . ' <span class="dbg-muted">(' . dbg_h($reasonkey) . ')</span></td>';
    echo '<td>' . (int)$count . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';

echo '<div class="dbg-card">';
echo '<h2 class="dbg-sub">Student Details</h2>';
echo '<table class="dbg-table">';
echo '<thead><tr>';
echo '<th>User</th>';
echo '<th>Source</th>';
echo '<th>Student Plans</th>';
echo '<th>Panel Plans for Course</th>';
echo '<th>Panel sees Cursando?</th>';
echo '<th>Status=2 in this class?</th>';
echo '<th>Reason</th>';
echo '<th>Flags</th>';
echo '<th>gmk_course_progre for this course</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $r) {
    $okpanel = $r['panelseescursando'] ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>';
    $okexact = $r['hasstatus2exact'] ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>';
    $reasoncss = ($r['reason'] === 'ok_visible') ? 'dbg-ok' : 'dbg-bad';
    $flags = [];
    if ($r['hasduplicateplanrows']) {
        $flags[] = 'duplicate_plan_rows';
    }
    if (empty($r['gcpcompact'])) {
        $flags[] = 'no_progress_rows';
    }

    echo '<tr>';
    echo '<td><strong>' . dbg_h($r['name']) . '</strong><br><span class="dbg-muted">uid=' . (int)$r['uid'] . ' idnumber=' . dbg_h($r['idnumber'] ?: '-') . '</span></td>';
    echo '<td>' . dbg_h($r['source']) . '</td>';
    echo '<td>' . dbg_h(dbg_list($r['studentplans'])) . '</td>';
    echo '<td>' . dbg_h(dbg_list($r['panelplans'])) . '</td>';
    echo '<td>' . $okpanel . '</td>';
    echo '<td>' . $okexact . '</td>';
    echo '<td class="' . $reasoncss . '">' . dbg_h(dbg_reason_label($r['reason'])) . '<br><span class="dbg-muted">(' . dbg_h($r['reason']) . ')</span></td>';
    echo '<td>' . dbg_h(dbg_list($flags)) . '</td>';
    echo '<td>';
    if (!empty($r['gcpcompact'])) {
        foreach ($r['gcpcompact'] as $line) {
            echo '<span class="dbg-pill">' . dbg_h($line) . '</span>';
        }
    } else {
        echo '-';
    }
    echo '<details><summary>Raw rows</summary>';
    echo '<table class="dbg-table"><thead><tr><th>gcp.id</th><th>lp</th><th>status</th><th>status label</th><th>classid</th><th>groupid</th><th>grade</th><th>progress</th><th>timemodified</th></tr></thead><tbody>';
    foreach ($r['gcprows'] as $gp) {
        echo '<tr>';
        echo '<td>' . (int)$gp->id . '</td>';
        echo '<td>' . (int)$gp->learningplanid . '</td>';
        echo '<td>' . (int)$gp->status . '</td>';
        echo '<td>' . dbg_h(dbg_status_label((int)$gp->status)) . '</td>';
        echo '<td>' . (int)$gp->classid . '</td>';
        echo '<td>' . (int)$gp->groupid . '</td>';
        echo '<td>' . dbg_h($gp->grade) . '</td>';
        echo '<td>' . dbg_h($gp->progress) . '</td>';
        echo '<td>' . userdate((int)$gp->timemodified) . '</td>';
        echo '</tr>';
    }
    if (empty($r['gcprows'])) {
        echo '<tr><td colspan="9">No rows</td></tr>';
    }
    echo '</tbody></table>';

    echo '<table class="dbg-table"><thead><tr><th>llu.id</th><th>lp</th><th>status</th><th>currentperiod</th><th>currentsubperiod</th><th>role</th></tr></thead><tbody>';
    foreach ($r['llurows'] as $lr) {
        echo '<tr>';
        echo '<td>' . (int)$lr->id . '</td>';
        echo '<td>' . (int)$lr->learningplanid . '</td>';
        echo '<td>' . dbg_h($lr->status) . '</td>';
        echo '<td>' . (int)$lr->currentperiodid . '</td>';
        echo '<td>' . (int)$lr->currentsubperiodid . '</td>';
        echo '<td>' . dbg_h($lr->userrolename ?: $lr->userroleid) . '</td>';
        echo '</tr>';
    }
    if (empty($r['llurows'])) {
        echo '<tr><td colspan="6">No rows</td></tr>';
    }
    echo '</tbody></table>';
    echo '</details>';
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';
?>
</div>
<?php
echo $OUTPUT->footer();

