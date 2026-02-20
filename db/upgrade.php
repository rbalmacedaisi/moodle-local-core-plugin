<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin upgrade steps are defined here.
 *
 * @package     local_grupomakro_core
 * @category    upgrade
 * @copyright   2022 Gilson Ricnón <gilson.rincon@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Require the upgradelib.php file.
require_once($CFG->dirroot . '/local/grupomakro_core/db/upgradelib.php');

/**
 * Execute local_soluttolms_core upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_grupomakro_core_upgrade($oldversion) {
    
    // Create the new roles.
    create_roles();
    // Creating the new custom user fields.
    create_custom_user_fields();
    global $DB;
    $dbman = $DB->get_manager();
    if ($oldversion < 20230306003) {
    
        // Define table gmk_class to be created.
        $table = new xmldb_table('gmk_class');
    
        // Adding fields to table gmk_class.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('instance', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('learningplanid', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodid', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('instructorid', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('inittime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('endtime', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('classdays', XMLDB_TYPE_CHAR, '13', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    
        // Adding keys to table gmk_class.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
    
        // Conditionally launch create table for gmk_class.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
    
        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230306003, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20230306007) {

        // Define field groupid to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');

        // Conditionally launch add field groupid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field bbbclassroomid to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('bbbclassroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'groupid');

        // Conditionally launch add field bbbclassroomid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230306007, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20230329002) {

        // Define field coursesectionid to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('coursesectionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'bbbclassroomid');

        // Conditionally launch add field coursesectionid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329002, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20230329007) {

        // Define table gmk_teacher_disponibility to be created.
        $table = new xmldb_table('gmk_teacher_disponibility');

        // Adding fields to table gmk_teacher_disponibility.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('disp_monday', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('disp_tuesday', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('disp_wednesday', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('disp_thursday', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('disp_friday', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('disp_saturday', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('disp_sunday', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_teacher_disponibility.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_teacher_disponibility.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329007, 'local', 'grupomakro_core');
    }
    
     if ($oldversion < 20230329013) {

        // Define table gmk_reschedule_causes to be created.
        $table = new xmldb_table('gmk_reschedule_causes');

        // Adding fields to table gmk_reschedule_causes.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('causeshortname', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('causename', XMLDB_TYPE_CHAR, '200', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_reschedule_causes.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_reschedule_causes.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329013, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20230329014) {

        // Define table gmk_institution to be created.
        $table = new xmldb_table('gmk_institution');

        // Adding fields to table gmk_institution.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('name', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('institutionid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_institution.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_institution.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Define table gmk_institution_contract to be created.
        $table = new xmldb_table('gmk_institution_contract');

        // Adding fields to table gmk_institution_contract.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('initdate', XMLDB_TYPE_INTEGER, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expectedenddate', XMLDB_TYPE_INTEGER, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('budget', XMLDB_TYPE_NUMBER, '20, 2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('billingcondition', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, null);
        $table->add_field('institutionid', XMLDB_TYPE_INTEGER, '20', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_institution_contract.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_institution_contract.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Define table gmk_contract_user to be created.
        $table = new xmldb_table('gmk_contract_user');

        // Adding fields to table gmk_contract_user.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contractids', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseids', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_contract_user.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_contract_user.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Define field coursesectionid to be dropped from gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('bbbclassroomid');

        // Conditionally launch drop field coursesectionid.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329014, 'local', 'grupomakro_core');
    }
    
     if ($oldversion < 20230329016) {

        // Define field contractid to be added to gmk_institution_contract.
        $table = new xmldb_table('gmk_institution_contract');
        $field = new xmldb_field('contractid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null, 'institutionid');

        // Conditionally launch add field contractid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329016, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20230329017) {

        // Changing type of field contractids on table gmk_contract_user to char.
        $table = new xmldb_table('gmk_contract_user');
        $field = new xmldb_field('contractids', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'userid');

        // Launch change of type for field contractids.
        $dbman->change_field_type($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329017, 'local', 'grupomakro_core');
    }
  if ($oldversion < 20230329018) {
    
        // Rename field contractid on table gmk_contract_user to NEWNAMEGOESHERE.
        $table = new xmldb_table('gmk_contract_user');
        $field = new xmldb_field('contractids', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'userid');
    
        // Launch rename field contractid.
        $dbman->rename_field($table, $field, 'contractid');
        
        // Changing type of field courseids on table gmk_contract_user to char.
        $table = new xmldb_table('gmk_contract_user');
        $field = new xmldb_field('courseids', XMLDB_TYPE_CHAR, '256', null, XMLDB_NOTNULL, null, null, 'contractid');

        // Launch change of type for field courseids.
        $dbman->change_field_type($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329018, 'local', 'grupomakro_core');

    }
    
    if ($oldversion < 20230329019) {
    
        // Rename field contractid on table gmk_contract_user to NEWNAMEGOESHERE.
        $table = new xmldb_table('gmk_contract_user');
        $field = new xmldb_field('courseids', XMLDB_TYPE_CHAR, '256', null, XMLDB_NOTNULL, null, null, 'userid');
    
        // Launch rename field contractid.
        $dbman->rename_field($table, $field, 'courseid');

        $field = new xmldb_field('courseid', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null, 'contractid');

        // Launch change of type for field courseids.
        $dbman->change_field_type($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329019, 'local', 'grupomakro_core');

    }
    
     if ($oldversion < 20230329020) {

        // Define table gmk_contract_enrol_link to be created.
        $table = new xmldb_table('gmk_contract_enrol_link');

        // Adding fields to table gmk_contract_enrol_link.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contractid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('token', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expirationdate', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_contract_enrol_link.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_contract_enrol_link.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230329020, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20230623000) {

        // Define table gmk_class_session to be created.
        $table = new xmldb_table('gmk_class_session');

        // Adding fields to table gmk_class_session.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sessiontype', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('classroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('classroomsessionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('startdate', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enddate', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_class_session.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_class_session.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230623000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20230627000) {

        // Define field classroomid to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('classroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'coursesectionid');

        // Conditionally launch add field classroomid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230627000, 'local', 'grupomakro_core');
    }
     if ($oldversion < 20230823000) {

        // Define field classroomcapacity to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('classroomcapacity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '40', 'classroomid');

        // Conditionally launch add field classroomcapacity.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230823000, 'local', 'grupomakro_core');
        
        
        // Define table gmk_class_queue to be created.
        $table = new xmldb_table('gmk_class_queue');

        // Adding fields to table gmk_class_queue.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table gmk_class_queue.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_class_queue.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230823000, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20230828000) {

        //Defined computed fields to be stored in the database in order to reduce computation
        
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('typelabel', XMLDB_TYPE_CHAR, '15', null, null, null, null, 'classroomcapacity');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('corecourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'typelabel');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('instructorlpid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'corecourseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('instructorname', XMLDB_TYPE_CHAR, '128', null, null, null, null, 'instructorlpid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('inithourformatted', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'instructorname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('endhourformatted', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'inithourformatted');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('inittimets', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'endhourformatted');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('endtimets', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'inittimets');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('classduration', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'endtimets');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('companyname', XMLDB_TYPE_CHAR, '128', null, null, null, null, 'classduration');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('companycode', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'companyname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230828000, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20230913000) {

        // Define table gmk_class_pre_registration to be created.
        $table = new xmldb_table('gmk_class_pre_registration');

        // Adding fields to table gmk_class_pre_registration.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table gmk_class_pre_registration.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_class_pre_registration.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Define table gmk_class_approval_message to be created.
        $table = new xmldb_table('gmk_class_approval_message');

        // Adding fields to table gmk_class_approval_message.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('approvalmessage', XMLDB_TYPE_TEXT, null, null, null, null, null);

        // Adding keys to table gmk_class_approval_message.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_class_approval_message.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
         // Define table gmk_class_deletion_message to be created.
        $table = new xmldb_table('gmk_class_deletion_message');

        // Adding fields to table gmk_class_deletion_message.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('deletionmessage', XMLDB_TYPE_TEXT, null, null, null, null, null);

        // Adding keys to table gmk_class_deletion_message.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_class_deletion_message.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230913000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20230919001) {

        // Define field approved to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('approved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'companycode');

        // Conditionally launch add field approved.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230919001, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20230921000) {

        // Define field closed to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('closed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'approved');

        // Conditionally launch add field closed.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Define field instructorname to be dropped from gmk_class.
        $field = new xmldb_field('instructorname');

        // Conditionally launch drop field instructorname.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20230921000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20231019000) {

        // Define field subperiodid to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('subperiodid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'closed');

        // Conditionally launch add field subperiodid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20231019000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20231030000) {
        
        // Define table gmk_teacher_skill_relation to be created.
        $table = new xmldb_table('gmk_teacher_skill_relation');

        // Adding fields to table gmk_teacher_skill_relation.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('skillid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_teacher_skill_relation.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_teacher_skill_relation.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Define table gmk_teacher_skill to be created.
        $table = new xmldb_table('gmk_teacher_skill');

        // Adding fields to table gmk_teacher_skill.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('shortname', XMLDB_TYPE_CHAR, '16', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_teacher_skill.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_teacher_skill.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        
        // Define field academicperiodid to be added to gmk_teacher_disponibility.
        $table = new xmldb_table('gmk_teacher_disponibility');
        $field = new xmldb_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'disp_sunday');

        // Conditionally launch add field academicperiodid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20231030000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20231127000) {

        // Define table gmk_course_progre to be created.
        $table = new xmldb_table('gmk_course_progre');

        // Adding fields to table gmk_course_progre.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('periodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('periodname', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, 'unnamed');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('coursename', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, 'unnamed');
        $table->add_field('progress', XMLDB_TYPE_NUMBER, '3, 2', null, null, null, '0.0');
        $table->add_field('grade', XMLDB_TYPE_NUMBER, '3, 2', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('credits', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prerequisites', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('tc', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('practicalhours', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('teoricalhours', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('learningplanid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_course_progre.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_course_progre.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20231127000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20231127001) {

        // Define field bbbmoduleids to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('bbbmoduleids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'subperiodid');

        // Conditionally launch add field bbbmoduleids.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20231127001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20231127002) {

        // Define field attendancemoduleid to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('attendancemoduleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'bbbmoduleids');

        // Conditionally launch add field attendancemoduleid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20231127002, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20231207000) {

        // Define field classid to be added to gmk_course_progre.
        $table = new xmldb_table('gmk_course_progre');
        $classIdField = new xmldb_field('classid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');
        $groupIdField = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'classid');

        // Conditionally launch add field classid.
        if (!$dbman->field_exists($table, $classIdField)) {
            $dbman->add_field($table, $classIdField);
        }
        if (!$dbman->field_exists($table, $groupIdField)) {
            $dbman->add_field($table, $groupIdField);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20231207000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20240102000) {

        // Define table gmk_academic_calendar to be created.
        $table = new xmldb_table('gmk_academic_calendar');

        // Adding fields to table gmk_academic_calendar.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('academicperiodid', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, null);
        $table->add_field('period', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('year', XMLDB_TYPE_CHAR, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('yearquarter', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('bimester', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('bimesternumber', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodstart', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodend', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('induction', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('finalexamfrom', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('finalexamuntil', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('loadnotesandclosesubjects', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('delivoflistforrevalbyteach', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('notiftostudforrevalidations', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('deadlforpayofrevalidations', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('revalidationprocess', XMLDB_TYPE_INTEGER, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('registrationsfrom', XMLDB_TYPE_INTEGER, '16', null, null, null, '0');
        $table->add_field('registrationsuntil', XMLDB_TYPE_INTEGER, '16', null, null, null, '0');
        $table->add_field('graduationdate', XMLDB_TYPE_INTEGER, '16', null, null, null, '0');

        // Adding keys to table gmk_academic_calendar.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for gmk_academic_calendar.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240102000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20240102001) {

        // Define field usermodified to be added to gmk_academic_calendar.
        $table = new xmldb_table('gmk_academic_calendar');
        $field = new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'graduationdate');

        // Conditionally launch add field usermodified.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'usermodified');

        // Conditionally launch add field timecreated.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');

        // Conditionally launch add field timemodified.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240102001, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20240709005) {

        // Define table gmk_class to be updated.
        $table = new xmldb_table('gmk_class');

        // Define field initdate to be added to gmk_class.
        $field = new xmldb_field('initdate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'closed');

        // Conditionally launch add field initdate.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field enddate to be added to gmk_class.
        $field = new xmldb_field('enddate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'initdate');
        
        // Conditionally launch add field enddate.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240709005, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20240116000) {

        // Define field usermodified to be added to gmk_academic_calendar.
        $table = new xmldb_table('local_grupomakro_attendance');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('studentid', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timetaken', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('takenby', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        
        // Adding keys to table gmk_academic_calendar.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        
        // Conditionally launch create table for gmk_academic_calendar.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240116000, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20240116001) {

        // Define field usermodified to be added to gmk_academic_calendar.
        $table = new xmldb_table('local_grupomakro_attendance');
        $field = new xmldb_field('timetaken', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'takensession');

        // Conditionally launch add field usermodified.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240116001, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20240122000) {

        // Define field instance to be dropped from gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('instance');

        // Conditionally launch drop field instance.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240122000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20240122001) {

        // Define field companyname to be dropped from gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('companyname');

        // Conditionally launch drop field companyname.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        
        // Define field companycode to be dropped from gmk_class.
        $field = new xmldb_field('companycode');
        
        // Conditionally launch drop field companycode.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        
        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240122001, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20240129000) {
        
        // Define table gmk_attendance_temp to be renamed to gmk_attendance_temp.
        $table = new xmldb_table('local_grupomakro_attendance');

        // Launch rename table for gmk_attendance_temp.
        $dbman->rename_table($table, 'gmk_attendance_temp');

        // Changing type of field courseid on table gmk_attendance_temp to int.
        $table = new xmldb_table('gmk_attendance_temp');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'studentid');

        // Launch change of type for field courseid.
        $dbman->change_field_type($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240129000, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20240130000) {

        // Define table gmk_bbb_attendance_relation to be created.
        $table = new xmldb_table('gmk_bbb_attendance_relation');

        // Adding fields to table gmk_bbb_attendance_relation.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('attendancesessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bbbmoduleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_bbb_attendance_relation.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Conditionally launch create table for gmk_bbb_attendance_relation.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240130000, 'local', 'grupomakro_core');
    }
  if ($oldversion < 20240130001) {

        // Define field attendancemoduleid to be added to gmk_bbb_attendance_relation.
        $table = new xmldb_table('gmk_bbb_attendance_relation');
        $field = new xmldb_field('attendancemoduleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');

        // Conditionally launch add field attendancemoduleid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('attendanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'attendancemoduleid');

        // Conditionally launch add field attendanceid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'attendanceid');

        // Conditionally launch add field attendanceid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Define field companyname to be dropped from gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('bbbmoduleids');

        // Conditionally launch drop field companyname.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240130001, 'local', 'grupomakro_core');
    }
    
    if ($oldversion < 20240327000) {

        // Define field bbbmoduleids to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('bbbmoduleids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'attendancemoduleid');

        // Conditionally launch add field bbbmoduleids.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240327000, 'local', 'grupomakro_core');
    }   
    if ($oldversion < 20240429000) {

        // Define field gradecategoryid to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('gradecategoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'bbbmoduleids');

        // Conditionally launch add field gradecategoryid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240429000, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20240512000) {

        // Define field bbbid to be added to gmk_bbb_attendance_relation.
        $table = new xmldb_table('gmk_bbb_attendance_relation');
        $field = new xmldb_field('bbbid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'sectionid');

        // Conditionally launch add field bbbid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240512000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20240709000) {

        // Changing precision of field name on table gmk_teacher_skill to (128).
        $table = new xmldb_table('gmk_teacher_skill');
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '128', null, null, null, null, 'id');

        // Launch change of precision for field name.
        $dbman->change_field_precision($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240709000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20240709003) {

        // Define field courseid to be added to gmk_teacher_skill.
        $table = new xmldb_table('gmk_teacher_skill');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');

        // Conditionally launch add field courseid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240709003, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20240709004) {

        // Changing the default of field groupid on table gmk_class to 0.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');

        // Launch change of default for field groupid.
        $dbman->change_field_default($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20240709004, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20251218006) {

        // Define field initdate to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('initdate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'endtime');

        // Conditionally launch add field initdate.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field enddate to be added to gmk_class.
        $field = new xmldb_field('enddate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'initdate');

        // Conditionally launch add field enddate.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20251218006, 'local', 'grupomakro_core');
    }
    if ($oldversion < 20251230001) {
        $table = new xmldb_table('gmk_course_progre');
        
        // Changing precision of field progress on table gmk_course_progre to (5, 2).
        $fieldProgress = new xmldb_field('progress', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, '0.0', 'coursename');
        if ($dbman->field_exists($table, $fieldProgress)) {
            $dbman->change_field_precision($table, $fieldProgress);
        }

        // Changing precision of field grade on table gmk_course_progre to (5, 2).
        $fieldGrade = new xmldb_field('grade', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0.0', 'progress');
        if ($dbman->field_exists($table, $fieldGrade)) {
            $dbman->change_field_precision($table, $fieldGrade);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20251230001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260102001) {

        // Define table gmk_financial_status to be created.
        $table = new xmldb_table('gmk_financial_status');

        // Adding fields to table gmk_financial_status.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('reason', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('json_data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('lastupdated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_financial_status.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Adding indexes to table gmk_financial_status.
        $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);

        // Conditionally launch create table for gmk_financial_status.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260102001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260102002) {
        $table = new xmldb_table('gmk_financial_status');

        // Drop index first to avoid dependency error
        $index = new xmldb_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Change status field to char(50)
        $fieldStatus = new xmldb_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'none', 'userid');
        if ($dbman->field_exists($table, $fieldStatus)) {
            $dbman->change_field_precision($table, $fieldStatus);
        }

        // Recreate index
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Change reason field to char(255)
        $fieldReason = new xmldb_field('reason', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'status');
        if ($dbman->field_exists($table, $fieldReason)) {
            $dbman->change_field_precision($table, $fieldReason);
        }

        upgrade_plugin_savepoint(true, 20260102002, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260105001) {

        // Define table gmk_academic_periods to be created.
        $table = new xmldb_table('gmk_academic_periods');

        // Adding fields to table gmk_academic_periods.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_academic_periods.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for gmk_academic_periods.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table gmk_academic_planning to be created.
        $table = new xmldb_table('gmk_academic_planning');

        // Adding fields to table gmk_academic_planning.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('learningplanid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('projected_students', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_academic_planning.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('academicperiodid', XMLDB_KEY_FOREIGN, ['academicperiodid'], 'gmk_academic_periods', ['id']);

        // Conditionally launch create table for gmk_academic_planning.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260105001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260212000) {

        // Define field block1start to be added to gmk_academic_calendar.
        $table = new xmldb_table('gmk_academic_calendar');
        $field1 = new xmldb_field('block1start', XMLDB_TYPE_INTEGER, '16', null, null, null, '0', 'graduationdate');
        $field2 = new xmldb_field('block1end', XMLDB_TYPE_INTEGER, '16', null, null, null, '0', 'block1start');
        $field3 = new xmldb_field('block2start', XMLDB_TYPE_INTEGER, '16', null, null, null, '0', 'block1end');
        $field4 = new xmldb_field('block2end', XMLDB_TYPE_INTEGER, '16', null, null, null, '0', 'block2start');
        $field5 = new xmldb_field('hassubperiods', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'block2end');

        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }
        if (!$dbman->field_exists($table, $field3)) {
            $dbman->add_field($table, $field3);
        }
        if (!$dbman->field_exists($table, $field4)) {
            $dbman->add_field($table, $field4);
        }
        if (!$dbman->field_exists($table, $field5)) {
            $dbman->add_field($table, $field5);
        }

        // Define table gmk_academic_period_lps to be created.
        $tableLp = new xmldb_table('gmk_academic_period_lps');
        $tableLp->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $tableLp->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tableLp->add_field('learningplanid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tableLp->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tableLp->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tableLp->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $tableLp->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        if (!$dbman->table_exists($tableLp)) {
            $dbman->create_table($tableLp);
        }

        // Define table gmk_student_suspension to be created.
        $tableSusp = new xmldb_table('gmk_student_suspension');
        $tableSusp->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $tableSusp->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tableSusp->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $tableSusp->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $tableSusp->add_field('targetperiodid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $tableSusp->add_field('active_courses_dropped', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $tableSusp->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tableSusp->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tableSusp->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $tableSusp->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        if (!$dbman->table_exists($tableSusp)) {
            $dbman->create_table($tableSusp);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260212000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260212001) {

        // 1. Define table gmk_classrooms
        $table = new xmldb_table('gmk_classrooms');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('capacity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '40');
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, null, null, 'general');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2. Define table gmk_holidays
        $table = new xmldb_table('gmk_holidays');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, null, null, 'feriado');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('academicperiod_idx', XMLDB_INDEX_NOTUNIQUE, ['academicperiodid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 3. Define table gmk_subject_loads
        $table = new xmldb_table('gmk_subject_loads');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('subjectname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('total_hours', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '64');
        $table->add_field('intensity', XMLDB_TYPE_NUMBER, '4, 2', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('academicperiod_idx', XMLDB_INDEX_NOTUNIQUE, ['academicperiodid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 4. Define table gmk_class_schedules
        $table = new xmldb_table('gmk_class_schedules');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('day', XMLDB_TYPE_CHAR, '15', null, XMLDB_NOTNULL, null, null);
        $table->add_field('start_time', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('end_time', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('classroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('class_fk', XMLDB_KEY_FOREIGN, ['classid'], 'gmk_class', ['id']);
        $table->add_key('classroom_fk', XMLDB_KEY_FOREIGN, ['classroomid'], 'gmk_classrooms', ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 5. Define table gmk_academic_projections
        $table = new xmldb_table('gmk_academic_projections');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('career', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('shift', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('academicperiod_idx', XMLDB_INDEX_NOTUNIQUE, ['academicperiodid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260212001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260220000) {
        // Define table gmk_academic_deferrals to be created.
        $table = new xmldb_table('gmk_academic_deferrals');

        // Adding fields to table gmk_academic_deferrals.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('career', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('shift', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('current_level', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('target_period_index', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_academic_deferrals.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('academicperiod_idx', XMLDB_INDEX_NOTUNIQUE, ['academicperiodid']);

        // Conditionally launch create table for gmk_academic_deferrals.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260220000, 'local', 'grupomakro_core');
    }

    return true;
}

