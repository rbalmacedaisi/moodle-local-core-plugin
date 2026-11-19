<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): archivos de la bandeja del personal.
 *  - POST (multipart): sube la carta hecha por el personal (PDF o Word) y cierra
 *    la solicitud segun `next` (available | pickup | delivered). Responde JSON.
 *  - GET ?requestid=&docid=: descarga un documento de la solicitud.
 * Siempre: sesion + sesskey + managerequests.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_grupomakro_core\local\letters\desk;
use local_grupomakro_core\local\letters\manager;

$isupload = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isupload) {
    define('AJAX_SCRIPT', true);
}
require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();
$context = context_system::instance();
require_capability('local/grupomakro_core:managerequests', $context);
$requestid = required_param('requestid', PARAM_INT);

if (!$isupload) {
    $docid = optional_param('docid', 0, PARAM_INT);
    $doc = $docid > 0
        ? $DB->get_record('gmk_letter_document', ['id' => $docid, 'requestid' => $requestid], '*', MUST_EXIST)
        : manager::get_latest_document($requestid);
    if (!$doc) {
        throw new moodle_exception('letter_document_not_found', 'local_grupomakro_core');
    }
    $file = get_file_storage()->get_file($context->id, 'local_grupomakro_core', 'letter_document',
        (int)$doc->fileitemid, '/', (string)$doc->filename);
    if (!$file) {
        throw new moodle_exception('letter_document_not_found', 'local_grupomakro_core');
    }
    send_stored_file($file, 0, 0, optional_param('inline', 0, PARAM_BOOL) ? false : true);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
try {
    $next = optional_param('next', 'available', PARAM_ALPHA);
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
        $code = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
        throw new moodle_exception($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'letter_err_filesize' : 'letter_err_filetype', 'local_grupomakro_core', '',
            manager::UPLOAD_MAX_BYTES / 1048576);
    }
    $name = clean_filename((string)$_FILES['file']['name']);
    $content = (string)file_get_contents($_FILES['file']['tmp_name']);
    manager::upload_document($requestid, (int)$USER->id, $name, $content, $next);
    echo json_encode(['success' => true, 'request' => desk::row($requestid, (int)$USER->id)]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
