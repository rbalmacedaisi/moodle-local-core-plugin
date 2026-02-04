<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$questionid = (int)$_GET['id'];
$qdata = question_bank::load_question_data($questionid);

echo "<pre>";
print_r($qdata->options);
echo "</pre>";
