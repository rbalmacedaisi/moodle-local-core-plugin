<?php
/**
 * Debug page to inspect GapSelect question data.
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_gapselect_inspect.php'));
$PAGE->set_title('Debug GapSelect Inspect');
$PAGE->set_heading('Debug GapSelect Inspect');

echo $OUTPUT->header();

$qid = optional_param('qid', 0, PARAM_INT);

if ($qid) {
    try {
        echo "<h3>Inspecting Question ID: $qid</h3>";
        $qdata = question_bank::load_question_data($qid);
        
        echo "<h4>Raw Question Data:</h4>";
        echo "<pre>" . htmlspecialchars(print_r($qdata, true)) . "</pre>";

        if (isset($qdata->options->choices)) {
            echo "<h4>Choices (Gaps):</h4>";
            echo "<table class='table table-bordered'>";
            echo "<thead><tr><th>No</th><th>Text</th><th>Group</th><th>Raw Data</th></tr></thead>";
            echo "<tbody>";
            foreach ($qdata->options->choices as $no => $choice) {
                $group = isset($choice->selectgroup) ? $choice->selectgroup : (isset($choice->draggroup) ? $choice->draggroup : 'N/A');
                echo "<tr>";
                echo "<td>$no</td>";
                echo "<td>" . htmlspecialchars($choice->answer) . "</td>";
                echo "<td>$group</td>";
                echo "<td><pre>" . htmlspecialchars(print_r($choice, true)) . "</pre></td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
        
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<h3>Select a GapSelect Question to Inspect</h3>";
    $questions = $DB->get_records('question', array('qtype' => 'gapselect'), 'id DESC', '*', 0, 20);
    if ($questions) {
        echo "<ul>";
        foreach ($questions as $q) {
            echo "<li><a href='debug_gapselect_inspect.php?qid={$q->id}'>ID: {$q->id} - {$q->name}</a></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No gapselect questions found.</p>";
    }
}

echo $OUTPUT->footer();
