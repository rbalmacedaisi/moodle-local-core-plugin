<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';

echo "=== Cleaning test generations ===\n";
$fs = get_file_storage();
$gens = $DB->get_records('gmk_diploma_generation');
foreach ($gens as $gen) {
    $docs = $DB->get_records('gmk_diploma_document', ['generationid' => $gen->id]);
    foreach ($docs as $doc) {
        $file = $fs->get_file_by_id((int)$doc->fileitemid);
        if (!$file) {
            $file = $fs->get_file(
                context_system::instance()->id,
                'local_grupomakro_core',
                'diploma_document',
                $gen->id,
                '/',
                (string)$doc->filename
            );
        }
        if ($file) {
            $file->delete();
            echo "  Deleted file id={$file->get_id()} ({$doc->filename})\n";
        }
        $DB->delete_records('gmk_diploma_document', ['id' => $doc->id]);
        echo "  Deleted doc id={$doc->id}\n";
    }
    $DB->delete_records('gmk_diploma_generation', ['id' => $gen->id]);
    echo "  Deleted generation id={$gen->id} (user={$gen->userid}, plan={$gen->learningplanid})\n";
}

echo "\n=== Verify clean state ===\n";
echo "Generations: " . $DB->count_records('gmk_diploma_generation') . "\n";
echo "Documents: " . $DB->count_records('gmk_diploma_document') . "\n";