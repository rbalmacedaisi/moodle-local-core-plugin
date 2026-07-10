<?php
/**
 * Spot check: pick a class with a revalida session and confirm the count
 * dropped by 1 (or more) after the recompute.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
global $DB;

$classid = (int)($argv[1] ?? 9676);
echo "Class: $classid\n\n";

$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
$nowts = time();

// 1. Count the session ids WITHOUT revalida filter (old behavior).
$pastsessionidsOld = [];
$attid = (int)$DB->get_field_sql(
    "SELECT attendanceid FROM {gmk_bbb_attendance_relation} WHERE classid = :cid LIMIT 1",
    ['cid' => $classid]
);
if ($attid > 0) {
    $pastsessionidsOld = array_keys($DB->get_records_sql(
        "SELECT s.id FROM {attendance_sessions} s
          WHERE s.attendanceid = :attid
            AND s.sessdate + s.duration < :now
            AND (EXISTS (SELECT 1 FROM {attendance_log} l WHERE l.sessionid = s.id)
                 OR COALESCE(s.lasttaken, 0) > 0)",
        ['attid' => $attid, 'now' => $nowts]
    ));
}

// 2. Count WITH the new filter.
$pastsessionidsNew = absd_get_class_past_session_ids($class, $nowts);
$takensessionidsNew = absd_get_taken_session_ids($pastsessionidsNew);
$revalidaInOld = count($pastsessionidsOld) - count($pastsessionidsNew);

printf("Sessions before revalida filter: %d\n", count($pastsessionidsOld));
printf("Sessions after revalida filter : %d (diff = -%d)\n", count($pastsessionidsNew), $revalidaInOld);
printf("Taken after revalida filter     : %d\n", count($takensessionidsNew));

// 3. For 3 students, show count before/after the recompute.
$students = $DB->get_fieldset_sql(
    "SELECT userid FROM {gmk_course_progre} WHERE classid = :cid GROUP BY userid LIMIT 5",
    ['cid' => $classid]
);
echo "\nPer-student absence count comparison (old method vs new helper):\n";
foreach ($students as $uid) {
    // Old: take all past sessions, count present in attendance_log.
    $oldPres = 0;
    if (!empty($pastsessionidsOld)) {
        list($sessin, $sp) = $DB->get_in_or_equal($pastsessionidsOld, SQL_PARAMS_NAMED, 'os');
        $row = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT s.id) AS total,
                    SUM(CASE WHEN al.id IS NOT NULL AND ast.grade > 0 THEN 1 ELSE 0 END) AS present
               FROM {attendance_sessions} s
               LEFT JOIN {attendance_log} al      ON al.sessionid = s.id AND al.studentid = :uid
               LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
              WHERE s.id $sessin",
            ['uid' => $uid] + $sp
        );
        $oldAbs = (int)($row->total ?? 0) - (int)($row->present ?? 0);
    } else {
        $oldAbs = 0;
    }
    $newAbs = absd_get_student_absences($takensessionidsNew, [$uid])[$uid] ?? 0;
    printf("  user=%d : old=%d  new=%d  diff=%d\n", $uid, $oldAbs, $newAbs, $oldAbs - $newAbs);
}
