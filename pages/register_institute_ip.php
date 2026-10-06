<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Endpoint publico para registrar la IP del instituto con TTL.
 *
 * Pensado para tres vias de uso, todas con header X-Institute-Token:
 *
 * 1. Script on-site (cron @ instituto): detecta IP publica via api.ipify.org
 *    y postea aca este IP. Autenticado con el token compartido
 *    (attendance_qr_institute_token).
 *
 * 2. Link one-time del recordatorio diario (email): la URL se visita desde
 *    un dispositivo del instituto, el server lee su REMOTE_ADDR directamente.
 *    Token via GET (?token=...).
 *
 * 3. curl manual del admin para forzar una IP concreta (?ip=... & token=...).
 *
 * Responde JSON siempre. Logged via gmk_log si esta disponible.
 *
 * @package    local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

global $DB;

// Este endpoint se autentica por token, no por sesion Moodle. NO usar
// require_login() porque la llaman scripts/curl sin cookie de sesion.

// Header JSON
header('Content-Type: application/json; charset=utf-8');

$configured = (string)get_config('local_grupomakro_core', 'attendance_qr_institute_token');
$provided = null;
if (!empty($_SERVER['HTTP_X_INSTITUTE_TOKEN'])) {
    $provided = (string)$_SERVER['HTTP_X_INSTITUTE_TOKEN'];
} else if (!empty($_GET['token'])) {
    $provided = (string)$_GET['token'];
}

if ($configured === '' || $provided === null || !hash_equals($configured, $provided)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Token invalido.']);
    exit;
}

$ttlhours = (int)get_config('local_grupomakro_core', 'attendance_qr_dynamic_ip_ttl_hours');
if ($ttlhours < 1) {
    $ttlhours = 48;
}

$label = trim((string)($_GET['label'] ?? $_POST['label'] ?? ''));

// IP: ?ip= tiene prioridad, si no REMOTE_ADDR del request
$ip = trim((string)($_GET['ip'] ?? $_POST['ip'] ?? ''));
if ($ip === '') {
    $ip = (string)getremoteaddr(['WS_SERVER', 'HTTP_X_FORWARDED_FOR']);
}

if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'IP invalida o ausente.']);
    exit;
}

$now = time();
$expires = $now + ($ttlhours * 3600);

try {
    $transaction = $DB->start_delegated_transaction();
    $existing = $DB->get_records('gmk_institute_ip_registry', ['ip' => $ip]);
    foreach ($existing as $row) {
        $DB->delete_records('gmk_institute_ip_registry', ['id' => $row->id]);
    }
    // Si llega cookie de Moodle, registrar quien; si no, dejar null.
    $registeredby = null;
    if (isloggedin() && !empty($USER) && !empty($USER->id)) {
        $registeredby = (int)$USER->id;
    }
    $record = (object)[
        'ip' => $ip,
        'source' => 'auto_webhook',
        'registered_by_userid' => $registeredby,
        'registered_at' => $now,
        'expires_at' => $expires,
        'label' => $label,
    ];
    $id = $DB->insert_record('gmk_institute_ip_registry', $record);
    $transaction->allow_commit();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}

http_response_code(200);
echo json_encode([
    'status' => 'success',
    'id' => (int)$id,
    'ip' => $ip,
    'registered_at' => $now,
    'expires_at' => $expires,
    'ttl_hours' => $ttlhours,
    'message' => 'IP del instituto registrada hasta ' . userdate($expires),
]);
exit;