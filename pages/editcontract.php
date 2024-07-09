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

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/editcontract.php'));
$PAGE->set_title(get_string('editcontract', $plugin_name));
$PAGE->set_heading(get_string('editcontract', $plugin_name));
$PAGE->set_pagelayout('base');

$contract_state = required_param('contract_state', PARAM_TEXT);
$contract_id = required_param('cid', PARAM_TEXT);

// Contract data.
$contract_data->username = 'john@user.com';
$contract_data->learningname = 'SOLDADURA SUBACUÁTICA';
$contract_data->typepayment = 'Crédito';
//$contrac_data->typepayment = 'Contado';
$contract_data->is_credit = false;
$contract_data->is_contractverify = false;
$contract_data->is_contractcorrection = false;
$contract_data->is_contractactive = false;
$contract_data->is_contractcreate = false;
$contract_data->is_contractaproved = false;
$contract_data->is_digitalsignature = false;

// We validate if the type of payment is credit.
if($contract_data->typepayment == 'Crédito'){
    $contract_data->is_credit = true;
    $contract_data->periodicity_payments = 'Mensual';
    $contract_data->number_installments = '3 Cuotas';
    $contract_data->payment_date = '15-12-2022';
    $contract_data->need_co_signer = true;
    if($contract_data->need_co_signer == true){
        $contract_data->namecosigner = 'Carlos';
        $contract_data->identification_number = '1128445678';
        $contract_data->phone = '3004567894';
        $contract_data->workplace = 'Solutto';
    }
}

// Contract Actions.
$actionbuttons = html_writer::tag('button', 
    get_string('re_asign', 'local_grupomakro_core'), 
    array('class' => 'btn btn-secondary', 'type' => 'button', 'data-toggle' => 'modal', 'data-target' => '#reasigncontractModalLong')
);

// We validate the status parameter in the url to activate the actions.
if($contract_state === 'Activo' || $contract_state === 'Aprobado'){
    $actionbuttons .= html_writer::tag('button', 
        get_string('defer', 'local_grupomakro_core'), 
        array('class' => 'btn btn-secondary', 'type' => 'button', 'data-toggle' => 'modal', 'data-target' => '#deferringcontractModalLong')
    );
    $actionbuttons .= html_writer::tag('button', 
        get_string('cancel', 'local_grupomakro_core'), 
        array('class' => 'btn btn-secondary', 'type' => 'button', 'data-toggle' => 'modal', 'data-target' => '#cancelcontractModalLong')
    );
}

// Validation of the contract status.
if($contract_state === 'Verificación'){
    $contract_data->is_contractverify = true;
}else if($contract_state === 'Corrección'){
    $contract_data->is_contractcorrection = true;
}else if($contract_state === 'Activo'){
    $contract_data->is_contractactive = true;
}else if($contract_state === 'Creación'){
    $contract_data->is_contractcreate = true;
}else if($contract_state === 'Aprobado'){
    $contract_data->is_contractaproved = true;
}else if($contract_state == 'Firma digital'){
    $contract_data->is_digitalsignature = true;
}

// Document data.
$documents_data = array();
$documents_data[0]->document_id = '1';
$documents_data[0]->documentname = 'Documento de identificación';
$documents_data[0]->src = '/';
$documents_data[1]->document_id = '2';
$documents_data[1]->documentname = 'Foto (imagen de perfil)';
$documents_data[1]->src = '/';
$documents_data[2]->document_id = '3';
$documents_data[2]->documentname = 'Diploma de bachiller';
$documents_data[2]->src = '/';
$documents_data[3]->document_id = '4';
$documents_data[3]->documentname = 'Carta de referencia personal';
$documents_data[3]->src = '/';

$data = array();
foreach ($documents_data as $document) {
    array_push($data,$document);
}

echo $OUTPUT->header();

$templatedata = [
    'cancelurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/contractmanagement.php',
    'username' => $contract_data->username,
    'learningname' => $contract_data->learningname,
    'periodicity_payments' => $contract_data->periodicity_payments,
    'typepayment' => $contract_data->typepayment,
    'is_credit' => $contract_data->is_credit,
    'number_installments' => $contract_data->number_installments,
    'payment_date' => $contract_data->payment_date,
    'need_co_signer' => $contract_data->need_co_signer,
    'namecosigner' => $contract_data->namecosigner,
    'identification_number' => $contract_data->identification_number,
    'phone' => $contract_data->phone,
    'workplace' => $contract_data->workplace,
    'is_contractverify' => $contract_data->is_contractverify,
    'is_contractcorrection' => $contract_data->is_contractcorrection,
    'is_contractactive' => $contract_data->is_contractactive,
    'is_contractcreate' => $contract_data->is_contractcreate,
    'is_contractaproved' => $contract_data->is_contractaproved,
    'is_digitalsignature' => $contract_data->is_digitalsignature,
    'actionbuttons' => $actionbuttons,
    'data' => $data,
    'contract_id' => $contract_id,
];


echo $OUTPUT->render_from_template('local_grupomakro_core/edit_contract', $templatedata);
echo $OUTPUT->footer();