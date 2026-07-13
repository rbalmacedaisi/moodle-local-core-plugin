<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

$classid = 9572;

// Resolve enrolled in three different ways and compare.
$a = $DB->get_fieldset_sql(
    "SELECT userid FROM {gmk_course_progre} WHERE classid = :cid GROUP BY userid",
    ['cid' => $classid]
);
$groupid = (int)$DB->get_field('gmk_class', 'groupid', ['id' => $classid]);
$b = [];
if ($groupid > 0) {
    $b = $DB->get_fieldset_sql(
        "SELECT userid FROM {groups_members} WHERE groupid = :gid",
        ['gid' => $groupid]
    );
}
$union = array_values(array_unique(array_merge($a ?: [], $b ?: [])));

echo "class=$classid  groupid=$groupid\n";
printf("gmk_course_progre: %d users\n", count($a));
printf("groups_members   : %d users\n", count($b));
printf("union (unique)   : %d users\n", count($union));
echo "Intersection: " . count(array_intersect($a ?: [], $b ?: [])) . "\n";
echo "Only in progre: " . count(array_diff($a ?: [], $b ?: [])) . "\n";
echo "Only in group: " . count(array_diff($b ?: [], $a ?: [])) . "\n";

// Cross-check: how many of each set have an attendance_log row for sess=7723.
$sessid = 7723;
$logUserIds = $DB->get_fieldset_sql(
    "SELECT DISTINCT studentid FROM {attendance_log} WHERE sessionid = :sid",
    ['sid' => $sessid]
);
echo "\nFor session $sessid:\n";
printf("  attendance_log studentids: %d\n", count($logUserIds));
echo "  only in progre AND in log: " . count(array_intersect($a ?: [], $logUserIds ?: [])) . "\n";
echo "  only in group  AND in log: " . count(array_intersect($b ?: [], $logUserIds ?: [])) . "\n";
echo "  in union      AND in log: " . count(array_intersect($union, $logUserIds ?: [])) . "\n";
echo "  in progre NOT in log: " . count(array_diff($a ?: [], $logUserIds ?: [])) . "\n";
echo "  in group  NOT in log: " . count(array_diff($b ?: [], $logUserIds ?: [])) . "\n";
echo "  in union NOT in log: " . count(array_diff($union, $logUserIds ?: [])) . "\n";