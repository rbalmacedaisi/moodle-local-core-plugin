<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

echo $OUTPUT->header();
echo "<h1>Diagnóstico de Periodos Académicos</h1>";

echo "<h2>Tabla: mdl_gmk_academic_periods</h2>";
$records1 = $DB->get_records('gmk_academic_periods', null, 'id DESC', '*', 0, 10);
if ($records1) {
    echo "<pre>" . print_r($records1, true) . "</pre>";
} else {
    echo "<p>No hay registros o la tabla no existe.</p>";
}

echo "<h2>Tabla: mdl_local_learning_periods</h2>";
$records2 = $DB->get_records('local_learning_periods', null, 'id DESC', '*', 0, 10);
if ($records2) {
    echo "<pre>" . print_r($records2, true) . "</pre>";
} else {
    echo "<p>No hay registros o la tabla no existe.</p>";
}

echo "<h2>Tabla: mdl_gmk_academic_calendar</h2>";
$records3 = $DB->get_records('gmk_academic_calendar', null, 'id DESC', '*', 0, 10);
if ($records3) {
    echo "<pre>" . print_r($records3, true) . "</pre>";
} else {
    echo "<p>No hay registros o la tabla no existe.</p>";
}

echo $OUTPUT->footer();
