<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
global $DB;

$classid = (int)($argv[1] ?? 9575);
$userid  = (int)($argv[2] ?? 2008);
echo "Class: $classid  User: $userid\n\n";

$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
$now = time();

$state = $DB->get_record('gmk_class_absence_state', [
    'userid'  => $userid,
    'classid' => $classid,
]);
echo "Current gmk_class_absence_state row:\n";
if ($state) {
    foreach ((array)$state as $k => $v) {
        if (in_array($k, ['id', 'userid', 'classid', 'courseid', 'usermodified', 'timecreated'])) continue;
        printf("  %s = %s\n", $k, $v);
    }
}

echo "\nLive absence count (with revalida filter):\n";
$past = absd_get_class_past_session_ids($class, $now);
$taken = absd_get_taken_session_ids($past);
$absMap = absd_get_student_absences($taken, [$userid]);
printf("  taken=%d  absences=%d\n", count($taken), $absMap[$userid] ?? 0);
printf("  expected level = %d (from count)\n", absd_level_for_count($absMap[$userid] ?? 0));

echo "\nRecomputing now...\n";
$res = absd_recompute_user_class_state($class, $userid);
echo "Result:\n";
print_r($res);

$state2 = $DB->get_record('gmk_class_absence_state', [
    'userid'  => $userid,
    'classid' => $classid,
]);
echo "\nNew gmk_class_absence_state row:\n";
if ($state2) {
    foreach ((array)$state2 as $k => $v) {
        if (in_array($k, ['id', 'userid', 'classid', 'courseid', 'usermodified', 'timecreated'])) continue;
        printf("  %s = %s\n", $k, $v);
    }
}
