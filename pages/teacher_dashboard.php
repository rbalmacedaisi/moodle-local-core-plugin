<?php
/**
 * Teacher Dashboard Entry Point
 * Created for Redesigning Teacher Experience
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();

// Ensure user is an instructor or admin
// Logic to check roles could be added here

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Dashboard del Docente');
$PAGE->set_heading('Dashboard del Docente');
$PAGE->set_pagelayout('embedded'); // Use embedded to have a cleaner canvas

// Required CSS for modern look
$PAGE->requires->css(new moodle_url('/local/grupomakro_core/styles/teacher_experience.css'));

// Required JS
$PAGE->requires->js_call_amd('local_grupomakro_core/vue_init', 'initTeacherDashboard', [
    'wwwroot' => $CFG->wwwroot,
    'userId' => $USER->id,
    'userToken' => $USER->sesskey // Or proper WS token if needed
]);

echo $OUTPUT->header();

// Vue App Mount Point
echo '<div id="teacher-app"></div>';

echo $OUTPUT->footer();
