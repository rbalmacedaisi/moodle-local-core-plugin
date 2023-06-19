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
require_once($CFG->dirroot . '/local/sc_learningplans/external/learning/get_active_learning_plans.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/createclass.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('createclass', $plugin_name));
$PAGE->set_heading(get_string('createclass', $plugin_name));
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('limitedwidth');

if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('classmanagement', $plugin_name), new moodle_url('/local/grupomakro_core/pages/classmanagement.php'));
}
$PAGE->navbar->add(
    get_string('createclass', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/createclass.php')
);

// Get the active learning plans with careers and format the object passed to the mustache
$activeLearningPlans = get_active_learning_plans_external::get_active_learning_plans();
$formattedAvailableCareers = [];
$availableCareers =json_decode($activeLearningPlans['availablecareers']); 
foreach($availableCareers as $careerName => $careerInfo){
    array_push($formattedAvailableCareers, ['value'=>$careerInfo->lpid, 'label'=>$careerName]);
}
// 

echo $OUTPUT->header();

$classTypes = [
    ['value'=>1, 'label'=>'Virtual'],
    ['value'=>0, 'label'=>'Presencial'],
    ['value'=>2, 'label'=>'Mixta'],
    
];

$templatedata = [
    'cancelurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/classmanagement.php',
    'availabilityPanelUrl' => $CFG->wwwroot.'/local/grupomakro_core/pages/availabilitypanel.php',
    'classTypes' => $classTypes,
    'availableCareers' => $formattedAvailableCareers
];

echo $OUTPUT->render_from_template('local_grupomakro_core/create_class', $templatedata);
$PAGE->requires->js_call_amd('local_grupomakro_core/create_class', 'init', []);
echo $OUTPUT->footer();