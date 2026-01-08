<?php
/**
 * Debug Teacher Redirect
 * Helpful tool to identify why a user is not being redirected to the Teacher Dashboard.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

// Only Admins should see this list to avoid data leakage
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_teacher_redirect.php'));
$PAGE->set_context($context);
$PAGE->set_title('Teacher Redirect Diagnostics');
$PAGE->set_heading('Diagnóstico de Redirección Docente');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo $OUTPUT->heading('Diagnóstico de Redirección Docente');

global $DB;

$userid = $USER->id;
echo "<h3>Simulación para Usuario Actual: " . fullname($USER) . " (ID: $userid)</h3>";

// 1. SIMULATE LOGIC
$has_active = $DB->record_exists('gmk_class', ['instructorid' => $userid, 'closed' => 0]);
$has_past = $DB->record_exists('gmk_class', ['instructorid' => $userid]);
$has_skills = $DB->record_exists('gmk_teacher_skill_relation', ['userid' => $userid]);
$has_disp = $DB->record_exists('gmk_teacher_disponibility', ['userid' => $userid]);

echo "<ul>";
echo "<li>Tiene Clases Activas: " . ($has_active ? "<strong>SÍ</strong> (Debería ir a Teacher Dashboard)" : "NO") . "</li>";
echo "<li>Tiene Clases Pasadas: " . ($has_past ? "SÍ" : "NO") . "</li>";
echo "<li>Tiene Skills: " . ($has_skills ? "SÍ" : "NO") . "</li>";
echo "<li>Tiene Disponibilidad: " . ($has_disp ? "SÍ" : "NO") . "</li>";
echo "</ul>";

if (!$has_active && ($has_past || $has_skills || $has_disp)) {
    echo "<div class='alert alert-success'><strong>RESULTADO ESPERADO:</strong> Redirección a Inactive Dashboard</div>";
} elseif ($has_active) {
    echo "<div class='alert alert-success'><strong>RESULTADO ESPERADO:</strong> Redirección a Teacher Dashboard</div>";
} else {
    echo "<div class='alert alert-warning'><strong>RESULTADO ESPERADO:</strong> Sin redirección (Comportamiento estándar)</div>";
}

echo "<hr>";
echo "<h3>Verificación del Evento 'user_loggedin'</h3>";

// 2. CHECK EVENT REGISTRATION
$handlers = $DB->get_records('events_handlers', ['eventname' => 'core\event\user_loggedin']);
if ($handlers) {
    echo "<p>Handlers registrados en base de datos para <code>core\event\user_loggedin</code>:</p>";
    echo "<ul>";
    foreach ($handlers as $h) {
        $style = ($h->component === 'local_grupomakro_core') ? "style='font-weight:bold; color:green;'" : "";
        echo "<li $style>{$h->component} -> {$h->handlerfile} :: {$h->handlerfunction}</li>";
    }
    echo "</ul>";
} else {
    echo "<p>Nota: Moodle moderno (Events 2) no siempre usa la tabla <code>events_handlers</code>. Verificando definición de archivo.</p>";
}

// Check if file exists and method exists
if (file_exists($CFG->dirroot . '/local/grupomakro_core/classes/observer.php')) {
    require_once($CFG->dirroot . '/local/grupomakro_core/classes/observer.php');
    if (class_exists('local_grupomakro_core_observer') && method_exists('local_grupomakro_core_observer', 'user_loggedin')) {
        echo "<p style='color:green'>✅ La clase y método `local_grupomakro_core_observer::user_loggedin` están CORRECTAMENTE definidos en disco.</p>";
    } else {
        echo "<p style='color:red'>❌ ERROR: No se encuentra la clase o método en observer.php</p>";
    }
} else {
    echo "<p style='color:red'>❌ ERROR: No existe el archivo classes/observer.php</p>";
}


// Check Logs again
$log_file = $CFG->dirroot . '/local/grupomakro_core/redirect_debug.log';
if (file_exists($log_file)) {
    echo "<h3>Contenido del Log de Depuración:</h3>";
    echo "<pre style='background:#eee; padding:10px; border:1px solid #ccc; max-height:300px; overflow:auto;'>" . htmlspecialchars(file_get_contents($log_file)) . "</pre>";
    echo "<p><a href='?purge=1' class='btn btn-secondary'>Purgar Log</a></p>";
} else {
    echo "<p><em>El archivo de log no se ha generado aún. Esto indica que el evento NO se está disparando.</em></p>";
}

if (optional_param('purge', 0, PARAM_INT)) {
    @unlink($log_file);
    redirect(new moodle_url('/local/grupomakro_core/pages/debug_teacher_redirect.php'));
}

echo '<div class="alert alert-info">';
echo '<p><strong>Lógica Actual de Redirección:</strong></p>';
echo '<ul>';
echo '<li>El sistema busca en la tabla <code>gmk_class</code> si el usuario tiene asignada alguna clase como <code>instructorid</code>.</li>';
echo '<li>La clase debe tener <code>closed = 0</code> (Estar activa).</li>';
echo '<li>Si se encuentra al menos UN registro, el usuario es redirigido.</li>';
echo '</ul>';
echo '</div>';

// 1. Fetch ALL users who might be teachers
// Criteria: 
// - Have entry in gmk_teacher_skill OR
// - Have entry in gmk_teacher_disponibility OR
// - Are assigned to ANY class in gmk_class (even closed)

$sql = "
    SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
    FROM {user} u
    LEFT JOIN {gmk_teacher_skill_relation} s ON s.userid = u.id
    LEFT JOIN {gmk_teacher_disponibility} d ON d.userid = u.id
    LEFT JOIN {gmk_class} c ON c.instructorid = u.id
    WHERE u.deleted = 0 
      AND (s.id IS NOT NULL OR d.id IS NOT NULL OR c.id IS NOT NULL)
    ORDER BY u.lastname ASC
";

$candidates = $DB->get_records_sql($sql);

if (empty($candidates)) {
    echo $OUTPUT->notification('No se encontraron candidatos potenciales (usuarios con skills, disponibilidad o clases asignadas).', 'warning');
} else {
    echo '<table class="generaltable">';
    echo '<thead>
            <tr>
                <th>Usuario</th>
                <th>Roles Moodle</th>
                <th>Tiene Skills?</th>
                <th>Tiene Disponibility?</th>
                <th>Clases Totales</th>
                <th>Clases Activas (Closed=0)</th>
                <th>¿Redirecciona?</th>
                <th>Diagnóstico</th>
            </tr>
          </thead>';
    echo '<tbody>';

    foreach ($candidates as $user) {
        
        $has_skills = $DB->record_exists('gmk_teacher_skill_relation', ['userid' => $user->id]);
        $has_disp = $DB->record_exists('gmk_teacher_disponibility', ['userid' => $user->id]);
        
        $all_classes = $DB->get_records('gmk_class', ['instructorid' => $user->id]);
        $total_classes = count($all_classes);
        
        $active_classes = 0;
        foreach ($all_classes as $c) {
            if ($c->closed == 0) {
                $active_classes++;
            }
        }
        
        // Check Moodle Roles (just for info)
        $user_roles = get_user_roles($context, $user->id, true);
        $role_names = [];
        foreach ($user_roles as $role) {
            $role_names[] = $role->shortname;
        }
        $roles_str = implode(', ', $role_names);

        $redirects = ($active_classes > 0);
        
        $redirect_icon = $redirects 
            ? '<span class="badge badge-success" style="font-size: 1.2em;">✅ SÍ</span>' 
            : '<span class="badge badge-danger" style="font-size: 1.2em;">❌ NO</span>';

        $diagnosis = '';
        if ($redirects) {
            $diagnosis = 'Todo correcto. Tiene ' . $active_classes . ' clase(s) activa(s).';
        } else {
            if ($total_classes > 0) {
                $diagnosis = '<strong style="color:red;">FALLO:</strong> Tiene ' . $total_classes . ' clases, pero TODAS están marcadas como CERRADAS (closed=1).';
            } elseif ($has_skills || $has_disp) {
                $diagnosis = '<strong style="color:orange;">PENDIENTE:</strong> Configurado como docente (Skills/Disp) pero NO se le ha asignado ninguna clase en gmk_class.';
            } else {
                $diagnosis = 'Usuario sin configuración docente completa.';
            }
        }

        echo '<tr>';
        echo '<td>' . fullname($user) . '<br><small>' . $user->email . '</small></td>';
        echo '<td>' . ($roles_str ? $roles_str : '-') . '</td>';
        echo '<td>' . ($has_skills ? 'Sí' : 'No') . '</td>';
        echo '<td>' . ($has_disp ? 'Sí' : 'No') . '</td>';
        echo '<td>' . $total_classes . '</td>';
        echo '<td>' . $active_classes . '</td>';
        echo '<td>' . $redirect_icon . '</td>';
        echo '<td>' . $diagnosis . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
}

echo $OUTPUT->footer();
