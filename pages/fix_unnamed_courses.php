<?php
/**
 * Fix unnamed courses in gmk_course_progre table.
 * This script updates all records where coursename is 'unnamed' or empty,
 * fetching the correct course name from the Moodle course table.
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

// Count how many records need fixing
$countQuery = "SELECT COUNT(id)
               FROM {gmk_course_progre}
               WHERE coursename = 'unnamed' OR coursename = '' OR coursename IS NULL";
$totalBroken = $DB->count_records_sql($countQuery);

// If action=fix parameter is passed, perform the update
$action = optional_param('action', '', PARAM_ALPHA);
$fixed = 0;
$errors = [];

if ($action === 'fix') {
    // Get all records with unnamed courses
    $sql = "SELECT cp.id, cp.courseid, cp.coursename, c.fullname, c.shortname
            FROM {gmk_course_progre} cp
            LEFT JOIN {course} c ON c.id = cp.courseid
            WHERE cp.coursename = 'unnamed' OR cp.coursename = '' OR cp.coursename IS NULL";

    $records = $DB->get_records_sql($sql);

    foreach ($records as $record) {
        try {
            if (!empty($record->fullname)) {
                // Update with the full course name
                $DB->set_field('gmk_course_progre', 'coursename', $record->fullname, ['id' => $record->id]);
                $fixed++;
            } else if (!empty($record->shortname)) {
                // Fallback to shortname if fullname is not available
                $DB->set_field('gmk_course_progre', 'coursename', $record->shortname, ['id' => $record->id]);
                $fixed++;
            } else {
                // Course doesn't exist in Moodle
                $errors[] = "Record ID {$record->id}: Course ID {$record->courseid} not found in Moodle";
            }
        } catch (Exception $e) {
            $errors[] = "Record ID {$record->id}: " . $e->getMessage();
        }
    }
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
                <span class="p-2 bg-blue-100 rounded-xl text-blue-600">
                    <i data-lucide="wrench" class="w-6 h-6"></i>
                </span>
                Corregir Asignaturas "unnamed"
            </h1>
            <p class="text-slate-500 mt-2">
                Esta herramienta corrige los registros en la tabla gmk_course_progre que tienen coursename como 'unnamed'.
            </p>
        </header>

        <!-- Status Card -->
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
            <h3 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                <i data-lucide="activity" class="text-blue-500 w-4 h-4"></i> Estado Actual
            </h3>

            <?php if ($action === 'fix'): ?>
                <!-- Results -->
                <div class="space-y-4">
                    <div class="bg-green-50 p-4 rounded-xl border border-green-200">
                        <div class="flex items-center gap-3">
                            <i data-lucide="check-circle" class="text-green-600 w-6 h-6"></i>
                            <div>
                                <p class="font-bold text-green-800">Corrección Completada</p>
                                <p class="text-sm text-green-700">
                                    Se corrigieron <?php echo $fixed; ?> registros exitosamente.
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="bg-red-50 p-4 rounded-xl border border-red-200">
                            <div class="flex items-start gap-3">
                                <i data-lucide="alert-circle" class="text-red-600 w-6 h-6 flex-shrink-0 mt-0.5"></i>
                                <div class="flex-1">
                                    <p class="font-bold text-red-800 mb-2">Errores Encontrados (<?php echo count($errors); ?>)</p>
                                    <div class="max-h-60 overflow-y-auto space-y-1">
                                        <?php foreach ($errors as $error): ?>
                                            <p class="text-xs text-red-700 font-mono bg-red-100 p-2 rounded">
                                                <?php echo htmlspecialchars($error); ?>
                                            </p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="flex justify-center">
                        <a href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/fix_unnamed_courses.php"
                           class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all inline-flex items-center gap-2">
                            <i data-lucide="rotate-cw" class="w-4 h-4"></i>
                            Verificar Nuevamente
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Pre-fix status -->
                <div class="grid grid-cols-1 gap-4">
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 border-l-4 <?php echo $totalBroken > 0 ? 'border-l-amber-500' : 'border-l-green-500'; ?>">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">
                            Registros con "unnamed"
                        </p>
                        <div class="text-4xl font-black text-slate-800">
                            <?php echo $totalBroken; ?>
                        </div>
                        <?php if ($totalBroken > 0): ?>
                            <p class="text-sm text-amber-600 mt-2">
                                Estos registros necesitan corrección
                            </p>
                        <?php else: ?>
                            <p class="text-sm text-green-600 mt-2">
                                ✓ Todos los registros están correctos
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($totalBroken > 0): ?>
                    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-xl">
                        <div class="flex items-start gap-3">
                            <i data-lucide="info" class="text-blue-600 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                            <div>
                                <p class="font-bold text-blue-800 mb-2">¿Qué hace esta corrección?</p>
                                <ul class="text-sm text-blue-700 space-y-1 list-disc list-inside">
                                    <li>Busca todos los registros con coursename = "unnamed", vacío o NULL</li>
                                    <li>Obtiene el nombre real del curso desde la tabla mdl_course</li>
                                    <li>Actualiza el campo coursename con el nombre correcto</li>
                                    <li>Reporta los cursos que no se encontraron en Moodle</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-center mt-6">
                        <a href="?action=fix"
                           class="bg-green-600 hover:bg-green-700 text-white px-8 py-4 rounded-2xl font-bold shadow-lg shadow-green-200 transition-all inline-flex items-center gap-3"
                           onclick="return confirm('¿Está seguro de que desea corregir <?php echo $totalBroken; ?> registros?');">
                            <i data-lucide="check-circle" class="w-5 h-5"></i>
                            Corregir <?php echo $totalBroken; ?> Registros
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Technical Details -->
        <div class="bg-slate-100 p-6 rounded-2xl border border-slate-200">
            <h3 class="font-bold text-slate-700 mb-3 flex items-center gap-2">
                <i data-lucide="database" class="text-slate-500 w-4 h-4"></i> Detalles Técnicos
            </h3>
            <div class="text-xs text-slate-600 space-y-2 font-mono bg-slate-50 p-4 rounded-lg">
                <p><strong>Tabla:</strong> mdl_gmk_course_progre</p>
                <p><strong>Campo:</strong> coursename</p>
                <p><strong>Condición:</strong> WHERE coursename = 'unnamed' OR coursename = '' OR coursename IS NULL</p>
                <p><strong>Fuente de corrección:</strong> mdl_course.fullname (o shortname como fallback)</p>
            </div>
        </div>
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
