<?php
/**
 * Mass diagnosis and cleanup for duplicated/misaligned BBB events.
 *
 * Uses attendance sessions as source of truth and keeps the BBB event
 * closest to expected timestamp (attendance sessdate - 600s).
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_bbb_mass_cleanup.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('BBB Mass Cleanup');
$PAGE->set_heading('BBB Mass Cleanup');

$periodid = optional_param('periodid', 0, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$maxclasses = optional_param('maxclasses', 250, PARAM_INT);
$maxdeltahours = optional_param('maxdeltahours', 48, PARAM_INT);
$showonlyissues = optional_param('showonlyissues', 1, PARAM_INT);
$deletemodules = optional_param('deletemodules', 1, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHAEXT);

function gmk_bbb_mc_h($v): string {
    return s((string)$v);
}

function gmk_bbb_mc_dt(int $ts): string {
    if ($ts <= 0) {
        return '-';
    }
    return userdate($ts, '%Y-%m-%d %H:%M');
}

function gmk_bbb_mc_period_name(int $periodid): string {
    global $DB;
    static $cache = [];
    if ($periodid <= 0) {
        return '';
    }
    if (isset($cache[$periodid])) {
        return (string)$cache[$periodid];
    }
    $tables = ['gmk_academic_periods', 'gmk_periods', 'local_learning_periods'];
    foreach ($tables as $t) {
        if (!gmk_bbb_mc_table_exists($t)) {
            continue;
        }
        $name = $DB->get_field($t, 'name', ['id' => $periodid], IGNORE_MISSING);
        if ($name !== false && $name !== null) {
            $cache[$periodid] = (string)$name;
            return (string)$cache[$periodid];
        }
    }
    $cache[$periodid] = '';
    return '';
}

function gmk_bbb_mc_table_exists(string $tablename): bool {
    global $DB;
    try {
        $cols = $DB->get_columns($tablename);
        return is_array($cols) && !empty($cols);
    } catch (Throwable $t) {
        return false;
    }
}

function gmk_bbb_mc_period_table(): string {
    if (gmk_bbb_mc_table_exists('gmk_academic_periods')) {
        return 'gmk_academic_periods';
    }
    if (gmk_bbb_mc_table_exists('gmk_periods')) {
        return 'gmk_periods';
    }
    if (gmk_bbb_mc_table_exists('local_learning_periods')) {
        return 'local_learning_periods';
    }
    return '';
}

function gmk_bbb_mc_bbb_module_id(): int {
    static $moduleid = -1;
    global $DB;
    if ($moduleid >= 0) {
        return $moduleid;
    }
    $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'bigbluebuttonbn'], IGNORE_MISSING);
    return $moduleid;
}

function gmk_bbb_mc_rel_rows_for_class(int $classid): array {
    global $DB;
    return $DB->get_records_sql(
        "SELECT id, classid, attendanceid, attendancesessionid, attendancemoduleid, bbbmoduleid, bbbid
           FROM {gmk_bbb_attendance_relation}
          WHERE classid = :classid
       ORDER BY attendancesessionid ASC, id ASC",
        ['classid' => $classid]
    );
}

function gmk_bbb_mc_sessions_map(array $rels, stdClass $class): array {
    global $DB;

    $sessionids = [];
    foreach ($rels as $r) {
        $sid = (int)($r->attendancesessionid ?? 0);
        if ($sid > 0) {
            $sessionids[$sid] = $sid;
        }
    }

    $sessions = [];
    if (!empty($sessionids)) {
        $sessions = $DB->get_records_list(
            'attendance_sessions',
            'id',
            array_values($sessionids),
            'sessdate ASC, id ASC',
            'id,attendanceid,groupid,sessdate,duration,caleventid'
        );
    }

    if (!empty($sessions)) {
        return $sessions;
    }

    // Fallback when relation has no session ids.
    $attendanceinstanceid = 0;
    if (!empty($class->attendancemoduleid)) {
        $attendanceinstanceid = (int)$DB->get_field('course_modules', 'instance', ['id' => (int)$class->attendancemoduleid], IGNORE_MISSING);
    }

    if ($attendanceinstanceid <= 0) {
        $attendanceinstanceid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendanceid', ['classid' => (int)$class->id], IGNORE_MISSING);
    }

    if ($attendanceinstanceid > 0) {
        return $DB->get_records(
            'attendance_sessions',
            ['attendanceid' => $attendanceinstanceid, 'groupid' => (int)$class->groupid],
            'sessdate ASC, id ASC',
            'id,attendanceid,groupid,sessdate,duration,caleventid'
        );
    }

    return [];
}

function gmk_bbb_mc_candidate_events(stdClass $class, array $sessions, int $maxdeltasecs): array {
    global $DB;

    if (empty($sessions)) {
        return [];
    }

    $minexpected = PHP_INT_MAX;
    $maxexpected = 0;
    foreach ($sessions as $s) {
        $expected = (int)$s->sessdate - 600;
        if ($expected < $minexpected) {
            $minexpected = $expected;
        }
        if ($expected > $maxexpected) {
            $maxexpected = $expected;
        }
    }

    if ($minexpected === PHP_INT_MAX || $maxexpected <= 0) {
        return [];
    }

    $params = [
        'courseid' => (int)$class->corecourseid,
        'gid' => (int)$class->groupid,
        'minstart' => (int)$minexpected - $maxdeltasecs,
        'maxstart' => (int)$maxexpected + $maxdeltasecs,
        'modname' => 'bigbluebuttonbn',
    ];

    return $DB->get_records_sql(
        "SELECT id, instance, timestart, timeduration, groupid, courseid, name
           FROM {event}
          WHERE modulename = :modname
            AND courseid = :courseid
            AND (groupid = :gid OR groupid = 0)
            AND timestart >= :minstart
            AND timestart <= :maxstart
       ORDER BY timestart ASC, id ASC",
        $params
    );
}

function gmk_bbb_mc_event_map_by_instance(array $events): array {
    $map = [];
    foreach ($events as $e) {
        $inst = (int)$e->instance;
        if (!isset($map[$inst])) {
            $map[$inst] = [];
        }
        $map[$inst][] = $e;
    }
    return $map;
}

function gmk_bbb_mc_cmid_by_instance(stdClass $class, array $instances): array {
    global $DB;
    $instances = array_values(array_unique(array_filter(array_map('intval', $instances))));
    if (empty($instances)) {
        return [];
    }

    $bbbmoduleid = gmk_bbb_mc_bbb_module_id();
    if ($bbbmoduleid <= 0) {
        return [];
    }

    list($insql, $inparams) = $DB->get_in_or_equal($instances, SQL_PARAMS_NAMED, 'inst');
    $inparams['courseid'] = (int)$class->corecourseid;
    $inparams['moduleid'] = (int)$bbbmoduleid;

    $rows = $DB->get_records_sql(
        "SELECT id, instance
           FROM {course_modules}
          WHERE course = :courseid
            AND module = :moduleid
            AND instance {$insql}
       ORDER BY id DESC",
        $inparams
    );

    $map = [];
    foreach ($rows as $r) {
        $inst = (int)$r->instance;
        if (!isset($map[$inst])) {
            $map[$inst] = (int)$r->id;
        }
    }
    return $map;
}

function gmk_bbb_mc_choose_best_event(array $events, int $expectedts, array &$used, int $maxdeltasecs) {
    $best = null;
    $bestdiff = PHP_INT_MAX;
    foreach ($events as $e) {
        $eid = (int)$e->id;
        if (isset($used[$eid])) {
            continue;
        }
        $diff = abs((int)$e->timestart - $expectedts);
        if ($diff > $maxdeltasecs) {
            continue;
        }
        if ($diff < $bestdiff) {
            $bestdiff = $diff;
            $best = $e;
            continue;
        }
        if ($diff === $bestdiff && $best && (int)$e->id > (int)$best->id) {
            $best = $e;
        }
    }
    return $best;
}

function gmk_bbb_mc_analyze_class(stdClass $class, int $maxdeltasecs): array {
    global $DB;

    $rels = gmk_bbb_mc_rel_rows_for_class((int)$class->id);
    $sessions = gmk_bbb_mc_sessions_map($rels, $class);
    $events = gmk_bbb_mc_candidate_events($class, $sessions, $maxdeltasecs);
    $eventbyinstance = gmk_bbb_mc_event_map_by_instance($events);

    $instances = [];
    foreach ($rels as $r) {
        if (!empty($r->bbbid)) {
            $instances[] = (int)$r->bbbid;
        }
    }
    foreach ($events as $ev) {
        $instances[] = (int)$ev->instance;
    }
    $cmidbyinstance = gmk_bbb_mc_cmid_by_instance($class, $instances);

    $relsbysession = [];
    $orphanrels = [];
    foreach ($rels as $r) {
        $sid = (int)($r->attendancesessionid ?? 0);
        if ($sid <= 0 || !isset($sessions[$sid])) {
            $orphanrels[] = $r;
            continue;
        }
        if (!isset($relsbysession[$sid])) {
            $relsbysession[$sid] = [];
        }
        $relsbysession[$sid][] = $r;
    }

    $sessionssorted = array_values($sessions);
    usort($sessionssorted, static function($a, $b) {
        $sa = (int)($a->sessdate ?? 0);
        $sb = (int)($b->sessdate ?? 0);
        if ($sa === $sb) {
            return (int)$a->id <=> (int)$b->id;
        }
        return $sa <=> $sb;
    });

    $usedevents = [];
    $updates = [];
    $deleterelids = [];
    $oldcmids = [];
    $oldinstances = [];
    $sessionduplicates = 0;
    $mismatchrows = 0;
    $fixablesessions = 0;
    $keptrelationids = [];

    foreach ($sessionssorted as $s) {
        $sid = (int)$s->id;
        $expected = (int)$s->sessdate - 600;
        $rows = $relsbysession[$sid] ?? [];
        if (count($rows) > 1) {
            $sessionduplicates++;
        }

        $bestevent = gmk_bbb_mc_choose_best_event($events, $expected, $usedevents, $maxdeltasecs);
        if ($bestevent) {
            $usedevents[(int)$bestevent->id] = true;
        }

        $keeprow = null;
        if (!empty($rows)) {
            // Prefer row that already points to selected best event.
            if ($bestevent) {
                foreach ($rows as $r) {
                    if ((int)$r->bbbid === (int)$bestevent->instance) {
                        $keeprow = $r;
                        break;
                    }
                }
            }

            // Fallback to closest row by event timestamp.
            if (!$keeprow) {
                $bestrowdiff = PHP_INT_MAX;
                foreach ($rows as $r) {
                    $cand = null;
                    $inst = (int)$r->bbbid;
                    if ($inst > 0 && !empty($eventbyinstance[$inst])) {
                        $cand = $eventbyinstance[$inst][0];
                    }
                    $diff = $cand ? abs((int)$cand->timestart - $expected) : PHP_INT_MAX;
                    if ($diff < $bestrowdiff) {
                        $bestrowdiff = $diff;
                        $keeprow = $r;
                    } else if ($diff === $bestrowdiff && $keeprow && (int)$r->id > (int)$keeprow->id) {
                        $keeprow = $r;
                    }
                }
            }
        }

        if ($keeprow) {
            $keptrelationids[(int)$keeprow->id] = true;
            if ($bestevent) {
                $newinstance = (int)$bestevent->instance;
                $newcmid = (int)($cmidbyinstance[$newinstance] ?? 0);
                if ($newcmid > 0 && ((int)$keeprow->bbbid !== $newinstance || (int)$keeprow->bbbmoduleid !== $newcmid)) {
                    $updates[] = [
                        'relationid' => (int)$keeprow->id,
                        'newbbbid' => $newinstance,
                        'newbbbmoduleid' => $newcmid,
                        'oldbbbid' => (int)$keeprow->bbbid,
                        'oldbbbmoduleid' => (int)$keeprow->bbbmoduleid,
                        'sessionid' => $sid,
                    ];
                    if (!empty($keeprow->bbbmoduleid)) {
                        $oldcmids[(int)$keeprow->bbbmoduleid] = (int)$keeprow->bbbmoduleid;
                    }
                    if (!empty($keeprow->bbbid)) {
                        $oldinstances[(int)$keeprow->bbbid] = (int)$keeprow->bbbid;
                    }
                    $mismatchrows++;
                    $fixablesessions++;
                }
            }

            foreach ($rows as $r) {
                if ((int)$r->id === (int)$keeprow->id) {
                    continue;
                }
                $deleterelids[(int)$r->id] = (int)$r->id;
                if (!empty($r->bbbmoduleid)) {
                    $oldcmids[(int)$r->bbbmoduleid] = (int)$r->bbbmoduleid;
                }
                if (!empty($r->bbbid)) {
                    $oldinstances[(int)$r->bbbid] = (int)$r->bbbid;
                }
                $fixablesessions++;
            }
        }
    }

    foreach ($orphanrels as $r) {
        $deleterelids[(int)$r->id] = (int)$r->id;
        if (!empty($r->bbbmoduleid)) {
            $oldcmids[(int)$r->bbbmoduleid] = (int)$r->bbbmoduleid;
        }
        if (!empty($r->bbbid)) {
            $oldinstances[(int)$r->bbbid] = (int)$r->bbbid;
        }
    }

    // Detect duplicate BBB events in the same visible slot.
    $slotdupcount = 0;
    $slotseen = [];
    foreach ($events as $e) {
        $k = (int)$e->timestart . '|' . (int)$e->timeduration;
        if (!isset($slotseen[$k])) {
            $slotseen[$k] = 0;
        }
        $slotseen[$k]++;
    }
    foreach ($slotseen as $cnt) {
        if ($cnt > 1) {
            $slotdupcount++;
        }
    }

    // Additional stale events not selected by attendance match can be deleted if they are not referenced.
    $selectedinstances = [];
    foreach ($updates as $u) {
        $selectedinstances[(int)$u['newbbbid']] = (int)$u['newbbbid'];
    }
    foreach ($rels as $r) {
        $rid = (int)$r->id;
        if (isset($deleterelids[$rid])) {
            continue;
        }
        if (isset($keptrelationids[$rid]) && !empty($r->bbbid)) {
            $selectedinstances[(int)$r->bbbid] = (int)$r->bbbid;
        }
    }

    $extracmids = [];
    foreach ($events as $e) {
        $inst = (int)$e->instance;
        if (isset($selectedinstances[$inst])) {
            continue;
        }
        $cmid = (int)($cmidbyinstance[$inst] ?? 0);
        if ($cmid > 0) {
            $extracmids[$cmid] = $cmid;
        }
    }

    $hasissues = (!empty($deleterelids) || !empty($updates) || $slotdupcount > 0 || !empty($extracmids));

    return [
        'class' => $class,
        'summary' => [
            'relrows' => count($rels),
            'sessions' => count($sessions),
            'orphan_rel_rows' => count($orphanrels),
            'duplicate_session_groups' => $sessionduplicates,
            'mismatch_rows' => $mismatchrows,
            'slot_duplicate_groups' => $slotdupcount,
            'updates' => count($updates),
            'delete_rel_rows' => count($deleterelids),
            'extra_cmid_candidates' => count($extracmids),
            'fixable_sessions' => $fixablesessions,
            'has_issues' => $hasissues ? 1 : 0,
        ],
        'plan' => [
            'updates' => $updates,
            'delete_rel_ids' => array_values($deleterelids),
            'old_cmids' => array_values($oldcmids),
            'extra_cmids' => array_values($extracmids),
        ],
    ];
}

function gmk_bbb_mc_apply_plan(array $analysis, bool $deletemodules): array {
    global $DB, $CFG;
    $class = $analysis['class'];
    $plan = $analysis['plan'];
    $result = [
        'classid' => (int)$class->id,
        'classname' => (string)$class->name,
        'updated' => 0,
        'deleted_rel' => 0,
        'deleted_cm' => 0,
        'errors' => [],
        'logs' => [],
    ];

    $result['logs'][] = 'Start at ' . userdate(time(), '%Y-%m-%d %H:%M:%S')
        . ' class=' . (int)$class->id . ' name=' . (string)$class->name;
    $result['logs'][] = 'Plan updates=' . count($plan['updates'])
        . ' delete_rel=' . count($plan['delete_rel_ids'])
        . ' old_cmids=' . count($plan['old_cmids'])
        . ' extra_cmids=' . count($plan['extra_cmids']);

    $tx = $DB->start_delegated_transaction();
    try {
        foreach ($plan['updates'] as $u) {
            $row = (object)[
                'id' => (int)$u['relationid'],
                'bbbid' => (int)$u['newbbbid'],
                'bbbmoduleid' => (int)$u['newbbbmoduleid'],
            ];
            $DB->update_record('gmk_bbb_attendance_relation', $row);
            $result['updated']++;
            $result['logs'][] = 'Updated relation id=' . (int)$u['relationid']
                . ' session=' . (int)$u['sessionid']
                . ' bbbid ' . (int)$u['oldbbbid'] . ' -> ' . (int)$u['newbbbid']
                . ' cmid ' . (int)$u['oldbbbmoduleid'] . ' -> ' . (int)$u['newbbbmoduleid'];
        }

        foreach ($plan['delete_rel_ids'] as $rid) {
            $DB->delete_records('gmk_bbb_attendance_relation', ['id' => (int)$rid]);
            $result['deleted_rel']++;
            $result['logs'][] = 'Deleted relation id=' . (int)$rid;
        }

        // Keep class.bbbmoduleids aligned with relation table.
        $cmrows = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => (int)$class->id], '', 'bbbmoduleid');
        $cmids = [];
        foreach ($cmrows as $r) {
            if (!empty($r->bbbmoduleid)) {
                $cmids[(int)$r->bbbmoduleid] = (int)$r->bbbmoduleid;
            }
        }
        $classupd = (object)[
            'id' => (int)$class->id,
            'bbbmoduleids' => empty($cmids) ? null : implode(',', array_values($cmids)),
        ];
        $DB->update_record('gmk_class', $classupd);
        $result['logs'][] = 'Updated gmk_class.bbbmoduleids=' . (empty($classupd->bbbmoduleids) ? 'NULL' : $classupd->bbbmoduleids);

        $tx->allow_commit();
        $result['logs'][] = 'DB transaction committed';
    } catch (Throwable $t) {
        $tx->rollback($t);
        $result['errors'][] = 'DB plan apply failed: ' . $t->getMessage();
        $result['logs'][] = 'ERROR DB plan apply failed: ' . $t->getMessage();
        return $result;
    }

    if ($deletemodules) {
        $bbbmoduleid = gmk_bbb_mc_bbb_module_id();
        $deletecandidates = array_values(array_unique($plan['old_cmids']));
        foreach ($deletecandidates as $cmid) {
            $cmid = (int)$cmid;
            if ($cmid <= 0) {
                continue;
            }
            if ($DB->record_exists('gmk_bbb_attendance_relation', ['bbbmoduleid' => $cmid])) {
                $result['logs'][] = 'Skipped cmid=' . $cmid . ' still referenced in relation';
                continue;
            }

            $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,module', IGNORE_MISSING);
            if (!$cm) {
                $result['logs'][] = 'Skipped cmid=' . $cmid . ' not found in course_modules';
                continue;
            }
            if ((int)$cm->module !== (int)$bbbmoduleid) {
                $result['logs'][] = 'Skipped cmid=' . $cmid . ' not BBB module';
                continue;
            }
            if ((int)$cm->course !== (int)$class->corecourseid) {
                $result['logs'][] = 'Skipped cmid=' . $cmid . ' course mismatch';
                continue;
            }

            try {
                course_delete_module($cmid, false);
                $result['deleted_cm']++;
                $result['logs'][] = 'Deleted BBB cmid=' . $cmid;
            } catch (Throwable $de) {
                $result['errors'][] = 'cmid ' . $cmid . ': ' . $de->getMessage();
                $result['logs'][] = 'ERROR deleting cmid=' . $cmid . ': ' . $de->getMessage();
            }
        }
    }

    try {
        if (!function_exists('rebuild_course_cache')) {
            require_once($CFG->libdir . '/modinfolib.php');
        }
        rebuild_course_cache((int)$class->corecourseid, true);
        $result['logs'][] = 'rebuild_course_cache done for course=' . (int)$class->corecourseid;
    } catch (Throwable $ce) {
        $result['errors'][] = 'cache rebuild: ' . $ce->getMessage();
        $result['logs'][] = 'ERROR cache rebuild: ' . $ce->getMessage();
    }

    $result['logs'][] = 'Finish updated=' . (int)$result['updated']
        . ' deleted_rel=' . (int)$result['deleted_rel']
        . ' deleted_cm=' . (int)$result['deleted_cm']
        . ' errors=' . count($result['errors']);

    return $result;
}

echo $OUTPUT->header();

echo '<style>
  .mc-card { background:#f8f9fa; border-left:4px solid #2c7be5; padding:10px; margin:10px 0; }
  .mc-table { width:100%; border-collapse:collapse; font-size:12px; background:#fff; }
  .mc-table th { background:#212529; color:#fff; text-align:left; padding:7px; border:1px solid #495057; }
  .mc-table td { padding:6px; border:1px solid #dee2e6; vertical-align:top; }
  .mc-ok { color:#198754; font-weight:700; }
  .mc-bad { color:#dc3545; font-weight:700; }
  .mc-warn { color:#996c00; font-weight:700; }
</style>';

echo '<h3>Mass BBB duplicate detection and cleanup</h3>';
echo '<div class="mc-card">Compares BBB events against attendance sessions and keeps the closest event per session.</div>';

$periodtable = gmk_bbb_mc_period_table();
$periods = [];
if ($periodtable !== '') {
    $periods = $DB->get_records_sql("SELECT id, name FROM {" . $periodtable . "} ORDER BY id DESC");
}

echo '<form method="get" class="mc-card">';
echo '<label>Period: <select name="periodid"><option value="0">All</option>';
foreach ($periods as $p) {
    $sel = ((int)$periodid === (int)$p->id) ? ' selected' : '';
    echo '<option value="' . (int)$p->id . '"' . $sel . '>' . gmk_bbb_mc_h((int)$p->id . ' - ' . (string)$p->name) . '</option>';
}
echo '</select></label> ';
echo '<label style="margin-left:10px">Class ID: <input type="number" name="classid" value="' . (int)$classid . '" style="width:90px"></label> ';
echo '<label style="margin-left:10px">Max classes: <input type="number" name="maxclasses" min="1" max="5000" value="' . (int)$maxclasses . '" style="width:90px"></label> ';
echo '<label style="margin-left:10px">Max delta hours: <input type="number" name="maxdeltahours" min="1" max="240" value="' . (int)$maxdeltahours . '" style="width:90px"></label> ';
echo '<label style="margin-left:10px"><input type="checkbox" name="showonlyissues" value="1" ' . (!empty($showonlyissues) ? 'checked' : '') . '> Show only issues</label> ';
echo '<label style="margin-left:10px"><input type="checkbox" name="deletemodules" value="1" ' . (!empty($deletemodules) ? 'checked' : '') . '> Delete orphan BBB modules</label> ';
echo '<button type="submit" class="btn btn-primary" style="margin-left:10px">Analyze</button>';
echo '</form>';

$maxclasses = max(1, min(5000, (int)$maxclasses));
$maxdeltasecs = max(3600, min(240 * 3600, (int)$maxdeltahours * 3600));

$sql = "SELECT c.id, c.name, c.periodid, c.corecourseid, c.groupid, c.learningplanid, c.approved, c.closed, c.attendancemoduleid,
               COUNT(r.id) AS relcount
          FROM {gmk_class} c
          JOIN {gmk_bbb_attendance_relation} r ON r.classid = c.id
         WHERE 1 = 1";
$params = [];
if ($periodid > 0) {
    $sql .= " AND c.periodid = :periodid";
    $params['periodid'] = (int)$periodid;
}
if ($classid > 0) {
    $sql .= " AND c.id = :classid";
    $params['classid'] = (int)$classid;
}
$sql .= " GROUP BY c.id, c.name, c.periodid, c.corecourseid, c.groupid, c.learningplanid, c.approved, c.closed, c.attendancemoduleid
          ORDER BY c.id DESC";

$classrows = $DB->get_records_sql($sql, $params, 0, $maxclasses);
$analyses = [];
$summary = [
    'classes_scanned' => 0,
    'classes_with_issues' => 0,
    'total_updates' => 0,
    'total_delete_rel' => 0,
    'total_extra_cm' => 0,
];

foreach ($classrows as $c) {
    $summary['classes_scanned']++;
    $analysis = gmk_bbb_mc_analyze_class($c, $maxdeltasecs);
    $hasissues = !empty($analysis['summary']['has_issues']);
    if ($hasissues) {
        $summary['classes_with_issues']++;
        $summary['total_updates'] += (int)$analysis['summary']['updates'];
        $summary['total_delete_rel'] += (int)$analysis['summary']['delete_rel_rows'];
        $summary['total_extra_cm'] += (int)$analysis['summary']['extra_cmid_candidates'];
    }
    if (!$showonlyissues || $hasissues) {
        $analyses[(int)$c->id] = $analysis;
    }
}

$applyresults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'repair_selected') {
    require_sesskey();
    $selected = optional_param_array('selected', [], PARAM_INT);
    $repairone = optional_param('repairone', 0, PARAM_INT);
    if ($repairone > 0) {
        $selected = [$repairone];
    }
    if (empty($selected)) {
        echo '<div class="mc-card"><span class="mc-warn">No classes selected for repair.</span></div>';
    } else {
    foreach ($selected as $sid) {
        $sid = (int)$sid;
        if (!isset($analyses[$sid])) {
                $classrow = $DB->get_record('gmk_class', ['id' => $sid], '*', IGNORE_MISSING);
                if ($classrow) {
                    $classrow = (object)[
                        'id' => (int)$classrow->id,
                        'name' => (string)$classrow->name,
                        'periodid' => (int)$classrow->periodid,
                        'corecourseid' => (int)$classrow->corecourseid,
                        'groupid' => (int)$classrow->groupid,
                        'learningplanid' => (int)$classrow->learningplanid,
                        'approved' => (int)$classrow->approved,
                        'closed' => (int)$classrow->closed,
                        'attendancemoduleid' => (int)$classrow->attendancemoduleid,
                    ];
                    $periodname = gmk_bbb_mc_period_name((int)$classrow->periodid);
                    if ($periodname !== '') {
                        $classrow->periodname = $periodname;
                    }
                    $analyses[$sid] = gmk_bbb_mc_analyze_class($classrow, $maxdeltasecs);
                } else {
                    $applyresults[] = [
                        'classid' => $sid,
                        'classname' => '',
                        'updated' => 0,
                        'deleted_rel' => 0,
                        'deleted_cm' => 0,
                        'errors' => ['Class not found'],
                        'logs' => ['ERROR class not found id=' . $sid],
                    ];
                    continue;
            }
        }
        if (empty($analyses[$sid]['summary']['has_issues'])) {
            $applyresults[] = [
                'classid' => $sid,
                'classname' => (string)($analyses[$sid]['class']->name ?? ''),
                'updated' => 0,
                'deleted_rel' => 0,
                'deleted_cm' => 0,
                'errors' => [],
                'logs' => ['No issues detected for this class; nothing to change.'],
            ];
            continue;
        }
        $applyresults[] = gmk_bbb_mc_apply_plan($analyses[$sid], !empty($deletemodules));
    }
}
}

echo '<div class="mc-card">';
echo 'Classes scanned: <strong>' . (int)$summary['classes_scanned'] . '</strong> | ';
echo 'With issues: <strong>' . (int)$summary['classes_with_issues'] . '</strong> | ';
echo 'Planned relation updates: <strong>' . (int)$summary['total_updates'] . '</strong> | ';
echo 'Planned relation deletes: <strong>' . (int)$summary['total_delete_rel'] . '</strong> | ';
echo 'Orphan module candidates: <strong>' . (int)$summary['total_extra_cm'] . '</strong>';
echo '</div>';

if (!empty($applyresults)) {
    echo '<div class="mc-card"><strong>Repair results</strong></div>';
    echo '<table class="mc-table"><thead><tr>'
        . '<th>Class</th><th>Updated rows</th><th>Deleted relation rows</th><th>Deleted modules</th><th>Errors</th><th>Log</th>'
        . '</tr></thead><tbody>';
    foreach ($applyresults as $r) {
        echo '<tr>';
        echo '<td>' . (int)$r['classid'] . ' ' . gmk_bbb_mc_h((string)($r['classname'] ?? '')) . '</td>';
        echo '<td>' . (int)$r['updated'] . '</td>';
        echo '<td>' . (int)$r['deleted_rel'] . '</td>';
        echo '<td>' . (int)$r['deleted_cm'] . '</td>';
        echo '<td>' . gmk_bbb_mc_h(empty($r['errors']) ? '-' : implode(' | ', $r['errors'])) . '</td>';
        $logtxt = !empty($r['logs']) ? implode("\n", $r['logs']) : '-';
        echo '<td><pre style="white-space:pre-wrap;max-width:480px;margin:0;">' . gmk_bbb_mc_h($logtxt) . '</pre></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo '<form method="post">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="action" value="repair_selected">';
echo '<input type="hidden" name="periodid" value="' . (int)$periodid . '">';
echo '<input type="hidden" name="classid" value="' . (int)$classid . '">';
echo '<input type="hidden" name="maxclasses" value="' . (int)$maxclasses . '">';
echo '<input type="hidden" name="maxdeltahours" value="' . (int)$maxdeltahours . '">';
echo '<input type="hidden" name="showonlyissues" value="' . (!empty($showonlyissues) ? '1' : '0') . '">';
echo '<input type="hidden" name="deletemodules" value="' . (!empty($deletemodules) ? '1' : '0') . '">';
echo '<input type="hidden" name="repairone" value="0">';

echo '<table class="mc-table"><thead><tr>'
    . '<th>Select</th><th>Class</th><th>Period</th><th>Core course</th><th>Group</th>'
    . '<th>rel rows</th><th>sessions</th><th>dup session groups</th><th>mismatch rows</th>'
    . '<th>updates</th><th>delete rel</th><th>extra cm</th><th>Status</th><th>Action</th>'
    . '</tr></thead><tbody>';

if (empty($analyses)) {
    echo '<tr><td colspan="14">No rows</td></tr>';
} else {
    foreach ($analyses as $cid => $a) {
        $c = $a['class'];
        $s = $a['summary'];
        $hasissues = !empty($s['has_issues']);
        echo '<tr>';
        echo '<td>';
        if ($hasissues) {
            echo '<input type="checkbox" name="selected[]" value="' . (int)$cid . '">';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '<td>' . (int)$c->id . ' - ' . gmk_bbb_mc_h((string)$c->name) . '</td>';
        echo '<td>' . (int)$c->periodid . '</td>';
        echo '<td>' . (int)$c->corecourseid . '</td>';
        echo '<td>' . (int)$c->groupid . '</td>';
        echo '<td>' . (int)$s['relrows'] . '</td>';
        echo '<td>' . (int)$s['sessions'] . '</td>';
        echo '<td>' . (int)$s['duplicate_session_groups'] . '</td>';
        echo '<td>' . (int)$s['mismatch_rows'] . '</td>';
        echo '<td>' . (int)$s['updates'] . '</td>';
        echo '<td>' . (int)$s['delete_rel_rows'] . '</td>';
        echo '<td>' . (int)$s['extra_cmid_candidates'] . '</td>';
        echo '<td>' . ($hasissues ? '<span class="mc-bad">ISSUE</span>' : '<span class="mc-ok">OK</span>') . '</td>';
        echo '<td>';
        if ($hasissues) {
            echo '<button type="submit" name="repairone" value="' . (int)$cid . '" class="btn btn-warning btn-sm"'
                . ' onclick="return confirm(\'Repair this class only?\');">Repair this class</button>';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '</tr>';
    }
}

echo '</tbody></table>';
echo '<div class="mc-card">';
echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Apply repair to selected classes?\');">Repair selected classes</button>';
echo '</div>';
echo '</form>';

echo $OUTPUT->footer();
