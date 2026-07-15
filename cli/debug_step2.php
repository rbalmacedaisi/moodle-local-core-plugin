<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

date_default_timezone_set('America/Panama');
cron_setup_user();

global $DB, $CFG, $USER;

$class = $DB->get_record('gmk_class', ['id' => 9618], '*', MUST_EXIST);
$class->course = get_course($class->corecourseid);
$class->courseid = $class->corecourseid;

$attendanceCm = get_coursemodule_from_id('attendance', $class->attendancemoduleid, 0, false, MUST_EXIST);
$attendanceRecord = $DB->get_record('attendance', ['id' => $attendanceCm->instance], '*', MUST_EXIST);

$BBBmoduleId = $DB->get_field('modules', 'id', ['name' => 'bigbluebuttonbn']);

echo "1. About to instantiate mod_attendance_structure\n";
$attendanceStructure = new \mod_attendance_structure($attendanceRecord, $attendanceCm, $class->course);
echo "2. mod_attendance_structure instantiated\n";

echo "3. About to get class_schedules\n";
$schedules = $DB->get_records('gmk_class_schedules', ['classid' => 9618]);
echo "4. Got " . count($schedules) . " schedules\n";

echo "5. About to compute candidates\n";
$weekdaysIso = [4]; // jueves
$count = 7;
$firstNewDate = '2026-07-11';
$startTime = '17:45';
$endTime = '19:45';
list($hh, $mm) = explode(':', $startTime);

$candidates = [];
foreach ($weekdaysIso as $iso) {
    $cursorTs = strtotime($firstNewDate . ' ' . sprintf('%02d:%02d', $hh, $mm) . ' America/Panama');
    echo "  Initial cursor: " . date('Y-m-d H:i', $cursorTs) . " iso_target=$iso actual_iso=" . date('N', $cursorTs) . "\n";
    while ((int)date('N', $cursorTs) !== $iso) {
        $cursorTs = strtotime('+1 day', $cursorTs);
    }
    for ($i = 0; $i < $count; $i++) {
        $candidates[] = [
            'ts'   => $cursorTs,
            'date' => date('Y-m-d', $cursorTs),
            'iso'  => $iso,
        ];
        $cursorTs = strtotime('+7 days', $cursorTs);
    }
}
echo "6. Got " . count($candidates) . " candidates:\n";
foreach ($candidates as $c) {
    echo "  " . $c['date'] . " (" . date('l', $c['ts']) . ") ts=" . $c['ts'] . "\n";
}

echo "7. About to test anti-duplicado\n";
$toCreate = [];
$skippedDup = [];
foreach ($candidates as $c) {
    $exists = $DB->record_exists_sql(
        "SELECT 1 FROM {attendance_sessions}
          WHERE attendanceid = ? AND groupid = ? AND sessdate = ?",
        [$attendanceRecord->id, $class->groupid, $c['ts']]
    );
    if ($exists) {
        $skippedDup[] = $c['date'];
    } else {
        $toCreate[] = $c;
    }
}
echo "8. toCreate=" . count($toCreate) . " skipped=" . count($skippedDup) . "\n";

echo "9. END\n";