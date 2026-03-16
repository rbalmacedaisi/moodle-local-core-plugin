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
        'enddate' => $enddate
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

if ($userid <= 0) {
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

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

    $classid = !empty($e->classId) ? (int)$e->classId : 0;
    $activeok = ($classid > 0 && isset($activeclassset[$classid])) ? 'YES' : 'NO';
    $staletag = ($classid > 0 && !isset($activeclassset[$classid])) ? 'stale_class_ref' : (($classid <= 0) ? 'class_unresolved' : '');

    $eventrows[] = [
        'eventid' => (int)($e->id ?? 0),
        'module' => s((string)($e->modulename ?? '')),
        'instance' => (int)($e->instance ?? 0),
        'classid' => $classid,
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

echo '</div>';
echo $OUTPUT->footer();
