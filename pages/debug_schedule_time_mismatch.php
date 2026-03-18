<?php
/**
 * Debug page: student schedule time mismatch.
 *
 * Goal:
 * - Explain why a class card time (calendar block) differs from modal time.
 * - Compare data sources used by frontend:
 *   1) card: event.start/event.end (from get_class_events)
 *   2) modal: event.timeRange (class inittime/endtime)
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug Schedule Time Mismatch');
$PAGE->set_heading('Debug Schedule Time Mismatch');

$search = optional_param('search', '', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$subject = optional_param('subject', 'GASES NOCIVOS EN ESPACIOS CERRADOS', PARAM_RAW_TRIMMED);
$classid = optional_param('classid', 0, PARAM_INT);
$initdate = optional_param('initdate', date('Y-m-01', strtotime('-1 month')), PARAM_RAW_TRIMMED);
$enddate = optional_param('enddate', date('Y-m-d', strtotime('+6 months')), PARAM_RAW_TRIMMED);
$runall = optional_param('runall', 0, PARAM_BOOL);
$offsetusers = optional_param('offsetusers', 0, PARAM_INT);
$maxusers = optional_param('maxusers', 300, PARAM_INT);
$maxdetails = optional_param('maxdetails', 800, PARAM_INT);

if ($offsetusers < 0) {
    $offsetusers = 0;
}
if ($maxusers < 20) {
    $maxusers = 20;
}
if ($maxusers > 1500) {
    $maxusers = 1500;
}
if ($maxdetails < 50) {
    $maxdetails = 50;
}
if ($maxdetails > 5000) {
    $maxdetails = 5000;
}

function dstm_h($value): string {
    return s((string)$value);
}

function dstm_time_hm(int $ts): string {
    if ($ts <= 0) {
        return '-';
    }
    return userdate($ts, '%H:%M');
}

function dstm_ts(int $ts): string {
    if ($ts <= 0) {
        return '-';
    }
    return userdate($ts, '%Y-%m-%d %H:%M');
}

function dstm_minutes_to_hm(int $minutes): string {
    if ($minutes < 0 || $minutes > 1440) {
        return '-';
    }
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
}

function dstm_time_token_to_minutes(string $token): ?int {
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})(?:\s*([AaPp]\.?[Mm]\.?))?$/', $token, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $mi = (int)$m[2];
    $ampm = isset($m[3]) ? strtolower(str_replace('.', '', $m[3])) : '';

    if ($ampm !== '') {
        if ($h === 12) {
            $h = 0;
        }
        if ($ampm === 'pm') {
            $h += 12;
        }
    }

    if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59) {
        return null;
    }
    return ($h * 60) + $mi;
}

/**
 * Parses range like:
 * - "08:00 AM - 10:00 AM"
 * - "08:00 - 10:00"
 * Returns [startMinutes, endMinutes, normalizedText].
 */
function dstm_parse_timerange(string $range): array {
    $raw = trim(strip_tags($range));
    if ($raw === '') {
        return [null, null, ''];
    }
    $parts = preg_split('/\s*-\s*/', $raw);
    if (!is_array($parts) || count($parts) < 2) {
        return [null, null, $raw];
    }

    $start = trim((string)$parts[0]);
    $end = trim((string)$parts[1]);

    // If second token has no am/pm but first has it, keep as-is (24h fallback).
    $startmin = dstm_time_token_to_minutes($start);
    $endmin = dstm_time_token_to_minutes($end);
    return [$startmin, $endmin, $raw];
}

/**
 * Build comparison payload used by single and mass modes.
 */
function dstm_build_event_compare($ev): array {
    $startts = (int)($ev->timestart ?? 0);
    $dur = (int)($ev->timeduration ?? 0);
    $endts = $startts + $dur;

    $cardstarttext = dstm_time_hm($startts);
    $cardendtext = dstm_time_hm($endts);
    $cardstartmin = dstm_time_token_to_minutes($cardstarttext);
    $cardendmin = dstm_time_token_to_minutes($cardendtext);

    list($modalstartmin, $modalendmin, $modalraw) = dstm_parse_timerange((string)($ev->timeRange ?? ''));

    $startdelta = null;
    $enddelta = null;
    if ($modalstartmin !== null && $cardstartmin !== null) {
        $startdelta = $cardstartmin - $modalstartmin;
    }
    if ($modalendmin !== null && $cardendmin !== null) {
        $enddelta = $cardendmin - $modalendmin;
    }

    $parseok = ($modalstartmin !== null && $cardstartmin !== null);
    $ismatch = false;
    if ($parseok) {
        if ($startdelta !== 0) {
            $ismatch = true;
        } else if ($enddelta !== null && $enddelta !== 0) {
            $ismatch = true;
        }
    }

    return [
        'startts' => $startts,
        'endts' => $endts,
        'dur' => $dur,
        'cardrange' => $cardstarttext . ' - ' . $cardendtext,
        'modalrange' => ($modalraw !== '' ? $modalraw : '(empty)'),
        'startdelta' => $startdelta,
        'enddelta' => $enddelta,
        'parseok' => $parseok,
        'ismatch' => $ismatch,
    ];
}

function dstm_clean(string $text): string {
    $text = trim($text);
    if (function_exists('cleanString')) {
        return cleanString($text);
    }
    $text = core_text::strtolower($text);
    return $text;
}

/**
 * Resolves columns from gmk_class_schedules across schema variants.
 * Returns [daycol, startcol, endcol, assignedcol, excludedcol].
 */
function dstm_get_schedule_columns(): array {
    global $DB;
    $cols = $DB->get_columns('gmk_class_schedules');
    if (empty($cols)) {
        return [null, null, null, null, null];
    }
    $keys = array_map('strtolower', array_keys($cols));
    $find = function(array $candidates) use ($keys) {
        foreach ($candidates as $candidate) {
            $lc = strtolower($candidate);
            if (in_array($lc, $keys, true)) {
                return $candidate;
            }
        }
        return null;
    };
    $daycol = $find(['day', 'weekday']);
    $startcol = $find(['start_time', 'starttime', 'inittime', 'start']);
    $endcol = $find(['end_time', 'endtime', 'end']);
    $assignedcol = $find(['assigned_dates', 'assigneddates']);
    $excludedcol = $find(['excluded_dates', 'excludeddates']);
    return [$daycol, $startcol, $endcol, $assignedcol, $excludedcol];
}

function dstm_print_table(array $headers, array $rows): void {
    echo '<table class="dstm-table"><thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . dstm_h($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($headers) . '" class="dstm-muted">Sin registros</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($headers as $h) {
                $v = isset($row[$h]) ? $row[$h] : '';
                echo '<td>' . (string)$v . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
}

echo $OUTPUT->header();

echo '<style>
.dstm-wrap{max-width:1400px}
.dstm-card{background:#fff;border:1px solid #d9e1ea;border-radius:8px;padding:14px;margin:10px 0}
.dstm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px}
.dstm-title{font-size:18px;font-weight:700;margin:2px 0 8px}
.dstm-sub{font-size:14px;font-weight:700;margin:14px 0 6px}
.dstm-note{font-size:12px;color:#516173}
.dstm-ok{color:#0a7a2f;font-weight:700}
.dstm-bad{color:#b42318;font-weight:700}
.dstm-warn{color:#9a6700;font-weight:700}
.dstm-muted{color:#6b7280}
.dstm-table{width:100%;border-collapse:collapse;font-size:12px}
.dstm-table th,.dstm-table td{border:1px solid #d9e1ea;padding:6px 8px;vertical-align:top}
.dstm-table th{background:#0f5ea8;color:#fff;text-align:left}
.dstm-form input{padding:7px 8px;border:1px solid #c8d2df;border-radius:6px}
.dstm-form button{padding:8px 12px;border:0;border-radius:6px;background:#0f5ea8;color:#fff;cursor:pointer}
.dstm-form label{font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px}
</style>';

echo '<div class="dstm-wrap">';
echo '<div class="dstm-title">Debug student schedule time mismatch</div>';
echo '<div class="dstm-note">This page compares card time (event.start/end) vs modal time (event.timeRange) for the selected class.</div>';

echo '<div class="dstm-card">';
echo '<form method="get" class="dstm-form">';
echo '<div class="dstm-grid">';
echo '<div><label>Student search (idnumber, username, email, name)</label><input type="text" name="search" value="' . dstm_h($search) . '" style="width:100%"></div>';
echo '<div><label>User ID</label><input type="number" name="userid" value="' . (int)$userid . '" style="width:100%"></div>';
echo '<div><label>Class ID (optional)</label><input type="number" name="classid" value="' . (int)$classid . '" style="width:100%"></div>';
echo '<div><label>Subject contains</label><input type="text" name="subject" value="' . dstm_h($subject) . '" style="width:100%"></div>';
echo '<div><label>Init date (Y-m-d)</label><input type="text" name="initdate" value="' . dstm_h($initdate) . '" style="width:100%"></div>';
echo '<div><label>End date (Y-m-d)</label><input type="text" name="enddate" value="' . dstm_h($enddate) . '" style="width:100%"></div>';
echo '<div><label>Scan offset (chunk)</label><input type="number" name="offsetusers" value="' . (int)$offsetusers . '" style="width:100%"></div>';
echo '<div><label>Scan size (chunk)</label><input type="number" name="maxusers" value="' . (int)$maxusers . '" style="width:100%"></div>';
echo '<div><label>Max detail rows</label><input type="number" name="maxdetails" value="' . (int)$maxdetails . '" style="width:100%"></div>';
echo '</div>';
echo '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">';
echo '<button type="submit">Diagnose selected</button>';
echo '<button type="submit" name="runall" value="1">Scan all mismatches</button>';
echo '</div>';
echo '</form>';
echo '</div>';

$selecteduser = null;
if ($userid > 0) {
    $selecteduser = $DB->get_record('user', ['id' => (int)$userid, 'deleted' => 0], 'id, firstname, lastname, email, username, idnumber, suspended', IGNORE_MISSING);
}

if (!$selecteduser && $search !== '') {
    $like = '%' . $DB->sql_like_escape($search) . '%';
    $studentcandidates = $DB->get_records_sql(
        "SELECT id, firstname, lastname, email, username, idnumber, suspended
           FROM {user}
          WHERE deleted = 0
            AND (" . $DB->sql_like('idnumber', ':q1', false) . "
              OR " . $DB->sql_like('username', ':q2', false) . "
              OR " . $DB->sql_like('email', ':q3', false) . "
              OR " . $DB->sql_like('firstname', ':q4', false) . "
              OR " . $DB->sql_like('lastname', ':q5', false) . ")
       ORDER BY suspended ASC, lastname ASC, firstname ASC",
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like],
        0,
        60
    );

    echo '<div class="dstm-card"><div class="dstm-sub">Student candidates</div>';
    $rows = [];
    foreach ($studentcandidates as $u) {
        $url = new moodle_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php', [
            'userid' => (int)$u->id,
            'search' => $search,
            'subject' => $subject,
            'classid' => (int)$classid,
            'initdate' => $initdate,
            'enddate' => $enddate,
        ]);
        $rows[] = [
            'User ID' => (int)$u->id,
            'Name' => dstm_h(trim($u->firstname . ' ' . $u->lastname)),
            'ID Number' => dstm_h((string)$u->idnumber),
            'Username' => dstm_h((string)$u->username),
            'Email' => dstm_h((string)$u->email),
            'Suspended' => (int)$u->suspended ? '<span class="dstm-bad">YES</span>' : '<span class="dstm-ok">NO</span>',
            'Action' => '<a href="' . $url->out(false) . '">Use</a>',
        ];
    }
    dstm_print_table(['User ID', 'Name', 'ID Number', 'Username', 'Email', 'Suspended', 'Action'], $rows);
    echo '</div>';
}

if ($runall) {
    $subjectclean = dstm_clean($subject);
    $startts = strtotime($initdate . ' 00:00:00');
    $endts = strtotime($enddate . ' 23:59:59');
    if (!$startts) {
        $startts = strtotime('-60 days');
    }
    if (!$endts) {
        $endts = strtotime('+180 days');
    }
    if ($endts < $startts) {
        $tmp = $startts;
        $startts = $endts;
        $endts = $tmp;
    }

    $scopeuserids = [];
    $hasscopefilter = false;
    if ($userid > 0) {
        $scopeuserids[] = (int)$userid;
        $hasscopefilter = true;
    } else if (trim($search) !== '') {
        $hasscopefilter = true;
        $like = '%' . $DB->sql_like_escape(trim($search)) . '%';
        $matchedusers = $DB->get_records_sql(
            "SELECT id
               FROM {user}
              WHERE deleted = 0
                AND (" . $DB->sql_like('idnumber', ':uq1', false) . "
                  OR " . $DB->sql_like('username', ':uq2', false) . "
                  OR " . $DB->sql_like('email', ':uq3', false) . "
                  OR " . $DB->sql_like('firstname', ':uq4', false) . "
                  OR " . $DB->sql_like('lastname', ':uq5', false) . ")
           ORDER BY id ASC",
            ['uq1' => $like, 'uq2' => $like, 'uq3' => $like, 'uq4' => $like, 'uq5' => $like],
            0,
            400
        );
        foreach ($matchedusers as $mu) {
            $scopeuserids[] = (int)$mu->id;
        }
        $scopeuserids = array_values(array_unique($scopeuserids));
    }

    $classwhere = "1=1";
    $classparams = [];
    if ((int)$classid > 0) {
        $classwhere .= " AND c.id = :cid";
        $classparams['cid'] = (int)$classid;
    }
    if ($subjectclean !== '') {
        $like = '%' . $DB->sql_like_escape(trim($subject)) . '%';
        $classwhere .= " AND (" . $DB->sql_like('c.name', ':cq1', false)
            . " OR " . $DB->sql_like('crs.fullname', ':cq2', false) . ")";
        $classparams['cq1'] = $like;
        $classparams['cq2'] = $like;
    }
    $classwhere .= " AND (c.enddate = 0 OR c.enddate >= :wstart)";
    $classwhere .= " AND (c.initdate = 0 OR c.initdate <= :wend)";
    $classparams['wstart'] = $startts;
    $classparams['wend'] = $endts;

    if ($hasscopefilter && empty($scopeuserids)) {
        $classwhere .= " AND 1 = 0";
    } else if (!empty($scopeuserids)) {
        list($gmuin, $gmuparams) = $DB->get_in_or_equal($scopeuserids, SQL_PARAMS_NAMED, 'gmu');
        list($cpuin, $cpuparams) = $DB->get_in_or_equal($scopeuserids, SQL_PARAMS_NAMED, 'cpu');
        $classwhere .= " AND (
                EXISTS (
                    SELECT 1
                      FROM {groups_members} gm
                     WHERE gm.groupid = c.groupid
                       AND gm.userid {$gmuin}
                )
                OR EXISTS (
                    SELECT 1
                      FROM {gmk_course_progre} cp
                     WHERE cp.classid = c.id
                       AND cp.userid {$cpuin}
                )
            )";
        $classparams = array_merge($classparams, $gmuparams, $cpuparams);
    }

    $totalcandidates = (int)$DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {gmk_class} c
      LEFT JOIN {course} crs ON crs.id = c.corecourseid
          WHERE {$classwhere}",
        $classparams
    );

    $classlist = array_values($DB->get_records_sql(
        "SELECT c.id, c.name, c.corecourseid, c.learningplanid, c.groupid, c.instructorid,
                c.inittime, c.endtime, c.classduration, c.initdate, c.enddate, c.approved, c.closed,
                crs.fullname AS corecoursename
           FROM {gmk_class} c
      LEFT JOIN {course} crs ON crs.id = c.corecourseid
          WHERE {$classwhere}
       ORDER BY c.id ASC",
        $classparams,
        $offsetusers,
        $maxusers
    ));

    $classmap = [];
    $classids = [];
    foreach ($classlist as $c) {
        $cid = (int)$c->id;
        if ($cid > 0) {
            $classids[] = $cid;
            $classmap[$cid] = $c;
        }
    }

    $scannedclasses = count($classids);
    $scannedevents = 0;
    $sessionevents = 0;
    $parseokcount = 0;
    $mismatchcount = 0;
    $affectedclasses = [];
    $detailrows = [];
    $seenkeys = [];

    if (!empty($classids)) {
        list($classin1, $classparams1) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'c1');
        $attendanceparams = array_merge($classparams1, ['st1' => $startts, 'en1' => $endts]);
        $attendancers = $DB->get_recordset_sql(
            "SELECT c.id AS classid, c.name AS classname, c.inittime AS classstart, c.endtime AS classend,
                    e.id AS eventid, e.name AS eventname, e.timestart, e.timeduration
               FROM {gmk_class} c
               JOIN {gmk_bbb_attendance_relation} r ON r.classid = c.id
               JOIN {attendance_sessions} s ON s.id = r.attendancesessionid
               JOIN {event} e ON e.id = s.caleventid
              WHERE c.id {$classin1}
                AND e.timestart BETWEEN :st1 AND :en1",
            $attendanceparams
        );
        foreach ($attendancers as $row) {
            $sessionevents++;
            $scannedevents++;
            $cid = (int)$row->classid;
            $eventid = (int)$row->eventid;
            $dedupkey = 'attendance:' . $cid . ':' . $eventid;
            if (isset($seenkeys[$dedupkey])) {
                continue;
            }
            $seenkeys[$dedupkey] = true;
            if (!isset($classmap[$cid])) {
                continue;
            }
            $duration = (int)$row->timeduration;
            if ($duration <= 0) {
                $duration = (int)($classmap[$cid]->classduration ?? 0);
            }
            if ($duration < 0) {
                $duration = 0;
            }
            $modalrange = gmk_build_event_time_range((int)$row->timestart, $duration);
            $evobj = (object)[
                'timestart' => (int)$row->timestart,
                'timeduration' => $duration,
                'timeRange' => $modalrange,
            ];
            $cmp = dstm_build_event_compare($evobj);
            if (!$cmp['parseok']) {
                continue;
            }
            $parseokcount++;
            if (!$cmp['ismatch']) {
                continue;
            }
            $mismatchcount++;
            if (!isset($affectedclasses[$cid])) {
                $affectedclasses[$cid] = [
                    'classid' => $cid,
                    'classname' => (string)($classmap[$cid]->name ?? ''),
                    'corecoursename' => (string)($classmap[$cid]->corecoursename ?? ''),
                    'mismatchcount' => 0,
                    'samplecard' => $cmp['cardrange'],
                    'samplemodal' => $cmp['modalrange'],
                    'samplestartdelta' => $cmp['startdelta'],
                    'sampleenddelta' => $cmp['enddelta'],
                    'modules' => [],
                    'affectedusers' => 0,
                ];
            }
            $affectedclasses[$cid]['mismatchcount']++;
            $affectedclasses[$cid]['modules']['attendance'] = true;
            if (count($detailrows) < $maxdetails) {
                $detailrows[] = [
                    'Class ID' => $cid,
                    'Class' => dstm_h((string)($classmap[$cid]->name ?? '')),
                    'Module' => 'attendance',
                    'Event ID' => $eventid,
                    'Event' => dstm_h((string)$row->eventname),
                    'Date start' => dstm_ts((int)$cmp['startts']),
                    'Card time' => dstm_h((string)$cmp['cardrange']),
                    'Modal time' => dstm_h((string)$cmp['modalrange']),
                    'Start delta(min)' => ($cmp['startdelta'] === null ? '-' : (string)$cmp['startdelta']),
                    'End delta(min)' => ($cmp['enddelta'] === null ? '-' : (string)$cmp['enddelta']),
                    'Action' => '<a href="' . (new moodle_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php', [
                        'userid' => (int)$userid,
                        'search' => $search,
                        'classid' => $cid,
                        'subject' => $subject,
                        'initdate' => $initdate,
                        'enddate' => $enddate,
                    ]))->out(false) . '">Open</a>',
                ];
            }
        }
        $attendancers->close();

        list($classin2, $classparams2) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'c2');
        $bbbparams = array_merge($classparams2, ['st2' => $startts, 'en2' => $endts]);
        $bbbrs = $DB->get_recordset_sql(
            "SELECT c.id AS classid, c.name AS classname, c.inittime AS classstart, c.endtime AS classend,
                    e.id AS eventid, e.name AS eventname, e.timestart, e.timeduration,
                    COALESCE(r.bbbid, cm.instance, 0) AS bbbinstanceid
               FROM {gmk_class} c
               JOIN {gmk_bbb_attendance_relation} r ON r.classid = c.id
          LEFT JOIN {course_modules} cm ON cm.id = r.bbbmoduleid
               JOIN {event} e
                 ON e.modulename = 'bigbluebuttonbn'
                AND (
                    (r.bbbid > 0 AND e.instance = r.bbbid)
                    OR (cm.instance > 0 AND e.instance = cm.instance)
                )
              WHERE c.id {$classin2}
                AND e.timestart BETWEEN :st2 AND :en2",
            $bbbparams
        );
        foreach ($bbbrs as $row) {
            $sessionevents++;
            $scannedevents++;
            $cid = (int)$row->classid;
            $eventid = (int)$row->eventid;
            $dedupkey = 'bbb:' . $cid . ':' . $eventid;
            if (isset($seenkeys[$dedupkey])) {
                continue;
            }
            $seenkeys[$dedupkey] = true;
            if (!isset($classmap[$cid])) {
                continue;
            }
            list($resolvedstart, $resolvedduration) = gmk_resolve_bbb_event_schedule(
                (int)($row->bbbinstanceid ?? 0),
                (int)$row->timestart,
                (int)$row->timeduration,
                (int)($classmap[$cid]->classduration ?? 0)
            );
            $modalrange = gmk_build_event_time_range((int)$resolvedstart, (int)$resolvedduration);
            $evobj = (object)[
                'timestart' => (int)$resolvedstart,
                'timeduration' => (int)$resolvedduration,
                'timeRange' => $modalrange,
            ];
            $cmp = dstm_build_event_compare($evobj);
            if (!$cmp['parseok']) {
                continue;
            }
            $parseokcount++;
            if (!$cmp['ismatch']) {
                continue;
            }
            $mismatchcount++;
            if (!isset($affectedclasses[$cid])) {
                $affectedclasses[$cid] = [
                    'classid' => $cid,
                    'classname' => (string)($classmap[$cid]->name ?? ''),
                    'corecoursename' => (string)($classmap[$cid]->corecoursename ?? ''),
                    'mismatchcount' => 0,
                    'samplecard' => $cmp['cardrange'],
                    'samplemodal' => $cmp['modalrange'],
                    'samplestartdelta' => $cmp['startdelta'],
                    'sampleenddelta' => $cmp['enddelta'],
                    'modules' => [],
                    'affectedusers' => 0,
                ];
            }
            $affectedclasses[$cid]['mismatchcount']++;
            $affectedclasses[$cid]['modules']['bigbluebuttonbn'] = true;
            if (count($detailrows) < $maxdetails) {
                $detailrows[] = [
                    'Class ID' => $cid,
                    'Class' => dstm_h((string)($classmap[$cid]->name ?? '')),
                    'Module' => 'bigbluebuttonbn',
                    'Event ID' => $eventid,
                    'Event' => dstm_h((string)$row->eventname),
                    'Date start' => dstm_ts((int)$cmp['startts']),
                    'Card time' => dstm_h((string)$cmp['cardrange']),
                    'Modal time' => dstm_h((string)$cmp['modalrange']),
                    'Start delta(min)' => ($cmp['startdelta'] === null ? '-' : (string)$cmp['startdelta']),
                    'End delta(min)' => ($cmp['enddelta'] === null ? '-' : (string)$cmp['enddelta']),
                    'Action' => '<a href="' . (new moodle_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php', [
                        'userid' => (int)$userid,
                        'search' => $search,
                        'classid' => $cid,
                        'subject' => $subject,
                        'initdate' => $initdate,
                        'enddate' => $enddate,
                    ]))->out(false) . '">Open</a>',
                ];
            }
        }
        $bbbrs->close();
    }

    $affectedstudents = 0;
    if (!empty($affectedclasses)) {
        $mismatchclassids = array_keys($affectedclasses);
        list($mcin1, $mcparams1) = $DB->get_in_or_equal($mismatchclassids, SQL_PARAMS_NAMED, 'mc1');
        list($mcin2, $mcparams2) = $DB->get_in_or_equal($mismatchclassids, SQL_PARAMS_NAMED, 'mc2');

        $gmusersql = '';
        $cpusersql = '';
        $scopeparams = [];
        if (!empty($scopeuserids)) {
            list($suin1, $suparams1) = $DB->get_in_or_equal($scopeuserids, SQL_PARAMS_NAMED, 'su1');
            list($suin2, $suparams2) = $DB->get_in_or_equal($scopeuserids, SQL_PARAMS_NAMED, 'su2');
            $gmusersql = " AND gm.userid {$suin1}";
            $cpusersql = " AND cp.userid {$suin2}";
            $scopeparams = array_merge($scopeparams, $suparams1, $suparams2);
        }

        $classcounts = $DB->get_records_sql_menu(
            "SELECT x.classid, COUNT(DISTINCT x.userid) AS usercount
               FROM (
                    SELECT c.id AS classid, gm.userid AS userid
                      FROM {gmk_class} c
                      JOIN {groups_members} gm ON gm.groupid = c.groupid
                     WHERE c.id {$mcin1}
                       AND gm.userid <> COALESCE(c.instructorid, 0)
                           {$gmusersql}
                    UNION ALL
                    SELECT cp.classid AS classid, cp.userid AS userid
                      FROM {gmk_course_progre} cp
                     WHERE cp.classid {$mcin2}
                       AND cp.status = 2
                           {$cpusersql}
               ) x
           GROUP BY x.classid",
            array_merge($mcparams1, $mcparams2, $scopeparams)
        );
        foreach ($classcounts as $cid => $cnt) {
            $icid = (int)$cid;
            if (isset($affectedclasses[$icid])) {
                $affectedclasses[$icid]['affectedusers'] = (int)$cnt;
            }
        }

        $affectedstudents = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT x.userid)
               FROM (
                    SELECT gm.userid AS userid
                      FROM {gmk_class} c
                      JOIN {groups_members} gm ON gm.groupid = c.groupid
                     WHERE c.id {$mcin1}
                       AND gm.userid <> COALESCE(c.instructorid, 0)
                           {$gmusersql}
                    UNION ALL
                    SELECT cp.userid AS userid
                      FROM {gmk_course_progre} cp
                     WHERE cp.classid {$mcin2}
                       AND cp.status = 2
                           {$cpusersql}
               ) x",
            array_merge($mcparams1, $mcparams2, $scopeparams)
        );
    }

    echo '<div class="dstm-card">';
    echo '<div class="dstm-sub">Mass scan summary</div>';
    echo '<div class="dstm-grid">';
    echo '<div><strong>Total class candidates</strong><br>' . (int)$totalcandidates . '</div>';
    echo '<div><strong>Scanned classes (chunk)</strong><br>' . (int)$scannedclasses . '</div>';
    echo '<div><strong>Offset</strong><br>' . (int)$offsetusers . '</div>';
    echo '<div><strong>Chunk size</strong><br>' . (int)$maxusers . '</div>';
    echo '<div><strong>Scanned events</strong><br>' . (int)$scannedevents . '</div>';
    echo '<div><strong>Session events</strong><br>' . (int)$sessionevents . '</div>';
    echo '<div><strong>Parseable events</strong><br>' . (int)$parseokcount . '</div>';
    echo '<div><strong>Mismatches</strong><br>' . ((int)$mismatchcount > 0 ? '<span class="dstm-bad">' . (int)$mismatchcount . '</span>' : '<span class="dstm-ok">0</span>') . '</div>';
    echo '<div><strong>Affected classes</strong><br>' . (int)count($affectedclasses) . '</div>';
    echo '<div><strong>Affected students</strong><br>' . (int)$affectedstudents . '</div>';
    echo '<div><strong>User scope</strong><br>' . (!empty($scopeuserids) ? (int)count($scopeuserids) . ' user(s)' : 'All') . '</div>';
    echo '</div>';

    $prevurl = null;
    $nexturl = null;
    if ($offsetusers > 0) {
        $prevoffset = $offsetusers - $maxusers;
        if ($prevoffset < 0) {
            $prevoffset = 0;
        }
        $prevurl = new moodle_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php', [
            'runall' => 1,
            'search' => $search,
            'userid' => (int)$userid,
            'subject' => $subject,
            'classid' => (int)$classid,
            'initdate' => $initdate,
            'enddate' => $enddate,
            'offsetusers' => $prevoffset,
            'maxusers' => $maxusers,
            'maxdetails' => $maxdetails,
        ]);
    }
    if (($offsetusers + $maxusers) < $totalcandidates) {
        $nexturl = new moodle_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php', [
            'runall' => 1,
            'search' => $search,
            'userid' => (int)$userid,
            'subject' => $subject,
            'classid' => (int)$classid,
            'initdate' => $initdate,
            'enddate' => $enddate,
            'offsetusers' => $offsetusers + $maxusers,
            'maxusers' => $maxusers,
            'maxdetails' => $maxdetails,
        ]);
    }
    if ($prevurl || $nexturl) {
        echo '<div style="margin-top:10px">';
        if ($prevurl) {
            echo '<a href="' . $prevurl->out(false) . '">Prev chunk</a>';
        }
        if ($prevurl && $nexturl) {
            echo ' | ';
        }
        if ($nexturl) {
            echo '<a href="' . $nexturl->out(false) . '">Next chunk</a>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="dstm-card"><div class="dstm-sub">Affected classes</div>';
    $classrows = [];
    uasort($affectedclasses, function($a, $b) {
        $ca = (int)($a['mismatchcount'] ?? 0);
        $cb = (int)($b['mismatchcount'] ?? 0);
        if ($ca === $cb) {
            return ((int)($a['classid'] ?? 0) <=> (int)($b['classid'] ?? 0));
        }
        return ($cb <=> $ca);
    });
    foreach ($affectedclasses as $ac) {
        $cid = (int)($ac['classid'] ?? 0);
        $samplestartdelta = ($ac['samplestartdelta'] === null ? '-' : (string)$ac['samplestartdelta']);
        $sampleenddelta = ($ac['sampleenddelta'] === null ? '-' : (string)$ac['sampleenddelta']);
        $sampledelta = $samplestartdelta . ' / ' . $sampleenddelta;
        $action = '<a href="' . (new moodle_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php', [
            'userid' => (int)$userid,
            'search' => $search,
            'classid' => $cid,
            'subject' => $subject,
            'initdate' => $initdate,
            'enddate' => $enddate,
        ]))->out(false) . '">Diagnose</a>';
        $classrows[] = [
            'Class ID' => $cid,
            'Class' => dstm_h((string)($ac['classname'] ?? '')),
            'Core course' => dstm_h((string)($ac['corecoursename'] ?? '')),
            'Mismatches' => (int)($ac['mismatchcount'] ?? 0),
            'Affected users' => (int)($ac['affectedusers'] ?? 0),
            'Modules' => dstm_h(implode(', ', array_keys($ac['modules'] ?? []))),
            'Sample card' => dstm_h((string)($ac['samplecard'] ?? '')),
            'Sample modal' => dstm_h((string)($ac['samplemodal'] ?? '')),
            'Sample delta start/end' => dstm_h($sampledelta),
            'Action' => $action,
        ];
    }
    dstm_print_table(['Class ID', 'Class', 'Core course', 'Mismatches', 'Affected users', 'Modules', 'Sample card', 'Sample modal', 'Sample delta start/end', 'Action'], $classrows);
    echo '</div>';

    echo '<div class="dstm-card"><div class="dstm-sub">Mismatch details (limited)</div>';
    echo '<div class="dstm-note">Showing up to ' . (int)$maxdetails . ' rows.</div>';
    dstm_print_table(
        ['Class ID', 'Class', 'Module', 'Event ID', 'Event', 'Date start', 'Card time', 'Modal time', 'Start delta(min)', 'End delta(min)', 'Action'],
        $detailrows
    );
    echo '</div>';

    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

if (!$selecteduser) {
    echo '<div class="dstm-card"><span class="dstm-warn">Select a student to continue.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Selected student</div>';
echo '<div><strong>' . dstm_h(trim($selecteduser->firstname . ' ' . $selecteduser->lastname)) . '</strong>'
    . ' | uid=' . (int)$selecteduser->id
    . ' | idnumber=' . dstm_h((string)$selecteduser->idnumber)
    . ' | username=' . dstm_h((string)$selecteduser->username)
    . ' | email=' . dstm_h((string)$selecteduser->email)
    . '</div>';
echo '</div>';

$classes = [];
if ($classid > 0) {
    $sql = "SELECT c.*, crs.fullname AS corecoursename, lp.name AS planname
              FROM {gmk_class} c
         LEFT JOIN {course} crs ON crs.id = c.corecourseid
         LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
             WHERE c.id = :cid";
    $one = $DB->get_records_sql($sql, ['cid' => (int)$classid]);
    $classes = array_values($one);
} else if (trim($subject) !== '') {
    $like = '%' . $DB->sql_like_escape(trim($subject)) . '%';
    $sql = "SELECT DISTINCT c.*, crs.fullname AS corecoursename, lp.name AS planname
              FROM {gmk_class} c
         LEFT JOIN {course} crs ON crs.id = c.corecourseid
         LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
         LEFT JOIN {groups_members} gm
                ON gm.groupid = c.groupid
               AND gm.userid = :uid1
         LEFT JOIN {gmk_course_progre} cp
                ON cp.classid = c.id
               AND cp.userid = :uid2
             WHERE (" . $DB->sql_like('c.name', ':q1', false) . "
                OR " . $DB->sql_like('crs.fullname', ':q2', false) . ")
               AND (gm.id IS NOT NULL OR cp.id IS NOT NULL)
          ORDER BY c.closed ASC, c.approved DESC, c.id DESC";
    $classes = array_values($DB->get_records_sql($sql, ['uid1' => (int)$selecteduser->id, 'uid2' => (int)$selecteduser->id, 'q1' => $like, 'q2' => $like], 0, 100));

    // Fallback without student scope to let admin pick class if user mapping is broken.
    if (empty($classes)) {
        $sql2 = "SELECT c.*, crs.fullname AS corecoursename, lp.name AS planname
                   FROM {gmk_class} c
              LEFT JOIN {course} crs ON crs.id = c.corecourseid
              LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
                  WHERE " . $DB->sql_like('c.name', ':q1', false) . "
                     OR " . $DB->sql_like('crs.fullname', ':q2', false) . "
               ORDER BY c.closed ASC, c.approved DESC, c.id DESC";
        $classes = array_values($DB->get_records_sql($sql2, ['q1' => $like, 'q2' => $like], 0, 100));
    }
}

if (empty($classes)) {
    echo '<div class="dstm-card"><span class="dstm-bad">No classes found for the current filters.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$selectedclass = null;
if ($classid > 0) {
    $selectedclass = $classes[0] ?? null;
}

if (!$selectedclass) {
    echo '<div class="dstm-card"><div class="dstm-sub">Matching classes</div>';
    $rows = [];
    foreach ($classes as $c) {
        $url = new moodle_url('/local/grupomakro_core/pages/debug_schedule_time_mismatch.php', [
            'userid' => (int)$selecteduser->id,
            'search' => $search,
            'subject' => $subject,
            'classid' => (int)$c->id,
            'initdate' => $initdate,
            'enddate' => $enddate,
        ]);
        $rows[] = [
            'Class ID' => (int)$c->id,
            'Class' => dstm_h((string)$c->name),
            'Core course' => (int)($c->corecourseid ?? 0) . ' - ' . dstm_h((string)($c->corecoursename ?? '')),
            'Plan' => dstm_h((string)($c->planname ?? '')),
            'Group' => (int)($c->groupid ?? 0),
            'Class time' => dstm_h((string)($c->inittime ?? '')) . ' - ' . dstm_h((string)($c->endtime ?? '')),
            'Approved/Closed' => (int)($c->approved ?? 0) . '/' . (int)($c->closed ?? 0),
            'Action' => '<a href="' . $url->out(false) . '">Diagnose</a>',
        ];
    }
    dstm_print_table(['Class ID', 'Class', 'Core course', 'Plan', 'Group', 'Class time', 'Approved/Closed', 'Action'], $rows);
    echo '</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Selected class</div>';
echo '<div><strong>#' . (int)$selectedclass->id . '</strong> '
    . dstm_h((string)$selectedclass->name)
    . ' | core=' . (int)($selectedclass->corecourseid ?? 0) . ' ' . dstm_h((string)($selectedclass->corecoursename ?? ''))
    . ' | plan=' . (int)($selectedclass->learningplanid ?? 0) . ' ' . dstm_h((string)($selectedclass->planname ?? ''))
    . ' | group=' . (int)($selectedclass->groupid ?? 0)
    . ' | class_time=' . dstm_h((string)($selectedclass->inittime ?? '')) . ' - ' . dstm_h((string)($selectedclass->endtime ?? ''))
    . ' | duration=' . (int)($selectedclass->classduration ?? 0) . 's'
    . '</div>';
echo '</div>';

// 1) get_class_events payload (same source as LXP card).
$eventsall = get_class_events((int)$selecteduser->id, $initdate, $enddate);
$classevents = [];
$subjectclean = dstm_clean($subject);
foreach ($eventsall as $ev) {
    $evclassid = (int)($ev->classId ?? 0);
    $isclassmatch = ($evclassid > 0 && $evclassid === (int)$selectedclass->id);
    if (!$isclassmatch && $subjectclean !== '') {
        $hay = dstm_clean((string)($ev->className ?? '') . ' ' . (string)($ev->coursename ?? '') . ' ' . (string)($ev->name ?? ''));
        $isclassmatch = (strpos($hay, $subjectclean) !== false);
    }
    if ($isclassmatch) {
        $classevents[] = $ev;
    }
}

// 2) class schedules.
list($daycol, $startcol, $endcol, $assignedcol, $excludedcol) = dstm_get_schedule_columns();
$schedulerows = [];
if ($daycol && $startcol && $endcol) {
    $extras = [];
    if ($assignedcol) {
        $extras[] = $assignedcol;
    }
    if ($excludedcol) {
        $extras[] = $excludedcol;
    }
    $extras[] = 'classroomid';
    $extraselect = implode(', ', $extras);
    $sql = "SELECT id, classid, {$daycol} AS dayx, {$startcol} AS startx, {$endcol} AS endx, {$extraselect}
              FROM {gmk_class_schedules}
             WHERE classid = :cid
          ORDER BY id ASC";
    $schedulerows = array_values($DB->get_records_sql($sql, ['cid' => (int)$selectedclass->id]));
}

// 3) attendance sessions for selected class.
$attendancecmid = (int)($selectedclass->attendancemoduleid ?? 0);
$attendanceid = 0;
if ($attendancecmid > 0) {
    $attendanceid = (int)$DB->get_field('course_modules', 'instance', ['id' => $attendancecmid]);
}
$attendancesessions = [];
if ($attendanceid > 0) {
    $attendancesessions = array_values($DB->get_records('attendance_sessions', ['attendanceid' => $attendanceid], 'sessdate ASC'));
}

// 4) relation rows.
$relations = array_values($DB->get_records('gmk_bbb_attendance_relation', ['classid' => (int)$selectedclass->id], 'id ASC'));

// Build quick maps for debugging event source.
$sessionsbyevent = [];
foreach ($attendancesessions as $srow) {
    $eid = (int)($srow->caleventid ?? 0);
    if ($eid > 0) {
        $sessionsbyevent[$eid] = $srow;
    }
}

$relationbybbbcmid = [];
foreach ($relations as $rel) {
    if (!empty($rel->bbbmoduleid)) {
        $relationbybbbcmid[(int)$rel->bbbmoduleid] = $rel;
    }
}

// 5) Simulate horario.vue behavior for hour field.
$eventrows = [];
$mismatchcount = 0;
foreach ($classevents as $ev) {
    $cmp = dstm_build_event_compare($ev);

    $status = '<span class="dstm-ok">OK</span>';
    $delta = '-';
    if ($cmp['parseok']) {
        $diff = (int)$cmp['startdelta'];
        $delta = ($diff > 0 ? '+' : '') . $diff . ' min';
        if ($cmp['ismatch']) {
            $status = '<span class="dstm-bad">MISMATCH</span>';
            $mismatchcount++;
        }
    } else {
        $status = '<span class="dstm-warn">NO_PARSER_MATCH</span>';
    }

    $sourcehint = '-';
    if ((string)($ev->modulename ?? '') === 'attendance') {
        $sid = isset($sessionsbyevent[(int)$ev->id]) ? (int)$sessionsbyevent[(int)$ev->id]->id : 0;
        $sourcehint = $sid > 0 ? ('attendance_session id=' . $sid) : 'attendance_session not linked by caleventid';
    } else if ((string)($ev->modulename ?? '') === 'bigbluebuttonbn') {
        $sourcehint = 'bbb instance=' . (int)($ev->instance ?? 0);
    }

    $eventrows[] = [
        'Event ID' => (int)($ev->id ?? 0),
        'Module' => dstm_h((string)($ev->modulename ?? '')),
        'Class ID' => (int)($ev->classId ?? 0),
        'Event start/end (card)' => dstm_h((string)$cmp['cardrange']),
        'timeRange (modal)' => dstm_h((string)$cmp['modalrange']),
        'Delta start' => dstm_h($delta),
        'timestart' => dstm_ts((int)$cmp['startts']),
        'timeduration(s)' => (int)$cmp['dur'],
        'Source hint' => dstm_h($sourcehint),
        'Status' => $status,
    ];
}

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Frontend behavior check (same logic as horario.vue)</div>';
echo '<div class="dstm-note">Card uses start/end. Modal uses timeRange when present. If both differ, UI shows two different hours.</div>';
echo '<div style="margin:8px 0"><strong>Total events for class in date range:</strong> ' . count($classevents)
    . ' | <strong>Mismatches:</strong> ' . ($mismatchcount > 0 ? '<span class="dstm-bad">' . $mismatchcount . '</span>' : '<span class="dstm-ok">0</span>')
    . '</div>';
dstm_print_table(
    ['Event ID', 'Module', 'Class ID', 'Event start/end (card)', 'timeRange (modal)', 'Delta start', 'timestart', 'timeduration(s)', 'Source hint', 'Status'],
    $eventrows
);
echo '</div>';

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Class master data (gmk_class)</div>';
$classrow = [[
    'id' => (int)$selectedclass->id,
    'name' => dstm_h((string)$selectedclass->name),
    'inittime' => dstm_h((string)($selectedclass->inittime ?? '')),
    'endtime' => dstm_h((string)($selectedclass->endtime ?? '')),
    'classduration' => (int)($selectedclass->classduration ?? 0),
    'classdays' => dstm_h((string)($selectedclass->classdays ?? '')),
    'attendancemoduleid' => (int)($selectedclass->attendancemoduleid ?? 0),
    'bbbmoduleids' => dstm_h((string)($selectedclass->bbbmoduleids ?? '')),
    'groupid' => (int)($selectedclass->groupid ?? 0),
    'approved/closed' => (int)($selectedclass->approved ?? 0) . '/' . (int)($selectedclass->closed ?? 0),
]];
dstm_print_table(['id', 'name', 'inittime', 'endtime', 'classduration', 'classdays', 'attendancemoduleid', 'bbbmoduleids', 'groupid', 'approved/closed'], $classrow);
echo '</div>';

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Class schedules (gmk_class_schedules)</div>';
echo '<div class="dstm-note">Resolved columns: day=' . dstm_h((string)$daycol) . ', start=' . dstm_h((string)$startcol) . ', end=' . dstm_h((string)$endcol) . '</div>';
$rows = [];
foreach ($schedulerows as $sr) {
    $rows[] = [
        'id' => (int)$sr->id,
        'day' => dstm_h((string)($sr->dayx ?? '')),
        'start' => dstm_h((string)($sr->startx ?? '')),
        'end' => dstm_h((string)($sr->endx ?? '')),
        'classroomid' => (int)($sr->classroomid ?? 0),
        'assigned_dates_len' => $assignedcol ? strlen((string)($sr->{$assignedcol} ?? '')) : '-',
        'excluded_dates_len' => $excludedcol ? strlen((string)($sr->{$excludedcol} ?? '')) : '-',
    ];
}
dstm_print_table(['id', 'day', 'start', 'end', 'classroomid', 'assigned_dates_len', 'excluded_dates_len'], $rows);
echo '</div>';

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Attendance sessions (attendance_sessions)</div>';
$rows = [];
foreach ($attendancesessions as $srow) {
    $rows[] = [
        'sessionid' => (int)$srow->id,
        'sessdate' => dstm_ts((int)$srow->sessdate),
        'sess_hm' => dstm_time_hm((int)$srow->sessdate),
        'duration_min' => (int)round(((int)$srow->duration) / 60),
        'caleventid' => (int)($srow->caleventid ?? 0),
        'groupid' => (int)($srow->groupid ?? 0),
    ];
}
dstm_print_table(['sessionid', 'sessdate', 'sess_hm', 'duration_min', 'caleventid', 'groupid'], $rows);
echo '</div>';

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Relation rows (gmk_bbb_attendance_relation)</div>';
$rows = [];
foreach ($relations as $rel) {
    $rows[] = [
        'id' => (int)$rel->id,
        'classid' => (int)($rel->classid ?? 0),
        'attendanceid' => (int)($rel->attendanceid ?? 0),
        'attendancemoduleid' => (int)($rel->attendancemoduleid ?? 0),
        'attendancesessionid' => (int)($rel->attendancesessionid ?? 0),
        'bbbid' => (int)($rel->bbbid ?? 0),
        'bbbmoduleid' => (int)($rel->bbbmoduleid ?? 0),
    ];
}
dstm_print_table(['id', 'classid', 'attendanceid', 'attendancemoduleid', 'attendancesessionid', 'bbbid', 'bbbmoduleid'], $rows);
echo '</div>';

echo '<div class="dstm-card">';
echo '<div class="dstm-sub">Root cause hint</div>';
if ($mismatchcount > 0) {
    echo '<div class="dstm-bad">Detected mismatch between card and modal time.</div>';
    echo '<ul>';
    echo '<li>Card hour is derived from event timestart/timeduration (calendar block).</li>';
    echo '<li>Modal hour is derived from event timeRange, which comes from class inittime/endtime.</li>';
    echo '<li>If attendance session/event time changed but class inittime/endtime was not updated, both hours diverge.</li>';
    echo '</ul>';
} else {
    echo '<div class="dstm-ok">No mismatch detected in the selected range for this class.</div>';
}
echo '</div>';

echo '</div>';
echo $OUTPUT->footer();
