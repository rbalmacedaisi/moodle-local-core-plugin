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
 * Check whether a session is open for students.
 * Uses mod_attendance helper when available, otherwise local fallback.
 *
 * @param stdClass $session
 * @return bool
 */
function gmk_qr_is_session_open_for_students($session) {
    if (function_exists('attendance_session_open_for_students')) {
        return (bool)attendance_session_open_for_students($session);
    }

    $sessdate = isset($session->sessdate) ? (int)$session->sessdate : 0;
    $earlyopen = isset($session->studentsearlyopentime) ? (int)$session->studentsearlyopentime : 0;
    $sessionopens = $sessdate - max(0, $earlyopen);

    return (time() > $sessionopens);
}

/**
 * Resolve internal rejection/trace reason code.
 *
 * @param string $reason
 * @param stdClass $session
 * @return string
 */
function gmk_qr_reason_to_code($reason, $session) {
    $reason = trim((string)$reason);
    if ($reason === 'preventsharederror') {
        return 'shared_ip_restricted';
    }
    if ($reason === 'closed') {
        if (!gmk_qr_is_session_open_for_students($session)) {
            return 'outside_window';
        }
        return 'session_closed';
    }
    if ($reason === '') {
        return '';
    }
    return 'attendance_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($reason));
}

/**
 * Build trace identifier for each QR decision.
 *
 * @return string
 */
function gmk_qr_trace_id() {
    try {
        return 'qr_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
    } catch (Throwable $ex) {
        return 'qr_' . gmdate('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);
    }
}

/**
 * Persist QR decision trace.
 *
 * @param string $traceid
 * @param string $status
 * @param string $reasoncode
 * @param string $message
 * @param int $sessionid
 * @param int $userid
 * @param array $target
 * @param array $extra
 * @return void
 */
function gmk_qr_log_decision($traceid, $status, $reasoncode, $message, $sessionid, $userid, array $target, array $extra = []) {
    $payload = [
        'time' => date('Y-m-d H:i:s'),
        'traceid' => (string)$traceid,
        'status' => (string)$status,
        'reasoncode' => (string)$reasoncode,
        'message' => (string)$message,
        'sessionid' => (int)$sessionid,
        'userid' => (int)$userid,
        'classid' => (int)($target['classid'] ?? 0),
        'courseid' => (int)($target['courseid'] ?? 0),
        'classname' => (string)($target['classname'] ?? ''),
        'coursename' => (string)($target['coursename'] ?? ''),
        'extra' => $extra,
    ];
    $logline = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($logline) || $logline === '') {
        $logline = '{"traceid":"' . (string)$traceid . '","status":"log_encode_error"}';
    }
    @file_put_contents(__DIR__ . '/../gmk_qr_attendance.log', $logline . PHP_EOL, FILE_APPEND);
}

/**
 * Redirect to Vue result page.
 *
 * @param string $status
 * @param string $reasoncode
 * @param string $message
 * @param array $target
 * @param int $sessionid
 * @param string $traceid
 */
function gmk_qr_redirect_to_student_ui($status, $reasoncode, $message, array $target, $sessionid, $traceid = '') {
    $base = gmk_qr_student_app_base_url();
    $params = [
        'status' => (string)$status,
        'reasoncode' => (string)$reasoncode,
        'message' => (string)$message,
        'traceid' => (string)$traceid,
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

/**
 * Finalize request with trace + redirect.
 *
 * @param string $status
 * @param string $reasoncode
 * @param string $message
 * @param array $target
 * @param int $sessionid
 * @param int $userid
 * @param array $extra
 * @return void
 */
function gmk_qr_finish($status, $reasoncode, $message, array $target, $sessionid, $userid, array $extra = []) {
    $traceid = gmk_qr_trace_id();
    gmk_qr_log_decision($traceid, $status, $reasoncode, $message, (int)$sessionid, (int)$userid, $target, $extra);
    gmk_qr_redirect_to_student_ui($status, $reasoncode, $message, $target, $sessionid, $traceid);
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
    gmk_qr_finish(
        'error',
        'group_mismatch',
        'No perteneces al grupo de esta sesion.',
        $target,
        $sessionid,
        (int)$USER->id,
        ['groupid' => (int)$session->groupid]
    );
}

$existinglog = $DB->get_record('attendance_log', [
    'sessionid' => (int)$sessionid,
    'studentid' => (int)$USER->id,
], 'id,statusid,timetaken', IGNORE_MULTIPLE);
if ($existinglog) {
    gmk_qr_finish(
        'success',
        'already_marked',
        'Asistencia ya registrada previamente para esta sesion.',
        $target,
        $sessionid,
        (int)$USER->id,
        ['attendance_log_id' => (int)$existinglog->id]
    );
}

[$canmark, $reason] = attendance_can_student_mark($session);
if (!$canmark) {
    $msg = gmk_qr_reason_message((string)$reason);
    if ($msg === '') {
        $msg = 'No se puede registrar asistencia en este momento.';
    }
    gmk_qr_finish(
        'error',
        gmk_qr_reason_to_code((string)$reason, $session),
        $msg,
        $target,
        $sessionid,
        (int)$USER->id,
        ['attendance_reason' => (string)$reason]
    );
}

$attconfig = get_config('attendance');
$qrpassflag = false;

if (gmk_qr_is_session_open_for_students($session) && (int)$session->rotateqrcode === 1) {
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
        gmk_qr_finish(
            'error',
            'qr_expired',
            'QR invalido o expirado. Escanea nuevamente.',
            $target,
            $sessionid,
            (int)$USER->id,
            ['rotate' => 1]
        );
    }
}

if ((int)$session->autoassignstatus !== 1 || !gmk_qr_is_session_open_for_students($session)) {
    $reasoncode = !gmk_qr_is_session_open_for_students($session) ? 'outside_window' : 'session_not_autoassign';
    gmk_qr_finish(
        'error',
        $reasoncode,
        'La sesion no permite marcado automatico por QR en este momento.',
        $target,
        $sessionid,
        (int)$USER->id
    );
}

if (!empty($session->studentpassword) && !$qrpassflag) {
    if ((string)$qrpass === '' || (string)$session->studentpassword !== (string)$qrpass) {
        gmk_qr_finish(
            'error',
            'qr_invalid',
            'QR invalido para esta sesion.',
            $target,
            $sessionid,
            (int)$USER->id
        );
    }
}

// Pass pageparams with sessid so that mod_attendance's attendance_taken event
// can correctly populate its required 'sessionid' field. Some versions of the
// attendance module read this from pageparams when building the event data.
$pageparams = new stdClass();
$pageparams->sessid    = (int)$sessionid;
$pageparams->grouptype = 0;

$attstructure = new mod_attendance_structure($attendance, $cm, $course, $pageparams);
$statusid = attendance_session_get_highest_status($attstructure, $session);
if (empty($statusid)) {
    gmk_qr_finish(
        'error',
        'no_valid_status',
        'No hay un estado de asistencia valido para registrar.',
        $target,
        $sessionid,
        (int)$USER->id
    );
}

$payload = new stdClass();
$payload->status = (int)$statusid;
$payload->sessid = (int)$sessionid;
if ((string)$qrpass !== '') {
    $payload->studentpassword = (string)$qrpass;
}

// take_from_student writes the attendance log and then fires the attendance_taken
// event. Some versions of mod_attendance throw a coding_exception when the event
// is missing 'sessionid' even though the log was already committed. We catch that
// specific case and verify via the DB so the student still gets the success result.
//
// We also buffer output so that PHP notices emitted by the attendance event
// internals do not reach the response stream. If they did, Moodle's redirect()
// would fall back to the HTML confirmation page instead of an instant 303.
$success = false;
ob_start();
try {
    $success = $attstructure->take_from_student($payload);
} catch (\coding_exception $e) {
    // The event validation failed, but the DB write may have already succeeded.
    $success = $DB->record_exists('attendance_log', [
        'sessionid' => (int)$sessionid,
        'studentid' => (int)$USER->id,
    ]);
} catch (Throwable $e) {
    $success = false;
}
ob_end_clean();

if (!$success) {
    if ($DB->record_exists('attendance_log', ['sessionid' => (int)$sessionid, 'studentid' => (int)$USER->id])) {
        gmk_qr_finish(
            'success',
            'already_marked',
            'Asistencia registrada para esta sesion.',
            $target,
            $sessionid,
            (int)$USER->id
        );
    }
    gmk_qr_finish(
        'error',
        'mark_failed',
        'No fue posible registrar la asistencia. Intenta de nuevo.',
        $target,
        $sessionid,
        (int)$USER->id
    );
}

gmk_qr_finish(
    'success',
    'marked',
    'Asistencia registrada correctamente.',
    $target,
    $sessionid,
    (int)$USER->id,
    ['statusid' => (int)$statusid]
);
