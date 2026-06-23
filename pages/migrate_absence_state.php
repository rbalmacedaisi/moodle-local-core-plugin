<?php
/**
 * One-off migration: populate gmk_class_absence_state for all active
 * enrollments and recompute the absence counters using the same logic
 * the cron / observer will use from now on.
 *
 * Usage (browser or CLI):
 *   /local/grupomakro_core/pages/migrate_absence_state.php
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$nowts = time();
$dryrun = optional_param('dryrun', 0, PARAM_INT) === 1;
$classid_param = optional_param('classid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/migrate_absence_state.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo '<h2>Migración del estado de inasistencias</h2>';

if (!absd_is_staged_alerts_enabled()) {
    echo '<p style="color:#b71c1c"><strong>Advertencia:</strong> el feature flag <code>enable_absence_alerts</code> está desactivado. La migración recalculará el estado pero no se aplicarán bloqueos hasta activar el flag.</p>';
}

$classsql = "SELECT id, courseid, corecourseid, groupid, attendancemoduleid, initdate, enddate
               FROM {gmk_class}
              WHERE approved = 1 AND closed = 0 AND enddate > :now";
$classparams = ['now' => $nowts];
if ($classid_param > 0) {
    $classsql .= ' AND id = :cid';
    $classparams['cid'] = $classid_param;
}
$classes = $DB->get_records_sql($classsql, $classparams);

$total_users = 0;
$total_state_rows = 0;
$blocked_now = 0;
$would_block = 0;
$errors = [];

foreach ($classes as $class) {
    $cid = (int)$class->id;
    $enrolled = absd_get_class_enrolled_userids($cid);
    if (empty($enrolled)) {
        continue;
    }
    foreach ($enrolled as $uid) {
        $uid = (int)$uid;
        $total_users++;

        if ($dryrun) {
            $pastsessionids  = absd_get_class_past_session_ids($class, $nowts);
            $takensessionids = absd_get_taken_session_ids($pastsessionids);
            $count = 0;
            if (!empty($takensessionids)) {
                $count = (int)(absd_get_student_absences($takensessionids, [$uid])[$uid] ?? 0);
            }
            $level = absd_level_for_count($count);
            if ($level === 3) {
                $would_block++;
            }
            continue;
        }

        try {
            $result = absd_recompute_user_class_state($class, $uid);
            $total_state_rows++;
            if (in_array('block', $result['transitions'], true)) {
                if (!absd_is_user_exempt($uid, $cid)) {
                    absd_apply_class_block($uid, $cid, 'attendance_threshold_reached (migration)');
                    $blocked_now++;
                }
            }
        } catch (Throwable $e) {
            $errors[] = "Clase {$cid} / Usuario {$uid}: " . $e->getMessage();
        }
    }
}

echo '<ul>';
echo '<li>Clases procesadas: ' . count($classes) . '</li>';
echo '<li>Usuarios procesados: ' . $total_users . '</li>';
if ($dryrun) {
    echo '<li>(dry-run) Bloqueos que se aplicarían: ' . $would_block . '</li>';
} else {
    echo '<li>Filas de estado escritas/actualizadas: ' . $total_state_rows . '</li>';
    echo '<li>Bloqueos de clase aplicados en esta pasada: ' . $blocked_now . '</li>';
}
if (!empty($errors)) {
    echo '<li style="color:#b71c1c">Errores: ' . count($errors) . '</li>';
}
echo '</ul>';

if (!empty($errors)) {
    echo '<h3>Detalle de errores</h3><pre style="max-height:300px;overflow:auto">';
    echo htmlspecialchars(implode("\n", $errors));
    echo '</pre>';
}

echo '<p>Recuerde activar el feature flag <code>enable_absence_alerts</code> en la configuración del plugin para que el sistema entre en efecto en la próxima ejecución del cron.</p>';

echo $OUTPUT->footer();
