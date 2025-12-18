<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Optional Autoload
if (file_exists($CFG->dirroot . '/vendor/autoload.php')) {
    require_once($CFG->dirroot . '/vendor/autoload.php');
}

// Permissions
admin_externalpage_setup('grupomakro_core_manage_courses');

$PAGE->set_url('/local/grupomakro_core/pages/manage_courses.php');
$PAGE->set_title('Gestión de Cursos Moderna');
$PAGE->set_heading('Gestor Avanzado de Cursos');
$PAGE->requires->jquery();

echo $OUTPUT->header();

echo "<h1>GMK DEBUG: HEADERS LOADED OK</h1>";

echo $OUTPUT->footer();
