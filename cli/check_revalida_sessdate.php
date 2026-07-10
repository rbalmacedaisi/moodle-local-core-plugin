<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

$classid = (int)($argv[1] ?? 9676);
$sess = $DB->get_record_sql(
    "SELECT s.id, s.sessdate, s.duration, s.is_revalida, s.lasttaken
       FROM {attendance_sessions} s
       JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
      WHERE r.classid = :cid AND COALESCE(s.is_revalida, 0) = 1
      LIMIT 1",
    ['cid' => $classid]
);
echo "Class $classid revalida session:\n";
if ($sess) {
    printf("  sess=%d  sessdate=%s  duration=%ds  lasttaken=%d  is_revalida=1\n",
        $sess->id, gmdate('Y-m-d H:i', $sess->sessdate),
        $sess->duration, $sess->lasttaken);
    printf("  end_ts=%s  now_ts=%s  is_past=%s\n",
        gmdate('Y-m-d H:i', $sess->sessdate + $sess->duration),
        gmdate('Y-m-d H:i', time()),
        (($sess->sessdate + $sess->duration) < time()) ? 'YES' : 'NO'
    );
} else {
    echo "  (no revalida session found)\n";
}
