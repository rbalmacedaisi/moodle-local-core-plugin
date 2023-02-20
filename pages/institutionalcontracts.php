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

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/institutionalcontracts.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('institutional_contracts', $plugin_name));
$PAGE->set_heading(get_string('institutional_contracts', $plugin_name));
$PAGE->set_pagelayout('base');

if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('institutionmanagement', $plugin_name), new moodle_url('/local/grupomakro_core/pages/institutionmanagement.php'));
}
$PAGE->navbar->add(
    get_string('institutional_contracts', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/institutionalcontracts.php')
);


echo $OUTPUT->header();

// Contract data.
$contract_data = array();
$contract_data[0]->contract_id = '2022A23658';
$contract_data[0]->start_date = '20-12-2022';
$contract_data[0]->end_date = '20-06-2023';
$contract_data[0]->budget = '100.000.000';
$contract_data[0]->billing_condition = '30%';
$contract_data[0]->users = '1';
$contract_data[1]->contract_id = '2022A23645';
$contract_data[1]->start_date = '20-12-2022';
$contract_data[1]->end_date = '20-06-2023';
$contract_data[1]->budget = '200.000.000';
$contract_data[1]->billing_condition = '20%';
$contract_data[1]->users = '1';
$contract_data[2]->contract_id = '2022A23646';
$contract_data[2]->start_date = '03-01-2023';
$contract_data[2]->end_date = '03-06-2023';
$contract_data[2]->budget = '200.000.000';
$contract_data[2]->billing_condition = '10%';
$contract_data[2]->users = '1';

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

foreach ($contract_data as $contract) {
    $displaycontract = html_writer::start_tag('div', array('class' => 'd-flex align-items-center'));
        $displaycontract .= html_writer::start_tag('div', array('class' => 'contract-img'));
           $displaycontract .= html_writer::tag('img', '', array('src' => $CFG->wwwroot.'/local/grupomakro_core/pix/t/contract.png', 'height' => 35, 'class' => 'mr-3'));
           $displaycontract .= html_writer::tag('span', $contract->contract_id, array());
        $displaycontract .= html_writer::end_tag('div');
    $displaycontract .= html_writer::end_tag('div');
    
    // Table Action Icons.
    $modifyicon = html_writer::tag('i', '', array('class' => 'fa fa-gear', 'style'=>'font-size: 16px;'));
    $removeicon = html_writer::tag('i', '', array('class' => 'fa fa-trash', 'style'=>'font-size: 16px;'));
    
    // Contract Table Actions.
    $options_buttons = html_writer::link(
        new moodle_url('/local/grupomakro_core/pages/editcontractinstitutionals.php?cid='.$contract->contract_id),
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
            'data-toggle'=>'modal', 'data-target'=> '#confirmModalCenter'
        )
    );
    
    // Fill the table with the contract data.
    $table->data[] = array(
        $displaycontract,
        $contract->start_date, 
        $contract->end_date,
        $contract->budget, 
        $contract->billing_condition, 
        $contract->users,
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

// Users data.
$users_data = array();
$users_data[0]->username = 'Nataly Hoyos';
$users_data[0]->email = 'natalyhoyos@solutto.com';
$users_data[0]->phone = '3003458905';
$users_data[0]->courses = 'Maquinaria Amarilla';
$users_data[0]->avatar = $CFG->wwwroot.'/local/grupomakro_core/pix/t/avatar.jpg';
$users_data[0]->contracts = '1';
$users_data[1]->username = 'Sergio Mejia';
$users_data[1]->email = 'sergiomejia@solutto.com';
$users_data[1]->phone = '3003450000';
$users_data[1]->courses = 'Maquinaria Pesada';
$users_data[1]->avatar = $CFG->wwwroot.'/local/grupomakro_core/pix/t/avatar.jpg';
$users_data[1]->contracts = '1';
$users_data[2]->username = 'Luz Lopez';
$users_data[2]->email = 'luzlopez@solutto.com';
$users_data[2]->phone = '3003250000';
$users_data[2]->courses = 'Maquinaria Pesada';
$users_data[2]->avatar = $CFG->wwwroot.'/local/grupomakro_core/pix/t/avatar.jpg';
$users_data[2]->contracts = '1';

foreach ($users_data as $user) {
    
    $userprofile = html_writer::start_tag('div', array('class' => 'd-flex align-center', 'style' => 'gap: 16px;'));
        $userprofile .= html_writer::tag('i', '', array('class' => 'fa fa-user-circle avatar-default'));
        $userprofile .= html_writer::start_tag('div', array('class' => ''));
            $userprofile .= html_writer::tag('h6', $user->username, array('class' => 'mb-0'));
            $userprofile .= html_writer::tag('small', $user->email, array());
        $userprofile .= html_writer::end_tag('div');
    $userprofile .= html_writer::end_tag('div');
    
    $action_button = html_writer::tag('button',
        get_string('details', 'local_grupomakro_core'), 
        array('class' => 'btn btn-primary btn-sm', 'data-toggle' => 'modal', 'data-target' => '#userinfoModalLong')
    );
    
    // The contract status tag is generated.
    $countcontract = html_writer::start_tag('span', array('class' => 'v-chip n-contract'));
        $countcontract .= html_writer::tag('span', $user->contracts, array('class' => 'v-chip__content'));
    $countcontract .= html_writer::end_tag('span');
    
    // Fill the table with the user data.
    $userstable->data[] = array(
        $userprofile, 
        $user->phone, 
        $user->courses,
        $countcontract,
        $action_button
    );
}
$templatedata = [
    'table' =>  html_writer::table($table),
    'createurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/createcontractinstitutional.php',
    'usertable' => html_writer::table($userstable)
];

echo $OUTPUT->render_from_template('local_grupomakro_core/institutionalcontracts', $templatedata);
echo $OUTPUT->footer();