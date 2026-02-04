<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$qid = optional_param('qid', 0, PARAM_INT);

echo "<html><head><title>Debug Question Data</title></head><body>";
echo "<h1>Diagnostic Tool - Question Data</h1>";

if ($qid === 0) {
    echo "<p>Please provide a question ID (?qid=XXX)</p>";
    // Look for recent ddwtos questions
    $recent = $DB->get_records('question', ['qtype' => 'ddwtos'], 'id DESC', 'id, name', 0, 5);
    if ($recent) {
        echo "<h3>Recent DDWTOS Questions:</h3><ul>";
        foreach ($recent as $r) {
            echo "<li>ID: {$r->id} - <a href='?qid={$r->id}'>{$r->name}</a></li>";
        }
        echo "</ul>";
    }
} else {
    try {
        $qdata = question_bank::load_question_data($qid);
        echo "<h2>Data for Question ID: $qid</h2>";
        echo "<pre>";
        echo "QTYPE: " . htmlspecialchars($qdata->qtype) . "\n";
        echo "NAME: " . htmlspecialchars($qdata->name) . "\n";
        echo "\n--- RAW QDATA ---\n";
        print_r($qdata);
        
        if (isset($qdata->options)) {
            echo "\n--- OPTIONS ---\n";
            print_r($qdata->options);
        }
        
        echo "</pre>";
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
}

echo "</body></html>";
