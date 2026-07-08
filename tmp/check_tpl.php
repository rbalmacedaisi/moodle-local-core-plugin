<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';

$tpls = $DB->get_records('gmk_diploma_template');
foreach ($tpls as $t) {
    echo "Template id={$t->id} name={$t->name}\n";
    echo "  background_fileid=" . var_export($t->background_fileid, true) . "\n";
    echo "  background_filename={$t->background_filename}\n";
    echo "  background_mimetype={$t->background_mimetype}\n";

    if (!empty($t->background_fileid)) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id((int)$t->background_fileid);
        if ($file) {
            echo "  file found: id={$file->get_id()} size={$file->get_filesize()}\n";
        } else {
            echo "  FILE NOT FOUND with id={$t->background_fileid}\n";
        }
    }
}