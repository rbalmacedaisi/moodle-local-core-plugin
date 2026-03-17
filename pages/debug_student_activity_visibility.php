<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

global $DB, $PAGE, $OUTPUT, $USER;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_student_activity_visibility.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug Student Activity Visibility');
$PAGE->set_heading('Debug Student Activity Visibility');

$classid = optional_param('classid', 0, PARAM_INT);
$classquery = optional_param('classquery', '2026-II (N) SEGURIDAD INDUSTRIAL Y SALUD OCUPACIONAL (PRESENCIAL) B', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$maxstudents = optional_param('maxstudents', 20, PARAM_INT);
$run = optional_param('run', 0, PARAM_BOOL);

if ($maxstudents < 5) {
    $maxstudents = 5;
}
if ($maxstudents > 120) {
    $maxstudents = 120;
}

function dsv_h($v): string {
    return s((string)$v);
}

function dsv_ts(int $ts): string {
    if ($ts <= 0) {
        return '-';
    }
    return userdate($ts, '%Y-%m-%d %H:%M');
}

function dsv_status_label(int $status): string {
    static $map = [
        0 => 'No disponible',
        1 => 'Disponible',
        2 => 'Cursando',
        3 => 'Completada',
        4 => 'Aprobada',
        5 => 'Reprobada',
        6 => 'Pendiente revalida',
        7 => 'Revalidando',
        99 => 'Migracion',
    ];
    return $map[$status] ?? ('Estado ' . $status);
}

function dsv_find_classes(int $classid, string $query): array {
    global $DB;
    if ($classid > 0) {
        $sql = "SELECT c.*, crs.fullname AS corecoursename, lp.name AS planname
                  FROM {gmk_class} c
             LEFT JOIN {course} crs ON crs.id = c.corecourseid
             LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
                 WHERE c.id = :classid";
        return array_values($DB->get_records_sql($sql, ['classid' => $classid]));
    }
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $like = '%' . $DB->sql_like_escape($query) . '%';
    $sql = "SELECT c.*, crs.fullname AS corecoursename, lp.name AS planname
              FROM {gmk_class} c
         LEFT JOIN {course} crs ON crs.id = c.corecourseid
         LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
             WHERE " . $DB->sql_like('c.name', ':q1', false) . "
                OR " . $DB->sql_like('crs.fullname', ':q2', false) . "
          ORDER BY c.closed ASC, c.approved DESC, c.id DESC";
    return array_values($DB->get_records_sql($sql, ['q1' => $like, 'q2' => $like], 0, 100));
}

function dsv_doc_map(array $userids): array {
    global $DB;
    $out = [];
    if (empty($userids)) {
        return $out;
    }
    $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']);
    if ($fieldid <= 0) {
        return $out;
    }
    list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
    $sql = "SELECT id, userid, data
              FROM {user_info_data}
             WHERE fieldid = :fieldid
               AND userid {$insql}";
    $rows = $DB->get_records_sql($sql, array_merge(['fieldid' => $fieldid], $params));
    foreach ($rows as $row) {
        $doc = trim((string)$row->data);
        if ($doc !== '') {
            $out[(int)$row->userid] = $doc;
        }
    }
    return $out;
}

function dsv_collect_students(stdClass $class): array {
    global $DB;
    $acc = [];
    if (!empty($class->groupid)) {
        $gm = $DB->get_records('groups_members', ['groupid' => (int)$class->groupid], '', 'id,userid');
        foreach ($gm as $r) {
            $uid = (int)$r->userid;
            if ($uid <= 0) {
                continue;
            }
            if (!isset($acc[$uid])) {
                $acc[$uid] = ['sources' => [], 'statuses' => []];
            }
            $acc[$uid]['sources']['group'] = true;
        }
    }
    $cp = $DB->get_records('gmk_course_progre', ['classid' => (int)$class->id], '', 'id,userid,status');
    foreach ($cp as $r) {
        $uid = (int)$r->userid;
        if ($uid <= 0) {
            continue;
        }
        if (!isset($acc[$uid])) {
            $acc[$uid] = ['sources' => [], 'statuses' => []];
        }
        $acc[$uid]['sources']['progre'] = true;
        $acc[$uid]['statuses'][(int)$r->status] = true;
    }
    $queue = $DB->get_records('gmk_class_queue', ['classid' => (int)$class->id], '', 'id,userid');
    foreach ($queue as $r) {
        $uid = (int)$r->userid;
        if ($uid <= 0) {
            continue;
        }
        if (!isset($acc[$uid])) {
            $acc[$uid] = ['sources' => [], 'statuses' => []];
        }
        $acc[$uid]['sources']['queue'] = true;
    }
    $prereg = $DB->get_records('gmk_class_pre_registration', ['classid' => (int)$class->id], '', 'id,userid');
    foreach ($prereg as $r) {
        $uid = (int)$r->userid;
        if ($uid <= 0) {
            continue;
        }
        if (!isset($acc[$uid])) {
            $acc[$uid] = ['sources' => [], 'statuses' => []];
        }
        $acc[$uid]['sources']['pre_registration'] = true;
    }
    if (!empty($class->instructorid)) {
        unset($acc[(int)$class->instructorid]);
    }
    if (empty($acc)) {
        return [];
    }
    $userids = array_values(array_map('intval', array_keys($acc)));
    list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
    $users = $DB->get_records_sql(
        "SELECT id, firstname, lastname, email, username, idnumber, deleted, suspended
           FROM {user}
          WHERE id {$insql}
       ORDER BY lastname ASC, firstname ASC",
        $params
    );
    $docmap = dsv_doc_map($userids);
    $out = [];
    foreach ($users as $u) {
        $uid = (int)$u->id;
        if (!isset($acc[$uid])) {
            continue;
        }
        $out[$uid] = (object)[
            'id' => $uid,
            'firstname' => (string)$u->firstname,
            'lastname' => (string)$u->lastname,
            'email' => (string)$u->email,
            'username' => (string)$u->username,
            'idnumber' => (string)$u->idnumber,
            'documentnumber' => $docmap[$uid] ?? '',
            'sources' => $acc[$uid]['sources'],
            'statuses' => $acc[$uid]['statuses'],
            'deleted' => (int)$u->deleted,
            'suspended' => (int)$u->suspended,
        ];
    }
    return $out;
}

function dsv_activities_for_user(stdClass $class, int $userid): array {
    $result = ['sectionfound' => false, 'rows' => [], 'warnings' => []];
    if (empty($class->corecourseid)) {
        $result['warnings'][] = 'class.corecourseid is empty';
        return $result;
    }
    require_once($GLOBALS['CFG']->libdir . '/modinfolib.php');
    $modinfo = get_fast_modinfo((int)$class->corecourseid, $userid);
    if (empty($class->coursesectionid)) {
        $result['warnings'][] = 'class.coursesectionid is empty';
        return $result;
    }
    $section = $modinfo->get_section_info_by_id((int)$class->coursesectionid);
    if (!$section) {
        $result['warnings'][] = 'section not found in modinfo';
        return $result;
    }
    $result['sectionfound'] = true;
    $sections = $modinfo->get_sections();
    $cmids = $sections[(int)$section->section] ?? [];
    foreach ($cmids as $cmid) {
        $cm = $modinfo->get_cm((int)$cmid);
        if ((string)$cm->modname === 'label') {
            continue;
        }
        $isgeneral = ((string)$cm->modname === 'attendance' || (string)$cm->modname === 'bigbluebuttonbn');
        $tags = [];
        if (!$isgeneral) {
            try {
                $tags = array_values(array_map(static function($t) { return (string)$t->rawname; }, \core_tag_tag::get_item_tags('core', 'course_modules', (int)$cm->id)));
            } catch (Throwable $t) {
                $tags = [];
            }
        }
        $result['rows'][] = (object)[
            'cmid' => (int)$cm->id,
            'modname' => (string)$cm->modname,
            'name' => (string)$cm->name,
            'uservisible' => !empty($cm->uservisible),
            'isgeneral' => $isgeneral,
            'tags' => $tags,
            'availability' => (string)($cm->availabilityinfo ?? ''),
        ];
    }
    return $result;
}

function dsv_virtual_map(stdClass $class): array {
    global $DB;
    $out = ['rows' => [], 'events_total' => 0, 'events_included' => 0, 'warnings' => []];
    $attinstance = 0;
    if (!empty($class->attendancemoduleid)) {
        $attinstance = (int)$DB->get_field_sql(
            "SELECT cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid AND m.name = 'attendance'",
            ['cmid' => (int)$class->attendancemoduleid]
        );
    }
    $bbbids = [];
    if (!empty($class->bbbmoduleids)) {
        foreach (explode(',', (string)$class->bbbmoduleids) as $cmidraw) {
            $cmid = (int)trim((string)$cmidraw);
            if ($cmid <= 0) {
                continue;
            }
            $bbbid = (int)$DB->get_field_sql(
                "SELECT cm.instance
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = :cmid AND m.name = 'bigbluebuttonbn'",
                ['cmid' => $cmid]
            );
            if ($bbbid > 0) {
                $bbbids[$bbbid] = $bbbid;
            }
        }
    }
    $relbbbs = $DB->get_fieldset_select('gmk_bbb_attendance_relation', 'bbbid', 'classid = :classid AND bbbid > 0', ['classid' => (int)$class->id]);
    foreach ($relbbbs as $x) {
        $x = (int)$x;
        if ($x > 0) {
            $bbbids[$x] = $x;
        }
    }
    $parts = [];
    $params = ['courseid' => (int)$class->corecourseid];
    if ($attinstance > 0) {
        $parts[] = "(e.modulename = 'attendance' AND e.instance = :attinstance)";
        $params['attinstance'] = $attinstance;
    }
    if (!empty($bbbids)) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_values($bbbids), SQL_PARAMS_NAMED, 'bb');
        $parts[] = "(e.modulename = 'bigbluebuttonbn' AND e.instance {$insql})";
        $params = array_merge($params, $inparams);
    }
    if (empty($parts)) {
        $out['warnings'][] = 'No attendance/bbb linkage found in class';
        return $out;
    }
    $events = $DB->get_records_sql(
        "SELECT e.id, e.modulename, e.instance, e.name, e.groupid, e.timestart, e.timeduration
           FROM {event} e
          WHERE e.courseid = :courseid
            AND (" . implode(' OR ', $parts) . ")
       ORDER BY e.timestart ASC, e.id ASC",
        $params
    );
    $out['events_total'] = count($events);
    foreach ($events as $event) {
        $included = true;
        $reason = 'ok';
        if ((string)$event->modulename === 'bigbluebuttonbn') {
            foreach ($events as $att) {
                if ((string)$att->modulename !== 'attendance') {
                    continue;
                }
                if (abs((int)$att->timestart - (int)$event->timestart) <= 601) {
                    $included = false;
                    $reason = 'dedup_near_attendance';
                    break;
                }
            }
        }
        if ($included) {
            $out['events_included']++;
        }
        $out['rows'][] = (object)[
            'id' => (int)$event->id,
            'modulename' => (string)$event->modulename,
            'instance' => (int)$event->instance,
            'name' => (string)$event->name,
            'start' => (int)$event->timestart,
            'end' => (int)$event->timestart + (int)$event->timeduration,
            'included' => $included,
            'reason' => $reason,
        ];
    }
    return $out;
}

function dsv_student_event_counts(int $userid, stdClass $class): array {
    $init = !empty($class->initdate) ? date('Y-m-d', (int)$class->initdate - 86400 * 7) : date('Y-m-d', strtotime('-90 days'));
    $end = !empty($class->enddate) ? date('Y-m-d', (int)$class->enddate + 86400 * 7) : date('Y-m-d', strtotime('+90 days'));
    $events = get_class_events($userid, $init, $end);
    $tot = 0;
    $virt = 0;
    $act = 0;
    foreach ($events as $e) {
        if ((int)($e->classId ?? 0) !== (int)$class->id) {
            continue;
        }
        $tot++;
        $mod = (string)($e->modulename ?? '');
        if ($mod === 'attendance' || $mod === 'bigbluebuttonbn') {
            $virt++;
        } else {
            $act++;
        }
    }
    return ['total' => $tot, 'virtual' => $virt, 'activity' => $act];
}

echo $OUTPUT->header();
?>
<style>
.dsv-form input{padding:6px;border:1px solid #cbd5e1;border-radius:6px}
.dsv-form button{padding:7px 10px;border:0;background:#0d6efd;color:#fff;border-radius:6px}
.dsv-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}
.dsv-table th{background:#1f2937;color:#fff;border:1px solid #374151;padding:6px}
.dsv-table td{border:1px solid #cbd5e1;padding:6px;vertical-align:top}
.ok{color:#166534;font-weight:700}.bad{color:#991b1b;font-weight:700}.warn{color:#92400e;font-weight:700}
</style>
<form method="get" class="dsv-form" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <div><div>Class ID</div><input type="number" name="classid" value="<?php echo dsv_h($classid); ?>"></div>
    <div style="min-width:420px"><div>Class query</div><input type="text" name="classquery" value="<?php echo dsv_h($classquery); ?>" style="width:100%"></div>
    <div><div>User ID</div><input type="number" name="userid" value="<?php echo dsv_h($userid); ?>"></div>
    <div><div>Max students</div><input type="number" name="maxstudents" value="<?php echo dsv_h($maxstudents); ?>"></div>
    <div><input type="hidden" name="run" value="1"><button type="submit">Diagnose</button></div>
</form>
<?php
$classes = dsv_find_classes((int)$classid, (string)$classquery);
if (empty($classes)) {
    echo html_writer::tag('p', '<span class="warn">No classes found with current filter.</span>');
    echo $OUTPUT->footer();
    return;
}
if (count($classes) > 1 && $classid <= 0) {
    echo html_writer::tag('h4', 'Class matches');
    echo '<table class="dsv-table"><thead><tr><th>ID</th><th>Name</th><th>Course</th><th>Plan</th><th>Section</th><th>Group</th><th>Action</th></tr></thead><tbody>';
    foreach ($classes as $c) {
        $url = new moodle_url('/local/grupomakro_core/pages/debug_student_activity_visibility.php', [
            'run' => 1, 'classid' => (int)$c->id, 'classquery' => $classquery, 'userid' => (int)$userid, 'maxstudents' => (int)$maxstudents,
        ]);
        echo '<tr>';
        echo '<td>' . (int)$c->id . '</td>';
        echo '<td>' . dsv_h($c->name) . '</td>';
        echo '<td>' . dsv_h($c->corecoursename ?? '-') . '</td>';
        echo '<td>' . dsv_h($c->planname ?? '-') . '</td>';
        echo '<td>' . (int)$c->coursesectionid . '</td>';
        echo '<td>' . (int)$c->groupid . '</td>';
        echo '<td><a href="' . $url->out(false) . '">Use</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo html_writer::tag('p', '<span class="warn">Select one class using the Use link to run detailed diagnostics.</span>');
    echo $OUTPUT->footer();
    return;
}
$class = $classes[0];
$students = dsv_collect_students($class);
$ids = array_values(array_map('intval', array_keys($students)));
$focus = [];
if ($userid > 0) {
    $focus[] = (int)$userid;
}
if (empty($focus)) {
    foreach ($ids as $sid) {
        $focus[] = $sid;
        if (count($focus) >= $maxstudents) {
            break;
        }
    }
}
$sample = !empty($focus) ? (int)$focus[0] : ((int)($ids[0] ?? $USER->id));
$sampleacts = dsv_activities_for_user($class, $sample);
$virtmap = dsv_virtual_map($class);
echo html_writer::tag('h4', 'Selected class');
echo '<table class="dsv-table"><tbody>';
echo '<tr><th>Class</th><td>#' . (int)$class->id . ' ' . dsv_h($class->name) . '</td></tr>';
echo '<tr><th>Course</th><td>' . (int)$class->corecourseid . ' - ' . dsv_h($class->corecoursename ?? '-') . '</td></tr>';
echo '<tr><th>Linkage</th><td>section=' . (int)$class->coursesectionid . ' | group=' . (int)$class->groupid . ' | attendancecm=' . (int)$class->attendancemoduleid . ' | bbbcmids=' . dsv_h((string)$class->bbbmoduleids) . '</td></tr>';
echo '<tr><th>Date range</th><td>' . dsv_ts((int)$class->initdate) . ' - ' . dsv_ts((int)$class->enddate) . '</td></tr>';
echo '<tr><th>Students</th><td>' . count($students) . ' in class scope</td></tr>';
echo '</tbody></table>';

echo html_writer::tag('h4', 'Virtual session mapping summary');
echo '<p>events included: <strong>' . (int)$virtmap['events_included'] . '</strong> / raw events: <strong>' . (int)$virtmap['events_total'] . '</strong></p>';
if (!empty($virtmap['warnings'])) {
    echo '<ul>';
    foreach ($virtmap['warnings'] as $w) {
        echo '<li><span class="warn">' . dsv_h($w) . '</span></li>';
    }
    echo '</ul>';
}
echo '<table class="dsv-table"><thead><tr><th>Event</th><th>Module</th><th>Start</th><th>End</th><th>Included</th><th>Reason</th></tr></thead><tbody>';
if (empty($virtmap['rows'])) {
    echo '<tr><td colspan="6"><span class="bad">No attendance/BBB events found</span></td></tr>';
} else {
    foreach ($virtmap['rows'] as $r) {
        echo '<tr>';
        echo '<td>#' . (int)$r->id . ' ' . dsv_h($r->name) . '</td>';
        echo '<td>' . dsv_h($r->modulename) . ' / ' . (int)$r->instance . '</td>';
        echo '<td>' . dsv_ts((int)$r->start) . '</td>';
        echo '<td>' . dsv_ts((int)$r->end) . '</td>';
        echo '<td>' . (!empty($r->included) ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>') . '</td>';
        echo '<td>' . dsv_h($r->reason) . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';

echo html_writer::tag('h4', 'Sample user activity visibility (same section scope as get_all_activities)');
echo '<p>sample user id: <strong>' . (int)$sample . '</strong></p>';
if (!empty($sampleacts['warnings'])) {
    echo '<ul>';
    foreach ($sampleacts['warnings'] as $w) {
        echo '<li><span class="warn">' . dsv_h($w) . '</span></li>';
    }
    echo '</ul>';
}
echo '<table class="dsv-table"><thead><tr><th>CM</th><th>Module</th><th>Name</th><th>Visible</th><th>General</th><th>Tags</th><th>Availability</th></tr></thead><tbody>';
if (empty($sampleacts['rows'])) {
    echo '<tr><td colspan="7"><span class="bad">No modules returned from class section</span></td></tr>';
} else {
    foreach ($sampleacts['rows'] as $r) {
        echo '<tr>';
        echo '<td>#' . (int)$r->cmid . '</td>';
        echo '<td>' . dsv_h($r->modname) . '</td>';
        echo '<td>' . dsv_h($r->name) . '</td>';
        echo '<td>' . (!empty($r->uservisible) ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>') . '</td>';
        echo '<td>' . (!empty($r->isgeneral) ? 'YES' : 'NO') . '</td>';
        echo '<td>' . dsv_h(!empty($r->tags) ? implode(', ', $r->tags) : '-') . '</td>';
        echo '<td>' . dsv_h($r->availability !== '' ? $r->availability : '-') . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';

echo html_writer::tag('h4', 'Student matrix');
echo '<table class="dsv-table"><thead><tr><th>Student</th><th>Sources</th><th>Status in class</th><th>Visible modules</th><th>Visible virtual</th><th>Visible tagged</th><th>Events total</th><th>Events virtual</th><th>Issue</th></tr></thead><tbody>';
if (empty($focus)) {
    echo '<tr><td colspan="9"><span class="bad">No students in focus list</span></td></tr>';
} else {
    foreach ($focus as $sid) {
        $st = $students[$sid] ?? null;
        if ($st) {
            $name = trim((string)$st->firstname . ' ' . (string)$st->lastname);
            $doc = trim((string)$st->documentnumber);
            if ($doc === '') {
                $doc = trim((string)$st->username);
            }
            if ($doc === '') {
                $doc = trim((string)$st->idnumber);
            }
            $sources = implode(' ', array_keys((array)$st->sources));
            $statuslabels = [];
            foreach (array_keys((array)$st->statuses) as $scode) {
                $statuslabels[] = ((int)$scode) . ':' . dsv_status_label((int)$scode);
            }
            $statusstr = empty($statuslabels) ? '-' : implode(' | ', $statuslabels);
        } else {
            $name = 'UID ' . (int)$sid;
            $doc = '-';
            $sources = '-';
            $statusstr = '-';
        }
        $acts = dsv_activities_for_user($class, (int)$sid);
        $vis = 0;
        $visvirt = 0;
        $vistag = 0;
        foreach ($acts['rows'] as $r) {
            if (empty($r->uservisible)) {
                continue;
            }
            $vis++;
            if ((string)$r->modname === 'attendance' || (string)$r->modname === 'bigbluebuttonbn') {
                $visvirt++;
            } else if (!empty($r->tags)) {
                $vistag++;
            }
        }
        $ev = dsv_student_event_counts((int)$sid, $class);
        $issues = [];
        if ($visvirt <= 0) {
            $issues[] = 'no_visible_virtual_modules';
        }
        if ($ev['virtual'] <= 0) {
            $issues[] = 'no_virtual_events';
        }
        echo '<tr>';
        echo '<td>' . dsv_h($name) . '<br><small>uid=' . (int)$sid . ' | id=' . dsv_h($doc) . '</small></td>';
        echo '<td>' . dsv_h($sources !== '' ? $sources : '-') . '</td>';
        echo '<td>' . dsv_h($statusstr) . '</td>';
        echo '<td>' . $vis . '</td>';
        echo '<td>' . $visvirt . '</td>';
        echo '<td>' . $vistag . '</td>';
        echo '<td>' . (int)$ev['total'] . '</td>';
        echo '<td>' . (int)$ev['virtual'] . '</td>';
        echo '<td>' . (empty($issues) ? '<span class="ok">OK</span>' : '<span class="bad">' . dsv_h(implode(', ', $issues)) . '</span>') . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';

echo $OUTPUT->footer();
