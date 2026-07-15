<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

date_default_timezone_set('America/Panama');
cron_setup_user();

echo "1. Loaded\n";
global $DB;

$classid = 9618;
$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
$class->course = get_course($class->corecourseid);
$class->courseid = $class->corecourseid;

echo "2. Class loaded\n";

if (empty($class->attendancemoduleid) || empty($class->coursesectionid) || empty($class->groupid)) {
    fwrite(STDERR, "ERROR: clase {$classid} sin attendance/section/group\n"); exit(3);
}

$attendanceCm = get_coursemodule_from_id('attendance', $class->attendancemoduleid, 0, false, MUST_EXIST);
$attendanceRecord = $DB->get_record('attendance', ['id' => $attendanceCm->instance], '*', MUST_EXIST);

echo "3. Attendance loaded\n";

$BBBmoduleId = $DB->get_field('modules', 'id', ['name' => 'bigbluebuttonbn']);

$schedules = $DB->get_records('gmk_class_schedules', ['classid' => $classid]);
echo "4. Schedules=" . count($schedules) . "\n";

$isoToMoodle = [1=>'Lun',2=>'Mar',3=>'Mie',4=>'Jue',5=>'Vie',6=>'Sab',7=>'Dom'];
$weekdaysIso = [];
$scheduleRows = [];

if (!empty($schedules)) {
    foreach ($schedules as $s) {
        $weekdaysIso[(int)$s->day] = true;
        $scheduleRows[] = $s;
    }
} else {
    $bitToIso = [0=>1, 1=>2, 2=>3, 3=>4, 4=>5, 5=>6, 6=>7];
    $classdays = (int)$class->classdays;
    for ($b = 0; $b < 7; $b++) {
        if ($classdays & (1 << $b)) $weekdaysIso[$bitToIso[$b]] = true;
    }
}
$weekdaysIso = array_keys($weekdaysIso);
sort($weekdaysIso);

echo "5. weekdaysIso=" . implode(',', $weekdaysIso) . "\n";

$existingSessions = $DB->get_records(
    'attendance_sessions',
    ['attendanceid' => $attendanceRecord->id, 'groupid' => $class->groupid],
    'sessdate DESC',
    'id, sessdate, duration'
);
echo "6. existingSessions=" . count($existingSessions) . "\n";

$lastExistingTs = 0;
foreach ($existingSessions as $s) {
    if ((int)$s->sessdate > $lastExistingTs) $lastExistingTs = (int)$s->sessdate;
}
echo "7. lastExistingTs=" . $lastExistingTs . " (" . date('Y-m-d H:i', $lastExistingTs) . ")\n";

$firstNewDate = $lastExistingTs
    ? date('Y-m-d', strtotime('+1 day', $lastExistingTs))
    : date('Y-m-d');
echo "8. firstNewDate=" . $firstNewDate . "\n";

echo "9. END\n";