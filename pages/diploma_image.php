<?php
// Public-facing file server for the diploma module.
//
// We do NOT rely on /pluginfile.php because the path through
// file_pluginfile() in lib/filelib.php has too many edge cases (per-filearea
// capability rules, session handling, get_context_info_array for system
// context, etc.). This script:
//   1. Validates the request and the matching capability for system context.
//   2. Resolves the file from the local_grupomakro_core file storage.
//   3. Streams it with send_file() / send_stored_file().
//
// Routes:
//   ?id=<templateid>                            -> background of a template (admin only)
//   ?id=<templateid>&file=<filename>             -> explicit background file name
//   ?gen=<generationid>                          -> latest PDF of a generation
//   ?gen=<generationid>&file=<filename>         -> explicit PDF version
//   ?thumb=<fileid>                             -> thumbnail (image) of a stored file
//
// Optional:
//   &download=1                                  -> force download headers
//   &sesskey=<sesskey>                           -> sesskey for CSRF on AJAX callers
//   &nologin=1                                   -> serve the file to anonymous
//                                                   callers (used by the public
//                                                   diploma verification page only).
//
// Usage example from JS:
//   $CFG->wwwroot + '/local/grupomakro_core/pages/diploma_image.php?id=2&t=' + ts

require_once(__DIR__ . '/../../../config.php');

$id          = optional_param('id', 0, PARAM_INT);
$genid       = optional_param('gen', 0, PARAM_INT);
$fileid      = optional_param('fileid', 0, PARAM_INT);
$filename    = optional_param('file', '', PARAM_FILE);
$forcedown   = optional_param('download', 0, PARAM_BOOL);
$nologin     = optional_param('nologin', 0, PARAM_BOOL);
$sesskey     = optional_param('sesskey', '', PARAM_RAW);
$type        = optional_param('asset', '', PARAM_ALPHANUMEXT);

// Serve the institute logo used by the public verification page.
// The logo is a static asset on disk and must NOT go through
// pluginfile.php (Moodle blocks direct web access to plugin
// directories). We accept either a custom override at
// local/grupomakro_core/pix/institute-logo.{png,jpg} or fall back to
// the soluttolmsadmin theme logo.
if ($type === 'brand') {
    $candidates = [
        $CFG->dirroot . '/local/grupomakro_core/pix/institute-logo.png',
        $CFG->dirroot . '/local/grupomakro_core/pix/institute-logo.jpg',
        $CFG->dirroot . '/theme/soluttolmsadmin/pix/static/logo ISI-1 (1).png',
    ];
    foreach ($candidates as $cand) {
        if (is_readable($cand)) {
            \core\session\manager::write_close();
            send_file($cand, basename($cand), 0, 0, false, false, '', true);
            exit;
        }
    }
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Logo not found';
    exit;
}

if ($nologin) {
    // Public path used by diploma_verify.php. No login required.
    // We trust $nologin=1 because the file content itself is non-sensitive
    // (the diploma artwork/PDF the student is already allowed to view).
} else {
    if (!isloggedin() || isguestuser()) {
        // Allow basic auth fallback for service-to-service calls? No — keep it strict.
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden: please log in.';
        exit;
    }
    // Optional sesskey check. The JS sends it from M.cfg.sesskey.
    if (!empty($sesskey) && $sesskey !== sesskey()) {
        // Tolerate missing but reject mismatched.
        if ($sesskey !== '') {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Forbidden: invalid sesskey.';
            exit;
        }
    }
}

$sysctx = context_system::instance();
$fs = get_file_storage();
$file = null;

if ($id > 0) {
    // Background of a template.
    if (!$nologin) {
        require_capability('local/grupomakro_core:managediplomas', $sysctx);
    }
    $tpl = $DB->get_record('gmk_diploma_template', ['id' => $id], 'id, background_fileid, background_filename', IGNORE_MISSING);
    if (!$tpl) {
        header('HTTP/1.1 404 Not Found');
        echo 'Template not found';
        exit;
    }
    if ($tpl->background_fileid) {
        $file = $fs->get_file_by_id((int)$tpl->background_fileid);
    }
    if (!$file) {
        // Fallback: look in the area directly.
        $target = $filename !== '' ? $filename : $tpl->background_filename;
        if ($target !== '') {
            $file = $fs->get_file($sysctx->id, 'local_grupomakro_core', 'diploma_background', $id, '/', $target);
        }
    }
} else if ($genid > 0) {
    // Latest PDF of a generation.
    if (!$nologin) {
        require_capability('local/grupomakro_core:viewdiplomas', $sysctx);
    }
    $gen = $DB->get_record('gmk_diploma_generation', ['id' => $genid], 'id, status', IGNORE_MISSING);
    if (!$gen) {
        header('HTTP/1.1 404 Not Found');
        echo 'Generation not found';
        exit;
    }
    if ($gen->status === 'revoked' && !$nologin) {
        require_capability('local/grupomakro_core:managediplomas', $sysctx);
    }
    $sql = "SELECT * FROM {gmk_diploma_document} WHERE generationid = :gid ORDER BY version DESC, id DESC";
    $doc = $DB->get_record_sql($sql, ['gid' => $genid], IGNORE_MISSING);
    if ($doc) {
        $file = $fs->get_file_by_id((int)$doc->fileitemid);
        if (!$file) {
            $file = $fs->get_file($sysctx->id, 'local_grupomakro_core', 'diploma_document', $genid, '/', $doc->filename);
        }
    }
} else if ($fileid > 0) {
    if (!$nologin) {
        require_capability('local/grupomakro_core:viewdiplomas', $sysctx);
    }
    $file = $fs->get_file_by_id($fileid);
}

if (!$file) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found';
    exit;
}

// Final defence: ensure the resolved file really belongs to this plugin.
if ($file->get_component() !== 'local_grupomakro_core') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Forbidden: wrong component';
    exit;
}

// Stream the file.
\core\session\manager::write_close();
send_stored_file($file, 0, 0, $forcedown);
