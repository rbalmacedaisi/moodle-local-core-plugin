<?php
/**
 * Debug: File Proxy Diagnostics
 * Verifies that file_proxy.php works and that Moodle file storage returns the correct file.
 *
 * Usage: /local/grupomakro_core/pages/debug_file_proxy.php
 *   With params: ?url=PLUGINFILE_URL   (test a specific file)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_file_proxy.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: File Proxy');
$PAGE->set_heading('Debug: File Proxy');
$PAGE->set_pagelayout('admin');

$testurl = optional_param('url', '', PARAM_RAW);

echo $OUTPUT->header();
?>
<style>
body { font-family: monospace; }
.ok  { color: #1b5e20; } .err { color: #b71c1c; } .warn { color: #e65100; } .info { color: #1565c0; }
.box { background:#f5f5f5; border-left:4px solid #1976d2; padding:12px 16px; margin:12px 0 4px; font-size:1rem; font-weight:bold; }
pre  { background:#263238; color:#eceff1; padding:12px; border-radius:4px; overflow-x:auto; font-size:0.82rem; }
table { border-collapse:collapse; font-size:0.85rem; }
td,th { border:1px solid #ddd; padding:4px 10px; }
th { background:#f5f5f5; }
</style>
<?php

function ok($m)   { echo '<p class="ok">&#x2705; '.htmlspecialchars($m).'</p>'; }
function err($m)  { echo '<p class="err">&#x274C; '.htmlspecialchars($m).'</p>'; }
function warn($m) { echo '<p class="warn">&#x26A0;&#xFE0F; '.htmlspecialchars($m).'</p>'; }
function inf($m)  { echo '<p class="info">&#x2139;&#xFE0F; '.htmlspecialchars($m).'</p>'; }
function box($m)  { echo '<div class="box">'.htmlspecialchars($m).'</div>'; }

// ── STEP 1: Verify file_proxy.php exists on disk ──────────────────────────
box('1. Archivo file_proxy.php en disco');
$proxyPath = __DIR__ . '/file_proxy.php';
if (file_exists($proxyPath)) {
    ok("file_proxy.php existe en: $proxyPath");
    $proxyUrl = $CFG->wwwroot . '/local/grupomakro_core/pages/file_proxy.php';
    inf("URL esperada: $proxyUrl");
} else {
    err("file_proxy.php NO existe en: $proxyPath");
    err("El archivo no se ha sincronizado al servidor.");
    echo '<p>Asegúrate de subir el archivo al servidor en la ruta indicada.</p>';
}

// ── STEP 2: Test HTTP accessibility of file_proxy.php ─────────────────────
box('2. Accesibilidad HTTP de file_proxy.php (sin parámetros → debe dar 400, no 404)');
$proxyTestUrl = $CFG->wwwroot . '/local/grupomakro_core/pages/file_proxy.php';
$ch = curl_init($proxyTestUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_NOBODY         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_COOKIE         => 'MoodleSession=' . session_id(),
]);
$resp    = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 404) {
    err("HTTP 404 — el archivo NO está accesible en el servidor. Confirma que fue subido/sincronizado.");
} elseif ($httpCode === 400 || $httpCode === 403) {
    ok("HTTP $httpCode — el archivo SÍ existe y responde (400/403 es esperado sin parámetros válidos).");
} elseif ($httpCode === 200) {
    ok("HTTP 200 — el archivo existe y responde.");
} else {
    warn("HTTP $httpCode — respuesta inesperada.");
}
inf("Código HTTP obtenido: $httpCode");

// ── STEP 3: Parse and look up file from URL ───────────────────────────────
if (!empty($testurl)) {
    $testurl = html_entity_decode($testurl, ENT_QUOTES, 'UTF-8');
    // Double-decode if needed (browser may send %2520 → %20 after first decode)
    if (strpos($testurl, '%25') !== false) {
        $testurl = rawurldecode($testurl);
    }

    box("3. Parseo de URL y búsqueda en file storage");
    inf("URL a analizar: $testurl");

    $parsed = parse_url($testurl);
    $path   = $parsed['path'] ?? '';
    inf("Path extraído: $path");

    if (!preg_match('|pluginfile\.php/(\d+)/([^/]+)/([^/]+)/(\d+)((?:/[^/]+)*?)/?([^/]+)$|', $path, $m)) {
        err("No se pudo parsear el path como pluginfile.php URL.");
        err("Path: $path");
    } else {
        $contextid = (int)$m[1];
        $component = $m[2];
        $filearea  = $m[3];
        $itemid    = (int)$m[4];
        $filepathRaw = $m[5];
        $filename  = rawurldecode($m[6]);
        $filepath  = ($filepathRaw === '' || $filepathRaw === '/') ? '/' : '/' . trim(rawurldecode($filepathRaw), '/') . '/';

        echo '<table><tr><th>Campo</th><th>Valor</th></tr>';
        echo "<tr><td>contextid</td><td>$contextid</td></tr>";
        echo "<tr><td>component</td><td>$component</td></tr>";
        echo "<tr><td>filearea</td><td>$filearea</td></tr>";
        echo "<tr><td>itemid</td><td>$itemid</td></tr>";
        echo "<tr><td>filepath</td><td>" . htmlspecialchars($filepath) . "</td></tr>";
        echo "<tr><td>filename</td><td>" . htmlspecialchars($filename) . "</td></tr>";
        echo '</table>';

        // Capability check
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            err("Context $contextid no existe.");
        } else {
            ok("Context encontrado: $contextid (level={$context->contextlevel})");
            $canGrade = has_capability('mod/assign:grade', $context);
            $canView  = has_capability('mod/assign:view', $context);
            $canGrade ? ok("Tienes mod/assign:grade en este contexto.") : warn("NO tienes mod/assign:grade en este contexto.");
            $canView  ? ok("Tienes mod/assign:view en este contexto.")  : warn("NO tienes mod/assign:view en este contexto.");
        }

        // File storage lookup
        $fs   = get_file_storage();
        $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

        if (!$file || $file->is_directory()) {
            err("Archivo NO encontrado en el file storage con los parámetros dados.");

            // Try alternative filepath variations
            inf("Intentando variaciones de filepath...");
            foreach (['/', '// '] as $alt) {
                $altFile = $fs->get_file($contextid, $component, $filearea, $itemid, $alt, $filename);
                if ($altFile && !$altFile->is_directory()) {
                    ok("Encontrado con filepath='$alt'");
                    break;
                }
            }

            // List ALL files in this context/component/filearea/itemid
            inf("Archivos disponibles en contextid=$contextid / $component / $filearea / itemid=$itemid:");
            $allfiles = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'filename', false);
            if (empty($allfiles)) {
                warn("No hay archivos en este area. Intentando sin itemid...");
                $allfiles = $fs->get_area_files($contextid, $component, $filearea, false, 'itemid,filename', false);
            }
            if (empty($allfiles)) {
                err("No se encontró ningún archivo en este contexto/componente/filearea.");
            } else {
                echo '<table><tr><th>filename</th><th>filepath</th><th>itemid</th><th>mimetype</th><th>filesize</th></tr>';
                foreach ($allfiles as $f) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($f->get_filename()) . '</td>';
                    echo '<td>' . htmlspecialchars($f->get_filepath()) . '</td>';
                    echo '<td>' . $f->get_itemid() . '</td>';
                    echo '<td>' . htmlspecialchars($f->get_mimetype()) . '</td>';
                    echo '<td>' . number_format($f->get_filesize()) . ' bytes</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        } else {
            ok("Archivo encontrado en file storage:");
            echo '<table>';
            echo '<tr><td>filename</td><td>' . htmlspecialchars($file->get_filename()) . '</td></tr>';
            echo '<tr><td>filepath</td><td>' . htmlspecialchars($file->get_filepath()) . '</td></tr>';
            echo '<tr><td>mimetype</td><td>' . htmlspecialchars($file->get_mimetype()) . '</td></tr>';
            echo '<tr><td>filesize</td><td>' . number_format($file->get_filesize()) . ' bytes</td></tr>';
            echo '<tr><td>timecreated</td><td>' . date('Y-m-d H:i:s', $file->get_timecreated()) . '</td></tr>';
            echo '</table>';

            // Build proxy URL
            $proxy = $CFG->wwwroot . '/local/grupomakro_core/pages/file_proxy.php?url=' . urlencode($testurl);
            ok("URL del proxy que se usaría:");
            echo '<pre>' . htmlspecialchars($proxy) . '</pre>';
            echo '<p><a href="' . htmlspecialchars($proxy) . '" target="_blank">Abrir vía proxy (nueva pestaña)</a></p>';
        }
    }
} else {
    box('3. Prueba con URL de archivo');
    echo '<form method="get">';
    echo '<p>Pega la URL de un pluginfile.php para diagnosticar:</p>';
    echo '<input type="text" name="url" style="width:600px;padding:6px;border:1px solid #ccc;border-radius:4px;" placeholder="https://lms.isi.edu.pa/pluginfile.php/..."><br><br>';
    echo '<button type="submit" style="padding:6px 16px;background:#1976d2;color:white;border:none;border-radius:4px;cursor:pointer">Diagnosticar</button>';
    echo '</form>';
}

// ── STEP 4: X-Frame-Options issue ────────────────────────────────────────
box('4. Configuración X-Frame-Options (para PDFs en iframe)');
inf("El error 'ALLOW-FROM' en la consola viene de la configuración global de Moodle.");
inf("En config.php busca: \$CFG->additionalhtmlhead, header(), o X-Frame-Options en .htaccess");
$htaccess = $CFG->dirroot . '/.htaccess';
if (file_exists($htaccess)) {
    $content = file_get_contents($htaccess);
    if (stripos($content, 'X-Frame-Options') !== false) {
        warn("X-Frame-Options encontrado en .htaccess:");
        preg_match_all('/.*X-Frame-Options.*/i', $content, $matches);
        foreach ($matches[0] as $line) {
            echo '<pre>' . htmlspecialchars(trim($line)) . '</pre>';
        }
        inf("Fix: cambiar 'ALLOW-FROM' por 'SAMEORIGIN' en .htaccess para que el iframe funcione.");
    } else {
        ok("X-Frame-Options no está en .htaccess");
    }
} else {
    inf(".htaccess no existe o no es accesible desde PHP.");
}

echo $OUTPUT->footer();
