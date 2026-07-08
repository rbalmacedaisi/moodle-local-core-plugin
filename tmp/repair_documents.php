<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';

echo "=== Repairing broken fileitemid in existing documents ===\n";
$sysctx = context_system::instance();
$fs = get_file_storage();

$docs = $DB->get_records_sql(
    "SELECT d.id, d.generationid, d.filename, d.fileitemid
       FROM {gmk_diploma_document} d
   ORDER BY d.id ASC"
);
foreach ($docs as $doc) {
    $file = $fs->get_file_by_id((int)$doc->fileitemid);
    $current_ok = $file && $file->get_filename() === $doc->filename && $file->get_filesize() > 1000;
    echo "  doc={$doc->id} gen={$doc->generationid} name={$doc->filename} stored_id={$doc->fileitemid} current_ok=" . ($current_ok ? 'YES' : 'NO') . "\n";

    if (!$current_ok) {
        // Try to find the actual file by name in the filearea.
        $realfile = $fs->get_file($sysctx->id, 'local_grupomakro_core', 'diploma_document', $doc->generationid, '/', $doc->filename);
        if ($realfile) {
            $newid = (int)$realfile->get_id();
            $DB->set_field('gmk_diploma_document', 'fileitemid', $newid, ['id' => $doc->id]);
            $DB->set_field('gmk_diploma_document', 'filesize', (int)$realfile->get_filesize(), ['id' => $doc->id]);
            $DB->set_field('gmk_diploma_document', 'contenthash', (string)$realfile->get_contenthash(), ['id' => $doc->id]);
            echo "    FIXED -> fileitemid={$newid} filesize=" . $realfile->get_filesize() . "\n";
        } else {
            echo "    CANNOT FIX: real file not found\n";
        }
    }
}

echo "\n=== Verify after repair ===\n";
$docs = $DB->get_records_sql(
    "SELECT d.id, d.generationid, d.filename, d.fileitemid, d.filesize
       FROM {gmk_diploma_document} d"
);
foreach ($docs as $doc) {
    $file = $fs->get_file_by_id((int)$doc->fileitemid);
    $size = $file ? $file->get_filesize() : '?';
    echo "  doc={$doc->id} gen={$doc->generationid} stored_id={$doc->fileitemid} real_size={$size}\n";
}