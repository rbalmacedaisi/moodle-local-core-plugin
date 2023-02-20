<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin Page - Grupo Makro
 *
 * @package     local_grupomakro_core
 * @copyright   2022 Solutto <dev@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/editclass.php'));
$PAGE->set_title(get_string('edit_class', $plugin_name));
$PAGE->set_heading(get_string('edit_class', $plugin_name));
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('limitedwidth');

$classid = required_param('class_id', PARAM_TEXT);
$periods = false;
if($classid == 3){
    $periods = true;
    $class_data->classname = 'Maquinaría';
    $class_data->classinstructor = 'Artur R. Mendoza';
    $class_data->classcompany = 'Isi Panamá';
    $classt_data->startdate = '2023-01-20';
    $class_data->state = 'gk-col';
    $class_data->classtype = 'virtual';
    $class_data->classschedule = '20:00';
}else if($classid == 1){
    $periods = false;
    $class_data->classname = 'Soldadura';
    $class_data->classinstructor = 'Jorge N. Woods';
    $class_data->classcompany = 'Grupo Makro Colombia';
    $class_data->startdate = '2023-01-20';
    $class_data->state = 'gk-col';
    $class_data->classtype = 'virtual';
    $class_data->classschedule = '15:00';
}else if($classid == 2){
    $class_data->classid = 2;
    $class_data->classname = 'Maquinaría';
    $class_data->classinstructor = 'George R. Mendoza';
    $class_data->classcompany = 'Grupo Makro México';
    $class_data->startdate = '2023-01-30';
    $class_data->state = 'gk-mex';
    $class_data->classtype = 'Presencial';
    $class_data->classschedule = '10:00';
}else{
    $class_data->classid = 4;
    $class_data->classname = 'Maquinaría Pesada';
    $class_data->classinstructor = 'jhon R. Mejia';
    $class_data->classcompany = 'Grupo Makro Colombia';
    $class_data->startdate = '2023-01-30';
    $class_data->state = 'gk-col';
    $class_data->classtype = 'Presencial';
    $class_data->classschedule = '13:00';
}

echo $OUTPUT->header();

$templatedata = [
    'cancelurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/classmanagement.php',
    'name' => $class_data->classname,
    'instructor' => $class_data->classinstructor,
    'type' => $class_data->classtype,
    'isperiods' => $periods,
    'startdate' => $classt_data->startdate,
    'classschedule' => $class_data->classschedule,
];

echo $OUTPUT->render_from_template('local_grupomakro_core/editclass', $templatedata);
echo $OUTPUT->footer();