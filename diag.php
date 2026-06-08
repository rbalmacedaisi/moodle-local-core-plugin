<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

echo "=== DIAGNOSTICO DE GRUPOS ===\n\n";
echo "DB type: " . $DB->get_dbtype() . "\n";
echo "Tablas prefijo: " . $DB->get_prefix() . "\n\n";

$total = $DB->count_records('groups_members');
echo "Total groups_members: $total\n";

// Buscar grupos con "dulo" usando LIKE (case insensitive en CI default de MySQL)
$sql = "SELECT id, courseid, name FROM {groups} WHERE name LIKE '%dulo%' LIMIT 15";
echo "\n--- Grupos con 'dulo' (LIKE) ---\n";
foreach ($DB->get_records_sql($sql) as $g) {
    echo "  [{$g->id}] curso={$g->courseid} | {$g->name}\n";
}

// Sin filtro, primeros 20 grupos
echo "\n--- 20 grupos cualquiera ---\n";
foreach ($DB->get_records('groups', null, 'id ASC', 'id, courseid, name', 0, 20) as $g) {
    echo "  [{$g->id}] curso={$g->courseid} | {$g->name}\n";
}

// Verificar la query que usa el fix_multi_groups.php
echo "\n--- Query del fix: g.name REGEXP 'M[ÓO]DULO' ---\n";
$sql = "SELECT COUNT(*) AS c FROM {groups} g WHERE g.name REGEXP ?";
$count = $DB->count_records_sql("SELECT COUNT(*) FROM {groups} WHERE name REGEXP ?", ['M[ÓO]DULO']);
echo "Match: $count\n";

// Con la O normal
$count2 = $DB->count_records_sql("SELECT COUNT(*) FROM {groups} WHERE name REGEXP ?", ['MODULO']);
echo "Match solo MODULO: $count2\n";
