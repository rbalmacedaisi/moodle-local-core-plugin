<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$questionid = (int)$argv[1];
$qdata = question_bank::load_question_data($questionid);

echo "QTYPE: " . $qdata->qtype . "\n";
echo "OPTIONS: " . json_encode(array_keys((array)$qdata->options)) . "\n";

if (isset($qdata->options->choices)) {
    echo "CHOICES FOUND: " . count($qdata->options->choices) . "\n";
    foreach ($qdata->options->choices as $choice) {
        echo " - Choice: " . $choice->answer . " (Group: " . ($choice->draggroup ?? 'N/A') . ")\n";
    }
}

if (isset($qdata->answers)) {
    echo "ANSWERS FOUND: " . count($qdata->answers) . "\n";
}

if (isset($qdata->options->answers)) {
    echo "OPTIONS->ANSWERS FOUND: " . count($qdata->options->answers) . "\n";
}
