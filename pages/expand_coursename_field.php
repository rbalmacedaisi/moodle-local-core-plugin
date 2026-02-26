<?php
/**
 * Expand coursename field size in gmk_course_progre table.
 * Changes VARCHAR(64) to VARCHAR(255) to accommodate longer course names.
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/ddllib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB;

$action = optional_param('action', '', PARAM_ALPHA);
$success = false;
$message = '';

if ($action === 'expand') {
    try {
        // Get the database manager
        $dbman = $DB->get_manager();

        // Define the table
        $table = new xmldb_table('gmk_course_progre');

        // Define the field with new size
        $field = new xmldb_field('coursename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'unnamed', 'courseid');

        // Check if the field exists
        if ($dbman->field_exists($table, $field)) {
            // Change the field size
            $dbman->change_field_precision($table, $field);
            $success = true;
            $message = 'Campo coursename expandido exitosamente de VARCHAR(64) a VARCHAR(255)';
        } else {
            $message = 'El campo coursename no existe en la tabla gmk_course_progre';
        }

    } catch (Exception $e) {
        $message = 'Error al expandir el campo: ' . $e->getMessage();
    }
}

// Get current field info
$currentSize = 64; // Default from install.xml
$currentType = 'VARCHAR(64)';

try {
    // Try to get actual field size from database
    $sql = "SELECT CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$CFG->prefix}gmk_course_progre'
            AND COLUMN_NAME = 'coursename'";

    $result = $DB->get_record_sql($sql);
    if ($result) {
        $currentSize = $result->character_maximum_length;
        $currentType = "VARCHAR($currentSize)";
    }
} catch (Exception $e) {
    // Ignore errors, use defaults
}

// Display the page
admin_externalpage_setup('grupomakro_core_manage_courses');
echo $OUTPUT->header();
?>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>

<div class="bg-slate-50 min-h-screen p-6 font-sans text-slate-800">
    <div class="max-w-4xl mx-auto space-y-8">

        <!-- Header -->
        <header>
            <h1 class="text-3xl font-bold text-slate-900 tracking-tight flex items-center gap-3">
                <span class="p-2 bg-purple-100 rounded-xl text-purple-600">
                    <i data-lucide="expand" class="w-6 h-6"></i>
                </span>
                Expandir Campo "coursename"
            </h1>
            <p class="text-slate-500 mt-2">
                Esta herramienta expande el tamaño del campo coursename en la tabla gmk_course_progre de 64 a 255 caracteres.
            </p>
        </header>

        <?php if ($action === 'expand'): ?>
            <!-- Result -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                <?php if ($success): ?>
                    <div class="bg-green-50 p-4 rounded-xl border border-green-200">
                        <div class="flex items-center gap-3">
                            <i data-lucide="check-circle" class="text-green-600 w-8 h-8"></i>
                            <div>
                                <p class="font-bold text-green-800 text-lg">¡Expansión Exitosa!</p>
                                <p class="text-sm text-green-700 mt-1"><?php echo htmlspecialchars($message); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-center mt-6">
                        <a href="fix_unnamed_courses.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all inline-flex items-center gap-2">
                            <i data-lucide="wrench" class="w-4 h-4"></i>
                            Ahora Corregir Registros "unnamed"
                        </a>
                    </div>
                <?php else: ?>
                    <div class="bg-red-50 p-4 rounded-xl border border-red-200">
                        <div class="flex items-start gap-3">
                            <i data-lucide="alert-circle" class="text-red-600 w-8 h-8 flex-shrink-0"></i>
                            <div>
                                <p class="font-bold text-red-800 text-lg">Error en la Expansión</p>
                                <p class="text-sm text-red-700 mt-1 font-mono bg-red-100 p-2 rounded">
                                    <?php echo htmlspecialchars($message); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Pre-expand status -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                <h3 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                    <i data-lucide="database" class="text-purple-500 w-4 h-4"></i> Estado Actual del Campo
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 border-l-4 border-l-amber-500">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Tamaño Actual</p>
                        <div class="text-3xl font-black text-slate-800 mt-1"><?php echo $currentSize; ?></div>
                        <p class="text-sm text-slate-500 mt-1">caracteres máximos</p>
                    </div>

                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 border-l-4 border-l-green-500">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Tamaño Propuesto</p>
                        <div class="text-3xl font-black text-slate-800 mt-1">255</div>
                        <p class="text-sm text-slate-500 mt-1">caracteres máximos</p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
                    <div class="flex items-start gap-3">
                        <i data-lucide="info" class="text-blue-600 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                        <div>
                            <p class="font-bold text-blue-800 mb-2">¿Por qué expandir?</p>
                            <ul class="text-sm text-blue-700 space-y-2 list-disc list-inside">
                                <li>Algunos cursos tienen nombres muy largos (65+ caracteres)</li>
                                <li>Ejemplo: "TABLAS DE DESCOMPRESIÓN DE AIRE Y PROCEDIMIENTOS DE DESCOMPRESIÓN" (65 chars)</li>
                                <li>El campo actual solo permite 64 caracteres, causando errores</li>
                                <li>255 caracteres es el estándar para campos de texto mediano</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php if ($currentSize < 255): ?>
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <i data-lucide="alert-triangle" class="text-amber-600 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                            <div>
                                <p class="font-bold text-amber-800 mb-2">Importante</p>
                                <ul class="text-sm text-amber-700 space-y-1 list-disc list-inside">
                                    <li>Esta operación modifica la estructura de la base de datos</li>
                                    <li>Es una operación segura que solo aumenta el tamaño del campo</li>
                                    <li>No afecta los datos existentes</li>
                                    <li>Se recomienda hacer un respaldo antes de continuar</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-center">
                        <a href="?action=expand"
                           class="bg-purple-600 hover:bg-purple-700 text-white px-8 py-4 rounded-2xl font-bold shadow-lg shadow-purple-200 transition-all inline-flex items-center gap-3"
                           onclick="return confirm('¿Está seguro de que desea expandir el campo coursename de <?php echo $currentSize; ?> a 255 caracteres?');">
                            <i data-lucide="expand" class="w-5 h-5"></i>
                            Expandir Campo a 255 Caracteres
                        </a>
                    </div>
                <?php else: ?>
                    <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                        <div class="flex items-center gap-3">
                            <i data-lucide="check-circle" class="text-green-600 w-6 h-6"></i>
                            <div>
                                <p class="font-bold text-green-800">¡Campo Ya Expandido!</p>
                                <p class="text-sm text-green-700 mt-1">
                                    El campo coursename ya tiene un tamaño de <?php echo $currentSize; ?> caracteres. No es necesario expandirlo.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-center mt-6">
                        <a href="fix_unnamed_courses.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all inline-flex items-center gap-2">
                            <i data-lucide="wrench" class="w-4 h-4"></i>
                            Ir a Corregir Registros "unnamed"
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Technical Details -->
            <div class="bg-slate-100 p-6 rounded-2xl border border-slate-200">
                <h3 class="font-bold text-slate-700 mb-3 flex items-center gap-2">
                    <i data-lucide="code" class="text-slate-500 w-4 h-4"></i> Detalles Técnicos
                </h3>
                <div class="text-xs text-slate-600 space-y-2 font-mono bg-slate-50 p-4 rounded-lg">
                    <p><strong>Tabla:</strong> mdl_gmk_course_progre</p>
                    <p><strong>Campo:</strong> coursename</p>
                    <p><strong>Tipo actual:</strong> <?php echo $currentType; ?></p>
                    <p><strong>Tipo propuesto:</strong> VARCHAR(255)</p>
                    <p><strong>Operación SQL:</strong> ALTER TABLE mdl_gmk_course_progre MODIFY coursename VARCHAR(255) NOT NULL DEFAULT 'unnamed'</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Initialize Lucide icons
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });
</script>

<?php
echo $OUTPUT->footer();
