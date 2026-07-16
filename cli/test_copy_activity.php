<?php
/**
 * test_copy_activity.php - Standalone test for the copy WS
 *
 * Usage:
 *   php test_copy_activity.php
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

date_default_timezone_set('America/Panama');
cron_setup_user();

global $DB, $USER;

// Test data
$classId        = 9618;          // INGLÉS APLICADO A LA AERONÁUTICA 2026-III (N)
$sourceSessId   = 8373;          // Last existing session (2026-07-10 jueves)
$class = $DB->get_record('gmk_class', ['id' => $classId], '*', MUST_EXIST);
echo "=== TEST: gmk_copy_class_activity ===\n";
echo "Clase: {$class->name} (id={$classId})\n";

// Capture state BEFORE
$attendanceId = (int)$DB->get_field('course_modules', 'instance', ['id' => $class->attendancemoduleid]);
$beforeSessions = $DB->count_records('attendance_sessions', ['attendanceid' => $attendanceId, 'groupid' => $class->groupid]);
$beforeRel = $DB->count_records('gmk_bbb_attendance_relation', ['classid' => $classId]);
echo "Antes: $beforeSessions sesiones / $beforeRel relaciones\n";

// Try with a single date (a few weeks in the future, no conflicts expected)
$testDates = [
    ['date' => '2026-09-10', 'initTime' => '17:45', 'endTime' => '19:45'],
    ['date' => '2026-09-17', 'initTime' => '17:45', 'endTime' => '19:45'],
];

echo "\n--- DRY RUN: calling gmk_copy_class_activity with 2 dates ---\n";
echo "Dates:\n";
foreach ($testDates as $d) {
    echo "  {$d['date']} {$d['initTime']}-{$d['endTime']}\n";
}

try {
    $result = gmk_copy_class_activity([
        'classId'         => $classId,
        'sourceSessionId' => $sourceSessId,
        'dates'           => $testDates,
    ]);
    echo "\nResult:\n";
    print_r($result);

    // Verify AFTER state
    $afterSessions = $DB->count_records('attendance_sessions', ['attendanceid' => $attendanceId, 'groupid' => $class->groupid]);
    $afterRel = $DB->count_records('gmk_bbb_attendance_relation', ['classid' => $classId]);
    echo "\nDespues: $afterSessions sesiones / $afterRel relaciones\n";
    echo "Diff: +" . ($afterSessions - $beforeSessions) . " sesiones / +" . ($afterRel - $beforeRel) . " relaciones\n";

    // Verify assigned_dates was updated
    $sched = $DB->get_record('gmk_class_schedules', ['classid' => $classId]);
    echo "\nassigned_dates actual:\n" . $sched->assigned_dates . "\n";

    // Verify enddate was extended
    $class = $DB->get_record('gmk_class', ['id' => $classId]);
    echo "enddate actual: " . date('Y-m-d H:i', $class->enddate) . "\n";

} catch (\Throwable $e) {
    echo "\n!!! ERROR: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . ":" . $e->getLine() . "\n";
}