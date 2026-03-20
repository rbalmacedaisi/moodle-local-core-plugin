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
        '📘 ' . get_string('class_management', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/classmanagement.php')
    );
    $classSchedulesPage =new admin_externalpage(
        'grupomakro_core_class_schedule',
        '🗓️ ' . get_string('class_schedules', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/schedules.php')
    );
    $availabilityPanelPage =new admin_externalpage(
        'grupomakro_core_availability_panel',
        '🧑‍🏫 ' . get_string('availability_panel', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/availabilitypanel.php')
    );
    $availabilityCalendarPage =new admin_externalpage(
        'grupomakro_core_availability_calendar',
        '📆 ' . get_string('availability_calendar', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/availability.php')
    );
    $schedulesPanelPage =new admin_externalpage(
        'grupomakro_core_schedules_panel',
        '🕒 ' . get_string('schedules_panel', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/schedulepanel.php')
    );
    $institutionManagementPage =new admin_externalpage(
        'grupomakro_core_institution_management',
        '🏫 ' . get_string('institution_management', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/institutionmanagement.php')
    );
    $academicDirectorPanelPage = new admin_externalpage(
        'grupomakro_core_academic_director_panel',
        '🎯 ' . get_string('academic_director_panel', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/academicpanel.php')
    );
    $teachersManagementPage = new admin_externalpage(
        'grupomakro_core_teachers_management',
        '👩‍🏫 ' . get_string('admin_teachers_management', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/teachers.php')
    );

    // -- IMPORTADORES MASIVOS --
    $importUsersPage = new admin_externalpage(
        'grupomakro_core_import_users',
        '👥 Importar Usuarios (Masivo)',
        new moodle_url('/local/grupomakro_core/pages/import_users.php')
    );
    $importGradesPage = new admin_externalpage(
        'grupomakro_core_import_grades',
        '📝 Importar Notas (Q10)',
        new moodle_url('/local/grupomakro_core/pages/import_grades.php')
    );
    $bulkDeletePage = new admin_externalpage(
        'grupomakro_core_bulk_delete_users',
        '🗑️ Eliminación Masiva',
        new moodle_url('/local/grupomakro_core/pages/bulk_delete_users.php')
    );
    $manageCoursesPage = new admin_externalpage(
        'grupomakro_core_manage_courses',
        '📂 Gestor de Cursos',
        new moodle_url('/local/grupomakro_core/pages/manage_courses.php')
    );
    $manageMeetingsPage = new admin_externalpage(
        'grupomakro_core_manage_meetings',
        '🎥 Gestor de Sesiones Virtuales',
        new moodle_url('/local/grupomakro_core/pages/manage_meetings.php')
    );
    $bypassFinancialPage = new admin_externalpage(
        'grupomakro_core_bypass_financial',
        '💳 Ignorar Estado Financiero en Login',
        new moodle_url('/local/grupomakro_core/pages/bypass_financial.php')
    );
    $gracePeriodPage = new admin_externalpage(
        'grupomakro_core_grace_period',
        '⏳ Periodo de Gracia en Primer Login',
        new moodle_url('/local/grupomakro_core/pages/grace_period.php')
    );
    $debugPublishStatusPage = new admin_externalpage(
        'grupomakro_core_debug_publish_status',
        '🧪 Debug Publicación Horarios',
        new moodle_url('/local/grupomakro_core/pages/debug_publish_status.php')
    );
    $fixOrphanedClassidPage = new admin_externalpage(
        'grupomakro_core_fix_orphaned_classid',
        '🔧 Fix: Clases Eliminadas (classid huérfano)',
        new moodle_url('/local/grupomakro_core/pages/fix_orphaned_classid.php')
    );
    $resetPeriodPublishPage = new admin_externalpage(
        'grupomakro_core_reset_period_publish',
        '🗑 Reset: Limpiar Publicación de Período',
        new moodle_url('/local/grupomakro_core/pages/reset_period_publish.php')
    );
    $debugFixDraftPage = new admin_externalpage(
        'grupomakro_core_debug_fix_draft',
        '🔍 Debug: Fix Draft & Grupos Huérfanos',
        new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php')
    );
    $debugPublishDraftPage = new admin_externalpage(
        'grupomakro_core_debug_publish_draft',
        'Debug: Draft Publish Inspector',
        new moodle_url('/local/grupomakro_core/pages/debug_publish_draft.php')
    );
    $overlapAnalyticsPage = new admin_externalpage(
        'grupomakro_core_overlap_analytics',
        'Analítica de Solapamientos',
        new moodle_url('/local/grupomakro_core/pages/overlap_analytics.php')
    );
    $debugOverlapStudentPage = new admin_externalpage(
        'grupomakro_core_debug_overlap_student',
        'Debug: Overlap Student Trace',
        new moodle_url('/local/grupomakro_core/pages/debug_overlap_student_trace.php')
    );
    $academicDemandGapsPage = new admin_externalpage(
        'grupomakro_core_academic_demand_gaps',
        'Brechas de Demanda Académica',
        new moodle_url('/local/grupomakro_core/pages/academic_demand_gaps.php')
    );
    $scheduleWeeklyViewPage = new admin_externalpage(
        'grupomakro_core_schedule_weekly_view',
        'Vista Semanal de Horarios',
        new moodle_url('/local/grupomakro_core/pages/schedule_weekly_view.php')
    );
    $activeStudentsByClassPage = new admin_externalpage(
        'grupomakro_core_active_students_by_class',
        get_string('active_students_by_class_page', 'local_grupomakro_core'),
        new moodle_url('/local/grupomakro_core/pages/active_students_by_class.php')
    );
    $debugBbbTeacherJoinPage = new admin_externalpage(
        'grupomakro_core_debug_bbb_teacher_join',
        'Debug BBB Join Docente',
        new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php')
    );
    $financialPlanningPage = new admin_externalpage(
        'grupomakro_core_financial_planning',
        '💰 Análisis Financiero Docente',
        new moodle_url('/local/grupomakro_core/pages/financial_planning.php')
    );
    $debugStudentActivityVisibilityPage = new admin_externalpage(
        'grupomakro_core_debug_student_activity_visibility',
        'Debug: Student Activity Visibility',
        new moodle_url('/local/grupomakro_core/pages/debug_student_activity_visibility.php')
    );
    $syncBbbRecordingsPage = new admin_externalpage(
        'grupomakro_core_sync_bbb_recordings',
        '🎙️ Sincronizar Grabaciones BBB',
        new moodle_url('/local/grupomakro_core/pages/sync_bbb_recordings.php')
    );
    $debugProgreApprovedOrphansPage = new admin_externalpage(
        'grupomakro_core_debug_progre_approved_orphans',
        '🩺 Inconsistencias Progreso: aprobado + huérfano',
        new moodle_url('/local/grupomakro_core/pages/debug_progre_approved_orphans.php')
    );
    $debugApprovedZeroGradePage = new admin_externalpage(
        'grupomakro_core_debug_approved_zero_grade',
        'Debug: Aprobada con nota 0',
        new moodle_url('/local/grupomakro_core/pages/debug_approved_zero_grade.php')
    );
    $ADMIN->add('grupomakrocore_plugin', $classManagementPage);
    $ADMIN->add('grupomakrocore_plugin', $classSchedulesPage);
    $ADMIN->add('grupomakrocore_plugin', $availabilityPanelPage);
    $ADMIN->add('grupomakrocore_plugin', $availabilityCalendarPage);
    $ADMIN->add('grupomakrocore_plugin', $schedulesPanelPage);
    $ADMIN->add('grupomakrocore_plugin', $institutionManagementPage);
    $ADMIN->add('grupomakrocore_plugin', $academicDirectorPanelPage);
    $ADMIN->add('grupomakrocore_plugin', $teachersManagementPage);
    $ADMIN->add('grupomakrocore_plugin', $importUsersPage);
    $ADMIN->add('grupomakrocore_plugin', $importGradesPage);
    $ADMIN->add('grupomakrocore_plugin', $bulkDeletePage);
    $ADMIN->add('grupomakrocore_plugin', $manageCoursesPage);
    $ADMIN->add('grupomakrocore_plugin', $manageMeetingsPage);
    $ADMIN->add('grupomakrocore_plugin', $bypassFinancialPage);
    $ADMIN->add('grupomakrocore_plugin', $gracePeriodPage);
    $ADMIN->add('grupomakrocore_plugin', $debugPublishStatusPage);
    $ADMIN->add('grupomakrocore_plugin', $fixOrphanedClassidPage);
    $ADMIN->add('grupomakrocore_plugin', $resetPeriodPublishPage);
    $ADMIN->add('grupomakrocore_plugin', $debugFixDraftPage);
    $ADMIN->add('grupomakrocore_plugin', $debugPublishDraftPage);
    $ADMIN->add('grupomakrocore_plugin', $overlapAnalyticsPage);
    $ADMIN->add('grupomakrocore_plugin', $debugOverlapStudentPage);
    $ADMIN->add('grupomakrocore_plugin', $academicDemandGapsPage);
    $ADMIN->add('grupomakrocore_plugin', $scheduleWeeklyViewPage);
    $ADMIN->add('grupomakrocore_plugin', $activeStudentsByClassPage);
    $ADMIN->add('grupomakrocore_plugin', $financialPlanningPage);
    $ADMIN->add('grupomakrocore_plugin', $debugStudentActivityVisibilityPage);
    $ADMIN->add('grupomakrocore_plugin', $debugBbbTeacherJoinPage);
    $ADMIN->add('grupomakrocore_plugin', $syncBbbRecordingsPage);
    $ADMIN->add('grupomakrocore_plugin', $debugProgreApprovedOrphansPage);
    $ADMIN->add('grupomakrocore_plugin', $debugApprovedZeroGradePage);

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

        // Student app URL used by QR attendance bridge redirections.
        $settingspage->add(new admin_setting_configtext(
            'local_grupomakro_core/student_app_url',
            'URL base de la interfaz de estudiantes (LXP)',
            'URL base para redirigir al estudiante despues de escanear QR de asistencia.',
            'https://students.isi.edu.pa',
            PARAM_URL
        ));
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

        // URL del servidor proxy Express (para bypass financiero)
        $settingspage->add(new admin_setting_configtext(
            'local_grupomakro_core/odoo_proxy_url',
            'URL del Proxy Odoo (Express Server)',
            'URL base del servidor Express que gestiona la validación financiera. Ej: https://lms.isi.edu.pa:4000',
            'https://lms.isi.edu.pa:4000',
            PARAM_URL
        ));

        // Secret de administración del proxy
        $settingspage->add(new admin_setting_configpasswordunmask(
            'local_grupomakro_core/odoo_proxy_admin_secret',
            'Secreto Admin del Proxy (Bypass Financiero)',
            'Token secreto para autenticar solicitudes de administración al proxy Express. Debe coincidir con ADMIN_SECRET en server.js.',
            'gmk_admin_bypass_2026',
            PARAM_TEXT
        ));

        // Habilitar/deshabilitar periodo de gracia en primer login
        $settingspage->add(new admin_setting_configcheckbox(
            'local_grupomakro_core/grace_period_enabled',
            'Periodo de gracia en primer login',
            'Si está activo, los estudiantes que inicien sesión por primera vez tendrán acceso hasta el final del mes sin restricciones financieras.',
            0
        ));

        // Token compartido para la consulta server-to-server (Express -> Moodle)
        $settingspage->add(new admin_setting_configpasswordunmask(
            'local_grupomakro_core/grace_period_token',
            'Token de consulta de periodo de gracia',
            'Token secreto compartido entre el Express Server y Moodle. Debe coincidir con MOODLE_GRACE_TOKEN en server.js.',
            'gmk_grace_check_2026',
            PARAM_TEXT
        ));

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
