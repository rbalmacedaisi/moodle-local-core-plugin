<?php
/**
 * Phase 4 audit: for every class whose LAST attendance session falls in
 * [Mon 06-Jul-2026 00:00 .. Sat 11-Jul-2026 23:59] Panama time, list
 *  - the sessions in that week (and the revalida session in particular)
 *  - the enrolled students per session
 *  - the count of attendance_log rows already present
 *  - the gap (enrolled but no log)
 *
 * SELECT-only. Prints the full breakdown so the operator can decide whether
 * to backfill via cli/bulk_fill_attendance_revalida.php.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
global $DB;

$startTs = gmmktime(5, 0, 0, 7, 6, 2026);  // 06-Jul 00:00 Panama.
$endTs   = gmmktime(5, 0, 0, 7, 12, 2026); // 12-Jul 00:00 Panama (excl).

echo "Window (Panama): Mon 06-Jul-2026 00:00 .. Sat 11-Jul-2026 23:59\n\n";

// 1. Find every class whose LAST attendance session falls in the window.
echo "=== Classes whose LAST attendance session is in the window ===\n";
$classIds = $DB->get_fieldset_sql(
    "SELECT DISTINCT r.classid
       FROM {attendance_sessions} s
       JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
      WHERE s.sessdate >= :start
        AND s.sessdate <  :end
        AND s.sessdate = (
               SELECT MAX(s2.sessdate)
                 FROM {attendance_sessions} s2
                WHERE s2.attendanceid = s.attendanceid
            )
   ORDER BY r.classid",
    ['start' => $startTs, 'end' => $endTs]
);
echo "Found " . count($classIds) . " classes\n\n";

if (empty($classIds)) {
    echo "No classes to audit.\n";
    exit(0);
}

// 2. For each class, list the sessions in the window, enrolled students
//    and the attendance_log gap.
$totalEnrolled = 0;
$totalLogRows  = 0;
$totalGap      = 0;
$totalSessions = 0;

foreach ($classIds as $classid) {
    $class = $DB->get_record('gmk_class', ['id' => $classid], 'id, name', MUST_EXIST);

    // Resolve attendance_id via the relation table (most reliable).
    $attid = (int)$DB->get_field_sql(
        "SELECT attendanceid FROM {gmk_bbb_attendance_relation} WHERE classid = :cid LIMIT 1",
        ['cid' => $classid]
    );

    // Sessions in the window for this attendance module.
    $sessions = $DB->get_records_sql(
        "SELECT s.id, s.sessdate, s.duration, s.is_revalida, s.lasttaken
           FROM {attendance_sessions} s
          WHERE s.attendanceid = :attid
            AND s.sessdate >= :start
            AND s.sessdate <  :end
       ORDER BY s.sessdate ASC",
        ['attid' => $attid, 'start' => $startTs, 'end' => $endTs]
    );

    // Enrolled students (status 1 = Available, 2 = In progress, 3 = Completed
    // — include all of them so a student who finished the course is still
    // backfilled if they ever had a gap).
    $enrolledIds = $DB->get_fieldset_sql(
        "SELECT userid FROM {gmk_course_progre}
          WHERE classid = :cid GROUP BY userid",
        ['cid' => $classid]
    );
    // Also include anyone in the legacy groups_members, in case the progre
    // table wasn't updated.
    $groupUserIds = [];
    $groupid = (int)$DB->get_field('gmk_class', 'groupid', ['id' => $classid]);
    if ($groupid > 0) {
        $groupUserIds = $DB->get_fieldset_sql(
            "SELECT userid FROM {groups_members} WHERE groupid = :gid",
            ['gid' => $groupid]
        );
    }
    $allEnrolled = array_values(array_unique(array_map('intval',
        array_merge($enrolledIds ?: [], $groupUserIds ?: []))));
    $enrolledCount = count($allEnrolled);

    $sessionRows = [];
    foreach ($sessions as $sess) {
        // Existing attendance_log studentids for this session.
        $loggedIds = $DB->get_fieldset_sql(
            "SELECT DISTINCT studentid FROM {attendance_log} WHERE sessionid = :sid",
            ['sid' => (int)$sess->id]
        );
        $loggedIds = array_map('intval', $loggedIds ?: []);
        $missing = array_values(array_diff($allEnrolled, $loggedIds));

        $sessionRows[] = [
            'sessionid' => (int)$sess->id,
            'sessdate'  => (int)$sess->sessdate,
            'is_revalida' => (int)($sess->is_revalida ?? 0),
            'lasttaken'  => (int)$sess->lasttaken,
            'enrolled'   => $enrolledCount,
            'logged'     => count($loggedIds),
            'gap'        => count($missing),
        ];
    }

    $classEnrolled = $enrolledCount * count($sessions);
    $classLogged   = array_sum(array_column($sessionRows, 'logged'));
    $classGap      = array_sum(array_column($sessionRows, 'gap'));

    $totalEnrolled += $classEnrolled;
    $totalLogRows  += $classLogged;
    $totalGap      += $classGap;
    $totalSessions += count($sessions);

    echo sprintf("class=%d (%s) : %d sessions, %d enrolled\n",
        $classid, substr($class->name ?? '', 0, 50),
        count($sessions), $enrolledCount);
    foreach ($sessionRows as $sr) {
        printf("    sess=%d d=%s revalida=%d  enrolled=%d logged=%d gap=%d\n",
            $sr['sessionid'], gmdate('Y-m-d H:i', $sr['sessdate']),
            $sr['is_revalida'], $sr['enrolled'], $sr['logged'], $sr['gap']);
    }
    echo "\n";
}

echo "=== Totals across all affected classes ===\n";
printf("Sessions in window: %d\n", $totalSessions);
printf("Enrolled×sessions: %d\n", $totalEnrolled);
printf("Existing log rows: %d\n", $totalLogRows);
printf("Gap (enrolled but no log row): %d\n", $totalGap);