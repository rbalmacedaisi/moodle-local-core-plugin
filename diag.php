<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

echo "=== DIAGNOSTICO DE GRUPOS ===\n\n";

$total = $DB->count_records('groups_members');
echo "Total groups_members: $total\n";

// Buscar grupos con "dulo" usando LIKE
$sql = "SELECT id, courseid, name FROM {groups} WHERE name LIKE '%dulo%' LIMIT 15";
echo "\n--- Grupos con 'dulo' (LIKE) ---\n";
foreach ($DB->get_records_sql($sql) as $g) {
    echo "  [{$g->id}] curso={$g->courseid} | {$g->name}\n";
}

echo "\n--- 20 grupos cualquiera ---\n";
foreach ($DB->get_records('groups', null, 'id ASC', 'id, courseid, name', 0, 20) as $g) {
    echo "  [{$g->id}] curso={$g->courseid} | {$g->name}\n";
}

echo "\n--- Match M[ÓO]DULO ---\n";
$count = $DB->count_records_sql("SELECT COUNT(*) FROM {groups} WHERE name REGEXP ?", ['M[ÓO]DULO']);
echo "REGEXP 'M[ÓO]DULO': $count\n";
$count2 = $DB->count_records_sql("SELECT COUNT(*) FROM {groups} WHERE name REGEXP ?", ['MODULO']);
echo "REGEXP 'MODULO': $count2\n";
