<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_admin();

$PAGE->set_url('/local/grupomakro_core/pages/debug_file_viewer.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug File Viewer');

$file    = optional_param('file', '', PARAM_PATH);
$offset  = optional_param('offset', 1, PARAM_INT);
$limit   = optional_param('limit', 100, PARAM_INT);

echo $OUTPUT->header();

echo '<style>
pre { font-size:11px; background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:6px; overflow:auto; max-height:70vh; }
.ln { color:#666; user-select:none; margin-right:8px; }
form { margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
input[type=text] { width:500px; }
input { padding:4px 8px; border:1px solid #ccc; border-radius:4px; }
button { padding:4px 12px; background:#0073aa; color:#fff; border:none; border-radius:4px; cursor:pointer; }
.info { font-size:12px; color:#666; margin-bottom:8px; }
</style>';

echo '<h2>Debug File Viewer</h2>';

// Predefined shortcuts for common files
$shortcuts = [
    'locallib.php'       => $CFG->dirroot . '/local/grupomakro_core/locallib.php',
    'scheduler.php'      => $CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php',
    'ajax.php'           => $CFG->dirroot . '/local/grupomakro_core/ajax.php',
    'message/manager.php'=> $CFG->dirroot . '/lib/classes/message/manager.php',
    'modlib.php'         => $CFG->dirroot . '/course/modlib.php',
];

echo '<form method="get">';
echo 'Archivo: <input type="text" name="file" value="' . htmlspecialchars($file) . '" placeholder="/local/grupomakro_core/locallib.php">';
echo ' Desde línea: <input type="number" name="offset" value="' . $offset . '" style="width:70px">';
echo ' Líneas: <input type="number" name="limit" value="' . $limit . '" style="width:70px">';
echo ' <button type="submit">Ver</button>';
echo '</form>';

echo '<div style="margin-bottom:8px">Atajos: ';
foreach ($shortcuts as $label => $path) {
    $rel = str_replace($CFG->dirroot, '', $path);
    echo '<a href="?file=' . urlencode($rel) . '&offset=' . $offset . '&limit=' . $limit . '" style="margin-right:10px">' . htmlspecialchars($label) . '</a>';
}
echo '</div>';

if ($file) {
    // Security: only allow files inside dirroot
    $fullpath = realpath($CFG->dirroot . '/' . ltrim($file, '/'));
    if (!$fullpath || strpos($fullpath, realpath($CFG->dirroot)) !== 0) {
        echo '<p style="color:red">Ruta no permitida.</p>';
        echo $OUTPUT->footer();
        exit;
    }

    if (!file_exists($fullpath)) {
        echo '<p style="color:red">Archivo no encontrado: ' . htmlspecialchars($fullpath) . '</p>';
        echo $OUTPUT->footer();
        exit;
    }

    $lines = file($fullpath);
    $total = count($lines);
    $from  = max(1, $offset);
    $to    = min($total, $from + $limit - 1);

    echo '<div class="info">Mostrando líneas ' . $from . '–' . $to . ' de ' . $total . ' | ' . htmlspecialchars($fullpath) . '</div>';

    // Navigation
    $prevOffset = max(1, $from - $limit);
    $nextOffset = min($total, $from + $limit);
    echo '<div style="margin-bottom:6px;font-size:12px">';
    if ($from > 1) echo '<a href="?file=' . urlencode($file) . '&offset=' . $prevOffset . '&limit=' . $limit . '">← Anterior</a> &nbsp;';
    if ($to < $total) echo '<a href="?file=' . urlencode($file) . '&offset=' . $nextOffset . '&limit=' . $limit . '">Siguiente →</a>';
    echo '</div>';

    echo '<pre>';
    for ($i = $from - 1; $i < $to; $i++) {
        $lineNum = $i + 1;
        $lineContent = htmlspecialchars($lines[$i]);
        echo '<span class="ln">' . str_pad($lineNum, 5, ' ', STR_PAD_LEFT) . '</span>' . $lineContent;
    }
    echo '</pre>';
}

echo $OUTPUT->footer();
