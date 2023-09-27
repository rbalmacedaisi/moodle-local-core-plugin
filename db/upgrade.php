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


    return true;
}
