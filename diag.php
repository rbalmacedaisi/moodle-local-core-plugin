<?php
// Diagnostico: cuentas y ejemplos de grupos M[ÓO]DULO.
// Ejecucion: php /var/www/html/moodle/local/grupomakro_core/diag.php

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

$total_gm = $DB->count_records('groups_members');
echo "Total groups_members: $total_gm\n";

// Cualquier grupo que contenga "M" o "M" como inicio de palabra + DULO es complicado.
// Probemos patrones más amplios.
$patrones = [
    'MÓDULO'    => '/MÓDULO/',
    'MODULO'    => '/MODULO/',
    'M.dulo'    => '/M.dulo/',
];

foreach ($patrones as $label => $pat) {
    $sql = "SELECT COUNT(*) AS c FROM {groups} WHERE name ~ :pat";
    $count = $DB->count_records_sql("SELECT COUNT(*) FROM {groups} WHERE name ~ ?", [$pat]);
    echo "Grupos que matchean $label: $count\n";
}

// Ejemplos reales
echo "\n--- Ejemplos de grupos con 'dulo' (case insensitive) ---\n";
$rs = $DB->get_recordset_sql("SELECT id, courseid, name FROM {groups} WHERE LOWER(name) LIKE '%dulo%' LIMIT 10");
foreach ($rs as $g) {
    echo "  [{$g->id}] curso={$g->courseid} | {$g->name}\n";
}
$rs->close();

// Cómo se llama exactamente la columna en pgsql
echo "\n--- Version Moodle ---\n";
echo "version: " . $DB->get_server_info()['version'] . "\n";
echo "type: " . $DB->get_dbtype() . "\n";
