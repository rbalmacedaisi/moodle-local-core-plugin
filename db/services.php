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
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = array(
    'local_grupomakro_create_user' => array(
        'classname' => 'local_grupomakro_core\external\create_user',
        'methodname' => 'execute',
        'description' => 'Ths method creates a new user in the Moodle platform.',
        'type' => 'write',
        "ajax" => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_generate_order' => array(
        'classname' => 'local_grupomakro_core\external\generate_order',
        'methodname' => 'execute',
        'description' => 'Generates a new order for the userid and the items provided.',
        'type' => 'write',
        "ajax" => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_get_user_status' => array(
        'classname' => 'local_grupomakro_core\external\get_user_status',
        'methodname' => 'execute',
        'description' => 'Returns the status of the user in the Moodle platform.',
        'type' => 'read',
        "ajax" => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_create_class' => array(
        'classname'     => 'local_grupomakro_core\external\gmkclass\create_class',
        'methodname'    => 'execute',
        'description'   => 'Create new class',
        'type'          => 'write',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_list_classes' => array(
        'classname'     => 'local_grupomakro_core\external\gmkclass\list_classes',
        'methodname'    => 'execute',
        'description'   => 'This method list the created classes',
        'type'          => 'read',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_delete_class' => array(
        'classname'     => 'local_grupomakro_core\external\gmkclass\delete_class',
        'methodname'    => 'execute',
        'description'   => 'This method delete the class with the id provided',
        'type'          => 'write',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_update_class' => array(
        'classname'     => 'local_grupomakro_core\external\gmkclass\update_class',
        'methodname'    => 'execute',
        'description'   => 'This method update the class with the id provided',
        'type'          => 'write',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_calendar_get_calendar_events' => array(
        'classname' => 'local_grupomakro_core\external\calendar_external',
        'methodname' => 'execute',
        'description' => 'Get calendar events',
        'type' => 'read',
        'capabilities' => 'moodle/course:ignoreavailabilityrestrictions, moodle/calendar:manageentries, moodle/calendar:manageownentries, moodle/calendar:managegroupentries',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_send_reschedule_message' => array(
        'classname' => 'local_grupomakro_core\external\activity\send_reschedule_message',
        'methodname' => 'execute',
        'description' => '',
        'type' => 'read',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_get_teachers_disponibility' => array(
        'classname' => 'local_grupomakro_core\external\disponibility\get_teachers_disponibility',
        'methodname' => 'execute',
        'description' => 'Get the teachers disponibility',
        'type' => 'read',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_add_teacher_disponibility' => array(
        'classname' => 'local_grupomakro_core\external\disponibility\add_teacher_disponibility',
        'methodname' => 'execute',
        'description' => 'Add teacher disponibility record',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_update_teacher_disponibility' => array(
        'classname' => 'local_grupomakro_core\external\disponibility\update_teacher_disponibility',
        'methodname' => 'execute',
        'description' => 'Update teacher disponibility record',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_delete_teacher_disponibility' => array(
        'classname' => 'local_grupomakro_core\external\disponibility\delete_teacher_disponibility',
        'methodname' => 'execute',
        'description' => 'Delete a teacher disponibility record',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_get_teachers_disponibility_calendar' => array(
        'classname' => 'local_grupomakro_core\external\disponibility\get_teachers_disponibility_calendar',
        'methodname' => 'execute',
        'description' => 'Get the theachers disponibility for the disponibility calendar',
        'type' => 'read',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_check_reschedule_conflicts' => array(
        'classname' => 'local_grupomakro_core\external\activity\check_reschedule_conflicts',
        'methodname' => 'execute',
        'description' => 'Check if the new date and time for the activity is in conflict with another class for the users in the update activity',
        'type' => 'read',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_reschedule_activity' => array(
        'classname' => 'local_grupomakro_core\external\activity\reschedule_activity',
        'methodname' => 'execute',
        'description' => 'Reschedule an activity to another date and time',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_create_institution' => array(
        'classname' => 'local_grupomakro_core\external\institution\create_institution',
        'methodname' => 'execute',
        'description' => 'Create a new institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_update_institution' => array(
        'classname' => 'local_grupomakro_core\external\institution\update_institution',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_list_institutions' => array(
        'classname' => 'local_grupomakro_core\external\institution\list_institutions',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'read',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_delete_institution' => array(
        'classname' => 'local_grupomakro_core\external\institution\delete_institution',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_create_institution_contract' => array(
        'classname' => 'local_grupomakro_core\external\institutionContract\create_institution_contract',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_update_institution_contract' => array(
        'classname' => 'local_grupomakro_core\external\institutionContract\update_institution_contract',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_delete_institution_contract' => array(
        'classname' => 'local_grupomakro_core\external\institutionContract\delete_institution_contract',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_list_institution_contracts' => array(
        'classname' => 'local_grupomakro_core\external\institutionContract\list_institution_contracts',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'read',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_create_contract_user' => array(
        'classname' => 'local_grupomakro_core\external\contractUser\create_contract_user',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_update_contract_user' => array(
        'classname' => 'local_grupomakro_core\external\contractUser\update_contract_user',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_delete_contract_user' => array(
        'classname' => 'local_grupomakro_core\external\contractUser\delete_contract_user',
        'methodname' => 'execute',
        'description' => 'Delete a contract user record',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_list_contract_users' => array(
        'classname' => 'local_grupomakro_core\external\contractUser\list_contract_users',
        'methodname' => 'execute',
        'description' => 'Update an institution',
        'type' => 'read',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_generate_contract_enrol_link' => array(
        'classname' => 'local_grupomakro_core\external\contractUser\generate_contract_enrol_link',
        'methodname' => 'execute',
        'description' => 'generate a link for the users to enrol in a course related to a contract',
        'type' => 'write',
        'capabilities' => '',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
);
