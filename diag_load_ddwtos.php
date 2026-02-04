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

if (isset($qdata->options->choices)) {
    echo "\n--- OPTIONS->CHOICES (" . count($qdata->options->choices) . ") ---\n";
    foreach ($qdata->options->choices as $id => $choice) {
        echo "Choice ID $id:\n";
        print_r($choice);
    }
}
