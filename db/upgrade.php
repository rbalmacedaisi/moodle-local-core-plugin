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
 * @copyright   2022 Gilson RicnÃ³n <gilson.rincon@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Require the upgradelib.php file.
require_once($CFG->dirroot . '/local/grupomakro_core/db/upgradelib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

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
    global $DB, $CFG;
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

    if ($oldversion < 20260220001) {

        // Define field configsettings to be added to gmk_academic_periods
        $table = new xmldb_table('gmk_academic_periods');
        $field = new xmldb_field('configsettings', XMLDB_TYPE_TEXT, null, null, null, null, null, null);

        // Conditionally launch add field configsettings The
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260220001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260220003) {
        // Define table gmk_planning_period_maps to be created.
        $table = new xmldb_table('gmk_planning_period_maps');

        // Adding fields to table gmk_planning_period_maps.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('base_period_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('relative_index', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, null);
        $table->add_field('target_period_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_planning_period_maps.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('base_period_id', XMLDB_KEY_FOREIGN, ['base_period_id'], 'gmk_academic_periods', ['id']);
        $table->add_key('target_period_id', XMLDB_KEY_FOREIGN, ['target_period_id'], 'gmk_academic_periods', ['id']);

        // Adding indexes to table gmk_planning_period_maps.
        $table->add_index('base_index_idx', XMLDB_INDEX_UNIQUE, ['base_period_id', 'relative_index']);

        // Conditionally launch create table for gmk_planning_period_maps.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260220003, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260221000) {

        // Define fields to be added to gmk_class.
        $table = new xmldb_table('gmk_class');
        
        $field_shift = new xmldb_field('shift', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'gradecategoryid');
        $field_level = new xmldb_field('level_label', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'shift');
        $field_career = new xmldb_field('career_label', XMLDB_TYPE_TEXT, null, null, null, null, null, 'level_label');

        // Conditionally launch add field shift.
        if (!$dbman->field_exists($table, $field_shift)) {
            $dbman->add_field($table, $field_shift);
        }

        // Conditionally launch add field level_label.
        if (!$dbman->field_exists($table, $field_level)) {
            $dbman->add_field($table, $field_level);
        }

        // Conditionally launch add field career_label.
        if (!$dbman->field_exists($table, $field_career)) {
            $dbman->add_field($table, $field_career);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260221000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260221001) {

        // Changing the type of field userid on table gmk_class_queue to char.
        $table = new xmldb_table('gmk_class_queue');
        $field = new xmldb_field('userid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, 'timemodified');

        // Launch change of type for field userid.
        $dbman->change_field_type($table, $field);

        // Changing the type of field userid on table gmk_class_pre_registration to char.
        $table = new xmldb_table('gmk_class_pre_registration');
        $field = new xmldb_field('userid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'timemodified');

        // Launch change of type for field userid.
        $dbman->change_field_type($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260221001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260221002) {

        // Changing the type of field level_label on table gmk_class to text.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field('level_label', XMLDB_TYPE_TEXT, null, null, null, null, null, 'shift');

        // Launch change of type for field level_label.
        $dbman->change_field_type($table, $field);

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260221002, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260221003) {

        // Define field excluded_dates to be added to gmk_class_schedules.
        $table = new xmldb_table('gmk_class_schedules');
        $field = new xmldb_field('excluded_dates', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timemodified');

        // Conditionally launch add field excluded_dates.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260221003, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260224000) {

        // Define field draft_schedules to be added to gmk_academic_periods.
        $table = new xmldb_table('gmk_academic_periods');
        $field = new xmldb_field('draft_schedules', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'configsettings');

        // Conditionally launch add field draft_schedules.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260224000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260226000) {

        // Define field coursename to be expanded in gmk_course_progre.
        $table = new xmldb_table('gmk_course_progre');
        $field = new xmldb_field('coursename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'unnamed', 'courseid');

        // Conditionally expand field coursename from 64 to 255 characters.
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260226000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260226001) {
        // Define field status to be expanded in gmk_course_progre.
        $table = new xmldb_table('gmk_course_progre');
        $field = new xmldb_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'learningplanid');

        // Conditionally expand field status from 1 to 2 digits.
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260226001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260304000) {
        // Add assigned_dates column to gmk_class_schedules to store planning-board session dates.
        $table = new xmldb_table('gmk_class_schedules');
        $field = new xmldb_field('assigned_dates', XMLDB_TYPE_TEXT, null, null, null, null, null, 'excluded_dates');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260304000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260310000) {
        // Create gmk_grace_period table for first-login grace periods.
        $table = new xmldb_table('gmk_grace_period');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('documentnumber', XMLDB_TYPE_CHAR, '50');
        $table->add_field('graceuntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_UNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 20260310000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260323000) {
        // Letter types catalog.
        $table = new xmldb_table('gmk_letter_type');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('code', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('warningtext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('cost', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0.00');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('deliverymode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'digital');
        $table->add_field('generationmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'auto');
        $table->add_field('autostamp', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('autosignature', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('stampimageurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('signatureimageurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('odoo_product_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('template_html', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('codeuniq', XMLDB_KEY_UNIQUE, ['code']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('activeidx', XMLDB_INDEX_NOTUNIQUE, ['active']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Dataset definitions for letter templates.
        $table = new xmldb_table('gmk_letter_dataset_def');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('code', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('codeuniq', XMLDB_KEY_UNIQUE, ['code']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Relation between letter types and datasets.
        $table = new xmldb_table('gmk_letter_type_dataset');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('lettertypeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('datasetdefid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('uniq', XMLDB_KEY_UNIQUE, ['lettertypeid', 'datasetdefid']);
        $table->add_key('letterfk', XMLDB_KEY_FOREIGN, ['lettertypeid'], 'gmk_letter_type', ['id']);
        $table->add_key('datasetfk', XMLDB_KEY_FOREIGN, ['datasetdefid'], 'gmk_letter_dataset_def', ['id']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Student letter requests.
        $table = new xmldb_table('gmk_letter_request');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('lettertypeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'solicitada');
        $table->add_field('observation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('warning_snapshot', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('cost_snapshot', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0.00');
        $table->add_field('deliverymode_snapshot', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'digital');
        $table->add_field('generationmode_snapshot', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'auto');
        $table->add_field('invoice_id', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('invoice_number', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('payment_link', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('paid_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('rejection_reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('cancel_reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('extra_data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('lettertypefk', XMLDB_KEY_FOREIGN, ['lettertypeid'], 'gmk_letter_type', ['id']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('statusidx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('invoiceidx', XMLDB_INDEX_NOTUNIQUE, ['invoice_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Request lifecycle events.
        $table = new xmldb_table('gmk_letter_request_event');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('oldstatus', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('newstatus', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'status_change');
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('metadata', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('requestfk', XMLDB_KEY_FOREIGN, ['requestid'], 'gmk_letter_request', ['id']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('eventidx', XMLDB_INDEX_NOTUNIQUE, ['eventtype']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Generated letter documents.
        $table = new xmldb_table('gmk_letter_document');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('versionno', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('mimetype', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'application/pdf');
        $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('fileitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('odoo_attachment_id', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('requestfk', XMLDB_KEY_FOREIGN, ['requestid'], 'gmk_letter_request', ['id']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Seed predefined datasets for v1.
        $now = time();
        $datasets = [
            ['code' => 'asignaturas_cursadas', 'name' => 'Asignaturas cursadas', 'description' => 'Lista de asignaturas, nota y crÃ©ditos por estudiante.'],
            ['code' => 'resumen_creditos', 'name' => 'Resumen de crÃ©ditos', 'description' => 'Totales de crÃ©ditos cursados y aprobados.'],
            ['code' => 'periodo_actual', 'name' => 'Periodo actual', 'description' => 'Resumen del periodo acadÃ©mico actual del estudiante.'],
        ];
        foreach ($datasets as $dataset) {
            if (!$DB->record_exists('gmk_letter_dataset_def', ['code' => $dataset['code']])) {
                $record = (object) [
                    'code' => $dataset['code'],
                    'name' => $dataset['name'],
                    'description' => $dataset['description'],
                    'enabled' => 1,
                    'usermodified' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $DB->insert_record('gmk_letter_dataset_def', $record);
            }
        }

        // Ensure capabilities are assigned to internal roles after introducing new ones.
        assign_capabilities_to_internal_roles();

        upgrade_plugin_savepoint(true, 20260323000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260324000) {
        // Seed default letter types for pilot (only when missing by code).
        if ($dbman->table_exists(new xmldb_table('gmk_letter_type'))
            && $dbman->table_exists(new xmldb_table('gmk_letter_dataset_def'))
            && $dbman->table_exists(new xmldb_table('gmk_letter_type_dataset'))) {

            $now = time();
            $systemuserid = (int)$DB->get_field('user', 'id', ['username' => 'admin'], IGNORE_MISSING);
            if ($systemuserid <= 0) {
                $systemuserid = 0;
            }

            $defaultletters = [
                [
                    'code' => 'constancia_estudios',
                    'name' => 'Constancia de estudios',
                    'warningtext' => 'Verifique que sus datos personales esten actualizados antes de solicitar esta carta.',
                    'cost' => 0.00,
                    'deliverymode' => 'digital',
                    'generationmode' => 'auto',
                    'autostamp' => 1,
                    'autosignature' => 1,
                    'template_html' => '<h1>Constancia de estudios</h1><p>Por medio de la presente se certifica que <strong>{{student.fullname}}</strong>, con documento <strong>{{student.document_number}}</strong>, es estudiante activo de la institucion.</p><p>Fecha de expedicion: {{date.today}}</p><p>{{request.observation}}</p>{{DATASET:periodo_actual}}',
                    'datasets' => ['periodo_actual'],
                ],
                [
                    'code' => 'carta_practica_profesional',
                    'name' => 'Carta para practica profesional',
                    'warningtext' => 'Para esta carta el estudiante debe estar cursando su ultimo cuatrimestre y cumplir las condiciones academicas definidas por la institucion.',
                    'cost' => 0.00,
                    'deliverymode' => 'fisica',
                    'generationmode' => 'manual',
                    'autostamp' => 0,
                    'autosignature' => 0,
                    'template_html' => '<h1>Carta de practica profesional</h1><p>Se certifica que <strong>{{student.fullname}}</strong>, identificado con <strong>{{student.document_number}}</strong>, es estudiante de la institucion y solicita esta carta para fines de practica profesional.</p><p>Fecha: {{date.today}}</p><p>{{request.observation}}</p>{{DATASET:resumen_creditos}}',
                    'datasets' => ['resumen_creditos'],
                ],
                [
                    'code' => 'certificacion_creditos',
                    'name' => 'Certificacion de creditos y asignaturas',
                    'warningtext' => 'Esta solicitud genera factura y se procesa automaticamente despues del pago.',
                    'cost' => 10.00,
                    'deliverymode' => 'digital',
                    'generationmode' => 'auto',
                    'autostamp' => 1,
                    'autosignature' => 1,
                    'template_html' => '<h1>Certificacion de creditos y asignaturas</h1><p>Se certifica el avance academico de <strong>{{student.fullname}}</strong>, documento <strong>{{student.document_number}}</strong>.</p><p>Fecha: {{date.today}}</p>{{DATASET:resumen_creditos}}{{DATASET:asignaturas_cursadas}}',
                    'datasets' => ['resumen_creditos', 'asignaturas_cursadas'],
                ],
            ];

            $datasetbycode = [];
            $datasetrecords = $DB->get_records('gmk_letter_dataset_def', []);
            foreach ($datasetrecords as $datasetrecord) {
                $datasetbycode[(string)$datasetrecord->code] = (int)$datasetrecord->id;
            }

            foreach ($defaultletters as $def) {
                $type = $DB->get_record('gmk_letter_type', ['code' => $def['code']], '*', IGNORE_MISSING);
                if (!$type) {
                    $typeid = $DB->insert_record('gmk_letter_type', (object)[
                        'code' => $def['code'],
                        'name' => $def['name'],
                        'warningtext' => $def['warningtext'],
                        'cost' => $def['cost'],
                        'active' => 1,
                        'deliverymode' => $def['deliverymode'],
                        'generationmode' => $def['generationmode'],
                        'autostamp' => $def['autostamp'],
                        'autosignature' => $def['autosignature'],
                        'stampimageurl' => '',
                        'signatureimageurl' => '',
                        'odoo_product_id' => 0,
                        'template_html' => $def['template_html'],
                        'usermodified' => $systemuserid,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                } else {
                    $typeid = (int)$type->id;
                }

                $sortorder = 1;
                foreach ($def['datasets'] as $datasetcode) {
                    if (empty($datasetbycode[$datasetcode])) {
                        continue;
                    }
                    $datasetid = (int)$datasetbycode[$datasetcode];
                    if (!$DB->record_exists('gmk_letter_type_dataset', ['lettertypeid' => $typeid, 'datasetdefid' => $datasetid])) {
                        $DB->insert_record('gmk_letter_type_dataset', (object)[
                            'lettertypeid' => $typeid,
                            'datasetdefid' => $datasetid,
                            'sortorder' => $sortorder,
                            'usermodified' => $systemuserid,
                            'timecreated' => $now,
                            'timemodified' => $now,
                        ]);
                    }
                    $sortorder++;
                }
            }
        }

        upgrade_plugin_savepoint(true, 20260324000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260324010) {
        // Add public verification fields to generated letter documents.
        $table = new xmldb_table('gmk_letter_document');

        $field = new xmldb_field('verificationtoken', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'odoo_attachment_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('verificationurl', XMLDB_TYPE_TEXT, null, null, null, null, null, 'verificationtoken');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('verificationtokenux', XMLDB_INDEX_UNIQUE, ['verificationtoken']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Backfill existing documents so old letters can also be verified publicly.
        $records = $DB->get_records_select('gmk_letter_document', '(verificationtoken IS NULL OR verificationtoken = :empty)', ['empty' => '']);
        foreach ($records as $record) {
            do {
                try {
                    $token = bin2hex(random_bytes(20));
                } catch (Throwable $ex) {
                    $token = sha1(uniqid((string)time(), true) . mt_rand());
                }
            } while ($DB->record_exists('gmk_letter_document', ['verificationtoken' => $token]));

            $record->verificationtoken = $token;
            $record->verificationurl = rtrim($CFG->wwwroot, '/') . '/local/grupomakro_core/pages/letter_verify.php?t=' . $token;
            $DB->update_record('gmk_letter_document', $record);
        }

        upgrade_plugin_savepoint(true, 20260324010, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260324020) {
        // Ensure absence dashboard capability exists and is assigned to internal administrative role.
        assign_capabilities_to_internal_roles();
        upgrade_plugin_savepoint(true, 20260324020, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260324030) {
        // Add is_module and module_deadline_days fields to gmk_class for independent study modules.
        $table = new xmldb_table('gmk_class');

        $field_is_module = new xmldb_field('is_module', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'career_label');
        if (!$DB->get_manager()->field_exists($table, $field_is_module)) {
            $DB->get_manager()->add_field($table, $field_is_module);
        }

        $field_deadline = new xmldb_field('module_deadline_days', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '25', 'is_module');
        if (!$DB->get_manager()->field_exists($table, $field_deadline)) {
            $DB->get_manager()->add_field($table, $field_deadline);
        }

        // Create gmk_module_enrollment table.
        $enroll_table = new xmldb_table('gmk_module_enrollment');
        if (!$DB->get_manager()->table_exists($enroll_table)) {
            $enroll_table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $enroll_table->add_field('classid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $enroll_table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $enroll_table->add_field('enrolldate',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $enroll_table->add_field('duedate',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $enroll_table->add_field('status',       XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'active');
            $enroll_table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $enroll_table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $enroll_table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $enroll_table->add_key('primary',  XMLDB_KEY_PRIMARY,  ['id']);
            $enroll_table->add_key('classfk',  XMLDB_KEY_FOREIGN,  ['classid'], 'gmk_class', ['id']);
            $enroll_table->add_key('userfk',   XMLDB_KEY_FOREIGN,  ['userid'],  'user',       ['id']);

            $enroll_table->add_index('classuser_unique', XMLDB_INDEX_UNIQUE, ['classid', 'userid']);

            $DB->get_manager()->create_table($enroll_table);
        }

        upgrade_plugin_savepoint(true, 20260324030, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260414010) {
        $obs_table = new xmldb_table('gmk_student_observations');
        if (!$DB->get_manager()->table_exists($obs_table)) {
            $obs_table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $obs_table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $obs_table->add_field('classid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $obs_table->add_field('teacherid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $obs_table->add_field('observation',  XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL, null, null);
            $obs_table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $obs_table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $obs_table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $obs_table->add_index('userid_classid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'classid']);
            $DB->get_manager()->create_table($obs_table);
        }
        upgrade_plugin_savepoint(true, 20260414010, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260414020) {
        // Deduplicate gmk_financial_status: keep only the row with the highest id per userid,
        // then add a UNIQUE index on userid to prevent future duplicates.
        // The subquery alias is required by MySQL to avoid "can't reopen table" error.
        $DB->execute("
            DELETE FROM {gmk_financial_status}
            WHERE id NOT IN (
                SELECT max_id FROM (
                    SELECT MAX(id) AS max_id FROM {gmk_financial_status} GROUP BY userid
                ) AS latest
            )
        ");

        $table = new xmldb_table('gmk_financial_status');
        $index = new xmldb_index('userid_unique', XMLDB_INDEX_UNIQUE, ['userid']);
        if (!$DB->get_manager()->index_exists($table, $index)) {
            $DB->get_manager()->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 20260414020, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260420001) {
        // Add original_status column to gmk_module_enrollment to store the course status
        // before enrolling in a module, so it can be restored when the module is removed.
        $table = new xmldb_table('gmk_module_enrollment');
        $field = new xmldb_field('original_status', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 20260420001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260423002) {
        // Define table gmk_student_deferrals to be created.
        // NOTE: Table name shortened from gmk_academic_student_deferrals (33 chars)
        // to gmk_student_deferrals (22 chars) to comply with Moodle's 28-char limit.
        $table = new xmldb_table('gmk_student_deferrals');

        // Adding fields to table gmk_student_deferrals.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('career', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('shift', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('current_level', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('target_period_index', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_student_deferrals.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Adding indexes to table gmk_student_deferrals.
        $table->add_index('uniq_period_user_course', XMLDB_INDEX_UNIQUE, ['academicperiodid', 'userid', 'courseid']);
        $table->add_index('academicperiod_idx', XMLDB_INDEX_NOTUNIQUE, ['academicperiodid']);
        $table->add_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        

        // Conditionally launch create table for gmk_student_deferrals.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260423002, 'local', 'grupomakro_core');
    }

    // 20260424001 - FASE 4: Crear tabla gmk_course_projections para proyecciones de asignaturas por jornada
    if ($oldversion < 20260424001) {
        // Define table gmk_course_projections to be created.
        // Esta tabla almacena la proyecciÃ³n de una asignatura para un subperiodo especÃ­fico y jornada.
        // Permite hacer drag-drop desde el panel de asignaturas hacia los bloques de bimestre.
        $table = new xmldb_table('gmk_course_projections');

        // Adding fields to table gmk_course_projections.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('learning_courses_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);  // FK a local_learning_courses.id
        $table->add_field('subperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);          // FK a local_learning_subperiods.id
        $table->add_field('jornada', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);                 // Diurna, Nocturna, Sabatina
        $table->add_field('projected_opening_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null);        // timestamp opcional
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');               // 0=planned, 1=confirmed, 2=cancelled
        $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table gmk_course_projections.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_learning_courses', XMLDB_KEY_FOREIGN, ['learning_courses_id'], 'local_learning_courses', ['id']);
        $table->add_key('fk_subperiod', XMLDB_KEY_FOREIGN, ['subperiodid'], 'local_learning_subperiods', ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

        // Adding indexes to table gmk_course_projections.
        // La combinaciÃ³n learning_courses_id + subperiodid + jornada debe ser Ãºnica
        $table->add_index('idx_lc_sp_jornada', XMLDB_INDEX_UNIQUE, ['learning_courses_id', 'subperiodid', 'jornada']);
        $table->add_index('idx_jornada', XMLDB_INDEX_NOTUNIQUE, ['jornada']);
        // Nota: No agregamos Ã­ndice para subperiodid porque ya existe como parte de la FK

        // Conditionally launch create table for gmk_course_projections.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Grupomakro_core savepoint reached.
        upgrade_plugin_savepoint(true, 20260424001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260507001) {
        // Create gmk_class_closure_log table for grade closure audit trail.
        $table = new xmldb_table('gmk_class_closure_log');
        $table->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('classid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('closedby',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeclosed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('approved',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failed',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('revalid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('no_grade',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('notes',      XMLDB_TYPE_TEXT,    null, null, null,          null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('classid_idx', XMLDB_INDEX_NOTUNIQUE, ['classid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260507001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260609001) {
        // Create gmk_revalidations table for teacher-driven revalidation management.
        $table = new xmldb_table('gmk_revalidations');
        $table->add_field('id',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('classid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('corecourseid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('learningplanid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('progreid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('originalgrade',  XMLDB_TYPE_NUMBER,  '5, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('revalidgrade',   XMLDB_TYPE_NUMBER,  '5, 2', null, null,          null, null);
        $table->add_field('result',         XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('bbbcmid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sessionstart',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sessionend',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('invoice_extref', XMLDB_TYPE_CHAR,    '64', null, null,          null, null);
        $table->add_field('invoice_id',     XMLDB_TYPE_CHAR,    '32', null, null,          null, null);
        $table->add_field('invoice_number', XMLDB_TYPE_CHAR,    '64', null, null,          null, null);
        $table->add_field('payment_link',   XMLDB_TYPE_CHAR,    '1333', null, null,        null, null);
        $table->add_field('payment_state',  XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'unpaid');
        $table->add_field('paidat',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status',         XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'scheduled');
        $table->add_field('createdby',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('class_user_uix', XMLDB_INDEX_UNIQUE, ['classid', 'userid']);
        
        $table->add_index('invoice_extref_idx', XMLDB_INDEX_NOTUNIQUE, ['invoice_extref']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260609001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260611001) {
        // Create gmk_academic_alerts table for persistent academic alerts
        // (e.g. students who finished their cycle but still have pending courses).
        $table = new xmldb_table('gmk_academic_alerts');
        $table->add_field('id',               XMLDB_TYPE_INTEGER, '20',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',           XMLDB_TYPE_INTEGER, '20',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('learningplanid',   XMLDB_TYPE_INTEGER, '20',  null, null,          null, null);
        $table->add_field('type',             XMLDB_TYPE_CHAR,    '64',  null, XMLDB_NOTNULL, null, 'finished_cycle_with_pending');
        $table->add_field('detail_json',      XMLDB_TYPE_TEXT,    'long', null, null,         null, null);
        $table->add_field('messagetext',      XMLDB_TYPE_TEXT,    'long', null, null,         null, null);
        $table->add_field('status',           XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('timecreated',      XMLDB_TYPE_INTEGER, '20',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeacknowledged', XMLDB_TYPE_INTEGER, '20',  null, null,          null, null);
        $table->add_field('usermodified',     XMLDB_TYPE_INTEGER, '20',  null, null,          null, null);
        $table->add_field('timemodified',     XMLDB_TYPE_INTEGER, '20',  null, null,          null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_idx',       XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('status_idx',       XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('type_idx',         XMLDB_INDEX_NOTUNIQUE, ['type']);
        $table->add_index('userplan_type_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'learningplanid', 'type']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260611001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260612001) {
        // Create gmk_bbb_presence table: accumulates per-student presence time in BBB
        // virtual sessions for the 70%-permanence auto-attendance.
        $table = new xmldb_table('gmk_bbb_presence');
        $table->add_field('id',                  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('attendancesessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('classid',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bbbid',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ismoderator',         XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('present_seconds',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sample_count',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('first_seen',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('last_seen',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reconciled',          XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('session_user_uix', XMLDB_INDEX_UNIQUE, ['attendancesessionid', 'userid']);
        $table->add_index('reconcile_idx',     XMLDB_INDEX_NOTUNIQUE, ['reconciled', 'last_seen']);
        $table->add_index('classid_idx',       XMLDB_INDEX_NOTUNIQUE, ['classid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260612001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260618001) {
        // Diploma templates: visual layout for graduation certificates.
        $table = new xmldb_table('gmk_diploma_template');
        $table->add_field('id',                  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name',                XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description',         XMLDB_TYPE_TEXT,    'long', null, null, null, null);
        $table->add_field('orientation',         XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'landscape');
        $table->add_field('width_mm',            XMLDB_TYPE_NUMBER,  '6, 2', null, XMLDB_NOTNULL, null, '297');
        $table->add_field('height_mm',           XMLDB_TYPE_NUMBER,  '6, 2', null, XMLDB_NOTNULL, null, '210');
        $table->add_field('background_fileid',   XMLDB_TYPE_INTEGER, '10',  null, null, null, '0');
        $table->add_field('background_filename', XMLDB_TYPE_CHAR,    '255', null, null, null, null);
        $table->add_field('background_mimetype', XMLDB_TYPE_CHAR,    '100', null, null, null, null);
        $table->add_field('active',              XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',        XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);
        $table->add_index('name_idx',   XMLDB_INDEX_NOTUNIQUE, ['name']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Layout fields/variables positioned inside a diploma template.
        $table = new xmldb_table('gmk_diploma_tpl_field');
        $table->add_field('id',            XMLDB_TYPE_INTEGER, '10',     null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('templateid',    XMLDB_TYPE_INTEGER, '10',     null, XMLDB_NOTNULL, null, '0');
        $table->add_field('field_type',    XMLDB_TYPE_CHAR,    '20',     null, XMLDB_NOTNULL, null, 'variable');
        $table->add_field('variable_code', XMLDB_TYPE_CHAR,    '50',     null, null, null, null);
        $table->add_field('custom_text',   XMLDB_TYPE_TEXT,    'medium', null, null, null, null);
        $table->add_field('static_text',   XMLDB_TYPE_TEXT,    'medium', null, null, null, null);
        $table->add_field('x_mm',          XMLDB_TYPE_NUMBER,  '7, 2',   null, XMLDB_NOTNULL, null, '20');
        $table->add_field('y_mm',          XMLDB_TYPE_NUMBER,  '7, 2',   null, XMLDB_NOTNULL, null, '20');
        $table->add_field('width_mm',      XMLDB_TYPE_NUMBER,  '7, 2',   null, XMLDB_NOTNULL, null, '80');
        $table->add_field('height_mm',     XMLDB_TYPE_NUMBER,  '7, 2',   null, XMLDB_NOTNULL, null, '12');
        $table->add_field('rotation',      XMLDB_TYPE_NUMBER,  '6, 2',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('font_family',   XMLDB_TYPE_CHAR,    '80',     null, XMLDB_NOTNULL, null, 'helvetica');
        $table->add_field('font_size',     XMLDB_TYPE_NUMBER,  '5, 2',   null, XMLDB_NOTNULL, null, '14');
        $table->add_field('font_weight',   XMLDB_TYPE_CHAR,    '20',     null, XMLDB_NOTNULL, null, 'normal');
        $table->add_field('font_color',    XMLDB_TYPE_CHAR,    '15',     null, XMLDB_NOTNULL, null, '#000000');
        $table->add_field('align',         XMLDB_TYPE_CHAR,    '10',     null, XMLDB_NOTNULL, null, 'center');
        $table->add_field('line_height',   XMLDB_TYPE_NUMBER,  '4, 2',   null, XMLDB_NOTNULL, null, '1.2');
        $table->add_field('z_index',       XMLDB_TYPE_INTEGER, '10',     null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified',  XMLDB_TYPE_INTEGER, '10',     null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10',     null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',  XMLDB_TYPE_INTEGER, '10',     null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',         XMLDB_KEY_PRIMARY,  ['id']);
        $table->add_key('templatefk',      XMLDB_KEY_FOREIGN,  ['templateid'],   'gmk_diploma_template', ['id']);
        $table->add_key('usermodifiedfk',  XMLDB_KEY_FOREIGN,  ['usermodified'], 'user', ['id']);
        $table->add_index('zindex_idx',     XMLDB_INDEX_NOTUNIQUE, ['templateid', 'z_index']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Generated diploma records (one per student per template issuance).
        $table = new xmldb_table('gmk_diploma_generation');
        $table->add_field('id',                 XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('templateid',         XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid',             XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('learningplanid',     XMLDB_TYPE_INTEGER, '10',   null, null, null, '0');
        $table->add_field('diploma_number',     XMLDB_TYPE_CHAR,    '64',   null, XMLDB_NOTNULL, null, null);
        $table->add_field('version',            XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('status',             XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null, 'generated');
        $table->add_field('verification_token', XMLDB_TYPE_CHAR,    '64',   null, XMLDB_NOTNULL, null, null);
        $table->add_field('verification_url',   XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('snapshot_json',      XMLDB_TYPE_TEXT,    'long', null, null, null, null);
        $table->add_field('issued_by',          XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('issued_at',          XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('revoked_by',         XMLDB_TYPE_INTEGER, '10',   null, null, null, '0');
        $table->add_field('revoked_at',         XMLDB_TYPE_INTEGER, '10',   null, null, null, '0');
        $table->add_field('revoke_reason',      XMLDB_TYPE_TEXT,    'medium', null, null, null, null);
        $table->add_field('usermodified',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',         XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('templatefk',      XMLDB_KEY_FOREIGN, ['templateid'],  'gmk_diploma_template', ['id']);
        $table->add_key('userfk',          XMLDB_KEY_FOREIGN, ['userid'],      'user', ['id']);
        $table->add_key('issuedfk',        XMLDB_KEY_FOREIGN, ['issued_by'],   'user', ['id']);
        $table->add_key('revokedfk',       XMLDB_KEY_FOREIGN, ['revoked_by'],  'user', ['id']);
        $table->add_key('usermodifiedfk',  XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('token_uix',         XMLDB_INDEX_UNIQUE,   ['verification_token']);
        $table->add_index('user_template_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'templateid']);
        $table->add_index('user_plan_idx',     XMLDB_INDEX_NOTUNIQUE, ['userid', 'learningplanid']);
        $table->add_index('status_idx',        XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('issued_at_idx',     XMLDB_INDEX_NOTUNIQUE, ['issued_at']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // File storage metadata for generated diploma PDFs.
        $table = new xmldb_table('gmk_diploma_document');
        $table->add_field('id',            XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('generationid',  XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('fileitemid',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('filename',      XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('mimetype',      XMLDB_TYPE_CHAR,    '100',  null, XMLDB_NOTNULL, null, 'application/pdf');
        $table->add_field('version',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('filesize',      XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('contenthash',   XMLDB_TYPE_CHAR,    '64',   null, null, null, null);
        $table->add_field('usermodified',  XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',  XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',         XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('generationfk',    XMLDB_KEY_FOREIGN, ['generationid'],  'gmk_diploma_generation', ['id']);
        $table->add_key('usermodifiedfk',  XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('version_idx',    XMLDB_INDEX_NOTUNIQUE, ['generationid', 'version']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260618001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260623001) {
        // Absence alert system: extend gmk_course_progre with class-level access flags
        // and add the gmk_class_absence_state + gmk_class_absence_history tables.

        $table = new xmldb_table('gmk_course_progre');
        $field = new xmldb_field('blocked_by_absence', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'groupid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('blocked_by_absence_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'blocked_by_absence');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('blocked_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'classid', 'blocked_by_absence']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Per (user, class) absence state.
        $table = new xmldb_table('gmk_class_absence_state');
        $table->add_field('id',                 XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('classid',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid',           XMLDB_TYPE_INTEGER, '10', null, null,        null, '0');
        $table->add_field('absence_count',      XMLDB_TYPE_INTEGER, '4',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('last_session_id',    XMLDB_TYPE_INTEGER, '10', null, null,        null, '0');
        $table->add_field('last_calculated',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('alert_level',        XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('info_dismissed_at',  XMLDB_TYPE_INTEGER, '10', null, null,        null, '0');
        $table->add_field('warning_dismissed_at', XMLDB_TYPE_INTEGER, '10', null, null,      null, '0');
        $table->add_field('blocked_at',         XMLDB_TYPE_INTEGER, '10', null, null,        null, '0');
        $table->add_field('unblocked_at',       XMLDB_TYPE_INTEGER, '10', null, null,        null, '0');
        $table->add_field('block_reason',       XMLDB_TYPE_CHAR,    '255', null, null,       null, null);
        $table->add_field('usermodified',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userfk',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('userclass_uix', XMLDB_INDEX_UNIQUE,   ['userid', 'classid']);
        $table->add_index('alert_idx',     XMLDB_INDEX_NOTUNIQUE, ['userid', 'alert_level']);
        $table->add_index('class_idx',     XMLDB_INDEX_NOTUNIQUE, ['classid', 'alert_level']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Append-only audit log of state transitions.
        $table = new xmldb_table('gmk_class_absence_history');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('classid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sessionid',    XMLDB_TYPE_INTEGER, '10', null, null,        null, '0');
        $table->add_field('count_after',  XMLDB_TYPE_INTEGER, '4',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('level_after',  XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('action',       XMLDB_TYPE_CHAR,    '32', null, XMLDB_NOTNULL, null, 'recompute');
        $table->add_field('details',      XMLDB_TYPE_TEXT,    'medium', null, null,    null, null);
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userfk',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('userclass_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'classid']);
        $table->add_index('time_idx',      XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260623001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260701001) {
        // MitigaciÃ³n de despliegue a mitad de perÃ­odo: el feature flag de
        // blocking se introduce como setting separado (enable_absence_blocking)
        // y se mantiene apagado hasta que se decida activar. No requiere
        // cambios de esquema; la guarda se aplica en absence_helpers.
        upgrade_plugin_savepoint(true, 20260701001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260701002) {
        // Force Moodle to re-register the message providers declared in
        // db/messages.php (added in this version: absence_info_alert,
        // absence_warning_alert, absence_block_alert). The actual savepoint
        // call below triggers the message provider reload.
        upgrade_plugin_savepoint(true, 20260701002, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260701004) {
        // Homologation feature: track source and reason on gmk_course_progre
        // so the academic panel can show a chip per asignatura indicating how
        // the consolidated "Nota Final Integrada" was assigned (suficiencia /
        // migracion / homologacion). All four fields are nullable so legacy
        // rows stay untouched.
        $table = new xmldb_table('gmk_course_progre');

        $field = new xmldb_field('homologation_type', XMLDB_TYPE_CHAR, '20', null, null, null, '', 'blocked_by_absence_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('homologation_note', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'homologation_type');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('homologation_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'homologation_note');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('homologation_by', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'homologation_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 20260701004, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260701008) {
        // Module invoice requests: gate module enrollment behind a paid Odoo invoice.
        // Mirrors the revalidation flow (gmk_revalidations + REVALID_REQ: ref) but
        // uses MODULE_REQ:<id> as the invoice ref prefix.
        $table = new xmldb_table('gmk_module_invoice_requests');

        $table->add_field('id',               XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('corecourseid',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('learningplanid',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('module_type',      XMLDB_TYPE_CHAR,    '32',   null, XMLDB_NOTNULL, null, 'tronco_comun');
        $table->add_field('invoice_extref',   XMLDB_TYPE_CHAR,    '64',   null, null,          null, null);
        $table->add_field('invoice_id',       XMLDB_TYPE_CHAR,    '32',   null, null,          null, null);
        $table->add_field('invoice_number',   XMLDB_TYPE_CHAR,    '64',   null, null,          null, null);
        $table->add_field('payment_link',     XMLDB_TYPE_CHAR,    '1333', null, null,          null, null);
        $table->add_field('amount',           XMLDB_TYPE_NUMBER,  '10',   null, XMLDB_NOTNULL, null, '0', null, '2');
        $table->add_field('payment_state',    XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null, 'unpaid');
        $table->add_field('status',           XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null, 'pending_payment');
        $table->add_field('enrolled_classid', XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('expires_at',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('paidat',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',      XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userfk',   XMLDB_KEY_FOREIGN, ['userid'],       'user',   ['id']);
        $table->add_key('coursefk', XMLDB_KEY_FOREIGN, ['corecourseid'], 'course', ['id']);

        $table->add_index('user_course_status_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'corecourseid', 'status']);
        $table->add_index('invoice_extref_idx',     XMLDB_INDEX_NOTUNIQUE, ['invoice_extref']);
        $table->add_index('status_idx',             XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('expires_idx',            XMLDB_INDEX_NOTUNIQUE, ['expires_at']);
        $table->add_index('user_status_idx',        XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260701008, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260801002) {
        // Extemporaneous revalidations: when the academic director creates a
        // revalidation request outside the normal calendar window, the row is
        // marked with extemporaneous=1 and stores the actor + reason + timestamp
        // for audit purposes. Existing rows default to extemporaneous=0.
        $table = new xmldb_table('gmk_revalidations');

        $field = new xmldb_field('extemporaneous', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('extemporaneous_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'extemporaneous');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('extemporaneous_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'extemporaneous_by');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('extemporaneous_reason', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'extemporaneous_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('extemp_idx', XMLDB_INDEX_NOTUNIQUE, ['classid', 'userid', 'extemporaneous']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 20260801002, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260803000) {
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Admin broadcast messages (info / warning to student LXP).
        //
        // gmk_admin_message           â€“ the publishable announcement
        // gmk_admin_message_ack       â€“ per-user acknowledgement rows
        // gmk_admin_message_user      â€“ materialised audience so we can
        //                               group stats by career without
        //                               recomputing audience every load.
        //
        // These tables give the academic area a delivery channel for
        // administrative notices that take precedence over the absence
        // alert system (priority column on the message row).
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        // gmk_admin_message
        $table = new xmldb_table('gmk_admin_message');

        $table->add_field('id',                 XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('usermodified',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',       XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('authorid',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('title',              XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('messagetext',        XMLDB_TYPE_TEXT,    'long', null, XMLDB_NOTNULL, null, null);
        $table->add_field('messagetype',        XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null, 'info');
        $table->add_field('audience_scope',     XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null, 'all');
        $table->add_field('audience_careerid',  XMLDB_TYPE_INTEGER, '10',   null, null,          null, '0');
        $table->add_field('audience_groupid',   XMLDB_TYPE_INTEGER, '10',   null, null,          null, '0');
        $table->add_field('require_ack',        XMLDB_TYPE_INTEGER, '1',    null, XMLDB_NOTNULL, null, '1');
        $table->add_field('ack_label',          XMLDB_TYPE_CHAR,    '255',  null, null,          null, null);
        $table->add_field('priority',           XMLDB_TYPE_INTEGER, '4',    null, XMLDB_NOTNULL, null, '50');
        $table->add_field('starts_at',          XMLDB_TYPE_INTEGER, '10',   null, null,          null, '0');
        $table->add_field('ends_at',            XMLDB_TYPE_INTEGER, '10',   null, null,          null, '0');
        $table->add_field('active',             XMLDB_TYPE_INTEGER, '1',    null, XMLDB_NOTNULL, null, '1');

        $table->add_key('primary',       XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('authorfk',      XMLDB_KEY_FOREIGN, ['authorid'],     'user',               ['id']);
        $table->add_key('usermodifiedfk', XMLDB_KEY_FOREIGN, ['usermodified'], 'user',               ['id']);
        $table->add_key('careerfk',      XMLDB_KEY_FOREIGN, ['audience_careerid'], 'local_learning_plans', ['id']);

        $table->add_index('active_idx',       XMLDB_INDEX_NOTUNIQUE, ['active']);
        $table->add_index('timecreated_idx',  XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $table->add_index('priority_idx',     XMLDB_INDEX_NOTUNIQUE, ['priority', 'active']);
        $table->add_index('scope_idx',        XMLDB_INDEX_NOTUNIQUE, ['audience_scope', 'audience_careerid', 'audience_groupid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_admin_message_ack
        $table = new xmldb_table('gmk_admin_message_ack');

        $table->add_field('id',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('messageid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('acknowledged',     XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timeacknowledged', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('messagefk', XMLDB_KEY_FOREIGN, ['messageid'], 'gmk_admin_message', ['id']);
        $table->add_key('userfk',    XMLDB_KEY_FOREIGN, ['userid'],    'user',              ['id']);

        // Moodle auto-creates a non-unique index on each FK column, so we
        // only declare the additional indexes that are not redundant with
        // a FK (messageid has an FK -> no manual message_idx; userid too).
        $table->add_index('message_user_uix', XMLDB_INDEX_UNIQUE, ['messageid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_admin_message_user
        $table = new xmldb_table('gmk_admin_message_user');

        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('messageid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('careerid',    XMLDB_TYPE_INTEGER, '10', null, null,          null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('messagefk', XMLDB_KEY_FOREIGN, ['messageid'], 'gmk_admin_message', ['id']);
        $table->add_key('userfk',    XMLDB_KEY_FOREIGN, ['userid'],    'user',              ['id']);

        // No manual message_idx (covered by the messagefk FK).
        $table->add_index('message_user_uix',   XMLDB_INDEX_UNIQUE, ['messageid', 'userid']);
        $table->add_index('message_career_idx', XMLDB_INDEX_NOTUNIQUE, ['messageid', 'careerid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260803000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260805000) {
        // Individual course certificates: which courses are eligible for
        // per-student certificate generation + a nullable courseid column
        // on gmk_diploma_generation so the same table can store both
        // learning-plan diplomas (courseid NULL) and per-course diplomas.

        // 1) gmk_diploma_eligible_course (admin-managed eligibility).
        $table = new xmldb_table('gmk_diploma_eligible_course');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled',      XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('coursefk',  XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
        $table->add_index('enabled_idx', XMLDB_INDEX_NOTUNIQUE, ['enabled']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2) Add courseid column to gmk_diploma_generation.
        $gtable = new xmldb_table('gmk_diploma_generation');
        $gfield = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'learningplanid');
        if (!$dbman->field_exists($gtable, $gfield)) {
            $dbman->add_field($gtable, $gfield);
        }

        upgrade_plugin_savepoint(true, 20260805000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260805002) {
        // Homologation audit log: gmk_homologation_audit captures every
        // homologate/revert action with the (user, course, plan) context, the
        // grade and type that were set/cleared, the previous state for reverts
        // and the director who applied the change. Powers the timeline view
        // in the academic panel grades modal.

        $table = new xmldb_table('gmk_homologation_audit');
        $table->add_field('id',                  XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',              XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null, '0');
        $table->add_field('corecourseid',        XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null, '0');
        $table->add_field('learningplanid',      XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null, '0');
        $table->add_field('gcp_id',              XMLDB_TYPE_INTEGER, '10',    null, null,          null, null);
        $table->add_field('action',              XMLDB_TYPE_CHAR,    '16',    null, XMLDB_NOTNULL, null, 'homologate');
        $table->add_field('type',                XMLDB_TYPE_CHAR,    '20',    null, null,          null, null);
        $table->add_field('grade',               XMLDB_TYPE_NUMBER,  '5',     null, null,          null, null, '2');
        $table->add_field('course_status',       XMLDB_TYPE_INTEGER, '2',     null, null,          null, null);
        $table->add_field('observation',         XMLDB_TYPE_TEXT,    'medium', null, null,         null, null);
        $table->add_field('previous_observation', XMLDB_TYPE_TEXT,   'medium', null, null,         null, null);
        $table->add_field('previous_grade',      XMLDB_TYPE_NUMBER,  '5',     null, null,          null, null, '2');
        $table->add_field('previous_status',     XMLDB_TYPE_INTEGER, '2',     null, null,          null, null);
        $table->add_field('previous_type',       XMLDB_TYPE_CHAR,    '20',    null, null,          null, null);
        $table->add_field('applied_by',          XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null, '0');
        $table->add_field('applied_at',          XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('user_course_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'corecourseid', 'learningplanid']);
        $table->add_index('action_idx',      XMLDB_INDEX_NOTUNIQUE, ['action']);
        $table->add_index('applied_at_idx',  XMLDB_INDEX_NOTUNIQUE, ['applied_at']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260805002, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260806000) {
        // Per-session "is_revalida" flag so the attendance-grade calculations
        // (academicpanel, teacher dashboard, LXP gradebook, student
        // absences, 3-strikes inactivation) and the per-class weighted grade
        // resolver exclude the makeup/revalidation session that closes a
        // course. Defaults to 0 (regular session); teachers can toggle it
        // from the AttendancePanel / ManageClass UI.
        $atable = new xmldb_table('attendance_sessions');
        $afield = new xmldb_field('is_revalida',
            XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'lasttakenby');
        if (!$dbman->field_exists($atable, $afield)) {
            $dbman->add_field($atable, $afield);
        }
        $aindex = new xmldb_index('att_sess_is_revalida_idx',
            XMLDB_INDEX_NOTUNIQUE, ['is_revalida']);
        if (!$dbman->index_exists($atable, $aindex)) {
            $dbman->add_index($atable, $aindex);
        }

        // Backfill for this week (Mon 06-Jul-2026 .. Sat 11-Jul-2026 Panama
        // time = UTC-5, no DST): mark the LAST attendance session per class
        // as is_revalida=1 if it falls in that window. The same logic is
        // also available as a SELECT-only dry-run script:
        //   cli/mark_revalida_window_jul6_jul11.php
        // Any subsequent week is handled either by the UI toggle (Phase 2)
        // or by an at-creation auto-marker (Phase 3, future).
        $rvStart = gmmktime(5, 0, 0, 7, 6, 2026);  // 06-Jul 00:00 Panama.
        $rvEnd   = gmmktime(5, 0, 0, 7, 12, 2026); // 12-Jul 00:00 Panama (excl).
        $rvNow   = time();
        // Find ids of "last per attendance" sessions in the window.
        $rvIds = $DB->get_fieldset_sql(
            "SELECT s.id
               FROM {attendance_sessions} s
              WHERE s.sessdate >= :start
                AND s.sessdate <  :end
                AND s.sessdate = (
                       SELECT MAX(s2.sessdate)
                         FROM {attendance_sessions} s2
                        WHERE s2.attendanceid = s.attendanceid
                    )",
            ['start' => $rvStart, 'end' => $rvEnd]
        );
        if (!empty($rvIds)) {
            list($rvinsql, $rvparams) = $DB->get_in_or_equal($rvIds, SQL_PARAMS_NAMED, 'rv');
            $DB->execute(
                "UPDATE {attendance_sessions}
                    SET is_revalida = 1, timemodified = :now
                  WHERE id $rvinsql",
                ['now' => $rvNow] + $rvparams
            );
        }
        $rvMarked = is_array($rvIds) ? count($rvIds) : 0;
        $rvMessage = "Phase 1: is_revalida flag added; backfilled " . (int)$rvMarked
            . " session(s) in [Mon 06-Jul .. Sat 11-Jul 2026] that are the last of their class.";
        gmk_log('INFO: ' . $rvMessage);
        // Visible in the upgrade output.
        echo PHP_EOL . "[grupomakro_core] " . $rvMessage . PHP_EOL;

        upgrade_plugin_savepoint(true, 20260806000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260809001) {
        // Indexes for the paginated classmanagement view (WS
        // local_grupomakro_list_classes_paged). The gmk_class table only
        // had the primary key + a single FK on usermodified, so every
        // filter (closed, periodid, learningplanid, corecourseid) and the
        // default ORDER BY timecreated DESC were full-table scans. These
        // indexes bring that down to index range scans, which is what
        // makes the new server-side paginated view viable at scale.
        $ctable = new xmldb_table('gmk_class');

        $cindexes = [
            ['name' => 'gmkcls_closed_idx',         'fields' => ['closed']],
            ['name' => 'gmkcls_period_idx',         'fields' => ['periodid']],
            ['name' => 'gmkcls_lp_idx',             'fields' => ['learningplanid']],
            ['name' => 'gmkcls_corecourse_idx',     'fields' => ['corecourseid']],
            ['name' => 'gmkcls_timecreated_idx',    'fields' => ['timecreated']],
            // Composite covering the most common query in classmanagement:
            // "Active classes of the current period, newest first".
            ['name' => 'gmkcls_closed_period_tc_idx', 'fields' => ['closed', 'periodid', 'timecreated']],
            // Covers list_classes's JOIN path gmk_class.courseid =
            // local_learning_courses.id (subject resolution).
            ['name' => 'gmkcls_courseid_idx',       'fields' => ['courseid']],
        ];
        foreach ($cindexes as $idxdef) {
            $idx = new xmldb_index($idxdef['name'], XMLDB_INDEX_NOTUNIQUE, $idxdef['fields']);
            if (!$dbman->index_exists($ctable, $idx)) {
                $dbman->add_index($ctable, $idx);
            }
        }

        // Index for the LIKE search on course.fullname triggered by the
        // classmanagement search box. The default Moodle install already
        // creates an index on course.shortname but not on fullname; this
        // adds one explicitly. Without it the search LIKE has to full-
        // scan the course table, which is acceptable in dev but painful
        // in production.
        $coursetable = new xmldb_table('course');
        $cfullnameidx = new xmldb_index('course_fullname_search_idx', XMLDB_INDEX_NOTUNIQUE, ['fullname']);
        if (!$dbman->index_exists($coursetable, $cfullnameidx)) {
            $dbman->add_index($coursetable, $cfullnameidx);
        }

        upgrade_plugin_savepoint(true, 20260809001, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260812000) {
        // Homologation Manager: reusable origin->destination course homologation rules.
        $table = new xmldb_table('gmk_homologation_rules');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('origin_planid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('origin_courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('dest_planid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('dest_courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('homologation_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'homologacion');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('rule_unique_idx', XMLDB_INDEX_UNIQUE, ['origin_planid', 'origin_courseid', 'dest_planid', 'dest_courseid']);
            $table->add_index('dest_plan_idx', XMLDB_INDEX_NOTUNIQUE, ['dest_planid']);
            $table->add_index('origin_plan_idx', XMLDB_INDEX_NOTUNIQUE, ['origin_planid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260812000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260813000) {
        // Diploma consecutive bundles: let admins group templates that
        // share an external sequence (e.g. so all "Faculty of Engineering"
        // templates keep a single counter regardless of how many of each
        // variant get issued). The bundle owns the prefix (optional) and
        // the next_number; templates linked to a bundle inherit that
        // counter and bump it after each generation.

        // 1) gmk_diploma_bundle (short name: MySQL 28-char limit).
        $btable = new xmldb_table('gmk_diploma_bundle');
        $btable->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $btable->add_field('name',         XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL, null, '');
        $btable->add_field('prefix',       XMLDB_TYPE_CHAR,    '40',  null, null, null, '');
        $btable->add_field('next_number',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '1');
        $btable->add_field('active',       XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '1');
        $btable->add_field('usermodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $btable->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $btable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');

        $btable->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
        $btable->add_index('name_uix', XMLDB_INDEX_UNIQUE, ['name']);
        $btable->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);

        if (!$dbman->table_exists($btable)) {
            $dbman->create_table($btable);
        }

        // 2) Nullable bundle_id on gmk_diploma_template.
        $gtable = new xmldb_table('gmk_diploma_template');
        $bfield = new xmldb_field('bundle_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'active');
        if (!$dbman->field_exists($gtable, $bfield)) {
            $dbman->add_field($gtable, $bfield);
        }
        $gkey = new xmldb_key('bundlefk', XMLDB_KEY_FOREIGN, ['bundle_id'], 'gmk_diploma_bundle', ['id']);
        $dbman->add_key($gtable, $gkey);

        upgrade_plugin_savepoint(true, 20260813000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260815000) {
        // Academic Movements Phase 1: persist per-attempt history and append-only
        // academic movements so the resolver can pick the best grade across
        // re-enrolments without overwriting prior evidence. See
        // classes/local/academic_movement_manager.php and academic_grade_resolver.php.

        // 1) gmk_course_attempts: immutable per-attempt record (regular or module).
        $atable = new xmldb_table('gmk_course_attempts');
        if (!$dbman->table_exists($atable)) {
            $atable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $atable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $atable->add_field('learningplanid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $atable->add_field('corecourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $atable->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $atable->add_field('attempt_no', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
            $atable->add_field('is_module', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $atable->add_field('enroll_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $atable->add_field('end_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $atable->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active');
            $atable->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $atable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $atable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $atable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $atable->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $atable->add_index('attempt_uix', XMLDB_INDEX_UNIQUE, ['userid', 'learningplanid', 'corecourseid', 'attempt_no']);
            $atable->add_index('attempt_lookup_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'learningplanid', 'corecourseid', 'status']);

            $dbman->create_table($atable);
        }

        // 2) gmk_academic_movements: append-only per-event record.
        $mtable = new xmldb_table('gmk_academic_movements');
        if (!$dbman->table_exists($mtable)) {
            $mtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $mtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $mtable->add_field('learningplanid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $mtable->add_field('corecourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $mtable->add_field('attempt_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $mtable->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $mtable->add_field('source', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'class_close');
            $mtable->add_field('source_record_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $mtable->add_field('grade', XMLDB_TYPE_NUMBER, '5', null, null, null, null);
            $mtable->add_field('course_status', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
            $mtable->add_field('effective_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $mtable->add_field('annulled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $mtable->add_field('annulled_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $mtable->add_field('annulled_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $mtable->add_field('annul_reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $mtable->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $mtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $mtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $mtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $mtable->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $mtable->add_key('attemptfk', XMLDB_KEY_FOREIGN, ['attempt_id'], 'gmk_course_attempts', ['id']);
            $mtable->add_index('resolve_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'learningplanid', 'corecourseid', 'annulled', 'effective_at']);
            $mtable->add_index('attempt_idx', XMLDB_INDEX_NOTUNIQUE, ['attempt_id', 'annulled']);
            $mtable->add_index('source_idx', XMLDB_INDEX_NOTUNIQUE, ['source', 'source_record_id']);

            $dbman->create_table($mtable);
        }

        // 3) gmk_movement_deletion_log: append-only audit of annul actions.
        $ltable = new xmldb_table('gmk_movement_deletion_log');
        if (!$dbman->table_exists($ltable)) {
            $ltable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $ltable->add_field('movement_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ltable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ltable->add_field('learningplanid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ltable->add_field('corecourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ltable->add_field('snapshot_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $ltable->add_field('reason', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $ltable->add_field('acted_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ltable->add_field('acted_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $ltable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $ltable->add_key('movementlogfk', XMLDB_KEY_FOREIGN, ['movement_id'], 'gmk_academic_movements', ['id']);
            $ltable->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $dbman->create_table($ltable);
        }

        upgrade_plugin_savepoint(true, 20260815000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260816000) {
        // Status change wizard: extend gmk_student_suspension with origin + details
        // so the academic timeline can distinguish LXP-driven changes from Odoo
        // sync and capture the full snapshot of what was changed (active courses
        // dropped, target period, Odoo sync result, etc.).

        $stable = new xmldb_table('gmk_student_suspension');

        $origin = new xmldb_field('origin', XMLDB_TYPE_CHAR, '20', null, null, null, 'odoo');
        if (!$dbman->field_exists($stable, $origin)) {
            $dbman->add_field($stable, $origin);
        }

        $details = new xmldb_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($stable, $details)) {
            $dbman->add_field($stable, $details);
        }

        $historyidx = new xmldb_index('history_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        if (!$dbman->index_exists($stable, $historyidx)) {
            $dbman->add_index($stable, $historyidx);
        }

        upgrade_plugin_savepoint(true, 20260816000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260818000) {
        // Financial status real-time: dead-letter queue for the
        // pages/financial_webhook.php endpoint so we don't lose pushes
        // from Express /api/odoo/cache/invalidate when the proxy call
        // fails. Admins can inspect/retry from
        // pages/financial_webhook_dlq.php.
        $table = new xmldb_table('gmk_financial_webhook_dlq');

        $table->add_field('id',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('partner_vat',      XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, '');
        $table->add_field('reason',           XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, '');
        $table->add_field('invoice_id',       XMLDB_TYPE_CHAR,    '50', null, null,         null, null);
        $table->add_field('event_time',       XMLDB_TYPE_CHAR,    '50', null, null,         null, null);
        $table->add_field('payload',          XMLDB_TYPE_TEXT,    null, null, null,         null, null);
        $table->add_field('signature',        XMLDB_TYPE_CHAR,   '128', null, null,         null, null);
        $table->add_field('attempts',         XMLDB_TYPE_INTEGER,  '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('last_error',       XMLDB_TYPE_TEXT,    null, null, null,         null, null);
        $table->add_field('last_received_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('state',            XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('timecreated',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('state_idx',       XMLDB_INDEX_NOTUNIQUE, ['state']);
        $table->add_index('vat_idx',         XMLDB_INDEX_NOTUNIQUE, ['partner_vat']);
        $table->add_index('received_at_idx', XMLDB_INDEX_NOTUNIQUE, ['last_received_at']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260818000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260826000) {
        // Support teacher: optional second teacher per class with the same
        // Moodle-level capabilities as the main instructor for this class only.
        // Admin/director managed; UI in pages/editclass.php adds a second
        // <select> for it. See locallib.php::update_class() and
        // gmk_sync_bbb_moderator_rules_for_class() for the runtime effects.
        $table = new xmldb_table('gmk_class');
        $field = new xmldb_field(
            'supportinstructorid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'instructorlpid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('supportinstructorid_idx', XMLDB_INDEX_NOTUNIQUE, ['supportinstructorid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 20260826000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260827000) {
        // Student-facing revalidation notice: alert_sent_at stops the message
        // being re-sent on every idempotent re-schedule, alert_dismissed_at
        // holds the "no volver a mostrar" choice made in the LXP popup.
        $table = new xmldb_table('gmk_revalidations');

        $field = new xmldb_field('alert_sent_at', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'createdby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('alert_dismissed_at', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'alert_sent_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The teacher dashboard now reports gradebook weights, so the archetype
        // change below must also be reflected on the roles that already exist:
        // the revalidations director dashboard lists every class in the
        // institute and is not meant for teachers.
        $caps = ['local/grupomakro_core:view_revalidations_dashboard'];
        foreach ($caps as $cap) {
            foreach (['editingteacher', 'scteachrole'] as $shortname) {
                $role = $DB->get_record('role', ['shortname' => $shortname], 'id');
                if ($role) {
                    $DB->delete_records('role_capabilities', [
                        'roleid' => (int)$role->id,
                        'capability' => $cap,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 20260827000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260901000) {

        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // WELLNESS MODULE â€” Phase 1: RF-01, RF-02, RF-04, RF-05, RF-06, RF-09.1, RF-09.2
        // 7 tables: partner category, partner, event, event attachment, registration, dynamic form, dynamic form response.
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        // gmk_wellness_partner_cats --------------------------------------
        $table = new xmldb_table('gmk_wellness_partner_cats');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slug', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('sort', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('active_sort_idx', XMLDB_INDEX_NOTUNIQUE, ['active', 'sort']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_partner ----------------------------------------------
        $table = new xmldb_table('gmk_wellness_partner');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('benefit_description', XMLDB_TYPE_TEXT, 'medium', null, XMLDB_NOTNULL, null, null);
        $table->add_field('conditions', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('requirements', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('contact_label', XMLDB_TYPE_CHAR, '64', null, null, null, '');
        $table->add_field('contact_value', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('logo_path', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('sort', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('categoryfk', XMLDB_KEY_FOREIGN, ['categoryid'], 'gmk_wellness_partner_cats', ['id']);
        $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);
        $table->add_index('category_idx', XMLDB_INDEX_NOTUNIQUE, ['categoryid', 'active']);
        $table->add_index('dates_idx', XMLDB_INDEX_NOTUNIQUE, ['startdate', 'enddate']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_event ------------------------------------------------
        $table = new xmldb_table('gmk_wellness_event');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('summary', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, 'long', null, XMLDB_NOTNULL, null, null);
        $table->add_field('category', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'otro');
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('modality', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'presencial');
        $table->add_field('location', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('virtual_url', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('capacity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('requires_registration', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('allow_waitlist', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('registration_opens_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('registration_closes_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('organizer_name', XMLDB_TYPE_CHAR, '128', null, null, null, '');
        $table->add_field('organizer_email', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('cover_path', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);
        $table->add_index('startdate_idx', XMLDB_INDEX_NOTUNIQUE, ['startdate']);
        $table->add_index('category_idx', XMLDB_INDEX_NOTUNIQUE, ['category', 'active']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_event_files -------------------------------------
        $table = new xmldb_table('gmk_wellness_event_files');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('kind', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'handout');
        $table->add_field('label', XMLDB_TYPE_CHAR, '128', null, null, null, '');
        $table->add_field('url', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('file_path', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('mimetype', XMLDB_TYPE_CHAR, '64', null, null, null, '');
        $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sort', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('eventfk', XMLDB_KEY_FOREIGN, ['eventid'], 'gmk_wellness_event', ['id']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_registration -----------------------------------------
        $table = new xmldb_table('gmk_wellness_registration');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'confirmada');
        $table->add_field('modality', XMLDB_TYPE_CHAR, '16', null, null, null, '');
        $table->add_field('registered_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attended_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('cancelled_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('source', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'lxp');
        $table->add_field('registered_by', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('notes', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('eventfk', XMLDB_KEY_FOREIGN, ['eventid'], 'gmk_wellness_event', ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('event_user_uix', XMLDB_INDEX_UNIQUE, ['eventid', 'userid']);
        
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_dynamic_form -----------------------------------------
        $table = new xmldb_table('gmk_wellness_dynamic_form');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('schema_json', XMLDB_TYPE_TEXT, 'long', null, XMLDB_NOTNULL, null, null);
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('eventfk', XMLDB_KEY_FOREIGN, ['eventid'], 'gmk_wellness_event', ['id']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_form_resp --------------------------------
        $table = new xmldb_table('gmk_wellness_form_resp');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('formid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('answers_json', XMLDB_TYPE_TEXT, 'long', null, XMLDB_NOTNULL, null, null);
        $table->add_field('submitted_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('formfk', XMLDB_KEY_FOREIGN, ['formid'], 'gmk_wellness_dynamic_form', ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('form_user_uix', XMLDB_INDEX_UNIQUE, ['formid', 'userid']);
        $table->add_index('form_event_idx', XMLDB_INDEX_NOTUNIQUE, ['eventid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260901000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260902000) {

        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // WELLNESS MODULE â€” Phase 2: RF-03 (psychology appointments) + RF-09.3
        // 4 tables: psychology schedule slots, appointments, staff role
        // mapping (replaces the legacy wellness_psychology_email_dulce /
        // _jorge settings), staff audit log.
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        // gmk_wellness_psy_slot -----------------------------
        $table = new xmldb_table('gmk_wellness_psy_slot');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('psychologist_userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('weekday', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('starttime', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('endtime', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modality', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'presencial');
        $table->add_field('duration_minutes', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '45');
        $table->add_field('location', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('valid_from', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('valid_until', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('psychofk', XMLDB_KEY_FOREIGN, ['psychologist_userid'], 'user', ['id']);
        $table->add_index('psycho_active_idx', XMLDB_INDEX_NOTUNIQUE, ['psychologist_userid', 'active']);
        $table->add_index('psycho_weekday_idx', XMLDB_INDEX_NOTUNIQUE, ['weekday', 'starttime']);
        $table->add_index('psycho_validity_idx', XMLDB_INDEX_NOTUNIQUE, ['valid_from', 'valid_until']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_psy_appts -------------------------------
        $table = new xmldb_table('gmk_wellness_psy_appts');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('slotid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('psychologist_userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('appointment_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('duration_minutes', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '45');
        $table->add_field('modality', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'presencial');
        $table->add_field('reason', XMLDB_TYPE_TEXT, 'medium', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'pendiente');
        $table->add_field('status_changed_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('status_changed_by', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('attendees_notes', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('student_notified_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('staff_notified_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('cancelled_by', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('cancel_reason', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('slotfk', XMLDB_KEY_FOREIGN, ['slotid'], 'gmk_wellness_psy_slot', ['id']);
        $table->add_key('psychofk', XMLDB_KEY_FOREIGN, ['psychologist_userid'], 'user', ['id']);
        
        // psycho_when_idx removed: the FK psychofk on psychologist_userid
        // already creates a covering index; the redundant non-unique
        // index collided at xmldb time.
        $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('when_idx', XMLDB_INDEX_NOTUNIQUE, ['appointment_at']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // gmk_wellness_staff_role -------------------------------------------
        $table = new xmldb_table('gmk_wellness_staff_role');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('rolekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('role_label', XMLDB_TYPE_CHAR, '128', null, null, null, '');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('email_override', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('notify_on_request', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('notify_on_change', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('rolekey_uix', XMLDB_INDEX_UNIQUE, ['rolekey']);
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Migration: copy legacy wellness_psychology_email_dulce / _jorge
        // settings into gmk_wellness_staff_role. Idempotent: only acts if the
        // legacy setting exists AND no rolekey row is yet present.
        $legacyDulce = get_config('local_grupomakro_core', 'wellness_psychology_email_dulce');
        $legacyJorge = get_config('local_grupomakro_core', 'wellness_psychology_email_jorge');
        $now = time();
        if (!empty($legacyDulce) && !$DB->record_exists('gmk_wellness_staff_role', ['rolekey' => 'talento_humano'])) {
            $DB->insert_record('gmk_wellness_staff_role', (object)[
                'rolekey'           => 'talento_humano',
                'role_label'        => 'Dulce Jurado â€” Talento Humano',
                'userid'            => 0,
                'email_override'    => (string)$legacyDulce,
                'notify_on_request' => 1,
                'notify_on_change'  => 1,
                'active'            => 1,
                'usermodified'      => 2,
                'timecreated'       => $now,
                'timemodified'      => $now,
            ]);
        }
        if (!empty($legacyJorge) && !$DB->record_exists('gmk_wellness_staff_role', ['rolekey' => 'bienestar_jefe'])) {
            $DB->insert_record('gmk_wellness_staff_role', (object)[
                'rolekey'           => 'bienestar_jefe',
                'role_label'        => 'Jorge Oviedo â€” Bienestar Estudiantil',
                'userid'            => 0,
                'email_override'    => (string)$legacyJorge,
                'notify_on_request' => 1,
                'notify_on_change'  => 1,
                'active'            => 1,
                'usermodified'      => 2,
                'timecreated'       => $now,
                'timemodified'      => $now,
            ]);
        }

        // gmk_wellness_staff_audit ------------------------------------------
        $table = new xmldb_table('gmk_wellness_staff_audit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('rolekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('old_userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('new_userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('old_email', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('new_email', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('changed_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('changed_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('note', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('rolekey_at_idx', XMLDB_INDEX_NOTUNIQUE, ['rolekey', 'changed_at']);
        $table->add_index('changed_by_idx', XMLDB_INDEX_NOTUNIQUE, ['changed_by']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260902000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260903000) {

        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // WELLNESS MODULE â€” Phase 3: Carnet digital (RF-07, RF-09.4)
        // 1 table: gmk_wellness_carnet (digital ID card with QR token + photo).
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        $table = new xmldb_table('gmk_wellness_carnet');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('fullname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('documentnumber', XMLDB_TYPE_CHAR, '64', null, null, null, '');
        $table->add_field('learning_plan_name', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('admission_date', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('valid_from', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('valid_until', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'activo');
        $table->add_field('qr_token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('photo_path', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $table->add_field('issued_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('revoked_at', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // userfk FK removed: the user_uix UNIQUE already creates a
        // covering index on userid and enforces 1:1. The carnet is
        // optional and lazy-issued, so we do not need referential
        // integrity with {user} — orphaning the carnet when a user
        // is deleted is the desired behaviour.
        $table->add_index('user_uix', XMLDB_INDEX_UNIQUE, ['userid']);
        $table->add_index('qr_token_uix', XMLDB_INDEX_UNIQUE, ['qr_token']);
        $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status', 'valid_until']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260903000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260904000) {

        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // WELLNESS MODULE â€” Hotfixes from internal audit (36 findings F-01..F-36).
        // No schema changes: the install.xml of Phase 2 already used the
        // correct UNIQUE(rolekey) index after F-23. The changes live in the
        // managers, services.php, settings.php, lib.php, lang and the
        // user_login_handler.
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        // The schema index correction (F-23) is reflected both in install.xml
        // (for fresh installs) and here in the index definition above
        // (in case an existing site was on the old UNIQUE(rolekey,active)).
        // Since the index is UNIQUE not NOTUNIQUE, fresh installs get the
        // new one; sites that previously had the old one would need a
        // one-off backfill â€” that's out of scope for the hotfix.

        upgrade_plugin_savepoint(true, 20260904000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260905000) {

        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // WELLNESS MODULE â€” Hotfixes round 2 (8 findings, F-06 + N-01..N-05 + F-18 + F-32).
        // No schema changes; behavioural-only fixes that do not need an
        // upgrade step beyond the savepoint marker.
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        upgrade_plugin_savepoint(true, 20260905000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260906000) {

        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // WELLNESS MODULE â€” Hotfix from internal audit round 3.
        // Behavioural-only fix in wellness_staff_manager::upsert(): now
        // reads via get_role() so that re-activating a seeded-but-disabled
        // rolekey does not collide with UNIQUE(rolekey). No schema change.
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

        upgrade_plugin_savepoint(true, 20260906000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260907000) {

        // -------------------------------------------------------------------
        // WELLNESS - Imagen de portada para formularios dinamicos (RF-06).
        // Convenios y eventos ya tenian logo_path / cover_path; el formulario
        // no. Se guarda la URL absoluta de pluginfile para que la LXP (otro
        // dominio) la resuelva tal cual.
        // -------------------------------------------------------------------
        $table = new xmldb_table('gmk_wellness_dynamic_form');
        $field = new xmldb_field('cover_path', XMLDB_TYPE_CHAR, '255', null, null, null, '', 'schema_json');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 20260907000, 'local', 'grupomakro_core');
    }

    if ($oldversion < 20260908000) {

        // -------------------------------------------------------------------
        // WELLNESS FASE 4 - Evaluacion docente post-sesion (RF-08).
        // Decisiones de producto confirmadas con el cliente:
        //   * SIN ANONIMATO: se guarda y se muestra el userid del estudiante.
        //   * Disparador POR SESION: una evaluacion por (sesion, estudiante).
        // Filtros acordados: se excluyen las clases de modulo
        // (gmk_class.is_module = 1) y las sesiones de revalida.
        // -------------------------------------------------------------------
        $table = new xmldb_table('gmk_wellness_teacher_eval');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('classid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sessiondate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('corecourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('instructorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'enviada');
        $table->add_field('rating_overall', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rating_clarity', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rating_punctuality', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('comment', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('submitted_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        // Una sola fila por (sesion, estudiante): enviar o descartar ocupa el
        // hueco, de modo que el popup no vuelve a aparecer.
        $table->add_index('session_user_uix', XMLDB_INDEX_UNIQUE, ['sessionid', 'userid']);
        $table->add_index('class_status_idx', XMLDB_INDEX_NOTUNIQUE, ['classid', 'status']);
        $table->add_index('instructor_date_idx', XMLDB_INDEX_NOTUNIQUE, ['instructorid', 'sessiondate']);
        $table->add_index('user_status_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20260908000, 'local', 'grupomakro_core');
    }

    return true;
}

