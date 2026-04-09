<?php
/**
 * File Proxy — serves Moodle stored files with Content-Disposition: inline
 * so PDFs display in the browser and DOCX can be fetched client-side by mammoth.js.
 *
 * Usage: /local/grupomakro_core/pages/file_proxy.php?contextid=X&component=Y&filearea=Z&itemid=N&filepath=/&filename=file.pdf
 *
 * Security: requires login + mod/assign:grade or mod/assign:view capability on the context.
 */

define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../../config.php');
require_login();

$contextid = required_param('contextid', PARAM_INT);
$component = required_param('component', PARAM_ALPHANUMEXT);
$filearea  = required_param('filearea',  PARAM_ALPHANUMEXT);
$itemid    = required_param('itemid',    PARAM_INT);
$filepath  = required_param('filepath',  PARAM_PATH);
$filename  = required_param('filename',  PARAM_FILE);

// Validate context exists
$context = context::instance_by_id($contextid, IGNORE_MISSING);
if (!$context) {
    http_response_code(404);
    die('Context not found.');
}

// Only allow submission-related file areas to limit exposure
$allowed_components = ['mod_assign', 'assignsubmission_file', 'assignsubmission_onlinetext'];
if (!in_array($component, $allowed_components, true)) {
    http_response_code(403);
    die('Component not allowed.');
}

// Teacher must have grading or viewing capability on this context
if (!has_capability('mod/assign:grade', $context) && !has_capability('mod/assign:view', $context)) {
    http_response_code(403);
    die('Access denied.');
}

// Retrieve the file from Moodle file storage
$fs   = get_file_storage();
$file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

if (!$file || $file->is_directory()) {
    http_response_code(404);
    die('File not found.');
}

$mimetype = $file->get_mimetype();
$filesize = $file->get_filesize();

// Serve inline — key difference from pluginfile.php default (which uses 'attachment')
header('Content-Type: ' . $mimetype);
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

// Stream the file content
$file->readfile();
exit;
