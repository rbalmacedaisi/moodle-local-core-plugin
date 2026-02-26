<?php
/**
 * Setup academic status custom field
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB;

admin_externalpage_setup('grupomakro_core_manage_courses');
echo $OUTPUT->header();

echo "<h1>🔧 Configuración de Campo Personalizado: Estado Académico</h1>";

// Check if field exists
$field = $DB->get_record('user_info_field', ['shortname' => 'academicstatus']);

if ($field) {
    echo "<div style='background-color: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3 style='color: #155724;'>✓ El campo 'academicstatus' ya existe</h3>";
    echo "<p><strong>Nombre:</strong> {$field->name}</p>";
    echo "<p><strong>Short name:</strong> {$field->shortname}</p>";
    echo "<p><strong>Tipo:</strong> " . ($field->datatype === 'text' ? 'Texto' : $field->datatype) . "</p>";
    echo "<p><strong>Categoría ID:</strong> {$field->categoryid}</p>";
    echo "</div>";

    echo "<div style='background-color: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>📝 Configuración Recomendada:</h3>";
    echo "<p>El campo ya está creado. Verifica que esté en la categoría correcta y visible donde lo necesites.</p>";
    echo "<p><a href='{$CFG->wwwroot}/user/profile/index.php' class='btn btn-primary'>Ir a Campos de Perfil</a></p>";
    echo "</div>";

} else {
    echo "<div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3 style='color: #856404;'>⚠️ El campo 'academicstatus' NO existe</h3>";
    echo "<p>Es necesario crear este campo personalizado para poder usar la funcionalidad de actualización de estado académico.</p>";
    echo "</div>";

    echo "<div style='background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>📋 Instrucciones de Configuración Manual:</h3>";
    echo "<ol>";
    echo "<li>Ve a <strong>Administración del sitio → Usuarios → Cuentas de usuario → Campos del perfil de usuario</strong></li>";
    echo "<li>Haz clic en <strong>\"Crear un nuevo campo\"</strong></li>";
    echo "<li>Selecciona el tipo: <strong>\"Campo de texto\"</strong> o <strong>\"Menú desplegable\"</strong> (recomendado)</li>";
    echo "<li>Configuración:";
    echo "<ul>";
    echo "<li><strong>Short name:</strong> academicstatus</li>";
    echo "<li><strong>Name:</strong> Estado Académico</li>";
    echo "<li><strong>Descripción:</strong> Estado académico del estudiante (Regular, Probatorio, etc.)</li>";
    echo "<li><strong>¿Es obligatorio?:</strong> No</li>";
    echo "<li><strong>¿Es editable?:</strong> Sí (por admin)</li>";
    echo "<li><strong>Visible:</strong> A todos</li>";
    if ($field_datatype === 'menu') {
        echo "<li><strong>Opciones del menú:</strong><br><textarea readonly style='width:100%; height:150px;'>";
        echo "Regular\nProbatorio\nCondicional\nBaja Temporal\nBaja Definitiva\nEgresado\nTitulado";
        echo "</textarea></li>";
    }
    echo "</ul>";
    echo "</li>";
    echo "<li>Guarda los cambios</li>";
    echo "</ol>";
    echo "<p><a href='{$CFG->wwwroot}/user/profile/index.php' class='btn btn-primary' target='_blank'>Abrir Campos de Perfil</a></p>";
    echo "</div>";

    echo "<div style='background-color: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🔄 Alternativa: Crear campo automáticamente (Solo si tienes permisos)</h3>";

    if (isset($_POST['create_field'])) {
        try {
            // Get or create the category
            $category = $DB->get_record('user_info_category', ['name' => 'Información del Estudiante']);

            if (!$category) {
                $category = new stdClass();
                $category->name = 'Información del Estudiante';
                $category->sortorder = 1;
                $category->id = $DB->insert_record('user_info_category', $category);
            }

            // Create the field
            $newfield = new stdClass();
            $newfield->shortname = 'academicstatus';
            $newfield->name = 'Estado Académico';
            $newfield->datatype = 'text';
            $newfield->description = 'Estado académico del estudiante (Regular, Probatorio, Condicional, Baja Temporal, Baja Definitiva, Egresado, Titulado)';
            $newfield->descriptionformat = FORMAT_HTML;
            $newfield->categoryid = $category->id;
            $newfield->sortorder = 999;
            $newfield->required = 0;
            $newfield->locked = 0;
            $newfield->visible = 2; // Visible to everyone
            $newfield->forceunique = 0;
            $newfield->signup = 0;
            $newfield->defaultdata = '';
            $newfield->defaultdataformat = FORMAT_HTML;
            $newfield->param1 = 255; // Maximum length
            $newfield->param2 = 100; // Display size
            $newfield->param3 = 0;
            $newfield->param4 = '';
            $newfield->param5 = '';

            $fieldid = $DB->insert_record('user_info_field', $newfield);

            echo "<div style='background-color: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4 style='color: #155724;'>✓ Campo creado exitosamente</h4>";
            echo "<p>ID del campo: $fieldid</p>";
            echo "<p><a href='?'>Recargar esta página</a> para ver la confirmación.</p>";
            echo "</div>";

        } catch (Exception $e) {
            echo "<div style='background-color: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4 style='color: #721c24;'>✗ Error al crear el campo</h4>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p>Por favor, crea el campo manualmente usando las instrucciones anteriores.</p>";
            echo "</div>";
        }
    } else {
        echo "<form method='post'>";
        echo "<button type='submit' name='create_field' class='btn btn-success' onclick='return confirm(\"¿Estás seguro de crear el campo automáticamente?\");'>Crear Campo Automáticamente</button>";
        echo "</form>";
        echo "<p style='color: #856404; font-size: 0.9em;'><strong>Nota:</strong> Esta operación creará el campo con configuración por defecto. Si falla, usa el método manual.</p>";
    }
    echo "</div>";
}

echo "<hr style='margin: 30px 0;'>";

echo "<div style='background-color: #e7f3ff; padding: 20px; border-radius: 5px;'>";
echo "<h3>📚 Información del Campo</h3>";
echo "<p><strong>Short name:</strong> academicstatus</p>";
echo "<p><strong>Propósito:</strong> Almacenar el estado académico del estudiante, diferente del estado general (Activo/Inactivo).</p>";
echo "<p><strong>Valores posibles:</strong></p>";
echo "<ul>";
echo "<li><strong>Regular:</strong> Estudiante en buen estado académico</li>";
echo "<li><strong>Probatorio:</strong> Estudiante en periodo de prueba</li>";
echo "<li><strong>Condicional:</strong> Estudiante con condiciones especiales</li>";
echo "<li><strong>Baja Temporal:</strong> Estudiante temporalmente inactivo</li>";
echo "<li><strong>Baja Definitiva:</strong> Estudiante dado de baja permanentemente</li>";
echo "<li><strong>Egresado:</strong> Estudiante que completó el programa</li>";
echo "<li><strong>Titulado:</strong> Estudiante que obtuvo el título</li>";
echo "</ul>";
echo "</div>";

echo "<div style='margin-top: 20px;'>";
echo "<a href='{$CFG->wwwroot}/local/grupomakro_core/pages/academicpanel.php' class='btn btn-primary'>← Volver al Panel Académico</a>";
echo "</div>";

echo $OUTPUT->footer();
