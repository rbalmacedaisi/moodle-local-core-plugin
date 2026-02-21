<?php
// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../../config.php';
}
require_once($config_path);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/fix_db.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Corrección de Base de Datos');

echo $OUTPUT->header();

global $DB;
echo "<h2>Aplicando Correcciones a la Base de Datos</h2>";

echo "<ul>";
try {
    $DB->execute("ALTER TABLE {gmk_class_queue} MODIFY COLUMN userid VARCHAR(255)");
    echo "<li>✅ gmk_class_queue: <code>userid</code> modificado a VARCHAR(255).</li>";
} catch (Exception $e) {
    echo "<li>❌ Error en gmk_class_queue: " . $e->getMessage() . "</li>";
}

try {
    $DB->execute("ALTER TABLE {gmk_class_pre_registration} MODIFY COLUMN userid VARCHAR(255)");
    echo "<li>✅ gmk_class_pre_registration: <code>userid</code> modificado a VARCHAR(255).</li>";
} catch (Exception $e) {
    echo "<li>❌ Error en gmk_class_pre_registration: " . $e->getMessage() . "</li>";
}

try {
    $DB->execute("ALTER TABLE {gmk_class} MODIFY COLUMN level_label TEXT");
    echo "<li>✅ gmk_class: <code>level_label</code> modificado a TEXT.</li>";
} catch (Exception $e) {
    echo "<li>❌ Error en gmk_class: " . $e->getMessage() . "</li>";
}

try {
    $DB->execute("ALTER TABLE {gmk_class} MODIFY COLUMN career_label TEXT");
    echo "<li>✅ gmk_class: <code>career_label</code> modificado a TEXT.</li>";
} catch (Exception $e) {
    echo "<li>❌ Error en gmk_class: " . $e->getMessage() . "</li>";
}
echo "</ul>";

echo "<h3>¡Listo! La base de datos ahora puede guardar textos largos.</h3>";
echo "<p>Vuelve al planificador e intenta <strong>Guardar Cambios</strong> de nuevo.</p>";

echo $OUTPUT->footer();
