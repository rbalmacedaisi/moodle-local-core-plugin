<?php
/**
 * Phase 2 UI verification: simulate the AJAX endpoint call for getting
 * sessions (the same one the AttendancePanel consumes) and dump the JSON
 * so we can confirm is_revalida is included.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');

$classid = isset($argv[1]) ? (int)$argv[1] : 9575;
echo "Calling attendance_manager::get_sessions for class $classid...\n";
$result = \local_grupomakro_core\external\teacher\attendance_manager::get_sessions($classid);
if (!isset($result['status']) || $result['status'] !== 'success') {
    fwrite(STDERR, "ERROR: " . json_encode($result) . "\n");
    exit(1);
}
echo "status: success, total sessions: " . count($result['sessions']) . "\n";
echo "attendance_id: " . ($result['attendance_id'] ?? '?') . "\n\n";

echo "Sample session rows (is_revalida field per row):\n";
foreach ($result['sessions'] as $s) {
    $rv = isset($s->is_revalida) ? (int)$s->is_revalida : 'MISSING';
    printf("  sess=%d d=%s is_revalida=%s\n",
        $s->id,
        gmdate('Y-m-d H:i', $s->sessdate),
        $rv
    );
}

// Find at least one session with is_revalida=1.
$hits = array_filter($result['sessions'], fn($s) => (int)($s->is_revalida ?? 0) === 1);
echo "\nSessions flagged is_revalida=1 in this class: " . count($hits) . "\n";
exit(count($hits) > 0 ? 0 : 2);
