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

    /**
     * Operational roles for the workflow matrix (added in 20261001000).
     *
     * Each role is scoped at CONTEXT_SYSTEM because all the admin pages they
     * gate are system-level. The 'user' archetype gives every holder basic
     * per-user permissions (login, edit own profile, view own grades) — the
     * GMK-specific capabilities are layered on top by
     * assign_capabilities_to_internal_roles() (filled in a later PR).
     *
     * Idempotent: get_record() before create_role() so re-running this on
     * an existing installation is a no-op for roles that are already there.
     */
    $operational_roles = [
        'gmk_director_academico'  => 'Director Académico',
        'gmk_secretaria_academica' => 'Secretaría Académica',
        'gmk_registros_academicos' => 'Registros Académicos',
        'gmk_soporte_ti'           => 'Soporte TI',
        'gmk_bienestar'            => 'Coordinador de Bienestar',
        'gmk_psicologo'            => 'Psicólogo/a',
    ];
    foreach ($operational_roles as $shortname => $name) {
        $role = $DB->get_record('role', ['shortname' => $shortname]);
        if (!$role) {
            create_role($name, $shortname, '', 'user');
            $role = $DB->get_record('role', ['shortname' => $shortname]);
        }
        if ($role) {
            set_role_contextlevels($role->id, [CONTEXT_SYSTEM]);
        }
    }

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
    $needfirsttuition->param1 = "si" . PHP_EOL . "no";

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

    $periodoingreso = new stdClass();
    $periodoingreso->shortname = 'periodo_ingreso';
    $periodoingreso->name = 'Periodo de Ingreso';
    $periodoingreso->datatype = 'text';
    $periodoingreso->description = '';
    $periodoingreso->descriptionformat = FORMAT_HTML;
    $periodoingreso->categoryid = $category->id;
    $periodoingreso->sortorder = $sortorderfield;
    $periodoingreso->required = 0;
    $periodoingreso->locked = 0;
    $periodoingreso->visible = 3;
    $periodoingreso->forceunique = 0;
    $periodoingreso->signup = 0;
    $periodoingreso->defaultdata = '';
    $periodoingreso->defaultdataformat = FORMAT_PLAIN;
    $periodoingreso->param1 = 30; // Display size
    $periodoingreso->param2 = 100; // Max length

    $sortorderfield++;

    $gmkjourney = new stdClass();
    $gmkjourney->shortname = 'gmkjourney';
    $gmkjourney->name = 'Jornada';
    $gmkjourney->datatype = 'text';
    $gmkjourney->description = '';
    $gmkjourney->descriptionformat = FORMAT_HTML;
    $gmkjourney->categoryid = $category->id;
    $gmkjourney->sortorder = $sortorderfield;
    $gmkjourney->required = 0;
    $gmkjourney->locked = 0;
    $gmkjourney->visible = 3;
    $gmkjourney->forceunique = 0;
    $gmkjourney->signup = 0;
    $gmkjourney->defaultdata = '';
    $gmkjourney->defaultdataformat = FORMAT_PLAIN;
    $gmkjourney->param1 = 30; 

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

    try {// Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $periodoingreso->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $periodoingreso);
        }
    } catch (Exception $e) {
    }

    try {// Verify if the field already exists.
        $record = $DB->get_record('user_info_field', array('shortname' => $gmkjourney->shortname));

        if (!isset($record->id)) {
            $DB->insert_record('user_info_field', $gmkjourney);
        }
    } catch (Exception $e) {
    }
}

/**
 * Assign all needed capabilities to the custom roles needed by this plugin.
 *
 * Idempotent: re-running on an existing install (a) applies any new
 * capability definitions declared in db/access.php via update_capabilities(),
 * (b) re-applies the assignment to the legacy 'administrative' role, and
 * (c) re-applies the bundle for each of the 5 operational roles created
 * in 20261001000. assign_capability() is itself idempotent.
 *
 * @return void
 */
function assign_capabilities_to_internal_roles() {
    global $DB;

    // First we need tu update the capabilities definition for this plugin.
    update_capabilities('local_grupomakro_core');

    $context = context_system::instance();
    $permission = CAP_ALLOW;

    // Legacy 'administrative' role: kept for backward compatibility with
    // users already assigned to it. The bundle is narrow (5 caps) and is
    // not affected by the workflow matrix.
    $administrative = $DB->get_record('role', array('shortname' => 'administrative'));
    if ($administrative) {
        $legacy_caps = [
            'local/grupomakro_core:seeallorders',
            'local/grupomakro_core:manageletters',
            'local/grupomakro_core:managerequests',
            'local/grupomakro_core:viewallletterrequests',
            'local/grupomakro_core:viewabsencedashboard',
        ];
        foreach ($legacy_caps as $capability) {
            assign_capability($capability, $permission, $administrative->id, $context->id);
        }
    }

    // Workflow matrix: capability bundle per operational role (added in
    // 20261001001). Each entry is the complete target state for that
    // role; future PRs that add new caps just append to the relevant
    // role(s) here. The matrix itself is the source of truth for
    // per-role access — db/access.php only declares defaults.
    //
    // Bulk_delete_users and import_grades are deliberately NOT in any
    // operational role (product decision PR1 Q4 + Q6). Those pages stay
    // gated by moodle/site:config.
    $role_caps = [
        'gmk_director_academico' => [
            // Workflow 1 — Academic structure (full)
            'local/grupomakro_core:manage_academic_calendar',
            'local/grupomakro_core:manage_academic_planning',
            'local/grupomakro_core:view_academic_demand_gaps',
            'local/grupomakro_core:view_overlap_analytics',
            'local/grupomakro_core:view_student_timeline',
            'local/grupomakro_core:manage_student_timeline',
            'local/grupomakro_core:manage_schedules',
            'local/grupomakro_core:manage_teacher_availability',
            // Workflow 2 — Classes and teachers (full)
            'local/grupomakro_core:view_classmanagement',
            'local/grupomakro_core:manage_classes',
            'local/grupomakro_core:manage_courses',
            'local/grupomakro_core:manage_meetings',
            'local/grupomakro_core:manage_teachers',
            'local/grupomakro_core:editsupportteacher',
            'local/grupomakro_core:manage_modules',
            // Workflow 3 — Students and enrollment
            'local/grupomakro_core:manage_users',
            'local/grupomakro_core:bulk_enroll',
            'local/grupomakro_core:import_users',
            'local/grupomakro_core:export_students',
            'local/grupomakro_core:view_student_population',
            'local/grupomakro_core:view_active_students_by_class',
            'local/grupomakro_core:view_academic_panel',
            'local/grupomakro_core:view_revalidations_dashboard',
            'local/grupomakro_core:create_extemporaneous_revalidations',
            // Workflow 4 — Attendance, grades, movements
            'local/grupomakro_core:viewabsencedashboard',
            'local/grupomakro_core:view_attendance_pdf',
            'local/grupomakro_core:bulk_attendance_actions',
            'local/grupomakro_core:view_grade_report',
            'local/grupomakro_core:view_failed_subjects_report',
            'local/grupomakro_core:enrol_from_failed_subjects_report',
            'local/grupomakro_core:view_movement_audit',
            'local/grupomakro_core:annul_movement',
            'local/grupomakro_core:manageacademicstatus',
            // Workflow 5 — Letters, contracts, institutions
            'local/grupomakro_core:seeallorders',
            'local/grupomakro_core:viewallletterrequests',
            'local/grupomakro_core:view_credit_report',
            'local/grupomakro_core:view_financial_planning',
            'local/grupomakro_core:manage_orders',
            'local/grupomakro_core:manage_institutional_contracts',
            'local/grupomakro_core:manage_institutions',
            // Workflow 6 — Diplomas (manage + view)
            'local/grupomakro_core:managediplomas',
            'local/grupomakro_core:viewdiplomas',
            // Workflow 7 — Announcements (view only — manage goes to gmk_bienestar)
            'local/grupomakro_core:viewannouncements',
            // Workflow 8 — selective oversight (config + health read)
            'local/grupomakro_core:manage_financial_config',
            'local/grupomakro_core:view_financial_health',
        ],
        'gmk_secretaria_academica' => [
            // Workflow 1 — Operational scheduling (no structural decisions)
            'local/grupomakro_core:manage_academic_calendar',
            'local/grupomakro_core:view_academic_demand_gaps',
            'local/grupomakro_core:view_overlap_analytics',
            'local/grupomakro_core:view_student_timeline',
            'local/grupomakro_core:manage_schedules',
            'local/grupomakro_core:manage_teacher_availability',
            // Workflow 2 — Classes and teachers (full)
            'local/grupomakro_core:view_classmanagement',
            'local/grupomakro_core:manage_classes',
            'local/grupomakro_core:manage_courses',
            'local/grupomakro_core:manage_meetings',
            'local/grupomakro_core:manage_teachers',
            'local/grupomakro_core:editsupportteacher',
            'local/grupomakro_core:manage_modules',
            // Workflow 3 — Students (operational)
            'local/grupomakro_core:manage_users',
            'local/grupomakro_core:bulk_enroll',
            'local/grupomakro_core:import_users',
            'local/grupomakro_core:export_students',
            'local/grupomakro_core:view_student_population',
            'local/grupomakro_core:view_active_students_by_class',
            'local/grupomakro_core:view_academic_panel',
            'local/grupomakro_core:view_revalidations_dashboard',
            'local/grupomakro_core:manageacademicstatus',
            // Workflow 4 — Attendance, grades (no annul_movement, no create_extemporaneous_revalidations)
            'local/grupomakro_core:viewabsencedashboard',
            'local/grupomakro_core:view_attendance_pdf',
            'local/grupomakro_core:bulk_attendance_actions',
            'local/grupomakro_core:view_grade_report',
            'local/grupomakro_core:view_failed_subjects_report',
            'local/grupomakro_core:enrol_from_failed_subjects_report',
            'local/grupomakro_core:view_movement_audit',
            // Workflow 5 — Letters (request management, no orders/contracts)
            'local/grupomakro_core:viewallletterrequests',
            'local/grupomakro_core:managerequests',
            // Workflow 6 — Diplomas (view only, per Q1)
            'local/grupomakro_core:viewdiplomas',
            // Workflow 7 — View announcements only
            'local/grupomakro_core:viewannouncements',
        ],
        'gmk_registros_academicos' => [
            // Workflow 3 — Student record management
            'local/grupomakro_core:manage_users',
            'local/grupomakro_core:export_students',
            'local/grupomakro_core:view_student_population',
            'local/grupomakro_core:view_active_students_by_class',
            // Workflow 4 — View attendance and grades (read-only)
            'local/grupomakro_core:viewabsencedashboard',
            'local/grupomakro_core:view_attendance_pdf',
            'local/grupomakro_core:view_grade_report',
            // Workflow 5 — Letters, contracts (full — this is the workflow)
            'local/grupomakro_core:seeallorders',
            'local/grupomakro_core:viewallletterrequests',
            'local/grupomakro_core:manageletters',
            'local/grupomakro_core:managerequests',
            'local/grupomakro_core:view_credit_report',
            'local/grupomakro_core:view_financial_planning',
            'local/grupomakro_core:manage_orders',
            'local/grupomakro_core:manage_institutional_contracts',
            // Workflow 6 — Diplomas (full)
            'local/grupomakro_core:managediplomas',
            'local/grupomakro_core:viewdiplomas',
            // Workflow 7 — View announcements only
            'local/grupomakro_core:viewannouncements',
        ],
        'gmk_soporte_ti' => [
            // Workflow 8 — Infrastructure and integrations (full)
            'local/grupomakro_core:view_log',
            'local/grupomakro_core:view_financial_health',
            'local/grupomakro_core:manage_financial_webhooks',
            'local/grupomakro_core:manage_financial_config',
            'local/grupomakro_core:manage_debug',
            // Workflow 2 — BBB reset only (limited support for virtual classes)
            'local/grupomakro_core:manage_meetings',
        ],
        'gmk_bienestar' => [
            // Workflow 7 — Wellness (full)
            'local/grupomakro_core:manage_wellness',
            'local/grupomakro_core:manage_psychology_appointments',
            'local/grupomakro_core:manageannouncements',
            'local/grupomakro_core:viewannouncements',
            // view_wellness is granted to the 'user' archetype so every
            // logged-in student can read the wellness feed; this role
            // covers the management side only.
        ],
        'gmk_psicologo' => [
            // Workflow 7 — Psicología: solo la agenda psicológica. No
            // puede crear eventos, convenios ni gestionar el resto del
            // módulo (eso queda en manos del Coordinador de Bienestar).
            'local/grupomakro_core:manage_psychology_appointments',
        ],
    ];

    foreach ($role_caps as $shortname => $caps) {
        $role = $DB->get_record('role', ['shortname' => $shortname]);
        if (!$role) {
            // Role not yet created (e.g. partial upgrade). create_roles()
            // runs in the previous upgrade step (20261001000), so this
            // should not happen, but skip silently rather than abort
            // the whole upgrade.
            continue;
        }
        foreach ($caps as $capability) {
            assign_capability($capability, $permission, $role->id, $context->id);
        }
    }
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
