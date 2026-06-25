<?php
/**
 * One-off cleanup: deletes every row in gmk_class_absence_state so the
 * next cron run can rebuild the state from scratch with the corrected
 * recompute logic. Also resets the gmk_course_progre.blocked_by_absence
 * flag to 0 across all rows so the LXP and the Moodle access guard
 * stop reflecting stale block decisions from the previous period.
 *
 * Use this after a deploy that touched the recompute logic (level
 * transitions, dismissal resets, blocked_at / unblocked_at handling)
 * to give students a clean baseline.
 *
 * Usage:
 *   /local/grupomakro_core/pages/reset_absence_state.php
 *   /local/grupomakro_core/pages/reset_absence_state.php?dryrun=1
 *   /local/grupomakro_core/pages/reset_absence_state.php?keep_history=1
 *   /local/grupomakro_core/pages/reset_absence_state.php?confirm=1
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();
require_capability('local/grupomakro_core:viewabsencedashboard', context_system::instance());

$dryrun      = optional_param('dryrun', 0, PARAM_INT) === 1;
$confirm     = optional_param('confirm', 0, PARAM_INT) === 1;
$keephistory = optional_param('keep_history', 0, PARAM_INT) === 1;

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/reset_absence_state.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo '<h2>Limpieza del registro de inasistencias</h2>';
echo '<p>Esta acción borra todas las filas de <code>gmk_class_absence_state</code> y resetea los flags <code>gmk_course_progre.blocked_by_absence</code> a 0. El cron de mañana (04:00) las va a regenerar con la lógica corregida de <code>absd_recompute_user_class_state</code>.</p>';

if (!$dryrun && !$confirm) {
    echo '<div style="margin-top:14px;padding:14px 18px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;color:#92400e">'
        . '<strong>Advertencia:</strong> operación destructiva. Ejecute con <code>&amp;confirm=1</code> para aplicar.'
        . '</div>';
}

// Counts.
$state_count = (int)$DB->count_records('gmk_class_absence_state');
$history_count = (int)$DB->count_records('gmk_class_absence_history');
$prog_count = (int)$DB->count_records_select(
    'gmk_course_progre',
    'blocked_by_absence = 1'
);

echo '<h3 style="margin-top:18px">Estado actual</h3>';
echo '<ul>';
echo '<li>Filas en <code>gmk_class_absence_state</code>: <strong>' . $state_count . '</strong></li>';
echo '<li>Filas en <code>gmk_class_absence_history</code>: <strong>' . $history_count . '</strong> '
    . ($keephistory ? '(se preservan)' : '(se preservan)') . '</li>';
echo '<li><code>gmk_course_progre.blocked_by_absence = 1</code>: <strong>' . $prog_count . '</strong></li>';
echo '</ul>';

// Group by alert_level for context.
$by_level = $DB->get_records_sql(
    "SELECT alert_level, COUNT(*) AS n
       FROM {gmk_class_absence_state}
   GROUP BY alert_level
   ORDER BY alert_level"
);
if (!empty($by_level)) {
    echo '<h3 style="margin-top:18px">Distribución por alert_level</h3><ul>';
    foreach ($by_level as $r) {
        echo '<li>level ' . (int)$r->alert_level . ': <strong>' . (int)$r->n . '</strong></li>';
    }
    echo '</ul>';
}

if ($dryrun) {
    echo '<p style="margin-top:14px;color:#0369a1"><strong>Modo dry-run:</strong> no se modificó nada. Ejecute con <code>&amp;confirm=1</code> para aplicar.</p>';
} else {
    if (!$confirm) {
        echo '<p style="margin-top:14px;color:#b71c1c"><strong>Operación no ejecutada.</strong> Agregue <code>&amp;confirm=1</code> a la URL para aplicar.</p>';
    } else {
        $nowts = time();

        // 1. Truncate gmk_class_absence_state.
        $DB->delete_records('gmk_class_absence_state');

        // 2. Reset gmk_course_progre.blocked_by_absence.
        $DB->set_field('gmk_course_progre', 'blocked_by_absence', 0);
        $DB->set_field('gmk_course_progre', 'blocked_by_absence_at', 0);

        // 3. Optionally clear the history too.
        if (!$keephistory) {
            $DB->delete_records('gmk_class_absence_history');
        }

        // 4. Audit log entry.
        $detail = sprintf(
            "bulk_reset_absence_state by user #%d (%s); keep_history=%s; users=%d; state_rows_deleted=%d",
            (int)$USER->id,
            fullname($USER),
            $keephistory ? '1' : '0',
            0, // placeholder, updated below
            $state_count
        );
        // Note: we use a synthetic userid 0 since the reset is a bulk admin op.
        absd_log_history(0, 0, 0, 0, 'bulk_reset_absence_state', $detail);

        echo '<p style="margin-top:14px;color:#166534"><strong>&#10003; Limpieza completada.</strong></p>';
        echo '<ul>';
        echo '<li>Filas eliminadas de <code>gmk_class_absence_state</code>: <strong>' . $state_count . '</strong></li>';
        echo '<li>Filas de <code>gmk_course_progre</code> reseteadas a 0: <strong>' . $prog_count . '</strong></li>';
        if (!$keephistory) {
            echo '<li>Historial <code>gmk_class_absence_history</code> limpiado.</li>';
        } else {
            echo '<li>Historial preservado (se mantienen las ' . $history_count . ' filas).</li>';
        }
        echo '</ul>';

        echo '<p>El próximo cron a las 04:00 va a recalcular todo con la lógica corregida. Los estudiantes verán alertas precisas en su próximo login al LXP.</p>';
    }
}

echo $OUTPUT->footer();
