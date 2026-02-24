<?php
define('NO_OUTPUT_BUFFERING', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$periodid = optional_param('periodid', 0, PARAM_INT);

echo $OUTPUT->header();
echo "<h1>Debug Omitir Auto</h1>";

if (!$periodid) {
    $periods = $DB->get_records('gmk_academic_periods', [], 'id DESC');
    echo "<h2>Selecciona un Periodo</h2><ul>";
    foreach ($periods as $p) {
        echo "<li><a href='?periodid={$p->id}'>{$p->name} (ID: {$p->id})</a></li>";
    }
    echo "</ul>";
} else {
    echo "<h2>Analizando Periodo ID: $periodid</h2>";
    
    // 1. Raw Data from Table
    $projections = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodid]);
    echo "<h3>Registros en gmk_academic_planning (" . count($projections) . ")</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Plan ID</th><th>Course ID</th><th>Status</th><th>Projected Students</th></tr>";
    $ignoredCount = 0;
    foreach ($projections as $p) {
        $style = ($p->status == 2) ? "background-color: #ffcccc;" : "";
        if ($p->status == 2) $ignoredCount++;
        echo "<tr style='$style'><td>{$p->learningplanid}</td><td>{$p->courseid}</td><td>{$p->status}</td><td>{$p->projected_students}</td></tr>";
    }
    echo "</table>";
    echo "<p>Total Omitidos (Status=2): $ignoredCount</p>";

    // 2. Planning Manager Data
    echo "<h3>Resultado de planning_manager::get_demand_data</h3>";
    $data = \local_grupomakro_core\local\planning_manager::get_demand_data($periodid);
    
    $tree = $data['demand_tree'];
    echo "<h4>Árbol de Demanda (Resumen de materias)</h4>";
    echo "<ul>";
    foreach ($tree as $career => $shifts) {
        echo "<li><strong>Carrera: $career</strong><ul>";
        foreach ($shifts as $shift => $levels) {
            echo "<li>Shift: $shift<ul>";
            foreach ($levels as $level => $lData) {
                echo "<li>Nivel: $level (Estudiantes: {$lData['student_count']})<ul>";
                foreach ($lData['course_counts'] as $courseId => $cData) {
                    $courseName = $DB->get_field('course', 'fullname', ['id' => $courseId]);
                    echo "<li>$courseName (ID: $courseId) - Cantidad: {$cData['count']}</li>";
                }
                echo "</ul></li>";
            }
            echo "</ul></li>";
        }
        echo "</ul></li>";
    }
    echo "</ul>";
}

echo $OUTPUT->footer();
