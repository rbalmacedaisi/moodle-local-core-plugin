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
