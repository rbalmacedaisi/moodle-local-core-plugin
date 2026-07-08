<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
require_once '/var/www/html/moodle/lib/moodlelib.php';

$user = core_user::get_user(2, '*', MUST_EXIST);
core\session\manager::set_user($user);

echo "=== Existing diploma numbers ===\n";
$rows = $DB->get_records_sql("SELECT id, diploma_number, timecreated FROM {gmk_diploma_generation} ORDER BY id");
foreach ($rows as $r) {
    echo "  gen=" . $r->id . " number=" . $r->diploma_number . " (created " . userdate($r->timecreated) . ")\n";
}

echo "\n=== Next consecutive per (idnumber, plancode, year) ===\n";
// Mimic the SQL we just deployed
$sql = "SELECT g.diploma_number,
               SUBSTRING_INDEX(g.diploma_number, '-', -1) AS lastseg,
               CAST(SUBSTRING_INDEX(g.diploma_number, '-', -1) AS UNSIGNED) AS lastnum
          FROM {gmk_diploma_generation} g
         ORDER BY lastnum DESC";
$all = $DB->get_records_sql($sql);
echo "Sample parsed lastseg:\n";
foreach (array_slice($all, 0, 5) as $r) {
    echo "  " . $r->diploma_number . " -> lastseg='" . $r->lastseg . "' lastnum=" . $r->lastnum . "\n";
}

echo "\n=== Simulate next number ===\n";
$prefix = 'DP-2023A00031-TCNIC-2026-';
$maxnum = $DB->get_field_sql(
    "SELECT MAX(CAST(SUBSTRING_INDEX(g.diploma_number, '-', -1) AS UNSIGNED)) FROM {gmk_diploma_generation} g WHERE g.diploma_number LIKE ?",
    [$prefix . '%']
);
$next = (int)$maxnum + 1;
echo "Max existing for prefix '$prefix': $maxnum\n";
echo "Next would be: " . $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT) . "\n";