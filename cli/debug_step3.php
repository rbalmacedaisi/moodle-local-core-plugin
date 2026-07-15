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
$attendanceStructure = new \mod_attendance_structure($attendanceRecord, $attendanceCm, $class->course);
$schedules = $DB->get_records('gmk_class_schedules', ['classid' => 9618]);

$weekdaysIso = [4];
$count = 7;
$firstNewDate = '2026-07-11';
$startTime = '17:45';
$endTime = '19:45';
list($hh, $mm) = explode(':', $startTime);

$candidates = [];
foreach ($weekdaysIso as $iso) {
    $cursorTs = strtotime($firstNewDate . ' ' . sprintf('%02d:%02d', $hh, $mm) . ' America/Panama');
    while ((int)date('N', $cursorTs) !== $iso) {
        $cursorTs = strtotime('+1 day', $cursorTs);
    }
    for ($i = 0; $i < $count; $i++) {
        $candidates[] = ['ts' => $cursorTs, 'date' => date('Y-m-d', $cursorTs), 'iso' => $iso];
        $cursorTs = strtotime('+7 days', $cursorTs);
    }
}
usort($candidates, fn($a, $b) => $a['ts'] <=> $b['ts']);

$toCreate = [];
$skippedDup = [];
foreach ($candidates as $c) {
    $exists = $DB->record_exists_sql(
        "SELECT 1 FROM {attendance_sessions} WHERE attendanceid = ? AND groupid = ? AND sessdate = ?",
        [$attendanceRecord->id, $class->groupid, $c['ts']]
    );
    if ($exists) $skippedDup[] = $c['date'];
    else $toCreate[] = $c;
}

echo "10. About to write backup JSON\n";
$backupPath = "/tmp/test_backup_" . time() . ".json";
$backup = [
    'class' => $class,
    'attendance' => $attendanceRecord,
    'candidates' => $candidates,
    'toCreate' => $toCreate,
];
file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "11. Backup written: " . filesize($backupPath) . " bytes\n";

echo "12. About to output dry-run report\n";
echo "=================================================\n";
echo "CLASE: {$class->name} (id=9618)\n";
echo "Modo: DRY-RUN\n";
echo "=================================================\n";
foreach ($toCreate as $i => $c) {
    echo sprintf("[%02d] %s  Jue  17:45-19:45\n", $i + 1, $c['date']);
}
echo "\nDRY-RUN: nada modificado.\n";

echo "13. END\n";