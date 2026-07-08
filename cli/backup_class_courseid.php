<?php
/**
 * One-time: snapshot the current state of gmk_class.courseid and
 * gmk_class.corecourseid into a backup table so we can recover if the
 * courseid mismatch fix turned out to be wrong for some rows.
 *
 * Idempotent: drops and recreates the backup table each run.
 *
 * Usage:
 *   sudo -u www-data php local/grupomakro_core/cli/backup_class_courseid.php
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

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
    fwrite(STDERR, "[backup_class_courseid] No admin available to run as.\n");
    exit(2);
}

global $DB;

echo "[backup_class_courseid] Running as user #{$admin->id}\n";

// Drop & recreate the backup table (Moodle xmldb).
$table = new xmldb_table('gmk_class_courseid_backup');
if ($DB->get_manager()->table_exists($table)) {
    $DB->get_manager()->drop_table($table);
    echo "[backup_class_courseid] Dropped existing backup table.\n";
}

$table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
$table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
$table->add_field('classname', XMLDB_TYPE_CHAR, '255', null, null);
$table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null);
$table->add_field('corecourseid', XMLDB_TYPE_INTEGER, '10', null, null);
$table->add_field('snapshot_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
$table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
$table->add_index('classid_idx', XMLDB_INDEX_NOTUNIQUE, ['classid']);

$DB->get_manager()->create_table($table);
echo "[backup_class_courseid] Created backup table gmk_class_courseid_backup.\n";

// Snapshot every class.
$nowts = time();
$rows = $DB->get_records('gmk_class', null, 'id ASC', 'id, name, courseid, corecourseid');
$inserted = 0;
foreach ($rows as $r) {
    $rec = new stdClass();
    $rec->classid      = (int)$r->id;
    $rec->classname    = $r->name;
    $rec->courseid     = $r->courseid !== null ? (int)$r->courseid : null;
    $rec->corecourseid = $r->corecourseid !== null ? (int)$r->corecourseid : null;
    $rec->snapshot_at  = $nowts;
    $DB->insert_record('gmk_class_courseid_backup', $rec);
    $inserted++;
}

echo "[backup_class_courseid] Snapshot complete: {$inserted} classes archived.\n";
echo "[backup_class_courseid] To roll back: see cli/restore_class_courseid.php\n";