<?php
/**
 * Manual fix for BBB live attendance.
 * Allows teachers/admins to mark attendance for students who connected to BBB live sessions.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$plugin_name = 'local_grupomakro_core';
$context = context_system::instance();
require_login();
require_capability('local/grupomakro_core:manage_classes', $context);

$PAGE->set_url('/local/grupomakro_core/pages/fix_bbb_live_attendance.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('fix_bbb_live_attendance', $plugin_name));
$PAGE->set_heading(get_string('fix_bbb_live_attendance', $plugin_name));
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(get_string('class_management', $plugin_name), new moodle_url('/local/grupomakro_core/pages/classmanagement.php'));
$PAGE->navbar->add(get_string('fix_bbb_live_attendance', $plugin_name));

echo $OUTPUT->header();
?>
<style>
    .fix-container { max-width: 1100px; margin: 20px auto; }
    .info-box { background: #e7f3ff; padding: 18px; border-left: 4px solid #007bff; margin-bottom: 18px; }
    .warning-box { background: #fff3cd; padding: 18px; border-left: 4px solid #ffc107; margin-bottom: 18px; }
    .success-box { background: #d4edda; padding: 18px; border-left: 4px solid #28a745; margin-bottom: 18px; }
    .error-box { background: #f8d7da; padding: 18px; border-left: 4px solid #dc3545; margin-bottom: 18px; }
    .stats-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    .stats-table th { background: #343a40; color: white; padding: 10px 12px; text-align: left; }
    .stats-table td { padding: 9px 12px; border-bottom: 1px solid #dee2e6; font-size: 13px; }
    .stats-table tr:hover { background: #f8f9fa; }
    .btn { padding: 10px 22px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; }
    .btn-primary { background: #007bff; color: white; }
    .btn-danger { background: #dc3545; color: white; }
    .num { font-weight: bold; font-size: 16px; }
    .red { color: #dc3545; }
    .green { color: #28a745; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-group select, .form-group input { padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; width: 100%; max-width: 400px; }
</style>

<div class="fix-container">
    <h1><?php echo get_string('fix_bbb_live_attendance', $plugin_name); ?></h1>

    <div class="info-box">
        <h3>¿Qué hace esta herramienta?</h3>
        <p>
            Procesa sesiones de asistencia vinculadas a actividades BBB que no fueron iniciadas
            automáticamente cuando el docente se conectó a la clase virtual.
        </p>
        <p>
            Para cada sesión seleccionada:
            <ul>
                <li>Inicia la sesión de asistencia (establece lasttaken) si no está iniciada</li>
                <li>Consulta los logs de conexión de BBB (bigbluebuttonbn_logs)</li>
                <li>Marca como presentes a los estudiantes que se unieron a la sesión</li>
            </ul>
        </p>
    </div>

<?php
$action = optional_param('action', '', PARAM_ALPHA);
$classid = optional_param('classid', 0, PARAM_INT);
$sessionid = optional_param('sessionid', 0, PARAM_INT);

if ($action === 'process' && ($classid > 0 || $sessionid > 0)) {
    echo '<div class="success-box"><h3>Procesando...</h3></div>';

    $results = process_bbb_live_attendance($classid, $sessionid);

    if ($results['success']) {
        echo '<div class="success-box">';
        echo '<h3>✓ Proceso completado</h3>';
        echo '<p>Sessions procesadas: <strong>' . $results['sessions_processed'] . '</strong></p>';
        echo '<p>Estudiantes marcados presentes: <strong>' . $results['students_marked'] . '</strong></p>';
        echo '<p>Estudiantes que ya tenían registro: <strong>' . $results['already_marked'] . '</strong></p>';
        if (!empty($results['errors'])) {
            echo '<p class="red">Errors: ' . implode(', ', $results['errors']) . '</p>';
        }
        echo '</div>';

        if (!empty($results['details'])) {
            echo '<div class="info-box" style="margin-top: 20px; text-align: left;">';
            echo '<h4>Detalle del proceso:</h4>';
            echo '<pre style="font-size: 12px; text-align: left;">';
            foreach ($results['details'] as $detail) {
                echo htmlspecialchars($detail) . "\n";
            }
            echo '</pre>';
            echo '</div>';
        }
    } else {
        echo '<div class="error-box">';
        echo '<h3>✗ Error en el proceso</h3>';
        echo '<p>' . ($results['error'] ?? 'Unknown error') . '</p>';
        echo '</div>';
    }

    echo '<p><a href="" class="btn btn-primary">← Volver</a></p>';

} else {
    $classes = $DB->get_records_sql(
        "SELECT c.id, c.name, c.inittime, c.endtime,
                MAX(r.bbbmoduleid) AS bbbmoduleid,
                MAX(r.attendancesessionid) AS attendancesessionid,
                MAX(r.attendanceid) AS attendanceid
           FROM {gmk_class} c
           JOIN {gmk_bbb_attendance_relation} r ON r.classid = c.id
          WHERE c.closed = 0
            AND r.bbbmoduleid > 0
            AND r.attendancesessionid > 0
            AND c.instructorid = :userid
          GROUP BY c.id, c.name, c.inittime, c.endtime
          ORDER BY c.name",
        ['userid' => $USER->id]
    );

    if (empty($classes)) {
        $classes = $DB->get_records_sql(
            "SELECT c.id, c.name, c.inittime, c.endtime,
                    MAX(r.bbbmoduleid) AS bbbmoduleid,
                    MAX(r.attendancesessionid) AS attendancesessionid,
                    MAX(r.attendanceid) AS attendanceid
               FROM {gmk_class} c
               JOIN {gmk_bbb_attendance_relation} r ON r.classid = c.id
              WHERE c.closed = 0
                AND r.bbbmoduleid > 0
                AND r.attendancesessionid > 0
              GROUP BY c.id, c.name, c.inittime, c.endtime
              ORDER BY c.name"
        );
    }
?>

    <div class="form-group">
        <h3>Seleccionar Clase</h3>
        <form method="post" action="">
            <input type="hidden" name="action" value="process" />

            <div class="form-group">
                <label for="classid">Clase:</label>
                <select name="classid" id="classid">
                    <option value="0">-- Todas las clases --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c->id; ?>">
                            <?php echo s($c->name) . ' (' . s($c->inittime) . ' - ' . s($c->endtime) . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Procesar Asistencia</button>
        </form>
    </div>

    <div class="warning-box">
        <h3>Nota importante</h3>
        <p>
            Este proceso es <strong>aditivo</strong>: solo inserta registros de asistencia para estudiantes
            que no tienen uno existente. <strong>No modifica registros existentes</strong>.
        </p>
    </div>

<?php
}
echo '</div>';
echo $OUTPUT->footer();

/**
 * Process BBB live attendance for a class or session.
 *
 * @param int $classId
 * @param int $sessionId
 * @return array
 */
function process_bbb_live_attendance($classId, $sessionId) {
    global $DB;

    $results = [
        'success' => true,
        'sessions_processed' => 0,
        'students_marked' => 0,
        'already_marked' => 0,
        'errors' => [],
        'details' => []
    ];

    try {
        $sql = "SELECT r.*, c.corecourseid, c.groupid, c.instructorid
                  FROM {gmk_bbb_attendance_relation} r
                  JOIN {gmk_class} c ON c.id = r.classid
                 WHERE r.bbbmoduleid > 0
                   AND r.attendancesessionid > 0";

        $params = [];
        if ($classId > 0) {
            $sql .= " AND r.classid = :classid";
            $params['classid'] = $classId;
        }
        if ($sessionId > 0) {
            $sql .= " AND r.attendancesessionid = :sessionid";
            $params['sessionid'] = $sessionId;
        }

        $relations = $DB->get_records_sql($sql, $params);

        if (empty($relations)) {
            $results['success'] = false;
            $results['error'] = 'No se encontraron relaciones BBB-Asistencia para los criterios especificados.';
            return $results;
        }

        foreach ($relations as $rel) {
            $processResult = process_single_session($rel);
            $results['sessions_processed']++;
            $results['students_marked'] += $processResult['marked'];
            $results['already_marked'] += $processResult['already'];
            if (!empty($processResult['error'])) {
                $results['errors'][] = $processResult['error'];
            }
            if (!empty($processResult['details'])) {
                $results['details'] = array_merge($results['details'], $processResult['details']);
            }
        }

    } catch (Exception $e) {
        $results['success'] = false;
        $results['error'] = $e->getMessage();
    }

    return $results;
}

/**
 * Process a single attendance session.
 *
 * @param stdClass $relation
 * @return array
 */
function process_single_session($rel) {
    global $DB;

    $result = ['marked' => 0, 'already' => 0, 'error' => null, 'details' => []];

    $session = $DB->get_record('attendance_sessions', ['id' => $rel->attendancesessionid], 'id, sessdate, duration, lasttaken, attendanceid');
    if (!$session) {
        $result['error'] = "Session {$rel->attendancesessionid} not found";
        return $result;
    }

    $result['details'][] = "Session {$session->id}: sessdate=" . date('Y-m-d H:i', $session->sessdate) . ", duration={$session->duration}, lasttaken={$session->lasttaken}, attendanceid={$session->attendanceid}";

    if ((int)$session->lasttaken === 0) {
        $DB->set_field('attendance_sessions', 'lasttaken', time(), ['id' => $session->id]);
        $DB->set_field('attendance_sessions', 'lasttakenby', $rel->instructorid, ['id' => $session->id]);
        $result['details'][] = "Started attendance session (lasttaken set)";
    }

    $cm = get_coursemodule_from_id('bigbluebuttonbn', $rel->bbbmoduleid);
    if (!$cm) {
        $result['error'] = "BBB module {$rel->bbbmoduleid} not found";
        return $result;
    }

    $result['details'][] = "BBB cmid={$cm->id}, instance={$cm->instance}";

    $bbbInstance = $DB->get_record('bigbluebuttonbn', ['id' => $cm->instance], 'id');
    if (!$bbbInstance) {
        $result['error'] = "BBB instance not found";
        return $result;
    }

    $sessStart = $session->sessdate;
    $sessEnd = $sessStart + (int)$session->duration;
    $result['details'][] = "Querying BBB logs for bbbid={$bbbInstance->id}, time > " . date('Y-m-d H:i', $sessStart - 300) . " (sessdate=" . date('Y-m-d H:i', $sessStart) . ")";

    $sql = "SELECT DISTINCT bl.userid, bl.log, bl.timecreated
              FROM {bigbluebuttonbn_logs} bl
             WHERE bl.bigbluebuttonbnid = :bbbid
               AND bl.log IN ('join', 'meeting_start')
               AND bl.timecreated > :sessstart
               AND bl.timecreated < :sessend
             ORDER BY bl.timecreated";

    $joinedUsers = $DB->get_records_sql($sql, [
        'bbbid' => $bbbInstance->id,
        'sessstart' => $sessStart - 300,
        'sessend' => $sessEnd + 3600
    ]);

    $result['details'][] = "Found " . count($joinedUsers) . " BBB connections";

    if (empty($joinedUsers)) {
        return $result;
    }

    $allStatuses = $DB->get_records('attendance_statuses', ['attendanceid' => $session->attendanceid], 'id ASC');
    $result['details'][] = "Attendance statuses for {$session->attendanceid}: " . count($allStatuses) . " found";
    foreach ($allStatuses as $st) {
        $result['details'][] = "  Status id={$st->id}, setnumber={$st->setnumber}, setunmarked={$st->setunmarked}, deleted={$st->deleted}";
    }

    $presentStatusId = (int)$DB->get_field_sql(
        "SELECT id FROM {attendance_statuses}
          WHERE attendanceid = :aid AND setnumber = 0 AND deleted = 0 AND (setunmarked = 0 OR setunmarked = '' OR setunmarked IS NULL)
          ORDER BY id ASC LIMIT 1",
        ['aid' => $session->attendanceid]
    );

    if ($presentStatusId <= 0) {
        $result['error'] = "No present status found for attendance {$session->attendanceid}";
        return $result;
    }

    $result['details'][] = "Using present statusid=$presentStatusId";

    $now = time();

    foreach ($joinedUsers as $joinedUser) {
        $studentId = (int)$joinedUser->userid;

        $existingLog = $DB->get_record('attendance_log', [
            'sessionid' => $session->id,
            'studentid' => $studentId
        ], 'id');

        if ($existingLog) {
            $result['already']++;
            $result['details'][] = "Student $studentId already has attendance log";
            continue;
        }

        $log = new \stdClass();
        $log->sessionid = $session->id;
        $log->studentid = $studentId;
        $log->statusid = $presentStatusId;
        $log->timetaken = $now;
        $log->remarks = 'auto-marked: BBB live attendance fix';
        $log->statusset = '0';

        $DB->insert_record('attendance_log', $log);
        $result['marked']++;
        $result['details'][] = "Marked student $studentId as present";
    }

    return $result;
}
