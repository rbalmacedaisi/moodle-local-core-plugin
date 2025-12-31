<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();

$userid = $USER->id;
$username = $USER->username;

echo "<h1>Diagnóstico de Redirección (Grupo Makro)</h1>";
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
    
    $url = new moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php');
    echo "<p>La redirección debería enviarte a: <a href='$url'>$url</a></p>";
} else {
    echo "<h2>Estado: <span style='color: red;'>NO ERES DOCENTE</span></h2>";
    echo "<p>No se encontraron registros en la tabla <b>gmk_class</b> donde <b>instructorid = $userid</b> y <b>closed = 0</b>.</p>";
    
    // Check if the table exists
    try {
        $count = $DB->count_records('gmk_class');
        echo "<p>La tabla gmk_class existe y tiene $count registros en total.</p>";
        
        // Show some instructors for debug
        $some_instructors = $DB->get_records_sql("SELECT DISTINCT instructorid FROM {gmk_class} WHERE closed = 0 LIMIT 5");
        if ($some_instructors) {
            echo "<p>IDs de algunos docentes activos en el sistema:</p><ul>";
            foreach ($some_instructors as $inst) {
                $u = $DB->get_record('user', ['id' => $inst->instructorid], 'username');
                echo "<li>ID: {$inst->instructorid} (" . ($u ? $u->username : 'Desconocido') . ")</li>";
            }
            echo "</ul>";
        }
    } catch (Exception $e) {
        echo "<p style='color: orange;'>Error al consultar gmk_class: " . $e->getMessage() . "</p>";
    }
}

// Check for redirect hook existence in the current PHP session
$hook_exists_php = function_exists('local_grupomakro_core_user_home_redirect');

// Check if Moodle's registry knows about the hook
$plugins_with_hook = get_plugins_with_function('user_home_redirect');
$hook_in_registry = isset($plugins_with_hook['local_grupomakro_core']);

echo "<hr><h3>Estado del Registry de Moodle</h3>";

// Explicitly try to include lib.php to see if it's reachable
$lib_path = $CFG->dirroot . '/local/grupomakro_core/lib.php';
$lib_reachable = file_exists($lib_path);
echo "<p>¿Archivo <b>lib.php</b> existe en el servidor?: " . ($lib_reachable ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO (Ruta: $lib_path)</span>") . "</p>";

if ($lib_reachable) {
    require_once($lib_path);
}

// Check for redirect hook existence in the current PHP session
$hook_exists_php = function_exists('local_grupomakro_core_user_home_redirect');
echo "<p>¿Función de redirección cargada en PHP ahora?: " . ($hook_exists_php ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>") . "</p>";

// Check if Moodle's registry knows about the hook
if (function_exists('get_plugins_with_function')) {
    $plugins_with_hook = get_plugins_with_function('user_home_redirect');
    $hook_in_registry = isset($plugins_with_hook['local_grupomakro_core']);
    echo "<p>¿Hook registrado en la caché de Moodle?: " . ($hook_in_registry ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>") . "</p>";

    if ($plugins_with_hook) {
        echo "<p>Otros plugins con este hook:</p><ul>";
        foreach ($plugins_with_hook as $pname => $pinfo) {
            $p_display = is_array($pinfo) ? json_encode($pinfo) : $pinfo;
            echo "<li>$pname: $p_display</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p>La función 'get_plugins_with_function' no existe en esta versión de Moodle.</p>";
}

// Check event observers for user_loggedin
echo "<h3>Estado del Observador de Login</h3>";
if (class_exists('\\core\\event\\manager')) {
    try {
        // Some Moodle versions might require different ways to access observers
        $observers = [];
        if (method_exists('\\core\\event\\manager', 'get_observers_for_event')) {
            $observers = \core\event\manager::get_observers_for_event('core\event\user_loggedin');
        } else {
            echo "<p style='color: orange;'>El método 'get_observers_for_event' no existe en esta versión.</p>";
        }

        $our_observer_found = false;
        foreach ($observers as $observer) {
            $callback = is_array($observer->callback) ? implode('::', $observer->callback) : $observer->callback;
            if (strpos($callback, 'local_grupomakro_core_observer') !== false) {
                $our_observer_found = true;
                echo "<p style='color: green;'>Observador detectado: <b>$callback</b></p>";
            }
        }
        if (!$our_observer_found && !empty($observers)) {
            echo "<p style='color: red;'>Observador NO detectado para 'user_loggedin' en este plugin.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error al consultar observadores: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>La clase '\\core\\event\\manager' no existe.</p>";
}

echo "<hr><p><a href='debug_redirection.php?purge=1'>Purgar Caches de Moodle</a></p>";

if (optional_param('purge', 0, PARAM_INT)) {
    purge_all_caches();
    echo "<script>alert('Cachés purgadas. Recargando...'); window.location.href='debug_redirection.php';</script>";
    exit;
}

echo "<p><a href='teacher_dashboard.php'>Ir al Dashboard manualmente</a></p>";
?>
