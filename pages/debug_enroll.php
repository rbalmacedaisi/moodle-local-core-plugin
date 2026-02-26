<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/grupomakro_core/pages/debug_enroll.php');
$PAGE->set_title('Debug Enroll Student Endpoint');
$PAGE->set_heading('Debug Enroll Student Endpoint');

echo $OUTPUT->header();

echo '<h3>Debug: Ejecutando local_grupomakro_odoo_enroll_student</h3>';

$username = optional_param('username', '', PARAM_RAW);
$product = optional_param('product', '', PARAM_TEXT);

if (empty($username) || empty($product)) {
    echo '<p>Por favor, provea los parámetros <code>?username=USERNAME&product=PLAN_NAME</code> en la URL.</p>';
    echo '<p>Ejemplo: <code>?username=8-1011-1219&product=TÉCNICO SUPERIOR...</code></p>';
    
    // Test Form
    echo '<form method="GET">';
    echo '<label>Username: <input type="text" name="username" value="8-1011-1219" style="width:300px; padding:5px; margin:5px;"></label><br>';
    echo '<label>Plan (Course Name): <input type="text" name="product" value="TÉCNICO SUPERIOR EN SOLDADURA SUBACUÁTICA Y ESTRUCTURAS ESPECIALES" style="width:300px; padding:5px; margin:5px;"></label><br>';
    echo '<input type="submit" value="Probar Matrícula" class="btn btn-primary">';
    echo '</form>';
    
    echo $OUTPUT->footer();
    die();
}

echo '<h4>Parámetros recibidos:</h4>';
echo '<ul>';
echo '<li>Username: ' . s($username) . '</li>';
echo '<li>Plan (Odoo Product): ' . s($product) . '</li>';
echo '</ul>';

echo '<hr><h4>Ejecutando la clase externa Enroll_student::execute()</h4>';

try {
    // Attempting to manually load the class to see if the require fails locally.
    require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/odoo/enroll_student.php');
    
    echo '<p>Clase PHP cargada correctamente (Sin errores fatales de parseo).</p>';
    
    // Call the same execute method Odoo uses.
    $result = \local_grupomakro_core\external\odoo\enroll_student::execute($product, $username, 5);
    
    echo '<div class="alert alert-success">';
    echo '<h5>Resultado (Éxito de la API)</h5>';
    echo '<pre>';
    print_r($result);
    echo '</pre>';
    echo '</div>';
    
} catch (\Throwable $e) {
    echo '<div class="alert alert-danger">';
    echo '<h5>FATAL ERROR CAPTURADO (Throwable)</h5>';
    echo '<p><strong>Clase de Error:</strong> ' . get_class($e) . '</p>';
    echo '<p><strong>Mensaje:</strong> ' . s($e->getMessage()) . '</p>';
    echo '<p><strong>Archivo:</strong> ' . $e->getFile() . '</p>';
    echo '<p><strong>Línea:</strong> ' . $e->getLine() . '</p>';
    echo '<hr><strong>Stack Trace:</strong><br><pre>' . $e->getTraceAsString() . '</pre>';
    echo '</div>';
}

echo $OUTPUT->footer();
