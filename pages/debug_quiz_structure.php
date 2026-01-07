<?php
require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/editlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context); // Only admins

$PAGE->set_url('/local/grupomakro_core/pages/debug_quiz_structure.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard'); // Standard layout, not admin
$PAGE->set_title('Diagnóstico de Estructura de Preguntas');
$PAGE->set_heading('Diagnóstico de Estructura de Preguntas');

echo $OUTPUT->header();

echo '<h2>Inspector de Objetos de Pregunta</h2>';
echo '<p>Ingrese el ID de una pregunta existente para ver su estructura interna completa (objeto $question).</p>';

$qid = optional_param('qid', 0, PARAM_INT);

echo '<form method="get" action="">';
echo '<div class="form-group">';
echo '<label for="qid">ID de Pregunta (mdl_question.id): </label>';
echo '<input type="number" name="qid" id="qid" value="' . $qid . '" class="form-control" style="width: 150px; display: inline-block;">';
echo '<input type="submit" value="Inspeccionar" class="btn btn-primary">';
echo '</div>';
echo '</form>';

if ($qid) {
    if ($questiondata = $DB->get_record('question', ['id' => $qid])) {
        // Load full question definition
        $questionobj = question_bank::load_question($qid);
        
        echo '<hr>';
        echo '<h3>Resultado para Pregunta ID: ' . $qid . ' (' . $questionobj->qtype . ')</h3>';
        
        echo '<div class="row">';
        echo '<div class="col-md-6">';
        echo '<h4>Objeto Crudo (DB Record)</h4>';
        echo '<pre style="background: #f8f9fa; padding: 15px; border: 1px solid #ddd; max-height: 500px; overflow: auto;">';
        print_r($questiondata);
        echo '</pre>';
        echo '</div>';
        
        echo '<div class="col-md-6">';
        echo '<h4>Objeto Cargado (Full Definition)</h4>';
        echo '<pre style="background: #e9ecef; padding: 15px; border: 1px solid #ddd; max-height: 500px; overflow: auto;">';
        print_r($questionobj);
        echo '</pre>';
        echo '</div>';
        echo '</div>';

        // Check Specific Tables based on type
        echo '<h4>Tablas Específicas</h4>';
        $tables = [
            'multichoice' => 'question_multichoice',
            'truefalse' => 'question_truefalse',
            'shortanswer' => 'question_shortanswer',
            'numerical' => 'question_numerical',
            'essay' => 'question_essay',
            'match' => 'question_match',
        ];

        if (array_key_exists($questionobj->qtype, $tables)) {
            $tablename = $tables[$questionobj->qtype];
            if ($specific = $DB->get_record($tablename, ['question' => $qid])) {
                echo '<p>Datos en tabla <code>mdl_' . $tablename . '</code>:</p>';
                echo '<pre>' . print_r($specific, true) . '</pre>';
            } else {
                echo '<p>No se encontró registro en <code>mdl_' . $tablename . '</code> (¿Quizás usa otra tabla o lógica?)</p>';
            }
        }
        
    } else {
        echo '<div class="alert alert-danger">No se encontró una pregunta con ID ' . $qid . '</div>';
    }
}

// Helper: Show latest questions
echo '<hr>';
echo '<h4>Últimas 10 preguntas creadas en el sistema</h4>';
$latest = $DB->get_records('question', null, 'id DESC', 'id, name, qtype, timecreated', 0, 10);
echo '<table class="table table-bordered table-sm">';
echo '<thead><tr><th>ID</th><th>Nombre</th><th>Tipo</th><th>Creada</th></tr></thead>';
echo '<tbody>';
foreach ($latest as $q) {
    echo '<tr>';
    echo '<td><a href="?qid=' . $q->id . '">' . $q->id . '</a></td>';
    echo '<td>' . s($q->name) . '</td>';
    echo '<td>' . $q->qtype . '</td>';
    echo '<td>' . userdate($q->timecreated) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo $OUTPUT->footer();
