<?php
/**
 * Bulk-fill attendance_log for revalida sessions of the Jul 6-11 week.
 *
 * Scope (confirmed by operator):
 *   For every (student, session) pair where the session has
 *   is_revalida=1 and falls in [Mon 06-Jul .. Sat 11-Jul 2026] (Panama
 *   time) AND the student is enrolled in the class, the script:
 *
 *     (a) INSERTS a new attendance_log row with the attendance module's
 *         highest-grade status (typically 'P' Presente, grade=100) when
 *         no row currently exists for that pair.
 *     (b) UPDATES the existing row to the same highest-grade status when
 *         the current status's grade is 0 (i.e., was marked as some kind
 *         of absence).
 *
 *   The script never touches rows whose current grade is already > 0.
 *   The script does NOT modify attendance_sessions or attendance_statuses.
 *
 * USAGE:
 *   php bulk_fill_revalida_attendance.php            # dry-run (preview)
 *   php bulk_fill_revalida_attendance.php --apply   # actually INSERT/UPDATE
 *   php bulk_fill_revalida_attendance.php --classid=9575  # scope to one class
 *
 * IMPORTANT: When --apply is set, the operation is NOT wrapped in a
 * single transaction (would lock too many rows); instead it commits in
 * small batches of 200 rows. A snapshot of every change is logged to
 *   <dataroot>/local_grupomakro_core/logs/revalida_fill_<utc>.log
 * so the operator can reverse with a hand-crafted UPDATE if needed.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
global $DB, $CFG;

$apply    = in_array('--apply', $argv, true);
$onlyClass = null;
foreach ($argv as $a) {
    if (preg_match('/^--classid=(\d+)$/', $a, $m)) {
        $onlyClass = (int)$m[1];
    }
}

$startTs = gmmktime(5, 0, 0, 7, 6, 2026);  // 06-Jul 00:00 Panama.
$endTs   = gmmktime(5, 0, 0, 7, 12, 2026); // 12-Jul 00:00 Panama (excl).
$now     = time();

echo "Window (Panama): Mon 06-Jul-2026 00:00 .. Sat 11-Jul-2026 23:59\n";
echo "Mode: " . ($apply ? 'APPLY (writes to DB)' : 'DRY-RUN (no writes)') . "\n";
if ($onlyClass) echo "Scoped to classid=$onlyClass\n";
echo "\n";

// 1. Find every revalida session in the window (optionally filtered by class).
$params = ['start' => $startTs, 'end' => $endTs];
$classFilter = '';
if ($onlyClass) {
    $classFilter = 'AND r.classid = :onlycls';
    $params['onlycls'] = $onlyClass;
}
$revalSessions = $DB->get_records_sql(
    "SELECT s.id AS sessionid, s.attendanceid, r.classid,
            s.sessdate, s.lasttaken,
            gc.name AS classname
       FROM {attendance_sessions} s
       JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
       JOIN {gmk_class} gc ON gc.id = r.classid
      WHERE COALESCE(s.is_revalida, 0) = 1
        AND s.sessdate >= :start
        AND s.sessdate <  :end
        $classFilter
   ORDER BY gc.id, s.sessdate",
    $params
);

if (empty($revalSessions)) {
    echo "No revalida sessions in the window match the criteria.\n";
    exit(0);
}
echo "Revalida sessions to process: " . count($revalSessions) . "\n\n";

// 2. For each session, resolve the highest-grade status_id and the
//    list of enrolled students.
$logDir = $CFG->dataroot . '/local_grupomakro_core/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/revalida_fill_' . gmdate('Ymd_His') . '.log';
$logFh = fopen($logFile, 'w');
fprintf($logFh, "# Phase 4 bulk-fill revalida attendance log\n");
fprintf($logFh, "# window_utc_start=%d window_utc_end=%d apply=%s\n",
    $startTs, $endTs, $apply ? 'yes' : 'no');
fprintf($logFh, "# sessionid\tclassid\tclassname\tstudentid\tstatusid\tgrade\toperation\n");

$stats = [
    'sessions_processed' => 0,
    'sessions_skipped_no_present_status' => 0,
    'inserts_planned'    => 0,
    'updates_planned'    => 0,
    'inserts_applied'    => 0,
    'updates_applied'    => 0,
    'errors'             => [],
];

foreach ($revalSessions as $rsess) {
    $sessionid  = (int)$rsess->sessionid;
    $attid      = (int)$rsess->attendanceid;
    $classid    = (int)$rsess->classid;
    $classname  = (string)($rsess->classname ?? '');

    // Resolve the "Present" status = highest-grade non-deleted status
    // belonging to this attendance module.
    $presentStatus = $DB->get_record_sql(
        "SELECT id, acronym, description, grade
           FROM {attendance_statuses}
          WHERE attendanceid = :aid
            AND deleted = 0
            AND grade > 0
       ORDER BY grade DESC, id ASC
          LIMIT 1",
        ['aid' => $attid]
    );
    if (!$presentStatus) {
        $stats['sessions_skipped_no_present_status']++;
        echo "  [SKIP] sess=$sessionid class=$classid — no positive-grade status defined\n";
        continue;
    }
    $presentStatusId = (int)$presentStatus->id;
    $presentGrade    = (float)$presentStatus->grade;

    // Enrolled students: prefer gmk_course_progre, fall back to groups_members.
    $enrolled = $DB->get_fieldset_sql(
        "SELECT userid FROM {gmk_course_progre} WHERE classid = :cid GROUP BY userid",
        ['cid' => $classid]
    );
    $groupid = (int)$DB->get_field('gmk_class', 'groupid', ['id' => $classid]);
    if ($groupid > 0) {
        $grp = $DB->get_fieldset_sql(
            "SELECT userid FROM {groups_members} WHERE groupid = :gid",
            ['gid' => $groupid]
        );
        $enrolled = array_values(array_unique(array_merge($enrolled ?: [], $grp ?: [])));
    }
    $enrolled = array_map('intval', $enrolled ?: []);
    if (empty($enrolled)) {
        echo "  [INFO] sess=$sessionid class=$classid — no enrolled students, skipping\n";
        continue;
    }

    // Current attendance_log studentids + their status grades.
    list($insql, $inparams) = $DB->get_in_or_equal($enrolled, SQL_PARAMS_NAMED, 'u');
    $cur = $DB->get_records_sql(
        "SELECT l.id AS logid, l.studentid, l.statusid, ast.grade
           FROM {attendance_log} l
      LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid
          WHERE l.sessionid = :sid
            AND l.studentid $insql",
        array_merge(['sid' => $sessionid], $inparams)
    );
    $present   = [];   // studentid => logid
    $absentLog = [];   // studentid => logid
    foreach ($cur as $row) {
        if ((float)($row->grade ?? 0) > 0) {
            $present[(int)$row->studentid] = (int)$row->logid;
        } else {
            $absentLog[(int)$row->studentid] = (int)$row->logid;
        }
    }

    // Students missing a log row. Merge the two associative arrays first
    // (they share studentid keys, so the union is keyed by studentid)
    // and then array_diff against $enrolled.
    $loggedSids = array_keys($present + $absentLog);
    $missing = array_values(array_diff($enrolled, $loggedSids));

    $stats['sessions_processed']++;
    $stats['inserts_planned'] += count($missing);
    $stats['updates_planned'] += count($absentLog);

    printf("  sess=%d class=%d (%s) d=%s present_status='%s'(grade=%.0f) | missing=%d already_absent=%d already_present=%d\n",
        $sessionid, $classid, substr($classname, 0, 40),
        gmdate('Y-m-d H:i', $rsess->sessdate),
        $presentStatus->acronym, $presentGrade,
        count($missing), count($absentLog), count($present));

    if (!$apply) {
        continue;
    }

    // APPLY: insert missing rows in batches of 200, then update absent rows.
    $inserted = 0;
    foreach (array_chunk($missing, 200) as $batch) {
        $records = [];
        foreach ($batch as $uid) {
            $rec = new stdClass();
            $rec->sessionid   = $sessionid;
            $rec->studentid   = (int)$uid;
            $rec->statusid    = $presentStatusId;
            $rec->statusset   = $presentStatusId;
            $rec->timetaken   = $now;
            $rec->takenby     = 0;
            $rec->remarks     = '';
            $records[] = $rec;
            fprintf($logFh, "%d\t%d\t%s\t%d\t%d\t%.0f\tINSERT\n",
                $sessionid, $classid, $classname,
                (int)$uid, $presentStatusId, $presentGrade);
        }
        if (!empty($records)) {
            try {
                $DB->insert_records('attendance_log', $records);
                $inserted += count($records);
            } catch (Throwable $e) {
                $stats['errors'][] = "INSERT sess=$sessionid batch=" . count($records) . " : " . $e->getMessage();
            }
        }
    }
    $stats['inserts_applied'] += $inserted;

    // UPDATE rows currently marked as absent (grade = 0). Use a direct
    // SQL with implode of logids (Moodle's get_in_or_equal adds one extra
    // placeholder vs parameter that throws off execute()).
    $updated = 0;
    foreach (array_chunk($absentLog, 200, true) as $batch) {
        $logIds = array_values($batch);
        if (empty($logIds)) continue;
        $logIdsInts = array_map('intval', $logIds);
        $idsList = implode(',', $logIdsInts);
        try {
            $DB->execute(
                "UPDATE {attendance_log}
                    SET statusid = ?, statusset = ?, timetaken = ?
                  WHERE id IN ($idsList)",
                [$presentStatusId, $presentStatusId, $now]
            );
            $updated += count($logIds);
        } catch (Throwable $e) {
            $stats['errors'][] = "UPDATE sess=$sessionid batch=" . count($logIds) . " : " . $e->getMessage();
        }
        foreach ($batch as $uid => $logid) {
            fprintf($logFh, "%d\t%d\t%s\t%d\t%d\t%.0f\tUPDATE logid=%d\n",
                $sessionid, $classid, $classname,
                (int)$uid, $presentStatusId, $presentGrade, $logid);
        }
    }
    $stats['updates_applied'] += $updated;

    // Set lasttaken so the session is marked as "tomada".
    if (count($inserted > 0 ? [$inserted] : []) > 0 || $updated > 0) {
        $DB->set_field('attendance_sessions', 'lasttaken', $now, ['id' => $sessionid]);
        $DB->set_field('attendance_sessions', 'lasttakenby', 0, ['id' => $sessionid]);
    }
}

fclose($logFh);

echo "\n=== Summary ===\n";
printf("Sessions processed                    : %d\n", $stats['sessions_processed']);
printf("Sessions skipped (no positive status) : %d\n", $stats['sessions_skipped_no_present_status']);
printf("Inserts planned                       : %d\n", $stats['inserts_planned']);
printf("Updates planned                       : %d\n", $stats['updates_planned']);
if ($apply) {
    printf("Inserts applied                       : %d\n", $stats['inserts_applied']);
    printf("Updates applied                       : %d\n", $stats['updates_applied']);
}
if (!empty($stats['errors'])) {
    echo "Errors:\n";
    foreach ($stats['errors'] as $err) echo "  - $err\n";
}
printf("\nAudit log: %s\n", $logFile);

if (!$apply) {
    echo "\nDRY-RUN only. Re-run with --apply to actually INSERT/UPDATE the rows.\n";
} else {
    echo "DONE.\n";
}
exit(0);