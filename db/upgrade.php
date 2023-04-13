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


    return true;
}
