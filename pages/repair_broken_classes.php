<?php
/**
 * Repair script for gmk_class records missing corecourseid.
 * 
 * This script scans the gmk_class table for records where corecourseid is not set
 * and attempts to populate it by looking up the corresponding Moodle course ID.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Security check: Only admins should run this.
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

echo $OUTPUT->header();
echo $OUTPUT->heading("Reparación de Registros de Clase (gmk_class)");

$sql = "SELECT id, courseid, corecourseid, name FROM {gmk_class} WHERE corecourseid IS NULL OR corecourseid = 0";
$brokenClasses = $DB->get_records_sql($sql);

if (empty($brokenClasses)) {
    echo $OUTPUT->notification("No se encontraron registros corruptos. Todo parece estar en orden.", 'notifysuccess');
    echo $OUTPUT->footer();
    exit;
}

echo "<p>Se encontraron <strong>" . count($brokenClasses) . "</strong> registros con datos faltantes. Iniciando reparación...</p>";

$stats = [
    'success' => 0,
    'failed' => 0,
    'skipped' => 0
];

foreach ($brokenClasses as $class) {
    echo "<li>Procesando Clase ID: {$class->id} ({$class->name})... ";
    
    // Look up corecourseid from local_learning_courses
    $coreId = $DB->get_field('local_learning_courses', 'courseid', ['id' => $class->courseid], IGNORE_MISSING);
    
    if ($coreId) {
        $update = new stdClass();
        $update->id = $class->id;
        $update->corecourseid = $coreId;
        
        // Populate other missing fields if they are defaults
        // (We can't easily guess formatted times/timestamps here without re-parsing init/endtime, 
        // but corecourseid is the critical one for the 'invalidrecord' crash).
        
        if ($DB->update_record('gmk_class', $update)) {
            echo "<span style='color:green'>Reparado (CoreCourseID: $coreId)</span></li>";
            $stats['success']++;
        } else {
            echo "<span style='color:red'>Error al actualizar DB</span></li>";
            $stats['failed']++;
        }
    } else {
        echo "<span style='color:orange'>Omitido (No se encontró curso en local_learning_courses para ID {$class->courseid})</span></li>";
        $stats['skipped']++;
    }
}

echo "</ul>";
echo $OUTPUT->heading("Resumen de Reparación");
echo "<ul>";
echo "<li>Éxito: {$stats['success']}</li>";
echo "<li>Fallidos: {$stats['failed']}</li>";
echo "<li>Omitidos: {$stats['skipped']}</li>";
echo "</ul>";

if ($stats['success'] > 0) {
    echo $OUTPUT->notification("Se han reparado los registros críticos. Las páginas de Gestión de Clases y Panel de Horarios deberían funcionar ahora.", 'notifysuccess');
}

echo $OUTPUT->footer();
