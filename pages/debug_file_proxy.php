<?php
/**
 * Debug: File Proxy Diagnostics (auto-mode)
 * URL: /local/grupomakro_core/pages/debug_file_proxy.php
 */
require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_file_proxy.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: File Proxy');
$PAGE->set_pagelayout('admin');

$testAction = optional_param('action', '', PARAM_ALPHA);
$testContextid = optional_param('contextid', 0, PARAM_INT);
$testComponent = optional_param('component', '', PARAM_ALPHANUMEXT);
$testFilearea  = optional_param('filearea',  '', PARAM_ALPHANUMEXT);
$testItemid    = optional_param('itemid',    0, PARAM_INT);
$testFilepath  = optional_param('filepath',  '/', PARAM_PATH);
$testFilename  = optional_param('filename',  '', PARAM_FILE);

echo $OUTPUT->header();
?>
<style>
.ok   { color:#1b5e20; margin:2px 0; } .err  { color:#b71c1c; margin:2px 0; }
.warn { color:#e65100; margin:2px 0; } .info { color:#1565c0; margin:2px 0; }
.box  { background:#e3f2fd; border-left:4px solid #1976d2; padding:10px 16px; margin:16px 0 6px; font-weight:bold; font-size:1rem; }
pre   { background:#263238; color:#eceff1; padding:10px; border-radius:4px; overflow-x:auto; font-size:0.8rem; word-break:break-all; }
table { border-collapse:collapse; font-size:0.82rem; margin:6px 0; }
td,th { border:1px solid #ddd; padding:4px 10px; }
th    { background:#f5f5f5; font-weight:bold; }
.badge-pdf  { background:#f44336; color:#fff; border-radius:4px; padding:1px 6px; font-size:0.75rem; }
.badge-docx { background:#1976d2; color:#fff; border-radius:4px; padding:1px 6px; font-size:0.75rem; }
.badge-xlsx { background:#388e3c; color:#fff; border-radius:4px; padding:1px 6px; font-size:0.75rem; }
.badge-other{ background:#757575; color:#fff; border-radius:4px; padding:1px 6px; font-size:0.75rem; }
</style>
<?php

function ok($m)   { echo '<p class="ok">&#x2705; '  .htmlspecialchars($m).'</p>'; }
function err($m)  { echo '<p class="err">&#x274C; ' .htmlspecialchars($m).'</p>'; }
function warn($m) { echo '<p class="warn">&#x26A0;&#xFE0F; '.htmlspecialchars($m).'</p>'; }
function inf($m)  { echo '<p class="info">&#x2139;&#xFE0F; '.htmlspecialchars($m).'</p>'; }
function box($m)  { echo '<div class="box">'.htmlspecialchars($m).'</div>'; }

function fileBadge($mime, $name) {
    if (strpos($mime, 'pdf') !== false || preg_match('/\.pdf$/i', $name))
        return '<span class="badge-pdf">PDF</span>';
    if (strpos($mime, 'word') !== false || preg_match('/\.docx?$/i', $name))
        return '<span class="badge-docx">DOCX</span>';
    if (strpos($mime, 'spreadsheet') !== false || strpos($mime, 'excel') !== false || preg_match('/\.xlsx?$/i', $name))
        return '<span class="badge-xlsx">XLSX</span>';
    return '<span class="badge-other">'.htmlspecialchars(strtoupper(pathinfo($name, PATHINFO_EXTENSION))).'</span>';
}

global $CFG, $DB;
$fs = get_file_storage();

// ── STEP 1: file_proxy.php on disk ──────────────────────────────────────
box('1. ¿Existe file_proxy.php en el servidor?');
$proxyPath = __DIR__ . '/file_proxy.php';
if (file_exists($proxyPath)) {
    ok("file_proxy.php encontrado en disco: $proxyPath");
} else {
    err("file_proxy.php NO existe en: $proxyPath");
    err("Debes subir/sincronizar el archivo al servidor antes de continuar.");
    echo $OUTPUT->footer(); exit;
}

// ── STEP 2: HTTP reachability ────────────────────────────────────────────
box('2. ¿Responde el proxy por HTTP?');
$proxyBase = $CFG->wwwroot . '/local/grupomakro_core/pages/file_proxy.php';
$ch = curl_init($proxyBase . '?url=test');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_NOBODY         => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 6,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 404) {
    err("HTTP 404 — el servidor devuelve 404. Moodle no puede enrutar a este archivo.");
    err("Causa probable: Moodle tiene activado el router de URL y necesita que el plugin esté instalado correctamente, o hay un .htaccess bloqueando el acceso.");
    inf("Verifica en el servidor: ls -la " . $proxyPath);
} elseif (in_array($httpCode, [400, 403, 200])) {
    ok("HTTP $httpCode — el proxy responde correctamente ($httpCode es esperado para URL inválida).");
} else {
    warn("HTTP $httpCode — respuesta inesperada.");
}

// ── STEP 3: Find recent submission files automatically ───────────────────
box('3. Archivos de entregas recientes (últimas 20 entregas con archivos)');

$recentSubmissions = $DB->get_records_sql("
    SELECT asub.id, asub.assignment, asub.userid, asub.timemodified,
           a.name AS assignname, u.firstname, u.lastname, cm.id AS cmid, ctx.id AS contextid
      FROM {assign_submission} asub
      JOIN {assign} a ON a.id = asub.assignment
      JOIN {user} u ON u.id = asub.userid
      JOIN {course_modules} cm ON cm.instance = asub.assignment
         AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
      JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = 70
     WHERE asub.status = 'submitted'
       AND asub.latest = 1
  ORDER BY asub.timemodified DESC
     LIMIT 50
");

$foundFiles = [];
foreach ($recentSubmissions as $sub) {
    $files = $fs->get_area_files(
        $sub->contextid,
        'assignsubmission_file',
        'submission_files',
        $sub->id,
        'filename',
        false
    );
    foreach ($files as $f) {
        $foundFiles[] = [
            'file'       => $f,
            'sub'        => $sub,
            'contextid'  => $sub->contextid,
            'component'  => 'assignsubmission_file',
            'filearea'   => 'submission_files',
            'itemid'     => $sub->id,
        ];
        if (count($foundFiles) >= 20) break 2;
    }
}

if (empty($foundFiles)) {
    warn("No se encontraron archivos en entregas recientes.");
} else {
    echo '<table>';
    echo '<tr><th>#</th><th>Tipo</th><th>Archivo</th><th>Estudiante</th><th>Tarea</th><th>Tamaño</th><th>Acciones</th></tr>';
    foreach ($foundFiles as $i => $entry) {
        $f      = $entry['file'];
        $sub    = $entry['sub'];
        $badge  = fileBadge($f->get_mimetype(), $f->get_filename());
        $size   = number_format($f->get_filesize() / 1024, 1) . ' KB';
        $name   = htmlspecialchars($f->get_filename());
        $student= htmlspecialchars($sub->firstname . ' ' . $sub->lastname);
        $assign = htmlspecialchars($sub->assignname);

        // Build proxy URL from parameters
        $params = http_build_query([
            'action'    => 'test',
            'contextid' => $entry['contextid'],
            'component' => $entry['component'],
            'filearea'  => $entry['filearea'],
            'itemid'    => $entry['itemid'],
            'filepath'  => $f->get_filepath(),
            'filename'  => $f->get_filename(),
        ]);
        $testLink = new moodle_url('/local/grupomakro_core/pages/debug_file_proxy.php', [
            'action'    => 'test',
            'contextid' => $entry['contextid'],
            'component' => $entry['component'],
            'filearea'  => $entry['filearea'],
            'itemid'    => $entry['itemid'],
            'filepath'  => $f->get_filepath(),
            'filename'  => $f->get_filename(),
        ]);

        echo "<tr>";
        echo "<td>" . ($i+1) . "</td>";
        echo "<td>$badge</td>";
        echo "<td>$name</td>";
        echo "<td>$student</td>";
        echo "<td>$assign</td>";
        echo "<td>$size</td>";
        echo '<td><a href="' . $testLink->out(false) . '">Probar proxy</a></td>';
        echo "</tr>";
    }
    echo '</table>';
}

// ── STEP 4: Test a specific file ─────────────────────────────────────────
if ($testAction === 'test' && $testContextid && $testFilename) {
    box("4. Resultado del proxy para: " . htmlspecialchars($testFilename));

    $context = context::instance_by_id($testContextid, IGNORE_MISSING);
    if (!$context) {
        err("Context $testContextid no existe.");
    } else {
        ok("Context encontrado (level={$context->contextlevel})");

        $file = $fs->get_file($testContextid, $testComponent, $testFilearea, $testItemid, $testFilepath, $testFilename);
        if (!$file || $file->is_directory()) {
            err("Archivo NO encontrado en file storage con:");
            echo '<pre>contextid=' . $testContextid . "\ncomponent=" . $testComponent . "\nfilearea=" . $testFilearea . "\nitemid=" . $testItemid . "\nfilepath=" . $testFilepath . "\nfilename=" . $testFilename . '</pre>';

            // Try listing what IS in that area
            inf("Archivos disponibles en esa área:");
            $area = $fs->get_area_files($testContextid, $testComponent, $testFilearea, $testItemid, 'filename', false);
            if ($area) {
                foreach ($area as $af) {
                    echo '<pre>filename=' . htmlspecialchars($af->get_filename()) . ' filepath=' . htmlspecialchars($af->get_filepath()) . '</pre>';
                }
            } else {
                err("No hay archivos en esa área.");
            }
        } else {
            ok("Archivo encontrado: " . $file->get_filename() . " (" . number_format($file->get_filesize()/1024, 1) . " KB, " . $file->get_mimetype() . ")");

            // Build the actual proxy URL
            $pluginfileUrl = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            );
            $proxyTestUrl = $proxyBase . '?url=' . urlencode($pluginfileUrl->out(false));

            ok("URL del proxy construida:");
            echo '<pre>' . htmlspecialchars($proxyTestUrl) . '</pre>';

            // Test proxy via curl
            inf("Probando proxy via curl...");
            $ch2 = curl_init($proxyTestUrl);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_COOKIE         => 'MoodleSession=' . session_id(),
            ]);
            $r2   = curl_exec($ch2);
            $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $ct    = curl_getinfo($ch2, CURLINFO_CONTENT_TYPE);
            curl_close($ch2);

            if ($code2 === 200) {
                ok("HTTP 200 — proxy funciona correctamente.");
                ok("Content-Type devuelto: $ct");
                if (strpos($ct, 'inline') !== false || strpos($ct, 'pdf') !== false || strpos($ct, 'word') !== false) {
                    ok("Content-Disposition parece inline — el archivo se mostrará en el visor.");
                }
            } elseif ($code2 === 404) {
                err("HTTP 404 — el proxy no puede ser alcanzado por el servidor (curl interno).");
                inf("Headers recibidos:");
                echo '<pre>' . htmlspecialchars(substr($r2, 0, 500)) . '</pre>';
            } else {
                warn("HTTP $code2");
                echo '<pre>' . htmlspecialchars(substr($r2, 0, 500)) . '</pre>';
            }

            // Show direct links for manual test
            echo '<br>';
            echo '<p><strong>Pruebas manuales (abrir en nueva pestaña):</strong></p>';
            echo '<ul>';
            echo '<li><a href="' . htmlspecialchars($proxyTestUrl) . '" target="_blank">Abrir vía proxy</a> (debe mostrar inline)</li>';
            echo '<li><a href="' . htmlspecialchars($pluginfileUrl->out(false)) . '" target="_blank">Abrir pluginfile.php original</a> (puede descargar)</li>';
            echo '</ul>';
        }
    }
} elseif ($testAction !== 'test') {
    box('4. Cómo usar este debug');
    inf('Haz clic en "Probar proxy" en cualquier archivo de la tabla de arriba para diagnóstico completo.');
}

echo $OUTPUT->footer();
