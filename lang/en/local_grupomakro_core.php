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

// General settings page.
$string['general_settingspage'] = 'General Settings';
$string['inactiveafter_x_hours'] = 'Inactive after "X" number of hours';
$string['inactiveafter_x_hours_desc'] = '
<p>This is the number of hours after which a user is considered inactive.</p>
<p>For example, if you set this value to 24, then a user will be considered inactive if he/she has not logged in for 24 hours, and that user will be deleted.</p>
<p><strong>NOTE:</strong> This setting is used to keep the platform clean of users that are not really interested in taking any course or career in the system.</p>';

// Scheduled tasks.
$string['taskinactiveusers'] = 'Delete inactive users';
