<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

global $DB;
$qid = $DB->get_field_sql("SELECT MAX(id) FROM {question} WHERE qtype = 'gapselect'");
if (!$qid) die("No gapselect questions found\n");

$qdata = question_bank::load_question_data($qid);
echo "Question ID: $qid\n";
echo "Text: {$qdata->questiontext}\n";
echo "Choices in options->choices:\n";
print_r($qdata->options->choices);
