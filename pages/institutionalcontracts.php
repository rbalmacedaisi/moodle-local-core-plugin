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
$PAGE->set_pagelayout('incourse');


$PAGE->navbar->add(get_string('administrationsite'), new moodle_url('/admin/search.php'));
$PAGE->navbar->add(get_string('pluginname', $plugin_name), new moodle_url('/local/grupomakro_core/pages/institutionalcontracts.php'));


echo $OUTPUT->header();

// Contract data.
$contract_data = array();
$contract_data[0]->contract_id = '2022A23658';
$contract_data[0]->start_date = '20-12-2022';
$contract_data[0]->end_date = '20-06-2023';
$contract_data[0]->budget = '100.000.000';
$contract_data[0]->billing_condition = '30%';
$contract_data[0]->users = '10';
$contract_data[1]->contract_id = '2022A23645';
$contract_data[1]->start_date = '20-12-2022';
$contract_data[1]->end_date = '20-06-2023';
$contract_data[1]->budget = '200.000.000';
$contract_data[1]->billing_condition = '20%';
$contract_data[1]->users = '20';


// Generate a table with the the records from the gm_orders table.
$table = new html_table();
$table->attributes['class'] = 'table rounded mt-3';
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
    $modifyicon = html_writer::tag('i', '', array('class' => 'fa fa-gear'));
    $removeicon = html_writer::tag('i', '', array('class' => 'fa fa-trash'));
    
    // Contract Table Actions.
    $options_buttons = html_writer::link(
        new moodle_url('/local/grupomakro_core/pages/editcontract.php'),
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

    
    $templatedata = [
        'table' =>  html_writer::table($table),
        'createurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/createcontract.php',
    ];
}

echo $OUTPUT->render_from_template('local_grupomakro_core/institutionalcontracts', $templatedata);
echo $OUTPUT->footer();