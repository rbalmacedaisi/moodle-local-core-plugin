<?php
// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../config.php';
}
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../../config.php';
}
require_once($config_path);
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

// Security Check
require_login();

// Find a course where user is instructor (has quiz manage cap)
// Find a course where user is instructor (has quiz manage cap OR is gmk instructor)
$valid_course = null;
global $DB;
$courses = enrol_get_users_courses($USER->id, true, '*');

foreach ($courses as $c) {
    $ctx = context_course::instance($c->id);
    $is_standard_manager = has_capability('mod/quiz:manage', $ctx);
    $is_gmk_instructor = $DB->record_exists('gmk_class', ['corecourseid' => $c->id, 'instructorid' => $USER->id]);

    if ($is_standard_manager || $is_gmk_instructor) {
        $valid_course = $c;
        $context = $ctx;
        break;
    }
}

if (!$valid_course) {
    // Fallback to system if admin, otherwise die
    $context = context_system::instance();
    if (!is_siteadmin()) {
        die("Error: No tienes permisos de gestión (Moodle o GMK) en ningún curso.");
    }
}

$PAGE->set_url('/local/grupomakro_core/pages/test_quiz_types.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Test Masivo de Tipos de Pregunta');
$PAGE->set_heading('Test Masivo de Tipos de Pregunta');

echo $OUTPUT->header();
echo '<h2>Ejecución de Prueba Masiva</h2>';
if ($valid_course) {
    echo '<p>Ejecutando en contexto del curso: <strong>' . $valid_course->fullname . '</strong></p>';
} else {
    echo '<p>Ejecutando en contexto del Sistema</p>';
}

// ... helper function unchanged ...

// Get a category
$category = $DB->get_record('question_categories', ['contextid' => $context->id], '*', IGNORE_MULTIPLE);
if (!$category) {
    // Try to find ANY category in this context or parent
    $cats = question_category_options([$context]); // Gets categories user can use
    // flatten
    foreach ($cats as $key => $name) {
         // id is usually key
         $category = $DB->get_record('question_categories', ['id' => $key]);
         if ($category) break;
    }
}

function get_mock_data($type) {
    $base = [
        'name' => 'Test ' . $type . ' ' . date('H:i:s'),
        'questiontext' => ['text' => 'This is a test question for type: ' . $type, 'format' => FORMAT_HTML],
        'defaultmark' => 1,
        'qtype' => $type,
        'stamp' => make_unique_id_code(),
        'version' => make_unique_id_code(),
        'timecreated' => time(),
        'timemodified' => time(),
        'createdby' => 2, // Force admin ID or current user
        'modifiedby' => 2,
    ];

    switch ($type) {
        case 'truefalse':
            $base['correctanswer'] = 1;
            $base['feedbacktrue'] = ['text' => 'Good', 'format' => FORMAT_HTML];
            $base['feedbackfalse'] = ['text' => 'Bad', 'format' => FORMAT_HTML];
            break;
        case 'multichoice':
            $base['single'] = 1;
            $base['shuffleanswers'] = 1;
            $base['answernumbering'] = 'abc';
            $base['answer'] = [['text' => 'Correct', 'format' => FORMAT_HTML], ['text' => 'Wrong', 'format' => FORMAT_HTML]];
            $base['fraction'] = [1.0, 0.0];
            $base['feedback'] = [['text' => '', 'format' => FORMAT_HTML], ['text' => '', 'format' => FORMAT_HTML]];
            
            $base['correctfeedback'] = ['text' => 'Correct', 'format' => FORMAT_HTML];
            $base['partiallycorrectfeedback'] = ['text' => 'Partially', 'format' => FORMAT_HTML];
            $base['incorrectfeedback'] = ['text' => 'Incorrect', 'format' => FORMAT_HTML];
            $base['shownumcorrect'] = 1;
            break;
        case 'shortanswer':
            $base['usecase'] = 0;
            $base['answer'] = ['Correct', 'Wrong']; // Plain strings for shortanswer
            $base['fraction'] = [1.0, 0.0];
            $base['feedback'] = [['text' => '', 'format' => FORMAT_HTML], ['text' => '', 'format' => FORMAT_HTML]];
            break;
        case 'numerical':
            $base['answer'] = ['10'];
            $base['fraction'] = [1.0];
            $base['tolerance'] = [0];
            $base['feedback'] = [['text' => '', 'format' => FORMAT_HTML]];
            $base['unit'] = []; // strict expectations
            $base['multiplier'] = [];
            break;
        case 'match':
            $base['shuffleanswers'] = 1;
            $base['subquestions'] = [['text' => 'Q1', 'format' => FORMAT_HTML], ['text' => 'Q2', 'format' => FORMAT_HTML]];
            $base['subanswers'] = ['A1', 'A2'];
            $base['correctfeedback'] = ['text' => '', 'format' => FORMAT_HTML];
            $base['partiallycorrectfeedback'] = ['text' => '', 'format' => FORMAT_HTML];
            $base['incorrectfeedback'] = ['text' => '', 'format' => FORMAT_HTML];
            $base['shownumcorrect'] = 1;
            break;
        case 'gapselect':
        case 'ddwtos':
            $base['shuffleanswers'] = 1;
            $base['answer'] = [['text' => 'Option 1', 'format' => FORMAT_HTML]];
            $base['choicegroup'] = [1];
            $base['correctfeedback'] = ['text' => '', 'format' => FORMAT_HTML];
            $base['partiallycorrectfeedback'] = ['text' => '', 'format' => FORMAT_HTML];
            $base['incorrectfeedback'] = ['text' => '', 'format' => FORMAT_HTML];
            $base['shownumcorrect'] = 1;
            $base['questiontext']['text'] = 'Test text with [[1]] marker.';
            break;
        case 'essay':
            $base['responseformat'] = 'editor';
            $base['responsefieldlines'] = 15;
            $base['attachments'] = 0;
            $base['responserequired'] = 0;
            $base['attachmentsrequired'] = 0;
            $base['graderinfo'] = ['text' => '', 'format' => FORMAT_HTML];
            $base['responsetemplate'] = ['text' => '', 'format' => FORMAT_HTML];
            break;
        case 'description':
            // minimal
            break;
    }
    
    // Cast to object
    return (object)$base;
}

$types_to_test = [
    'truefalse',
    'multichoice',
    'shortanswer', 
    'numerical',
    'match',
    'essay',
    'description',
    'gapselect',
    'ddwtos'
];


// Category is already resolved above.
if (!$category) {

    echo '<div class="alert alert-danger">No se encontró ninguna categoría de preguntas para probar.</div>';
} else {
    echo '<p>Usando Categoría ID: ' . $category->id . ' (' . $category->name . ')</p>';
    
    echo '<table class="table table-striped">';
    echo '<thead><tr><th>Tipo</th><th>Resultado</th><th>Mensaje/Error</th></tr></thead>';
    echo '<tbody>';

    foreach ($types_to_test as $qtype) {
        $qdata = get_mock_data($qtype);
        $qdata->category = $category->id;
        global $USER;
        $qdata->createdby = $USER->id;
        $qdata->modifiedby = $USER->id;

        echo '<tr>';
        echo '<td>' . $qtype . '</td>';
        
        try {
            if (!question_bank::is_qtype_installed($qtype)) {
                 throw new Exception('Tipo de pregunta no instalado en Moodle');
            }

            $qtypeobj = question_bank::get_qtype($qtype);
            $savedq = $qtypeobj->save_question($qdata, $qdata);
            
            echo '<td><span class="badge badge-success">OK</span></td>';
            echo '<td>Pregunta creada ID: ' . $savedq->id . '</td>';
            
            // Clean up
            question_delete_question($savedq->id);
            echo '<td><small class="text-muted">Eliminada automaticament</small></td>'; 
            
        } catch (Throwable $e) {
            echo '<td><span class="badge badge-danger">FAIL</span></td>';
            echo '<td><pre>' . $e->getMessage() . "\n" . $e->getTraceAsString() . '</pre></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo $OUTPUT->footer();
