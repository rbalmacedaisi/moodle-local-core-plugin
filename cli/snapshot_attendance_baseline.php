<?php
/**
 * CLI script: snapshot the current attendance/grade state for a list of classes
 * BEFORE we deploy the new is_revalida flag and add the SQL filters.
 *
 * This is purely a SELECT-only diagnostic tool. It writes ONE JSON file into
 * $CFG->dataroot/local_grupomakro_core/baselines/ containing:
 *   - schema_before           (confirms the column does not exist yet)
 *   - classes[]               (per-class session list and per-user grade baseline)
 *   - totals                  (db-wide counts of detected revalida candidates)
 *
 * USAGE:
 *   php snapshot_attendance_baseline.php                           # top 10 most-recent classes
 *   php snapshot_attendance_baseline.php --classids=12,34,56       # explicit class ids
 *   php snapshot_attendance_baseline.php --limit=30                # top N most-recent classes
 *   php snapshot_attendance_baseline.php --out=/path/to/file.json  # explicit output path
 *   php snapshot_attendance_baseline.php --help
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
    fwrite(STDERR, "ERROR: Moodle config.php not found (looked in __DIR__/../../../config.php and /var/www/html/moodle/config.php).\n");
    exit(2);
}

require_once($configpath);
require_once($CFG->libdir . '/clilib.php');

$longoptions = [
    'classids' => '',
    'limit'    => 10,
    'out'      => '',
    'help'     => false,
];
$options = getopt('', ['classids::', 'limit::', 'out::', 'help']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php snapshot_attendance_baseline.php [--classids=1,2,3] [--limit=N] [--out=/path/file.json]\n";
    echo "\n";
    echo "Defaults: top 10 most-recent gmk_class rows. Output file:\n";
    echo "  <dataroot>/local_grupomakro_core/baselines/snapshot_<utc>.json\n";
    exit(0);
}

cli_heading('Snapshot attendance baseline');

$explicitClassIds = [];
if (!empty($options['classids'])) {
    $explicitClassIds = array_values(array_filter(array_map('intval', explode(',', $options['classids']))));
}

$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 10;
$outPath = '';
if (!empty($options['out'])) {
    $outPath = (string)$options['out'];
} else {
    $base = $CFG->dataroot . '/local_grupomakro_core/baselines';
    if (!is_dir($base)) {
        @mkdir($base, 0777, true);
    }
    $outPath = $base . '/snapshot_' . gmdate('Ymd_His') . '.json';
}

// 1. Schema probe: confirm is_revalida is NOT present yet.
$colExists = (bool)$DB->get_record_sql(
    "SELECT 1
       FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'mdl_attendance_sessions'
        AND column_name = 'is_revalida'
      LIMIT 1"
);
if ($colExists) {
    cli_warn('NOTE: mdl_attendance_sessions already has column is_revalida. The snapshot is being taken AFTER the schema change — baseline values will be post-filtration!');
}

// 2. Pick classes.
if (!empty($explicitClassIds)) {
    list($insql, $params) = $DB->get_in_or_equal($explicitClassIds, SQL_PARAMS_NAMED, 'cls');
    $classRows = $DB->get_records_sql(
        "SELECT id, name, corecourseid, gradecategoryid, inittime, endtime
           FROM {gmk_class}
          WHERE id $insql",
        $params
    );
} else {
    $classRows = $DB->get_records_sql(
        "SELECT id, name, corecourseid, gradecategoryid, inittime, endtime
           FROM {gmk_class}
       ORDER BY id DESC
          LIMIT $limit"
    );
}
if (empty($classRows)) {
    cli_error('No classes found for the given criteria.');
}

printf("Classes to snapshot: %d\n", count($classRows));

// 3. Db-wide totals (for sanity / "how many would be flagged" preview).
$totalSessions = (int)$DB->count_records('attendance_sessions');
$candidatesRows = $DB->get_records_sql(
    "SELECT s.id AS session_id
       FROM {attendance_sessions} s
       JOIN {gmk_bbb_attendance_relation} r ON r.attendancesessionid = s.id
       JOIN {course_modules} cm             ON cm.id = r.bbbmoduleid
       JOIN {modules} m                    ON m.id = cm.module AND m.name = 'bigbluebuttonbn'
       JOIN {bigbluebuttonbn} b            ON b.id = cm.instance
      WHERE b.name LIKE 'Reválida - %'"
);
$candidatesCount = count($candidatesRows);
$candidateIds = array_map(function ($r) { return (int)$r->session_id; }, $candidatesRows);

// 4. Per-class snapshot.
$now = time();
$classesOut = [];
foreach ($classRows as $cls) {
    $cid = (int)$cls->id;

    // 4a. Attendance id (via relation table fallback to module id).
    $attId = 0;
    $relAtt = $DB->get_record('gmk_bbb_attendance_relation', ['classid' => $cid], 'attendanceid', IGNORE_MULTIPLE);
    if ($relAtt) {
        $attId = (int)$relAtt->attendanceid;
    }

    // 4b. Sessions for this attendance module.
    $sessionsOut = [];
    if ($attId > 0) {
        $sessRows = $DB->get_records(
            'attendance_sessions',
            ['attendanceid' => $attId],
            'sessdate ASC',
            'id, sessdate, duration, lasttaken, description'
        );
        foreach ($sessRows as $sr) {
            $rels = $DB->get_records(
                'gmk_bbb_attendance_relation',
                ['classid' => $cid, 'attendancesessionid' => (int)$sr->id],
                '',
                'bbbmoduleid'
            );
            $bbbNames = [];
            foreach ($rels as $rr) {
                $bn = $DB->get_record_sql(
                    "SELECT b.name
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module AND m.name = 'bigbluebuttonbn'
                       JOIN {bigbluebuttonbn} b ON b.id = cm.instance
                      WHERE cm.id = :cmid",
                    ['cmid' => (int)$rr->bbbmoduleid],
                    IGNORE_MISSING
                );
                if ($bn && isset($bn->name)) {
                    $bbbNames[] = (string)$bn->name;
                }
            }
            $sessionsOut[] = [
                'session_id'         => (int)$sr->id,
                'sessdate'           => (int)$sr->sessdate,
                'duration'           => (int)$sr->duration,
                'description'        => (string)($sr->description ?? ''),
                'lasttaken'          => (int)$sr->lasttaken,
                'past'               => ((int)$sr->sessdate + (int)$sr->duration) < $now,
                'has_log'            => $DB->record_exists_select(
                    'attendance_log',
                    'sessionid = :sid AND EXISTS (SELECT 1 FROM {attendance_log} l2 WHERE l2.sessionid = :sid2)',
                    ['sid' => (int)$sr->id, 'sid2' => (int)$sr->id]
                ),
                'has_bbb_relation'   => !empty($bbbNames),
                'bbb_names'          => $bbbNames,
                'would_be_revalida'  => (function () use ($bbbNames) {
                    foreach ($bbbNames as $n) {
                        if (strpos($n, 'Reválida - ') === 0) { return true; }
                    }
                    return false;
                })(),
            ];
        }
    }

    // 4c. Users in the class + their weighted grades (will be re-measured after the change).
    $userIds = $DB->get_fieldset_sql(
        "SELECT userid FROM {gmk_course_progre} WHERE classid = :cid GROUP BY userid",
        ['cid' => $cid]
    );
    $gradesOut = [];
    if (!empty($userIds) && !empty($attId)) {
        foreach ($userIds as $uid) {
            $attRow = $DB->get_record_sql(
                "SELECT COUNT(s.id) AS total,
                        SUM(CASE WHEN al.id IS NOT NULL AND ast.grade > 0 THEN 1 ELSE 0 END) AS present
                   FROM {attendance_sessions} s
                   LEFT JOIN {attendance_log} al ON al.sessionid = s.id AND al.studentid = :uid
                   LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
                  WHERE s.attendanceid = :attid
                    AND s.sessdate + s.duration < :now
                    AND (EXISTS (SELECT 1 FROM {attendance_log} l WHERE l.sessionid = s.id)
                         OR COALESCE(s.lasttaken, 0) > 0)",
                ['uid' => (int)$uid, 'attid' => $attId, 'now' => $now]
            );
            $tot = (int)($attRow->total ?? 0);
            $pres = (int)($attRow->present ?? 0);
            $pct = $tot > 0 ? round(($pres / $tot) * 100, 1) : null;
            $gradesOut[] = [
                'userid'             => (int)$uid,
                'attendance_total'   => $tot,
                'attendance_present' => $pres,
                'attendance_pct'     => $pct,
            ];
        }
    }

    $classesOut[] = [
        'classid'        => $cid,
        'classname'      => (string)($cls->name ?? ''),
        'corecourseid'   => (int)($cls->corecourseid ?? 0),
        'inittime'       => (int)($cls->inittime ?? 0),
        'endtime'        => (int)($cls->endtime ?? 0),
        'attendanceid'   => $attId,
        'sessions'       => $sessionsOut,
        'per_user_attendance_baseline' => $gradesOut,
    ];

    printf("  - class %d (%s): %d sessions, %d users\n",
        $cid, $cls->name ?? '', count($sessionsOut), count($gradesOut));
}

$snapshot = [
    'tool'             => 'snapshot_attendance_baseline',
    'version'          => '1.0.0',
    'captured_at_utc'  => gmdate('Y-m-d\TH:i:s\Z'),
    'schema_before'    => [
        'attendance_sessions_has_is_revalida_column' => $colExists,
    ],
    'detection_heuristic' => "attendance_sessions JOIN gmk_bbb_attendance_relation (relation) JOIN course_modules (module=bigbluebuttonbn) JOIN bigbluebuttonbn WHERE b.name LIKE 'Reválida - %'",
    'totals' => [
        'attendance_sessions_total'        => $totalSessions,
        'candidates_flagged_by_heuristic' => $candidatesCount,
    ],
    'candidate_session_ids' => $candidateIds,
    'classes' => $classesOut,
];

$ok = file_put_contents($outPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if ($ok === false) {
    cli_error("Failed to write snapshot JSON to: {$outPath}");
}

cli_heading('Snapshot written');
echo "File: {$outPath}\n";
printf("Bytes: %d\n", $ok);
printf("Candidates (would-be revalida sessions): %d\n", $candidatesCount);
echo "\nNEXT STEP: copy this file somewhere safe BEFORE running upgrade.php.\n";
exit(0);
