<?php
/**
 * Audit script: list every (user, class) pair in gmk_class_absence_state
 * where the class has at least one is_revalida=1 attendance session.
 * The presence_count / absence_count in that row is likely STALE because
 * the revalida session was counted as a regular absence when the cron
 * last ran. This script also recomputes (dry-run by default) so the
 * operator can see the deltas.
 *
 * SELECT-only by default. --apply will force absd_recompute_user_class_state
 * for every affected (user, class) so the new revalida filter is applied.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
global $DB;

$apply = in_array('--apply', $argv, true);

echo "=== AUDIT: stale gmk_class_absence_state rows affected by revalida sessions ===\n\n";

// Find every class that has at least one revalida session.
$classIds = $DB->get_fieldset_sql(
    "SELECT DISTINCT r.classid
       FROM {attendance_sessions} s
       JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
      WHERE COALESCE(s.is_revalida, 0) = 1
   ORDER BY r.classid"
);

if (empty($classIds)) {
    echo "No classes with revalida sessions.\n";
    exit(0);
}

echo "Classes with revalida sessions: " . count($classIds) . "\n";
echo "Looking up affected (user, class) pairs in gmk_class_absence_state...\n\n";

list($insql, $params) = $DB->get_in_or_equal($classIds, SQL_PARAMS_NAMED, 'rvclass');
$stateRows = $DB->get_records_sql(
    "SELECT s.id, s.userid, s.classid, s.absence_count, s.alert_level,
            s.blocked_at, s.unblocked_at, s.last_calculated,
            u.firstname, u.lastname,
            gc.name AS classname
       FROM {gmk_class_absence_state} s
       JOIN {user}        u  ON u.id  = s.userid
       JOIN {gmk_class}   gc ON gc.id = s.classid
      WHERE s.classid $insql
   ORDER BY s.classid, s.userid",
    $params
);

if (empty($stateRows)) {
    echo "No state rows to refresh (great - the revalida sessions never affected any student).\n";
    exit(0);
}

echo "Affected (user, class) pairs: " . count($stateRows) . "\n\n";

$totals = ['before_count' => 0, 'before_level' => 0, 'to_unblock' => 0, 'to_demote' => 0];
$i = 0;
foreach ($stateRows as $row) {
    $i++;
    $userid = (int)$row->userid;
    $classid = (int)$row->classid;
    $totals['before_count'] += (int)$row->absence_count;
    $totals['before_level'] = max($totals['before_level'], (int)$row->alert_level);
    $blockedNow = !empty($row->blocked_at) && empty($row->unblocked_at);
    $blockStatus = $blockedNow ? 'BLOCKED' : 'open';
    printf("[%d/%d] class=%d (%s) user=%d (%s %s) absence_count=%d alert_level=%d %s last_calc=%s\n",
        $i, count($stateRows),
        $classid, substr($row->classname ?? '', 0, 30),
        $userid, $row->firstname, $row->lastname,
        $row->absence_count, $row->alert_level, $blockStatus,
        !empty($row->last_calculated) ? gmdate('Y-m-d H:i', $row->last_calculated) : 'never'
    );
}

echo "\n=== Pre-recompute totals ===\n";
printf("Sum of absence_count : %d\n", $totals['before_count']);
printf("Max alert_level      : %d\n", $totals['before_level']);

if (!$apply) {
    echo "\nDRY-RUN. Re-run with --apply to force absd_recompute_user_class_state on these pairs.\n";
    exit(0);
}

echo "\n=== APPLYING absd_recompute_user_class_state for every pair ===\n\n";

$recomputed = 0;
$unblocked  = 0;
$demoted    = 0;
$errors     = 0;
foreach ($stateRows as $row) {
    $classid = (int)$row->classid;
    $userid  = (int)$row->userid;
    try {
        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $beforeBlocked = !empty($row->blocked_at) && empty($row->unblocked_at);
        $beforeLevel   = (int)$row->alert_level;
        $r = absd_recompute_user_class_state($class, $userid);
        $recomputed++;
        if ($beforeBlocked && in_array('unblock', $r['transitions'], true)) {
            $unblocked++;
        }
        if ($beforeLevel > $r['current_level']) {
            $demoted++;
        }
    } catch (Throwable $e) {
        $errors++;
        echo "  ERROR class=$classid user=$userid : " . $e->getMessage() . "\n";
    }
}

printf("Recomputed pairs: %d\n", $recomputed);
printf("Newly unblocked : %d\n", $unblocked);
printf("Newly demoted    : %d\n", $demoted);
printf("Errors           : %d\n", $errors);
echo "\nDONE.\n";
exit(0);
