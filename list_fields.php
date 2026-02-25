<?php
define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: text/plain');
global $DB;

$fields = $DB->get_records('user_info_field', [], 'id ASC');
echo "ID | Shortname | Name\n";
echo "----------------------\n";
foreach ($fields as $f) {
    echo "{$f->id} | {$f->shortname} | {$f->name}\n";
}
