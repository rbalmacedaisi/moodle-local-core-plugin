<?php
require_once('../../../config.php');
require_login();

// Restricted to admins or managers usually, but keeping it simple for debug
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/grupomakro_core/pages/debug_class_mapping.php');
$PAGE->set_title('Depurador de Clases Huérfanas');
$PAGE->set_heading('Depurador de Clases Huérfanas (Sin Periodo Válido)');

$action = optional_param('action', '', PARAM_TEXT);
$classid = optional_param('classid', 0, PARAM_INT);

echo $OUTPUT->header();

if ($action === 'delete' && $classid) {
    // Delete orphan class and its relations
    $DB->delete_records('gmk_class_queue', ['classid' => $classid]);
    $DB->delete_records('gmk_class_schedules', ['classid' => $classid]);
    $DB->delete_records('gmk_class', ['id' => $classid]);
    echo $OUTPUT->notification("Clase $classid eliminada correctamente.", 'success');
}

if ($action === 'delete_all') {
    $sql = "SELECT c.id FROM {gmk_class} c
            LEFT JOIN {local_learning_periods} p ON c.periodid = p.id
            WHERE p.id IS NULL OR c.periodid = 0";
    $orphans = $DB->get_records_sql($sql);
    $count = 0;
    foreach ($orphans as $orphan) {
        $DB->delete_records('gmk_class_queue', ['classid' => $orphan->id]);
        $DB->delete_records('gmk_class_schedules', ['classid' => $orphan->id]);
        $DB->delete_records('gmk_class', ['id' => $orphan->id]);
        $count++;
    }
    echo $OUTPUT->notification("$count clases huérfanas eliminadas correctamente.", 'success');
}

// Find orphans based on periodid
$sql = "SELECT c.id, c.name, c.periodid, c.gradecategoryid, c.courseid, c.learningplanid, c.inittime, c.endtime, c.classdays
        FROM {gmk_class} c
        LEFT JOIN {local_learning_periods} p ON c.periodid = p.id
        WHERE p.id IS NULL OR c.periodid = 0";

$orphans = $DB->get_records_sql($sql);

echo html_writer::tag('p', 'Esta página identifica las clases en la tabla <code>gmk_class</code> cuyo <code>periodid</code> es 0 o no existe en la tabla <code>local_learning_periods</code>.');

if (empty($orphans)) {
    echo $OUTPUT->notification('No se encontraron clases huérfanas en la base de datos.', 'success');
} else {
    echo html_writer::tag('h3', 'Clases Huérfanas Encontradas: ' . count($orphans));
    
    // Delete all button
    $deleteallurl = new moodle_url('/local/grupomakro_core/pages/debug_class_mapping.php', ['action' => 'delete_all']);
    echo html_writer::tag('p', html_writer::link($deleteallurl, 'Eliminar todas las clases huérfanas', ['class' => 'btn btn-danger', 'onclick' => 'return confirm("¿Estás seguro de eliminar todas las clases listadas a continuación y sus asociaciones?");']));

    $table = new html_table();
    $table->attributes['class'] = 'generaltable table table-striped table-bordered';
    $table->head = ['ID Clase', 'Nombre', 'P. Académico ID (Level)', 'P. Institucional (Cat)', 'Course ID', 'Plan ID', 'Horario', 'Días', 'Acciones'];
    $table->data = [];

    foreach ($orphans as $orphan) {
        $deleteurl = new moodle_url('/local/grupomakro_core/pages/debug_class_mapping.php', ['action' => 'delete', 'classid' => $orphan->id]);
        $deletebtn = html_writer::link($deleteurl, 'Eliminar', ['class' => 'btn btn-sm btn-danger', 'onclick' => 'return confirm("¿Confirmas la eliminación de esta clase?");']);
        
        $table->data[] = [
            $orphan->id,
            $orphan->name,
            $orphan->periodid,
            $orphan->gradecategoryid,
            $orphan->courseid,
            $orphan->learningplanid,
            $orphan->inittime . ' - ' . $orphan->endtime,
            $orphan->classdays,
            $deletebtn
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
