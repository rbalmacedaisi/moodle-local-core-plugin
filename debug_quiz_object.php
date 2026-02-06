<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$attemptid = optional_param('attemptid', 0, PARAM_INT);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/debug_quiz_object.php'));
$PAGE->set_title("Debug Quiz Object");

echo $OUTPUT->header();

echo "<h2>Debug Quiz Object</h2>";

if (!$attemptid) {
    echo "<h3>Select a recent finished attempt:</h3>";
    $recent_attempts = $DB->get_records_sql("
        SELECT quiza.id, quiza.userid, quiza.timefinish, q.name as quizname, u.firstname, u.lastname
        FROM {quiz_attempts} quiza
        JOIN {quiz} q ON q.id = quiza.quiz
        JOIN {user} u ON u.id = quiza.userid
        WHERE quiza.state = 'finished'
        ORDER BY quiza.timefinish DESC
        LIMIT 20
    ");

    if ($recent_attempts) {
        echo "<ul>";
        foreach ($recent_attempts as $ra) {
            $name = $ra->firstname . ' ' . $ra->lastname;
            $date = date('Y-m-d H:i', $ra->timefinish);
            echo "<li><a href='?attemptid={$ra->id}'>Attempt ID: {$ra->id} - Quiz: {$ra->quizname} - Student: $name ($date)</a></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No finished attempts found in the system.</p>";
    }
} else {
    try {
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $attemptobj = \quiz_attempt::create($attemptid);
        
        echo "<h3>Attempt Analysis for ID: $attemptid</h3>";
        echo "<p><b>Class Name:</b> " . get_class($attemptobj) . "</p>";
        
        echo "<h3>Available Methods:</h3>";
        $methods = get_class_methods($attemptobj);
        sort($methods);
        echo "<div style='column-count: 3; font-family: monospace;'>";
        echo "<ul>";
        foreach ($methods as $method) {
            echo "<li>$method</li>";
        }
        echo "</ul>";
        echo "</div>";
        
        echo "<h3>Attempt Raw Data:</h3>";
        echo "<pre style='background: #f4f4f4; padding: 10px; border: 1px solid #ddd;'>";
        print_r($attemptobj->get_attempt());
        echo "</pre>";

        echo "<h3>Attempting to call get_context():</h3>";
        if (method_exists($attemptobj, 'get_context')) {
            echo "<b style='color:green;'>Method get_context() EXISTS.</b><br>";
            $ctx = $attemptobj->get_context();
            echo "Context class: " . get_class($ctx) . " ID: " . $ctx->id;
        } else {
            echo "<b style='color:red;'>Method get_context() DOES NOT EXIST.</b><br>";
            
            echo "Searching for alternatives...<br>";
            $alternatives = ['get_quiz_context', 'get_cm', 'get_course'];
            foreach ($alternatives as $alt) {
                if (method_exists($attemptobj, $alt)) {
                    echo "Alternative found: <b>$alt()</b><br>";
                }
            }
        }

    } catch (Exception $e) {
        echo "<div style='color:red; padding: 10px; border: 1px solid red;'>Error: " . $e->getMessage() . "</div>";
        echo "<p><a href='?'>Back to list</a></p>";
    }
}

echo $OUTPUT->footer();
