<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';

$user = core_user::get_user(2, '*', MUST_EXIST);
core\session\manager::set_user($user);

$tpl = $DB->get_record('gmk_diploma_template', ['id' => 2]);
$exported = \local_grupomakro_core\local\diplomas\manager::export_template($tpl);
echo "Template:\n";
print_r($exported);