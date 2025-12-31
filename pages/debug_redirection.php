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
echo "<p>¿Archivo <b>lib.php</b> cargado en esta sesión?: " . ($hook_exists_php ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>") . "</p>";
echo "<p>¿Hook registrado en la caché de Moodle?: " . ($hook_in_registry ? "<span style='color: green;'>SÍ</span>" : "<span style='color: red;'>NO</span>") . "</p>";

if (!$hook_in_registry) {
    echo "<p style='color: red;'><b>IMPORTANTE:</b> Moodle no ha registrado tu nueva función. Por favor, asegúrate de haber aceptado la actualización de la base de datos (Upgrade) al entrar a la administración del sitio.</p>";
}

echo "<p><a href='debug_redirection.php?purge=1'>Purgar Caches de Moodle</a></p>";

if (optional_param('purge', 0, PARAM_INT)) {
    purge_all_caches();
    redirect(new moodle_url('debug_redirection.php'));
}

echo "<p><a href='teacher_dashboard.php'>Ir al Dashboard manualmente</a></p>";
?>
