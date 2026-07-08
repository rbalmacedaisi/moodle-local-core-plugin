<?php
/**
 * Roll back gmk_class.courseid and/or gmk_class.corecourseid to the values
 * archived in gmk_class_courseid_backup (created by backup_class_courseid.php).
 *
 * Usage:
 *   # Preview all changes (uses backup table as truth):
 *   sudo -u www-data php local/grupomakro_core/cli/restore_class_courseid.php --dry-run
 *
 *   # Roll back specific classids:
 *   sudo -u www-data php local/grupomakro_core/cli/restore_class_courseid.php --classid 9572 --classid 9579
 *
 *   # Roll back EVERY class (full restore):
 *   sudo -u www-data php local/grupomakro_core/cli/restore_class_courseid.php --all
 *
 *   # Show the backup snapshot:
 *   sudo -u www-data php local/grupomakro_core/cli/restore_class_courseid.php --show
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

$dryrun = in_array('--dry-run', $argv ?? [], true);
$restoreall = in_array('--all', $argv ?? [], true);
$show = in_array('--show', $argv ?? [], true);

$classids = [];
foreach ($argv ?? [] as $i => $a) {
    if ($a === '--classid' && isset($argv[$i + 1])) {
        $classids[] = (int)$argv[$i + 1];
    }
}

$admin = null;
if (isloggedin() && !isguestuser()) {
    $admin = $USER;
}
if (!$admin) {
    $admins = get_admins();
    if (!empty($admins)) {
        $admin = reset($admins);
        \core\session\manager::login_user($admin);
    }
}
if (!$admin) {
    fwrite(STDERR, "[restore_class_courseid] No admin available to run as.\n");
    exit(2);
}

global $DB;

if ($show) {
    echo "[restore_class_courseid] Showing backup snapshot:\n";
    $rows = $DB->get_records('gmk_class_courseid_backup', null, 'classid ASC');
    echo "  classid | courseid | corecourseid | classname\n";
    foreach ($rows as $r) {
        printf("  %-7d | %-8s | %-12s | %s\n",
            $r->classid,
            $r->courseid === null ? 'NULL' : $r->courseid,
            $r->corecourseid === null ? 'NULL' : $r->corecourseid,
            $r->classname
        );
    }
    exit(0);
}

if (!$restoreall && empty($classids)) {
    fwrite(STDERR, "[restore_class_courseid] Specify --all or --classid <id> (can repeat).\n");
    exit(1);
}

// Load backup rows.
if ($restoreall) {
    $rows = $DB->get_records('gmk_class_courseid_backup');
} else {
    [$insql, $inparams] = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'rb');
    $rows = $DB->get_records_select('gmk_class_courseid_backup', "classid $insql", $inparams);
}

if (empty($rows)) {
    echo "[restore_class_courseid] No matching backup rows.\n";
    exit(0);
}

echo "[restore_class_courseid] Running as user #{$admin->id}\n";
echo "[restore_class_courseid] dry_run=" . ($dryrun ? 'yes' : 'no') . " classes=" . count($rows) . "\n\n";

$courseid_updates = 0;
$corecourseid_updates = 0;
$unchanged = 0;

foreach ($rows as $bk) {
    $current = $DB->get_record('gmk_class', ['id' => $bk->classid], 'id, courseid, corecourseid');
    if (!$current) {
        continue;
    }
    $cur_courseid = $current->courseid !== null ? (int)$current->courseid : null;
    $cur_corecourseid = $current->corecourseid !== null ? (int)$current->corecourseid : null;
    $bk_courseid = $bk->courseid !== null ? (int)$bk->courseid : null;
    $bk_corecourseid = $bk->corecourseid !== null ? (int)$bk->corecourseid : null;

    $courseid_changed = ($cur_courseid !== $bk_courseid);
    $corecourseid_changed = ($cur_corecourseid !== $bk_corecourseid);

    if (!$courseid_changed && !$corecourseid_changed) {
        $unchanged++;
        continue;
    }

    printf("  classid=%-6d  courseid %s -> %s  |  corecourseid %s -> %s  |  %s\n",
        $bk->classid,
        $cur_courseid === null ? 'NULL' : $cur_courseid,
        $bk_courseid === null ? 'NULL' : $bk_courseid,
        $cur_corecourseid === null ? 'NULL' : $cur_corecourseid,
        $bk_corecourseid === null ? 'NULL' : $bk_corecourseid,
        $bk->classname
    );

    if (!$dryrun) {
        if ($courseid_changed) {
            $DB->set_field('gmk_class', 'courseid', $bk_courseid, ['id' => $bk->classid]);
            $courseid_updates++;
        }
        if ($corecourseid_changed) {
            $DB->set_field('gmk_class', 'corecourseid', $bk_corecourseid, ['id' => $bk->classid]);
            $corecourseid_updates++;
        }
    }
}

if ($dryrun) {
    echo "\n[restore_class_courseid] DRY-RUN done. Re-run without --dry-run to apply.\n";
} else {
    echo "\n[restore_class_courseid] DONE.\n";
    echo "  courseid updates:     {$courseid_updates}\n";
    echo "  corecourseid updates: {$corecourseid_updates}\n";
    echo "  unchanged:            {$unchanged}\n";
}