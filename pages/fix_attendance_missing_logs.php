<?php
/**
 * Retroactive fix: insert absent attendance_log entries for students who have no
 * record in a past session.
 *
 * Logic: if a student has no attendance_log row for a closed session they were
 * supposed to attend → they are absent.  We insert the setunmarked status and
 * recalculate grades.
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/fix_attendance_missing_logs.php');
$PAGE->set_context($context);
$PAGE->set_title('Corregir Logs de Asistencia Faltantes');
$PAGE->set_heading('Corrección Retroactiva de Ausencias');
$PAGE->set_pagelayout('admin');

$action = optional_param('action', '', PARAM_ALPHA);

echo $OUTPUT->header();
?>
<style>
    .fix-container { max-width: 1100px; margin: 20px auto; }
    .info-box    { background: #e7f3ff; padding: 18px; border-left: 4px solid #007bff; margin-bottom: 18px; }
    .warning-box { background: #fff3cd; padding: 18px; border-left: 4px solid #ffc107; margin-bottom: 18px; }
    .success-box { background: #d4edda; padding: 18px; border-left: 4px solid #28a745; margin-bottom: 18px; }
    .error-box   { background: #f8d7da; padding: 18px; border-left: 4px solid #dc3545; margin-bottom: 18px; }
    .stats-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    .stats-table th { background: #343a40; color: white; padding: 10px 12px; text-align: left; }
    .stats-table td { padding: 9px 12px; border-bottom: 1px solid #dee2e6; font-size: 13px; }
    .stats-table tr:hover { background: #f8f9fa; }
    .btn { padding: 10px 22px; border: none; border-radius: 4px; cursor: pointer;
           font-weight: bold; text-decoration: none; display: inline-block; }
    .btn-primary { background: #007bff; color: white; }
    .btn-danger  { background: #dc3545; color: white; }
    .num { font-weight: bold; font-size: 16px; }
    .red { color: #dc3545; } .green { color: #28a745; }
</style>

<div class="fix-container">
<h1>Corrección Retroactiva de Ausencias</h1>

<div class="info-box">
    <h3>Qué hace esta herramienta</h3>
    <p>
        Para cada sesión de asistencia ya cerrada (<code>sessdate + duration &lt; ahora</code>),
        identifica a los estudiantes matriculados que <strong>no tienen ningún registro</strong>
        en <code>attendance_log</code> y les inserta una fila con el estado "ausente"
        (<code>setunmarked</code>). Luego recalcula la nota de asistencia de los afectados.
    </p>
    <p>
        Solo se procesan sesiones con <code>automark = 2</code> (cierre automático), que son
        las que crea nuestro sistema.  Los registros ya existentes no se modifican.
    </p>
</div>

<?php

$now = time();

// ── helpers ──────────────────────────────────────────────────────────────────

/**
 * Returns the module numeric id for 'attendance'.
 */
function gmk_get_attendance_module_id(): int {
    global $DB;
    static $mid = null;
    if ($mid === null) {
        $mid = (int)$DB->get_field('modules', 'id', ['name' => 'attendance']);
    }
    return $mid;
}

/**
 * For one attendance, insert absent logs for students with no record in past sessions,
 * then recalculate grades.  Returns stats array.
 */
function gmk_fix_missing_attendance_logs(stdClass $att, int $now): array {
    global $DB;

    // Ensure the absent status is marked as setunmarked.
    gmk_attendance_ensure_setunmarked((int)$att->id);

    // Get setunmarked status ID (setnumber=0, which all our sessions use).
    $setunmarkedId = (int)$DB->get_field_select(
        'attendance_statuses', 'id',
        'attendanceid = :aid AND setunmarked = 1 AND deleted = 0 AND setnumber = 0',
        ['aid' => $att->id]
    );
    if (!$setunmarkedId) {
        return ['sessions' => 0, 'inserted' => 0, 'users' => 0, 'error' => 'Sin estado setunmarked'];
    }

    // Status IDs for setnumber=0 (the statusset string stored in attendance_log).
    $statusIds = $DB->get_fieldset_select(
        'attendance_statuses', 'id',
        'attendanceid = :aid AND deleted = 0 AND setnumber = 0',
        ['aid' => $att->id],
        'id ASC'
    );
    $statussetStr = implode(',', $statusIds);

    // Course module and context.
    $cm = get_coursemodule_from_instance('attendance', $att->id, $att->course);
    if (!$cm) {
        return ['sessions' => 0, 'inserted' => 0, 'users' => 0, 'error' => 'CM no encontrado'];
    }
    $ctx = context_module::instance($cm->id);

    // Enrolled students that can be listed in attendance.
    $enrolled = get_enrolled_users($ctx, 'mod/attendance:canbelisted', 0, 'u.id');
    $enrolledIds = array_keys($enrolled);
    if (empty($enrolledIds)) {
        return ['sessions' => 0, 'inserted' => 0, 'users' => 0, 'error' => null];
    }

    // Past sessions with automark > 0.
    $sessions = $DB->get_records_select(
        'attendance_sessions',
        'attendanceid = :aid AND sessdate + duration < :now AND automark > 0',
        ['aid' => $att->id, 'now' => $now],
        'sessdate ASC'
    );
    if (empty($sessions)) {
        return ['sessions' => 0, 'inserted' => 0, 'users' => 0, 'error' => null];
    }
    $sessionIds = array_keys($sessions);

    // All existing logs for those sessions in one query.
    [$inSql, $inParams] = $DB->get_in_or_equal($sessionIds, SQL_PARAMS_NAMED, 'sid');
    $existingLogs = $DB->get_recordset_sql(
        "SELECT sessionid, studentid FROM {attendance_log} WHERE sessionid $inSql", $inParams
    );
    $logLookup = []; // sessionid → [studentid => true]
    foreach ($existingLogs as $row) {
        $logLookup[$row->sessionid][$row->studentid] = true;
    }
    $existingLogs->close();

    // Group members (fetched once per group used in sessions).
    $groupMemberCache = []; // groupid → [userid => true]
    foreach ($sessions as $sess) {
        $gid = (int)$sess->groupid;
        if ($gid > 0 && !isset($groupMemberCache[$gid])) {
            $members = $DB->get_fieldset_select('groups_members', 'userid',
                'groupid = :gid', ['gid' => $gid]);
            $groupMemberCache[$gid] = array_flip($members);
        }
    }

    $newlogs       = [];
    $affectedUsers = [];

    foreach ($sessions as $sess) {
        $gid = (int)$sess->groupid;

        // Users who should attend this session.
        if ($gid > 0 && isset($groupMemberCache[$gid])) {
            $targetIds = array_filter($enrolledIds,
                fn($uid) => isset($groupMemberCache[$gid][$uid]));
        } else {
            $targetIds = $enrolledIds;
        }

        $loggedInSession = $logLookup[$sess->id] ?? [];

        // Handle sessions that may use a different statusset than 0.
        $sessStatusset = (int)($sess->statusset ?? 0);
        if ($sessStatusset !== 0) {
            $altIds = $DB->get_fieldset_select('attendance_statuses', 'id',
                'attendanceid = :aid AND deleted = 0 AND setnumber = :sn',
                ['aid' => $att->id, 'sn' => $sessStatusset], 'id ASC');
            $sessStatussetStr = implode(',', $altIds);
            $sessSetunmarked = (int)$DB->get_field_select('attendance_statuses', 'id',
                'attendanceid = :aid AND setunmarked = 1 AND deleted = 0 AND setnumber = :sn',
                ['aid' => $att->id, 'sn' => $sessStatusset]);
        } else {
            $sessStatussetStr = $statussetStr;
            $sessSetunmarked  = $setunmarkedId;
        }
        if (!$sessSetunmarked) {
            continue; // No absent status for this statusset — skip session.
        }

        foreach ($targetIds as $uid) {
            if (!isset($loggedInSession[$uid])) {
                $log = new stdClass();
                $log->sessionid  = $sess->id;
                $log->studentid  = $uid;
                $log->statusid   = $sessSetunmarked;
                $log->statusset  = $sessStatussetStr;
                $log->timetaken  = $now;
                $log->takenby    = 0;
                $log->remarks    = 'Ausente (corrección retroactiva)';
                $newlogs[]       = $log;
                $affectedUsers[$uid] = true;
            }
        }
    }

    if (!empty($newlogs)) {
        $DB->insert_records('attendance_log', $newlogs);

        // Mark sessions as automark-completed so the cron doesn't re-process them.
        $DB->set_field_select('attendance_sessions', 'automarkcompleted', 2,
            "attendanceid = :aid AND sessdate + duration < :now AND automark > 0",
            ['aid' => $att->id, 'now' => $now]);
    }

    // Recalculate grades for all affected students.
    if (!empty($affectedUsers) && !empty($att->grade)) {
        attendance_update_users_grades_by_id($att->id, $att->grade, array_keys($affectedUsers));
    }

    return [
        'sessions' => count($sessions),
        'inserted' => count($newlogs),
        'users'    => count($affectedUsers),
        'error'    => null,
    ];
}

// ── analysis: GET ─────────────────────────────────────────────────────────────
if ($action === '') {

    // Attendances in our system (linked via gmk_class.attendancemoduleid).
    $midAtt = gmk_get_attendance_module_id();
    $sql_att = "
        SELECT DISTINCT a.id, a.name, a.course, a.grade, c.fullname AS coursename,
               (SELECT COUNT(*)
                  FROM {attendance_sessions} s2
                 WHERE s2.attendanceid = a.id
                   AND s2.sessdate + s2.duration < :now2
                   AND s2.automark > 0) AS past_sessions,
               (SELECT COUNT(*)
                  FROM {attendance_sessions} s3
                  JOIN {attendance_log} al ON al.sessionid = s3.id
                 WHERE s3.attendanceid = a.id
                   AND s3.sessdate + s3.duration < :now3
                   AND s3.automark > 0) AS existing_logs
          FROM {attendance} a
          JOIN {course} c ON c.id = a.course
          JOIN {course_modules} cm ON cm.instance = a.id
               AND cm.module = :mid AND cm.deletioninprogress = 0
         WHERE a.id IN (
               SELECT DISTINCT cm2.instance
                 FROM {gmk_class} gc
                 JOIN {course_modules} cm2 ON cm2.id = gc.attendancemoduleid
                WHERE gc.attendancemoduleid > 0
         )
         ORDER BY c.fullname, a.name
    ";
    $attendances = $DB->get_records_sql($sql_att,
        ['mid' => $midAtt, 'now2' => $now, 'now3' => $now]);

    $totalAtt      = count($attendances);
    $totalSessions = 0;
    $totalLogs     = 0;
    foreach ($attendances as $row) {
        $totalSessions += (int)$row->past_sessions;
        $totalLogs     += (int)$row->existing_logs;
    }

    echo "<div class='warning-box'>";
    echo "<h3>Diagnóstico</h3>";
    echo "<table class='stats-table'><tbody>";
    echo "<tr><td>Actividades de asistencia en el sistema</td><td class='num'>{$totalAtt}</td></tr>";
    echo "<tr><td>Sesiones pasadas con automark activo</td><td class='num'>{$totalSessions}</td></tr>";
    echo "<tr><td>Registros de asistencia ya existentes en esas sesiones</td><td class='num green'>{$totalLogs}</td></tr>";
    echo "</tbody></table>";
    echo "<p><em>El número exacto de logs faltantes se calcula al ejecutar la corrección (requiere consultar matrículas por grupo).</em></p>";
    echo "</div>";

    if ($totalAtt > 0 && $totalSessions > 0) {
        echo "<h3>Actividades a procesar</h3>";
        echo "<table class='stats-table'>";
        echo "<thead><tr><th>Actividad</th><th>Curso</th><th>Sesiones pasadas</th><th>Logs existentes</th></tr></thead><tbody>";
        foreach ($attendances as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row->name) . "</td>";
            echo "<td>" . htmlspecialchars($row->coursename) . "</td>";
            echo "<td class='num'>{$row->past_sessions}</td>";
            echo "<td class='num green'>{$row->existing_logs}</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";

        $confirmMsg = "¿Insertar registros de ausencia para todos los estudiantes sin log en sesiones pasadas ({$totalSessions} sesiones en {$totalAtt} actividades)?";
        echo "<div style='text-align:center;margin:30px 0;'>";
        echo "<a href='?action=fix' class='btn btn-danger'"
             . " onclick='return confirm(" . json_encode($confirmMsg) . ")'>Ejecutar corrección retroactiva</a>";
        echo "</div>";
    } else {
        echo "<div class='success-box'><h3>Nada que procesar</h3><p>No se encontraron sesiones pasadas vinculadas al sistema.</p></div>";
    }

// ── fix: action=fix ───────────────────────────────────────────────────────────
} elseif ($action === 'fix') {

    $midAtt = gmk_get_attendance_module_id();
    $sql_att = "
        SELECT DISTINCT a.id, a.name, a.course, a.grade, c.fullname AS coursename
          FROM {attendance} a
          JOIN {course} c ON c.id = a.course
          JOIN {course_modules} cm ON cm.instance = a.id
               AND cm.module = :mid AND cm.deletioninprogress = 0
         WHERE a.id IN (
               SELECT DISTINCT cm2.instance
                 FROM {gmk_class} gc
                 JOIN {course_modules} cm2 ON cm2.id = gc.attendancemoduleid
                WHERE gc.attendancemoduleid > 0
         )
         ORDER BY a.id
    ";
    $attendances = $DB->get_records_sql($sql_att, ['mid' => $midAtt]);

    echo "<div class='info-box'><h3>Procesando " . count($attendances) . " actividades de asistencia...</h3></div>";

    $totalInserted   = 0;
    $totalUsers      = 0;
    $totalSessions   = 0;
    $rows            = [];
    $errors          = [];

    foreach ($attendances as $att) {
        try {
            $r = gmk_fix_missing_attendance_logs($att, $now);
            $totalSessions += $r['sessions'];
            $totalInserted += $r['inserted'];
            $totalUsers    += $r['users'];
            if ($r['inserted'] > 0 || $r['error']) {
                $rows[] = [
                    'name'    => $att->name,
                    'course'  => $att->coursename,
                    'sessions'=> $r['sessions'],
                    'inserted'=> $r['inserted'],
                    'users'   => $r['users'],
                    'error'   => $r['error'],
                ];
            }
        } catch (Throwable $e) {
            $errors[] = htmlspecialchars("ID {$att->id} ({$att->name}): " . $e->getMessage());
        }
    }

    if (empty($errors)) {
        echo "<div class='success-box'>";
    } else {
        echo "<div class='warning-box'>";
    }
    echo "<h3>Corrección completada</h3>";
    echo "<table class='stats-table'><tbody>";
    echo "<tr><td>Actividades procesadas</td><td class='num'>" . count($attendances) . "</td></tr>";
    echo "<tr><td>Sesiones evaluadas</td><td class='num'>{$totalSessions}</td></tr>";
    echo "<tr><td>Registros de ausencia insertados</td><td class='num red'>{$totalInserted}</td></tr>";
    echo "<tr><td>Estudiantes con nota recalculada</td><td class='num'>{$totalUsers}</td></tr>";
    echo "</tbody></table>";
    echo "</div>";

    if (!empty($rows)) {
        echo "<h3>Detalle (solo actividades con cambios)</h3>";
        echo "<table class='stats-table'>";
        echo "<thead><tr><th>Actividad</th><th>Curso</th><th>Sesiones</th><th>Logs insertados</th><th>Estudiantes</th><th>Error</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($r['name']) . "</td>";
            echo "<td>" . htmlspecialchars($r['course']) . "</td>";
            echo "<td>{$r['sessions']}</td>";
            echo "<td class='num " . ($r['inserted'] > 0 ? 'red' : '') . "'>{$r['inserted']}</td>";
            echo "<td>{$r['users']}</td>";
            echo "<td>" . ($r['error'] ? "<span style='color:red'>" . htmlspecialchars($r['error']) . "</span>" : '—') . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

    if (!empty($errors)) {
        echo "<div class='error-box'><h3>Errores</h3><ul>";
        foreach ($errors as $err) {
            echo "<li>{$err}</li>";
        }
        echo "</ul></div>";
    }

    echo "<div class='info-box'>";
    echo "<h3>Próximos pasos</h3>";
    echo "<ol>";
    echo "<li>Las notas de asistencia de los estudiantes afectados ya fueron recalculadas.</li>";
    echo "<li>Para las sesiones futuras, el cron <em>auto_mark</em> ya tiene el estado <code>setunmarked</code> configurado y marcará ausentes automáticamente.</li>";
    echo "<li>Verifique la nota de asistencia de un estudiante de muestra en el <a href='debug_attendance_api.php'>Debug de Asistencia</a>.</li>";
    echo "</ol>";
    echo "</div>";

    echo "<div style='text-align:center;margin:30px 0;'>";
    echo "<a href='?' class='btn btn-primary'>Volver al diagnóstico</a>";
    echo "</div>";
}
?>
</div>
<?php echo $OUTPUT->footer(); ?>
