<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

header('Content-Type: text/plain');

$q = $DB->get_record_sql("SELECT id FROM {question} WHERE qtype = 'ddwtos' ORDER BY id DESC LIMIT 1");

if (!$q) {
    die("No DDWTOS questions found in DB.");
}

$qid = $q->id;
$qdata = question_bank::load_question_data($qid);

echo "QUESTION ID: $qid\n";
echo "QTYPE: {$qdata->qtype}\n";

echo "\n--- TOP LEVEL ---\n";
foreach ($qdata as $key => $val) {
    if (!is_array($val) && !is_object($val)) {
        echo "$key: $val\n";
    } else {
        echo "$key: [" . gettype($val) . "]\n";
    }
}

if (isset($qdata->answers)) {
    echo "\n--- ANSWERS (" . count($qdata->answers) . ") ---\n";
    print_r($qdata->answers);
}

if (isset($qdata->options)) {
    echo "\n--- OPTIONS ---\n";
    foreach ($qdata->options as $key => $val) {
        if (!is_array($val) && !is_object($val)) {
            echo "$key: $val\n";
        } else {
            echo "$key: [" . gettype($val) . "]\n";
        }
    }
    
    if (isset($qdata->options->choices)) {
        echo "\n--- OPTIONS->CHOICES (" . count($qdata->options->choices) . ") ---\n";
        print_r($qdata->options->choices);
    }
}
