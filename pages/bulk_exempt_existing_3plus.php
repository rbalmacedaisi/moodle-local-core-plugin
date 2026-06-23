<?php
/**
 * One-off migration: bulk-exempt students who already have 3+ absences at
 * the moment the new absence alert system goes live. Designed for the
 * soft-launch mid-period deploy: with these exemptions in place, the new
 * cron won't suspend any student who had already accumulated 3+
 * absences before the rollout. The first block will only happen for a
 * student who reaches the threshold *after* the deploy.
 *
 * Usage:
 *   /local/grupomakro_core/pages/bulk_exempt_existing_3plus.php
 *   /local/grupomakro_core/pages/bulk_exempt_existing_3plus.php?dryrun=1
 *   /local/grupomakro_core/pages/bulk_exempt_existing_3plus.php?classid=123
 *
 * The reverse operation lives in clear_period_exemptions.php and should
 * be run at the start of the next academic period.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();
require_capability('local/grupomakro_core:viewabsencedashboard', context_system::instance());

$dryrun       = optional_param('dryrun', 0, PARAM_INT) === 1;
$classidparam = optional_param('classid', 0, PARAM_INT);
$periodid     = optional_param('periodid', 0, PARAM_INT);
$nowts        = time();
$threshold    = absd_get_block_threshold();

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/bulk_exempt_existing_3plus.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo '<h2>' . get_string('bulk_exempt_legacy_title', 'local_grupomakro_core') . '</h2>';
echo '<p>' . get_string('bulk_exempt_legacy_desc', 'local_grupomakro_core') . '</p>';
echo '<p><strong>Umbral configurado: ' . $threshold . ' inasistencias</strong></p>';

$classsql = "SELECT id, courseid, corecourseid, groupid, attendancemoduleid, initdate, enddate, learningplanid
               FROM {gmk_class}
              WHERE approved = 1 AND closed = 0 AND enddate > :now";
$classparams = ['now' => $nowts];
if ($classidparam > 0) {
    $classsql .= ' AND id = :cid';
    $classparams['cid'] = $classidparam;
}
$classes = $DB->get_records_sql($classsql, $classparams);

if ($periodid > 0) {
    $classes = array_filter($classes, static function($cls) use ($periodid) {
        return (int)($cls->learningplanid ?? 0) === (int)$periodid;
    });
}

$usersaffected = [];
$classesaffected = [];
$totalchecked = 0;
$skippedalreadyexempt = 0;

foreach ($classes as $class) {
    $cid = (int)$class->id;
    $pastsessionids  = absd_get_class_past_session_ids($class, $nowts);
    $takensessionids = absd_get_taken_session_ids($pastsessionids);
    if (empty($takensessionids)) {
        continue;
    }
    $enrolled = absd_get_class_enrolled_userids($cid);
    if (empty($enrolled)) {
        continue;
    }
    $absencemap = absd_get_student_absences($takensessionids, $enrolled);
    foreach ($enrolled as $uid) {
        $uid = (int)$uid;
        $totalchecked++;
        $count = (int)($absencemap[$uid] ?? 0);
        if ($count < $threshold) {
            continue;
        }
        if (absd_is_user_exempt($uid, 0)) {
            $skippedalreadyexempt++;
            continue;
        }
        $usersaffected[$uid] = ($usersaffected[$uid] ?? 0) + 1;
        $classesaffected[$cid] = ($classesaffected[$cid] ?? 0) + 1;
    }
}

echo '<h3>Resumen</h3>';
echo '<ul>';
echo '<li>Clases evaluadas: ' . count($classes) . '</li>';
echo '<li>Estudiantes revisados: ' . $totalchecked . '</li>';
echo '<li>Estudiantes que requieren exención: ' . count($usersaffected) . '</li>';
echo '<li>Marcajes de clase (suma por usuario): ' . array_sum($classesaffected) . '</li>';
echo '<li>Ya exentos (omitidos): ' . $skippedalreadyexempt . '</li>';
echo '</ul>';

if ($dryrun) {
    echo '<p style="color:#0369a1"><strong>Modo dry-run:</strong> no se aplicaron cambios. Ejecute sin <code>dryrun=1</code> para aplicar la exención.</p>';
    if (!empty($usersaffected)) {
        echo '<details><summary>Vista previa de usuarios a eximir (primeros 50)</summary><pre style="max-height:240px;overflow:auto">';
        $i = 0;
        foreach ($usersaffected as $uid => $count) {
            if ($i++ >= 50) break;
            $u = core_user::get_user($uid);
            echo sprintf("userid=%d  %s  clases=%d\n", $uid, $u ? fullname($u) : '(?)', $count);
        }
        echo '</pre></details>';
    }
} else {
    if (empty($usersaffected)) {
        echo '<p style="color:#166534">' . get_string('bulk_exempt_legacy_no_op', 'local_grupomakro_core') . '</p>';
    } else {
        $detail = sprintf(
            "bulk_exempt at %s (threshold=%d, dryrun=%d)",
            userdate($nowts, get_string('strftimedatetime', 'langconfig')),
            $threshold,
            $dryrun ? 1 : 0
        );
        foreach ($usersaffected as $uid => $_ignored) {
            absd_mark_user_globally_exempt((int)$uid, $detail);
        }
        echo '<p style="color:#166534"><strong>&#10003; ' . get_string('bulk_exempt_legacy_complete', 'local_grupomakro_core', (object)[
            'users'   => count($usersaffected),
            'classes' => count($classesaffected),
        ]) . '</strong></p>';
    }
}

echo $OUTPUT->footer();
