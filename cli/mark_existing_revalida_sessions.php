<?php
/**
 * CLI script: dry-run the backfill that will mark attendance_sessions.is_revalida=1
 * for every session tied (via gmk_bbb_attendance_relation) to a BigBlueButton
 * whose name starts with 'Reválida - '.
 *
 * IMPORTANT: This script is strictly SELECT-only by default. Use --apply ONLY
 * AFTER the upgrade.php savepoint that adds the `is_revalida` column has been
 * applied to the schema. The script is safe to run BEFORE the schema change
 * (it will simply WARN that the column does not exist and skip the UPDATE).
 *
 * USAGE:
 *   php mark_existing_revalida_sessions.php                # dry-run (SELECT only)
 *   php mark_existing_revalida_sessions.php --json         # machine-readable output
 *   php mark_existing_revalida_sessions.php --quiet        # only the count
 *   php mark_existing_revalida_sessions.php --apply        # UPDATE is_revalida=1 (after schema change)
 *   php mark_existing_revalida_sessions.php --help
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    $configpath = '/var/www/html/moodle/config.php';
}
if (!file_exists($configpath)) {
    fwrite(STDERR, "ERROR: Moodle config.php not found.\n");
    exit(2);
}

require_once($configpath);
require_once($CFG->libdir . '/clilib.php');

$options = getopt('', ['apply', 'json', 'quiet', 'help']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php mark_existing_revalida_sessions.php                # Dry-run (SELECT only)\n";
    echo "  php mark_existing_revalida_sessions.php --json         # JSON output\n";
    echo "  php mark_existing_revalida_sessions.php --quiet        # Only the summary line\n";
    echo "  php mark_existing_revalida_sessions.php --apply        # UPDATE is_revalida=1 (requires schema change)\n";
    exit(0);
}

$applyMode = isset($options['apply']);
$jsonMode  = isset($options['json']);
$quietMode = isset($options['quiet']);

// 1. Schema probe.
$colExists = (bool)$DB->get_record_sql(
    "SELECT 1
       FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'mdl_attendance_sessions'
        AND column_name = 'is_revalida'
      LIMIT 1"
);

if ($applyMode && !$colExists) {
    cli_error('Cannot apply: column mdl_attendance_sessions.is_revalida does not exist yet. Run `php admin/cli/upgrade.php` first.');
}

// 2. Detection query — session is "revalida" if any of its related BBB modules
//    has a bigbluebuttonbn.name starting with "Reválida - ".
$detectionSql = "
    SELECT s.id              AS session_id,
           s.attendanceid    AS attendanceid,
           s.sessdate        AS sessdate,
           s.duration        AS duration,
           s.description     AS description,
           s.lasttaken       AS lasttaken,
           gc.id             AS classid,
           gc.name           AS classname,
           gc.corecourseid   AS corecourseid,
           b.id              AS bbb_id,
           b.name            AS bbb_name,
           cm.id             AS bbb_cmid
      FROM {attendance_sessions} s
      JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
      JOIN {gmk_class} gc                 ON gc.id = r.classid
      JOIN {course_modules} cm            ON cm.id = r.bbbmoduleid
      JOIN {modules} m                    ON m.id = cm.module AND m.name = 'bigbluebuttonbn'
      JOIN {bigbluebuttonbn} b            ON b.id = cm.instance
     WHERE b.name LIKE 'Reválida - %'
  ORDER BY gc.id ASC, s.sessdate ASC";

$rows = $DB->get_records_sql($detectionSql);
$candidates = [];
foreach ($rows as $r) {
    $sid = (int)$r->session_id;
    if (!isset($candidates[$sid])) {
        $candidates[$sid] = [
            'session_id'    => $sid,
            'attendanceid'  => (int)$r->attendanceid,
            'sessdate'      => (int)$r->sessdate,
            'duration'      => (int)$r->duration,
            'description'   => (string)($r->description ?? ''),
            'lasttaken'     => (int)$r->lasttaken,
            'classid'       => (int)$r->classid,
            'classname'     => (string)($r->classname ?? ''),
            'corecourseid'  => (int)$r->corecourseid,
            'bbb_matches'   => [],
        ];
    }
    $candidates[$sid]['bbb_matches'][] = [
        'bbb_id'   => (int)$r->bbb_id,
        'bbb_name' => (string)$r->bbb_name,
        'bbb_cmid' => (int)$r->bbb_cmid,
    ];
}
$candidates = array_values($candidates);

// Also detect session count per class.
$classSummary = [];
foreach ($candidates as $c) {
    $cid = $c['classid'];
    if (!isset($classSummary[$cid])) {
        $classSummary[$cid] = [
            'classid'   => $cid,
            'classname' => $c['classname'],
            'session_count' => 0,
        ];
    }
    $classSummary[$cid]['session_count']++;
}

if ($quietMode) {
    printf("candidates=%d classes_affected=%d apply=%s schema_is_revalida=%s\n",
        count($candidates), count($classSummary),
        $applyMode ? 'yes' : 'no',
        $colExists ? 'yes' : 'no');
    exit(0);
}

if ($jsonMode) {
    echo json_encode([
        'tool'        => 'mark_existing_revalida_sessions',
        'version'     => '1.0.0',
        'dry_run'     => !$applyMode,
        'apply'       => $applyMode,
        'schema_is_revalida_column_present' => $colExists,
        'candidates_count' => count($candidates),
        'classes_affected_count' => count($classSummary),
        'candidates'   => $candidates,
        'class_summary' => array_values($classSummary),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n";
    exit(0);
}

cli_heading($applyMode ? 'Apply revalida backfill' : 'Dry-run: revalida backfill');
echo "Heuristic: session whose related BBB module (via gmk_bbb_attendance_relation) has name LIKE 'Reválida - %'.\n";
echo "Schema mdl_attendance_sessions.is_revalida present: " . ($colExists ? 'YES' : 'NO') . "\n";
echo "Mode: " . ($applyMode ? 'APPLY (UPDATE)' : 'DRY-RUN (SELECT only, no writes)') . "\n\n";

printf("Candidates detected: %d sessions across %d classes.\n\n",
    count($candidates), count($classSummary));

if (!empty($classSummary)) {
    echo "Per-class summary:\n";
    foreach ($classSummary as $cs) {
        printf("  class %d (%s): %d session(s)\n",
            $cs['classid'], $cs['classname'], $cs['session_count']);
    }
    echo "\n";
}

if (!empty($candidates)) {
    echo "Per-session detection:\n";
    foreach ($candidates as $c) {
        printf("  session=%d class=%d (%s) sessdate=%s bbb=%s\n",
            $c['session_id'], $c['classid'], $c['classname'],
            gmdate('Y-m-d H:i', $c['sessdate']),
            implode(' | ', array_map(fn($m) => $m['bbb_name'], $c['bbb_matches']))
        );
    }
    echo "\n";
}

if (!$applyMode) {
    if (!$colExists) {
        cli_warn('NOTE: column is_revalida does NOT exist yet. The upgrade.php savepoint must run before --apply is meaningful.');
    }
    echo "No changes applied. Re-run with --apply to mark them in the DB after running upgrade.php.\n";
    exit(0);
}

// 4. APPLY: UPDATE mdl_attendance_sessions SET is_revalida = 1 WHERE id IN (...).
$ids = array_map(fn($c) => (int)$c['session_id'], $candidates);
list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'rv');
$now = time();
$updates = $DB->execute(
    "UPDATE {attendance_sessions}
        SET is_revalida = 1, timemodified = :now
      WHERE id $insql",
    ['now' => $now] + $params
);

echo "Applied UPDATE to " . count($ids) . " row(s).\n";
echo "Verification (post-apply): " . (int)$DB->count_records_select('attendance_sessions', 'is_revalida = 1') . " sessions now have is_revalida = 1.\n";
exit(0);
