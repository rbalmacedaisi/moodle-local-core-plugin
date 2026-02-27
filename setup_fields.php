<?php
define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

// Security check
require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: text/plain');
echo "GMK FIELD SETUP\n";
echo "==============\n\n";

global $DB;

$fields_to_create = [
    ['shortname' => 'periodo_ingreso', 'name' => 'Periodo de Ingreso', 'datatype' => 'text', 'size' => 30, 'max' => 100],
    ['shortname' => 'gmkjourney', 'name' => 'Jornada', 'datatype' => 'text', 'size' => 30, 'max' => 100],
    ['shortname' => 'genero', 'name' => 'Género', 'datatype' => 'text', 'size' => 30, 'max' => 100],
    ['shortname' => 'sexo', 'name' => 'Sexo', 'datatype' => 'text', 'size' => 30, 'max' => 100]
];

// 2. Ensure we have a category
$category = $DB->get_record('user_info_category', [], '', 'id', IGNORE_MULTIPLE);
if (!$category) {
    $category = new stdClass();
    $category->name = 'Datos Académicos';
    $category->sortorder = 1;
    $category->id = $DB->insert_record('user_info_category', $category);
    echo "Created new category: {$category->name}\n";
} else {
    echo "Using existing category (ID: {$category->id}).\n";
}

foreach ($fields_to_create as $finfo) {
    $shortname = $finfo['shortname'];
    $existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);
    if ($existing) {
        echo "The field '{$shortname}' already exists (ID: {$existing->id}).\n";
    } else {
        // 3. Create the field
        $field = new stdClass();
        $field->shortname = $shortname;
        $field->name = $finfo['name'];
        $field->datatype = $finfo['datatype']; 
        $field->categoryid = $category->id;
        $field->sortorder = $DB->count_records('user_info_field', ['categoryid' => $category->id]) + 1;
        $field->required = 0;
        $field->locked = 0;
        $field->visible = 1; // Visible to user? 1=Visible, 2=Not visible, 0=Hidden
        $field->forceunique = 0;
        $field->signup = 0;
        $field->defaultdata = '';
        $field->defaultdataformat = FORMAT_HTML;
        $field->param1 = $finfo['size']; // Display size
        $field->param2 = $finfo['max']; // Max length
        
        $field->id = $DB->insert_record('user_info_field', $field);
        echo "SUCCESS: Field '{$shortname}' created with ID: {$field->id}\n";
    }
}

echo "\nGMK SETUP END";
