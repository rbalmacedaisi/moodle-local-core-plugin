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
 * Version details.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$plugin->component = "local_grupomakro_core";
$plugin->version   = 20260701007;         // overdue_grace_days admin setting + local_grupomakro_get_overdue_grace_days AJAX action for server-to-server Express lookup; configurable from financial settings page, default 3, consumed by Express rest_express/server.js instead of hard-coded constant.
$plugin->version   = 20260701008;         // Module enrollment gated by Odoo invoice (gmk_module_invoice_requests) — mirrors revalidation flow with MODULE_REQ:<id> ref, payment-gated enroll_module, refresh-payment action, 30-day expiry cron, LXP pending modules section.
$plugin->version   = 20260801002;         // Academic Director Revalidations Dashboard (pages/revalidations_director.php): 4 new WS (list/refresh_one/refresh_bulk/get_classes/get_eligible/create_extemp), new capabilities view_revalidations_dashboard + create_extemporaneous_revalidations, academic-calendar window gate on teacher schedule action, gmk_revalidations.extemporaneous* audit columns, extemporaneous creation by director bypassing the window but enforcing eligibility (60.0–70.9 sin horas prácticas).
$plugin->version   = 20260802000;         // Failed Subjects Report (pages/failed_subjects_report.php + classes/local/failed_subjects_manager.php + 5 new WS in classes/external/admin/failed_subjects/): students with status 5/7 in gmk_course_progre matched against gmk_class projected in a chosen period by jornada, quota, and availability; contact lookup via Odoo Express proxy with TTL cache; force-over-quota enrolment with audit log in gmk_class_absence_history. Two new capabilities view_failed_subjects_report and enrol_from_failed_subjects_report.
$plugin->version   = 20260803000;         // Admin broadcast messages (gmk_admin_message + gmk_admin_message_ack + gmk_admin_message_user): info/warning notices to students with career/group audience targeting, optional acknowledgement checkbox and configurable ack label, per-career acknowledgement stats, capability manageannouncements, dedicated admin page (pages/announcements.php) and Vue component (js/components/announcements.js). LXP store/components/announcements with priority-based precedence over AbsenceAlertSystem.
$plugin->version   = 20260804000;         // Failed Subjects Report enhancements: grade calculation via gradebookWeightedTotal matching grademodal.js exactly; quota count via get_class_participants (matches list_classes); new student_status filter (activo/aplazado/retirado/...); new "Ver grupos" picker showing all available gmk_class for a (course, period) with jornada/cupo match; new "Refrescar estado financiero" button calling local_grupomakro_refresh_financial_status_failed_subjects; new WS refresh_financial_status.php.
$plugin->version   = 20260805000;         // Individual course certificates: new gmk_diploma_eligible_course table (admin-managed per-course eligibility), nullable courseid column on gmk_diploma_generation, manager methods to list/toggle courses, count eligible students per course, list students for a course, compute eligibility, generate PDF per (student, course) pair; new "Por curso" tab in diplomageneration.php with the same Solo pendientes toggle UX as the existing "Estudiantes elegibles" section; admin sub-section in diplomatemplates.php to toggle which courses are eligible.
$plugin->requires = 2014051200;
$plugin->maturity = MATURITY_STABLE;
