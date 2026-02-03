<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$questionid = 11; // Use a known ID from user's env if possible, or iterate
$qdata = $DB->get_record('question', ['id' => $questionid]);

if (!$qdata) {
    die("Question not found\n");
}

echo "Question: {$qdata->name} ({$qdata->qtype})\n";

$fs = get_file_storage();
$areas = ['bgimage', 'dragimage'];

foreach ($areas as $area) {
    echo "Area: $area\n";
    $files = $fs->get_area_files($qdata->contextid, 'qtype_' . $qdata->qtype, $area, $qdata->id, 'itemid, filepath, filename', false);
    foreach ($files as $file) {
        echo " - {$file->get_filename()} (Size: {$file->get_size()})\n";
    }
}
