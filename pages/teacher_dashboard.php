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
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/TeacherDashboard.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/ActivityCreationWizard.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/ManageClass.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/studenttable.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/TeacherStudentTable.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/GradesGrid.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/grademodal.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/PendingGradingView.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/QuickGrader.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/QuizCreationWizard.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/QuizEditor.js?v=20251231036'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/AttendancePanel.js?v=20251231036'), true);

// Load main experience module as standard JS (bypassing AMD build issues)
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/amd/src/teacher_experience.js?v=20251231036'), true);

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

    // Localized strings for the JS app
    'strings' => [
        'name' => get_string('fullname', 'local_grupomakro_core'),
        'email' => get_string('email', 'local_grupomakro_core'),
        'document' => get_string('identification_number', 'local_grupomakro_core'),
        'career' => get_string('careers', 'local_grupomakro_core'),
        'period' => get_string('class_period', 'local_grupomakro_core'),
        'status' => get_string('state', 'local_grupomakro_core'),
        'options' => get_string('options', 'local_grupomakro_core'),
        'search' => get_string('search', 'local_grupomakro_core'),
        'sync' => get_string('generate', 'local_grupomakro_core'), // Using generate for sync
        'students_list' => get_string('students_list', 'local_grupomakro_core'),
        'active_users' => get_string('active_users', 'local_grupomakro_core'),
        // Safer retrieval for potentially cached new strings
        'active_users' => get_string_manager()->string_exists('active_users', 'local_grupomakro_core') ? get_string('active_users', 'local_grupomakro_core') : 'Usuarios activos',
        'active_students' => get_string_manager()->string_exists('active_students', 'local_grupomakro_core') ? get_string('active_students', 'local_grupomakro_core') : 'Estudiantes activos',
        'active_courses' => get_string_manager()->string_exists('active_courses', 'local_grupomakro_core') ? get_string('active_courses', 'local_grupomakro_core') : 'Cursos activos',
        'my_active_classes' => get_string_manager()->string_exists('my_active_classes', 'local_grupomakro_core') ? get_string('my_active_classes', 'local_grupomakro_core') : 'Mis clases activas',
        'pending_tasks' => get_string_manager()->string_exists('pending_tasks', 'local_grupomakro_core') ? get_string('pending_tasks', 'local_grupomakro_core') : 'Tareas pendientes',
        'next_session' => get_string_manager()->string_exists('next_session', 'local_grupomakro_core') ? get_string('next_session', 'local_grupomakro_core') : 'Siguiente sesión',
        'no_groups' => get_string_manager()->string_exists('no_groups', 'local_grupomakro_core') ? get_string('no_groups', 'local_grupomakro_core') : 'No hay grupos asignados',
    ]
];
$PAGE->requires->js_init_code("if(window.TeacherExperience) { window.TeacherExperience.init(".json_encode($config)."); }");

echo $OUTPUT->header();

// Vue App Mount Point wrapped in a Moodle-friendly container
echo '<div class="local_grupomakro_core_dashboard_wrapper">';
echo '<div id="teacher-app"></div>';
echo '</div>';

echo $OUTPUT->footer();
