<?php
/**
 * Informe de Calificaciones.
 *
 * Lists the official grade of every student in every subject of their plan:
 * the LAST grade placed (latest class, module, revalidation, Nota Final
 * Integrada; historical sources only as fallback), as stored in
 * gmk_course_progre by course_grade_resolver::sync_progress(), together with
 * where it came from and when it was placed. The same value is what the grade
 * modal, studenttable, the reports and the LXP show.
 *
 * Optional check ("verificar"): recomputes each row live with the same
 * resolver and flags rows whose stored grade or status no longer matches,
 * i.e. a grade that changed without the sync running. It is expensive, so it
 * requires a plan or subject filter.
 *
 * This page used to compare gmk_course_progre.grade with the Moodle course
 * total. Under the latest-grade model those two differ by design (the course
 * total mixes every class of the course), so that comparison was dropped.
 *
 * @package    local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use local_grupomakro_core\course_grade_resolver;

admin_externalpage_setup('grupomakro_core_grade_report');
require_capability('local/grupomakro_core:view_grade_report', context_system::instance());

$action   = optional_param('action', '', PARAM_ALPHAEXT);
$planid   = optional_param('planid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$status   = optional_param('status', -1, PARAM_INT);
$verify   = optional_param('verify', 0, PARAM_BOOL);
$onlydiff = optional_param('onlydiff', 0, PARAM_BOOL);

$sourcelabels = [
    course_grade_resolver::SOURCE_LATEST_CLASS      => 'Clase',
    course_grade_resolver::SOURCE_LATEST_MODULE     => 'Módulo',
    course_grade_resolver::SOURCE_REVALIDATION      => 'Reválida',
    course_grade_resolver::SOURCE_LATEST_NFI        => 'Nota Final Integrada / homologación',
    course_grade_resolver::SOURCE_HISTORICAL_PROGRE => 'Histórico (registro migrado)',
];
$sourcelabel = function (?string $src) use ($sourcelabels): string {
    if ($src === null || $src === '') {
        return 'Sin calcular';
    }
    if (isset($sourcelabels[$src])) {
        return $sourcelabels[$src];
    }
    return strpos($src, 'historical_') === 0 ? 'Histórico (' . substr($src, 11) . ')' : $src;
};

if ($action === 'download_csv') {
    require_sesskey();
    if ($verify && !$planid && !$courseid) {
        throw new moodle_exception('generalexceptionmessage', 'error', '',
            'La verificación recalcula cada nota: elija un plan o una asignatura.');
    }
    core_php_time_limit::raise(0);
    raise_memory_limit(MEMORY_EXTRA);

    $where = ['u.deleted = 0', 'p.courseid > 1'];
    $params = [];
    if ($planid) {
        $where[] = 'p.learningplanid = :planid';
        $params['planid'] = $planid;
    }
    if ($courseid) {
        $where[] = 'p.courseid = :courseid';
        $params['courseid'] = $courseid;
    }
    if ($status >= 0) {
        // 3 and 4 are both "Aprobada": the filter offers 3 and covers both.
        $where[] = $status === 3 ? 'p.status IN (3, 4)' : 'p.status = :status';
        if ($status !== 3) {
            $params['status'] = $status;
        }
    }
    $rs = $DB->get_recordset_sql(
        "SELECT p.id, p.userid, p.courseid, p.learningplanid, p.classid, p.groupid, p.coursename,
                p.grade, p.status, p.grade_source, p.grade_at, p.grade_classid,
                u.username, u.firstname, u.lastname, lp.name AS planname, c.fullname AS coursefull,
                gc.name AS gradeclassname
           FROM {gmk_course_progre} p
           JOIN {user} u ON u.id = p.userid
      LEFT JOIN {local_learning_plans} lp ON lp.id = p.learningplanid
      LEFT JOIN {course} c ON c.id = p.courseid
      LEFT JOIN {gmk_class} gc ON gc.id = p.grade_classid
          WHERE " . implode(' AND ', $where) . "
       ORDER BY u.lastname, u.firstname, lp.name, c.fullname", $params);

    $filename = 'informe_calificaciones_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $header = ['Usuario', 'Estudiante', 'Plan', 'Asignatura', 'Nota', 'Estado', 'Origen de la nota',
        'Fecha de la nota', 'Clase de origen'];
    if ($verify) {
        $header = array_merge($header, ['Nota recalculada', 'Estado recalculado', 'Origen recalculado', '¿Coincide?']);
    }
    fputcsv($fp, $header);

    foreach ($rs as $p) {
        $row = [
            $p->username,
            trim($p->firstname . ' ' . $p->lastname),
            (string)$p->planname,
            (string)($p->coursefull ?: $p->coursename),
            $p->grade === null ? '' : round((float)$p->grade, 2),
            course_grade_resolver::STATUS_LABEL[(int)$p->status] ?? (string)$p->status,
            $sourcelabel($p->grade_source),
            !empty($p->grade_at) ? userdate((int)$p->grade_at, '%d/%m/%Y %H:%M') : '',
            (string)$p->gradeclassname,
        ];
        if ($verify) {
            $live = course_grade_resolver::resolve_latest_grade((int)$p->userid, (int)$p->courseid,
                (int)$p->learningplanid, (int)$p->classid, (int)$p->groupid, (string)$p->coursename, (int)$p->status);
            $livestatus = $live['source'] === course_grade_resolver::SOURCE_NONE
                ? (int)$p->status
                : course_grade_resolver::status_for_latest((int)$p->status, $live);
            $livegrade = $live['grade'];
            if ($live['source'] === course_grade_resolver::SOURCE_NONE) {
                // Nothing to recompute from: the stored (migrated) value stands.
                $match = true;
            } else {
                $match = $p->grade !== null && $livegrade !== null
                    && abs((float)$p->grade - (float)$livegrade) < 0.01
                    && $livestatus === (int)$p->status;
            }
            if ($onlydiff && $match) {
                continue;
            }
            $row = array_merge($row, [
                $livegrade === null ? '' : $livegrade,
                course_grade_resolver::STATUS_LABEL[$livestatus] ?? (string)$livestatus,
                $sourcelabel($live['source']),
                $match ? 'SI' : 'NO',
            ]);
        }
        fputcsv($fp, $row);
    }
    $rs->close();
    fclose($fp);
    die;
}

$plans = $DB->get_records_menu('local_learning_plans', null, 'name', 'id, name');
$courses = $DB->get_records_sql_menu(
    "SELECT DISTINCT c.id, c.fullname
       FROM {course} c
       JOIN {local_learning_courses} lc ON lc.courseid = c.id
   ORDER BY c.fullname");
$statuses = course_grade_resolver::STATUS_LABEL;
unset($statuses[4]); // 3 and 4 share the label "Aprobada"; 3 stays as the filter option.

echo $OUTPUT->header();
echo $OUTPUT->heading('Informe de Calificaciones');
?>
<p>Lista la <b>nota oficial</b> de cada estudiante en cada asignatura de su plan: la <b>última nota colocada</b>
(última clase, módulo, reválida o Nota Final Integrada / homologación), con su estado, de dónde sale y cuándo se colocó.
Es la misma nota que muestran el modal de notas, la tabla de estudiantes, los reportes y el portal del estudiante.</p>
<p><b>Verificar contra el cálculo:</b> recalcula cada nota y marca las que no coinciden con la guardada
(por ejemplo, una nota cambiada sin que se actualizara el registro). Es más lento: requiere elegir un plan o una asignatura.</p>

<form method="get" action="" class="card card-body mb-3">
    <input type="hidden" name="action" value="download_csv">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <div class="form-row">
        <div class="form-group col-md-4">
            <label for="planid">Plan</label>
            <select class="form-control" name="planid" id="planid">
                <option value="0">Todos</option>
                <?php foreach ($plans as $id => $name): ?>
                    <option value="<?php echo (int)$id; ?>"><?php echo s($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-md-4">
            <label for="courseid">Asignatura</label>
            <select class="form-control" name="courseid" id="courseid">
                <option value="0">Todas</option>
                <?php foreach ($courses as $id => $name): ?>
                    <option value="<?php echo (int)$id; ?>"><?php echo s($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-md-4">
            <label for="status">Estado</label>
            <select class="form-control" name="status" id="status">
                <option value="-1">Todos</option>
                <?php foreach ($statuses as $code => $label): ?>
                    <option value="<?php echo (int)$code; ?>"><?php echo s($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="verify" value="1" id="verify">
        <label class="form-check-label" for="verify">Verificar contra el cálculo (requiere plan o asignatura)</label>
    </div>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="onlydiff" value="1" id="onlydiff">
        <label class="form-check-label" for="onlydiff">Solo filas que no coinciden (con verificación)</label>
    </div>
    <div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> Descargar informe (CSV)</button>
    </div>
</form>
<?php
echo $OUTPUT->footer();
