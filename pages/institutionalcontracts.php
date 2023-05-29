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
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/institutionalcontracts.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('institutional_contracts', $plugin_name));
$PAGE->set_heading(get_string('institutional_contracts', $plugin_name));
$PAGE->set_pagelayout('base');
$institutionId = required_param('id', PARAM_TEXT);


if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('institutionmanagement', $plugin_name), new moodle_url('/local/grupomakro_core/pages/institutionmanagement.php'));
}
$PAGE->navbar->add(
    get_string('institutional_contracts', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/institutionalcontracts.php')
);

$users = $DB->get_records('user');

$users =array_values( array_map(function ($user){
    $userMin = new stdClass();
    $userMin->fullname = $user->firstname.' '.$user->lastname;
    $userMin->email = $user->email;
    $userMin->username = $user->username;
    $userMin->id = $user->id;
    return $userMin;
},$users));

$institution = get_institution_contract_panel_info($institutionId);
// print_object(uniqid());
// die;

$courses = $DB->get_records('course');
$courses =array_values( array_map(function ($course){
    $courseMin = new stdClass();
    $courseMin->fullname = $course->fullname;
    $courseMin->id = $course->id;
    return $courseMin;
},$courses));

echo $OUTPUT->header();

// Generate a table with the the records from the gm_orders table.
$table = new html_table();
$table->attributes['class'] = 'table rounded mt-3 shadow-sm';
$table->id = 'contract_table';
$table->tablealign  = 'center';
$table->head = array(
    get_string('contractnumber', $plugin_name),
    get_string('startdate', $plugin_name),
    get_string('enddate', $plugin_name),
    get_string('budget', $plugin_name),
    get_string('billing_condition', $plugin_name),
    get_string('users', $plugin_name),
    get_string('options', $plugin_name),
    
);



foreach ($institution->institutionInfo->contracts as $contract) {
    $displaycontract = html_writer::start_tag('div', array('class' => 'd-flex align-items-center'));
        $displaycontract .= html_writer::start_tag('div', array('class' => 'contract-img'));
           $displaycontract .= html_writer::tag('img', '', array('src' => $CFG->wwwroot.'/local/grupomakro_core/pix/t/contract.png', 'height' => 35, 'class' => 'mr-3'));
           $displaycontract .= html_writer::tag('span', $contract->contractid, array());
        $displaycontract .= html_writer::end_tag('div');
    $displaycontract .= html_writer::end_tag('div');
    
    // Table Action Icons.
    $modifyicon = html_writer::tag('i', '', array('class' => 'fa fa-gear', 'style'=>'font-size: 16px;'));
    $removeicon = html_writer::tag('i', '', array('class' => 'fa fa-trash', 'style'=>'font-size: 16px;'));
    
    // Contract Table Actions.
    $options_buttons = html_writer::link(
        new moodle_url('/local/grupomakro_core/pages/editcontractinstitutionals.php?id='.$contract->id.'&institutionId='.$contract->institutionid),
        $modifyicon,
        array(
            'class' => 'mx-1',
            'data-toggle' => 'tooltip',
            'data-placement' => 'bottom',
            'title' => get_string(
                'modify', $plugin_name
            )
        )
    );
    
    $options_buttons .= html_writer::tag('a',
        $removeicon,
        array(
            'class' => 'mx-1 remove-contract',
            'data-toggle' => 'tooltip',
            'data-placement' => 'bottom',
            'title' => get_string(
                'remove', $plugin_name
            ),
            'data-toggle'=>'modal', 
            'data-target'=> '#confirmModalCenter',
            'contract-id'=>$contract->id
        )
    );
    
    // Fill the table with the contract data.
    $table->data[] = array(
        $displaycontract,
        $contract->formattedInitDate, 
        $contract->formattedExpectedEndDate,
        $contract->formattedBudget, 
        $contract->formattedBillingCondition, 
        $contract->usersCount,
        $options_buttons
    );
}

// Generate a table with the the records from the gm_orders table.
$userstable = new html_table();
$userstable->attributes['class'] = 'table rounded mt-3 shadow-sm';
$userstable->id = 'users_table';
$userstable->tablealign  = 'center';
$userstable->head = array(
    get_string('user', $plugin_name),
    get_string('phone', $plugin_name),
    get_string('courses', $plugin_name),
    get_string('contracts', $plugin_name),
    get_string('actions', $plugin_name),
    
);



foreach ($institution->contractUsers as $contractUser) {
    
    $userprofile = html_writer::start_tag('div', array('class' => 'd-flex align-center', 'style' => 'gap: 16px;'));
        $userprofile .= html_writer::tag('img','' , array('class' => 'fa fa-user-circle avatar-default','src'=>$contractUser->avatar, 'style' => 'max-width: 38px; '));
        $userprofile .= html_writer::start_tag('div', array('class' => ''));
            $userprofile .= html_writer::tag('h6', $contractUser->fullname, array('class' => 'mb-0'));
            $userprofile .= html_writer::tag('small', $contractUser->email, array());
        $userprofile .= html_writer::end_tag('div');
    $userprofile .= html_writer::end_tag('div');
    
    $action_button = html_writer::tag('button',
        get_string('details', 'local_grupomakro_core'), 
        array('class' => 'btn btn-primary btn-sm view-details-button', 'data-toggle' => 'modal', 'data-target' => '#userinfoModalLong','contract-user-id'=>$contractUser->userid)
    );
    
    // The contract status tag is generated.
    $countcontract = html_writer::start_tag('span', array('class' => 'v-chip n-contract'));
        $countcontract .= html_writer::tag('span', $contractUser->acquiredContracts, array('class' => 'v-chip__content'));
    $countcontract .= html_writer::end_tag('span');
    
    // Fill the table with the user data.
    $userstable->data[] = array(
        $userprofile, 
        $contractUser->phone, 
        $contractUser->coursesString,
        $countcontract,
        $action_button
    );
}
$templatedata = [
    'table' =>  html_writer::table($table),
    'usertable' => html_writer::table($userstable),
    'numberOfContracts' => $institution->institutionInfo->numberOfContracts,
    'numberOfUsers'=>$institution->institutionInfo->numberOfUsers,
    'users'=>$users,
    'courses'=>$courses,
    'contracts'=>$institution->institutionInfo->contractNames
];

echo $OUTPUT->render_from_template('local_grupomakro_core/institutionalcontracts', $templatedata);
$PAGE->requires->js_call_amd('local_grupomakro_core/institutional_contracts', 'init', [$institutionId,$institution->contractUsers,$users,$institution->institutionInfo->contractNames]);
echo $OUTPUT->footer();