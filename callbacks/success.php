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
 * Grupo Makro Core is a plugin used by the various components developed for the Grupo Makro platform.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Require the config.php file.
require_once(__DIR__ . '../../../config.php');

/*********************************
txndate_processed: 10/20/22 08:32:17 PM
ccbin: 410923
timezone: America/Mexico_City
oid: C-380051b2-56a3-4a41-a334-dda1a504abef
cccountry: USA
expmonth: 12
hash_algorithm: HMACSHA256
endpointTransactionId: 567844
currency: 840
processor_response_code: 00
chargetotal: 1.00
terminal_id: F010114
approval_code: Y:060351:4610659785:PPXX:567844
expyear: 2022
response_hash: wWZdbfBi1a3cbW4kcjwKYogzfwz3bm9l66a8Wp4sHMw=
response_code_3dsecure: 5
tdate: 1666290737
installments_interest: false
bname: Daniel
ccbrand: VISA
refnumber: 000610659785
txntype: sale
paymentMethod: V
txndatetime: 2022:10:20-12:51:10
cardnumber: (VISA) ... 5009
ipgTransactionId: 84610659785
status: APROBADO
*////////////////////////////////////

// Get the data from the POST request.
$txndateprocessed = $_POST['txndate_processed'];
$ccbin = $_POST['ccbin'];
$timezone = $_POST['timezone'];
$oid = $_POST['oid'];
$cccountry = $_POST['cccountry'];
$expmonth = $_POST['expmonth'];
$hashalgorithm = $_POST['hash_algorithm'];
$endpointTransactionId = $_POST['endpointTransactionId'];
$currency = $_POST['currency'];
$processor_response_code = $_POST['processor_response_code'];
$chargetotal = $_POST['chargetotal'];
$terminalid = $_POST['terminal_id'];
$approvalcode = $_POST['approval_code'];
$expyear = $_POST['expyear'];
$response_hash = $_POST['response_hash'];
$responsecode3dsecure = $_POST['response_code_3dsecure'];
$tdate = $_POST['tdate'];
$installmentsinterest = $_POST['installments_interest'];
$bname = $_POST['bname'];
$ccbrand = $_POST['ccbrand'];
$refnumber = $_POST['refnumber'];
$txntype = $_POST['txntype'];
$paymentMethod = $_POST['paymentMethod'];
$txndatetime = $_POST['txndatetime'];
$cardnumber = $_POST['cardnumber'];
$ipgTransactionId = $_POST['ipgTransactionId'];
$status = $_POST['status'];

// Get the order from the gmk_order table based on the oid field.
$order = $DB->get_record('gmk_order', array('oid' => $oid));

// If the order exists, let's update the status and the payment method.
if ($order) {
    // Update the order status.
    $order->status = $status;
    $order->payment_method = $paymentMethod;
    $order->usermodified = 0;
    $order->timemodified = time();
    $DB->update_record('gmk_order', $order);

    profile_save_custom_fields($order->userid, array('needfirsttuition' => 'no'));

}

error_log(print_r($_POST, true), 3, $CFG->dataroot.'/success.log');
