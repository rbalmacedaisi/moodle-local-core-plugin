<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Backfill gmk_course_progre with the latest-grade model.
 *
 * Runs course_grade_resolver::sync_progress() for every (student, course)
 * pair that has a progress row. Dry run by default: prints what would change
 * (grade, status, source) and a CSV with every changed row, without writing.
 *
 * Usage:
 *   php local/grupomakro_core/cli/sync_latest_grades.php [--userid=N] [--courseid=N] [--csv=/tmp/x.csv]
 *   php local/grupomakro_core/cli/sync_latest_grades.php --apply
 *
 * @package    local_grupomakro_core
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

list($options, $unrecognized) = cli_get_params(
    ['apply' => false, 'userid' => 0, 'courseid' => 0, 'csv' => '', 'help' => false],
    ['h' => 'help']
);
if ($options['help']) {
    echo "php sync_latest_grades.php [--apply] [--userid=N] [--courseid=N] [--csv=file]\n";
    exit(0);
}
$apply = !empty($options["apply"]);
if ($apply && !get_config("local_grupomakro_core", "latest_grade_sync_enabled")) {
    cli_error("latest_grade_sync_enabled esta en 0: activalo antes de --apply (admin/cli/cfg.php).");
}

$where = 'p.courseid > 1 AND u.deleted = 0';
$params = [];
if ((int)$options['userid'] > 0) {
    $where .= ' AND p.userid = :userid';
    $params['userid'] = (int)$options['userid'];
}
if ((int)$options['courseid'] > 0) {
    $where .= ' AND p.courseid = :courseid';
    $params['courseid'] = (int)$options['courseid'];
}
$pairs = $DB->get_recordset_sql(
    "SELECT DISTINCT p.userid, p.courseid
       FROM {gmk_course_progre} p
       JOIN {user} u ON u.id = p.userid
      WHERE $where
   ORDER BY p.userid, p.courseid", $params);

$csvfile = $options['csv'] ?: '/tmp/sync_latest_grades_' . ($apply ? 'apply' : 'dryrun') . '_' . date('Ymd_His') . '.csv';
$csv = fopen($csvfile, 'w');
fputcsv($csv, ['progreid', 'userid', 'courseid', 'learningplanid', 'grade_old', 'grade_new',
    'status_old', 'status_new', 'source_new', 'grade_at_new', 'classid_new']);

$stats = ['pairs' => 0, 'rows_changed' => 0, 'grade_changed' => 0, 'status_changed' => 0,
          'errors' => 0, 'sources' => [], 'transitions' => []];
$start = time();

foreach ($pairs as $pair) {
    $stats['pairs']++;
    try {
        $changes = \local_grupomakro_core\course_grade_resolver::sync_progress((int)$pair->userid, (int)$pair->courseid, $apply);
    } catch (\Throwable $e) {
        $stats['errors']++;
        cli_writeln("ERROR user {$pair->userid} course {$pair->courseid}: " . $e->getMessage());
        continue;
    }
    foreach ($changes as $c) {
        $stats['rows_changed']++;
        if (isset($c['grade'])) {
            $stats['grade_changed']++;
        }
        if (isset($c['status'])) {
            $stats['status_changed']++;
            $t = $c['status'][0] . '->' . $c['status'][1];
            $stats['transitions'][$t] = ($stats['transitions'][$t] ?? 0) + 1;
        }
        $src = isset($c['grade_source']) ? (string)$c['grade_source'][1] : '(sin cambio)';
        $stats['sources'][$src] = ($stats['sources'][$src] ?? 0) + 1;
        fputcsv($csv, [
            $c['progreid'], $pair->userid, $pair->courseid, $c['learningplanid'],
            isset($c['grade']) ? $c['grade'][0] : '', isset($c['grade']) ? $c['grade'][1] : '',
            isset($c['status']) ? $c['status'][0] : '', isset($c['status']) ? $c['status'][1] : '',
            $src,
            isset($c['grade_at']) ? $c['grade_at'][1] : '', isset($c['grade_classid']) ? $c['grade_classid'][1] : '',
        ]);
    }
    if ($stats['pairs'] % 500 === 0) {
        cli_writeln("... {$stats['pairs']} pares procesados (" . (time() - $start) . " s)");
    }
}
$pairs->close();
fclose($csv);

arsort($stats['sources']);
arsort($stats['transitions']);
cli_writeln(($apply ? 'APLICADO' : 'SIMULACION') . ' en ' . (time() - $start) . ' s');
cli_writeln("pares estudiante-curso: {$stats['pairs']}");
cli_writeln("filas con cambios:      {$stats['rows_changed']}");
cli_writeln("  nota cambia:          {$stats['grade_changed']}");
cli_writeln("  estado cambia:        {$stats['status_changed']}");
cli_writeln("errores:                {$stats['errors']}");
cli_writeln('fuente nueva de la nota:');
foreach ($stats['sources'] as $k => $v) {
    cli_writeln("  $k: $v");
}
cli_writeln('cambios de estado (antes->despues):');
foreach ($stats['transitions'] as $k => $v) {
    cli_writeln("  $k: $v");
}
cli_writeln("detalle: $csvfile");
