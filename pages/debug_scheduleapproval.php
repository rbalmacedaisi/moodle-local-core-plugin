<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_scheduleapproval.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Schedule Approval');
$PAGE->set_heading('Debug: Schedule Approval');
$PAGE->set_pagelayout('admin');

$courseid = optional_param('id', 0, PARAM_INT);
$periodsid = optional_param('periodsid', '', PARAM_RAW);

echo $OUTPUT->header();
?>

<style>
    .debug-container { max-width: 1400px; margin: 20px auto; font-family: monospace; }
    .section { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #007bff; }
    .info-box { background: #e7f3ff; padding: 15px; border-left: 4px solid #007bff; margin: 15px 0; }
    .warning-box { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; }
    .error-box { background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 15px 0; }
    .success-box { background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 15px 0; }
    .code-block { background: #f4f4f4; padding: 12px; border-left: 3px solid #007bff; margin: 10px 0; font-size: 13px; overflow-x: auto; }
    .table-debug { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 13px; }
    .table-debug th { background: #343a40; color: white; padding: 10px; text-align: left; }
    .table-debug td { padding: 8px; border-bottom: 1px solid #dee2e6; }
    .table-debug tr:hover { background: #f8f9fa; }
    h2 { color: #007bff; margin-top: 30px; }
    h3 { color: #495057; margin-top: 20px; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
    .badge-success { background: #28a745; color: white; }
    .badge-danger { background: #dc3545; color: white; }
    .badge-warning { background: #ffc107; color: black; }
    .badge-info { background: #17a2b8; color: white; }
    pre { background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; overflow-x: auto; }
</style>

<div class="debug-container">
    <h1>🔍 Debug: Schedule Approval</h1>

    <div class="info-box">
        <strong>URL Actual:</strong> <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?><br>
        <strong>Course ID:</strong> <?php echo $courseid; ?><br>
        <strong>Periods ID:</strong> <?php echo $periodsid; ?>
    </div>

<?php

if (empty($courseid)) {
    echo "<div class='warning-box'>";
    echo "<h3>⚠️ No se proporcionó ID de curso</h3>";
    echo "<p>Por favor, proporciona un ID de curso en la URL: <code>?id=XXX&periodsid=YYY</code></p>";
    echo "</div>";
    echo $OUTPUT->footer();
    exit;
}

// ============================================================================
// STEP 1: Verify Course Exists
// ============================================================================
echo "<h2>📋 PASO 1: Verificar que el Curso Existe</h2>";

$course = $DB->get_record('course', ['id' => $courseid], '*');

if ($course) {
    echo "<div class='success-box'>";
    echo "<h3>✅ Curso encontrado en tabla {course}</h3>";
    echo "<strong>ID:</strong> {$course->id}<br>";
    echo "<strong>Nombre:</strong> {$course->fullname}<br>";
    echo "<strong>Short name:</strong> {$course->shortname}<br>";
    echo "</div>";
} else {
    echo "<div class='error-box'>";
    echo "<h3>❌ ERROR: No se encontró el curso con ID {$courseid} en tabla {course}</h3>";
    echo "<p>Este es el problema principal. El ID que viene del panel de horarios no corresponde a un curso válido.</p>";
    echo "</div>";
}

// ============================================================================
// STEP 2: Check local_learning_courses
// ============================================================================
echo "<h2>📚 PASO 2: Buscar en local_learning_courses (Subject Records)</h2>";

$subject_by_courseid = $DB->get_records('local_learning_courses', ['courseid' => $courseid]);
$subject_by_id = $DB->get_record('local_learning_courses', ['id' => $courseid]);

if ($subject_by_courseid) {
    echo "<div class='success-box'>";
    echo "<h3>✅ Encontrado como Moodle Course ID (courseid = {$courseid})</h3>";
    echo "<table class='table-debug'>";
    echo "<thead><tr><th>Subject ID</th><th>Learning Plan ID</th><th>Period ID</th><th>Course ID</th></tr></thead>";
    echo "<tbody>";
    foreach ($subject_by_courseid as $subj) {
        echo "<tr>";
        echo "<td>{$subj->id}</td>";
        echo "<td>{$subj->learningplanid}</td>";
        echo "<td>{$subj->periodid}</td>";
        echo "<td>{$subj->courseid}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo "</div>";
} elseif ($subject_by_id) {
    echo "<div class='success-box'>";
    echo "<h3>✅ Encontrado como Subject ID (id = {$courseid})</h3>";
    echo "<strong>Subject ID:</strong> {$subject_by_id->id}<br>";
    echo "<strong>Learning Plan ID:</strong> {$subject_by_id->learningplanid}<br>";
    echo "<strong>Period ID:</strong> {$subject_by_id->periodid}<br>";
    echo "<strong>Moodle Course ID:</strong> {$subject_by_id->courseid}<br>";
    echo "</div>";
} else {
    echo "<div class='error-box'>";
    echo "<h3>❌ No se encontró en local_learning_courses</h3>";
    echo "<p>El ID {$courseid} no existe ni como 'id' ni como 'courseid' en local_learning_courses.</p>";
    echo "</div>";
}

// ============================================================================
// STEP 3: Check gmk_class table
// ============================================================================
echo "<h2>🏫 PASO 3: Buscar Clases en gmk_class</h2>";

// Try different search strategies
$classes_by_courseid = $DB->get_records('gmk_class', ['courseid' => $courseid]);
$classes_by_corecourseid = $DB->get_records('gmk_class', ['corecourseid' => $courseid]);

echo "<h3>Búsqueda por courseid = {$courseid}</h3>";
if ($classes_by_courseid) {
    echo "<div class='success-box'>";
    echo "<p>✅ Encontradas <strong>" . count($classes_by_courseid) . "</strong> clases</p>";
    echo "<table class='table-debug'>";
    echo "<thead><tr><th>Class ID</th><th>Name</th><th>courseid</th><th>corecourseid</th><th>learningplanid</th><th>periodid</th><th>approved</th><th>closed</th></tr></thead>";
    echo "<tbody>";
    foreach ($classes_by_courseid as $class) {
        $approved_badge = $class->approved ? "<span class='badge badge-success'>Aprobado</span>" : "<span class='badge badge-warning'>Pendiente</span>";
        $closed_badge = $class->closed ? "<span class='badge badge-danger'>Cerrado</span>" : "<span class='badge badge-success'>Abierto</span>";
        echo "<tr>";
        echo "<td>{$class->id}</td>";
        echo "<td>{$class->name}</td>";
        echo "<td>{$class->courseid}</td>";
        echo "<td>" . ($class->corecourseid ?: 'NULL') . "</td>";
        echo "<td>{$class->learningplanid}</td>";
        echo "<td>{$class->periodid}</td>";
        echo "<td>{$approved_badge}</td>";
        echo "<td>{$closed_badge}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo "</div>";
} else {
    echo "<div class='warning-box'>";
    echo "<p>⚠️ No se encontraron clases con courseid = {$courseid}</p>";
    echo "</div>";
}

echo "<h3>Búsqueda por corecourseid = {$courseid}</h3>";
if ($classes_by_corecourseid) {
    echo "<div class='success-box'>";
    echo "<p>✅ Encontradas <strong>" . count($classes_by_corecourseid) . "</strong> clases</p>";
    echo "<table class='table-debug'>";
    echo "<thead><tr><th>Class ID</th><th>Name</th><th>courseid</th><th>corecourseid</th><th>learningplanid</th><th>periodid</th><th>approved</th><th>closed</th></tr></thead>";
    echo "<tbody>";
    foreach ($classes_by_corecourseid as $class) {
        $approved_badge = $class->approved ? "<span class='badge badge-success'>Aprobado</span>" : "<span class='badge badge-warning'>Pendiente</span>";
        $closed_badge = $class->closed ? "<span class='badge badge-danger'>Cerrado</span>" : "<span class='badge badge-success'>Abierto</span>";
        echo "<tr>";
        echo "<td>{$class->id}</td>";
        echo "<td>{$class->name}</td>";
        echo "<td>{$class->courseid}</td>";
        echo "<td>{$class->corecourseid}</td>";
        echo "<td>{$class->learningplanid}</td>";
        echo "<td>{$class->periodid}</td>";
        echo "<td>{$approved_badge}</td>";
        echo "<td>{$closed_badge}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo "</div>";
} else {
    echo "<div class='warning-box'>";
    echo "<p>⚠️ No se encontraron clases con corecourseid = {$courseid}</p>";
    echo "</div>";
}

// ============================================================================
// STEP 4: Simulate Web Service Call
// ============================================================================
echo "<h2>🔌 PASO 4: Simular Llamada al Web Service</h2>";

echo "<div class='info-box'>";
echo "<p>El componente Vue llama a: <code>local_grupomakro_get_course_class_schedules</code></p>";
echo "<p>Con parámetros:</p>";
echo "<ul>";
echo "<li><strong>courseId:</strong> {$courseid}</li>";
echo "<li><strong>periodIds:</strong> {$periodsid}</li>";
echo "</ul>";
echo "</div>";

try {
    // Simulate the parameters that would be passed to the web service
    $params = [
        'courseId' => $courseid,
        'periodIds' => $periodsid,
        'learningPlanId' => null
    ];

    echo "<div class='code-block'>";
    echo "<strong>Parámetros simulados:</strong><br>";
    echo "<pre>" . print_r($params, true) . "</pre>";
    echo "</div>";

    // Call the actual function
    echo "<h3>Llamando a get_learning_plan_course_schedules()...</h3>";

    $result = get_learning_plan_course_schedules($params);

    if (empty($result)) {
        echo "<div class='error-box'>";
        echo "<h3>❌ ERROR: La función retornó un array vacío</h3>";
        echo "<p>Esto explica por qué no se muestra nada en la página.</p>";
        echo "</div>";
    } else {
        echo "<div class='success-box'>";
        echo "<h3>✅ La función retornó datos</h3>";
        echo "<p>Se encontraron <strong>" . count($result) . "</strong> registros agrupados</p>";
        echo "</div>";

        echo "<h3>Resultado Detallado:</h3>";
        echo "<pre>" . print_r($result, true) . "</pre>";
    }

} catch (Exception $e) {
    echo "<div class='error-box'>";
    echo "<h3>❌ ERROR al ejecutar la función</h3>";
    echo "<p><strong>Mensaje:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>Archivo:</strong> " . $e->getFile() . ":" . $e->getLine() . "</p>";
    echo "</div>";
}

// ============================================================================
// STEP 5: Check construct_course_schedules_filter
// ============================================================================
echo "<h2>🔍 PASO 5: Analizar Construcción de Filtros</h2>";

try {
    $filters = construct_course_schedules_filter($params);

    echo "<div class='code-block'>";
    echo "<strong>Filtros construidos:</strong><br>";
    echo "<pre>" . print_r($filters, true) . "</pre>";
    echo "</div>";

    if (empty($filters)) {
        echo "<div class='error-box'>";
        echo "<h3>❌ No se construyeron filtros</h3>";
        echo "<p>La función construct_course_schedules_filter() retornó un array vacío.</p>";
        echo "</div>";
    } else {
        echo "<div class='success-box'>";
        echo "<h3>✅ Se construyeron " . count($filters) . " filtro(s)</h3>";
        echo "</div>";

        // Try each filter
        echo "<h3>Resultados por Filtro:</h3>";
        foreach ($filters as $idx => $filter) {
            echo "<h4>Filtro #" . ($idx + 1) . ":</h4>";
            echo "<div class='code-block'>";
            echo "<pre>" . print_r($filter, true) . "</pre>";
            echo "</div>";

            $classes = list_classes($filter);

            if (empty($classes)) {
                echo "<div class='warning-box'>";
                echo "<p>⚠️ Este filtro no retornó clases</p>";

                // Debug: check why
                if (!empty($filter['corecourseid'])) {
                    $total = $DB->count_records('gmk_class', ['corecourseid' => $filter['corecourseid']]);
                    $closed = $DB->count_records('gmk_class', ['corecourseid' => $filter['corecourseid'], 'closed' => 1]);
                    $open = $DB->count_records('gmk_class', ['corecourseid' => $filter['corecourseid'], 'closed' => 0]);

                    echo "<p><strong>Debug de corecourseid={$filter['corecourseid']}:</strong></p>";
                    echo "<ul>";
                    echo "<li>Total de clases: {$total}</li>";
                    echo "<li>Clases cerradas (closed=1): {$closed}</li>";
                    echo "<li>Clases abiertas (closed=0): {$open}</li>";
                    echo "</ul>";

                    if (!empty($filter['periodid'])) {
                        $with_period = $DB->count_records('gmk_class', ['corecourseid' => $filter['corecourseid'], 'periodid' => $filter['periodid']]);
                        echo "<li>Clases con periodid={$filter['periodid']}: {$with_period}</li>";
                    }

                    if ($closed === $total) {
                        echo "<p><span class='badge badge-danger'>PROBLEMA</span> Todas las clases están cerradas (closed=1). El filtro excluye clases cerradas.</p>";
                    }
                }

                echo "</div>";
            } else {
                echo "<div class='success-box'>";
                echo "<p>✅ Este filtro retornó <strong>" . count($classes) . "</strong> clase(s)</p>";
                echo "</div>";

                echo "<table class='table-debug'>";
                echo "<thead><tr><th>Class ID</th><th>Name</th><th>Instructor ID</th><th>Period ID</th><th>Learning Plan ID</th></tr></thead>";
                echo "<tbody>";
                foreach ($classes as $class) {
                    echo "<tr>";
                    echo "<td>{$class->id}</td>";
                    echo "<td>{$class->name}</td>";
                    echo "<td>{$class->instructorid}</td>";
                    echo "<td>{$class->periodid}</td>";
                    echo "<td>{$class->learningplanid}</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            }
        }
    }

} catch (Exception $e) {
    echo "<div class='error-box'>";
    echo "<h3>❌ ERROR al construir filtros</h3>";
    echo "<p><strong>Mensaje:</strong> " . $e->getMessage() . "</p>";
    echo "</div>";
}

// ============================================================================
// STEP 6: Recommendations
// ============================================================================
echo "<h2>💡 PASO 6: Diagnóstico y Recomendaciones</h2>";

echo "<div class='info-box'>";
echo "<h3>Posibles Causas del Problema:</h3>";
echo "<ol>";
echo "<li><strong>ID Incorrecto:</strong> El courseId={$courseid} que viene del panel puede ser el ID incorrecto (debería ser corecourseid en lugar de courseid).</li>";
echo "<li><strong>Clases Cerradas:</strong> Todas las clases pueden tener closed=1, lo cual las excluye del listado.</li>";
echo "<li><strong>Mismatch de IDs:</strong> Puede haber inconsistencia entre courseid y corecourseid en gmk_class.</li>";
echo "<li><strong>Filtro de Períodos:</strong> El periodsid puede no coincidir con ninguna clase.</li>";
echo "</ol>";

echo "<h3>Soluciones Recomendadas:</h3>";
echo "<ul>";
echo "<li>Verificar que el panel de horarios esté pasando el <code>corecourseid</code> correcto</li>";
echo "<li>Revisar la función <code>construct_course_schedules_filter()</code> en locallib.php</li>";
echo "<li>Asegurarse de que las clases no estén marcadas como cerradas</li>";
echo "<li>Verificar que el periodid en gmk_class coincida con los períodos académicos</li>";
echo "</ul>";
echo "</div>";

?>

<div class="section">
    <h3>🔗 Enlaces Útiles</h3>
    <ul>
        <li><a href="schedulepanel.php">← Volver al Panel de Horarios</a></li>
        <li><a href="?id=<?php echo $courseid; ?>&periodsid=<?php echo $periodsid; ?>">🔄 Recargar Debug</a></li>
    </ul>
</div>

</div>

<?php
echo $OUTPUT->footer();
?>
