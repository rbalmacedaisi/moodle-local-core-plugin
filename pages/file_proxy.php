<?php
/**
 * File Proxy — serves Moodle stored files with Content-Disposition: inline
 * so PDFs display in the browser and DOCX/XLSX can be fetched by JS libraries.
 *
 * Usage: /local/grupomakro_core/pages/file_proxy.php?url=ENCODED_PLUGINFILE_URL
 *
 * Security: requires login + mod/assign:grade or mod/assign:view on the file's context.
 */

define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../../config.php');
require_login();

$fileurl = required_param('url', PARAM_RAW);

// Decode URL if needed
$fileurl = html_entity_decode($fileurl, ENT_QUOTES, 'UTF-8');

// Parse the path from the URL
$parsed = parse_url($fileurl);
if (!$parsed || empty($parsed['path'])) {
    http_response_code(400);
    die('Invalid URL.');
}

$path = $parsed['path'];

// Match pluginfile.php pattern:
// /pluginfile.php/CONTEXTID/COMPONENT/FILEAREA/ITEMID/[FILEPATH/]FILENAME
// Capture everything after itemid as one group to avoid greedy-backtrack bugs.
if (!preg_match('|pluginfile\.php/(\d+)/([^/]+)/([^/]+)/(\d+)(/.+)$|', $path, $m)) {
    http_response_code(400);
    die('Could not parse file path.');
}

$contextid = (int)$m[1];
$component = clean_param($m[2], PARAM_ALPHANUMEXT);
$filearea  = clean_param($m[3], PARAM_ALPHANUMEXT);
$itemid    = (int)$m[4];

// Decode the full path-after-itemid, then split at the last slash.
// strrpos is used instead of regex to avoid backtracking that eats the filename.
$decodedPath = rawurldecode($m[5]);
$lastSlash   = strrpos($decodedPath, '/');
$filename    = substr($decodedPath, $lastSlash + 1);
$filepath    = ($lastSlash >= 0) ? substr($decodedPath, 0, $lastSlash + 1) : '/';

// Normalize filepath — must start and end with /
if (empty($filepath) || $filepath[0] !== '/') {
    $filepath = '/' . $filepath;
}
if (substr($filepath, -1) !== '/') {
    $filepath .= '/';
}

// Only allow submission-related components
$allowed_components = ['mod_assign', 'assignsubmission_file', 'assignsubmission_onlinetext'];
if (!in_array($component, $allowed_components, true)) {
    http_response_code(403);
    die('Component not allowed.');
}

// Verify context and capability
$context = context::instance_by_id($contextid, IGNORE_MISSING);
if (!$context) {
    http_response_code(404);
    die('Context not found.');
}

if (!has_capability('mod/assign:grade', $context) && !has_capability('mod/assign:view', $context)) {
    http_response_code(403);
    die('Access denied.');
}

// Fetch from Moodle file storage
$fs   = get_file_storage();
$file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

// Fallback: try NFC Unicode normalization.
// URLs sometimes use decomposed form (o + %CC%81) while DB stores precomposed (ó = %C3%B3).
if (!$file && function_exists('normalizer_normalize')) {
    $filenameNFC = normalizer_normalize($filename, Normalizer::FORM_C);
    if ($filenameNFC && $filenameNFC !== $filename) {
        $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filenameNFC);
    }
}

// Fallback: search all files in area and match by normalized filename.
if (!$file) {
    $areaFiles = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'filename', false);
    $needleNFC = function_exists('normalizer_normalize') ? normalizer_normalize($filename, Normalizer::FORM_C) : $filename;
    foreach ($areaFiles as $af) {
        if ($af->is_directory()) {
            continue;
        }
        $storedNFC = function_exists('normalizer_normalize') ? normalizer_normalize($af->get_filename(), Normalizer::FORM_C) : $af->get_filename();
        if ($storedNFC === $needleNFC || $af->get_filename() === $filename) {
            $file = $af;
            break;
        }
    }
}

if (!$file || $file->is_directory()) {
    // Debug info (only shown to admin)
    if (is_siteadmin()) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "File not found in storage.\n";
        echo "contextid=$contextid\n";
        echo "component=$component\n";
        echo "filearea=$filearea\n";
        echo "itemid=$itemid\n";
        echo "filepath=" . var_export($filepath, true) . "\n";
        echo "filename=" . var_export($filename, true) . "\n";
        die();
    }
    http_response_code(404);
    die('File not found.');
}

$mimetype = $file->get_mimetype();
$filesize = $file->get_filesize();

// Serve inline so browser displays PDF/DOCX instead of downloading
header('Content-Type: ' . $mimetype);
header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $filename) . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
// Allow iframe embedding from same origin
header('X-Frame-Options: SAMEORIGIN');

$file->readfile();
exit;
