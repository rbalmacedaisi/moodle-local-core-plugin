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
 * This file is executed right after the install.xml
 *
 * @package     local_grupomakro_core
 * @category    string
 * @copyright   2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_local_grupomakro_core_install() {
    global $DB;

    // Install the roles system.
    $caregiver = create_role('Acudiente', 'caregiver', '', 'user');

    // Set up the context levels where you can assign each role!
    set_role_contextlevels($caregiver, [CONTEXT_SYSTEM, CONTEXT_COURSE]);

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
        // - documentnumber: text.
    $usertype = new stdClass();
    $usertype->shortname = 'usertype';
    $usertype->name = 'Tipo de usuario';
    $usertype->datatype = 'menu';
    $usertype->description = '';
    $usertype->descriptionformat = FORMAT_HTML;
    $usertype->categoryid = $category->id;
    $usertype->sortorder = $sortorderfield;
    $usertype->required = 0;
    $usertype->locked = 1;
    $usertype->visible = 0;
    $usertype->forceunique = 0;
    $usertype->signup = 0;
    $usertype->defaultdata = '';
    $usertype->defaultdataformat = FORMAT_PLAIN;
    $usertype->param1 = "Estudiante\rAcudiente / Codeudor";

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
    $accountmanager->locked = 1;
    $accountmanager->visible = 0;
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
    $birthdate->visible = 2;
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
    $documenttype->param1 = "Cédula de Ciudadanía\rCédula de Extranjería\rPasaporte";

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

    $DB->insert_record('user_info_field', $usertype);
    $DB->insert_record('user_info_field', $accountmanager);
    $DB->insert_record('user_info_field', $birthdate);
    $DB->insert_record('user_info_field', $documenttype);
    $DB->insert_record('user_info_field', $documentnumber);
    
}
