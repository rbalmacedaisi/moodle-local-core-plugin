<?php
/**
 * One-off cleanup: removes stale rows from gmk_class_absence_state that
 * reference classes the student is no longer actively enrolled in (status
 * not in 1,2,3 in gmk_course_progre). These rows can appear when the
 * observer fires for a student that has been unenrolled or when a teacher
 * marks attendance for someone outside the group. Without this cleanup
 * the LXP / dashboard can show absence alerts for classes the student
 * is no longer taking.
 *
 * Usage:
 *   /local/grupomakro_core/pages/purge_stale_absence_state.php
 *   /local/grupomakro_core/pages/purge_stale_absence_state.php?dryrun=1
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();
require_capability('local/grupomakro_core:viewabsencedashboard', context_system::instance());

$dryrun = optional_param('dryrun', 0, PARAM_INT) === 1;

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/purge_stale_absence_state.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo '<h2>Limpieza de estados de inasistencia huérfanos</h2>';
echo '<p>Elimina filas de <code>gmk_class_absence_state</code> cuyo (userid, classid) ya no está matriculado activamente (status IN 1,2,3 en <code>gmk_course_progre</code>).</p>';

// Find stale rows.
$sql = "SELECT s.id, s.userid, s.classid, s.absence_count, s.alert_level
          FROM {gmk_class_absence_state} s
     LEFT JOIN {gmk_course_progre} gcp
            ON gcp.userid  = s.userid
           AND gcp.classid = s.classid
           AND gcp.status IN (1, 2, 3)
         WHERE gcp.id IS NULL";
$rows = $DB->get_records_sql($sql);
$count = count($rows);

echo '<p>Filas huérfanas detectadas: <strong>' . $count . '</strong></p>';

if ($count > 0 && !$dryrun) {
    $ids = array_keys($rows);
    [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'del');
    $DB->delete_records_select('gmk_class_absence_state', "id $insql", $inparams);
    foreach ($rows as $r) {
        absd_log_history(
            (int)$r->userid,
            (int)$r->classid,
            (int)$r->absence_count,
            (int)$r->alert_level,
            'purged_bulk_cleanup'
        );
    }
    echo '<p style="color:#166534"><strong>&#10003; Limpieza completada.</strong> ' . $count . ' filas eliminadas y registradas en el log de auditoría.</p>';
} elseif ($dryrun) {
    echo '<p style="color:#0369a1"><strong>Modo dry-run:</strong> no se eliminó nada. Ejecute sin <code>dryrun=1</code> para aplicar.</p>';
    if ($count > 0) {
        echo '<details><summary>Vista previa (primeros 50)</summary><pre style="max-height:240px;overflow:auto">';
        $i = 0;
        foreach ($rows as $r) {
            if ($i++ >= 50) break;
            $u = core_user::get_user($r->userid);
            echo sprintf("userid=%d  classid=%d  count=%d  level=%d  user=%s\n",
                $r->userid,
                $r->classid,
                $r->absence_count,
                $r->alert_level,
                $u ? fullname($u) : '(?)'
            );
        }
        echo '</pre></details>';
    }
} else {
    echo '<p style="color:#166534">No hay filas huérfanas. La base de datos está limpia.</p>';
}

echo $OUTPUT->footer();
