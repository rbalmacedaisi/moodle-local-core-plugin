<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

$classid = 9572;
$sessionid = 7723;

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
printf("enrolled count = %d\n", count($enrolled));
printf("first 5 enrolled = %s\n", implode(',', array_slice($enrolled, 0, 5)));

list($insql, $inparams) = $DB->get_in_or_equal($enrolled, SQL_PARAMS_NAMED, 'u');
$cur = $DB->get_records_sql(
    "SELECT l.id AS logid, l.studentid, l.statusid, ast.grade
       FROM {attendance_log} l
  LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid
      WHERE l.sessionid = :sid
        AND l.studentid $insql",
    array_merge(['sid' => $sessionid], $inparams)
);
printf("cur count = %d\n", count($cur));

$present = [];
$absentLog = [];
foreach ($cur as $row) {
    if ((float)($row->grade ?? 0) > 0) {
        $present[(int)$row->studentid] = (int)$row->logid;
    } else {
        $absentLog[(int)$row->studentid] = (int)$row->logid;
    }
}
printf("present = %d, absentLog = %d\n", count($present), count($absentLog));

$unionKeys = array_keys($present) + array_keys($absentLog);
$uniqueUnion = array_unique($unionKeys);
printf("union keys = %d (unique = %d)\n", count($unionKeys), count($uniqueUnion));

$missing = array_values(array_diff($enrolled, $uniqueUnion));
printf("missing = %d\n", count($missing));
if (!empty($missing)) {
    printf("  missing ids = %s\n", implode(',', $missing));
}