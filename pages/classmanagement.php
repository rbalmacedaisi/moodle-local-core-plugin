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

$plugin_name = 'local_grupomakro_core';

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/classmanagement.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('classmanagement', $plugin_name));
$PAGE->set_heading(get_string('classmanagement', $plugin_name));
$PAGE->set_pagelayout('base');

echo $OUTPUT->header();

// Class data.
$class_data = array();
$class_data[0]->classid = 1;
$class_data[0]->classname = 'Soldadura';
$class_data[0]->classinstructor = 'Jorge N. Woods';
$class_data[0]->classcompany = 'Grupo Makro Colombia';
$class_data[0]->startdate = '01/20/2023';
$class_data[0]->state = 'gk-col';
$class_data[1]->classid = 2;
$class_data[1]->classname = 'Maquinaría';
$class_data[1]->classinstructor = 'George R. Mendoza';
$class_data[1]->classcompany = 'Grupo Makro México';
$class_data[1]->startdate = '01/30/2023';
$class_data[1]->state = 'gk-mex';
$class_data[2]->classid = 3;
$class_data[2]->classname = 'Maquinaría';
$class_data[2]->classinstructor = 'Artur R. Mendoza';
$class_data[2]->classcompany = 'Isi Panamá';
$class_data[2]->startdate = '01/30/2023';
$class_data[2]->state = 'isi-pa';
$class_data[3]->classid = 4;
$class_data[3]->classname = 'Maquinaría Pesada';
$class_data[3]->classinstructor = 'jhon R. Mejia';
$class_data[3]->classcompany = 'Grupo Makro Colombia';
$class_data[3]->startdate = '01/30/2023';
$class_data[3]->state = 'gk-col';

$data = array();
$gk_col = array();
$gk_mex = array();
$isi_pa = array();
$is_col = false;
$is_mex = false;
$is_pa = false;
foreach ($class_data as $class) {
    array_push($data,$class);
    if($class->state == 'gk-col'){
        array_push($gk_col,$class);
        $is_col = true;
    }else if($class->state == 'gk-mex'){
        array_push($gk_mex,$class);
        $is_mex = true;
    }else if($class->state == 'isi-pa'){
        array_push($isi_pa,$class);
        $is_pa = true;
    }
    
}

$templatedata = [
    'createurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/createcontract.php',
    'url' => $CFG->wwwroot.'/local/grupomakro_core/pages/contractmanagement.php',
    'data' => $data,
    'is_col' => $is_col,
    'is_mex' => $is_mex,
    'is_pa' => $is_pa,
    'gk_col' => $gk_col,
    'gk_mex' => $gk_mex,
    'isi_pa' => $isi_pa,
    'createclass_url' => $CFG->wwwroot . '/local/grupomakro_core/pages/createclass.php'
]; 

echo $OUTPUT->render_from_template('local_grupomakro_core/class_managment', $templatedata);
echo $OUTPUT->footer();
