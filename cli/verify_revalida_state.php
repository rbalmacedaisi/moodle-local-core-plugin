<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

$classid = 9575;
$sessionid = 7827;
echo "Class $classid, session $sessionid (revalida).\n";

// Enrolled
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
echo "Enrolled: " . count($enrolled) . "\n";

// Log rows for this session
$rows = $DB->get_records_sql(
    "SELECT l.studentid, ast.acronym, COALESCE(ast.grade, 0) AS grade
       FROM {attendance_log} l
  LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid
      WHERE l.sessionid = :sid",
    ['sid' => $sessionid]
);
$present = 0;
$absent  = 0;
foreach ($rows as $r) {
    if ((float)$r->grade > 0) $present++;
    else $absent++;
}
echo "Log rows: " . count($rows) . " (present=$present, absent=$absent)\n";

// Show a few sample users that previously were FI
echo "\nSample student status for this revalida session:\n";
$sampleIds = array_slice($enrolled, 0, 5);
foreach ($sampleIds as $uid) {
    $row = $DB->get_record_sql(
        "SELECT l.id AS logid, ast.acronym, COALESCE(ast.grade, 0) AS grade, l.timetaken
           FROM {attendance_log} l
      LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid
          WHERE l.sessionid = :sid AND l.studentid = :uid",
        ['sid' => $sessionid, 'uid' => $uid]
    );
    if ($row) {
        printf("  user=%-5d  status=%-3s (grade=%.0f) timetaken=%s\n",
            $uid, $row->acronym, $row->grade, gmdate('Y-m-d H:i', $row->timetaken));
    } else {
        printf("  user=%-5d  (no log row)\n", $uid);
    }
}