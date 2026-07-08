<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
$user = core_user::get_user(2, '*', MUST_EXIST);
core\session\manager::set_user($user);

$prefix = 'DP-CURSOSMKRJUN202600000006-GRUPO-2026-';
$maxnum = $DB->get_field_sql(
    "SELECT MAX(CAST(SUBSTRING_INDEX(g.diploma_number, '-', -1) AS UNSIGNED)) FROM {gmk_diploma_generation} g WHERE g.diploma_number LIKE ?",
    [$prefix . '%']
);
$next = (int)$maxnum + 1;
echo "Max existing for prefix '$prefix': $maxnum\n";
echo "Next consecutive: " . $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT) . "\n";

$prefix2 = 'DP-2023A00031-TCNIC-2026-';
$maxnum2 = $DB->get_field_sql(
    "SELECT MAX(CAST(SUBSTRING_INDEX(g.diploma_number, '-', -1) AS UNSIGNED)) FROM {gmk_diploma_generation} g WHERE g.diploma_number LIKE ?",
    [$prefix2 . '%']
);
$next2 = (int)$maxnum2 + 1;
echo "\nMax existing for prefix '$prefix2': $maxnum2\n";
echo "Next consecutive: " . $prefix2 . str_pad((string)$next2, 6, '0', STR_PAD_LEFT) . "\n";