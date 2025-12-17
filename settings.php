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
 * Settings page for the local_grupomakro_core plugin.
 *
 * @package    package_subpackage
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('courses', new admin_category('grupomakrocore_plugin', new lang_string('admin_category_label', 'local_grupomakro_core')));
    $classManagementPage =new admin_externalpage(
        'grupomakro_core_class_management',
        get_string('class_management', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/classmanagement.php')
    );
    $classSchedulesPage =new admin_externalpage(
        'grupomakro_core_class_schedule',
        get_string('class_schedules', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/schedules.php')
    );
    $availabilityPanelPage =new admin_externalpage(
        'grupomakro_core_availability_panel',
        get_string('availability_panel', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/availabilitypanel.php')
    );
    $availabilityCalendarPage =new admin_externalpage(
        'grupomakro_core_availability_calendar',
        get_string('availability_calendar', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/availability.php')
    );
    $schedulesPanelPage =new admin_externalpage(
        'grupomakro_core_schedules_panel',
        get_string('schedules_panel', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/schedulepanel.php')
    );
    $institutionManagementPage =new admin_externalpage(
        'grupomakro_core_institution_management',
        get_string('institution_management', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/institutionmanagement.php')
    );
    $academicDirectorPanelPage = new admin_externalpage(
        'grupomakro_core_academic_director_panel',
        get_string('academic_director_panel', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/academicpanel.php')
    );
    $ADMIN->add('grupomakrocore_plugin', $classManagementPage);
    $ADMIN->add('grupomakrocore_plugin', $classSchedulesPage);
    $ADMIN->add('grupomakrocore_plugin', $availabilityPanelPage);
    $ADMIN->add('grupomakrocore_plugin', $availabilityCalendarPage);
    $ADMIN->add('grupomakrocore_plugin', $schedulesPanelPage);
    $ADMIN->add('grupomakrocore_plugin', $institutionManagementPage);
    $ADMIN->add('grupomakrocore_plugin', $academicDirectorPanelPage);
    
    $ADMIN->add('localplugins', new admin_category('grupomakrocore', new lang_string('pluginname', 'local_grupomakro_core')));
    /********
     * Settings page: General Settings.
     */
    // Let's add a settings page called "general_settingspage" to the "localplugins" category.
    $settingspage = new admin_settingpage('general_settingspage', new lang_string('general_settingspage', 'local_grupomakro_core'));

    if ($ADMIN->fulltree) {
    
        // Add a setting to indicate the inactivity period.
        $settingspage->add(new admin_setting_configtext(
            'local_grupomakro_core/inactiveafter_x_hours',
            new lang_string('inactiveafter_x_hours', 'local_grupomakro_core'),
            new lang_string('inactiveafter_x_hours_desc', 'local_grupomakro_core'),
            48,
            PARAM_INT)
        );
    }

    // Add the page to the settings tree.
    $ADMIN->add('grupomakrocore', $settingspage);

    /**
     * End of settings page: General Settings.
     */

    /********
     * Settings page: Email Templates.
     */
    // Let's add a settings page called "emailtemplates_settingspage" to the "localplugins" category.
    $settingspage = new admin_settingpage('emailtemplates_settingspage', new lang_string('emailtemplates_settingspage', 'local_grupomakro_core'));

    if ($ADMIN->fulltree) {
    
        // Add the "welcomemessage" setting, which is an html editor.
        $settingspage->add(new admin_setting_confightmleditor(
            'local_grupomakro_core/emailtemplates_welcomemessage_student',
            new lang_string('emailtemplates_welcomemessage_student', 'local_grupomakro_core'),
            new lang_string('emailtemplates_welcomemessage_student_desc', 'local_grupomakro_core'),
            new lang_string('emailtemplates_welcomemessage_student_default', 'local_grupomakro_core'),
            PARAM_RAW
        ));

        // Add the "welcomemessage" setting, which is an html editor.
        $settingspage->add(new admin_setting_confightmleditor(
            'local_grupomakro_core/emailtemplates_welcomemessage_caregiver',
            new lang_string('emailtemplates_welcomemessage_caregiver', 'local_grupomakro_core'),
            new lang_string('emailtemplates_welcomemessage_caregiver_desc', 'local_grupomakro_core'),
            new lang_string('emailtemplates_welcomemessage_caregiver_default', 'local_grupomakro_core'),
            PARAM_RAW
        ));
    }

    // Add the page to the settings tree.
    $ADMIN->add('grupomakrocore', $settingspage);

    /**
     * End of settings page: Email Templates.
     */

    /********
     * Settings page: Financial settings.
     */
    // Let's add a settings page called "financial_settingspage" to the "localplugins" category.
    $settingspage = new admin_settingpage('financial_settingspage', new lang_string('financial_settingspage', 'local_grupomakro_core'));

    if ($ADMIN->fulltree) {
    
        // Add the "tuitionfee" setting, which is an text field.
        $settingspage->add(new admin_setting_configtext(
            'local_grupomakro_core/tuitionfee',
            new lang_string('tuitionfee', 'local_grupomakro_core'),
            new lang_string('tuitionfee_desc', 'local_grupomakro_core'),
            '',
            PARAM_TEXT
        ));

        // Add the "tuitionfee_discount" setting, which is an text field.
        $settingspage->add(new admin_setting_configtext(
            'local_grupomakro_core/tuitionfee_discount',
            new lang_string('tuitionfee_discount', 'local_grupomakro_core'),
            new lang_string('tuitionfee_discount_desc', 'local_grupomakro_core'),
            '',
            PARAM_TEXT
        ));

        // Add the "currency" setting, which is an dropdown.
        $settingspage->add(new admin_setting_configselect(
            'local_grupomakro_core/currency',
            new lang_string('currency', 'local_grupomakro_core'),
            new lang_string('currency_desc', 'local_grupomakro_core'),
            '840',
            array(
                '840' => new lang_string('USD', 'local_grupomakro_core'),
                '170' => new lang_string('COP', 'local_grupomakro_core'),
                '484' => new lang_string('MXN', 'local_grupomakro_core'),
                '604' => new lang_string('PEN', 'local_grupomakro_core'),
            )
        ));

        // Add a setting for the decimal separator.
        $settingspage->add(new admin_setting_configselect(
            'local_grupomakro_core/decsep',
            new lang_string('decsep', 'local_grupomakro_core'),
            '',
            '.',
            array(
                '.' => new lang_string('decsep_dot', 'local_grupomakro_core'),
                ',' => new lang_string('decsep_comma', 'local_grupomakro_core'),
            )
        ));

        // Add a setting for the thousands separator.
        $settingspage->add(new admin_setting_configselect(
            'local_grupomakro_core/thousandssep',
            new lang_string('thousandssep', 'local_grupomakro_core'),
            '',
            ',',
            array(
                ',' => new lang_string('thousandssep_comma', 'local_grupomakro_core'),
                '.' => new lang_string('thousandssep_dot', 'local_grupomakro_core'),
                ' ' => new lang_string('thousandssep_space', 'local_grupomakro_core'),
                '' => new lang_string('thousandssep_none', 'local_grupomakro_core'),
            )
        ));
    }

    // Add the page to the settings tree.
    $ADMIN->add('grupomakrocore', $settingspage);

    /**
     * End of settings page: Email Templates.
     */
}
