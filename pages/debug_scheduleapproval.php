<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('local/grupomakro_core:manage_debug', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_scheduleapproval.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Schedule Approval');
$PAGE->set_heading('Debug: Schedule Approval');
$PAGE->set_pagelayout('admin');

$courseid = optional_param('id', 0, PARAM_INT);
$periodsid = optional_param('periodsid', '', PARAM_RAW);
$auto = optional_param('auto', 0, PARAM_INT);

echo $OUTPUT->header();
?>

<style>
    .debug-container { max-width: 1600px; margin: 20px auto; font-family: monospace; }
    .section { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #007bff; }
    .info-box { background: #e7f3ff; padding: 15px; border-left: 4px solid #007bff; margin: 15px 0; }
    .warning-box { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; }
    .error-box { background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 15px 0; }
    .success-box { background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 15px 0; }
    .code-block { background: #f4f4f4; padding: 12px; border-left: 3px solid #007bff; margin: 10px 0; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
    .table-debug { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 12px; }
    .table-debug th { background: #343a40; color: white; padding: 10px; text-align: left; position: sticky; top: 0; }
    .table-debug td { padding: 8px; border-bottom: 1px solid #dee2e6; }
    .table-debug tr:hover { background: #f8f9fa; }
    .table-debug tr.clickable { cursor: pointer; }
    .table-debug tr.clickable:hover { background: #cfe2ff; }
    h2 { color: #007bff; margin-top: 30px; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
    h3 { color: #495057; margin-top: 20px; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; margin: 2px; display: inline-block; }
    .badge-success { background: #28a745; color: white; }
    .badge-danger { background: #dc3545; color: white; }
    .badge-warning { background: #ffc107; color: black; }
    .badge-info { background: #17a2b8; color: white; }
    .badge-secondary { background: #6c757d; color: white; }
    pre { background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; overflow-x: auto; font-size: 11px; max-height: 400px; }
    .collapsible { background: #007bff; color: white; cursor: pointer; padding: 10px; border: none; text-align: left; width: 100%; margin-top: 10px; }
    .collapsible:hover { background: #0056b3; }
    .collapsible-content { display: none; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 20px 0; }
    .summary-card { background: white; border: 1px solid #dee2e6; padding: 15px; border-radius: 5px; }
    .summary-card h4 { margin: 0 0 10px 0; color: #007bff; font-size: 14px; }
    .summary-card .number { font-size: 32px; font-weight: bold; color: #495057; }
    .summary-card .label { font-size: 12px; color: #6c757d; }
</style>

<script>
function toggleCollapsible(id) {
    var content = document.getElementById(id);
    if (content.style.display === "block") {
        content.style.display = "none";
    } else {
        content.style.display = "block";
    }
}
</script>

<div class="debug-container">
    <h1>🔍 Debug: Schedule Approval</h1>

    <div class="info-box">
        <strong>URL Actual:</strong> <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?><br>
        <strong>Course ID:</strong> <?php echo $courseid ?: 'Auto-detección activada'; ?><br>
        <strong>Periods ID:</strong> <?php echo $periodsid ?: 'N/A'; ?>
    </div>

<?php

// ============================================================================
// AUTO-DETECTION MODE: Find all courses from schedulepanel
// ============================================================================
if (empty($courseid) || $auto == 1) {
    echo "<h2>🔎 MODO AUTO-DETECCIÓN: Escaneando Sistema</h2>";

    // Get all courses from the schedule overview
    try {
        $params = [
            'learningPlanId' => null,
            'periodIds' => null,
            'courseId' => null
        ];

        $overview = get_class_schedules_overview($params);

        if (empty($overview)) {
            echo "<div class='warning-box'>";
            echo "<h3>⚠️ No se encontraron cursos en el sistema</h3>";
            echo "<p>La función get_class_schedules_overview() retornó un array vacío.</p>";
            echo "</div>";
        } else {
            echo "<div class='success-box'>";
            echo "<p>✅ Se encontraron <strong>" . count($overview) . "</strong> cursos en el panel de horarios</p>";
            echo "</div>";

            // Display summary
            echo "<div class='summary-grid'>";

            $totalClasses = 0;
            $totalStudents = 0;
            $coursesWithIssues = 0;

            foreach ($overview as $course) {
                $totalClasses += $course->numberOfClasses;
                $totalStudents += $course->totalParticipants;
                if ($course->numberOfClasses == 0) {
                    $coursesWithIssues++;
                }
            }

            echo "<div class='summary-card'>";
            echo "<h4>Total Cursos</h4>";
            echo "<div class='number'>" . count($overview) . "</div>";
            echo "<div class='label'>En el sistema</div>";
            echo "</div>";

            echo "<div class='summary-card'>";
            echo "<h4>Total Clases</h4>";
            echo "<div class='number'>{$totalClasses}</div>";
            echo "<div class='label'>Secciones programadas</div>";
            echo "</div>";

            echo "<div class='summary-card'>";
            echo "<h4>Total Estudiantes</h4>";
            echo "<div class='number'>{$totalStudents}</div>";
            echo "<div class='label'>Pre-registrados + En cola</div>";
            echo "</div>";

            echo "<div class='summary-card'>";
            echo "<h4>Cursos con Problemas</h4>";
            echo "<div class='number' style='color: " . ($coursesWithIssues > 0 ? '#dc3545' : '#28a745') . "'>{$coursesWithIssues}</div>";
            echo "<div class='label'>Sin clases asignadas</div>";
            echo "</div>";

            echo "</div>";

            // Display table
            echo "<h3>📋 Listado de Cursos Detectados</h3>";
            echo "<p><small>Click en una fila para ver el debug completo de ese curso</small></p>";
            echo "<table class='table-debug'>";
            echo "<thead>";
            echo "<tr>";
            echo "<th>Course ID</th>";
            echo "<th>Nombre del Curso</th>";
            echo "<th>Períodos</th>";
            echo "<th>Planes</th>";
            echo "<th># Clases</th>";
            echo "<th>Estudiantes</th>";
            echo "<th>Capacidad</th>";
            echo "<th>Estado</th>";
            echo "<th>Acción</th>";
            echo "</tr>";
            echo "</thead>";
            echo "<tbody>";

            foreach ($overview as $course) {
                $status_badge = '';
                if ($course->numberOfClasses == 0) {
                    $status_badge = "<span class='badge badge-danger'>Sin clases</span>";
                } elseif ($course->remainingCapacity <= 0) {
                    $status_badge = "<span class='badge badge-warning'>Lleno</span>";
                } else {
                    $status_badge = "<span class='badge badge-success'>OK</span>";
                }

                $tc_badge = $course->tc ? "<span class='badge badge-info'>TC</span>" : "";

                echo "<tr class='clickable' onclick=\"window.location.href='?id={$course->courseId}&periodsid={$course->periodIds}'\">";
                echo "<td><strong>{$course->courseId}</strong></td>";
                echo "<td>{$course->courseName} {$tc_badge}</td>";
                echo "<td><small>{$course->periodNames}</small></td>";
                echo "<td><small>{$course->learningPlanNames}</small></td>";
                echo "<td>{$course->numberOfClasses}</td>";
                echo "<td>{$course->totalParticipants}</td>";
                echo "<td>{$course->totalCapacity} ({$course->remainingCapacity} libre)</td>";
                echo "<td>{$status_badge}</td>";
                echo "<td><a href='?id={$course->courseId}&periodsid={$course->periodIds}' style='text-decoration:none'>🔍 Debug</a></td>";
                echo "</tr>";
            }

            echo "</tbody>";
            echo "</table>";
        }

    } catch (Exception $e) {
        echo "<div class='error-box'>";
        echo "<h3>❌ Error al escanear el sistema</h3>";
        echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
        echo "</div>";
    }

    if (empty($courseid)) {
        echo "<div class='info-box'>";
        echo "<h3>💡 Para analizar un curso específico</h3>";
        echo "<p>Haz click en cualquier fila de la tabla o usa la URL: <code>?id=COURSE_ID&periodsid=PERIOD_IDS</code></p>";
        echo "</div>";

        echo "</div>";
        echo $OUTPUT->footer();
        exit;
    }
}

echo "<hr style='margin: 40px 0; border: 2px solid #007bff;'>";
echo "<h2>🎯 ANÁLISIS DETALLADO DEL CURSO ID: {$courseid}</h2>";

// ============================================================================
// STEP 1: Verify Course Exists
// ============================================================================
echo "<button class='collapsible' onclick='toggleCollapsible(\"step1\")'>📋 PASO 1: Verificar que el Curso Existe</button>";
echo "<div id='step1' class='collapsible-content' style='display: block;'>";

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
    echo "<p>Este es el problema principal. El ID que viene del panel de horarios no corresponde a un curso válido en Moodle.</p>";
    echo "</div>";
}

echo "</div>";

// ============================================================================
// STEP 2: Check local_learning_courses
// ============================================================================
echo "<button class='collapsible' onclick='toggleCollapsible(\"step2\")'>📚 PASO 2: Buscar en local_learning_courses (Subject Records)</button>";
echo "<div id='step2' class='collapsible-content'>";

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

echo "</div>";

// ============================================================================
// STEP 3: Check gmk_class table
// ============================================================================
echo "<button class='collapsible' onclick='toggleCollapsible(\"step3\")'>🏫 PASO 3: Buscar Clases en gmk_class</button>";
echo "<div id='step3' class='collapsible-content'>";

$classes_by_courseid = $DB->get_records('gmk_class', ['courseid' => $courseid]);
$classes_by_corecourseid = $DB->get_records('gmk_class', ['corecourseid' => $courseid]);
$all_classes_for_course = array_merge($classes_by_courseid, $classes_by_corecourseid);

echo "<h3>Búsqueda por courseid = {$courseid}</h3>";
if ($classes_by_courseid) {
    echo "<div class='success-box'>";
    echo "<p>✅ Encontradas <strong>" . count($classes_by_courseid) . "</strong> clases</p>";
    echo "</div>";
    display_classes_table($classes_by_courseid);
} else {
    echo "<div class='warning-box'><p>⚠️ No se encontraron clases con courseid = {$courseid}</p></div>";
}

echo "<h3>Búsqueda por corecourseid = {$courseid}</h3>";
if ($classes_by_corecourseid) {
    echo "<div class='success-box'>";
    echo "<p>✅ Encontradas <strong>" . count($classes_by_corecourseid) . "</strong> clases</p>";
    echo "</div>";
    display_classes_table($classes_by_corecourseid);
} else {
    echo "<div class='warning-box'><p>⚠️ No se encontraron clases con corecourseid = {$courseid}</p></div>";
}

echo "</div>";

// ============================================================================
// STEP 4: Check Pre-registrations and Queue
// ============================================================================
echo "<button class='collapsible' onclick='toggleCollapsible(\"step4\")'>👥 PASO 4: Verificar Pre-registros y Colas</button>";
echo "<div id='step4' class='collapsible-content'>";

if (!empty($all_classes_for_course)) {
    $class_ids = array_keys($all_classes_for_course);
    list($in_sql, $in_params) = $DB->get_in_or_equal($class_ids, SQL_PARAMS_NAMED);

    $prereg_sql = "SELECT * FROM {gmk_class_pre_registration} WHERE classid $in_sql";
    $preregistrations = $DB->get_records_sql($prereg_sql, $in_params);

    $queue_sql = "SELECT * FROM {gmk_class_queue} WHERE classid $in_sql";
    $queue_students = $DB->get_records_sql($queue_sql, $in_params);

    echo "<h3>Pre-registros</h3>";
    if ($preregistrations) {
        echo "<div class='success-box'>";
        echo "<p>✅ Se encontraron <strong>" . count($preregistrations) . "</strong> pre-registros</p>";
        echo "</div>";

        echo "<table class='table-debug'>";
        echo "<thead><tr><th>ID</th><th>User ID</th><th>Class ID</th><th>Course ID</th><th>Fecha</th></tr></thead>";
        echo "<tbody>";
        foreach (array_slice($preregistrations, 0, 10) as $prereg) {
            echo "<tr>";
            echo "<td>{$prereg->id}</td>";
            echo "<td>{$prereg->userid}</td>";
            echo "<td>{$prereg->classid}</td>";
            echo "<td>{$prereg->courseid}</td>";
            echo "<td>" . date('Y-m-d H:i', $prereg->timecreated) . "</td>";
            echo "</tr>";
        }
        if (count($preregistrations) > 10) {
            echo "<tr><td colspan='5'><em>... y " . (count($preregistrations) - 10) . " más</em></td></tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<div class='warning-box'><p>⚠️ No hay pre-registros para estas clases</p></div>";
    }

    echo "<h3>Estudiantes en Cola</h3>";
    if ($queue_students) {
        echo "<div class='success-box'>";
        echo "<p>✅ Se encontraron <strong>" . count($queue_students) . "</strong> estudiantes en cola</p>";
        echo "</div>";

        echo "<table class='table-debug'>";
        echo "<thead><tr><th>ID</th><th>User ID</th><th>Class ID</th><th>Course ID</th><th>Fecha</th></tr></thead>";
        echo "<tbody>";
        foreach (array_slice($queue_students, 0, 10) as $queue) {
            echo "<tr>";
            echo "<td>{$queue->id}</td>";
            echo "<td>{$queue->userid}</td>";
            echo "<td>{$queue->classid}</td>";
            echo "<td>{$queue->courseid}</td>";
            echo "<td>" . date('Y-m-d H:i', $queue->timecreated) . "</td>";
            echo "</tr>";
        }
        if (count($queue_students) > 10) {
            echo "<tr><td colspan='5'><em>... y " . (count($queue_students) - 10) . " más</em></td></tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<div class='info-box'><p>ℹ️ No hay estudiantes en cola</p></div>";
    }
} else {
    echo "<div class='warning-box'>";
    echo "<p>⚠️ No se pueden verificar pre-registros porque no se encontraron clases</p>";
    echo "</div>";
}

echo "</div>";

// ============================================================================
// STEP 5: Simulate Web Service Call
// ============================================================================
echo "<button class='collapsible' onclick='toggleCollapsible(\"step5\")'>🔌 PASO 5: Simular Llamada al Web Service</button>";
echo "<div id='step5' class='collapsible-content'>";

echo "<div class='info-box'>";
echo "<p>El componente Vue llama a: <code>local_grupomakro_get_course_class_schedules</code></p>";
echo "<p>Con parámetros:</p>";
echo "<ul>";
echo "<li><strong>courseId:</strong> {$courseid}</li>";
echo "<li><strong>periodIds:</strong> {$periodsid}</li>";
echo "</ul>";
echo "</div>";

try {
    $params = [
        'courseId' => $courseid,
        'periodIds' => $periodsid,
        'learningPlanId' => null
    ];

    echo "<div class='code-block'>";
    echo "Parámetros simulados:\n";
    echo print_r($params, true);
    echo "</div>";

    echo "<h3>Llamando a get_learning_plan_course_schedules()...</h3>";
    $result = get_learning_plan_course_schedules($params);

    if (empty($result)) {
        echo "<div class='error-box'>";
        echo "<h3>❌ ERROR: La función retornó un array vacío</h3>";
        echo "<p><strong>Esto explica por qué no se muestra nada en scheduleapproval.php</strong></p>";
        echo "</div>";
    } else {
        echo "<div class='success-box'>";
        echo "<h3>✅ La función retornó datos</h3>";
        echo "<p>Se encontraron <strong>" . count($result) . "</strong> registros agrupados</p>";
        echo "</div>";

        echo "<h3>Resultado Detallado:</h3>";
        foreach ($result as $idx => $item) {
            echo "<h4>Grupo #{$idx}: {$item->courseName}</h4>";
            echo "<div class='code-block'>";
            echo "courseId: {$item->courseId}\n";
            echo "periodNames: {$item->periodNames}\n";
            echo "periodIds: {$item->periodIds}\n";
            echo "learningPlanNames: {$item->learningPlanNames}\n";
            echo "Número de schedules: " . count($item->schedules) . "\n";
            echo "</div>";

            if (!empty($item->schedules)) {
                echo "<table class='table-debug'>";
                echo "<thead><tr><th>Class ID</th><th>Instructor ID</th><th>Classroom</th><th>Approved</th><th>Closed</th><th>Days</th></tr></thead>";
                echo "<tbody>";
                foreach ($item->schedules as $schedule) {
                    echo "<tr>";
                    echo "<td>{$schedule->id}</td>";
                    echo "<td>{$schedule->instructorid}</td>";
                    echo "<td>" . ($schedule->classroomid ?: 'N/A') . "</td>";
                    echo "<td>" . ($schedule->approved ? '✅' : '❌') . "</td>";
                    echo "<td>" . ($schedule->closed ? '🔒' : '🔓') . "</td>";
                    echo "<td>{$schedule->classdays}</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            }
        }
    }

} catch (Exception $e) {
    echo "<div class='error-box'>";
    echo "<h3>❌ ERROR al ejecutar la función</h3>";
    echo "<p><strong>Mensaje:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>Archivo:</strong> " . $e->getFile() . ":" . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "</div>";

// ============================================================================
// STEP 6: Analyze Filters
// ============================================================================
echo "<button class='collapsible' onclick='toggleCollapsible(\"step6\")'>🔍 PASO 6: Analizar Construcción de Filtros</button>";
echo "<div id='step6' class='collapsible-content'>";

try {
    $params = [
        'courseId' => $courseid,
        'periodIds' => $periodsid,
        'learningPlanId' => null
    ];

    $filters = construct_course_schedules_filter($params);

    echo "<div class='code-block'>";
    echo "Filtros construidos:\n";
    echo print_r($filters, true);
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

        foreach ($filters as $idx => $filter) {
            echo "<h4>Filtro #" . ($idx + 1) . ":</h4>";
            echo "<div class='code-block'>";
            echo print_r($filter, true);
            echo "</div>";

            $classes = list_classes($filter);

            if (empty($classes)) {
                echo "<div class='warning-box'>";
                echo "<p>⚠️ Este filtro no retornó clases</p>";

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

                    if ($closed === $total && $total > 0) {
                        echo "<p><span class='badge badge-danger'>PROBLEMA IDENTIFICADO</span> Todas las clases están cerradas (closed=1). El filtro en list_classes() excluye clases cerradas por defecto.</p>";
                    }
                }

                echo "</div>";
            } else {
                echo "<div class='success-box'>";
                echo "<p>✅ Este filtro retornó <strong>" . count($classes) . "</strong> clase(s)</p>";
                echo "</div>";
                display_classes_table($classes);
            }
        }
    }

} catch (Exception $e) {
    echo "<div class='error-box'>";
    echo "<h3>❌ ERROR al construir filtros</h3>";
    echo "<p><strong>Mensaje:</strong> " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "</div>";

// ============================================================================
// STEP 7: Recommendations
// ============================================================================
echo "<button class='collapsible' onclick='toggleCollapsible(\"step7\")'>💡 PASO 7: Diagnóstico y Recomendaciones</button>";
echo "<div id='step7' class='collapsible-content' style='display: block;'>";

echo "<div class='info-box'>";
echo "<h3>Posibles Causas del Problema:</h3>";
echo "<ol>";
echo "<li><strong>ID Incorrecto:</strong> El courseId={$courseid} puede ser el ID incorrecto (debería ser corecourseid).</li>";
echo "<li><strong>Clases Cerradas:</strong> Las clases tienen closed=1, lo cual las excluye del listado.</li>";
echo "<li><strong>Mismatch de IDs:</strong> Inconsistencia entre courseid y corecourseid en gmk_class.</li>";
echo "<li><strong>Filtro de Períodos:</strong> El periodsid no coincide con ninguna clase.</li>";
echo "<li><strong>Problema en list_classes():</strong> La función excluye las clases por algún criterio (closed, approved, etc.).</li>";
echo "</ol>";

echo "<h3>Soluciones Recomendadas:</h3>";
echo "<ul>";
echo "<li>✅ Verificar que schedulepanel.php esté retornando el <code>corecourseid</code> correcto</li>";
echo "<li>✅ Revisar si las clases deben estar marcadas como closed=0 para aparecer</li>";
echo "<li>✅ Verificar la lógica de filtrado en <code>list_classes()</code> en locallib.php</li>";
echo "<li>✅ Asegurarse de que periodid coincida entre gmk_class y los períodos académicos</li>";
echo "</ul>";
echo "</div>";

echo "</div>";

?>

<div class="section">
    <h3>🔗 Enlaces Útiles</h3>
    <ul>
        <li><a href="schedulepanel.php">← Volver al Panel de Horarios</a></li>
        <li><a href="?auto=1">🔍 Escanear Todos los Cursos</a></li>
        <li><a href="?id=<?php echo $courseid; ?>&periodsid=<?php echo $periodsid; ?>">🔄 Recargar Debug de Este Curso</a></li>
    </ul>
</div>

</div>

<?php

// Helper function to display classes table
function display_classes_table($classes) {
    echo "<table class='table-debug'>";
    echo "<thead><tr><th>Class ID</th><th>Name</th><th>courseid</th><th>corecourseid</th><th>learningplanid</th><th>periodid</th><th>instructorid</th><th>approved</th><th>closed</th></tr></thead>";
    echo "<tbody>";
    foreach ($classes as $class) {
        $approved_badge = $class->approved ? "<span class='badge badge-success'>Aprobado</span>" : "<span class='badge badge-warning'>Pendiente</span>";
        $closed_badge = $class->closed ? "<span class='badge badge-danger'>Cerrado</span>" : "<span class='badge badge-success'>Abierto</span>";
        echo "<tr>";
        echo "<td>{$class->id}</td>";
        echo "<td>{$class->name}</td>";
        echo "<td>{$class->courseid}</td>";
        echo "<td>" . ($class->corecourseid ?: 'NULL') . "</td>";
        echo "<td>{$class->learningplanid}</td>";
        echo "<td>{$class->periodid}</td>";
        echo "<td>{$class->instructorid}</td>";
        echo "<td>{$approved_badge}</td>";
        echo "<td>{$closed_badge}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

echo $OUTPUT->footer();
?>
