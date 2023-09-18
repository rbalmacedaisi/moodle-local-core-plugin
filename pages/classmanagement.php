<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This page is responsible of managing everything related to the orders.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';
$plugin_name = 'local_grupomakro_core';

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/classmanagement.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('classmanagement', $plugin_name));
$PAGE->set_heading(get_string('classmanagement', $plugin_name));
$PAGE->set_pagelayout('base');

$classes = array_values(list_classes([]));
// print_object($classes);
// die;

echo $OUTPUT->header();
$colClasses = array();
$mexClasses = array();
$paClasses = array();
$definedColClasses = false;
$definedMexClasses = false;
$definedPaClasses = false;
foreach ($classes as $class) {
    
    $companyCode = $class->companycode;
    
    if($companyCode === 'gk-col'){
        array_push($colClasses,$class);
        $definedColClasses = true;
    }else if($companyCode === 'gk-mex'){
        array_push($mexClasses,$class);
        $definedMexClasses = true;
    }else if($companyCode=== 'isi-pa'){
        array_push($paClasses,$class);
        $definedPaClasses = true;
    }
    $class->icon = $class->type ==='0' ? 'fa fa-group':'fa fa-desktop';
    $class->classDaysString = implode('-',$class->selectedDaysES);
}

$templatedata = [
    'createurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/createcontract.php',
    'url' => $CFG->wwwroot.'/local/grupomakro_core/pages/contractmanagement.php',
    'allClasses' => $classes,
    'definedColClasses' => $definedColClasses,
    'definedMexClasses' => $definedMexClasses,
    'definedPaClasses' => $definedPaClasses,
    'colClasses' => $colClasses,
    'mexClasses' => $mexClasses,
    'paClasses' => $paClasses,
    'createclass_url' => $CFG->wwwroot . '/local/grupomakro_core/pages/createclass.php'
]; 

echo $OUTPUT->render_from_template('local_grupomakro_core/class_management', $templatedata);
$PAGE->requires->js_call_amd('local_grupomakro_core/delete_class', 'init', []);
echo $OUTPUT->footer();
