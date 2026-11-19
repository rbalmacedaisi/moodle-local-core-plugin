<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Mesa de ayuda: sube UN adjunto del chat al area de borrador del usuario.
 *
 * POST multipart:
 *   file         el archivo
 *   draftitemid  0 la primera vez; luego el que devolvio la respuesta
 *   ticketid     ticket donde se va a enviar (0 = ticket nuevo)
 *   action       upload (por defecto) | delete (con filename) para quitar uno
 *   wstoken      portal del estudiante (token del WS); sin token = sesion de
 *                Moodle + sesskey (bandeja y Ayuda TI)
 *
 * Responde JSON {ok, draftitemid, filename, filesize, kind, mimetype} o
 * {ok:false, error}. El archivo queda en user/draft; send_message o
 * create_ticket con ese draftitemid lo pasan al mensaje (attachments::attach).
 * Valida tipo real, tamano y cantidad aqui y otra vez al enviar.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// CORS preflight of the portal (an upload with progress events is always preflighted).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}
$usetoken = !empty($_POST['wstoken']) || !empty($_GET['wstoken']);
if ($usetoken) {
    define('NO_MOODLE_COOKIES', true);
}
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_grupomakro_core\local\support\attachments;
use local_grupomakro_core\local\support\manager;

/**
 * @param array $data
 * @param int $status
 */
function local_grupomakro_core_support_upload_respond(array $data, int $status = 200): void {
    global $usetoken;
    if ($usetoken) {
        header('Access-Control-Allow-Origin: *');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($data);
    die;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('invalidrequest');
    }
    if ($usetoken) {
        require_once($CFG->dirroot . '/webservice/lib.php');
        $webservicelib = new webservice();
        $webservicelib->authenticate_user(optional_param('wstoken', '', PARAM_ALPHANUM)); // Sets $USER.
    } else {
        require_login(null, false, null, false, true);
        require_sesskey();
    }
    if (isguestuser() || empty($USER->id)) {
        throw new moodle_exception('noguest');
    }
    $userid = (int)$USER->id;
    $syscontext = context_system::instance();
    if (!has_capability('local/grupomakro_core:support_create', $syscontext) && !manager::profile($userid)->isstaff) {
        throw new required_capability_exception($syscontext, 'local/grupomakro_core:support_create', 'nopermissions', '');
    }
    $ticketid = optional_param('ticketid', 0, PARAM_INT);
    if ($ticketid > 0) {
        [$ticket] = manager::require_ticket($ticketid, $userid);
        if (!manager::is_open_status((string)$ticket->status)) {
            throw new moodle_exception('tkt_err_closed', 'local_grupomakro_core');
        }
    }
    $limits = attachments::limits();
    if ($limits['maxfiles'] <= 0 || !$limits['types']) {
        throw new moodle_exception('tkt_err_attachdisabled', 'local_grupomakro_core');
    }

    $fs = get_file_storage();
    $usercontext = context_user::instance($userid);
    $draftitemid = optional_param('draftitemid', 0, PARAM_INT);
    if ($draftitemid <= 0) {
        $draftitemid = file_get_unused_draft_itemid();
    }

    $action = optional_param('action', 'upload', PARAM_ALPHA);
    if ($action === 'delete') {
        $filename = required_param('filename', PARAM_FILE);
        $file = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);
        if ($file) {
            $file->delete();
        }
        local_grupomakro_core_support_upload_respond(['ok' => true, 'draftitemid' => $draftitemid]);
    }

    if (empty($_FILES['file']) || is_array($_FILES['file']['name'])) {
        throw new moodle_exception('tkt_err_attachempty', 'local_grupomakro_core');
    }
    $upload = $_FILES['file'];
    if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
        if (in_array((int)$upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new moodle_exception('tkt_err_attachserver', 'local_grupomakro_core', '',
                display_size($limits['serverbytes']));
        }
        throw new moodle_exception('tkt_err_attachupload', 'local_grupomakro_core', '', (int)$upload['error']);
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        throw new moodle_exception('tkt_err_attachupload', 'local_grupomakro_core', '', 'tmp');
    }
    $name = clean_param((string)$upload['name'], PARAM_FILE);
    if ($name === '' || attachments::extension($name) === '') {
        throw new moodle_exception('tkt_err_attachtype', 'local_grupomakro_core', '', implode(', ', $limits['types']));
    }
    $size = (int)filesize($upload['tmp_name']);
    $head = (string)file_get_contents($upload['tmp_name'], false, null, 0, 32);
    $info = attachments::validate($name, $size, $head, $limits);

    $existing = attachments::draft_files($userid, $draftitemid);
    if (count($existing) >= $limits['maxfiles']) {
        throw new moodle_exception('tkt_err_attachcount', 'local_grupomakro_core', '', $limits['maxfiles']);
    }
    $taken = [];
    foreach ($existing as $f) {
        $taken[$f->get_filename()] = true;
    }
    $base = pathinfo($name, PATHINFO_FILENAME);
    $n = 1;
    while (isset($taken[$name])) {
        $name = $base . '-' . (++$n) . '.' . $info['ext'];
    }
    $fs->create_file_from_pathname([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => $name,
        'userid' => $userid,
        'mimetype' => $info['mimetype'],
    ], $upload['tmp_name']);

    local_grupomakro_core_support_upload_respond([
        'ok' => true,
        'draftitemid' => $draftitemid,
        'filename' => $name,
        'filesize' => $size,
        'kind' => $info['kind'],
        'mimetype' => $info['mimetype'],
    ]);
} catch (Throwable $e) {
    $message = $e instanceof moodle_exception ? $e->getMessage() : 'No se pudo subir el archivo.';
    local_grupomakro_core_support_upload_respond(['ok' => false, 'error' => $message], 400);
}
