<?php
/**
 * Verification script: compare attendance % per class BEFORE and AFTER
 * the is_revalida filter. Picks several classes that contain revalida
 * sessions and shows the difference.
 *
 * SELECT-only diagnostic. Run via:
 *   php cli/verify_phase1.php
 *   php cli/verify_phase1.php --classid=9575
 *   php cli/verify_phase1.php --limit=10
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
global $DB;

$options = getopt('', ['classid::', 'limit::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php cli/verify_phase1.php [--classid=N] [--limit=N]\n";
    exit(0);
}

$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 10;

if (!empty($options['classid'])) {
    $classIds = [(int)$options['classid']];
} else {
    // Take classes that have at least one is_revalida=1 session.
    $classIds = $DB->get_fieldset_sql(
        "SELECT DISTINCT r.classid
           FROM {attendance_sessions} s
           JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
          WHERE s.is_revalida = 1
       ORDER BY r.classid DESC
          LIMIT $limit"
    );
}

if (empty($classIds)) {
    echo "No classes with is_revalida sessions found.\n";
    exit(0);
}

foreach ($classIds as $cid) {
    $class = $DB->get_record('gmk_class', ['id' => $cid], 'id, name, groupid, corecourseid, attendancemoduleid');
    if (!$class) { echo "class $cid not found\n"; continue; }
    // Resolve the attendance.id the same way the live code does:
    //  - Prefer the row in gmk_bbb_attendance_relation (attendanceid column).
    //  - Fallback: get the attendance instance from the course_module.
    $rel = $DB->get_record_sql(
        "SELECT id, attendancesessionid, bbbmoduleid, attendancemoduleid, attendanceid, classid
           FROM {gmk_bbb_attendance_relation}
          WHERE classid = :cid
          LIMIT 1",
        ['cid' => $cid]
    );
    $attId = $rel ? (int)$rel->attendanceid : 0;
    if ($attId <= 0 && !empty($class->attendancemoduleid)) {
        $attId = (int)$DB->get_field('course_modules', 'instance', ['id' => (int)$class->attendancemoduleid]);
    }
    if ($attId <= 0) {
        // Final fallback: search by course.
        $attId = (int)$DB->get_field_sql(
            "SELECT cm.instance FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'attendance'
              WHERE cm.course = :cid LIMIT 1",
            ['cid' => (int)$class->corecourseid]
        );
    }
    if ($attId <= 0) {
        echo "[class $cid] '{$class->name}' - could not resolve attendanceid.\n";
        continue;
    }

    echo "\n=== class $cid '{$class->name}' (attendance=$attId) ===\n";

    // Pick 3 sample students.
    $users = $DB->get_fieldset_sql(
        "SELECT userid FROM {gmk_course_progre} WHERE classid = :cid AND userid IS NOT NULL GROUP BY userid LIMIT 3",
        ['cid' => $cid]
    );

    foreach ($users as $uid) {
        // OLD calculation (without is_revalida filter).
        $before = $DB->get_record_sql(
            "SELECT COUNT(s.id) AS total,
                    SUM(CASE WHEN al.id IS NOT NULL AND ast.grade > 0 THEN 1 ELSE 0 END) AS present
               FROM {attendance_sessions} s
               LEFT JOIN {attendance_log} al ON al.sessionid = s.id AND al.studentid = :uid
               LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
              WHERE s.attendanceid = :attid
                AND s.sessdate + s.duration < :now
                AND (EXISTS (SELECT 1 FROM {attendance_log} l WHERE l.sessionid = s.id)
                     OR COALESCE(s.lasttaken, 0) > 0)",
            ['uid' => $uid, 'attid' => $attId, 'now' => time()]
        );
        $oldTot = (int)($before->total ?? 0);
        $oldPres = (int)($before->present ?? 0);
        $oldPct = $oldTot > 0 ? round(($oldPres / $oldTot) * 100, 1) : null;
        $oldGrade = $oldTot > 0 ? round(($oldPres / $oldTot) * 100, 2) : null;

        // NEW calculation (with is_revalida filter).
        $after = $DB->get_record_sql(
            "SELECT COUNT(s.id) AS total,
                    SUM(CASE WHEN al.id IS NOT NULL AND ast.grade > 0 THEN 1 ELSE 0 END) AS present
               FROM {attendance_sessions} s
               LEFT JOIN {attendance_log} al ON al.sessionid = s.id AND al.studentid = :uid
               LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
              WHERE s.attendanceid = :attid
                AND COALESCE(s.is_revalida, 0) = 0
                AND s.sessdate + s.duration < :now
                AND (EXISTS (SELECT 1 FROM {attendance_log} l WHERE l.sessionid = s.id)
                     OR COALESCE(s.lasttaken, 0) > 0)",
            ['uid' => $uid, 'attid' => $attId, 'now' => time()]
        );
        $newTot = (int)($after->total ?? 0);
        $newPres = (int)($after->present ?? 0);
        $newPct = $newTot > 0 ? round(($newPres / $newTot) * 100, 1) : null;
        $newGrade = $newTot > 0 ? round(($newPres / $newTot) * 100, 2) : null;

        printf("  user=%d : BEFORE total=%d pres=%d pct=%s  ->  AFTER total=%d pres=%d pct=%s\n",
            $uid,
            $oldTot, $oldPres, $oldPct ?? 'n/a',
            $newTot, $newPres, $newPct ?? 'n/a'
        );
    }
}
