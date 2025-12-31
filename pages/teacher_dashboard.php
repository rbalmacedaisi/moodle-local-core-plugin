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
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('gmk-full-frame');

// Required CSS for modern look
$PAGE->requires->css(new moodle_url('/local/grupomakro_core/styles/teacher_experience.css'));
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css'));
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css'));

// Load base libraries via CDN (Same as import_grades.php)
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js'), true);
$PAGE->requires->js(new moodle_url('https://unpkg.com/axios/dist/axios.min.js'), true);

// Load components (Standard JS files)
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/TeacherDashboard.js'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/ManageClass.js'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/ActivityCreationWizard.js'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/studenttable.js'), true);

// Load main experience module as standard JS (bypassing AMD build issues)
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/amd/src/teacher_experience.js'), true);

// Initialize the experience
$logoUrl = $OUTPUT->get_logo_url();
if (!$logoUrl) {
    try {
        $theme = theme_config::load($CFG->theme);
        if (isset($theme->settings->logo) && !empty($theme->settings->logo)) {
            $logo = basename($theme->settings->logo);
            $logoUrl = new moodle_url('/theme/' . $CFG->theme . '/pix/static/' . $logo);
        }
    } catch (Exception $e) {
        // Silently fail and use placeholder
    }
}
$config = [
    'wwwroot' => $CFG->wwwroot,
    'userId' => $USER->id,
    'userToken' => $USER->sesskey,
    'logoutUrl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false),
    'logoUrl' => ($logoUrl instanceof moodle_url) ? $logoUrl->out(false) : 'https://images.unsplash.com/photo-1546410531-bb4caa6b424d?q=80&w=200', // Placeholder if no theme logo
];
$PAGE->requires->js_init_code("if(window.TeacherExperience) { window.TeacherExperience.init(".json_encode($config)."); }");

echo $OUTPUT->header();

// Vue App Mount Point wrapped in a Moodle-friendly container
echo '<div class="local_grupomakro_core_dashboard_wrapper">';
echo '<div id="teacher-app"></div>';
echo '</div>';

echo $OUTPUT->footer();
