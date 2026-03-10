<?php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/check_webservices.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Check Web Services');
echo $OUTPUT->header();

$toCheck = [
    'local_grupomakro_get_course_announcements',
    'local_grupomakro_get_student_gradebook',
];

echo '<h2>Estado de Web Services</h2>';
echo '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-family:sans-serif;">';
echo '<tr style="background:#1a73e8;color:white;"><th>Web Service</th><th>Registrado</th></tr>';

foreach ($toCheck as $fn) {
    $exists = $DB->record_exists('external_functions', ['name' => $fn]);
    $color  = $exists ? '#dfd' : '#fde';
    $label  = $exists ? '✅ SÍ' : '❌ NO — ejecuta /admin/index.php para actualizar';
    echo "<tr style='background:$color;'><td>$fn</td><td>$label</td></tr>";
}

echo '</table>';
echo $OUTPUT->footer();
