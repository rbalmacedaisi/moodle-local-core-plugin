<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

echo "Classes with revalida sessions that are PAST:\n";
$rows = $DB->get_records_sql(
    "SELECT DISTINCT r.classid, s.sessdate, s.duration, s.lasttaken
       FROM {attendance_sessions} s
       JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
      WHERE COALESCE(s.is_revalida, 0) = 1
        AND s.sessdate + s.duration < :now
   ORDER BY r.classid
      LIMIT 30",
    ['now' => time()]
);
$i = 0;
foreach ($rows as $r) {
    $i++;
    printf("  [%d] class=%d  sessdate=%s  lasttaken=%d\n",
        $i, $r->classid, gmdate('Y-m-d H:i', $r->sessdate), $r->lasttaken);
}
