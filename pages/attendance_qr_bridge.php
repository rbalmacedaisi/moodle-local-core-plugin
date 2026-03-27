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
        'request' => gmk_qr_request_snapshot((int)$sessionid),
    ];
    $logline = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($logline) || $logline === '') {
        $logline = '{"traceid":"' . (string)$traceid . '","status":"log_encode_error"}';
    }
    @file_put_contents(__DIR__ . '/../gmk_qr_attendance.log', $logline . PHP_EOL, FILE_APPEND);
}

/**
 * Build a diagnostic snapshot for the current QR attempt.
 *
 * @param int $sessionid
 * @return array
 */
function gmk_qr_request_snapshot($sessionid = 0) {
    global $DB, $gmkqrtoken, $qrpass;

    $sessioncookie = session_name();
    $snapshot = [
        'server_time' => time(),
        'server_time_iso' => date('c'),
        'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'remote_addr' => function_exists('getremoteaddr') ? (string)getremoteaddr(null) : '',
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'referer' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        'origin' => substr((string)($_SERVER['HTTP_ORIGIN'] ?? ''), 0, 255),
        'query' => [
            'keys' => array_keys($_GET ?? []),
            'gmkqr_present' => ((string)$gmkqrtoken !== ''),
            'gmkqr_length' => strlen((string)$gmkqrtoken),
            'gmkqr_digest' => ((string)$gmkqrtoken !== '') ? substr(hash('sha256', (string)$gmkqrtoken), 0, 16) : '',
            'qrpass_present' => ((string)$qrpass !== ''),
            'qrpass_length' => strlen((string)$qrpass),
            'qrpass_prefix' => substr((string)$qrpass, 0, 16),
            'qrpass_digest' => ((string)$qrpass !== '') ? substr(hash('sha256', (string)$qrpass), 0, 16) : '',
        ],
        'cookies' => [
            'session_cookie_name' => (string)$sessioncookie,
            'session_cookie_present' => ($sessioncookie !== '' && isset($_COOKIE[$sessioncookie])),
            'cookie_names' => array_slice(array_keys($_COOKIE ?? []), 0, 20),
        ],
        'pending_cookie' => $GLOBALS['GMK_QR_DEBUG_PENDINGCOOKIE'] ?? [],
        'signedtoken' => [
            'present' => ((string)$gmkqrtoken !== ''),
            'valid' => !empty($GLOBALS['GMK_QR_DEBUG_SIGNEDTOKENFLAG']),
            'reason' => (string)($GLOBALS['GMK_QR_DEBUG_SIGNEDTOKENREASON'] ?? ''),
            'payload' => $GLOBALS['GMK_QR_DEBUG_SIGNEDTOKENPAYLOAD'] ?? [],
        ],
        'qrpass_validation' => [
            'accepted' => !empty($GLOBALS['GMK_QR_DEBUG_QRPASSFLAG']),
        ],
    ];

    $currentsession = $GLOBALS['GMK_QR_DEBUG_SESSION'] ?? null;
    if (!$currentsession && !empty($sessionid)) {
        $currentsession = $DB->get_record('attendance_sessions', ['id' => (int)$sessionid], '*', IGNORE_MISSING);
    }

    if ($currentsession) {
        $attconfig = get_config('attendance');
        $margin = max(
            (int)($attconfig->rotateqrcodeexpirymargin ?? 0),
            defined('GMK_QR_MIN_EXPIRY_MARGIN') ? GMK_QR_MIN_EXPIRY_MARGIN : 0
        );
        $now = time();
        $validnow = 0;
        $validmargin = 0;
        $nextexpiry = 0;
        $lastexpiry = 0;
        try {
            $validnow = (int)$DB->count_records_select(
                'attendance_rotate_passwords',
                'attendanceid = :sessionid AND expirytime > :now',
                ['sessionid' => (int)$currentsession->id, 'now' => $now]
            );
            $validmargin = (int)$DB->count_records_select(
                'attendance_rotate_passwords',
                'attendanceid = :sessionid AND expirytime > :mintime',
                ['sessionid' => (int)$currentsession->id, 'mintime' => ($now - $margin)]
            );
            $nextexpiry = (int)$DB->get_field_sql(
                'SELECT MIN(expirytime)
                   FROM {attendance_rotate_passwords}
                  WHERE attendanceid = :sessionid
                    AND expirytime > :now',
                ['sessionid' => (int)$currentsession->id, 'now' => $now]
            );
            $lastexpiry = (int)$DB->get_field_sql(
                'SELECT MAX(expirytime)
                   FROM {attendance_rotate_passwords}
                  WHERE attendanceid = :sessionid',
                ['sessionid' => (int)$currentsession->id]
            );
        } catch (Throwable $e) {
            // Keep the snapshot lightweight and non-blocking.
        }

        $sessionend = ((int)$currentsession->sessdate) + ((int)$currentsession->duration);
        $snapshot['session'] = [
            'id' => (int)$currentsession->id,
            'attendanceid' => (int)$currentsession->attendanceid,
            'groupid' => (int)($currentsession->groupid ?? 0),
            'sessdate' => (int)$currentsession->sessdate,
            'duration' => (int)$currentsession->duration,
            'enddate' => $sessionend,
            'studentscanmark' => (int)($currentsession->studentscanmark ?? 0),
            'autoassignstatus' => (int)($currentsession->autoassignstatus ?? 0),
            'includeqrcode' => (int)($currentsession->includeqrcode ?? 0),
            'rotateqrcode' => (int)($currentsession->rotateqrcode ?? 0),
            'studentsearlyopentime' => (int)($currentsession->studentsearlyopentime ?? 0),
            'studentpassword_present' => (trim((string)($currentsession->studentpassword ?? '')) !== ''),
            'open_now' => gmk_qr_is_session_open_for_students($currentsession),
            'rotation_margin' => $margin,
            'rotation_valid_now' => $validnow,
            'rotation_valid_with_margin' => $validmargin,
            'rotation_next_expiry' => $nextexpiry,
            'rotation_last_expiry' => $lastexpiry,
        ];
    }

    return $snapshot;
}

/**
 * Decode URL-safe base64 payloads.
 *
 * @param string $value
 * @return string|false
 */
function gmk_qr_base64url_decode($value) {
    $value = strtr((string)$value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($value, true);
}

/**
 * Build the secret used to sign pending QR cookies.
 *
 * @param int $sessionid
 * @return string
 */
function gmk_qr_pending_cookie_secret($sessionid) {
    global $CFG;

    $secretbase = (string)($CFG->passwordsaltmain ?? '');
    if ($secretbase === '') {
        $secretbase = sha1((string)($CFG->wwwroot ?? ''));
    }

    return hash('sha256', $secretbase . '|gmk_qr_pending|' . (int)$sessionid);
}

/**
 * Get the cookie name used to persist QR data across auth redirects.
 *
 * @param int $sessionid
 * @return string
 */
function gmk_qr_pending_cookie_name($sessionid) {
    return 'gmk_qr_pending_' . (int)$sessionid;
}

/**
 * Store pending QR data in a short-lived signed cookie.
 *
 * @param int $sessionid
 * @param string $gmkqrtoken
 * @param string $qrpass
 * @return bool
 */
function gmk_qr_set_pending_cookie($sessionid, $gmkqrtoken, $qrpass) {
    $sessionid = (int)$sessionid;
    if ($sessionid <= 0 || ((string)$gmkqrtoken === '' && (string)$qrpass === '')) {
        return false;
    }

    $payload = [
        'sid' => $sessionid,
        'exp' => time() + 600,
        'gmkqr' => (string)$gmkqrtoken,
        'qrpass' => (string)$qrpass,
    ];
    $encodedpayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $encodedpayload, gmk_qr_pending_cookie_secret($sessionid));
    $cookievalue = $encodedpayload . '.' . $signature;

    return setcookie(gmk_qr_pending_cookie_name($sessionid), $cookievalue, [
        'expires' => time() + 600,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Read and validate pending QR data from cookie.
 *
 * @param int $sessionid
 * @return array
 */
function gmk_qr_read_pending_cookie($sessionid) {
    $sessionid = (int)$sessionid;
    $name = gmk_qr_pending_cookie_name($sessionid);
    if ($sessionid <= 0 || empty($_COOKIE[$name])) {
        return [
            'present' => false,
            'valid' => false,
            'reason' => 'missing',
            'gmkqr' => '',
            'qrpass' => '',
        ];
    }

    $cookievalue = trim((string)$_COOKIE[$name]);
    $parts = explode('.', $cookievalue, 2);
    if (count($parts) !== 2) {
        return [
            'present' => true,
            'valid' => false,
            'reason' => 'format',
            'gmkqr' => '',
            'qrpass' => '',
        ];
    }

    [$payloadb64, $signature] = $parts;
    $expected = hash_hmac('sha256', $payloadb64, gmk_qr_pending_cookie_secret($sessionid));
    if (!hash_equals($expected, (string)$signature)) {
        return [
            'present' => true,
            'valid' => false,
            'reason' => 'signature',
            'gmkqr' => '',
            'qrpass' => '',
        ];
    }

    $decoded = gmk_qr_base64url_decode($payloadb64);
    if ($decoded === false) {
        return [
            'present' => true,
            'valid' => false,
            'reason' => 'decode',
            'gmkqr' => '',
            'qrpass' => '',
        ];
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return [
            'present' => true,
            'valid' => false,
            'reason' => 'json',
            'gmkqr' => '',
            'qrpass' => '',
        ];
    }

    if ((int)($payload['sid'] ?? 0) !== $sessionid) {
        return [
            'present' => true,
            'valid' => false,
            'reason' => 'session',
            'gmkqr' => '',
            'qrpass' => '',
        ];
    }

    if ((int)($payload['exp'] ?? 0) < time()) {
        return [
            'present' => true,
            'valid' => false,
            'reason' => 'expired',
            'gmkqr' => '',
            'qrpass' => '',
        ];
    }

    return [
        'present' => true,
        'valid' => true,
        'reason' => 'ok',
        'gmkqr' => (string)($payload['gmkqr'] ?? ''),
        'qrpass' => (string)($payload['qrpass'] ?? ''),
    ];
}

/**
 * Clear the pending QR cookie for a session.
 *
 * @param int $sessionid
 * @return void
 */
function gmk_qr_clear_pending_cookie($sessionid) {
    $sessionid = (int)$sessionid;
    if ($sessionid <= 0) {
        return;
    }

    setcookie(gmk_qr_pending_cookie_name($sessionid), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Resolve the signing secret for bridge QR tokens.
 *
 * @param stdClass $session
 * @return string
 */
function gmk_qr_bridge_secret($session) {
    global $CFG;

    $secretbase = (string)($CFG->passwordsaltmain ?? '');
    if ($secretbase === '') {
        $secretbase = sha1((string)($CFG->wwwroot ?? ''));
    }

    return hash('sha256', implode('|', [
        $secretbase,
        (string)((int)$session->id),
        (string)((int)$session->attendanceid),
        (string)($session->rotateqrcodesecret ?? ''),
        (string)($session->studentpassword ?? ''),
    ]));
}

/**
 * Validate a signed bridge QR token.
 *
 * @param string $token
 * @param stdClass $session
 * @return array{0:bool,1:string,2:array}
 */
function gmk_qr_validate_bridge_token($token, $session) {
    $token = trim((string)$token);
    if ($token === '') {
        return [false, 'missing', []];
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return [false, 'format', []];
    }

    [$payloadb64, $signature] = $parts;
    $expected = hash_hmac('sha256', $payloadb64, gmk_qr_bridge_secret($session));
    if (!hash_equals($expected, (string)$signature)) {
        return [false, 'signature', []];
    }

    $decoded = gmk_qr_base64url_decode($payloadb64);
    if ($decoded === false) {
        return [false, 'decode', []];
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return [false, 'json', []];
    }

    if ((int)($payload['sid'] ?? 0) !== (int)$session->id) {
        return [false, 'session', $payload];
    }

    $issuedat = (int)($payload['iat'] ?? 0);
    $expiresat = (int)($payload['exp'] ?? 0);
    $now = time();
    if ($expiresat <= 0 || $expiresat < $now) {
        return [false, 'expired', $payload];
    }
    if ($issuedat > ($now + 60)) {
        return [false, 'issued_in_future', $payload];
    }

    return [true, 'ok', $payload];
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
    gmk_qr_clear_pending_cookie((int)$sessionid);
    gmk_qr_redirect_to_student_ui($status, $reasoncode, $message, $target, $sessionid, $traceid);
}

$sessionid = required_param('sessid', PARAM_INT);
$qrpass = optional_param('qrpass', '', PARAM_RAW_TRIMMED);
$gmkqrtoken = optional_param('gmkqr', '', PARAM_RAW_TRIMMED);

// Older student-app bundles only preserve qrpass across the login flow.
// Accept the signed bridge token there too when prefixed with "gmk:".
if ($gmkqrtoken === '' && strpos($qrpass, 'gmk:') === 0) {
    $gmkqrtoken = substr($qrpass, 4);
    $qrpass = '';
}

// If the student has no Moodle session, route them through the Vue student
// app instead of Moodle's login page. The Vue login (soluttolms_core/token.php)
// creates both the Vue token and a Moodle web session, so returning to this
// bridge afterwards will pass require_login() correctly.
// This avoids the issue where the custom Moodle theme ignores wantsurl and
// sends unauthenticated users to home after login instead of back here.
if (!isloggedin() || isguestuser()) {
    $pendingcookieset = gmk_qr_set_pending_cookie((int)$sessionid, (string)$gmkqrtoken, (string)$qrpass);
    $GLOBALS['GMK_QR_DEBUG_PENDINGCOOKIE'] = [
        'set' => $pendingcookieset,
        'present' => false,
        'recovered' => false,
        'reason' => $pendingcookieset ? 'stored_before_redirect' : 'not_stored',
    ];
    gmk_qr_log_decision(
        gmk_qr_trace_id(),
        'redirect',
        'bridge_requires_login',
        'Bridge redirigido al LXP para autenticacion previa.',
        (int)$sessionid,
        0,
        ['classid' => 0, 'courseid' => 0, 'classname' => '', 'coursename' => ''],
        ['target' => 'lxp_attendance_qr']
    );
    $vuebase  = gmk_qr_student_app_base_url();
    $vueparams = ['sessid' => (int)$sessionid];
    if ($gmkqrtoken !== '') {
        $vueparams['gmkqr'] = $gmkqrtoken;
        $vueparams['qrpass'] = 'gmk:' . $gmkqrtoken;
    }
    if ($qrpass !== '') {
        $vueparams['qrpass'] = $qrpass;
    }
    header('Location: ' . $vuebase . '/attendance/qr?' . http_build_query($vueparams));
    exit;
}

$pendingcookie = gmk_qr_read_pending_cookie((int)$sessionid);
$GLOBALS['GMK_QR_DEBUG_PENDINGCOOKIE'] = [
    'set' => false,
    'present' => !empty($pendingcookie['present']),
    'recovered' => false,
    'reason' => (string)($pendingcookie['reason'] ?? 'missing'),
];
if ((string)$gmkqrtoken === '' && !empty($pendingcookie['valid']) && (string)($pendingcookie['gmkqr'] ?? '') !== '') {
    $gmkqrtoken = (string)$pendingcookie['gmkqr'];
    $GLOBALS['GMK_QR_DEBUG_PENDINGCOOKIE']['recovered'] = true;
}
if ((string)$qrpass === '' && !empty($pendingcookie['valid']) && (string)($pendingcookie['qrpass'] ?? '') !== '') {
    $qrpass = (string)$pendingcookie['qrpass'];
    $GLOBALS['GMK_QR_DEBUG_PENDINGCOOKIE']['recovered'] = true;
}
if ($gmkqrtoken === '' && strpos($qrpass, 'gmk:') === 0) {
    $gmkqrtoken = substr($qrpass, 4);
    $qrpass = '';
    $GLOBALS['GMK_QR_DEBUG_PENDINGCOOKIE']['recovered'] = true;
}

$session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$attendance = $DB->get_record('attendance', ['id' => $session->attendanceid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('attendance', (int)$attendance->id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => (int)$cm->course], '*', MUST_EXIST);
$GLOBALS['GMK_QR_DEBUG_SESSION'] = $session;
$GLOBALS['GMK_QR_DEBUG_ATTENDANCE'] = $attendance;

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
$signedtokenflag = false;
$signedtokenreason = 'missing';
$signedtokenpayload = [];

// Minimum grace period: 3 minutes (180 s) to allow students time to authenticate
// through the Vue LXP before the rotated QR is considered expired.
if (!defined('GMK_QR_MIN_EXPIRY_MARGIN')) {
    define('GMK_QR_MIN_EXPIRY_MARGIN', 180);
}

if ($gmkqrtoken !== '') {
    [$signedtokenflag, $signedtokenreason, $signedtokenpayload] = gmk_qr_validate_bridge_token($gmkqrtoken, $session);
}
$GLOBALS['GMK_QR_DEBUG_SIGNEDTOKENFLAG'] = $signedtokenflag;
$GLOBALS['GMK_QR_DEBUG_SIGNEDTOKENREASON'] = $signedtokenreason;
$GLOBALS['GMK_QR_DEBUG_SIGNEDTOKENPAYLOAD'] = $signedtokenpayload;

if (!$signedtokenflag && gmk_qr_is_session_open_for_students($session) && (int)$session->rotateqrcode === 1) {
    $configured_margin = (int)($attconfig->rotateqrcodeexpirymargin ?? 0);
    $effective_margin  = max($configured_margin, GMK_QR_MIN_EXPIRY_MARGIN);
    if ((string)$qrpass !== '') {
        $qrpassflag = $DB->record_exists_select(
            'attendance_rotate_passwords',
            'attendanceid = :sessionid AND password = :password AND expirytime > :mintime',
            [
                'sessionid' => (int)$sessionid,
                'password' => (string)$qrpass,
                'mintime' => time() - $effective_margin,
            ]
        );
    }
    $GLOBALS['GMK_QR_DEBUG_QRPASSFLAG'] = $qrpassflag;
    if (!$qrpassflag) {
        gmk_qr_finish(
            'error',
            'qr_expired',
            'QR invalido o expirado. Escanea nuevamente.',
            $target,
            $sessionid,
            (int)$USER->id,
            [
                'rotate' => 1,
                'signedtokenpresent' => ($gmkqrtoken !== ''),
                'signedtokenreason' => $signedtokenreason,
                'signedtokenpayload' => $signedtokenpayload,
                'qrpasspresent' => ((string)$qrpass !== ''),
            ]
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

if (!empty($session->studentpassword) && !$qrpassflag && !$signedtokenflag) {
    if ((string)$qrpass === '' || (string)$session->studentpassword !== (string)$qrpass) {
        gmk_qr_finish(
            'error',
            'qr_invalid',
            'QR invalido para esta sesion.',
            $target,
            $sessionid,
            (int)$USER->id,
            [
                'signedtokenpresent' => ($gmkqrtoken !== ''),
                'signedtokenreason' => $signedtokenreason,
                'qrpasspresent' => ((string)$qrpass !== ''),
            ]
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
