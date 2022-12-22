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
 * Solutto LMS Core is a plugin used by the various components developed by Solutto.
 *
 * @package    local_soluttolms_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Grupo Makro Core';
$string['emailtemplates_settingspage'] = 'Email templates';

// Capabilities.
$string['grupomakro_core:seeallorders'] = 'See all orders';

// Email template sent when a new student is registrered.
$string['emailtemplates_welcomemessage_student'] = 'Welcome message new student';
$string['emailtemplates_welcomemessage_student_desc'] = 'This is the template used to send the welcome message to new students. You can use the following placeholders: {firstname}, {lastname}, {username}, {email}, {sitename}, {siteurl}, {password}';
$string['emailtemplates_welcomemessage_student_default'] = 'Welcome {firstname} {lastname}!';

// Email template sent when a new caregiver is registrered.
$string['emailtemplates_welcomemessage_caregiver'] = 'Welcome message new student';
$string['emailtemplates_welcomemessage_caregiver_desc'] = 'This is the template used to send the welcome message to new caregivers. You can use the following placeholders: {firstname}, {lastname}, {username}, {email}, {sitename}, {siteurl}, {password}';
$string['emailtemplates_welcomemessage_caregiver_default'] = 'Welcome {firstname} {lastname}!';

// Subject of the email sent when a new user is registrered.
$string['emailtemplates_welcomemessage_subject'] = 'Welcome to Grupo Makro';

// Settings page: Financial settings.
$string['financial_settingspage'] = 'Financial Details';
$string['tuitionfee'] = 'Tuition Fee';
$string['tuitionfee_desc'] = 'This is the price of the tuition fee defined for all the users across the site.';
$string['tuitionfee_discount'] = 'Tuition Fee Discount';
$string['tuitionfee_discount_desc'] = 'This is the % of discount applied to the tuition fee defined for all the users across the site.';
$string['currency'] = 'Currency';
$string['currency_desc'] = 'This is the currency used across the site.';
$string['USD'] = 'USD - United States Dollar';
$string['EUR'] = 'EUR - Euro';
$string['COP'] = 'COP - Colombian Peso';
$string['MXN'] = 'MXN - Mexican Peso';
$string['PEN'] = 'PEN - Peruvian Sol';
$string['decsep'] = 'Decimal separator';
$string['thousandssep'] = 'Thousands separator';
$string['decsep_dot'] = 'Dot (.)';
$string['decsep_comma'] = 'Comma (,)';
$string['thousandssep_comma'] = 'Comma (,)';
$string['thousandssep_dot'] = 'Dot (.)';
$string['thousandssep_space'] = 'Space ( )';
$string['thousandssep_none'] = 'None';

// Orders page.
$string['orders'] = 'Orders';
$string['oid'] = 'Order ID';
$string['fullname'] = 'Full Name';
$string['itemtype'] = 'Item Type';
$string['itemname'] = 'Item Name';
$string['order_date'] = 'Order Date';
$string['order_status'] = 'Order Status';
$string['order_total'] = 'Order Total';
$string['order_dateupdated'] = 'Updated Date';

// Contract Management Page.
$string['contract_management'] = 'Contract management';
$string['cid'] = 'Contract';
$string['careers'] = 'Careers';
$string['user'] = 'User';
$string['state'] = 'State';
$string['payment_link'] = 'Payment link';
$string['options'] = 'Options';
$string['create_contract'] = 'Create Contract';
$string['generate'] = 'Generate';
$string['visualize'] = 'Visualize';
$string['modify'] = 'Modify';
$string['remove'] = 'Remove';
$string['download'] = 'Download';
$string['adviser'] = 'Adviser';
$string['msgconfirm'] = 'Are you sure to remove the final form of this contract and all related information?';
$string['titleconfirm'] = 'Contractonfirm deletion';
$string['search'] = 'Search';
$string['payment_link_message'] = 'A payment link has been generated for the order, the user associated with the contract will be notified.';
$string['approve_message'] = 'The contract has been approved, the user will be notified that they have a contract ready to sign.';
$string['fixes_message'] = 'The user associated with the contract will be notified about the corrections to be made.';

// Contract Creation Page.
$string['title_add_users'] = 'Manage Users';
$string['select_user'] = 'Select a user';
$string['periodicityPayments'] = 'Periodicity Payments';
$string['general_terms'] = 'General Terms';
$string['manage_careers'] = 'Manage Careers';
$string['select_careers'] = 'Celect Careers';
$string['payment_type'] = 'Payment Type';
$string['select_payment_type'] = 'Select Payment type';
$string['cancel'] = 'Cancel';
$string['continue'] = 'Continue';
$string['next'] = 'Next';
$string['save'] = 'Save';
$string['back'] = 'Back';
$string['credit_terms'] = 'Credit Terms';
$string['select_periodicity'] = 'Select the periodicity';
$string['number_quotas'] = 'Number of Quotas';
$string['payment_date'] = 'Payment date';
$string['need_co-signer'] = 'Do you need a co-signer?';
$string['cosigner_information'] = 'Co-signer Information';
$string['name_co_signer'] = 'Name of the co-signer';
$string['identification_number'] = 'Identification number';
$string['phone'] = 'Phone';
$string['workplace'] = 'Workplace';
$string['msgcreatecontract'] = 'A new contract has been created, the associated user will be notified and will be able to see the new contract in their dashboard.';
$string['upload_documents'] = 'Upload Documents';
$string['identification_document'] = 'Identification document';
$string['photo_profile_picture'] = 'Photo (profile picture)';
$string["bachelor's_diploma"] = "Bachelor's or expert's diploma";
$string["personal_reference_letter"] = 'Personal Reference Letter';
$string["medical_certificate"] = 'Medical certificate';
$string["diving_certificate"] = 'Diving Certificate';
$string["work_letter"] = 'Work Letter';
$string["select_date"] = 'Select a date';
$string["scheduled_installments"] = 'Scheduled Installments';
$string['step'] = 'Step';

// General settings page.
$string['general_settingspage'] = 'General Settings';
$string['inactiveafter_x_hours'] = 'Inactive after "X" number of hours';
$string['inactiveafter_x_hours_desc'] = '
<p>This is the number of hours after which a user is considered inactive.</p>
<p>For example, if you set this value to 24, then a user will be considered inactive if he/she has not logged in for 24 hours, and that user will be deleted.</p>
<p><strong>NOTE:</strong> This setting is used to keep the platform clean of users that are not really interested in taking any course or career in the system.</p>';

// Scheduled tasks.
$string['taskinactiveusers'] = 'Delete inactive users';

// Edit contract page.
$string['editcontract'] = 'Edit Contract';
$string['defer'] = 'Defer';
$string['re_asign'] = 'Re asign';
$string['user'] = 'User';
$string['msndeferring'] = 'By deferring the contract, all related payments will be frozen and the contract inactive.';
$string['accept'] = 'Accept';
$string['list_advisers'] = 'List of advisers';
$string['select_advisor'] = 'Select an advisor';
$string['reassign_contract'] = 'Reassign contract';
$string['cancel_contract'] = 'Cancel Contract';
$string['msncancel'] = "Are you sure you want to cancel the contract? <br>This action cannot be undone, both the financial information related to the contract and the student's status will change to inactive.";
$string['documents'] = 'Documents';
$string['approve'] = 'Approve';
$string['correct'] = 'Correct';
$string['fixes'] = 'Fixes';

// Institutions Management Page.
$string['institutionmanagement'] = 'Institution Management';
$string['edit'] = 'Edit';
$string['see'] = 'See';
$string['create_institution'] = 'Create Institution';
$string['name_institution'] = 'Name of the Institution';

// Institutional Contracts Page.
$string['institutional_contracts'] = 'Institutional Contracts';
$string['contractnumber'] = 'Contract number';
$string['startdate'] = 'Start Date';
$string['enddate'] = 'End Date';
$string['budget'] = 'Budget';
$string['billing_condition'] = 'Billing condition';
$string['users'] = 'Users';
$string['contracts'] = 'Contracts';
$string['totalusers'] = 'Total Users';
$string['name'] = 'Name';
$string['email'] = 'Email';
$string['phone'] = 'Phone';
$string['courses'] = 'Courses';
$string['user'] = 'User';