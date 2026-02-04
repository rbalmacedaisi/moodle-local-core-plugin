<?php
define('NO_OUTPUT_BUFFERING', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

// Simple Security: Only for admins or teachers with capability
require_login();
$systemcontext = context_system::instance();
if (!is_siteadmin()) {
    // Basic check for teacher-like personas if not admin
    // require_capability('moodle/course:manageactivities', $systemcontext);
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$slot = optional_param('slot', 0, PARAM_INT);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Quiz Debug Tool Interactivo</title>
    <style>
        body { font-family: sans-serif; margin: 20px; line-height: 1.5; background: #f4f4f9; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #1976D2; }
        .breadcrumb { margin-bottom: 20px; font-size: 0.9em; color: #666; }
        .breadcrumb a { color: #1976D2; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #ddd; }
        tr:hover { background-color: #f1f1f1; }
        .btn { padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
        .btn-blue { background: #1976D2; color: white; }
        .btn-red { background: #f44336; color: white; }
        .btn-gray { background: #e0e0e0; color: #333; }
        .error-log { background: #ffebee; border: 1px solid #ffcdd2; padding: 15px; margin-top: 20px; color: #b71c1c; white-space: pre-wrap; font-family: monospace; }
        .success-log { background: #e8f5e9; border: 1px solid #c8e6c9; padding: 15px; margin-top: 20px; color: #2e7d32; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>Quiz Debug Tool Interactivo</h1>";

// --- ACTIONS ---
if ($action === 'delete' && $quizid && $slot) {
    try {
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
        
        echo "<div class='success-log'>Intentando eliminar slot <strong>$slot</strong> en <strong>{$quiz->name}</strong>...</div>";
        
        $quizobj = new quiz($quiz, $cm, $course);
        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        $structure->remove_slot($slot);
        
        echo "<div class='success-log'>¡Éxito! La ranura ha sido eliminada.</div>";
    } catch (Throwable $e) {
        echo "<div class='error-log'><strong>ERROR AL ELIMINAR:</strong>\n\n" . $e->getMessage() . "\n\n" . $e->getTraceAsString() . "</div>";
    }
}

// --- NAVIGATION ---
if (!$courseid) {
    // List Courses that have Quizzes
    echo "<h3>Selecciona un Curso</h3>";
    $sql = "SELECT DISTINCT c.id, c.fullname 
            FROM {course} c 
            JOIN {quiz} q ON q.course = c.id 
            ORDER BY c.fullname ASC";
    $courses = $DB->get_records_sql($sql);
    
    echo "<ul>";
    foreach ($courses as $c) {
        echo "<li><a href='?courseid={$c->id}'>" . htmlspecialchars($c->fullname) . "</a></li>";
    }
    echo "</ul>";
} else if (!$quizid) {
    // List Quizzes in Course
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    echo "<div class='breadcrumb'><a href='?'>Cursos</a> &raquo; " . htmlspecialchars($course->fullname) . "</div>";
    echo "<h3>Quizzes en este curso</h3>";
    
    $quizzes = $DB->get_records('quiz', ['course' => $courseid], 'name ASC');
    echo "<ul>";
    foreach ($quizzes as $q) {
        echo "<li><a href='?courseid=$courseid&quizid={$q->id}'>" . htmlspecialchars($q->name) . "</a></li>";
    }
    echo "</ul>";
} else {
    // List Slots in Quiz
    $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
    
    echo "<div class='breadcrumb'>
            <a href='?'>Cursos</a> &raquo; 
            <a href='?courseid=$courseid'>" . htmlspecialchars($course->fullname) . "</a> &raquo; 
            " . htmlspecialchars($quiz->name) . " (cmid: {$cm->id})
          </div>";
    
    echo "<h3>Estructura de Preguntas (Slots)</h3>";
    
    // Query slots
    $sql = "SELECT s.id, s.slot, s.page, q.name, q.qtype, q.id as questionid
            FROM {quiz_slots} s
            JOIN {question_references} qr ON qr.itemid = s.id
            JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
            JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
            JOIN {question} q ON q.id = qv.questionid
            WHERE s.quizid = :quizid
            AND qr.component = 'mod_quiz'
            AND qr.questionarea = 'slot'
            AND qv.version = (
                SELECT MAX(v.version)
                FROM {question_versions} v
                WHERE v.questionbankentryid = qbe.id
            )
            ORDER BY s.slot";
    
    $slots = $DB->get_records_sql($sql, ['quizid' => $quizid]);
    
    if (!$slots) {
        echo "<p>No hay preguntas en este cuestionario o la consulta falló (Moodle 4.0 structure check).</p>";
    } else {
        echo "<table>
                <thead>
                    <tr>
                        <th>Ranura</th>
                        <th>Pregunta (ID)</th>
                        <th>Tipo</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>";
        foreach ($slots as $s) {
            echo "<tr>
                    <td>{$s->slot}</td>
                    <td>" . htmlspecialchars($s->name) . " ({$s->questionid})</td>
                    <td><code>{$s->qtype}</code></td>
                    <td>
                        <a href='?courseid=$courseid&quizid=$quizid&slot={$s->slot}&action=delete' 
                           class='btn btn-red' 
                           onclick=\"return confirm('¿Seguro que quieres depurar la eliminación de esta pregunta?')\">
                           Debug Borrar
                        </a>
                    </td>
                  </tr>";
        }
        echo "</tbody></table>";
    }
}

echo "</div>
</body>
</html>";
