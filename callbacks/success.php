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


error_log(print_r($_POST, true), 3, $CFG->dataroot.'/success.log');