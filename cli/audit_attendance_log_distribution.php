<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;

$startTs = gmmktime(5, 0, 0, 7, 6, 2026);
$endTs   = gmmktime(5, 0, 0, 7, 12, 2026);

// Distribution of attendance_log grades for the 1,487 existing rows in
// Jul 6-11 sessions of the affected classes.
$classIds = $DB->get_fieldset_sql(
    "SELECT DISTINCT r.classid
       FROM {attendance_sessions} s
       JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
      WHERE s.sessdate >= :start AND s.sessdate < :end",
    ['start' => $startTs, 'end' => $endTs]
);
list($insql, $params) = $DB->get_in_or_equal($classIds, SQL_PARAMS_NAMED, 'cls');
$params['start'] = $startTs;
$params['end']   = $endTs;
$rows = $DB->get_records_sql(
    "SELECT CONCAT(s.id, '-', COALESCE(ast.id, 0)) AS uniq,
           s.id AS sessid, s.is_revalida,
           COALESCE(ast.acronym, '-') AS acronym,
           COALESCE(ast.description, 'Sin asignar') AS statusdesc,
           COUNT(al.id) AS total,
           SUM(CASE WHEN COALESCE(ast.grade, 0) > 0 THEN 1 ELSE 0 END) AS present,
           SUM(CASE WHEN COALESCE(ast.grade, 0) = 0 THEN 1 ELSE 0 END) AS absent
      FROM {attendance_sessions} s
      JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
 LEFT JOIN {attendance_log}      al  ON al.sessionid = s.id
 LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
     WHERE r.classid $insql
       AND s.sessdate >= :start
       AND s.sessdate <  :end
  GROUP BY s.id, s.is_revalida, ast.acronym, ast.description
  ORDER BY s.sessdate, ast.acronym",
    $params
);

echo "Distribution of attendance_log rows per session+status in Jul 6-11 affected classes:\n";
$byStatus = [];
foreach ($rows as $r) {
    $key = ($r->is_revalida ? 'REVAL' : 'REG  ') . ' | ' . $r->acronym . ' (' . $r->statusdesc . ')';
    $byStatus[$key] = ($byStatus[$key] ?? 0) + $r->total;
    printf("  sess=%-5d %s | %-3s %-25s total=%d present=%d absent=%d\n",
        $r->sessid, $r->is_revalida ? 'REVAL' : 'REG  ',
        $r->acronym, $r->statusdesc,
        $r->total, $r->present, $r->absent);
}

echo "\nAggregate by status:\n";
foreach ($byStatus as $k => $v) {
    printf("  %s : %d rows\n", $k, $v);
}