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
 * Access definitions for the local_grupomakro_core plugin.
 *
 * @package     local_grupomakro_core
 * @category    string
 * @copyright   2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/grupomakro_core:seeallorders' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(),
    ),
    'local/grupomakro_core:manageletters' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(),
    ),
    'local/grupomakro_core:managerequests' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(),
    ),
    'local/grupomakro_core:viewallletterrequests' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(),
    ),
    'local/grupomakro_core:viewabsencedashboard' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(),
    ),
    'local/grupomakro_core:manage_classes' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:managediplomas' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:viewdiplomas' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:verifydiplomas' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'guest' => CAP_ALLOW,
            'user' => CAP_ALLOW,
        ),
    ),
    // Director-level dashboard: lists revalidations across every class in the
    // institute, so it stays with management roles. Teachers manage their own
    // revalidations from the grades grid of their class instead.
    'local/grupomakro_core:view_revalidations_dashboard' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:create_extemporaneous_revalidations' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:view_failed_subjects_report' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:enrol_from_failed_subjects_report' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:manageannouncements' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:viewannouncements' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:view_movement_audit' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:annul_movement' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:manageacademicstatus' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:editsupportteacher' => array(
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    // ── Wellness module (RF-01..06, RF-09.1, RF-09.2) ────────────────────
    'local/grupomakro_core:view_wellness' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'user' => CAP_ALLOW,
        ),
    ),
    'local/grupomakro_core:manage_wellness' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),
    // ── Wellness psychology (RF-03, RF-09.3) ───────────────────────────────
    // Manager + custom 'psicologo' archetype (created via admin/roles).
    // Holders can manage the agenda and the staff roster.
    'local/grupomakro_core:manage_psychology_appointments' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),

    // ── Credit report (informe de créditos) ────────────────────────────────
    // Required by pages/credit_report.php. Default to 'manager' so existing
    // siteadmins keep their level of access; per-role bundle for the
    // gmk_* operational roles will be assigned in a later upgrade.
    'local/grupomakro_core:view_credit_report' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    ),

    // ── WORKFLOW MATRIX PR2: capabilities for the gmk_* operational roles ──
    // Added here so create_roles()/assign_capabilities_to_internal_roles()
    // (db/upgradelib.php) can hand each one to its owning role. All are
    // 'manager' archetype by retrocompatibility: siteadmins keep the same
    // level of access they had under moodle/site:config, and the role-by-role
    // bundle is applied by PR2. PR3+ then gates the pages with these caps.
    'local/grupomakro_core:manage_users' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_courses' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_schedules' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_teachers' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_teacher_availability' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_institutions' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_institutional_contracts' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_meetings' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_modules' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_orders' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_academic_calendar' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_academic_planning' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_student_timeline' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_financial_config' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_financial_webhooks' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:manage_debug' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:import_users' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:bulk_enroll' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:bulk_attendance_actions' => array(
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:export_students' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_academic_panel' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_classmanagement' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_student_timeline' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_student_population' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_active_students_by_class' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_attendance_pdf' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_overlap_analytics' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_academic_demand_gaps' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_log' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_financial_health' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_financial_planning' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/grupomakro_core:view_grade_report' => array(
        'captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
);
