<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

date_default_timezone_set('America/Panama');
cron_setup_user();

global $DB;

echo "=== Cleanup 10929 (test fresh) ===\n";

$sessId = 10929;
$rel = $DB->get_record('gmk_bbb_attendance_relation', ['attendancesessionid' => $sessId]);
$bbbCmid = $rel->bbbmoduleid ?? null;

if ($bbbCmid) {
    $cm = $DB->get_record('course_modules', ['id' => $bbbCmid]);
    if ($cm) {
        $sec = $DB->get_record('course_sections', ['id' => $cm->section], 'id,sequence');
        if ($sec && !empty($sec->sequence)) {
            $arr = array_filter(explode(',', $sec->sequence), fn($x) => (int)$x !== $bbbCmid);
            $DB->set_field('course_sections', 'sequence', implode(',', $arr), ['id' => $sec->id]);
        }
    }
    $DB->delete_records('event', ['instance' => $bbbCmid, 'modulename' => 'bigbluebuttonbn']);
    $bbbInst = $DB->get_field('course_modules', 'instance', ['id' => $bbbCmid]);
    if ($bbbInst) {
        $DB->delete_records('bigbluebuttonbn', ['id' => $bbbInst]);
    }
    $DB->delete_records('course_modules', ['id' => $bbbCmid]);
    echo "Deleted BBB cmid=$bbbCmid\n";
}
$DB->delete_records('gmk_bbb_attendance_relation', ['attendancesessionid' => $sessId]);
$DB->delete_records('attendance_sessions', ['id' => $sessId]);
echo "Deleted session id=$sessId\n";

$class = $DB->get_record('gmk_class', ['id' => 9618]);
$cmids = $class->bbbmoduleids ? array_filter(explode(',', $class->bbbmoduleids)) : [];
$cmids = array_filter($cmids, fn($c) => (int)$c !== $bbbCmid);
$class->bbbmoduleids = implode(',', array_values($cmids));
$class->enddate = strtotime('2026-09-03 22:45:00 UTC');
$class->timemodified = time();
$DB->update_record('gmk_class', $class);

$sched = $DB->get_record('gmk_class_schedules', ['classid' => 9618]);
$assigned = json_decode($sched->assigned_dates, true);
$assigned = array_values(array_filter($assigned, fn($d) => $d !== '2027-02-09'));
$sched->assigned_dates = json_encode($assigned);
$sched->timemodified = time();
$DB->update_record('gmk_class_schedules', $sched);

rebuild_course_cache(75, true);
echo "Restored bbbmoduleids, enddate, assigned_dates\n";
echo "=== DONE ===\n";