<?php
/**
 * Debug page for QR attendance bridge traces.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');

use local_grupomakro_core\external\teacher\attendance_manager;

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/attendance_qr_trace_debug.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug QR Attendance Trace');
$PAGE->set_heading('Debug QR Attendance Trace');
$PAGE->set_pagelayout('admin');

/**
 * Escape html.
 *
 * @param mixed $value
 * @return string
 */
function gmk_qr_debug_s($value) {
    return s((string)$value);
}

/**
 * Format timestamp or return dash.
 *
 * @param int $timestamp
 * @return string
 */
function gmk_qr_debug_time($timestamp) {
    $timestamp = (int)$timestamp;
    if ($timestamp <= 0) {
        return '-';
    }
    return userdate($timestamp, '%Y-%m-%d %H:%M:%S');
}

/**
 * Render a boolean badge.
 *
 * @param bool $value
 * @param string $true
 * @param string $false
 * @return string
 */
function gmk_qr_debug_bool_badge($value, $true = 'Yes', $false = 'No') {
    $class = $value ? 'gmk-badge-ok' : 'gmk-badge-bad';
    $label = $value ? $true : $false;
    return '<span class="gmk-badge ' . $class . '">' . gmk_qr_debug_s($label) . '</span>';
}

/**
 * Decode URL-safe base64.
 *
 * @param string $value
 * @return string|false
 */
function gmk_qr_debug_base64url_decode($value) {
    $value = strtr((string)$value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($value, true);
}

/**
 * Build the QR bridge secret for a session.
 *
 * @param stdClass $session
 * @return string
 */
function gmk_qr_debug_bridge_secret($session) {
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
 * Validate a bridge token using the same rules as the bridge page.
 *
 * @param string $token
 * @param stdClass $session
 * @return array{0:bool,1:string,2:array}
 */
function gmk_qr_debug_validate_token($token, $session) {
    $token = trim((string)$token);
    if ($token === '') {
        return [false, 'missing', []];
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return [false, 'format', []];
    }

    [$payloadb64, $signature] = $parts;
    $expected = hash_hmac('sha256', $payloadb64, gmk_qr_debug_bridge_secret($session));
    if (!hash_equals($expected, (string)$signature)) {
        return [false, 'signature', []];
    }

    $decoded = gmk_qr_debug_base64url_decode($payloadb64);
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
 * Extract the fallback bridge token from qrpass.
 *
 * @param string $qrpass
 * @return string
 */
function gmk_qr_debug_token_from_qrpass($qrpass) {
    $qrpass = (string)$qrpass;
    if (strpos($qrpass, 'gmk:') === 0) {
        return substr($qrpass, 4);
    }
    return '';
}

/**
 * Read recent log entries, optionally filtered by trace or session.
 *
 * @param string $logfile
 * @param string $traceid
 * @param int $sessionid
 * @param int $limit
 * @return array
 */
function gmk_qr_debug_read_log_entries($logfile, $traceid = '', $sessionid = 0, $limit = 100) {
    $entries = [];
    if (!is_file($logfile)) {
        return $entries;
    }

    $limit = max(1, min(500, (int)$limit));
    $traceid = trim((string)$traceid);
    $sessionid = (int)$sessionid;

    $file = new SplFileObject($logfile, 'r');
    while (!$file->eof()) {
        $line = trim((string)$file->fgets());
        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }

        $ismatch = true;
        if ($traceid !== '' && (string)($decoded['traceid'] ?? '') !== $traceid) {
            $ismatch = false;
        }
        if ($sessionid > 0 && (int)($decoded['sessionid'] ?? 0) !== $sessionid) {
            $ismatch = false;
        }
        if (!$ismatch) {
            continue;
        }

        $entries[] = $decoded;
        if (count($entries) > $limit) {
            array_shift($entries);
        }
    }

    return array_reverse($entries);
}

/**
 * Extract bridge url from get_qr html payload.
 *
 * @param string $html
 * @return string
 */
function gmk_qr_debug_extract_bridge_url($html) {
    if (!is_string($html) || $html === '') {
        return '';
    }
    if (preg_match('/href="([^"]+)"/', $html, $matches)) {
        return html_entity_decode($matches[1], ENT_QUOTES);
    }
    return '';
}

/**
 * Build diagnostic data for a session.
 *
 * @param int $sessionid
 * @return array|null
 */
function gmk_qr_debug_session_data($sessionid) {
    global $DB;

    $sessionid = (int)$sessionid;
    if ($sessionid <= 0) {
        return null;
    }

    $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', IGNORE_MISSING);
    if (!$session) {
        return null;
    }

    $attendance = $DB->get_record('attendance', ['id' => $session->attendanceid], '*', IGNORE_MISSING);
    if (!$attendance) {
        return ['session' => $session, 'error' => 'Attendance module not found'];
    }

    $cm = get_coursemodule_from_instance('attendance', (int)$attendance->id, 0, false, IGNORE_MISSING);
    $course = $cm ? $DB->get_record('course', ['id' => (int)$cm->course], '*', IGNORE_MISSING) : null;
    $class = null;
    if ($cm) {
        $class = $DB->get_record_sql(
            "SELECT gc.id, gc.name, gc.groupid, gc.periodid, gc.corecourseid
               FROM {gmk_class} gc
              WHERE gc.attendancemoduleid = :cmid
                AND (:groupidzero = 0 OR gc.groupid = :groupidmatch OR gc.groupid = 0)
           ORDER BY CASE WHEN gc.groupid = :groupidexact THEN 0 ELSE 1 END, gc.id DESC",
            [
                'cmid' => (int)$cm->id,
                'groupidzero' => (int)($session->groupid ?? 0),
                'groupidmatch' => (int)($session->groupid ?? 0),
                'groupidexact' => (int)($session->groupid ?? 0),
            ],
            IGNORE_MISSING
        );
    }

    $attconfig = get_config('attendance');
    $margin = max((int)($attconfig->rotateqrcodeexpirymargin ?? 0), 180);
    $now = time();
    $rotationrows = $DB->get_records_sql(
        "SELECT password, expirytime
           FROM {attendance_rotate_passwords}
          WHERE attendanceid = :sessionid
       ORDER BY expirytime DESC",
        ['sessionid' => $sessionid],
        0,
        5
    );

    $rotationpreview = [];
    foreach ($rotationrows as $row) {
        $rotationpreview[] = [
            'prefix' => substr((string)$row->password, 0, 8),
            'expirytime' => (int)$row->expirytime,
        ];
    }

    $validnow = (int)$DB->count_records_select(
        'attendance_rotate_passwords',
        'attendanceid = :sessionid AND expirytime > :now',
        ['sessionid' => $sessionid, 'now' => $now]
    );
    $validmargin = (int)$DB->count_records_select(
        'attendance_rotate_passwords',
        'attendanceid = :sessionid AND expirytime > :mintime',
        ['sessionid' => $sessionid, 'mintime' => ($now - $margin)]
    );

    $generatedqr = attendance_manager::get_qr($sessionid);
    $bridgeurl = '';
    $generatedparams = [];
    $tokendiag = [false, 'missing', []];
    if (($generatedqr['status'] ?? '') === 'success') {
        $bridgeurl = gmk_qr_debug_extract_bridge_url((string)($generatedqr['html'] ?? ''));
        $query = parse_url($bridgeurl, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $generatedparams);
        }
        $generatedtoken = (string)($generatedparams['gmkqr'] ?? '');
        if ($generatedtoken === '') {
            $generatedtoken = gmk_qr_debug_token_from_qrpass((string)($generatedparams['qrpass'] ?? ''));
        }
        if ($generatedtoken !== '') {
            $tokendiag = gmk_qr_debug_validate_token($generatedtoken, $session);
        }
    }

    return [
        'session' => $session,
        'attendance' => $attendance,
        'cm' => $cm,
        'course' => $course,
        'class' => $class,
        'open_now' => attendance_session_open_for_students($session),
        'margin' => $margin,
        'rotation_valid_now' => $validnow,
        'rotation_valid_margin' => $validmargin,
        'rotation_preview' => $rotationpreview,
        'generated_qr' => $generatedqr,
        'generated_url' => $bridgeurl,
        'generated_params' => $generatedparams,
        'generated_token_validation' => $tokendiag,
    ];
}

/**
 * Build root-cause hints from the selected trace and current session.
 *
 * @param array|null $entry
 * @param array|null $sessiondiag
 * @return array
 */
function gmk_qr_debug_hints($entry, $sessiondiag) {
    $hints = [];
    if (!$entry) {
        return $hints;
    }

    $reasoncode = (string)($entry['reasoncode'] ?? '');
    $request = $entry['request'] ?? [];
    $query = $request['query'] ?? [];
    $signed = $request['signedtoken'] ?? [];

    if ($reasoncode === 'qr_expired') {
        if (!empty($sessiondiag['generated_params']['gmkqr']) && empty($query['gmkqr_present']) && (($query['qrpass_prefix'] ?? '') !== 'gmk:')) {
            $hints[] = 'El servidor genera un QR con token firmado, pero este intento llego sin gmkqr ni fallback gmk: en qrpass. Eso apunta a que la app del estudiante o una redireccion intermedia esta perdiendo la query antes de volver al bridge.';
        }
        if (!empty($signed['present']) && ($signed['reason'] ?? '') === 'expired') {
            $hints[] = 'El token firmado si llego, pero para cuando el bridge lo valido ya estaba vencido. Aqui el problema seria tiempo real de autenticacion o demora excesiva entre escaneo y retorno al bridge.';
        }
        if (!empty($signed['present']) && in_array((string)($signed['reason'] ?? ''), ['signature', 'decode', 'format', 'json'], true)) {
            $hints[] = 'El token firmado llego truncado o alterado. Eso suele pasar cuando el navegador o una app modifica la URL del QR.';
        }
        if (empty($signed['present']) && !empty($query['qrpass_present']) && (($query['qrpass_prefix'] ?? '') !== 'gmk:')) {
            $hints[] = 'El intento llego por la ruta antigua basada en qrpass rotativo, no por el token firmado. Eso suele significar que el cliente esta usando un bundle viejo o un QR previo al cambio.';
        }
    }

    if ($sessiondiag && empty($sessiondiag['open_now'])) {
        $hints[] = 'La sesion actualmente no esta abierta para estudiantes. Si el problema se reproduce dentro de esta ventana, revisa la hora del servidor y el campo studentsearlyopentime.';
    }

    if ($sessiondiag && ($sessiondiag['session']->rotateqrcode ?? 0) == 1 && (int)($sessiondiag['rotation_valid_margin'] ?? 0) === 0) {
        $hints[] = 'La sesion sigue marcada como QR rotativo, pero no hay claves vigentes ni dentro del margen. Si el intento entra sin token firmado, inevitablemente caera en qr_expired.';
    }

    return array_values(array_unique($hints));
}

$traceid = optional_param('traceid', '', PARAM_RAW_TRIMMED);
$sessionid = optional_param('sessionid', 0, PARAM_INT);
$limit = max(20, min(300, optional_param('limit', 80, PARAM_INT)));
$clearlog = optional_param('clearlog', 0, PARAM_BOOL);
$logfile = __DIR__ . '/../gmk_qr_attendance.log';

if ($clearlog && confirm_sesskey()) {
    @file_put_contents($logfile, '');
    redirect(new moodle_url('/local/grupomakro_core/pages/attendance_qr_trace_debug.php'));
}

$entries = gmk_qr_debug_read_log_entries($logfile, $traceid, $sessionid, $limit);
$selected = $entries[0] ?? null;
if ($sessionid <= 0 && !empty($selected['sessionid'])) {
    $sessionid = (int)$selected['sessionid'];
}
$sessiondiag = gmk_qr_debug_session_data($sessionid);
$hints = gmk_qr_debug_hints($selected, $sessiondiag);

echo $OUTPUT->header();
?>
<style>
.gmk-debug-wrap { max-width: 1500px; margin: 0 auto; padding: 16px 22px; font-family: Segoe UI, Arial, sans-serif; }
.gmk-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
.gmk-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
.gmk-kv { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; }
.gmk-kv .label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.35px; margin-bottom: 5px; }
.gmk-kv .value { font-size: 14px; color: #0f172a; word-break: break-word; }
.gmk-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.gmk-badge-ok { background: #dcfce7; color: #166534; }
.gmk-badge-bad { background: #fee2e2; color: #b91c1c; }
.gmk-badge-warn { background: #fef3c7; color: #92400e; }
.gmk-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.gmk-table th, .gmk-table td { border-bottom: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; vertical-align: top; }
.gmk-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.35px; color: #475569; background: #f8fafc; }
.gmk-pre { background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 14px; overflow: auto; font-size: 12px; }
.gmk-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.gmk-link { color: #0f766e; text-decoration: none; font-weight: 600; }
.gmk-link:hover { text-decoration: underline; }
.gmk-hints { margin: 0; padding-left: 18px; }
.gmk-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
.gmk-form label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px; }
.gmk-form input { min-width: 220px; padding: 7px 10px; border-radius: 8px; border: 1px solid #cbd5e1; }
.gmk-btn { display: inline-flex; align-items: center; gap: 6px; text-decoration: none; padding: 9px 14px; border-radius: 8px; font-weight: 700; font-size: 13px; border: none; cursor: pointer; }
.gmk-btn-primary { background: #0f766e; color: #fff; }
.gmk-btn-outline { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
.gmk-btn-danger { background: #b91c1c; color: #fff; }
</style>

<div class="gmk-debug-wrap">
    <div class="gmk-panel">
        <h2 style="margin:0 0 12px 0; font-size:20px; color:#0f172a;">Debug QR attendance</h2>
        <p style="margin:0 0 16px 0; color:#475569;">
            Busca por <strong>traceid</strong> del error mostrado al estudiante o por <strong>sessionid</strong>.
            La pagina tambien inspecciona el QR que el servidor genera hoy para esa sesion.
        </p>

        <form method="get" action="" class="gmk-form">
            <div>
                <label for="traceid">Trace ID</label>
                <input id="traceid" name="traceid" type="text" value="<?php echo gmk_qr_debug_s($traceid); ?>" placeholder="qr_20260327141719_ed7ef3c0">
            </div>
            <div>
                <label for="sessionid">Session ID</label>
                <input id="sessionid" name="sessionid" type="number" value="<?php echo (int)$sessionid; ?>" min="0" step="1" placeholder="12345">
            </div>
            <div>
                <label for="limit">Max entries</label>
                <input id="limit" name="limit" type="number" value="<?php echo (int)$limit; ?>" min="20" max="300" step="1">
            </div>
            <div class="gmk-actions">
                <button type="submit" class="gmk-btn gmk-btn-primary">Buscar</button>
                <a class="gmk-btn gmk-btn-outline" href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/attendance_qr_sessions_debug.php">Debug sesiones QR</a>
                <a class="gmk-btn gmk-btn-outline" href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/attendance_qr_trace_debug.php">Limpiar filtros</a>
                <a class="gmk-btn gmk-btn-danger" href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/attendance_qr_trace_debug.php?clearlog=1&sesskey=<?php echo sesskey(); ?>">Vaciar log</a>
            </div>
        </form>
    </div>

    <div class="gmk-panel">
        <div class="gmk-grid">
            <div class="gmk-kv">
                <div class="label">Log file</div>
                <div class="value"><?php echo gmk_qr_debug_s($logfile); ?></div>
            </div>
            <div class="gmk-kv">
                <div class="label">Log exists</div>
                <div class="value"><?php echo gmk_qr_debug_bool_badge(is_file($logfile)); ?></div>
            </div>
            <div class="gmk-kv">
                <div class="label">Entries shown</div>
                <div class="value"><?php echo count($entries); ?></div>
            </div>
            <div class="gmk-kv">
                <div class="label">Current time</div>
                <div class="value"><?php echo gmk_qr_debug_s(date('Y-m-d H:i:s')); ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($hints)): ?>
    <div class="gmk-panel">
        <h3 style="margin-top:0;">Possible root-cause hints</h3>
        <ul class="gmk-hints">
            <?php foreach ($hints as $hint): ?>
            <li><?php echo gmk_qr_debug_s($hint); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($selected): ?>
    <div class="gmk-panel">
        <h3 style="margin-top:0;">Selected trace</h3>
        <div class="gmk-grid">
            <div class="gmk-kv"><div class="label">Trace ID</div><div class="value"><?php echo gmk_qr_debug_s($selected['traceid'] ?? ''); ?></div></div>
            <div class="gmk-kv"><div class="label">Status</div><div class="value"><?php echo gmk_qr_debug_s($selected['status'] ?? ''); ?></div></div>
            <div class="gmk-kv"><div class="label">Reason</div><div class="value"><?php echo gmk_qr_debug_s($selected['reasoncode'] ?? ''); ?></div></div>
            <div class="gmk-kv"><div class="label">Time</div><div class="value"><?php echo gmk_qr_debug_s($selected['time'] ?? ''); ?></div></div>
            <div class="gmk-kv"><div class="label">Session ID</div><div class="value"><?php echo (int)($selected['sessionid'] ?? 0); ?></div></div>
            <div class="gmk-kv"><div class="label">User ID</div><div class="value"><?php echo (int)($selected['userid'] ?? 0); ?></div></div>
            <div class="gmk-kv"><div class="label">Signed token present</div><div class="value"><?php echo gmk_qr_debug_bool_badge(!empty($selected['request']['signedtoken']['present'])); ?></div></div>
            <div class="gmk-kv"><div class="label">Signed token reason</div><div class="value"><?php echo gmk_qr_debug_s($selected['request']['signedtoken']['reason'] ?? '-'); ?></div></div>
            <div class="gmk-kv"><div class="label">qrpass prefix</div><div class="value"><?php echo gmk_qr_debug_s($selected['request']['query']['qrpass_prefix'] ?? '-'); ?></div></div>
            <div class="gmk-kv"><div class="label">Message</div><div class="value"><?php echo gmk_qr_debug_s($selected['message'] ?? ''); ?></div></div>
        </div>

        <h4>Full payload</h4>
        <pre class="gmk-pre"><?php echo gmk_qr_debug_s(json_encode($selected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
    <?php endif; ?>

    <?php if ($sessiondiag): ?>
    <div class="gmk-panel">
        <h3 style="margin-top:0;">Current session diagnostics</h3>
        <div class="gmk-grid">
            <div class="gmk-kv"><div class="label">Session ID</div><div class="value"><?php echo (int)$sessiondiag['session']->id; ?></div></div>
            <div class="gmk-kv"><div class="label">Course</div><div class="value"><?php echo gmk_qr_debug_s($sessiondiag['course']->fullname ?? '-'); ?></div></div>
            <div class="gmk-kv"><div class="label">Class</div><div class="value"><?php echo gmk_qr_debug_s($sessiondiag['class']->name ?? '-'); ?></div></div>
            <div class="gmk-kv"><div class="label">Open now</div><div class="value"><?php echo gmk_qr_debug_bool_badge(!empty($sessiondiag['open_now'])); ?></div></div>
            <div class="gmk-kv"><div class="label">Auto assign</div><div class="value"><?php echo gmk_qr_debug_bool_badge(((int)($sessiondiag['session']->autoassignstatus ?? 0) === 1)); ?></div></div>
            <div class="gmk-kv"><div class="label">Student scan mark</div><div class="value"><?php echo gmk_qr_debug_bool_badge(((int)($sessiondiag['session']->studentscanmark ?? 0) === 1)); ?></div></div>
            <div class="gmk-kv"><div class="label">Rotate QR</div><div class="value"><?php echo gmk_qr_debug_bool_badge(((int)($sessiondiag['session']->rotateqrcode ?? 0) === 1)); ?></div></div>
            <div class="gmk-kv"><div class="label">Include QR</div><div class="value"><?php echo gmk_qr_debug_bool_badge(((int)($sessiondiag['session']->includeqrcode ?? 0) === 1)); ?></div></div>
            <div class="gmk-kv"><div class="label">Session date</div><div class="value"><?php echo gmk_qr_debug_time((int)$sessiondiag['session']->sessdate); ?></div></div>
            <div class="gmk-kv"><div class="label">Duration (sec)</div><div class="value"><?php echo (int)($sessiondiag['session']->duration ?? 0); ?></div></div>
            <div class="gmk-kv"><div class="label">Early open (sec)</div><div class="value"><?php echo (int)($sessiondiag['session']->studentsearlyopentime ?? 0); ?></div></div>
            <div class="gmk-kv"><div class="label">Rotation margin</div><div class="value"><?php echo (int)$sessiondiag['margin']; ?></div></div>
            <div class="gmk-kv"><div class="label">Rotation valid now</div><div class="value"><?php echo (int)$sessiondiag['rotation_valid_now']; ?></div></div>
            <div class="gmk-kv"><div class="label">Rotation valid with margin</div><div class="value"><?php echo (int)$sessiondiag['rotation_valid_margin']; ?></div></div>
        </div>

        <h4>Current QR generated by server</h4>
        <?php if (($sessiondiag['generated_qr']['status'] ?? '') === 'success'): ?>
            <div class="gmk-grid">
                <div class="gmk-kv"><div class="label">Bridge URL</div><div class="value"><?php echo gmk_qr_debug_s($sessiondiag['generated_url'] ?? '-'); ?></div></div>
                <div class="gmk-kv"><div class="label">Has gmkqr</div><div class="value"><?php echo gmk_qr_debug_bool_badge(!empty($sessiondiag['generated_params']['gmkqr'])); ?></div></div>
                <div class="gmk-kv"><div class="label">Has qrpass fallback</div><div class="value"><?php echo gmk_qr_debug_bool_badge(!empty($sessiondiag['generated_params']['qrpass'])); ?></div></div>
                <div class="gmk-kv"><div class="label">Rotate interval</div><div class="value"><?php echo (int)($sessiondiag['generated_qr']['rotate_interval'] ?? 0); ?></div></div>
                <div class="gmk-kv"><div class="label">Token validation</div><div class="value"><?php echo gmk_qr_debug_s(($sessiondiag['generated_token_validation'][1] ?? '-')); ?></div></div>
                <div class="gmk-kv"><div class="label">Token payload</div><div class="value"><?php echo gmk_qr_debug_s(json_encode($sessiondiag['generated_token_validation'][2] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></div></div>
            </div>
        <?php else: ?>
            <div class="gmk-kv">
                <div class="label">QR generation status</div>
                <div class="value"><?php echo gmk_qr_debug_s($sessiondiag['generated_qr']['message'] ?? 'QR generation failed'); ?></div>
            </div>
        <?php endif; ?>

        <h4>Recent rotate-password rows</h4>
        <?php if (!empty($sessiondiag['rotation_preview'])): ?>
            <table class="gmk-table">
                <thead>
                    <tr>
                        <th>Password prefix</th>
                        <th>Expiry time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessiondiag['rotation_preview'] as $row): ?>
                    <tr>
                        <td><?php echo gmk_qr_debug_s($row['prefix']); ?></td>
                        <td><?php echo gmk_qr_debug_time($row['expirytime']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#64748b;">No rotate-password rows found for this session.</p>
        <?php endif; ?>
    </div>
    <?php elseif ($sessionid > 0): ?>
    <div class="gmk-panel">
        <h3 style="margin-top:0;">Current session diagnostics</h3>
        <p style="color:#b91c1c;">Session <?php echo (int)$sessionid; ?> was not found.</p>
    </div>
    <?php endif; ?>

    <div class="gmk-panel">
        <h3 style="margin-top:0;">Recent matching log entries</h3>
        <?php if (!empty($entries)): ?>
            <table class="gmk-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Trace</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th>Session</th>
                        <th>User</th>
                        <th>gmkqr</th>
                        <th>Signed reason</th>
                        <th>qrpass prefix</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?php echo gmk_qr_debug_s($entry['time'] ?? ''); ?></td>
                        <td>
                            <a class="gmk-link" href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/attendance_qr_trace_debug.php?traceid=<?php echo urlencode((string)($entry['traceid'] ?? '')); ?>&sessionid=<?php echo (int)($entry['sessionid'] ?? 0); ?>">
                                <?php echo gmk_qr_debug_s($entry['traceid'] ?? ''); ?>
                            </a>
                        </td>
                        <td><?php echo gmk_qr_debug_s($entry['status'] ?? ''); ?></td>
                        <td><?php echo gmk_qr_debug_s($entry['reasoncode'] ?? ''); ?></td>
                        <td><?php echo (int)($entry['sessionid'] ?? 0); ?></td>
                        <td><?php echo (int)($entry['userid'] ?? 0); ?></td>
                        <td><?php echo gmk_qr_debug_bool_badge(!empty($entry['request']['signedtoken']['present'])); ?></td>
                        <td><?php echo gmk_qr_debug_s($entry['request']['signedtoken']['reason'] ?? '-'); ?></td>
                        <td><?php echo gmk_qr_debug_s($entry['request']['query']['qrpass_prefix'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#64748b;">No matching log entries were found.</p>
        <?php endif; ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();
