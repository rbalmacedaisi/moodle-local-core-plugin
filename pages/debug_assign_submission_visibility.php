<?php
// Debug page for assignment submission visibility mismatch in teacher dashboard.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/group/lib.php');

global $DB, $PAGE, $OUTPUT;

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);
admin_externalpage_setup('grupomakro_core_debug_assign_submission_visibility');

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_assign_submission_visibility.php'));
$PAGE->set_context($syscontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug Assign Submission Visibility');
$PAGE->set_heading('Debug Assign Submission Visibility');

$run = optional_param('run', 1, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$classquery = trim(optional_param('classquery', '', PARAM_RAW_TRIMMED));
$assignmentid = optional_param('assignmentid', 0, PARAM_INT);
$assignmentquery = trim(optional_param('assignmentquery', '', PARAM_RAW_TRIMMED));
$studentid = optional_param('studentid', 0, PARAM_INT);
$studentquery = trim(optional_param('studentquery', '', PARAM_RAW_TRIMMED));
$tasksubmissionid = optional_param('tasksubmissionid', 0, PARAM_INT);
$maxrows = optional_param('maxrows', 100, PARAM_INT);
$showalltext = optional_param('showalltext', 0, PARAM_INT);

if ($maxrows < 10) {
    $maxrows = 10;
}
if ($maxrows > 500) {
    $maxrows = 500;
}

/**
 * Escape helper.
 * @param mixed $v
 * @return string
 */
function dasv_h($v): string {
    return s((string)$v);
}

/**
 * Format timestamp.
 * @param int $ts
 * @return string
 */
function dasv_ts(int $ts): string {
    if ($ts <= 0) {
        return '-';
    }
    return userdate($ts, '%Y-%m-%d %H:%M:%S');
}

/**
 * Normalize text for loose matching.
 * @param string $text
 * @return string
 */
function dasv_norm(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false && $ascii !== '') {
        $text = $ascii;
    }
    $text = core_text::strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string)$text);
}

/**
 * Check if haystack matches query in normalized form.
 * @param string $haystack
 * @param string $query
 * @return bool
 */
function dasv_match(string $haystack, string $query): bool {
    $query = dasv_norm($query);
    if ($query === '') {
        return true;
    }
    $hay = dasv_norm($haystack);
    return $hay !== '' && strpos($hay, $query) !== false;
}

/**
 * Render generic table.
 * @param string[] $headers
 * @param array[] $rows
 * @return void
 */
function dasv_render_table(array $headers, array $rows): void {
    echo '<table class="generaltable" style="width:100%;">';
    echo '<thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . dasv_h($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($headers) . '"><em>No rows</em></td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . (string)$cell . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
}

/**
 * Build URL preserving filters.
 * @param array $extra
 * @return moodle_url
 */
function dasv_url(array $extra = []): moodle_url {
    global $classquery, $assignmentquery, $studentquery, $tasksubmissionid, $maxrows, $showalltext;
    $base = [
        'run' => 1,
        'classquery' => $classquery,
        'assignmentquery' => $assignmentquery,
        'studentquery' => $studentquery,
        'tasksubmissionid' => $tasksubmissionid,
        'maxrows' => $maxrows,
        'showalltext' => $showalltext,
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    return new moodle_url('/local/grupomakro_core/pages/debug_assign_submission_visibility.php', $base);
}

/**
 * Resolve class candidates.
 * @param int $classid
 * @param string $classquery
 * @param int $maxrows
 * @return array
 */
function dasv_find_classes(int $classid, string $classquery, int $maxrows): array {
    global $DB;
    if ($classid > 0) {
        $sql = "SELECT c.id, c.name, c.corecourseid, c.courseid, c.groupid, c.coursesectionid, c.gradecategoryid,
                       c.instructorid, c.learningplanid, c.periodid, c.approved, c.closed,
                       crs.fullname AS corecoursename,
                       lp.name AS planname,
                       p.name AS periodname
                  FROM {gmk_class} c
             LEFT JOIN {course} crs ON crs.id = c.corecourseid
             LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
             LEFT JOIN {local_learning_periods} p ON p.id = c.periodid
                 WHERE c.id = :cid";
        return array_values($DB->get_records_sql($sql, ['cid' => $classid]));
    }
    if ($classquery === '') {
        return [];
    }
    $like = '%' . $DB->sql_like_escape($classquery) . '%';
    $sql = "SELECT c.id, c.name, c.corecourseid, c.courseid, c.groupid, c.coursesectionid, c.gradecategoryid,
                   c.instructorid, c.learningplanid, c.periodid, c.approved, c.closed,
                   crs.fullname AS corecoursename,
                   lp.name AS planname,
                   p.name AS periodname
              FROM {gmk_class} c
         LEFT JOIN {course} crs ON crs.id = c.corecourseid
         LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
         LEFT JOIN {local_learning_periods} p ON p.id = c.periodid
             WHERE " . $DB->sql_like('c.name', ':q1', false) . "
                OR " . $DB->sql_like('crs.fullname', ':q2', false) . "
          ORDER BY c.id DESC";
    return array_values($DB->get_records_sql($sql, ['q1' => $like, 'q2' => $like], 0, $maxrows));
}

/**
 * Resolve assignment candidates inside class course.
 * @param stdClass $class
 * @param int $assignmentid
 * @param string $assignmentquery
 * @param int $maxrows
 * @return array
 */
function dasv_find_assignments_for_class(stdClass $class, int $assignmentid, string $assignmentquery, int $maxrows): array {
    global $DB;

    $courseid = (int)$class->corecourseid > 0 ? (int)$class->corecourseid : (int)$class->courseid;
    if ($courseid <= 0) {
        return [];
    }

    $params = ['courseid' => $courseid, 'modname' => 'assign'];
    $where = "a.course = :courseid";
    if ($assignmentid > 0) {
        $where .= " AND a.id = :aid";
        $params['aid'] = $assignmentid;
    } else if ($assignmentquery !== '') {
        $like = '%' . $DB->sql_like_escape($assignmentquery) . '%';
        $where .= " AND " . $DB->sql_like('a.name', ':aq', false);
        $params['aq'] = $like;
    }

    $sql = "SELECT a.id, a.name, a.course, a.duedate, a.allowsubmissionsfromdate, a.cutoffdate,
                   a.teamsubmission, a.submissiondrafts, a.intro, a.introformat,
                   cm.id AS cmid, cm.section AS cmsection,
                   gi.id AS gradeitemid, gi.categoryid
              FROM {assign} a
              JOIN {course_modules} cm ON cm.instance = a.id
              JOIN {modules} m ON m.id = cm.module AND m.name = :modname
         LEFT JOIN {grade_items} gi
                ON gi.courseid = a.course
               AND gi.itemtype = 'mod'
               AND gi.itemmodule = 'assign'
               AND gi.iteminstance = a.id
             WHERE $where
          ORDER BY a.id DESC";

    $rows = array_values($DB->get_records_sql($sql, $params, 0, $maxrows));
    foreach ($rows as $r) {
        $scope = [];
        if (!empty($class->coursesectionid) && (int)$r->cmsection === (int)$class->coursesectionid) {
            $scope[] = 'section';
        }
        if (!empty($class->gradecategoryid) && (int)$r->categoryid === (int)$class->gradecategoryid) {
            $scope[] = 'gradecat';
        }
        if (preg_match('/-' . (int)$class->id . '$/', (string)$r->name)) {
            $scope[] = 'name_suffix';
        }
        $r->scopeflags = $scope;
    }
    return $rows;
}

/**
 * Resolve students linked to class (group/progre/queue/prereg).
 * @param stdClass $class
 * @param int $studentid
 * @param string $studentquery
 * @param int $maxrows
 * @return array
 */
function dasv_find_students_for_class(stdClass $class, int $studentid, string $studentquery, int $maxrows): array {
    global $DB;
    $candidateids = [];

    if ((int)$class->groupid > 0) {
        $gm = $DB->get_records('groups_members', ['groupid' => (int)$class->groupid], '', 'id,userid');
        foreach ($gm as $r) {
            $uid = (int)$r->userid;
            if ($uid > 0) {
                $candidateids[$uid] = true;
            }
        }
    }
    $cp = $DB->get_records('gmk_course_progre', ['classid' => (int)$class->id], '', 'id,userid');
    foreach ($cp as $r) {
        $uid = (int)$r->userid;
        if ($uid > 0) {
            $candidateids[$uid] = true;
        }
    }
    $qrows = $DB->get_records('gmk_class_queue', ['classid' => (int)$class->id], '', 'id,userid');
    foreach ($qrows as $r) {
        $uid = (int)$r->userid;
        if ($uid > 0) {
            $candidateids[$uid] = true;
        }
    }
    $prrows = $DB->get_records('gmk_class_pre_registration', ['classid' => (int)$class->id], '', 'id,userid');
    foreach ($prrows as $r) {
        $uid = (int)$r->userid;
        if ($uid > 0) {
            $candidateids[$uid] = true;
        }
    }

    if ($studentid > 0) {
        $candidateids[(int)$studentid] = true;
    }

    if (empty($candidateids)) {
        return [];
    }
    $userids = array_values(array_map('intval', array_keys($candidateids)));
    list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
    $users = $DB->get_records_sql(
        "SELECT id, firstname, lastname, username, email, idnumber, deleted, suspended
           FROM {user}
          WHERE id {$insql}
       ORDER BY lastname ASC, firstname ASC",
        $inparams
    );
    $out = [];
    foreach ($users as $u) {
        if ((int)$u->deleted === 1) {
            continue;
        }
        $full = trim((string)$u->firstname . ' ' . (string)$u->lastname);
        $blob = $full . ' ' . (string)$u->username . ' ' . (string)$u->email . ' ' . (string)$u->idnumber;
        if ($studentquery !== '' && !dasv_match($blob, $studentquery)) {
            continue;
        }
        $out[] = $u;
        if (count($out) >= $maxrows) {
            break;
        }
    }
    return $out;
}

/**
 * Flatten user groups for a course.
 * @param int $courseid
 * @param int $userid
 * @return int[]
 */
function dasv_user_group_ids(int $courseid, int $userid): array {
    $flat = [];
    $groups = groups_get_user_groups($courseid, $userid);
    if (is_array($groups)) {
        foreach ($groups as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $gid) {
                $gid = (int)$gid;
                if ($gid > 0) {
                    $flat[$gid] = $gid;
                }
            }
        }
    }
    ksort($flat);
    return array_values($flat);
}

/**
 * Load file rows from file API area.
 * @param file_storage $fs
 * @param int $contextid
 * @param string $component
 * @param string $filearea
 * @param int $itemid
 * @param string $source
 * @return array
 */
function dasv_get_area_files_rows(file_storage $fs, int $contextid, string $component, string $filearea, int $itemid, string $source): array {
    $rows = [];
    $files = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'sortorder', false);
    foreach ($files as $f) {
        $url = moodle_url::make_pluginfile_url(
            $f->get_contextid(),
            $f->get_component(),
            $f->get_filearea(),
            $f->get_itemid(),
            $f->get_filepath(),
            $f->get_filename()
        );
        $rows[] = (object)[
            'source' => $source,
            'component' => (string)$f->get_component(),
            'filearea' => (string)$f->get_filearea(),
            'itemid' => (int)$f->get_itemid(),
            'filename' => (string)$f->get_filename(),
            'filesize' => (int)$f->get_filesize(),
            'mimetype' => (string)$f->get_mimetype(),
            'url' => $url->out(false),
            'pathnamehash' => (string)$f->get_pathnamehash(),
        ];
    }
    return $rows;
}

echo $OUTPUT->header();

echo html_writer::tag('h2', 'Debug Assign Submission Visibility');
echo html_writer::tag(
    'p',
    'Use this page to inspect why inline text/images are visible in Moodle but not in Teacher Dashboard.'
);

echo '<form method="get" action="">';
echo '<input type="hidden" name="run" value="1">';
echo '<table class="generaltable" style="max-width:1200px;">';
echo '<tr>';
echo '<td><strong>Class (id or name)</strong></td>';
echo '<td><input type="text" name="classid" value="' . dasv_h($classid > 0 ? $classid : '') . '" placeholder="class id" style="width:120px;"> ';
echo '<input type="text" name="classquery" value="' . dasv_h($classquery) . '" placeholder="class name" style="width:420px;"></td>';
echo '</tr>';
echo '<tr>';
echo '<td><strong>Assignment (id or name)</strong></td>';
echo '<td><input type="text" name="assignmentid" value="' . dasv_h($assignmentid > 0 ? $assignmentid : '') . '" placeholder="assignment id" style="width:120px;"> ';
echo '<input type="text" name="assignmentquery" value="' . dasv_h($assignmentquery) . '" placeholder="assignment name" style="width:420px;"></td>';
echo '</tr>';
echo '<tr>';
echo '<td><strong>Student (id or name/email/idnumber)</strong></td>';
echo '<td><input type="text" name="studentid" value="' . dasv_h($studentid > 0 ? $studentid : '') . '" placeholder="student id" style="width:120px;"> ';
echo '<input type="text" name="studentquery" value="' . dasv_h($studentquery) . '" placeholder="student search" style="width:420px;"></td>';
echo '</tr>';
echo '<tr>';
echo '<td><strong>Task submission id (optional)</strong></td>';
echo '<td><input type="text" name="tasksubmissionid" value="' . dasv_h($tasksubmissionid > 0 ? $tasksubmissionid : '') . '" placeholder="submission id from modal" style="width:220px;"></td>';
echo '</tr>';
echo '<tr>';
echo '<td><strong>Max rows</strong></td>';
echo '<td><input type="number" name="maxrows" min="10" max="500" value="' . (int)$maxrows . '" style="width:120px;"> ';
echo '<label><input type="checkbox" name="showalltext" value="1"' . ($showalltext ? ' checked' : '') . '> show full html text payload</label></td>';
echo '</tr>';
echo '<tr><td></td><td><button type="submit" class="btn btn-primary">Diagnose</button></td></tr>';
echo '</table>';
echo '</form>';

if (!$run) {
    echo $OUTPUT->footer();
    exit;
}

$classcandidates = dasv_find_classes($classid, $classquery, $maxrows);
echo html_writer::tag('h3', 'Class candidates');
$classrows = [];
foreach ($classcandidates as $c) {
    $pickurl = dasv_url([
        'classid' => (int)$c->id,
        'assignmentid' => $assignmentid,
        'studentid' => $studentid,
    ]);
    $classrows[] = [
        (int)$c->id,
        dasv_h((string)$c->name),
        dasv_h((string)($c->corecoursename ?? '')),
        (int)$c->groupid,
        (int)$c->coursesectionid,
        (int)$c->gradecategoryid,
        ((int)$c->approved) . '/' . ((int)$c->closed),
        '<a href="' . $pickurl->out(false) . '">Use</a>',
    ];
}
dasv_render_table(
    ['ID', 'Class', 'Core course', 'Group', 'Section', 'Grade cat', 'Approved/Closed', 'Action'],
    $classrows
);

$selectedclass = null;
foreach ($classcandidates as $c) {
    if ((int)$c->id === (int)$classid) {
        $selectedclass = $c;
        break;
    }
}
if (!$selectedclass && count($classcandidates) === 1) {
    $selectedclass = $classcandidates[0];
    $classid = (int)$selectedclass->id;
}

if (!$selectedclass) {
    echo html_writer::tag('div', 'Select one class to continue.', ['class' => 'alert alert-info']);
    echo $OUTPUT->footer();
    exit;
}

$courseid = (int)$selectedclass->corecourseid > 0 ? (int)$selectedclass->corecourseid : (int)$selectedclass->courseid;
echo html_writer::tag(
    'p',
    '<strong>Selected class:</strong> #' . (int)$selectedclass->id
        . ' ' . dasv_h((string)$selectedclass->name)
        . ' | course=' . $courseid
        . ' | group=' . (int)$selectedclass->groupid
        . ' | section=' . (int)$selectedclass->coursesectionid
        . ' | gradecat=' . (int)$selectedclass->gradecategoryid
);

$assignmentcandidates = dasv_find_assignments_for_class($selectedclass, $assignmentid, $assignmentquery, $maxrows);
echo html_writer::tag('h3', 'Assignment candidates');
$assignmentrows = [];
foreach ($assignmentcandidates as $a) {
    $scope = empty($a->scopeflags) ? '-' : implode(', ', $a->scopeflags);
    $pickurl = dasv_url([
        'classid' => (int)$selectedclass->id,
        'assignmentid' => (int)$a->id,
        'studentid' => $studentid,
    ]);
    $assignmentrows[] = [
        (int)$a->id,
        (int)$a->cmid,
        dasv_h((string)$a->name),
        (int)$a->cmsection,
        (int)$a->categoryid,
        dasv_ts((int)$a->allowsubmissionsfromdate) . ' -> ' . dasv_ts((int)$a->duedate),
        ((int)$a->teamsubmission) . '/' . ((int)$a->submissiondrafts),
        dasv_h($scope),
        '<a href="' . $pickurl->out(false) . '">Use</a>',
    ];
}
dasv_render_table(
    ['Assign ID', 'CMID', 'Name', 'CM section', 'Grade cat', 'Window', 'Team/Drafts', 'Scope flags', 'Action'],
    $assignmentrows
);

$selectedassignment = null;
foreach ($assignmentcandidates as $a) {
    if ((int)$a->id === (int)$assignmentid) {
        $selectedassignment = $a;
        break;
    }
}
if (!$selectedassignment && count($assignmentcandidates) === 1) {
    $selectedassignment = $assignmentcandidates[0];
    $assignmentid = (int)$selectedassignment->id;
}
if (!$selectedassignment) {
    echo html_writer::tag('div', 'Select one assignment to continue.', ['class' => 'alert alert-info']);
    echo $OUTPUT->footer();
    exit;
}

$studentcandidates = dasv_find_students_for_class($selectedclass, $studentid, $studentquery, $maxrows);
echo html_writer::tag('h3', 'Student candidates');
$studentrows = [];
foreach ($studentcandidates as $u) {
    $pickurl = dasv_url([
        'classid' => (int)$selectedclass->id,
        'assignmentid' => (int)$selectedassignment->id,
        'studentid' => (int)$u->id,
    ]);
    $studentrows[] = [
        (int)$u->id,
        dasv_h(trim((string)$u->firstname . ' ' . (string)$u->lastname)),
        dasv_h((string)$u->idnumber),
        dasv_h((string)$u->username),
        dasv_h((string)$u->email),
        (int)$u->suspended,
        '<a href="' . $pickurl->out(false) . '">Use</a>',
    ];
}
dasv_render_table(
    ['User ID', 'Name', 'ID number', 'Username', 'Email', 'Suspended', 'Action'],
    $studentrows
);

$selectedstudent = null;
foreach ($studentcandidates as $u) {
    if ((int)$u->id === (int)$studentid) {
        $selectedstudent = $u;
        break;
    }
}
if (!$selectedstudent && count($studentcandidates) === 1) {
    $selectedstudent = $studentcandidates[0];
    $studentid = (int)$selectedstudent->id;
}
if (!$selectedstudent) {
    echo html_writer::tag('div', 'Select one student to continue.', ['class' => 'alert alert-info']);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag(
    'p',
    '<strong>Selected assignment:</strong> #' . (int)$selectedassignment->id
        . ' ' . dasv_h((string)$selectedassignment->name)
        . ' | cmid=' . (int)$selectedassignment->cmid
        . '<br><strong>Selected student:</strong> #' . (int)$selectedstudent->id
        . ' ' . dasv_h(trim((string)$selectedstudent->firstname . ' ' . (string)$selectedstudent->lastname))
        . ' | idnumber=' . dasv_h((string)$selectedstudent->idnumber)
        . ' | email=' . dasv_h((string)$selectedstudent->email)
        . '<br><strong>Task submission id:</strong> ' . ((int)$tasksubmissionid > 0 ? (int)$tasksubmissionid : '-')
);

$cmcontext = context_module::instance((int)$selectedassignment->cmid);
$fs = get_file_storage();
$groupids = dasv_user_group_ids($courseid, (int)$selectedstudent->id);

$subparams = ['aid' => (int)$selectedassignment->id, 'uid' => (int)$selectedstudent->id];
$subwhere = 'assignment = :aid AND userid = :uid';
if (!empty($groupids)) {
    list($ginsql, $ginparams) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'gid');
    $subwhere = "assignment = :aid AND (userid = :uid OR groupid {$ginsql})";
    $subparams = $subparams + $ginparams;
}
$submissions = array_values($DB->get_records_select(
    'assign_submission',
    $subwhere,
    $subparams,
    'latest DESC, timemodified DESC, id DESC'
));
$assign = null;
$assignusersubmissionid = 0;
$groupsubmissionid = 0;
$requestedvalid = false;
$simulatedselectedsubmissionid = 0;
$simulatedstrategy = 'none';

try {
    $cm = get_coursemodule_from_instance('assign', (int)$selectedassignment->id, $courseid, false, IGNORE_MISSING);
    if ($cm) {
        $assigncontext = context_module::instance((int)$cm->id);
        $course = get_course($courseid);
        $assign = new assign($assigncontext, $cm, $course);
        $assignusersubmission = $assign->get_user_submission((int)$selectedstudent->id, false);
        if ($assignusersubmission) {
            $assignusersubmissionid = (int)$assignusersubmission->id;
        }
    }
} catch (Throwable $e) {
    $assign = null;
}

if (!empty($groupids)) {
    list($ginsql, $ginparams) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'simgid');
    $groupsub = $DB->get_record_sql(
        "SELECT id
           FROM {assign_submission}
          WHERE assignment = :aid
            AND groupid {$ginsql}
       ORDER BY latest DESC, timemodified DESC, id DESC",
        ['aid' => (int)$selectedassignment->id] + $ginparams,
        IGNORE_MULTIPLE
    );
    if ($groupsub) {
        $groupsubmissionid = (int)$groupsub->id;
    }
}

$candidateorder = [];
if ((int)$tasksubmissionid > 0) {
    $candidateorder[] = (int)$tasksubmissionid;
}
if ($assignusersubmissionid > 0) {
    $candidateorder[] = $assignusersubmissionid;
}
if ($groupsubmissionid > 0) {
    $candidateorder[] = $groupsubmissionid;
}
foreach ($submissions as $srow) {
    $candidateorder[] = (int)$srow->id;
}
$candidateorder = array_values(array_unique(array_filter(array_map('intval', $candidateorder))));

$candidateinspection = [];
foreach ($candidateorder as $sid) {
    $srec = $DB->get_record(
        'assign_submission',
        ['id' => (int)$sid, 'assignment' => (int)$selectedassignment->id],
        'id,userid,groupid,attemptnumber,status,latest,timecreated,timemodified',
        IGNORE_MISSING
    );
    if (!$srec) {
        continue;
    }
    $isrequest = ((int)$tasksubmissionid > 0 && (int)$tasksubmissionid === (int)$sid);
    $isrequestvalid = false;
    if ($isrequest) {
        $isrequestvalid = (
            ((int)$srec->userid > 0 && (int)$srec->userid === (int)$selectedstudent->id) ||
            ((int)$srec->groupid > 0 && in_array((int)$srec->groupid, $groupids, true))
        );
        $requestedvalid = $isrequestvalid;
    }

    $ot = $DB->get_record(
        'assignsubmission_onlinetext',
        ['assignment' => (int)$selectedassignment->id, 'submission' => (int)$sid],
        'id,onlinetext,onlineformat',
        IGNORE_MISSING
    );
    $otlen = 0;
    $otrowid = 0;
    $othaspluginfile = 'NO';
    if ($ot) {
        $otrowid = (int)$ot->id;
        $otlen = core_text::strlen(trim(strip_tags((string)$ot->onlinetext)));
        $othaspluginfile = (strpos((string)$ot->onlinetext, '@@PLUGINFILE@@') !== false) ? 'YES' : 'NO';
    }

    $submissionfiles = $fs->get_area_files(
        (int)$cmcontext->id,
        'assignsubmission_file',
        'submission_files',
        (int)$sid,
        'sortorder',
        false
    );
    $inlinecount = 0;
    $inlineitemids = [(int)$sid];
    if ($otrowid > 0) {
        $inlineitemids[] = $otrowid;
    }
    $inlineitemids = array_values(array_unique(array_filter(array_map('intval', $inlineitemids))));
    foreach ($inlineitemids as $iid) {
        $inlinecount += count($fs->get_area_files(
            (int)$cmcontext->id,
            'assignsubmission_onlinetext',
            'onlinetext',
            (int)$iid,
            'sortorder',
            false
        ));
    }

    $hascontent = ($otlen > 0 || !empty($submissionfiles) || $inlinecount > 0);
    $candidateinspection[(int)$sid] = (object)[
        'id' => (int)$sid,
        'userid' => (int)$srec->userid,
        'groupid' => (int)$srec->groupid,
        'attemptnumber' => (int)$srec->attemptnumber,
        'latest' => (int)$srec->latest,
        'status' => (string)$srec->status,
        'timecreated' => (int)$srec->timecreated,
        'timemodified' => (int)$srec->timemodified,
        'isrequested' => $isrequest ? 1 : 0,
        'requestvalid' => $isrequestvalid ? 1 : 0,
        'onlinetextrowid' => $otrowid,
        'onlinetextlen' => $otlen,
        'onlinetexthaspluginfile' => $othaspluginfile,
        'submissionfilecount' => count($submissionfiles),
        'inlinefilecount' => (int)$inlinecount,
        'hascontent' => $hascontent ? 1 : 0,
    ];
}

$baseid = 0;
if ((int)$tasksubmissionid > 0 && !empty($candidateinspection[(int)$tasksubmissionid])) {
    if ((int)$candidateinspection[(int)$tasksubmissionid]->requestvalid === 1) {
        $baseid = (int)$tasksubmissionid;
        $simulatedstrategy = 'requested_submissionid';
    }
}
if ($baseid <= 0 && $assignusersubmissionid > 0 && !empty($candidateinspection[$assignusersubmissionid])) {
    $baseid = (int)$assignusersubmissionid;
    $simulatedstrategy = 'assign_get_user_submission';
}
if ($baseid <= 0 && $groupsubmissionid > 0 && !empty($candidateinspection[$groupsubmissionid])) {
    $baseid = (int)$groupsubmissionid;
    $simulatedstrategy = 'group_submission_fallback';
}
if ($baseid <= 0 && !empty($candidateorder)) {
    $baseid = (int)$candidateorder[0];
    $simulatedstrategy = 'first_candidate_fallback';
}

$simulatedselectedsubmissionid = $baseid;
if ($simulatedselectedsubmissionid > 0
    && !empty($candidateinspection[$simulatedselectedsubmissionid])
    && (int)$candidateinspection[$simulatedselectedsubmissionid]->hascontent === 0) {
    foreach ($candidateorder as $cid) {
        $cid = (int)$cid;
        if (!empty($candidateinspection[$cid]) && (int)$candidateinspection[$cid]->hascontent === 1) {
            $simulatedselectedsubmissionid = $cid;
            $simulatedstrategy .= '+content_fallback';
            break;
        }
    }
}

$submissionids = [];
foreach ($submissions as $srow) {
    $submissionids[(int)$srow->id] = (int)$srow->id;
}

$graderows = array_values($DB->get_records(
    'assign_grades',
    ['assignment' => (int)$selectedassignment->id, 'userid' => (int)$selectedstudent->id],
    'attemptnumber DESC, timemodified DESC'
));

$onlinetextrows = [];
if (!empty($submissionids)) {
    list($sinsql, $sinparams) = $DB->get_in_or_equal(array_values($submissionids), SQL_PARAMS_NAMED, 'sid');
    $sql = "SELECT id, assignment, submission, onlinetext, onlineformat
              FROM {assignsubmission_onlinetext}
             WHERE assignment = :aid
               AND submission {$sinsql}
          ORDER BY id DESC";
    $onlinetextrows = array_values($DB->get_records_sql($sql, ['aid' => (int)$selectedassignment->id] + $sinparams));
}
if (empty($onlinetextrows)) {
    // Fallback: pull latest onlinetext rows for assignment to detect mismatched submission references.
    $onlinetextrows = array_values($DB->get_records(
        'assignsubmission_onlinetext',
        ['assignment' => (int)$selectedassignment->id],
        'id DESC',
        'id,assignment,submission,onlinetext,onlineformat',
        0,
        50
    ));
}

$onlinetextitemids = [];
foreach ($submissionids as $sid) {
    $onlinetextitemids[$sid] = $sid;
}
foreach ($onlinetextrows as $ot) {
    $onlinetextitemids[(int)$ot->id] = (int)$ot->id;
}
$onlinetextitemids = array_values($onlinetextitemids);

$filerows = [];
$seen = [];
foreach ($submissionids as $sid) {
    $chunk = dasv_get_area_files_rows(
        $fs,
        (int)$cmcontext->id,
        'assignsubmission_file',
        'submission_files',
        (int)$sid,
        'submission_file'
    );
    foreach ($chunk as $r) {
        if (!isset($seen[$r->pathnamehash])) {
            $seen[$r->pathnamehash] = true;
            $filerows[] = $r;
        }
    }
}
foreach ($onlinetextitemids as $itemid) {
    $chunk = dasv_get_area_files_rows(
        $fs,
        (int)$cmcontext->id,
        'assignsubmission_onlinetext',
        'onlinetext',
        (int)$itemid,
        'onlinetext'
    );
    foreach ($chunk as $r) {
        if (!isset($seen[$r->pathnamehash])) {
            $seen[$r->pathnamehash] = true;
            $filerows[] = $r;
        }
    }
}

echo html_writer::tag('h3', 'QuickGrader selection simulation');
echo html_writer::tag(
    'p',
    '<strong>tasksubmissionid:</strong> ' . ((int)$tasksubmissionid > 0 ? (int)$tasksubmissionid : '-')
        . ' | <strong>requested valid:</strong> ' . ($requestedvalid ? 'YES' : 'NO')
        . ' | <strong>assign->get_user_submission:</strong> ' . ($assignusersubmissionid > 0 ? $assignusersubmissionid : '-')
        . ' | <strong>group fallback:</strong> ' . ($groupsubmissionid > 0 ? $groupsubmissionid : '-')
        . ' | <strong>selected:</strong> ' . ($simulatedselectedsubmissionid > 0 ? $simulatedselectedsubmissionid : '-')
        . ' | <strong>strategy:</strong> ' . dasv_h($simulatedstrategy)
);

$candidaterows = [];
foreach ($candidateorder as $cid) {
    $cid = (int)$cid;
    if (empty($candidateinspection[$cid])) {
        $candidaterows[] = [
            $cid,
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
        ];
        continue;
    }
    $diag = $candidateinspection[$cid];
    $candidaterows[] = [
        (int)$diag->id,
        (int)$diag->userid,
        (int)$diag->groupid,
        (int)$diag->attemptnumber,
        (int)$diag->latest,
        dasv_h((string)$diag->status),
        (int)$diag->isrequested ? 'YES' : 'NO',
        (int)$diag->requestvalid ? 'YES' : 'NO',
        (int)$diag->onlinetextrowid,
        (int)$diag->onlinetextlen,
        dasv_h((string)$diag->onlinetexthaspluginfile),
        (int)$diag->submissionfilecount . '/' . (int)$diag->inlinefilecount,
        ((int)$diag->hascontent ? 'YES' : 'NO') . ((int)$simulatedselectedsubmissionid === (int)$diag->id ? ' (selected)' : ''),
    ];
}
dasv_render_table(
    [
        'Submission ID',
        'User',
        'Group',
        'Attempt',
        'Latest',
        'Status',
        'Requested?',
        'Requested valid?',
        'Onlinetext row',
        'Onlinetext len',
        '@@PLUGINFILE@@',
        'Files (submission/inline)',
        'Has content / selected',
    ],
    $candidaterows
);

$submissiontable = [];
foreach ($submissions as $srow) {
    $submissiontable[] = [
        (int)$srow->id,
        (int)$srow->userid,
        (int)$srow->groupid,
        (int)$srow->attemptnumber,
        (int)$srow->latest,
        dasv_h((string)$srow->status),
        dasv_ts((int)$srow->timecreated),
        dasv_ts((int)$srow->timemodified),
    ];
}
echo html_writer::tag('h3', 'assign_submission rows');
dasv_render_table(
    ['ID', 'User', 'Group', 'Attempt', 'Latest', 'Status', 'Created', 'Modified'],
    $submissiontable
);

$gradetable = [];
foreach ($graderows as $g) {
    $gradetable[] = [
        (int)$g->id,
        (int)$g->assignment,
        (int)$g->userid,
        (int)$g->attemptnumber,
        (is_null($g->grade) ? 'NULL' : (string)$g->grade),
        dasv_ts((int)$g->timemodified),
    ];
}
echo html_writer::tag('h3', 'assign_grades rows');
dasv_render_table(
    ['ID', 'Assignment', 'User', 'Attempt', 'Grade', 'Modified'],
    $gradetable
);

$onlinetexttable = [];
foreach ($onlinetextrows as $ot) {
    $raw = (string)$ot->onlinetext;
    $rawlen = core_text::strlen($raw);
    $haspluginfile = (strpos($raw, '@@PLUGINFILE@@') !== false) ? 'YES' : 'NO';
    $belongs = isset($submissionids[(int)$ot->submission]) ? 'YES' : 'NO';
    $preview = dasv_h(core_text::substr(trim(strip_tags($raw)), 0, 120));
    $onlinetexttable[] = [
        (int)$ot->id,
        (int)$ot->submission,
        $belongs,
        (int)$ot->onlineformat,
        $rawlen,
        $haspluginfile,
        $preview,
    ];
}
echo html_writer::tag('h3', 'assignsubmission_onlinetext rows');
dasv_render_table(
    ['ID', 'Submission', 'Belongs to selected submission', 'Format', 'Raw length', '@@PLUGINFILE@@', 'Raw preview'],
    $onlinetexttable
);

$filestable = [];
foreach ($filerows as $f) {
    $filestable[] = [
        dasv_h((string)$f->source),
        dasv_h((string)$f->component),
        dasv_h((string)$f->filearea),
        (int)$f->itemid,
        dasv_h((string)$f->filename),
        (int)$f->filesize,
        dasv_h((string)$f->mimetype),
        '<a target="_blank" rel="noopener" href="' . dasv_h((string)$f->url) . '">Open</a>',
    ];
}
echo html_writer::tag('h3', 'File API rows');
dasv_render_table(
    ['Source', 'Component', 'Area', 'Item ID', 'Filename', 'Size', 'Mime', 'URL'],
    $filestable
);

echo html_writer::tag('h3', 'Onlinetext rewrite tests');
$rewriteheaders = ['Onlinetext row', 'Candidate itemid', 'Files in itemid', 'Rendered plain preview', 'Rendered HTML'];
$rewriterows = [];
foreach ($onlinetextrows as $ot) {
    $raw = (string)$ot->onlinetext;
    $candidateitemids = [(int)$ot->submission, (int)$ot->id];
    $candidateitemids = array_values(array_unique(array_filter($candidateitemids)));
    if (empty($candidateitemids)) {
        $candidateitemids = [0];
    }
    foreach ($candidateitemids as $candidateitemid) {
        $candidatefiles = $fs->get_area_files(
            (int)$cmcontext->id,
            'assignsubmission_onlinetext',
            'onlinetext',
            (int)$candidateitemid,
            'sortorder',
            false
        );
        $rewritten = file_rewrite_pluginfile_urls(
            $raw,
            'pluginfile.php',
            (int)$cmcontext->id,
            'assignsubmission_onlinetext',
            'onlinetext',
            (int)$candidateitemid
        );
        $formatted = format_text(
            $rewritten,
            (int)$ot->onlineformat,
            [
                'context' => $cmcontext,
                'overflowdiv' => true,
                'para' => false,
            ]
        );
        $plain = trim(strip_tags($formatted));
        $plainpreview = dasv_h(core_text::substr($plain, 0, 140));
        $htmlout = $showalltext
            ? $formatted
            : '<div style="max-height:240px;overflow:auto;border:1px solid #ddd;padding:8px;">' . $formatted . '</div>';
        $rewriterows[] = [
            (int)$ot->id,
            (int)$candidateitemid,
            count($candidatefiles),
            $plainpreview,
            $htmlout,
        ];
    }
}
dasv_render_table($rewriteheaders, $rewriterows);

$findings = [];
if (empty($submissions)) {
    $findings[] = 'No assign_submission rows found for selected student and assignment.';
}
if (!empty($submissions) && empty($onlinetextrows) && empty($filerows)) {
    $findings[] = 'Submission exists but no onlinetext rows and no files were found.';
}
if (!empty($onlinetextrows)) {
    $hasmatchingot = false;
    foreach ($onlinetextrows as $ot) {
        if (isset($submissionids[(int)$ot->submission])) {
            $hasmatchingot = true;
            break;
        }
    }
    if (!$hasmatchingot) {
        $findings[] = 'Onlinetext exists for assignment but not linked to selected submission IDs.';
    }
}
if (!empty($onlinetextrows) && empty($filerows)) {
    foreach ($onlinetextrows as $ot) {
        if (strpos((string)$ot->onlinetext, '@@PLUGINFILE@@') !== false) {
            $findings[] = 'Onlinetext contains @@PLUGINFILE@@ but no files were found in tested file areas/itemids.';
            break;
        }
    }
}
if ($simulatedselectedsubmissionid > 0 && !empty($candidateinspection[$simulatedselectedsubmissionid])) {
    $selecteddiag = $candidateinspection[$simulatedselectedsubmissionid];
    if ((int)$selecteddiag->hascontent === 0) {
        $findings[] = 'QuickGrader selected submission has no detectable content (text/files).';
    }
}
if ((int)$tasksubmissionid > 0 && !empty($candidateinspection[(int)$tasksubmissionid])) {
    $taskdiag = $candidateinspection[(int)$tasksubmissionid];
    if ((int)$taskdiag->hascontent === 1 && (int)$simulatedselectedsubmissionid !== (int)$tasksubmissionid) {
        $findings[] = 'Task submission id has content, but selection strategy resolved a different submission.';
    }
}

echo html_writer::tag('h3', 'Findings');
if (empty($findings)) {
    echo html_writer::tag('div', 'No obvious inconsistency detected in DB rows for this selection.', ['class' => 'alert alert-success']);
} else {
    echo '<ul>';
    foreach ($findings as $f) {
        echo '<li>' . dasv_h($f) . '</li>';
    }
    echo '</ul>';
}

echo $OUTPUT->footer();
