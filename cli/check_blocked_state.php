<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

echo "gmk_course_progre rows with blocked_by_absence = 1 (after recompute):\n";
$rows = $DB->get_records_sql(
    "SELECT gcp.id, gcp.userid, gcp.classid, gcp.status,
            gcp.blocked_by_absence, gcp.blocked_by_absence_at,
            u.firstname, u.lastname, gc.name AS classname
       FROM {gmk_course_progre} gcp
       JOIN {user} u       ON u.id = gcp.userid
       JOIN {gmk_class} gc ON gc.id = gcp.classid
      WHERE gcp.blocked_by_absence = 1
   ORDER BY gc.id, u.id
      LIMIT 20"
);
foreach ($rows as $r) {
    printf("  user=%d (%s %s) class=%d (%s) blocked_by_absence=%d at=%s\n",
        $r->userid, $r->firstname, $r->lastname,
        $r->classid, substr($r->classname ?? '', 0, 40),
        $r->blocked_by_absence, !empty($r->blocked_by_absence_at) ? gmdate('Y-m-d H:i', $r->blocked_by_absence_at) : '0'
    );
}
echo "\nTotal: " . count($rows) . " (showing first 20)\n";
echo "\nIs the field still 0 for users who were unblocked by the recompute?\n";
$stillBlocked = $DB->count_records('gmk_course_progre', ['blocked_by_absence' => 1]);
echo "gmk_course_progre.blocked_by_absence = 1 total: $stillBlocked\n";
