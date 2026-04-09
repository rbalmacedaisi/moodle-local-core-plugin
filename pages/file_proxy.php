<?php
/**
 * File Proxy — serves Moodle stored files with Content-Disposition: inline.
 * PDFs display in browser iframes; DOCX can be converted to HTML server-side.
 *
 * Modes:
 *   ?url=ENCODED_PLUGINFILE_URL             → serve file inline (PDF, images, etc.)
 *   ?url=ENCODED_PLUGINFILE_URL&convert=html → convert DOCX to HTML (no CDN needed)
 *
 * Security: requires login + mod/assign:grade or mod/assign:view on the file's context.
 */

define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../../config.php');
require_login();

$fileurl     = required_param('url', PARAM_RAW);
$convertMode = optional_param('convert', '', PARAM_ALPHA);

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
    if (is_siteadmin()) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "File not found in storage.\n";
        echo "contextid=$contextid\ncomponent=$component\nfilearea=$filearea\n";
        echo "itemid=$itemid\nfilepath=" . var_export($filepath, true) . "\nfilename=" . var_export($filename, true) . "\n";
        die();
    }
    http_response_code(404);
    die('File not found.');
}

$mimetype = $file->get_mimetype();
$filename = $file->get_filename(); // use the actual stored name (may differ via fallback)

// ── Office → HTML conversion mode ────────────────────────────────────────────
if ($convertMode === 'html') {
    $isDocx = preg_match('/\.docx?m?$/i', $filename)
           || strpos($mimetype, 'word') !== false
           || strpos($mimetype, 'wordprocessing') !== false;

    $isXlsx = preg_match('/\.xlsx?m?$/i', $filename)
           || strpos($mimetype, 'spreadsheet') !== false
           || strpos($mimetype, 'excel') !== false;

    $isPptx = preg_match('/\.pptx?m?$/i', $filename)
           || strpos($mimetype, 'powerpoint') !== false
           || strpos($mimetype, 'presentation') !== false;

    if (!$isDocx && !$isXlsx && !$isPptx) {
        http_response_code(400);
        die('convert=html only supported for Office files (DOCX, XLSX, PPTX and macro variants).');
    }

    $content = $file->get_content();

    if ($isDocx) {
        $html = gmk_docx_to_html($content);
    } elseif ($isXlsx) {
        $html = gmk_xlsx_to_html($content);
    } else {
        $html = gmk_pptx_to_html($content);
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=3600');
    header('Content-Security-Policy: frame-ancestors \'self\'');
    echo $html !== false ? $html : '<p style="color:red">No se pudo procesar el documento.</p>';
    exit;
}

// ── Standard inline file serving ─────────────────────────────────────────────
header('Content-Type: ' . $mimetype);
header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $filename) . '"');
header('Content-Length: ' . $file->get_filesize());
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
// CSP frame-ancestors 'self' overrides conflicting X-Frame-Options set at the server level
// (e.g. nginx sending "ALLOW-FROM https://students.isi.edu.pa" alongside PHP's SAMEORIGIN).
header('Content-Security-Policy: frame-ancestors \'self\'');

$file->readfile();
exit;

// ── DOCX to HTML converter (pure PHP, no external libraries) ─────────────────

function gmk_docx_to_html($fileContent) {
    if (!class_exists('ZipArchive')) {
        return '<p style="color:red">ZipArchive no disponible en el servidor.</p>';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gmk_docx_');
    file_put_contents($tmp, $fileContent);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return false;
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($tmp);

    if ($xml === false) {
        return '<p style="color:red">No se encontró el contenido del documento (word/document.xml).</p>';
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    if (!$dom->loadXML($xml)) {
        return '<p style="color:red">El archivo XML del documento está corrupto.</p>';
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $body = $xpath->query('//w:body');
    if ($body->length === 0) return false;

    $html = '';
    foreach ($body->item(0)->childNodes as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) continue;
        if ($node->localName === 'p') {
            $html .= gmk_docx_paragraph($node, $xpath);
        } elseif ($node->localName === 'tbl') {
            $html .= gmk_docx_table($node, $xpath);
        }
    }

    return '<div class="gmk-docx-preview" style="font-family:sans-serif;font-size:14px;line-height:1.6;padding:16px;max-width:900px">'
         . $html
         . '</div>';
}

function gmk_docx_paragraph($para, $xpath) {
    // Detect paragraph style
    $styleNodes = $xpath->query('w:pPr/w:pStyle', $para);
    $style      = $styleNodes->length > 0 ? $styleNodes->item(0)->getAttribute('w:val') : '';

    // Detect list paragraph
    $numPr = $xpath->query('w:pPr/w:numPr', $para);
    $isList = $numPr->length > 0;

    // Build inline content from runs
    $content = '';
    foreach ($para->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        if ($child->localName === 'r') {
            $content .= gmk_docx_run($child, $xpath);
        } elseif ($child->localName === 'hyperlink') {
            // Extract text from hyperlink runs
            $runs = $xpath->query('.//w:r', $child);
            foreach ($runs as $r) {
                $content .= gmk_docx_run($r, $xpath);
            }
        }
    }

    if (trim(strip_tags($content)) === '') {
        return '<br>';
    }

    // Headings
    if (preg_match('/heading(\d)/i', $style, $hm) || preg_match('/t[ií]tulo.*?(\d)/i', $style, $hm)) {
        $lvl = min((int)$hm[1], 6);
        $sz  = [1 => '1.6em', 2 => '1.4em', 3 => '1.2em', 4 => '1.1em', 5 => '1em', 6 => '0.9em'];
        return "<h{$lvl} style=\"font-weight:bold;margin:12px 0 4px;font-size:{$sz[$lvl]}\">{$content}</h{$lvl}>\n";
    }
    if (preg_match('/^(title|titulo)$/i', $style)) {
        return "<h1 style=\"font-size:1.8em;font-weight:bold;margin:8px 0\">{$content}</h1>\n";
    }
    if (preg_match('/^(subtitle|subtitulo)$/i', $style)) {
        return "<h2 style=\"font-size:1.3em;color:#555;margin:4px 0 8px\">{$content}</h2>\n";
    }

    if ($isList) {
        return "<li style=\"margin:2px 0\">{$content}</li>\n";
    }

    return "<p style=\"margin:4px 0\">{$content}</p>\n";
}

function gmk_docx_run($run, $xpath) {
    // Collect text nodes (w:t preserves spaces via xml:space="preserve")
    $texts = $xpath->query('w:t', $run);
    $text  = '';
    foreach ($texts as $t) {
        $text .= $t->textContent;
    }
    if ($text === '') return '';

    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Check formatting
    $isBold      = $xpath->query('w:rPr/w:b',  $run)->length > 0;
    $isItalic    = $xpath->query('w:rPr/w:i',  $run)->length > 0;
    $isUnderline = $xpath->query('w:rPr/w:u',  $run)->length > 0;
    $isStrike    = $xpath->query('w:rPr/w:strike', $run)->length > 0;

    if ($isStrike)    $text = "<s>{$text}</s>";
    if ($isUnderline) $text = "<u>{$text}</u>";
    if ($isItalic)    $text = "<em>{$text}</em>";
    if ($isBold)      $text = "<strong>{$text}</strong>";

    return $text;
}

function gmk_docx_table($tbl, $xpath) {
    $html = '<table style="border-collapse:collapse;width:100%;margin:10px 0;font-size:13px">';
    $rows = $xpath->query('w:tr', $tbl);
    foreach ($rows as $row) {
        $html .= '<tr>';
        $cells = $xpath->query('w:tc', $row);
        foreach ($cells as $cell) {
            $cellHtml = '';
            $paras = $xpath->query('w:p', $cell);
            foreach ($paras as $p) {
                $cellHtml .= gmk_docx_paragraph($p, $xpath);
            }
            $html .= '<td style="border:1px solid #ccc;padding:5px 8px;vertical-align:top">' . $cellHtml . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    return $html;
}

// ── PPTX to HTML converter ────────────────────────────────────────────────────

function gmk_pptx_to_html($fileContent) {
    if (!class_exists('ZipArchive')) {
        return '<p style="color:red">ZipArchive no disponible en el servidor.</p>';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gmk_pptx_');
    file_put_contents($tmp, $fileContent);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return false;
    }

    // Collect slide paths sorted by number
    $slides = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('|^ppt/slides/slide(\d+)\.xml$|', $name, $m)) {
            $slides[(int)$m[1]] = $name;
        }
    }
    ksort($slides);

    $html = '<div class="gmk-pptx-preview" style="font-family:sans-serif;font-size:14px;padding:8px">';

    foreach ($slides as $slideNum => $slidePath) {
        $xml = $zip->getFromName($slidePath);
        if ($xml === false) continue;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        if (!$dom->loadXML($xml)) continue;

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');

        $html .= '<div style="border:1px solid #ddd;border-radius:4px;margin:8px 0;padding:16px 20px;background:#fafafa">';
        $html .= '<div style="font-size:0.72em;color:#aaa;margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">Diapositiva ' . $slideNum . '</div>';

        // Each shape's text body → paragraphs
        $txBodies = $xpath->query('//p:sp/p:txBody');
        foreach ($txBodies as $txBody) {
            $paragraphs = $xpath->query('a:p', $txBody);
            foreach ($paragraphs as $para) {
                $runs = $xpath->query('a:r', $para);
                $text = '';
                foreach ($runs as $run) {
                    $tNodes = $xpath->query('a:t', $run);
                    foreach ($tNodes as $t) {
                        $text .= $t->textContent;
                    }
                }
                if (trim($text) === '') continue;

                // Detect if this looks like a title (first text body, first para)
                $isBold = $xpath->query('.//a:rPr[@b="1"]', $para)->length > 0
                       || $xpath->query('a:r/a:rPr[@b="1"]', $para)->length > 0;
                $tag    = $isBold ? 'strong' : 'span';
                $html  .= "<p style=\"margin:3px 0\"><{$tag}>" . htmlspecialchars($text) . "</{$tag}></p>";
            }
        }

        $html .= '</div>';
    }

    $zip->close();
    @unlink($tmp);

    $html .= '</div>';
    return $html;
}

// ── XLSX to HTML converter ────────────────────────────────────────────────────

function gmk_xlsx_to_html($fileContent) {
    if (!class_exists('ZipArchive')) {
        return '<p style="color:red">ZipArchive no disponible en el servidor.</p>';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gmk_xlsx_');
    file_put_contents($tmp, $fileContent);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return false;
    }

    // Load shared strings (string values are stored separately)
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        libxml_use_internal_errors(true);
        $ssDom = new DOMDocument();
        $ssDom->loadXML($ssXml);
        $ssXp = new DOMXPath($ssDom);
        $ssXp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($ssXp->query('//x:si') as $si) {
            $sharedStrings[] = $si->textContent;
        }
    }

    // Find the first sheet file
    $wsXml = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('|^xl/worksheets/sheet1\.xml$|', $name)) {
            $wsXml = $zip->getFromName($name);
            break;
        }
    }
    $zip->close();
    @unlink($tmp);

    if ($wsXml === false || $wsXml === null) {
        return '<p style="color:red">No se encontró la hoja de cálculo (sheet1.xml).</p>';
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadXML($wsXml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $rows = $xpath->query('//x:row');
    if ($rows->length === 0) {
        return '<p style="color:#888;padding:16px">La hoja está vacía.</p>';
    }

    // Build a 2D array respecting column references (A=0, B=1, AA=26, …)
    $data   = [];
    $maxCol = 0;
    $maxRow = 0;

    foreach ($rows as $row) {
        $rowIdx = (int)$row->getAttribute('r') - 1;
        if ($rowIdx > 200) break; // Hard limit to avoid huge tables

        $cells = $xpath->query('x:c', $row);
        foreach ($cells as $cell) {
            $ref = $cell->getAttribute('r'); // e.g. "B3"
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $cm);
            if (!$cm) continue;

            // Convert column letters to 0-based index
            $colStr = $cm[1];
            $colIdx = 0;
            for ($ci = 0; $ci < strlen($colStr); $ci++) {
                $colIdx = $colIdx * 26 + (ord($colStr[$ci]) - 64);
            }
            $colIdx--; // 0-based

            $type  = $cell->getAttribute('t');
            $vNode = $xpath->query('x:v', $cell);
            $value = $vNode->length > 0 ? $vNode->item(0)->textContent : '';

            if ($type === 's') {
                $value = isset($sharedStrings[(int)$value]) ? $sharedStrings[(int)$value] : '';
            } elseif ($type === 'b') {
                $value = $value ? 'TRUE' : 'FALSE';
            }

            $data[$rowIdx][$colIdx] = $value;
            if ($colIdx > $maxCol) $maxCol = $colIdx;
            if ($rowIdx > $maxRow) $maxRow = $rowIdx;
        }
    }

    $maxCol = min($maxCol, 50); // Cap at 50 columns

    $html  = '<div style="overflow-x:auto;font-size:12px">';
    $html .= '<table style="border-collapse:collapse;min-width:100%">';
    for ($r = 0; $r <= $maxRow; $r++) {
        $html .= '<tr>';
        for ($c = 0; $c <= $maxCol; $c++) {
            $val   = isset($data[$r][$c]) ? htmlspecialchars($data[$r][$c]) : '';
            $style = 'border:1px solid #ddd;padding:3px 8px;white-space:nowrap;';
            if ($r === 0) $style .= 'background:#f5f5f5;font-weight:bold;';
            $html .= "<td style=\"{$style}\">{$val}</td>";
        }
        $html .= '</tr>';
    }
    $html .= '</table></div>';

    return $html;
}
