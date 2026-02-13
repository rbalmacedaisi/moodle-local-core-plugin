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

$shortname = 'periodo_ingreso';
$name = 'Periodo de Ingreso';

// 1. Check if field already exists
$existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);
if ($existing) {
    echo "The field '{$shortname}' already exists (ID: {$existing->id}).\n";
} else {
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

    // 3. Create the field
    $field = new stdClass();
    $field->shortname = $shortname;
    $field->name = $name;
    $field->datatype = 'text'; // We'll use text for flexibility (e.g., 2024-I, 2024-II)
    $field->categoryid = $category->id;
    $field->sortorder = $DB->count_records('user_info_field', ['categoryid' => $category->id]) + 1;
    $field->required = 0;
    $field->locked = 0;
    $field->visible = 1; // Visible to user? 1=Visible, 2=Not visible, 0=Hidden
    $field->forceunique = 0;
    $field->signup = 0;
    $field->defaultdata = '';
    $field->defaultdataformat = FORMAT_HTML;
    $field->param1 = 30; // Display size
    $field->param2 = 100; // Max length
    
    $field->id = $DB->insert_record('user_info_field', $field);
    echo "SUCCESS: Field '{$shortname}' created with ID: {$field->id}\n";
}

echo "\nGMK SETUP END";
