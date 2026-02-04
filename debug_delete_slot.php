<?php
define('NO_OUTPUT_BUFFERING', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

$cmid = optional_param('cmid', 0, PARAM_INT);
$slot = optional_param('slot', 0, PARAM_INT);

echo "<h1>Diagnóstico de Eliminación de Ranura (Slot)</h1>";

if (!$cmid || !$slot) {
    echo "Uso: ?cmid=XXX&slot=YYY";
    exit;
}

try {
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);

    echo "Intentando eliminar slot $slot del cuestionario ID {$quiz->id} ({$quiz->name})...<br>";
    
    // Check if slot exists
    $slot_record = $DB->get_record('quiz_slots', ['quizid' => $quiz->id, 'slot' => $slot]);
    if (!$slot_record) {
        throw new Exception("El slot $slot no existe en este cuestionario.");
    }
    echo "Registro de slot encontrado (ID: {$slot_record->id}).<br>";

    // Execute deletion
    quiz_remove_slot($quiz, $slot);
    
    echo "<h2 style='color:green;'>¡Éxito! Slot eliminado.</h2>";
} catch (Throwable $e) {
    echo "<h2 style='color:red;'>Error al eliminar:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<h3>Traza de error:</h3>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
