<?php
/**
 * Detect attendance sessions to mark as "is_revalida" based on the rule:
 *
 *   "Todas las sesiones de esta semana desde el lunes 6 de julio hasta el
 *    sábado 11 de julio son sesiones de reválida para los cursos del cual
 *    esta sesión sea la última sesión, ya que hay otros que continúa.
 *    Deben ser marcadas como reválida."
 *
 * Heuristic:
 *   1. attendance_sessions.sessdate in [2026-07-06 00:00 local, 2026-07-12 00:00 local)
 *      = [Mon 06-Jul-2026 00:00:00, Sat 11-Jul-2026 23:59:59] in Panama time
 *      = [Mon 06-Jul-2026 05:00:00, Sun 12-Jul-2026 04:59:59] in UTC (UTC-5)
 *   2. AND there is NO future attendance session for the same attendanceid with sessdate >= 2026-07-12 05:00:00.
 *
 * SELECT-only by default. --apply writes once the column exists.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Detect revalida sessions in [06-Jul-2026 00:00, 12-Jul-2026 00:00) Panama');

// Panama = UTC-5 (no DST).
// "Lunes 6 de julio hasta sábado 11 de julio" is interpreted as full Monday
// through end of Saturday in local time.
$startTs = gmmktime(5, 0, 0, 7, 6, 2026);  // 2026-07-06 00:00 Panama = 2026-07-06 05:00 UTC
$endTs   = gmmktime(5, 0, 0, 7, 12, 2026); // 2026-07-12 00:00 Panama = 2026-07-12 05:00 UTC (exclusive)
$now      = time();

echo "Window (Panama): Mon 06-Jul-2026 00:00 .. Sat 11-Jul-2026 23:59\n";
echo "Window (UTC):    " . gmdate('Y-m-d H:i:s', $startTs) . " .. " . gmdate('Y-m-d H:i:s', $endTs) . " (exclusive end)\n";
echo "Current time:    " . gmdate('Y-m-d H:i:s', $now) . " UTC\n\n";

// Schema probe.
$colExists = (bool)$DB->get_record_sql(
    "SELECT 1
       FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'mdl_attendance_sessions'
        AND column_name = 'is_revalida'
      LIMIT 1"
);
echo "Schema mdl_attendance_sessions.is_revalida present: " . ($colExists ? 'YES' : 'NO') . "\n\n";

// Detect candidates: sessions in the window, AND their attendance module has
// no future sessions (i.e., this IS the last session).
$rows = $DB->get_records_sql("
    SELECT s.id           AS uniq,
           s.id           AS sessionid,
           s.attendanceid AS attendanceid,
           s.sessdate     AS sessdate,
           s.duration     AS duration,
           s.lasttaken    AS lasttaken,
           s.description  AS description,
           (SELECT gc.name FROM {gmk_class} gc
              JOIN {gmk_bbb_attendance_relation} r ON r.classid = gc.id
             WHERE r.attendanceid = s.attendanceid
             LIMIT 1) AS classname,
           (SELECT gc.id FROM {gmk_class} gc
              JOIN {gmk_bbb_attendance_relation} r ON r.classid = gc.id
             WHERE r.attendanceid = s.attendanceid
             LIMIT 1) AS classid,
           (SELECT MAX(s2.sessdate)
              FROM {attendance_sessions} s2
             WHERE s2.attendanceid = s.attendanceid) AS last_sessdate_in_attendance
      FROM {attendance_sessions} s
     WHERE s.sessdate >= :start
       AND s.sessdate <  :end
  ORDER BY s.sessdate ASC
", ['start' => $startTs, 'end' => $endTs]);

$applyMode = in_array('--apply', $argv, true);
$candidates = [];
$openOrFuture = [];
foreach ($rows as $r) {
    $sid = (int)$r->sessionid;
    $isLast = ((int)$r->last_sessdate_in_attendance === (int)$r->sessdate);
    $line = sprintf("sess=%d att=%d d=%s last=%s class=%s (%s) taken=%d desc=%s",
        $sid, (int)$r->attendanceid, gmdate('Y-m-d H:i', (int)$r->sessdate),
        $isLast ? 'YES' : 'NO ',
        (string)($r->classname ?? '?'),
        (string)($r->classid ?? '?'),
        (int)$r->lasttaken,
        substr((string)($r->description ?? ''), 0, 60)
    );
    if ($isLast) {
        $candidates[] = ['sessionid' => $sid, 'classid' => (int)($r->classid ?? 0), 'classname' => (string)($r->classname ?? ''), 'sessdate' => (int)$r->sessdate];
    } else {
        $openOrFuture[] = $line;
    }
}

echo "TOTAL sessions in window: " . count($rows) . "\n";
echo "Candidates to mark (last session of class): " . count($candidates) . "\n";
echo "Skipped (class has later sessions, NOT last): " . count($openOrFuture) . "\n\n";

if (!empty($candidates)) {
    echo "--- CANDIDATES TO MARK ---\n";
    foreach ($candidates as $c) {
        printf("  sess=%d class=%s (%d) d=%s\n",
            $c['sessionid'], $c['classname'], $c['classid'],
            gmdate('Y-m-d H:i', $c['sessdate']));
    }
    echo "\n";
}

if (!empty($openOrFuture)) {
    echo "--- SKIPPED (not last) ---\n";
    foreach ($openOrFuture as $line) {
        echo "  $line\n";
    }
    echo "\n";
}

if ($applyMode) {
    if (!$colExists) {
        cli_error('Cannot apply: is_revalida column does not exist yet. Run upgrade.php first.');
    }
    $ids = array_column($candidates, 'sessionid');
    if (empty($ids)) {
        echo "Nothing to update.\n";
        exit(0);
    }
    list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'rv');
    $DB->execute(
        "UPDATE {attendance_sessions} SET is_revalida = 1, timemodified = :now WHERE id $insql",
        ['now' => $now] + $params
    );
    echo "Applied UPDATE is_revalida=1 to " . count($ids) . " row(s).\n";
    exit(0);
}

echo "Dry-run only. Re-run with --apply after running upgrade.php to mark them.\n";
exit(0);
