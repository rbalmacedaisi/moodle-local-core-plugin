<?php
/**
 * Mimic the exact JSON shape returned by ajax.php for
 * local_grupomakro_get_class_attendance_matrix.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
global $DB, $CFG;

$classid = isset($argv[1]) ? (int)$argv[1] : 9606;

$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);

// Resolve attendance module id (prefer the relation table).
$sess_payload = [];
$rel = $DB->get_record_sql(
    "SELECT attendanceid, attendancemoduleid FROM {gmk_bbb_attendance_relation}
      WHERE classid = :cid LIMIT 1",
    ['cid' => $classid]
);
$attendanceid = $rel ? (int)$rel->attendanceid : 0;
if ($attendanceid <= 0) {
    fwrite(STDERR, "ERROR: no attendance module for class $classid\n");
    exit(1);
}

$all_session_ids   = absd_get_class_all_session_ids($class);
$taken_session_ids = absd_get_taken_session_ids($all_session_ids);
$taken_set = array_flip($taken_session_ids);
$now_ts   = time();

$session_rows = $DB->get_records_list('attendance_sessions', 'id', $all_session_ids, 'sessdate ASC');
$sessions_out = [];
foreach ($session_rows as $sr) {
    $sessions_out[] = [
        'id'          => (int)$sr->id,
        'sessdate'    => (int)$sr->sessdate,
        'description' => (string)($sr->description ?? ''),
        'taken'       => isset($taken_set[(int)$sr->id]),
        'future'      => (int)$sr->sessdate > $now_ts,
        'is_revalida' => isset($sr->is_revalida) ? (int)$sr->is_revalida : 0,
    ];
}

printf("class %d: %d sessions (taken=%d, revalida=%d)\n",
    $classid,
    count($sessions_out),
    count(array_filter($sessions_out, fn($s) => $s['taken'])),
    count(array_filter($sessions_out, fn($s) => $s['is_revalida'] === 1))
);
foreach ($sessions_out as $s) {
    printf("  sess=%d d=%s taken=%d future=%d is_revalida=%d\n",
        $s['id'], gmdate('Y-m-d H:i', $s['sessdate']),
        $s['taken'] ? 1 : 0, $s['future'] ? 1 : 0, $s['is_revalida']
    );
}
