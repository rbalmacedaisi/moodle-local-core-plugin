<?php
define('CLI_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->libdir.'/clilib.php');

$fields = $DB->get_records('user_info_field');
echo "ID | Shortname | Name\n";
echo "---|---|---\n";
foreach ($fields as $f) {
    echo "{$f->id} | {$f->shortname} | {$f->name}\n";
}
