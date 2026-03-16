<?php
/**
 * Debug page for duplicated student schedule cards in LXP.
 *
 * This page mirrors the student schedule source used by horario.vue:
 * local_grupomakro_calendar_get_calendar_events -> get_class_events().
 *
 * It helps identify duplicated visual cards caused by stale references
 * (mainly duplicated status=2 rows in gmk_course_progre).
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_student_schedule_duplicates.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug student schedule duplicates');
$PAGE->set_heading('Debug student schedule duplicates');

$userid = optional_param('userid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$initdate = optional_param('initdate', date('Y-m-d', strtotime('-30 days')), PARAM_TEXT);
$enddate = optional_param('enddate', date('Y-m-d', strtotime('+120 days')), PARAM_TEXT);
$action = optional_param('action', '', PARAM_ALPHAEXT);
$rowid = optional_param('rowid', 0, PARAM_INT);
$classidaction = optional_param('classidaction', 0, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$classname = optional_param('classname', '', PARAM_TEXT);
$maxstudents = optional_param('maxstudents', 150, PARAM_INT);
$scanstudents = optional_param('scanstudents', 0, PARAM_INT);

function gmk_dbg_sd_h($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if ($value === true) {
        return '1';
    }
    if ($value === false) {
        return '0';
    }
    return s((string)$value);
}

function gmk_dbg_sd_print_table(array $headers, array $rows): void {
    echo '<table class="gmk-sd-table"><thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . s($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($headers) . '" class="muted">No rows</td></tr>';
    } else {
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($headers as $h) {
                echo '<td>' . (isset($r[$h]) ? (string)$r[$h] : '') . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
}

function gmk_dbg_sd_pick_status2_to_keep(array $rows, array $classmap, array $membergroupset): int {
    $bestid = 0;
    $bestscore = -1e18;
    foreach ($rows as $r) {
        $rid = (int)$r->id;
        $rclassid = (int)$r->classid;
        $rgroupid = (int)$r->groupid;
        $score = 0;

        if ($rclassid > 0 && isset($classmap[$rclassid])) {
            $class = $classmap[$rclassid];
            $score += 3000;
            if ((int)$class->closed === 0) {
                $score += 1000;
            }
            if ((int)$class->approved === 1) {
                $score += 500;
            }
            if ((int)$class->groupid > 0 && $rgroupid > 0 && (int)$class->groupid === $rgroupid) {
                $score += 300;
            }
            if ((int)$class->groupid > 0 && isset($membergroupset[(int)$class->groupid])) {
                $score += 200;
            }
        } else if ($rclassid > 0) {
            $score -= 500;
        }

        if ($rgroupid > 0 && isset($membergroupset[$rgroupid])) {
            $score += 150;
        }

        $score += (int)($r->timemodified ?? 0);
        $score += $rid;

        if ($score > $bestscore) {
            $bestscore = $score;
            $bestid = $rid;
        }
    }
    return $bestid;
}

function gmk_dbg_sd_front_name($event): string {
    $modulename = (string)($event->modulename ?? '');
    $issession = ($modulename === 'attendance' || $modulename === 'bigbluebuttonbn');
    $courselabel = trim((string)($event->coursename ?? ''));
    if ($courselabel === '') {
        $courselabel = trim((string)($event->className ?? ''));
    }
    if ($courselabel === '') {
        $courselabel = trim((string)($event->name ?? 'Curso'));
    }
    if ($issession) {
        return $courselabel;
    }
    $evname = trim((string)($event->name ?? ''));
    return $evname !== '' ? $evname : $courselabel;
}

function gmk_dbg_sd_dt($value, $fallbackts = 0): string {
    $v = trim((string)$value);
    if ($v !== '') {
        return substr(str_replace('T', ' ', $v), 0, 16);
    }
    if (!empty($fallbackts)) {
        return date('Y-m-d H:i', (int)$fallbackts);
    }
    return '';
}

function gmk_dbg_sd_norm($value): string {
    $v = trim((string)$value);
    if ($v === '') {
        return '';
    }
    if (function_exists('cleanString')) {
        return cleanString($v);
    }
    return strtolower($v);
}

function gmk_dbg_sd_dup_reason(array $items): string {
    $att = 0;
    $bbb = 0;
    $other = 0;
    $classids = [];

    foreach ($items as $it) {
        $mod = (string)($it->modulename ?? '');
        if ($mod === 'attendance') {
            $att++;
        } else if ($mod === 'bigbluebuttonbn') {
            $bbb++;
        } else {
            $other++;
        }
        $classids[] = !empty($it->classId) ? (int)$it->classId : 0;
    }

    $uniqueclassids = array_values(array_unique($classids));
    if ($att > 1) {
        return 'duplicate_attendance_events';
    }
    if ($bbb > 1) {
        return 'duplicate_bbb_events';
    }
    if ($att > 0 && $bbb > 0) {
        if (in_array(0, $uniqueclassids, true)) {
            return 'mixed_att_bbb_unresolved_classid';
        }
        if (count($uniqueclassids) > 1) {
            return 'mixed_att_bbb_mismatched_classid';
        }
        return 'mixed_att_bbb_not_filtered';
    }
    if ($other > 1) {
        return 'duplicate_generic_module_events';
    }
    return 'duplicate_unknown';
}

function gmk_dbg_sd_dup_values_text(array $values): string {
    $countmap = [];
    foreach ($values as $v) {
        $k = (string)$v;
        if ($k === '' || $k === '0') {
            continue;
        }
        if (!isset($countmap[$k])) {
            $countmap[$k] = 0;
        }
        $countmap[$k]++;
    }
    $out = [];
    foreach ($countmap as $k => $cnt) {
        if ($cnt > 1) {
            $out[] = $k . 'x' . $cnt;
        }
    }
    return empty($out) ? '-' : implode(', ', $out);
}

function gmk_dbg_sd_table_exists(string $tablename): bool {
    global $DB;
    try {
        $cols = $DB->get_columns($tablename);
        return is_array($cols) && !empty($cols);
    } catch (Throwable $t) {
        return false;
    }
}

function gmk_dbg_sd_period_sql_parts(): array {
    if (gmk_dbg_sd_table_exists('gmk_academic_periods')) {
        return [
            'select' => 'p.name AS periodname',
            'join' => 'LEFT JOIN {gmk_academic_periods} p ON p.id = c.periodid',
        ];
    }
    if (gmk_dbg_sd_table_exists('local_learning_periods')) {
        return [
            'select' => 'p.name AS periodname',
            'join' => 'LEFT JOIN {local_learning_periods} p ON p.id = c.periodid',
        ];
    }

    return [
        'select' => "'' AS periodname",
        'join' => '',
    ];
}

$notices = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userid > 0 && $action !== '') {
    require_sesskey();
    global $DB, $USER;

    if ($action === 'demote_row' && $rowid > 0) {
        $row = $DB->get_record('gmk_course_progre', ['id' => (int)$rowid, 'userid' => (int)$userid], '*', IGNORE_MISSING);
        if (!$row) {
            $errors[] = "Row not found: id={$rowid}.";
        } else if ((int)$row->status !== 2) {
            $errors[] = "Row {$rowid} is not status=2.";
        } else {
            $DB->execute(
                "UPDATE {gmk_course_progre}
                    SET status = 1,
                        classid = 0,
                        groupid = 0,
                        timemodified = :now
                  WHERE id = :id",
                ['now' => time(), 'id' => (int)$rowid]
            );
            $notices[] = "Row {$rowid} moved from status=2 to status=1.";
        }
    } else if ($action === 'demote_classid' && $classidaction > 0) {
        $rows = $DB->get_records('gmk_course_progre', ['userid' => (int)$userid, 'classid' => (int)$classidaction], '', 'id,status');
        $updated = 0;
        foreach ($rows as $r) {
            if ((int)$r->status !== 2) {
                continue;
            }
            $DB->execute(
                "UPDATE {gmk_course_progre}
                    SET status = 1,
                        classid = 0,
                        groupid = 0,
                        timemodified = :now
                  WHERE id = :id",
                ['now' => time(), 'id' => (int)$r->id]
            );
            $updated++;
        }
        $notices[] = "Class {$classidaction}: demoted {$updated} status=2 rows.";
    } else if ($action === 'autofix_status2_dupes') {
        $status2 = $DB->get_records_sql(
            "SELECT id, userid, learningplanid, courseid, classid, groupid, status, timemodified
               FROM {gmk_course_progre}
              WHERE userid = :userid
                AND status = 2
           ORDER BY learningplanid ASC, courseid ASC, timemodified DESC, id DESC",
            ['userid' => (int)$userid]
        );

        $groups = [];
        $classids = [];
        foreach ($status2 as $r) {
            $key = (int)$r->learningplanid . '|' . (int)$r->courseid;
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $r;
            if (!empty($r->classid)) {
                $classids[(int)$r->classid] = (int)$r->classid;
            }
        }

        $classmap = [];
        if (!empty($classids)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_values($classids), SQL_PARAMS_NAMED, 'cid');
            $classmap = $DB->get_records_sql(
                "SELECT id, groupid, approved, closed
                   FROM {gmk_class}
                  WHERE id {$insql}",
                $inparams
            );
        }

        $membergroupset = [];
        $gms = $DB->get_records('groups_members', ['userid' => (int)$userid], '', 'groupid');
        foreach ($gms as $gm) {
            if (!empty($gm->groupid)) {
                $membergroupset[(int)$gm->groupid] = true;
            }
        }

        $demoted = 0;
        $dedupgroups = 0;
        foreach ($groups as $key => $rows) {
            if (count($rows) <= 1) {
                continue;
            }
            $dedupgroups++;
            $keepid = gmk_dbg_sd_pick_status2_to_keep($rows, $classmap, $membergroupset);
            foreach ($rows as $r) {
                if ((int)$r->id === $keepid) {
                    continue;
                }
                $DB->execute(
                    "UPDATE {gmk_course_progre}
                        SET status = 1,
                            classid = 0,
                            groupid = 0,
                            timemodified = :now
                      WHERE id = :id",
                    ['now' => time(), 'id' => (int)$r->id]
                );
                $demoted++;
            }
        }
        $notices[] = "Auto fix completed. duplicate_groups={$dedupgroups}, demoted_rows={$demoted}.";
    } else if ($action === 'rebuild_class_activities' && $classidaction > 0) {
        $class = $DB->get_record('gmk_class', ['id' => (int)$classidaction], '*', IGNORE_MISSING);
        if (!$class) {
            $errors[] = "Class not found: id={$classidaction}.";
        } else {
            try {
                // Full cleanup + recreate for this class to remove duplicated BBB rows/events.
                create_class_activities($class, true);
                $notices[] = "Class {$classidaction}: activities rebuilt successfully (attendance + BBB).";
            } catch (Throwable $re) {
                $errors[] = "Class {$classidaction}: rebuild failed - " . $re->getMessage();
            }
        }
    }
}

echo $OUTPUT->header();

echo '<style>
    .gmk-sd-wrap { max-width: 1800px; margin: 16px auto; }
    .gmk-sd-card { background: #f8f9fa; border-left: 4px solid #2c7be5; padding: 12px; margin: 12px 0; }
    .gmk-sd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .gmk-sd-table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 10px 0; background: #fff; }
    .gmk-sd-table th { background: #212529; color: #fff; text-align: left; padding: 8px; border: 1px solid #495057; }
    .gmk-sd-table td { padding: 7px; border: 1px solid #dee2e6; vertical-align: top; }
    .muted { color: #6c757d; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .ok { background: #198754; color: #fff; }
    .bad { background: #dc3545; color: #fff; }
    .warn { background: #ffc107; color: #111; }
</style>';

echo '<div class="gmk-sd-wrap">';
echo '<h2>Debug student schedule duplicated cards</h2>';
echo '<div class="gmk-sd-card">';
echo 'This page mirrors student schedule source from get_class_events() and front transformation in horario.vue.';
echo '</div>';

if (!empty($notices)) {
    echo '<div class="gmk-sd-card">';
    foreach ($notices as $n) {
        echo '<div><span class="badge ok">OK</span> ' . s($n) . '</div>';
    }
    echo '</div>';
}
if (!empty($errors)) {
    echo '<div class="gmk-sd-card">';
    foreach ($errors as $e) {
        echo '<div><span class="badge bad">ERR</span> ' . s($e) . '</div>';
    }
    echo '</div>';
}

echo '<form method="get" class="gmk-sd-card">';
echo '<div class="gmk-sd-grid">';
echo '<div><label><strong>Search student (name/email)</strong></label><br>';
echo '<input type="text" name="search" value="' . gmk_dbg_sd_h($search) . '" style="width:100%;" /></div>';
echo '<div><label><strong>User ID</strong></label><br>';
echo '<input type="number" name="userid" value="' . (int)$userid . '" style="width:100%;max-width:260px;" /></div>';
echo '</div>';
echo '<div class="gmk-sd-grid" style="margin-top:10px;">';
echo '<div><label><strong>Init date (YYYY-MM-DD)</strong></label><br>';
echo '<input type="text" name="initdate" value="' . gmk_dbg_sd_h($initdate) . '" style="width:100%;max-width:260px;" /></div>';
echo '<div><label><strong>End date (YYYY-MM-DD)</strong></label><br>';
echo '<input type="text" name="enddate" value="' . gmk_dbg_sd_h($enddate) . '" style="width:100%;max-width:260px;" /></div>';
echo '</div>';
echo '<div class="gmk-sd-grid" style="margin-top:10px;">';
echo '<div><label><strong>Class ID (optional)</strong></label><br>';
echo '<input type="number" name="classid" value="' . (int)$classid . '" style="width:100%;max-width:260px;" /></div>';
echo '<div><label><strong>Class name contains (optional)</strong></label><br>';
echo '<input type="text" name="classname" value="' . gmk_dbg_sd_h($classname) . '" style="width:100%;" /></div>';
echo '</div>';
echo '<div style="margin-top:10px;"><label><strong>Max class students to scan</strong></label><br>';
echo '<input type="number" min="10" max="5000" name="maxstudents" value="' . (int)$maxstudents . '" style="width:100%;max-width:260px;" /></div>';
echo '<div style="margin-top:10px;"><label><input type="checkbox" name="scanstudents" value="1" ' . (!empty($scanstudents) ? 'checked' : '') . '> Run heavy class student scan</label></div>';
echo '<div style="margin-top:10px;"><button type="submit" class="btn btn-primary">Diagnose</button></div>';
echo '</form>';

global $DB;

$searchsql = '';
$searchparams = [];
if (trim($search) !== '') {
    $like = '%' . $DB->sql_like_escape(trim($search)) . '%';
    $searchsql = " AND (
        " . $DB->sql_like('u.firstname', ':s1', false, false) . "
        OR " . $DB->sql_like('u.lastname', ':s2', false, false) . "
        OR " . $DB->sql_like('u.email', ':s3', false, false) . "
    )";
    $searchparams = ['s1' => $like, 's2' => $like, 's3' => $like];
}

$candidates = $DB->get_records_sql(
    "SELECT u.id,
            u.firstname,
            u.lastname,
            u.email,
            COUNT(*) AS status2_rows,
            COUNT(DISTINCT CONCAT(cp.learningplanid, '-', cp.courseid)) AS status2_unique_keys
       FROM {gmk_course_progre} cp
       JOIN {user} u ON u.id = cp.userid
      WHERE cp.status = 2
        AND u.deleted = 0 {$searchsql}
   GROUP BY u.id, u.firstname, u.lastname, u.email
   ORDER BY status2_rows DESC, u.lastname ASC, u.firstname ASC
      LIMIT 200",
    $searchparams
);

$candrows = [];
foreach ($candidates as $c) {
    $isdup = ((int)$c->status2_rows > (int)$c->status2_unique_keys);
    $url = new moodle_url('/local/grupomakro_core/pages/debug_student_schedule_duplicates.php', [
        'userid' => (int)$c->id,
        'search' => $search,
        'initdate' => $initdate,
        'enddate' => $enddate,
        'classid' => (int)$classid,
        'classname' => $classname,
        'maxstudents' => (int)$maxstudents,
        'scanstudents' => (int)$scanstudents
    ]);
    $candrows[] = [
        'User ID' => (int)$c->id,
        'Name' => s(trim($c->firstname . ' ' . $c->lastname)),
        'Email' => s($c->email),
        'status2_rows' => (int)$c->status2_rows,
        'status2_unique_keys' => (int)$c->status2_unique_keys,
        'Potential stale refs' => $isdup ? '<span class="badge bad">YES</span>' : '<span class="badge ok">NO</span>',
        'Action' => '<a href="' . $url->out(false) . '">Diagnose</a>',
    ];
}

echo '<div class="gmk-sd-card"><strong>Student candidates</strong> <span class="muted">(status=2 consistency pre-check)</span></div>';
gmk_dbg_sd_print_table(
    ['User ID', 'Name', 'Email', 'status2_rows', 'status2_unique_keys', 'Potential stale refs', 'Action'],
    $candrows
);

$hasselecteduser = ($userid > 0);
if (!$hasselecteduser) {
    echo '<div class="gmk-sd-card"><span class="badge warn">INFO</span> No user selected. User-level sections are skipped. Class-focused diagnosis still works.</div>';
}

if ($hasselecteduser) {
    $user = $DB->get_record('user', ['id' => (int)$userid, 'deleted' => 0], 'id,firstname,lastname,email,idnumber', IGNORE_MISSING);
    if (!$user) {
        echo '<div class="gmk-sd-card"><span class="badge bad">ERR</span> User not found.</div>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    echo '<div class="gmk-sd-card">';
    echo '<strong>Selected student:</strong> ' . s(trim($user->firstname . ' ' . $user->lastname));
    echo ' | uid=' . (int)$user->id;
    echo ' | idnumber=' . s((string)$user->idnumber);
    echo ' | ' . s((string)$user->email);
    echo '<br><strong>Date range:</strong> ' . s($initdate) . ' to ' . s($enddate);
    echo '</div>';

    $progre = $DB->get_records_sql(
        "SELECT cp.id, cp.userid, cp.learningplanid, cp.periodid, cp.courseid, cp.classid, cp.groupid,
                cp.status, cp.progress, cp.grade, cp.timemodified,
                c.fullname AS coursename,
                lp.name AS planname,
                gc.name AS classname,
                gc.groupid AS classgroupid,
                gc.approved AS classapproved,
                gc.closed AS classclosed
           FROM {gmk_course_progre} cp
      LEFT JOIN {course} c ON c.id = cp.courseid
      LEFT JOIN {local_learning_plans} lp ON lp.id = cp.learningplanid
      LEFT JOIN {gmk_class} gc ON gc.id = cp.classid
          WHERE cp.userid = :userid
       ORDER BY cp.learningplanid ASC, cp.courseid ASC, cp.status DESC, cp.timemodified DESC, cp.id DESC",
        ['userid' => (int)$userid]
    );

    $status2rows = [];
    $status2groups = [];
    $classids = [];
    foreach ($progre as $p) {
        if ((int)$p->status === 2) {
            $status2rows[] = $p;
            $key = (int)$p->learningplanid . '|' . (int)$p->courseid;
            if (!isset($status2groups[$key])) {
                $status2groups[$key] = [];
            }
            $status2groups[$key][] = $p;
        }
        if (!empty($p->classid)) {
            $classids[(int)$p->classid] = (int)$p->classid;
        }
    }

    $classmap = [];
    if (!empty($classids)) {
        list($insqlc, $paramsc) = $DB->get_in_or_equal(array_values($classids), SQL_PARAMS_NAMED, 'classid');
        $classmap = $DB->get_records_sql(
            "SELECT id, groupid, approved, closed
               FROM {gmk_class}
              WHERE id {$insqlc}",
            $paramsc
        );
    }

    $membergroupset = [];
    $gms = $DB->get_records('groups_members', ['userid' => (int)$userid], '', 'groupid');
    foreach ($gms as $gm) {
        if (!empty($gm->groupid)) {
            $membergroupset[(int)$gm->groupid] = true;
        }
    }

    $keeprowbykey = [];
    foreach ($status2groups as $k => $rows) {
        if (count($rows) > 1) {
            $keeprowbykey[$k] = gmk_dbg_sd_pick_status2_to_keep($rows, $classmap, $membergroupset);
        }
    }

    if (!empty($status2rows)) {
        echo '<div class="gmk-sd-card"><strong>status=2 references (course progress)</strong></div>';
        echo '<form method="post" class="gmk-sd-card" onsubmit="return confirm(\'Apply auto-fix for duplicate status=2 refs?\');">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<input type="hidden" name="userid" value="' . (int)$userid . '">';
        echo '<input type="hidden" name="search" value="' . gmk_dbg_sd_h($search) . '">';
        echo '<input type="hidden" name="initdate" value="' . gmk_dbg_sd_h($initdate) . '">';
        echo '<input type="hidden" name="enddate" value="' . gmk_dbg_sd_h($enddate) . '">';
    echo '<input type="hidden" name="classid" value="' . (int)$classid . '">';
    echo '<input type="hidden" name="classname" value="' . gmk_dbg_sd_h($classname) . '">';
    echo '<input type="hidden" name="maxstudents" value="' . (int)$maxstudents . '">';
    echo '<input type="hidden" name="scanstudents" value="' . (int)$scanstudents . '">';
    echo '<input type="hidden" name="action" value="autofix_status2_dupes">';
        echo '<button type="submit" class="btn btn-primary">Auto-fix duplicate status=2 refs</button>';
        echo '</form>';

        $rows = [];
        foreach ($status2rows as $p) {
            $key = (int)$p->learningplanid . '|' . (int)$p->courseid;
            $isdupgroup = isset($status2groups[$key]) && count($status2groups[$key]) > 1;
            $keepid = $keeprowbykey[$key] ?? 0;
            $classok = ((int)$p->classid > 0 && isset($classmap[(int)$p->classid]));
            $classstate = '-';
            if ($classok) {
                $classstate = 'approved=' . (int)$classmap[(int)$p->classid]->approved . ', closed=' . (int)$classmap[(int)$p->classid]->closed;
            }
            $ingroup = (!empty($p->groupid) && isset($membergroupset[(int)$p->groupid])) ? 'YES' : 'NO';

            $demoteform = '';
            if (!$isdupgroup || ((int)$p->id !== (int)$keepid) || !$classok) {
                $demoteform = '<form method="post" style="display:inline;" onsubmit="return confirm(\'Move this row to status=1?\');">'
                    . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                    . '<input type="hidden" name="userid" value="' . (int)$userid . '">'
                    . '<input type="hidden" name="search" value="' . gmk_dbg_sd_h($search) . '">'
                    . '<input type="hidden" name="initdate" value="' . gmk_dbg_sd_h($initdate) . '">'
                    . '<input type="hidden" name="enddate" value="' . gmk_dbg_sd_h($enddate) . '">'
                    . '<input type="hidden" name="classid" value="' . (int)$classid . '">'
                    . '<input type="hidden" name="classname" value="' . gmk_dbg_sd_h($classname) . '">'
                    . '<input type="hidden" name="maxstudents" value="' . (int)$maxstudents . '">'
                    . '<input type="hidden" name="scanstudents" value="' . (int)$scanstudents . '">'
                    . '<input type="hidden" name="action" value="demote_row">'
                    . '<input type="hidden" name="rowid" value="' . (int)$p->id . '">'
                    . '<button type="submit" class="btn btn-secondary btn-sm">Demote row</button></form>';
            }

            $rows[] = [
                'cp.id' => (int)$p->id,
                'Plan/Course' => (int)$p->learningplanid . ' / ' . (int)$p->courseid,
                'Plan name' => s((string)$p->planname),
                'Course name' => s((string)$p->coursename),
                'classid/groupid' => (int)$p->classid . ' / ' . (int)$p->groupid,
                'Class state' => s($classstate),
                'In group members' => $ingroup,
                'Duplicate key?' => $isdupgroup ? '<span class="badge bad">YES</span>' : '<span class="badge ok">NO</span>',
                'Keep?' => ($isdupgroup ? ((int)$p->id === (int)$keepid ? '<span class="badge ok">KEEP</span>' : '<span class="badge bad">DROP</span>') : '-'),
                'Action' => $demoteform,
            ];
        }
        gmk_dbg_sd_print_table(
            ['cp.id', 'Plan/Course', 'Plan name', 'Course name', 'classid/groupid', 'Class state', 'In group members', 'Duplicate key?', 'Keep?', 'Action'],
            $rows
        );
    }
}

$maxstudents = max(10, min(5000, (int)$maxstudents));
$targetclass = null;
$classcandidates = [];
$periodsqlparts = gmk_dbg_sd_period_sql_parts();
$periodselect = $periodsqlparts['select'];
$periodjoin = $periodsqlparts['join'];

if ($classid > 0) {
    $targetclass = $DB->get_record_sql(
        "SELECT c.id, c.name, c.periodid, c.corecourseid, c.learningplanid, c.shift, c.groupid, c.approved, c.closed, c.attendancemoduleid,
                {$periodselect}, lp.name AS planname, crs.fullname AS corecoursename
           FROM {gmk_class} c
      {$periodjoin}
      LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
      LEFT JOIN {course} crs ON crs.id = c.corecourseid
          WHERE c.id = :id",
        ['id' => (int)$classid]
    );
}

if (!$targetclass && trim($classname) !== '') {
    $clike = '%' . $DB->sql_like_escape(trim($classname)) . '%';
    $classcandidates = $DB->get_records_sql(
        "SELECT c.id, c.name, c.periodid, c.corecourseid, c.learningplanid, c.shift, c.groupid, c.approved, c.closed,
                {$periodselect}, lp.name AS planname, crs.fullname AS corecoursename
           FROM {gmk_class} c
      {$periodjoin}
      LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
      LEFT JOIN {course} crs ON crs.id = c.corecourseid
          WHERE " . $DB->sql_like('c.name', ':cl1', false, false) . "
             OR " . $DB->sql_like('crs.fullname', ':cl2', false, false) . "
       ORDER BY c.closed ASC, c.approved DESC, c.id DESC
          LIMIT 120",
        ['cl1' => $clike, 'cl2' => $clike]
    );
    if (count($classcandidates) === 1) {
        $targetclass = reset($classcandidates);
    }
}

if (!empty($classcandidates)) {
    $classcandrows = [];
    foreach ($classcandidates as $cc) {
        $pickurl = new moodle_url('/local/grupomakro_core/pages/debug_student_schedule_duplicates.php', [
            'userid' => (int)$userid,
            'search' => $search,
            'initdate' => $initdate,
            'enddate' => $enddate,
            'classid' => (int)$cc->id,
            'classname' => $classname,
            'maxstudents' => (int)$maxstudents,
            'scanstudents' => (int)$scanstudents
        ]);
        $classcandrows[] = [
            'Class ID' => (int)$cc->id,
            'Class name' => s((string)$cc->name),
            'Core course' => (int)$cc->corecourseid . ' - ' . s((string)($cc->corecoursename ?? '')),
            'Plan' => (int)$cc->learningplanid . ' - ' . s((string)($cc->planname ?? '')),
            'Period' => (int)$cc->periodid . ' - ' . s((string)($cc->periodname ?? '')),
            'State' => 'approved=' . (int)$cc->approved . ', closed=' . (int)$cc->closed,
            'Action' => '<a href="' . $pickurl->out(false) . '">Use</a>',
        ];
    }
    echo '<div class="gmk-sd-card"><strong>Class matches for class name filter</strong></div>';
    gmk_dbg_sd_print_table(
        ['Class ID', 'Class name', 'Core course', 'Plan', 'Period', 'State', 'Action'],
        $classcandrows
    );
}

if ($targetclass) {
    $targetclassid = (int)$targetclass->id;
    $targetcoursename = trim((string)($targetclass->corecoursename ?? ''));
    if ($targetcoursename === '') {
        $targetcoursename = trim((string)$targetclass->name);
    }
    $targetcoursenorm = gmk_dbg_sd_norm($targetcoursename);

    echo '<div class="gmk-sd-card"><strong>Class-focused diagnosis</strong><br>'
        . 'Class ID=' . (int)$targetclass->id
        . ' | Name=' . s((string)$targetclass->name)
        . ' | Core course=' . (int)$targetclass->corecourseid . ' - ' . s((string)$targetcoursename)
        . ' | Plan=' . (int)$targetclass->learningplanid . ' - ' . s((string)($targetclass->planname ?? ''))
        . ' | Period=' . (int)$targetclass->periodid . ' - ' . s((string)($targetclass->periodname ?? ''))
        . ' | Group=' . (int)$targetclass->groupid
        . ' | approved/closed=' . (int)$targetclass->approved . '/' . (int)$targetclass->closed
        . '</div>';

    echo '<form method="post" class="gmk-sd-card" onsubmit="return confirm(\'Rebuild activities for this class? This will recreate attendance and BBB sessions.\');">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="userid" value="' . (int)$userid . '">';
    echo '<input type="hidden" name="search" value="' . gmk_dbg_sd_h($search) . '">';
    echo '<input type="hidden" name="initdate" value="' . gmk_dbg_sd_h($initdate) . '">';
    echo '<input type="hidden" name="enddate" value="' . gmk_dbg_sd_h($enddate) . '">';
    echo '<input type="hidden" name="classid" value="' . (int)$targetclass->id . '">';
    echo '<input type="hidden" name="classidaction" value="' . (int)$targetclass->id . '">';
    echo '<input type="hidden" name="classname" value="' . gmk_dbg_sd_h($classname) . '">';
    echo '<input type="hidden" name="maxstudents" value="' . (int)$maxstudents . '">';
    echo '<input type="hidden" name="scanstudents" value="' . (int)$scanstudents . '">';
    echo '<input type="hidden" name="action" value="rebuild_class_activities">';
    echo '<button type="submit" class="btn btn-danger">Rebuild class activities</button>';
    echo '</form>';

    $relrows = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => $targetclassid], 'id ASC',
        'id,attendanceid,attendancesessionid,attendancemoduleid,bbbmoduleid,bbbid,classid');
    $reltable = [];
    $relsessionids = [];
    $relbbbids = [];
    $relattids = [];
    $relattcmids = [];
    foreach ($relrows as $rr) {
        $reltable[] = [
            'id' => (int)$rr->id,
            'attendanceid' => (int)$rr->attendanceid,
            'attendancesessionid' => (int)$rr->attendancesessionid,
            'attendancemoduleid' => (int)$rr->attendancemoduleid,
            'bbbmoduleid' => (int)$rr->bbbmoduleid,
            'bbbid' => (int)$rr->bbbid,
        ];
        if (!empty($rr->attendancesessionid)) {
            $relsessionids[(int)$rr->attendancesessionid] = (int)$rr->attendancesessionid;
        }
        if (!empty($rr->bbbid)) {
            $relbbbids[(int)$rr->bbbid] = (int)$rr->bbbid;
        }
        if (!empty($rr->attendanceid)) {
            $relattids[(int)$rr->attendanceid] = (int)$rr->attendanceid;
        }
        if (!empty($rr->attendancemoduleid)) {
            $relattcmids[(int)$rr->attendancemoduleid] = (int)$rr->attendancemoduleid;
        }
    }

    $relationsummary = [[
        'relation_rows' => count($relrows),
        'dup_by_sessionid' => gmk_dbg_sd_dup_values_text(array_map(static function($x) { return (int)$x->attendancesessionid; }, $relrows)),
        'dup_by_bbbid' => gmk_dbg_sd_dup_values_text(array_map(static function($x) { return (int)$x->bbbid; }, $relrows)),
        'dup_by_bbbmoduleid' => gmk_dbg_sd_dup_values_text(array_map(static function($x) { return (int)$x->bbbmoduleid; }, $relrows)),
        'dup_by_attcmid' => gmk_dbg_sd_dup_values_text(array_map(static function($x) { return (int)$x->attendancemoduleid; }, $relrows)),
    ]];
    echo '<div class="gmk-sd-card"><strong>Class relation consistency</strong></div>';
    gmk_dbg_sd_print_table(
        ['relation_rows', 'dup_by_sessionid', 'dup_by_bbbid', 'dup_by_bbbmoduleid', 'dup_by_attcmid'],
        $relationsummary
    );
    gmk_dbg_sd_print_table(
        ['id', 'attendanceid', 'attendancesessionid', 'attendancemoduleid', 'bbbmoduleid', 'bbbid'],
        $reltable
    );

    $attendanceinstanceids = $relattids;
    if (empty($attendanceinstanceids) && !empty($targetclass->attendancemoduleid)) {
        $attinstance = $DB->get_field('course_modules', 'instance', ['id' => (int)$targetclass->attendancemoduleid], IGNORE_MISSING);
        if (!empty($attinstance)) {
            $attendanceinstanceids[(int)$attinstance] = (int)$attinstance;
        }
    }

    $attsessions = [];
    if (!empty($relsessionids)) {
        $attsessions = $DB->get_records_list('attendance_sessions', 'id', array_values($relsessionids), 'id ASC',
            'id,attendanceid,groupid,sessdate,duration,caleventid');
    } else if (!empty($attendanceinstanceids)) {
        $attsessions = $DB->get_records_list('attendance_sessions', 'attendanceid', array_values($attendanceinstanceids), 'id ASC',
            'id,attendanceid,groupid,sessdate,duration,caleventid');
    }

    $attsessionrows = [];
    $attcalids = [];
    foreach ($attsessions as $as) {
        if (!empty($as->caleventid)) {
            $attcalids[(int)$as->caleventid] = (int)$as->caleventid;
        }
        $attsessionrows[] = [
            'sessionid' => (int)$as->id,
            'attendanceid' => (int)$as->attendanceid,
            'groupid' => (int)$as->groupid,
            'sessdate' => userdate((int)$as->sessdate, '%Y-%m-%d %H:%M'),
            'duration' => (int)$as->duration,
            'caleventid' => (int)$as->caleventid,
        ];
    }
    echo '<div class="gmk-sd-card"><strong>Attendance sessions linked to class</strong></div>';
    gmk_dbg_sd_print_table(
        ['sessionid', 'attendanceid', 'groupid', 'sessdate', 'duration', 'caleventid'],
        $attsessionrows
    );

    $atteventrows = [];
    if (!empty($attcalids)) {
        $attevents = $DB->get_records_list('event', 'id', array_values($attcalids), 'id ASC',
            'id,courseid,groupid,modulename,instance,timestart,timeduration,name');
        foreach ($attevents as $ev) {
            $atteventrows[] = [
                'eventid' => (int)$ev->id,
                'module' => s((string)$ev->modulename),
                'instance' => (int)$ev->instance,
                'groupid' => (int)$ev->groupid,
                'start' => userdate((int)$ev->timestart, '%Y-%m-%d %H:%M'),
                'duration' => (int)$ev->timeduration,
                'name' => s((string)$ev->name),
            ];
        }
    }
    echo '<div class="gmk-sd-card"><strong>Attendance calendar events referenced by sessions</strong></div>';
    gmk_dbg_sd_print_table(
        ['eventid', 'module', 'instance', 'groupid', 'start', 'duration', 'name'],
        $atteventrows
    );

    $bbbeventrows = [];
    if (!empty($relbbbids)) {
        list($bbbinsql, $bbbinparams) = $DB->get_in_or_equal(array_values($relbbbids), SQL_PARAMS_NAMED, 'bbbi');
        $bbbinparams['bbbmodname'] = 'bigbluebuttonbn';
        $bbbevents = $DB->get_records_sql(
            "SELECT id, courseid, groupid, modulename, instance, timestart, timeduration, name
               FROM {event}
              WHERE modulename = :bbbmodname
                AND instance {$bbbinsql}
           ORDER BY timestart ASC, id ASC",
            $bbbinparams
        );
        foreach ($bbbevents as $ev) {
            $bbbeventrows[] = [
                'eventid' => (int)$ev->id,
                'module' => s((string)$ev->modulename),
                'instance' => (int)$ev->instance,
                'groupid' => (int)$ev->groupid,
                'start' => userdate((int)$ev->timestart, '%Y-%m-%d %H:%M'),
                'duration' => (int)$ev->timeduration,
                'name' => s((string)$ev->name),
            ];
        }
    }
    echo '<div class="gmk-sd-card"><strong>BBB calendar events referenced by relation rows</strong></div>';
    gmk_dbg_sd_print_table(
        ['eventid', 'module', 'instance', 'groupid', 'start', 'duration', 'name'],
        $bbbeventrows
    );

    if ((int)$scanstudents === 1) {
        $groupmembers = [];
        if (!empty($targetclass->groupid)) {
            $groupmembers = array_values($DB->get_records_sql(
                "SELECT u.id, u.firstname, u.lastname, u.email, u.idnumber
                   FROM {groups_members} gm
                   JOIN {user} u ON u.id = gm.userid
                  WHERE gm.groupid = :gid
                    AND u.deleted = 0
               ORDER BY u.lastname ASC, u.firstname ASC",
                ['gid' => (int)$targetclass->groupid]
            ));
        }
        $groupmembertotal = count($groupmembers);
        if ($groupmembertotal > $maxstudents) {
            $groupmembers = array_slice($groupmembers, 0, $maxstudents);
        }

        $affectedstudents = [];
        $dupdetailrows = [];
        $reasoncount = [];
        foreach ($groupmembers as $gmuser) {
            $stuevents = get_class_events((int)$gmuser->id, $initdate, $enddate);
            $perstudentmap = [];

            foreach ($stuevents as $sev) {
                $sevclassid = !empty($sev->classId) ? (int)$sev->classId : 0;
                $sevfrontname = gmk_dbg_sd_front_name($sev);
                $sevfrontnorm = gmk_dbg_sd_norm($sevfrontname);
                $istarget = false;

                if ($sevclassid === $targetclassid) {
                    $istarget = true;
                } else if ((int)($sev->courseid ?? 0) === (int)$targetclass->corecourseid && $sevfrontnorm === $targetcoursenorm) {
                    $istarget = true;
                }

                if (!$istarget) {
                    continue;
                }

                $sstart = gmk_dbg_sd_dt($sev->start ?? '', $sev->timestart ?? 0);
                $send = gmk_dbg_sd_dt($sev->end ?? '', (!empty($sev->timestart) && !empty($sev->timeduration))
                    ? ((int)$sev->timestart + (int)$sev->timeduration) : 0);
                $skey = $sevfrontnorm . '|' . $sstart . '|' . $send;

                if (!isset($perstudentmap[$skey])) {
                    $perstudentmap[$skey] = [];
                }
                $perstudentmap[$skey][] = $sev;
            }

            $studentdups = 0;
            foreach ($perstudentmap as $skey => $items) {
                if (count($items) <= 1) {
                    continue;
                }
                $studentdups++;
                $sample = reset($items);
                $reason = gmk_dbg_sd_dup_reason($items);
                if (!isset($reasoncount[$reason])) {
                    $reasoncount[$reason] = 0;
                }
                $reasoncount[$reason]++;
                $modnames = [];
                $classidsmix = [];
                $eventidsmix = [];
                foreach ($items as $it) {
                    $modnames[] = (string)($it->modulename ?? '');
                    $classidsmix[] = (int)($it->classId ?? 0);
                    $eventidsmix[] = (int)($it->id ?? 0);
                }
                $dupdetailrows[] = [
                    'Student' => s(trim((string)$gmuser->firstname . ' ' . (string)$gmuser->lastname))
                        . ' (uid=' . (int)$gmuser->id . ')',
                    'Front name' => s(gmk_dbg_sd_front_name($sample)),
                    'Start' => s(gmk_dbg_sd_dt($sample->start ?? '', $sample->timestart ?? 0)),
                    'End' => s(gmk_dbg_sd_dt($sample->end ?? '', (!empty($sample->timestart) && !empty($sample->timeduration))
                        ? ((int)$sample->timestart + (int)$sample->timeduration) : 0)),
                    'Count' => count($items),
                    'Modules' => s(implode(', ', array_unique($modnames))),
                    'Class IDs' => s(implode(', ', array_unique($classidsmix))),
                    'Event IDs' => s(implode(', ', $eventidsmix)),
                    'Reason' => s($reason),
                ];
            }

            if ($studentdups > 0) {
                $affectedstudents[] = [
                    'User ID' => (int)$gmuser->id,
                    'Student' => s(trim((string)$gmuser->firstname . ' ' . (string)$gmuser->lastname)),
                    'ID Number' => s((string)$gmuser->idnumber),
                    'Duplicate groups' => $studentdups,
                ];
            }
        }

        $summaryrows = [[
            'group_members_total' => $groupmembertotal,
            'group_members_scanned' => count($groupmembers),
            'affected_students' => count($affectedstudents),
            'duplicate_groups' => count($dupdetailrows),
            'reason_breakdown' => empty($reasoncount) ? '-' : s(implode(' | ', array_map(static function($k, $v) {
                return $k . ':' . $v;
            }, array_keys($reasoncount), $reasoncount))),
        ]];
        echo '<div class="gmk-sd-card"><strong>Student impact for selected class</strong></div>';
        gmk_dbg_sd_print_table(
            ['group_members_total', 'group_members_scanned', 'affected_students', 'duplicate_groups', 'reason_breakdown'],
            $summaryrows
        );
        gmk_dbg_sd_print_table(
            ['User ID', 'Student', 'ID Number', 'Duplicate groups'],
            $affectedstudents
        );
        gmk_dbg_sd_print_table(
            ['Student', 'Front name', 'Start', 'End', 'Count', 'Modules', 'Class IDs', 'Event IDs', 'Reason'],
            $dupdetailrows
        );
    } else {
        echo '<div class="gmk-sd-card"><span class="badge warn">INFO</span> Student impact scan is disabled. Enable "Run heavy class student scan" to execute it.</div>';
    }
}

if ($hasselecteduser) {
    $events = get_class_events((int)$userid, $initdate, $enddate);
    $eventrows = [];
    $dupmap = [];
    $activeclassset = [];
    foreach ($status2rows as $p) {
        if (!empty($p->classid)) {
            $activeclassset[(int)$p->classid] = true;
        }
    }

    foreach ($events as $e) {
        $frontname = gmk_dbg_sd_front_name($e);
        $start = gmk_dbg_sd_dt($e->start ?? '', $e->timestart ?? 0);
        $end = gmk_dbg_sd_dt($e->end ?? '', (!empty($e->timestart) && !empty($e->timeduration)) ? ((int)$e->timestart + (int)$e->timeduration) : 0);
        $normname = trim($frontname);
        if (function_exists('mb_strtolower')) {
            $normname = mb_strtolower($normname, 'UTF-8');
        } else {
            $normname = strtolower($normname);
        }
        $key = $normname . '|' . $start . '|' . $end;
        if (!isset($dupmap[$key])) {
            $dupmap[$key] = [];
        }
        $dupmap[$key][] = $e;

        $eventclassid = !empty($e->classId) ? (int)$e->classId : 0;
        $activeok = ($eventclassid > 0 && isset($activeclassset[$eventclassid])) ? 'YES' : 'NO';
        $staletag = ($eventclassid > 0 && !isset($activeclassset[$eventclassid])) ? 'stale_class_ref' : (($eventclassid <= 0) ? 'class_unresolved' : '');

        $eventrows[] = [
            'eventid' => (int)($e->id ?? 0),
            'module' => s((string)($e->modulename ?? '')),
            'instance' => (int)($e->instance ?? 0),
            'classid' => $eventclassid,
            'groupid' => (int)($e->groupid ?? 0),
            'courseid' => (int)($e->courseid ?? 0),
            'front_name' => s($frontname),
            'start' => s($start),
            'end' => s($end),
            'active_class_ref?' => $activeok === 'YES' ? '<span class="badge ok">YES</span>' : '<span class="badge warn">NO</span>',
            'tag' => s($staletag),
        ];
    }

    $duprows = [];
    foreach ($dupmap as $k => $items) {
        if (count($items) <= 1) {
            continue;
        }
        $mods = [];
        $classidsdup = [];
        $evids = [];
        $stalehits = 0;
        $example = reset($items);
        foreach ($items as $it) {
            $mods[] = (string)($it->modulename ?? '');
            $cid = !empty($it->classId) ? (int)$it->classId : 0;
            $classidsdup[] = $cid;
            $evids[] = (int)($it->id ?? 0);
            if ($cid > 0 && !isset($activeclassset[$cid])) {
                $stalehits++;
            }
        }
        $frontname = gmk_dbg_sd_front_name($example);
        $start = gmk_dbg_sd_dt($example->start ?? '', $example->timestart ?? 0);
        $end = gmk_dbg_sd_dt($example->end ?? '', (!empty($example->timestart) && !empty($example->timeduration)) ? ((int)$example->timestart + (int)$example->timeduration) : 0);

        $reason = [];
        if ($stalehits > 0) {
            $reason[] = 'includes_stale_class_ref';
        }
        if (count(array_unique($mods)) > 1) {
            $reason[] = 'mixed_modules';
        }
        if (in_array(0, $classidsdup, true)) {
            $reason[] = 'class_unresolved';
        }
        if (empty($reason)) {
            $reason[] = 'check_calendar_rows';
        }

        $duprows[] = [
            'count' => count($items),
            'front_name' => s($frontname),
            'start' => s($start),
            'end' => s($end),
            'modules' => s(implode(', ', array_unique($mods))),
            'classids' => s(implode(', ', array_unique($classidsdup))),
            'eventids' => s(implode(', ', $evids)),
            'reason' => s(implode(' | ', $reason)),
        ];
    }

    echo '<div class="gmk-sd-card"><strong>Front-visible duplicate cards (same name/start/end)</strong></div>';
    if (empty($duprows)) {
        echo '<div class="gmk-sd-card"><span class="badge ok">OK</span> No duplicated front cards detected for this range.</div>';
    } else {
        gmk_dbg_sd_print_table(
            ['count', 'front_name', 'start', 'end', 'modules', 'classids', 'eventids', 'reason'],
            $duprows
        );
    }

    echo '<div class="gmk-sd-card"><strong>Raw schedule events used by horario.vue</strong></div>';
    gmk_dbg_sd_print_table(
        ['eventid', 'module', 'instance', 'classid', 'groupid', 'courseid', 'front_name', 'start', 'end', 'active_class_ref?', 'tag'],
        $eventrows
    );
}

echo '</div>';
echo $OUTPUT->footer();
