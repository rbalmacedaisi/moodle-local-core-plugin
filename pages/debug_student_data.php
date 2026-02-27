<?php
/**
 * Página de debug dinámica para verificar estructura de datos de estudiantes
 *
 * Esta página muestra:
 * - Estructura de datos que retorna get_student_info
 * - Estados de estudiantes
 * - Campos disponibles en cada registro
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_student_data.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Estructura de datos de estudiantes');
$PAGE->set_heading('Debug: Estructura de datos de estudiantes');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

?>
<style>
    .debug-container {
        font-family: 'Courier New', monospace;
        background: #1e1e1e;
        color: #d4d4d4;
        padding: 20px;
        border-radius: 8px;
        margin: 20px 0;
    }
    .debug-section {
        margin: 20px 0;
        padding: 15px;
        background: #252526;
        border-left: 4px solid #007acc;
        border-radius: 4px;
    }
    .debug-title {
        color: #4ec9b0;
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 10px;
    }
    .debug-subtitle {
        color: #569cd6;
        font-size: 14px;
        margin: 15px 0 5px 0;
    }
    .debug-data {
        background: #1e1e1e;
        padding: 10px;
        border-radius: 4px;
        overflow-x: auto;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
    .highlight-key {
        color: #9cdcfe;
    }
    .highlight-string {
        color: #ce9178;
    }
    .highlight-number {
        color: #b5cea8;
    }
    .highlight-null {
        color: #569cd6;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        background: #252526;
        margin: 10px 0;
    }
    th {
        background: #007acc;
        color: white;
        padding: 10px;
        text-align: left;
    }
    td {
        padding: 8px;
        border-bottom: 1px solid #3c3c3c;
    }
    .success {
        color: #4ec9b0;
    }
    .error {
        color: #f48771;
    }
</style>

<div class="debug-container">
    <h2 style="color: #4ec9b0;">🔍 Debug: Datos de Estudiantes</h2>
    <p style="color: #858585;">Esta página muestra la estructura real de los datos de estudiantes</p>

    <?php
    // 1. Obtener estudiantes de local_learning_users
    echo '<div class="debug-section">';
    echo '<div class="debug-title">1. Registros en local_learning_users (primeros 5)</div>';

    $learning_users = $DB->get_records('local_learning_users', null, 'id DESC', '*', 0, 5);

    if ($learning_users) {
        echo '<div class="debug-subtitle">📊 Campos disponibles:</div>';
        $first_record = reset($learning_users);
        echo '<table>';
        echo '<tr><th>Campo</th><th>Tipo</th><th>Valor de ejemplo</th></tr>';
        foreach ($first_record as $field => $value) {
            $type = gettype($value);
            $display_value = $value;
            if (is_null($value)) {
                $display_value = '<span class="highlight-null">NULL</span>';
            } else if (is_numeric($value)) {
                $display_value = '<span class="highlight-number">' . htmlspecialchars($value) . '</span>';
            } else {
                $display_value = '<span class="highlight-string">' . htmlspecialchars(substr($value, 0, 50)) . '</span>';
            }
            echo "<tr><td class='highlight-key'>{$field}</td><td>{$type}</td><td>{$display_value}</td></tr>";
        }
        echo '</table>';

        echo '<div class="debug-subtitle">📝 Ejemplo completo (primer registro):</div>';
        echo '<div class="debug-data">';
        echo json_encode($first_record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo '</div>';
    } else {
        echo '<p class="error">❌ No se encontraron registros en local_learning_users</p>';
    }
    echo '</div>';

    // 2. Verificar estados académicos únicos
    echo '<div class="debug-section">';
    echo '<div class="debug-title">2. Estados Académicos en uso</div>';

    $sql = "SELECT DISTINCT status FROM {local_learning_users} WHERE status IS NOT NULL ORDER BY status";
    $statuses = $DB->get_records_sql($sql);

    if ($statuses) {
        echo '<table>';
        echo '<tr><th>Estado</th><th>Cantidad de estudiantes</th></tr>';
        foreach ($statuses as $status_record) {
            $status = $status_record->status;
            $count = $DB->count_records('local_learning_users', ['status' => $status]);
            echo "<tr><td class='highlight-string'>{$status}</td><td class='highlight-number'>{$count}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p class="error">❌ No se encontraron estados en la base de datos</p>';
    }
    echo '</div>';

    // 3. Verificar campo de perfil studentstatus
    echo '<div class="debug-section">';
    echo '<div class="debug-title">3. Campo de perfil "studentstatus"</div>';

    $field = $DB->get_record('user_info_field', ['shortname' => 'studentstatus']);
    if ($field) {
        echo '<div class="success">✅ Campo encontrado</div>';
        echo '<div class="debug-data">';
        echo json_encode($field, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo '</div>';

        // Obtener valores únicos
        $sql = "SELECT DISTINCT data FROM {user_info_data} WHERE fieldid = ? AND data IS NOT NULL ORDER BY data";
        $values = $DB->get_records_sql($sql, [$field->id]);

        echo '<div class="debug-subtitle">Valores en uso:</div>';
        echo '<table>';
        echo '<tr><th>Valor</th><th>Cantidad de usuarios</th></tr>';
        foreach ($values as $value_record) {
            $value = $value_record->data;
            $count = $DB->count_records('user_info_data', ['fieldid' => $field->id, 'data' => $value]);
            echo "<tr><td class='highlight-string'>{$value}</td><td class='highlight-number'>{$count}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p class="error">❌ Campo "studentstatus" no encontrado</p>';
    }
    echo '</div>';

    // 4. Simular respuesta de get_student_info
    echo '<div class="debug-section">';
    echo '<div class="debug-title">4. Simulación de respuesta get_student_info</div>';

    require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_info.php');

    try {
        $result = \local_grupomakro_core\external\student\get_student_info::execute(1, 5, '', '', '', '', '', 0);

        echo '<div class="debug-subtitle">Estructura de respuesta:</div>';
        echo '<div class="debug-data">';

        // Decodificar si es string
        if (isset($result['data']['dataUsers']) && is_string($result['data']['dataUsers'])) {
            $decoded = json_decode($result['data']['dataUsers'], true);
            if ($decoded && is_array($decoded) && count($decoded) > 0) {
                echo "📊 Primer estudiante - Campos disponibles:\n\n";
                $first_student = $decoded[0];
                foreach ($first_student as $key => $value) {
                    $type = gettype($value);
                    $display = is_array($value) ? 'Array[' . count($value) . ']' : (is_null($value) ? 'NULL' : substr(json_encode($value), 0, 50));
                    echo "  {$key}: ({$type}) {$display}\n";
                }

                echo "\n\n📝 Estudiante completo:\n\n";
                echo json_encode($first_student, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        } else {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        echo '</div>';
    } catch (Exception $e) {
        echo '<p class="error">❌ Error al ejecutar get_student_info: ' . $e->getMessage() . '</p>';
        echo '<div class="debug-data">' . $e->getTraceAsString() . '</div>';
    }
    echo '</div>';

    // 5. Verificar servicio web update_student_status
    echo '<div class="debug-section">';
    echo '<div class="debug-title">5. Servicio web update_student_status</div>';

    $service_functions = $DB->get_records('external_functions', ['name' => 'local_grupomakro_update_student_status']);

    if ($service_functions) {
        echo '<div class="success">✅ Servicio registrado en base de datos</div>';
        foreach ($service_functions as $func) {
            echo '<div class="debug-data">';
            echo json_encode($func, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            echo '</div>';
        }
    } else {
        echo '<p class="error">❌ Servicio NO registrado en base de datos</p>';
        echo '<p style="color: #ce9178;">Ejecuta: php admin/cli/upgrade.php</p>';
    }

    // Verificar clase PHP
    $class_exists = class_exists('local_grupomakro_core\external\student\update_student_status');
    if ($class_exists) {
        echo '<div class="success">✅ Clase PHP existe</div>';
        echo '<div class="debug-subtitle">Métodos disponibles:</div>';
        echo '<div class="debug-data">';
        $reflection = new ReflectionClass('local_grupomakro_core\external\student\update_student_status');
        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic() && $method->isStatic()) {
                echo "  - {$method->name}()\n";
            }
        }
        echo '</div>';
    } else {
        echo '<p class="error">❌ Clase PHP no existe</p>';
    }
    echo '</div>';

    // 6. Resumen de configuración
    echo '<div class="debug-section">';
    echo '<div class="debug-title">6. ✅ Checklist de configuración</div>';

    $checks = [
        'Tabla local_learning_users existe' => $DB->get_manager()->table_exists('local_learning_users'),
        'Campo status en local_learning_users' => $DB->get_manager()->field_exists('local_learning_users', 'status'),
        'Campo studentstatus en user_info_field' => (bool)$DB->get_record('user_info_field', ['shortname' => 'studentstatus']),
        'Servicio web registrado' => (bool)$DB->get_record('external_functions', ['name' => 'local_grupomakro_update_student_status']),
        'Clase PHP existe' => class_exists('local_grupomakro_core\external\student\update_student_status'),
    ];

    echo '<table>';
    echo '<tr><th>Verificación</th><th>Estado</th></tr>';
    foreach ($checks as $check => $result) {
        $status = $result ? '<span class="success">✅ OK</span>' : '<span class="error">❌ FALTA</span>';
        echo "<tr><td>{$check}</td><td>{$status}</td></tr>";
    }
    echo '</table>';
    echo '</div>';

    ?>
</div>

<p style="margin-top: 20px;">
    <a href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/academicpanel.php" class="btn btn-primary">
        ← Volver al Panel Académico
    </a>
</p>

<?php
echo $OUTPUT->footer();
