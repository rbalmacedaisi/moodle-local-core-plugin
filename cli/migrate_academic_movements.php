<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Backfill CLI: creates gmk_course_attempts + gmk_academic_movements rows from
 * the existing gmk_course_progre, gmk_revalidations and gmk_homologation_audit
 * data. The migration is non-destructive and idempotent.
 *
 * Usage:
 *   php local/grupomakro_core/cli/migrate_academic_movements.php [--batch=1000]
 *        [--limit=0] [--report=path]
 *
 * Defaults: batch=1000, limit=0 (no limit), report=moodledata/grupomakro_migration_<ts>.csv
 *
 * @package     local_grupomakro_core
 * @category    cli
 * @copyright   2026 Solutto Consulting
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/academic_movement_manager.php');

global $CFG, $DB;

$batch    = (int)getopt('', ['batch:'])['batch'] ?? 1000;
$limit    = (int)getopt('', ['limit:'])['limit'] ?? 0;
$report   = getopt('', ['report:'])['report'] ?? null;

if ($batch <= 0) {
    cli_error("Invalid --batch value: $batch");
}

$starttime = time();
$ts        = date('Ymd_His', $starttime);
$reportpath = $report ?: ($CFG->dataroot . "/grupomakro_migration_{$ts}.csv");
$reportfh   = fopen($reportpath, 'w');
if ($reportfh === false) {
    cli_error("Could not open report file: $reportpath");
}
fputcsv($reportfh, [
    'kind', 'userid', 'learningplanid', 'corecourseid', 'progreid', 'classid',
    'source', 'source_record_id', 'grade', 'course_status', 'effective_at', 'reason',
]);

cli_writeln("Academic movements backfill starting (batch=$batch limit=$limit)");
cli_writeln("Report: $reportpath");

$mgr = 'local_grupomakro_academic_movement_manager';

// 1. Ensure gmk_course_attempts has a row per (user, plan, course) terminal progre.
cli_writeln("[1/3] Materialising gmk_course_attempts from gmk_course_progre...");
$progresql = "SELECT id, userid, courseid, learningplanid, classid, status, grade,
                     timemodified, timecreated, usermodified
                FROM {gmk_course_progre}
               WHERE status IN (3,4,5,6,7)
            ORDER BY id ASC";
$records = $DB->get_records_sql($progresql, [], 0, $limit > 0 ? $limit : 0);
$count   = 0;
$batchcount = 0;
foreach ($records as $progre) {
    $attemptid = $mgr::upsert_attempt([
        'userid'         => (int)$progre->userid,
        'learningplanid' => (int)$progre->learningplanid,
        'corecourseid'   => (int)$progre->courseid,
        'classid'        => $progre->classid,
        'attempt_no'     => 1,
        'is_module'      => 0,
        'enroll_date'    => (int)($progre->timecreated ?: $progre->timemodified ?: time()),
        'end_date'       => (int)$progre->timemodified ?: null,
        'status'         => 'closed',
        'usermodified'   => (int)$progre->usermodified,
    ]);
    $count++;
    $batchcount++;
    if ($batchcount >= $batch) {
        $batchcount = 0;
        gc_collect_cycles();
        cli_writeln("  ... $count attempts upserted so far");
    }
}
cli_writeln("[1/3] Done. Attempts touched: $count");

// 2. Materialise gmk_academic_movements from gmk_course_progre.
cli_writeln("[2/3] Materialising gmk_academic_movements from gmk_course_progre...");
$moved = 0;
$batchcount = 0;
foreach ($records as $progre) {
    $source = 'class_close';
    $reason = '';
    $grade  = (float)$progre->grade;
    $status = (int)$progre->status;

    if (!empty($progre->homologation_type)) {
        $source = 'homologate';
        $reason = 'homologation_type=' . $progre->homologation_type;
    }

    // effective_at preference: timemodified, then timecreated, else 0 (flagged).
    $effectiveat = (int)$progre->timemodified;
    if ($effectiveat <= 0) {
        $effectiveat = (int)$progre->timecreated;
    }

    $movementid = $mgr::record_movement([
        'userid'          => (int)$progre->userid,
        'learningplanid'  => (int)$progre->learningplanid,
        'corecourseid'    => (int)$progre->courseid,
        'classid'         => $progre->classid,
        'source'          => $source,
        'source_record_id' => (int)$progre->id,
        'grade'           => $grade,
        'course_status'   => $status,
        'effective_at'    => $effectiveat,
        'usermodified'    => (int)$progre->usermodified,
    ]);

    if ($reason !== '') {
        fputcsv($reportfh, ['homologation_hint', $progre->userid, $progre->learningplanid, $progre->courseid,
            $progre->id, $progre->classid, $source, $movementid, $grade, $status, $effectiveat, $reason]);
    }

    if ($effectiveat <= 0) {
        fputcsv($reportfh, ['unknown_effective_at', $progre->userid, $progre->learningplanid, $progre->courseid,
            $progre->id, $progre->classid, $source, $movementid, $grade, $status, $effectiveat,
            'effective_at derivado de timecreated/timemodified fue 0']);
    }

    $moved++;
    $batchcount++;
    if ($batchcount >= $batch) {
        $batchcount = 0;
        gc_collect_cycles();
        cli_writeln("  ... $moved movements inserted so far");
    }
}
cli_writeln("[2/3] Done. Movements inserted: $moved");

// 3. Materialise gmk_academic_movements from gmk_revalidations.status='consolidated'.
cli_writeln("[3/3] Materialising gmk_academic_movements from gmk_revalidations (consolidated)...");
$reval = $DB->get_records_select(
    'gmk_revalidations',
    "status = 'consolidated'",
    [],
    'timemodified ASC'
);
$revalmoved = 0;
foreach ($reval as $r) {
    $mgr::record_movement([
        'userid'         => (int)$r->userid,
        'learningplanid' => (int)$r->learningplanid,
        'corecourseid'   => (int)$r->corecourseid,
        'classid'        => (int)$r->classid,
        'source'         => 'revalidation',
        'source_record_id' => (int)$r->id,
        'grade'          => $r->revalidgrade !== null ? (float)$r->revalidgrade : null,
        'course_status'  => ($r->result === 'approved') ? 4 : 5,
        'effective_at'   => (int)$r->timemodified,
        'usermodified'   => (int)$r->createdby,
    ]);
    $revalmoved++;
}
cli_writeln("[3/3] Done. Revalidation movements inserted: $revalmoved");

// 4. Detect divergence: courses with multiple non-annulled movements.
cli_writeln("Detecting divergences (best grade vs current progre)...");
$sql = "SELECT userid, learningplanid, corecourseid,
               COUNT(*) AS movements,
               MAX(grade) AS best_grade,
               MIN(effective_at) AS first_at,
               MAX(effective_at) AS last_at
          FROM {gmk_academic_movements}
         WHERE annulled = 0
      GROUP BY userid, learningplanid, corecourseid
        HAVING COUNT(*) > 1";
$div = $DB->get_records_sql($sql);
foreach ($div as $row) {
    fputcsv($reportfh, ['multi_movement', $row->userid, $row->learningplanid, $row->corecourseid,
        '', '', '', '', $row->best_grade, '', $row->last_at,
        "movements={$row->movements} first_at={$row->first_at}"]);
}
cli_writeln("  Found " . count($div) . " triples with multiple non-annulled movements.");

fclose($reportfh);
cli_writeln("Report written to: $reportpath");
cli_writeln("Total time: " . (time() - $starttime) . "s");
cli_writeln("Done.");
