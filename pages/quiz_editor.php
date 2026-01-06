<?php
/**
 * Custom Quiz Editor Page
 * Allows teachers to manage questions without accessing the blocked standard interface.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$cmid = required_param('cmid', PARAM_INT);

require_login();

// Validate access standard Moodle way
$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

// Check standard capability FIRST
if (!has_capability('mod/quiz:manage', $context)) {
    // Fallback: Check if user is the instructor of the class linked to this course
    // This supports the custom plugin's permission model if it differs from Moodle roles.
    $is_gmk_instructor = $DB->record_exists('gmk_class', ['corecourseid' => $course->id, 'instructorid' => $USER->id, 'closed' => 0]);
    
    if (!$is_gmk_instructor) {
        require_capability('mod/quiz:manage', $context); // This will throw the exception if fallback fails
    }
}

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/quiz_editor.php', ['cmid' => $cmid]));
$PAGE->set_context(context_module::instance($cm->id));
$PAGE->set_title('Gestor de Preguntas del Cuestionario');
$PAGE->set_heading('Gestor de Preguntas del Cuestionario');
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('gmk-full-frame'); // Force full screen mode

// Assets
$PAGE->requires->css(new moodle_url('/local/grupomakro_core/styles/teacher_experience.css'));
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css'));
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js'), true);
$PAGE->requires->js(new moodle_url('https://unpkg.com/axios/dist/axios.min.js'), true);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/QuizEditor.js'), true);

// Pass config to JS
$config = [
    'cmid' => $cmid,
    'quizid' => $cm->instance,
    'courseid' => $course->id,
    'wwwroot' => $CFG->wwwroot,
    'sesskey' => sesskey()
];
$PAGE->requires->js_init_code("if(window.QuizEditorApp) { window.QuizEditorApp.init(".json_encode($config)."); }");

echo $OUTPUT->header();

echo '<div class="local_grupomakro_core_dashboard_wrapper">';
echo '<div id="quiz-editor-app">
    <v-app>
        <v-main>
            <v-container>
                <quiz-editor :config="initialConfig"></quiz-editor>
            </v-container>
        </v-main>
    </v-app>
</div>';
echo '</div>';

echo $OUTPUT->footer();
