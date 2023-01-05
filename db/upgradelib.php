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
 * Library for the upgrade/install/uninstall scripts.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Require the accesslib.php file.
require_once($CFG->libdir . '/accesslib.php');

/**
 * Creating the new roles.
 *
 * @return void
 */
function create_roles() {
    global $DB;

    /** Defining the role "caregiver" */

    // Let's see if the "caregiver" role exists.
    $role = $DB->get_record('role', array('shortname' => 'caregiver'));

    // If it doesn't exist, let's create it.
    if (!$role) {
        $caregiver = create_role('Acudiente', 'caregiver', '', 'user');
    } else {
        $caregiver = $role->id;
    }

    // Set up the context levels where you can assign each role!
    set_role_contextlevels($caregiver, [CONTEXT_SYSTEM, CONTEXT_COURSE]);

    /** End of role "caregiver" definition */

    /** Defining the role "administrative" */
    // Let's see if the "administrative" role exists.
    $role = $DB->get_record('role', array('shortname' => 'administrative'));

    // If it doesn't exist, let's create it.
    if (!$role) {
        $administrative = create_role('Administrativo', 'administrative', '', 'user');
    } else {
        $administrative = $role->id;
    }

    // Set up the context levels where you can assign each role!
    set_role_contextlevels($administrative, [CONTEXT_SYSTEM]);

    /** End of role "administrative" definition */

    // Assign all needed capabilities to the custom roles needed by this plugin.
    assign_capabilities_to_internal_roles();
}

/**
 * Creating the new custom user fields.
 *
 * @return void
 */
function create_custom_user_fields() {
    global $DB;

    // Is there a record in the user_info_category table with the name "Grupo Makro"?
    $category = $DB->get_record('user_info_category', ['name' => 'Grupo Makro']);

    // If not, create it.
    if (!$category) {
        // Get the highest sortorder in the user_info_category table.
        $sortorder = $DB->get_field_sql('SELECT MAX(sortorder) FROM {user_info_category}');
        $sortorder++;

        $category = new stdClass();
        $category->name = 'Grupo Makro';
        $category->sortorder = $sortorder;
        $category->id = $DB->insert_record('user_info_category', $category);
    }

    // Get the maximum sortorder in the user_info_field table.
    $sortorderfield = $DB->get_field_sql('SELECT MAX(sortorder) FROM {user_info_field}');
    $sortorderfield++;

    // Let's create a new field in the user_info_field table for:
        // - usertype: menu with the options "Estudiante", "Acudiente / Codeudor"
        // - accountmanager: text
        // - birthdate: datetime
        // - documenttype: menu with the options "Cédula de Ciudadanía", "Cédula de Extranjería", "Pasaporte"
        // - documentnumber: text
        // - needfirsttuition: text
        // - personalemail: text
    $usertype = new stdClass();
    $usertype->shortname = 'usertype';
    $usertype->name = 'Tipo de usuario';
    $usertype->datatype = 'menu';
    $usertype->description = '';
    $usertype->descriptionformat = FORMAT_HTML;
    $usertype->categoryid = $category->id;
    $usertype->sortorder = $sortorderfield;
    $usertype->required = 0;
    $usertype->locked = 0;
    $usertype->visible = 3;
    $usertype->forceunique = 0;
    $usertype->signup = 0;
    $usertype->defaultdata = '';
    $usertype->defaultdataformat = FORMAT_PLAIN;
    $usertype->param1 = "Estudiante\n\rAcudiente / Codeudor";

    $sortorderfield++;

    $accountmanager = new stdClass();
    $accountmanager->shortname = 'accountmanager';
    $accountmanager->name = 'Asesor comercial (E-mail)';
    $accountmanager->datatype = 'text';
    $accountmanager->description = '';
    $accountmanager->descriptionformat = FORMAT_HTML;
    $accountmanager->categoryid = $category->id;
    $accountmanager->sortorder = $sortorderfield;
    $accountmanager->required = 0;
    $accountmanager->locked = 0;
    $accountmanager->visible = 3;
    $accountmanager->forceunique = 0;
    $accountmanager->signup = 0;
    $accountmanager->defaultdata = '';
    $accountmanager->defaultdataformat = FORMAT_PLAIN;
    $accountmanager->param1 = '';

    $sortorderfield++;

    $birthdate = new stdClass();
    $birthdate->shortname = 'birthdate';
    $birthdate->name = 'Fecha de nacimiento';
    $birthdate->datatype = 'datetime';
    $birthdate->description = '';
    $birthdate->descriptionformat = FORMAT_HTML;
    $birthdate->categoryid = $category->id;
    $birthdate->sortorder = $sortorderfield;
    $birthdate->required = 0;
    $birthdate->locked = 0;
    $birthdate->visible = 3;
    $birthdate->forceunique = 0;
    $birthdate->signup = 1;
    $birthdate->defaultdata = '';
    $birthdate->defaultdataformat = FORMAT_PLAIN;
    $birthdate->param1 = '';

    $sortorderfield++;

    $documenttype = new stdClass();
    $documenttype->shortname = 'documenttype';
    $documenttype->name = 'Tipo de documento';
    $documenttype->datatype = 'menu';
    $documenttype->description = '';
    $documenttype->descriptionformat = FORMAT_HTML;
    $documenttype->categoryid = $category->id;
    $documenttype->sortorder = $sortorderfield;
    $documenttype->required = 0;
    $documenttype->locked = 0;
    $documenttype->visible = 3;
    $documenttype->forceunique = 0;
    $documenttype->signup = 1;
    $documenttype->defaultdata = '';
    $documenttype->defaultdataformat = FORMAT_PLAIN;
    $documenttype->param1 = "Cédula de Ciudadanía\n\rCédula de Extranjería\n\rPasaporte";

    $sortorderfield++;

    $documentnumber = new stdClass();
    $documentnumber->shortname = 'documentnumber';
    $documentnumber->name = 'Número de documento';
    $documentnumber->datatype = 'text';
    $documentnumber->description = '';
    $documentnumber->descriptionformat = FORMAT_HTML;
    $documentnumber->categoryid = $category->id;
    $documentnumber->sortorder = $sortorderfield;
    $documentnumber->required = 0;
    $documentnumber->locked = 0;
    $documentnumber->visible = 3;
    $documentnumber->forceunique = 0;
    $documentnumber->signup = 1;
    $documentnumber->defaultdata = '';
    $documentnumber->defaultdataformat = FORMAT_PLAIN;
    $documentnumber->param1 = '';

    $sortorderfield++;

    $needfirsttuition = new stdClass();
    $needfirsttuition->shortname = 'needfirsttuition';
    $needfirsttuition->name = 'Debe pagar primera matricula';
    $needfirsttuition->datatype = 'menu';
    $needfirsttuition->description = 'Este será un campo oculto, si el valor es "si" se mostrará el mensaje de que debe pagar la primera matrícula';
    $needfirsttuition->descriptionformat = FORMAT_HTML;
    $needfirsttuition->categoryid = $category->id;
    $needfirsttuition->sortorder = $sortorderfield;
    $needfirsttuition->required = 0;
    $needfirsttuition->locked = 0;
    $needfirsttuition->visible = 3;
    $needfirsttuition->forceunique = 0;
    $needfirsttuition->signup = 0;
    $needfirsttuition->defaultdata = '';
    $needfirsttuition->defaultdataformat = FORMAT_PLAIN;
    $needfirsttuition->param1 = "si\n\rno";

    $personalemail = new stdClass();
    $personalemail->shortname = 'personalemail';
    $personalemail->name = 'Correo personal';
    $personalemail->datatype = 'text';
    $personalemail->description = '';
    $personalemail->descriptionformat = FORMAT_HTML;
    $personalemail->categoryid = $category->id;
    $personalemail->sortorder = $sortorderfield;
    $personalemail->required = 0;
    $personalemail->locked = 0;
    $personalemail->visible = 3;
    $personalemail->forceunique = 0;
    $personalemail->signup = 0;
    $personalemail->defaultdata = '';
    $personalemail->defaultdataformat = FORMAT_PLAIN;
    $personalemail->param1 = '';

    $sortorderfield++;

    try {
        // Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $usertype->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $usertype);
        }
    } catch (Exception $e) {
    }

    try {
        // Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $accountmanager->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $accountmanager);
        }
    } catch (Exception $e) {
    }

    try {
        // Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $birthdate->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $birthdate);
        }
    } catch (Exception $e) {
    }

    try {
        // Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $documenttype->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $documenttype);
        }
    } catch (Exception $e) {
    }

    try {
        // Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $documentnumber->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $documentnumber);
        }
    } catch (Exception $e) {
    }

    try {// Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $needfirsttuition->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $needfirsttuition);
        }
    } catch (Exception $e) {
    }

    try {// Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $personalemail->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $personalemail);
        }
    } catch (Exception $e) {
    }
}

/**
 * Assign all needed capabilities to the custom roles needed by this plugin.
 *
 * @return void
 */
function assign_capabilities_to_internal_roles() {
    global $DB;

    // First we need tu update the capabilities definition for this plugin.
    update_capabilities('local_grupomakro_core');

    // Let's assign the grupomakro_core:seeallorders capability to the "administrative" role.
    $role = $DB->get_record('role', array('shortname' => 'administrative'));
    $context = context_system::instance();
    $capability = 'local/grupomakro_core:seeallorders';
    $permission = CAP_ALLOW;

    assign_capability($capability, $permission, $role->id, $context->id);
}

/**
 * This function deletes all the custom fields created by this plugin.
 * 
 * @return void
 * 
 */
function delete_custom_fields() {
    global $DB;

    // Let's get the ID of each individaul custom fiel:
    $fields = [];

    $fields[] = $DB->get_record('user_info_field', array('shortname' => 'usertype'));
    $fields[] = $DB->get_record('user_info_field', array('shortname' => 'accountmanager'));
    $fields[] = $DB->get_record('user_info_field', array('shortname' => 'birthdate'));
    $fields[] = $DB->get_record('user_info_field', array('shortname' => 'documenttype'));
    $fields[] = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));
    $fields[] = $DB->get_record('user_info_field', array('shortname' => 'needfirsttuition'));

    // Let's delete each custom field and the data lreated to it.
    foreach ($fields as $field) {
        if (isset($field->id)) {
            $DB->delete_records('user_info_data', array('fieldid' => $field->id));
            $DB->delete_records('user_info_field', array('id' => $field->id));
        }
    }

    return true;
}
