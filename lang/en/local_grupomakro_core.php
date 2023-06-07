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

// Admin Menú Strings
$string['pluginname'] = 'Grupo Makro Core';
$string['plugin'] = 'Grupo Makro';
$string['emailtemplates_settingspage'] = 'Email templates';
$string['class_management'] = 'Class Management';
$string['class_schedules'] = 'Class Schedules';
$string['availability_panel'] = 'Availability Panel';
$string['availability_calendar'] = 'Availability Calendar';
$string['institution_management'] = 'Institution Management';

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
$string['update_institution'] = 'Update Institution';
$string['name_institution'] = 'Name of the Institution';
$string['delete_institution_title'] = 'Delete Institution Confirmation';
$string['delete_institution_message'] = 'Are you sure to permanently delete this institution and all related information?';

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
$string['adduser'] = 'Add User';
$string['generateEnrolLink'] = 'Generate enrol Link';
$string['enrolLinkInfo'] = 'Enrol Link Information';
$string['enrolLinkExpirationDate'] = 'Expiration Date';
$string['enrolLinkUrl'] = 'Url';
$string['copyEnrolLink'] = 'Copy Url';
$string['viewActiveEnrolLinks'] = 'View Active Links';
$string['enrollUser'] = 'Enroll User';
$string['enrolLinkGeneration'] = 'Enrol Link Generation';
$string['userlist'] = 'User List';
$string['selectusers'] = 'Select user';
$string['select_courses'] = 'Select courses';
$string['select_course'] = 'Select course';
$string['actions'] = 'Actions';
$string['userinformation'] = 'User information';
$string['details'] = 'Details';
$string['profile'] = 'Profile';
$string['memessage'] = 'Memessage';
$string['selectcontract'] = 'Select a contract';
$string['bulkConfirmationMessage'] = 'Are you sure to upload the document and create the user contracts?';
$string['contractBulkConfirmTitle'] = 'Contract creation confirmation';
$string['bulkContractCreationReportTitle'] = 'Contract creation results';
$string['bulkUserIndex'] = 'CSV Index';
$string['bulkUserError'] = 'Error';

// Contract Enrol Page.
$string['contractenrol'] = 'Contract Enrol';
$string['contractenrollinkexpirated'] = 'This link has expired.';
$string['invalidtoken'] = 'Invalid Token.';
$string['enrol'] = 'Enrol';
$string['enrolUserNotFoundModalMessage'] = 'It seems the document provided is not registered, either create a new account or try again.';
$string['enrolCreateAccount'] = 'Create account';
$string['enrolTryAgain'] = 'Try again';
$string['enrolUserNotFound'] = 'User not found';
$string['enrolGeneralInformation'] = 'General information';
$string['enrolContractLabel'] = 'Contract: ';
$string['enrolCourseLabel'] = 'Course name: ';
$string['enrolUserDocumentLabel'] = 'Document Number';
$string['enrolUserFirstName'] = 'FirstName';
$string['enrolUserLastName'] = 'LastName';
$string['enrolUserEmail'] = 'Email';

// Class Management Page.
$string['classmanagement'] = 'Class Management';
$string['createclass'] = 'Create Class';
$string['allclasses'] = 'All the classes';
$string['confirm_delete_class'] = 'Delete class confirmation';
$string['instances'] = 'Instances';
$string['select_instance'] = 'Select instance';
$string['period'] = 'Periods';
$string['select_period'] = 'Select period';
$string['instructor'] = 'Instructor';
$string['select_instructor'] = 'Select Instructor';
$string['number_classes'] = 'Number of classes';
$string['start_time'] = 'Start Time';
$string['end_time'] = 'End Time';
$string['class_type'] = 'Class Type';
$string['select_type_class'] = 'Select the type of class';
$string['monday'] = 'Monday';
$string['tuesday'] = 'Tuesday';
$string['wednesday'] = 'Wednesday';
$string['thursday'] = 'Thursday';
$string['friday'] = 'Friday';
$string['saturday'] = 'Saturday';
$string['sunday'] = 'Sunday';
$string['classdays'] = 'Class days';
$string['delete_class'] = 'Are you sure to permanently delete this class and all related information?';
$string['edit_class'] = 'Edit Class';
$string['class_name'] = 'Class Name';
$string['new_date'] = 'New date';
$string['check_availability'] = "View instructor's availability";
$string['rescheduling_activity'] = 'Rescheduling activity of ';
$string['reschedule'] = 'Reschedule';
$string['confirm_reschedule_title'] = 'Reschedule Confirmation';

// Schedules page.
$string['schedules'] = 'Schedules';
$string['availability'] = 'Availability';
$string['availability_panel'] = 'Availability panel';
$string['today'] = 'Today';
$string['add'] = 'Add';
$string['day'] = 'Day';
$string['week'] = 'Week';
$string['month'] = 'Month';
$string['instructors'] = 'Instructors';
$string['scheduledclasses'] = 'Scheduled classes';
$string['close'] = 'Close';
$string['reschedule'] = 'Reschedule';
$string['desc_rescheduling'] = 'Describe the reason for rescheduling';
$string['available_hours'] = 'Available Hours';
$string['available'] = 'Available';
$string['delete_available'] = 'Are you sure you want to remove this availability?';
$string['delete_available_confirm'] = 'By clicking accept, all related data will be deleted.';
$string['add_availability'] = 'Add Availability.';
$string['days'] = 'Days';
$string['field_required'] = 'This field is required';
$string['add_schedule'] = 'Add Schedule';
$string['competences'] = 'Competences';
$string['causes_rescheduling'] = 'Causes of rescheduling';
$string['select_possible_date'] = 'Select possible date';
$string['new_class_time'] = 'New class time';
$string['activity'] = 'Activity';
$string['unable_complete_action'] = 'Unable to complete action. The range selected for editing has scheduled classes.';
$string['create'] = 'Create';

// Schedule Panel page.
$string['schedule_panel'] = 'Schedule Panel';
$string['scheduleapproval'] = 'Schedule approval';
$string['waitingusers'] = 'Waiting users';
$string['selection_schedules'] = 'Selection of Schedules';
$string['nodata'] = 'There is no data';
$string['approve_schedules'] = 'Approve Schedules';
$string['registered_users'] = 'Registered Users';
$string['waitinglist'] = 'Waiting list';
$string['approved'] = 'Approved';
$string['class_schedule'] = 'Class schedule';
$string['quotas_enabled'] = 'Quotas Enabled';
$string['registered_users'] = 'Registered Users';
$string['approve_users'] = 'Approve Users';
$string['move_to'] = 'Move to:';
$string['current_location'] = 'Current location:';
$string['student'] = 'Student';
$string['message_approved'] = 'The user list for this class has been approved.';
$string['maximum_quota_message'] = 'The selected class exceeds the maximum quota allowed.';
$string['want_to_approve'] = 'Are you sure you want to approve it?';
$string['mminimum_quota_message'] = 'The selected class does not have the minimum number of students allowed.';
$string['write_reason'] = 'Write the reason';

//Message providers names
$string['messageprovider:send_reschedule_message'] = 'Send reschedule message';

//Reschedule Message Body.
$string['msg:send_reschedule_message:body'] = '
<h2>The teacher <strong>{$a->instructorFullName}</strong> has requested a rescheduling for the following reasons:</h2>
<q>{$a->causeNames}</q>
<h3>Class Information:</h3>
<ul>
    <li><strong>Name: </strong>{$a->name}</li>
    <li><strong>Schedule: </strong>{$a->originalDate} ({$a->originalHour})</li>
    <li><strong>Course: </strong>{$a->coreCourseName}</li>
    <li><strong>Type: </strong>{$a->typeLabel}</li>
</ul>
<h3>Proposed schedule:</h3>
<ul>
    <li><strong>Day: </strong>{$a->proposedDate}</li>
    <li><strong>Hour: </strong>{$a->proposedHour}</li>
</ul>
<p>To reschedule the session click <a href="{a->rescheduleUrl}">here.</a></p>';

$string['msg:send_reschedule_message:subject'] ='New reschedule request';
$string['msg:send_reschedule_message:contexturlname'] ='Reschedule session';
