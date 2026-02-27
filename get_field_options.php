<?php
define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: text/plain; charset=utf-8');
global $DB;

$shortnames = ['gmkgenre', 'gmkjourney', 'documenttype', 'studentstatus', 'financialstate'];

echo "VALORES ESPERADOS POR MOODLE PARA CAMPOS DESPLEGABLES:\n";
echo "======================================================\n\n";

foreach ($shortnames as $sn) {
    if ($field = $DB->get_record('user_info_field', ['shortname' => $sn])) {
        echo "CAMPO: " . $field->name . " (Shortname: " . $field->shortname . ")\n";
        echo "TIPO: " . $field->datatype . "\n";
        
        if ($field->datatype == 'menu') {
            echo "OPCIONES PERMITIDAS:\n";
            $options = explode("\n", $field->param1);
            foreach ($options as $opt) {
                echo " - '" . trim($opt) . "'\n";
            }
        } else {
            echo "NOTA: Este campo es tipo '{$field->datatype}', acepta cualquier texto (no es un menú estricto).\n";
        }
        echo "\n------------------------------------------------------\n\n";
    } else {
        echo "CAMPO: {$sn} NO ENCONTRADO EN LA BASE DE DATOS\n\n";
    }
}
