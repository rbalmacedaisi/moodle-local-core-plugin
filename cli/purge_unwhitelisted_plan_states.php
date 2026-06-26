<?php
/**
 * Purge absence alerts/state for learning plans that are NOT in the
 * `absence_alert_planids` whitelist.
 *
 * Removes rows from:
 *  - gmk_class_absence_state (state derived from recompute)
 *  - gmk_class_absence_history (audit log rows for the affected classes)
 *  - gmk_course_progre.blocked_by_absence (cleared to 0 if a non-whitelisted
 *    plan class was blocked, so the student is not held back from class
 *    navigation).
 *
 * Also clears `gmk_class_absence_history` rows referencing classes whose
 * learningplan is excluded.
 *
 * Usage (CLI):
 *   sudo -u www-data php local/grupomakro_core/cli/purge_unwhitelisted_plan_states.php            # execute
 *   sudo -u www-data php local/grupomakro_core/cli/purge_unwhitelisted_plan_states.php --dry-run  # preview
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

$dryrun = in_array('--dry-run', $argv ?? [], true);

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
    fwrite(STDERR, "[purge_unwhitelisted_plan_states] No admin available to run as.\n");
    exit(2);
}

$raw = (string)get_config('local_grupomakro_core', 'absence_alert_planids');
$whitelist = [];
foreach (explode(',', $raw) as $piece) {
    $v = (int)trim($piece);
    if ($v > 0) {
        $whitelist[$v] = $v;
    }
}

echo "[purge_unwhitelisted_plan_states] Running as user #{$admin->id} ({$admin->firstname} {$admin->lastname})\n";
echo "[purge_unwhitelisted_plan_states] dry_run=" . ($dryrun ? 'yes' : 'no') . "\n";
echo "[purge_unwhitelisted_plan_states] Whitelist (allowed plans): "
    . (empty($whitelist) ? '(empty = all plans allowed)' : implode(',', $whitelist)) . "\n";

if (empty($whitelist)) {
    echo "[purge_unwhitelisted_plan_states] Whitelist empty -> nothing to purge.\n";
    exit(0);
}

global $DB;

// All classes that belong to a NON-whitelisted plan.
[$notinsql, $notinparams] = $DB->get_in_or_equal(array_values($whitelist), SQL_PARAMS_NAMED, 'wl', false);
// SQL_PARAMS_NAMED + false final arg gives "NOT IN (...)".
$excluded_classes = $DB->get_records_sql(
    "SELECT id, name, learningplanid
       FROM {gmk_class}
      WHERE learningplanid $notinsql",
    $notinparams
);

if (empty($excluded_classes)) {
    echo "[purge_unwhitelisted_plan_states] No classes outside the whitelist.\n";
    exit(0);
}

$excluded_class_ids = array_keys($excluded_classes);
$by_plan = [];
foreach ($excluded_classes as $cid => $cls) {
    $lp = (int)($cls->learningplanid ?? 0);
    $by_plan[$lp][] = (int)$cid;
}

echo "[purge_unwhitelisted_plan_states] Excluded classes: " . count($excluded_class_ids) . "\n";
foreach ($by_plan as $lp => $cids) {
    echo "  - learningplanid={$lp}: " . count($cids) . " classes\n";
}

[$csinsql, $csinparams] = $DB->get_in_or_equal($excluded_class_ids, SQL_PARAMS_NAMED, 'excl');

// 1) Count state rows to delete.
$state_count = (int)$DB->count_records_select('gmk_class_absence_state', "classid $csinsql", $csinparams);

// 2) Count history rows to delete.
$history_count = (int)$DB->count_records_select('gmk_class_absence_history', "classid $csinsql", $csinparams);

// 3) Count blocked enrollments to clear.
$blocked_count = (int)$DB->count_records_select('gmk_course_progre', "classid $csinsql AND blocked_by_absence = 1", $csinparams);

echo "[purge_unwhitelisted_plan_states] Rows to remove:\n";
echo "  gmk_class_absence_state:    {$state_count}\n";
echo "  gmk_class_absence_history:  {$history_count}\n";
echo "  gmk_course_progre blocked_by_absence=1: {$blocked_count}\n";

if ($dryrun) {
    echo "\n[purge_unwhitelisted_plan_states] DRY-RUN: no changes applied.\n";
    exit(0);
}

$DB->delete_records_select('gmk_class_absence_state', "classid $csinsql", $csinparams);
$DB->delete_records_select('gmk_class_absence_history', "classid $csinsql", $csinparams);
$DB->set_field_select('gmk_course_progre', 'blocked_by_absence', 0, "classid $csinsql AND blocked_by_absence = 1", $csinparams);

// Audit-log a synthetic event so operators see the bulk cleanup.
absd_log_history(0, 0, 0, 0, 'purge_unwhitelisted_plans',
    sprintf('plans=%s deleted_state=%d deleted_history=%d cleared_blocks=%d at %s',
        implode(',', array_keys($by_plan)), $state_count, $history_count, $blocked_count, userdate(time())));

echo "\n[purge_unwhitelisted_plan_states] DONE.\n";
echo "  gmk_class_absence_state rows deleted:    {$state_count}\n";
echo "  gmk_class_absence_history rows deleted:  {$history_count}\n";
echo "  blocked_by_absence flags cleared:        {$blocked_count}\n";