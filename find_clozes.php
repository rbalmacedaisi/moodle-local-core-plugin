<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$sql = "SELECT id, name, qtype FROM {question} WHERE qtype = 'multianswer' ORDER BY id DESC LIMIT 10";
$questions = $DB->get_records_sql($sql);

echo "Last Cloze Questions:\n";
foreach ($questions as $q) {
    echo "ID: {$q->id} - Name: {$q->name}\n";
}
