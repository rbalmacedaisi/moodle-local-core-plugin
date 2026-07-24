<?php
// Dedicated endpoint to stream a TTF/OTF file from local/grupomakro_core/
// tcpdf_fonts/ so the browser can load it via @font-face and use the
// exact same face that the PDF renderer registers with TCPDF.
//
// Usage: pages/diploma_font.php?key=lato&style=Regular
//   key   = the normalised font key (matches the value stored in the
//           template's font_family column, e.g. lato, gotica).
//   style = optional, one of Regular | Bold | Italic | BoldItalic.
//           Defaults to the first non-variable file found for the key.

require_once(__DIR__ . '/../../../config.php');

$key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)optional_param('key', '', PARAM_ALPHANUMEXT)));
$style = (string)optional_param('style', '', PARAM_ALPHANUMEXT);

if ($key === '') {
    header('HTTP/1.1 400 Bad Request');
    echo 'Missing key';
    exit;
}

$dir = $CFG->dirroot . '/local/grupomakro_core/tcpdf_fonts';
if (!is_dir($dir)) {
    header('HTTP/1.1 404 Not Found');
    echo 'No fonts available';
    exit;
}

$prefix = $key . '__';
// Accept both .ttf (most of the catalog) and .otf (e.g. OPTIEngraversOldEnglish).
$candidates = glob($dir . '/' . $prefix . '*.ttf');
$otf = glob($dir . '/' . $prefix . '*.otf');
if (is_array($otf)) {
    $candidates = is_array($candidates) ? array_merge($candidates, $otf) : $otf;
}
if ($candidates === false || empty($candidates)) {
    // Fallback when glob() returns false on some servers.
    $candidates = [];
    $dh = @opendir($dir);
    if ($dh) {
        while (($f = readdir($dh)) !== false) {
            if (strpos($f, $prefix) === 0 && (substr($f, -4) === '.ttf' || substr($f, -4) === '.otf')) {
                $candidates[] = $dir . '/' . $f;
            }
        }
        closedir($dh);
    }
}

// Filter out variable fonts ([wght] / [wdth,wght]) which browsers can
// technically load but we want to be explicit about the face we pick.
$ttf = null;
foreach ($candidates as $cand) {
    if (strpos($cand, '[wght]') !== false || strpos($cand, '[wdth') !== false) {
        continue;
    }
    $ttf = $cand;
    break;
}
if ($ttf === null) {
    header('HTTP/1.1 404 Not Found');
    echo 'Font not found';
    exit;
}

// Serve the file with the right Content-Type so @font-face accepts it.
\core\session\manager::write_close();
$isotf = strtolower(substr($ttf, -4)) === '.otf';
header('Content-Type: ' . ($isotf ? 'font/otf' : 'font/ttf'));
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=31536000, immutable');
readfile($ttf);
exit;