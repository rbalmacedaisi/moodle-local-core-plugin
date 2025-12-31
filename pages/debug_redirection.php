<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();

$userid = $USER->id;
$username = $USER->username;

echo "<h1>Diagnóstico de Redirección (Grupo Makro)</h1>";
echo "<p>Moodle Release: <b>" . (isset($CFG->release) ? $CFG->release : 'Desconocido') . "</b> (Versión: " . (isset($CFG->version) ? $CFG->version : 'Desconocida') . ")</p>";
echo "<p>Usuario Actual: <b>$username</b> (ID: $userid)</p>";

global $DB;

// Check if user is an instructor in any active class
$classes = $DB->get_records('gmk_class', ['instructorid' => $userid, 'closed' => 0]);

if ($classes) {
    echo "<h2>Estado: <span style='color: green;'>DOCENTE DETECTADO</span></h2>";
    echo "<p>Tienes " . count($classes) . " clases activas:</p>";
    echo "<ul>";
    foreach ($classes as $class) {
        $course = $DB->get_record('course', ['id' => $class->courseid], 'fullname');
        echo "<li>ID Clase: {$class->id} - Nombre: {$class->name} (Curso: " . ($course ? $course->fullname : 'N/A') . ")</li>";
    }
    echo "</ul>";
} else {
    echo "<h2>Estado: <span style='color: red;'>NO ERES DOCENTE</span></h2>";
}

echo "<hr><h3>Estado del Registry de Moodle</h3>";

// Explicitly try to include lib.php
$lib_path = $CFG->dirroot . '/local/grupomakro_core/lib.php';
$lib_reachable = file_exists($lib_path);
echo "<p>¿Archivo <b>lib.php</b> existe en el servidor?: " . ($lib_reachable ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO (Ruta: $lib_path)</span>") . "</p>";

if ($lib_reachable) {
    require_once($lib_path);
}

// Check for redirect hooks existence in the current PHP session
foreach (['user_home_redirect', 'my_home_redirect'] as $hookname) {
    $funcname = "local_grupomakro_core_$hookname";
    $hook_exists_php = function_exists($funcname);
    echo "<p>¿Hook <b>$hookname</b> cargado en PHP?: " . ($hook_exists_php ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>") . "</p>";

    // Check if Moodle's registry knows about the hook
    if (function_exists('get_plugins_with_function')) {
        $plugins_with_hook = get_plugins_with_function($hookname);
        
        // Handle both flat and grouped results
        $hook_in_registry = false;
        if (isset($plugins_with_hook['local_grupomakro_core'])) {
            $hook_in_registry = true;
        } else if (isset($plugins_with_hook['local']['grupomakro_core'])) {
            $hook_in_registry = true;
        }

        echo "<p>¿Hook registrado en Moodle?: " . ($hook_in_registry ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>") . "</p>";
        
        if ($plugins_with_hook) {
            echo "<p>Plugins con <b>$hookname</b>:</p><pre style='background: #f4f4f4; padding: 10px;'>" . json_encode($plugins_with_hook, JSON_PRETTY_PRINT) . "</pre>";
        }
    }
}

// Check event observers
echo "<h3>Estado del Observador de Login</h3>";
$events_file = $CFG->dirroot . '/local/grupomakro_core/db/events.php';
$obs_in_events_file = false;
if (file_exists($events_file)) {
    $observers_content = file_get_contents($events_file);
    if (strpos($observers_content, 'user_loggedin') !== false) {
        $obs_in_events_file = true;
    }
}
echo "<p>¿Evento 'user_loggedin' definido en db/events.php?: " . ($obs_in_events_file ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>") . "</p>";

echo "<hr><h3>Logs de Redirección</h3>";
$log_file = $CFG->dirroot . '/local/grupomakro_core/redirection_log.txt';
if (file_exists($log_file)) {
    echo "<pre style='background: #333; color: #eee; padding: 10px; max-height: 200px; overflow-y: scroll;'>" . htmlspecialchars(file_get_contents($log_file)) . "</pre>";
    echo "<p><a href='debug_redirection.php?clearlogs=1'>Limpiar logs</a></p>";
} else {
    echo "<p>No hay logs registrados aún.</p>";
}

if (optional_param('clearlogs', 0, PARAM_INT)) {
    @unlink($log_file);
    redirect(new moodle_url('debug_redirection.php'));
}

echo "<hr><p><a href='debug_redirection.php?purge=1'>Purgar Caches de Moodle</a></p>";

if (optional_param('purge', 0, PARAM_INT)) {
    purge_all_caches();
    echo "<script>alert('Cachés purgadas. Recargando...'); window.location.href='debug_redirection.php';</script>";
    exit;
}

echo "<p><a href='teacher_dashboard.php'>Ir al Dashboard manualmente</a></p>";
?>
