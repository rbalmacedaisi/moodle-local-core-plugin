<?php
/**
 * One-off operation: clears all absence_exempt_<userid> config entries
 * created by the bulk-exempt migration. Run at the start of a new
 * academic period to make the absence alert system start from a clean
 * slate (every student is again subject to the new rules).
 *
 * Usage:
 *   /local/grupomakro_core/pages/clear_period_exemptions.php
 *   /local/grupomakro_core/pages/clear_period_exemptions.php?dryrun=1
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();
require_capability('local/grupomakro_core:viewabsencedashboard', context_system::instance());

$dryrun = optional_param('dryrun', 0, PARAM_INT) === 1;
$nowts  = time();

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/clear_period_exemptions.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo '<h2>' . get_string('clear_period_exemptions_title', 'local_grupomakro_core') . '</h2>';
echo '<p>' . get_string('clear_period_exemptions_desc', 'local_grupomakro_core') . '</p>';

$currentcount = (int)$DB->count_records_select(
    'config_plugins',
    "plugin = ? AND name LIKE ?",
    ['local_grupomakro_core', 'absence_exempt_%']
);
echo '<p>Exenciones presentes actualmente: <strong>' . $currentcount . '</strong></p>';

if ($dryrun) {
    echo '<p style="color:#0369a1"><strong>Modo dry-run:</strong> no se eliminó nada. Ejecute sin <code>dryrun=1</code> para aplicar.</p>';
} else {
    $count = absd_clear_all_period_exemptions();
    absd_log_history(0, 0, 0, 0, 'clear_period_exemptions', sprintf(
        "%s cleared %d exemption entries",
        fullname($USER),
        $count
    ));
    echo '<p style="color:#166534"><strong>&#10003; ' . get_string('clear_period_exemptions_complete', 'local_grupomakro_core', $count) . '</strong></p>';
}

echo $OUTPUT->footer();
