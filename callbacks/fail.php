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
approval_code: N:-5993:Cancelled by user
txndate_processed: 10/20/22 09:35:27 PM
timezone: America/Mexico_City
response_hash: BF7QsRIbMGIs59EWl3ufbGBh0PdiuyQXwUERcjKinTs=
fail_rc: 5993
oid: C-f653e13e-a7dc-4f9e-ab5a-96f10156ae8d
tdate: 1666294527
installments_interest: false
cccountry: N/A
ccbrand: N/A
hash_algorithm: HMACSHA256
txntype: sale
currency: 840
txndatetime: 2022:10:20-12:51:11
ipgTransactionId: 84610664304
fail_reason: Interrupción por parte del usuario
chargetotal: 1.00
status: FALLADO
*////////////////////////////////////

error_log(print_r($_POST, true), 3, $CFG->dataroot.'/fail.log');