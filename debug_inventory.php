<?php
require_once(__DIR__ . '/../../config.php');

global $DB, $PAGE, $OUTPUT;

$PAGE->set_url(new moodle_url('/local/grupomakro_core/debug_inventory.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Inventario de Moodle - Fase 1');

echo $OUTPUT->header();
echo $OUTPUT->heading('Inventario de Actividades y Preguntas');

echo '<h3>Actividades (Módulos) Instaladas</h3>';
echo '<ul>';
$modules = $DB->get_records('modules', ['visible' => 1], 'name ASC');
foreach ($modules as $m) {
    try {
        $label = get_string('modulename', $m->name);
    } catch (Exception $e) {
        $label = $m->name;
    }
    echo "<li><strong>{$m->name}</strong>: {$label}</li>";
}
echo '</ul>';

echo '<h3>Tipos de Pregunta Instalados</h3>';
echo '<ul>';
$qtypes = core_component::get_plugin_list('qtype');
foreach ($qtypes as $name => $path) {
    try {
        $label = get_string('pluginname', 'qtype_' . $name);
    } catch (Exception $e) {
        $label = $name;
    }
    echo "<li><strong>{$name}</strong>: {$label}</li>";
}
echo '</ul>';

echo $OUTPUT->footer();
