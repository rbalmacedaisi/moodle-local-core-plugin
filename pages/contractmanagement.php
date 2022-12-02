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

// Requiere the grupomakro_core:seeallorders capability.
require_capability('local/grupomakro_core:seeallorders', context_system::instance());

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/contractmanagement.php');

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_title(get_string('contract_management', $plugin_name));
$PAGE->set_heading(get_string('contract_management', $plugin_name));
$PAGE->set_pagelayout('base');

echo $OUTPUT->header();

// Contract data.
$contrac_data = array();
$contrac_data[0]->contract_id = '2022A23658';
$contrac_data[0]->carrername = 'Soldadura';
$contrac_data[0]->user = 'john@user.com';
$contrac_data[0]->adviser = 'Ximena Rincón';
$contrac_data[0]->state = 'Verificación';
$contrac_data[1]->contract_id = '2022A23657';
$contrac_data[1]->carrername = 'Maquinaría';
$contrac_data[1]->user = 'alexa@user.com';
$contrac_data[1]->adviser = 'Ximena Rincón';
$contrac_data[1]->state = 'Corrección';
$contrac_data[2]->contract_id = '2022A23652';
$contrac_data[2]->carrername = 'Ingeniería';
$contrac_data[2]->user = 'laurent@user.com';
$contrac_data[2]->adviser = 'Ximena Rincón';
$contrac_data[2]->state = 'Activo';


// Generate a table with the the records from the gm_orders table.
$table = new html_table();
$table->attributes['class'] = 'table rounded mt-3';
$table->id = 'contract_table';
$table->tablealign  = 'center';
$table->head = array(
    get_string('cid', $plugin_name),
    get_string('careers', $plugin_name),
    get_string('user', $plugin_name),
    get_string('adviser', $plugin_name),
    get_string('state', $plugin_name),
    get_string('payment_link', $plugin_name),
    get_string('options', $plugin_name),
    
);

foreach ($contrac_data as $contract) {
    $displaycontract = html_writer::start_tag('div', array('class' => 'd-flex align-items-center'));
        $displaycontract .= html_writer::start_tag('div', array('class' => 'contract-img'));
           $displaycontract .= html_writer::tag('img', '', array('src' => $CFG->wwwroot.'/local/grupomakro_core/pix/t/contract.png', 'height' => 35, 'class' => 'mr-3'));
           $displaycontract .= html_writer::tag('span', $contract->contract_id, array());
        $displaycontract .= html_writer::end_tag('div');
    $displaycontract .= html_writer::end_tag('div');
    
    $vchipclass = '';
    if($contract->state == 'Verificación'){
        $vchipclass = 'state_v';
    }else if($contract->state == 'Corrección'){
        $vchipclass = 'state_c';
    }else{
        $vchipclass = 'state_a';
    }
    
    // The contract status tag is generated.
    $status = html_writer::start_tag('span', array('class' => $vchipclass .' v-chip'));
        $status .= html_writer::tag('span', $contract->state, array('class' => 'v-chip__content'));
    $status .= html_writer::end_tag('span');
    
    // button to generate the payment link.
    $payment_button = html_writer::tag('button', get_string('generate', $plugin_name), array('class' => 'btn btn-link btn-sm mr-2'));
    
    // Table Action Icons.
    $visualizeicon = html_writer::tag('i', '', array('class' => 'fa fa-folder-open-o'));
    $modifyicon = html_writer::tag('i', '', array('class' => 'fa fa-gear'));
    $downloadicon = html_writer::tag('i', '', array('class' => 'fa fa-download'));
    $removeicon = html_writer::tag('i', '', array('class' => 'fa fa-trash'));
    
    // Contract Table Actions.
    $options_buttons = html_writer::link(
        new moodle_url('', ['id' => 'open']),
        $visualizeicon,
        array(
            'class' => 'mx-1',
            'data-toggle' => 'tooltip',
            'data-placement' => 'bottom',
            'title' => get_string(
                'visualize', $plugin_name
            )
        )
    );
    $options_buttons .= html_writer::link(
        new moodle_url('', ['id' => 'modify']),
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
    $options_buttons .= html_writer::link(
        new moodle_url('', ['id' => 'download']),
        $downloadicon,
        array(
            'class' => 'mx-1',
            'data-toggle' => 'tooltip',
            'data-placement' => 'bottom',
            'title' => get_string(
                'download', $plugin_name
            )
        )
    );
    $options_buttons .= html_writer::link(
        new moodle_url('', ['id' => 'remove']),
        $removeicon,
        array(
            'class' => 'mx-1',
            'data-toggle' => 'tooltip',
            'data-placement' => 'bottom',
            'title' => get_string(
                'remove', $plugin_name
            )
        )
    );
    
    
    // Fill the table with the contract data.
    $table->data[] = array(
        $displaycontract,
        $contract->carrername, 
        $contract->user,
        $contract->adviser, 
        $status, 
        $payment_button, 
        $options_buttons
    );
    
    $templatedata = [
        'table' =>  html_writer::table($table),
        'createurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/createcontract.php',
    ];
}

echo $OUTPUT->render_from_template('local_grupomakro_core/manage_contracts', $templatedata);
echo $OUTPUT->footer();
