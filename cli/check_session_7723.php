<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

$sessionid = 7723;
$classid   = 9572;
$attid = (int)$DB->get_field_sql(
    "SELECT attendanceid FROM {gmk_bbb_attendance_relation} WHERE classid = :cid LIMIT 1",
    ['cid' => $classid]
);

// Look at attendance_log directly: what studentids have rows?
$logRows = $DB->get_records_sql(
    "SELECT l.id, l.studentid, l.statusid, ast.acronym, COALESCE(ast.grade, 0) AS grade
       FROM {attendance_log} l
  LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid
      WHERE l.sessionid = :sid
   ORDER BY l.studentid",
    ['sid' => $sessionid]
);
echo "Log rows for sess=$sessionid (count=" . count($logRows) . "):\n";
$byGrade = [0 => 0, 'pos' => 0];
foreach ($logRows as $r) {
    if ((float)$r->grade > 0) $byGrade['pos']++;
    else $byGrade[0]++;
}
echo "  present (grade>0): {$byGrade['pos']}\n";
echo "  absent  (grade=0): {$byGrade[0]}\n";

// Look at attendance_sessions table directly
$sess = $DB->get_record('attendance_sessions', ['id' => $sessionid]);
echo "\nSession record:\n";
foreach ((array)$sess as $k => $v) {
    if (in_array($k, ['id', 'lasttaken', 'lasttakenby'])) continue;
    echo "  $k = $v\n";
}

// What about the revalida flag for this session?
echo "\nis_revalida: " . var_export(isset($sess->is_revalida) ? $sess->is_revalida : 'NOT SET', true) . "\n";

// Is the relation table linking this session to class 9572?
$rel = $DB->get_record('gmk_bbb_attendance_relation', ['attendancesessionid' => $sessionid]);
echo "\nRelation row:\n";
if ($rel) {
    foreach ((array)$rel as $k => $v) echo "  $k = $v\n";
} else {
    echo "  (none)\n";
}