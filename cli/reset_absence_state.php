<?php
/**
 * CLI version of reset_absence_state.php. Same operation, but it runs as
 * the first admin user it finds in the system and skips the page-level
 * login requirement (which is for browser access). Useful for cron-style
 * one-off executions via ssh.
 *
 * Usage:
 *   sudo -u www-data php cli/reset_absence_state.php            # execute
 *   sudo -u www-data php cli/reset_absence_state.php --dry-run  # preview
 *   sudo -u www-data php cli/reset_absence_state.php --keep-history
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

$dryrun      = in_array('--dry-run', $argv, true);
$keephistory = in_array('--keep-history', $argv, true);

// Authenticate as the first admin user found (needed for capability checks
// in helpers that use $USER).
$admin = $DB->get_record_sql(
    "SELECT u.id, u.firstname, u.lastname, u.username
       FROM {user} u
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {role} r ON r.id = ra.roleid
       JOIN {context} c ON c.id = ra.contextid
      WHERE r.shortname IN ('manager', 'administrator', 'editingteacher', 'coursecreator')
            AND c.contextlevel = :sysctx
        AND u.deleted = 0 AND u.suspended = 0
   ORDER BY (r.shortname = 'administrator') DESC, (r.shortname = 'manager') DESC,
            u.id ASC
      LIMIT 1",
    ['sysctx' => CONTEXT_SYSTEM]
);
if (!$admin) {
    // Fallback: any non-guest, non-deleted user.
    $admin = $DB->get_record_sql(
        "SELECT u.id, u.firstname, u.lastname, u.username
           FROM {user} u
          WHERE u.id > 1 AND u.deleted = 0 AND u.suspended = 0
       ORDER BY u.id ASC LIMIT 1"
    );
}
if (!$admin) {
    fwrite(STDERR, "No admin user found in the system.\n");
    exit(1);
}

// Pretend the admin is logged in for the duration of this CLI invocation.
// Moodle 4.x+ signature is login_user(stdClass $user) — fetch the full
// record and load the user object into $USER so capability checks
// performed inside the helpers pass.
$adminobj = core_user::get_user($admin->id);
if (!$adminobj) {
    fwrite(STDERR, "Admin user #{$admin->id} not found.\n");
    exit(1);
}
\core\session\manager::login_user($adminobj);
$USER = $adminobj;

echo "[reset_absence_state] Running as user #{$admin->id} ({$admin->firstname} {$admin->lastname})\n";
echo "[reset_absence_state] dry_run=" . ($dryrun ? 'yes' : 'no') . "\n";
echo "[reset_absence_state] keep_history=" . ($keephistory ? 'yes' : 'no') . "\n\n";

// Counts.
$state_count   = (int)$DB->count_records('gmk_class_absence_state');
$history_count = (int)$DB->count_records('gmk_class_absence_history');
$prog_count    = (int)$DB->count_records_select('gmk_course_progre', 'blocked_by_absence = 1');

echo "[reset_absence_state] Current state:\n";
echo "  gmk_class_absence_state rows:           {$state_count}\n";
echo "  gmk_class_absence_history rows:          {$history_count}\n";
echo "  gmk_course_progre.blocked_by_absence=1: {$prog_count}\n";

$by_level = $DB->get_records_sql(
    "SELECT alert_level, COUNT(*) AS n
       FROM {gmk_class_absence_state}
   GROUP BY alert_level
   ORDER BY alert_level"
);
if (!empty($by_level)) {
    echo "\n  Distribution by alert_level:\n";
    foreach ($by_level as $r) {
        echo "    level " . (int)$r->alert_level . ": " . (int)$r->n . "\n";
    }
}

if ($dryrun) {
    echo "\n[reset_absence_state] DRY-RUN: no changes applied.\n";
    exit(0);
}

$nowts = time();
$DB->delete_records('gmk_class_absence_state');
$DB->set_field('gmk_course_progre', 'blocked_by_absence', 0);
$DB->set_field('gmk_course_progre', 'blocked_by_absence_at', 0);
if (!$keephistory) {
    $DB->delete_records('gmk_class_absence_history');
}

absd_log_history(
    0,
    0,
    0,
    0,
    'bulk_reset_absence_state',
    sprintf(
        "CLI reset by user #%d (%s); keep_history=%s; state_rows_deleted=%d",
        (int)$admin->id,
        fullname($admin),
        $keephistory ? '1' : '0',
        $state_count
    )
);

echo "\n[reset_absence_state] DONE.\n";
echo "  gmk_class_absence_state rows deleted:    {$state_count}\n";
echo "  gmk_course_progre rows reset to 0:       {$prog_count}\n";
if (!$keephistory) {
    echo "  gmk_class_absence_history rows deleted:  {$history_count}\n";
} else {
    echo "  gmk_class_absence_history preserved:    {$history_count} rows kept\n";
}
echo "  The next cron at 04:00 will repopulate the state with the corrected logic.\n";
exit(0);
