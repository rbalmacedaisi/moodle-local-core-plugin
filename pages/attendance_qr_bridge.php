<?php
// QR attendance bridge.
// Marks student attendance and redirects to student Vue UI result screen.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');
require_once($CFG->dirroot . '/mod/attendance/lib.php');

/**
 * Resolve student app base URL.
 *
 * @return string
 */
function gmk_qr_student_app_base_url() {
    $base = trim((string)get_config('local_grupomakro_core', 'student_app_url'));
    if ($base === '') {
        $base = 'https://students.isi.edu.pa';
    }
    return rtrim($base, '/');
}

/**
 * Build class metadata for redirection.
 *
 * @param stdClass $attendance
 * @param stdClass $session
 * @param stdClass $cm
 * @param stdClass $course
 * @return array
 */
function gmk_qr_resolve_target_data($attendance, $session, $cm, $course) {
    global $DB;

    $params = [
        'cmid1' => (int)$cm->id,
        'cmid2' => (int)$cm->id,
        'attid' => (int)$attendance->id,
        'corecourseid' => (int)$course->id,
        'localcourseid' => (int)$attendance->course,
    ];

    $groupsql = '';
    if (!empty($session->groupid)) {
        $groupsql = ' AND (gc.groupid = :groupid OR gc.groupid = 0)';
        $params['groupid'] = (int)$session->groupid;
    }

    $class = $DB->get_record_sql(
        "SELECT gc.id, gc.name, gc.corecourseid, gc.courseid, gc.groupid
           FROM {gmk_class} gc
      LEFT JOIN {gmk_bbb_attendance_relation} rel ON rel.classid = gc.id
          WHERE gc.closed = 0
            AND (gc.corecourseid = :corecourseid OR gc.courseid = :localcourseid)
            AND (
                gc.attendancemoduleid = :cmid1
                OR (rel.attendanceid = :attid AND rel.attendancemoduleid = :cmid2)
            )
            {$groupsql}
       ORDER BY gc.id DESC",
        $params,
        IGNORE_MULTIPLE
    );

    $courseid = 0;
    $classid = 0;
    $classname = trim((string)$course->fullname);
    if ($class) {
        $courseid = (int)$class->corecourseid;
        $classid = (int)$class->id;
        if (trim((string)$class->name) !== '') {
            $classname = trim((string)$class->name);
        }
    }
    if ($courseid <= 0) {
        $courseid = (int)$course->id;
    }

    return [
        'courseid' => $courseid,
        'classid' => $classid,
        'classname' => $classname,
        'coursename' => trim((string)$course->fullname),
    ];
}

/**
 * Translate attendance restriction reason.
 *
 * @param string $reason
 * @return string
 */
function gmk_qr_reason_message($reason) {
    if ($reason === '') {
        return '';
    }
    try {
        return get_string($reason, 'attendance');
    } catch (Throwable $ex) {
        return 'No se puede registrar asistencia en este momento.';
    }
}

/**
 * Redirect to Vue result page.
 *
 * @param string $status
 * @param string $message
 * @param array $target
 * @param int $sessionid
 */
function gmk_qr_redirect_to_student_ui($status, $message, array $target, $sessionid) {
    $base = gmk_qr_student_app_base_url();
    $params = [
        'status' => (string)$status,
        'message' => (string)$message,
        'sessid' => (int)$sessionid,
        'courseid' => (int)($target['courseid'] ?? 0),
        'classid' => (int)($target['classid'] ?? 0),
        'classname' => (string)($target['classname'] ?? ''),
        'coursename' => (string)($target['coursename'] ?? ''),
        'markedat' => time(),
    ];
    $url = new moodle_url($base . '/attendance/scan-result', $params);
    redirect($url);
}

$sessionid = required_param('sessid', PARAM_INT);
$qrpass = optional_param('qrpass', '', PARAM_RAW_TRIMMED);

$session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$attendance = $DB->get_record('attendance', ['id' => $session->attendanceid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('attendance', (int)$attendance->id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => (int)$cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);

$target = gmk_qr_resolve_target_data($attendance, $session, $cm, $course);

if (!empty($session->groupid) && !groups_is_member((int)$session->groupid, $USER->id)) {
    gmk_qr_redirect_to_student_ui('error', 'No perteneces al grupo de esta sesion.', $target, $sessionid);
}

$already = $DB->record_exists('attendance_log', [
    'sessionid' => (int)$sessionid,
    'studentid' => (int)$USER->id,
]);
if ($already && !attendance_check_allow_update((int)$sessionid)) {
    gmk_qr_redirect_to_student_ui('success', 'Asistencia ya registrada previamente para esta sesion.', $target, $sessionid);
}

[$canmark, $reason] = attendance_can_student_mark($session);
if (!$canmark) {
    $msg = gmk_qr_reason_message((string)$reason);
    if ($msg === '') {
        $msg = 'No se puede registrar asistencia en este momento.';
    }
    gmk_qr_redirect_to_student_ui('error', $msg, $target, $sessionid);
}

$attconfig = get_config('attendance');
$qrpassflag = false;

if (attendance_session_open_for_students($session) && (int)$session->rotateqrcode === 1) {
    $sql = 'SELECT * FROM {attendance_rotate_passwords}
             WHERE attendanceid = ? AND expirytime > ?
          ORDER BY expirytime ASC';
    $records = $DB->get_records_sql($sql, [
        (int)$sessionid,
        time() - (int)($attconfig->rotateqrcodeexpirymargin ?? 0),
    ], 0, 2);
    foreach ($records as $record) {
        if ((string)$qrpass !== '' && (string)$record->password === (string)$qrpass) {
            $qrpassflag = true;
            break;
        }
    }
    if (!$qrpassflag) {
        gmk_qr_redirect_to_student_ui('error', 'QR invalido o expirado. Escanea nuevamente.', $target, $sessionid);
    }
}

if ((int)$session->autoassignstatus !== 1 || !attendance_session_open_for_students($session)) {
    gmk_qr_redirect_to_student_ui('error', 'La sesion no permite marcado automatico por QR en este momento.', $target, $sessionid);
}

if (!empty($session->studentpassword) && !$qrpassflag) {
    if ((string)$qrpass === '' || (string)$session->studentpassword !== (string)$qrpass) {
        gmk_qr_redirect_to_student_ui('error', 'QR invalido para esta sesion.', $target, $sessionid);
    }
}

$attstructure = new mod_attendance_structure($attendance, $cm, $course);
$statusid = attendance_session_get_highest_status($attstructure, $session);
if (empty($statusid)) {
    gmk_qr_redirect_to_student_ui('error', 'No hay un estado de asistencia valido para registrar.', $target, $sessionid);
}

$payload = new stdClass();
$payload->status = (int)$statusid;
$payload->sessid = (int)$sessionid;
if ((string)$qrpass !== '') {
    $payload->studentpassword = (string)$qrpass;
}

$success = $attstructure->take_from_student($payload);
if (!$success) {
    if ($DB->record_exists('attendance_log', ['sessionid' => (int)$sessionid, 'studentid' => (int)$USER->id])) {
        gmk_qr_redirect_to_student_ui('success', 'Asistencia registrada para esta sesion.', $target, $sessionid);
    }
    gmk_qr_redirect_to_student_ui('error', 'No fue posible registrar la asistencia. Intenta de nuevo.', $target, $sessionid);
}

gmk_qr_redirect_to_student_ui('success', 'Asistencia registrada correctamente.', $target, $sessionid);
