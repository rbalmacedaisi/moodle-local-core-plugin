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
$plugin->version   = 20260805001;         // Revert homologation flow: WS local_grupomakro_revert_homologation clears the homologation fields on gmk_course_progre, resets status to Disponible and nulls Nota Final Integrada / course total grade_grades.
$plugin->version   = 20260805002;         // Homologation audit log: gmk_homologation_audit table + WS local_grupomakro_get_homologation_audit surfaces the chronological list of homologate/revert actions in the academic panel timeline dialog.
$plugin->version   = 20260806000;         // Per-session is_revalida flag: new column on attendance_sessions + index, auto-backfill for [Mon 06-Jul .. Sat 11-Jul 2026] marking the last session per class as revalida, filter applied to all attendance-grade SQL sites (course_grade_resolver, gmk_batch_weighted_grades, get_student_gradebook, get_student_course_pensum_activities, get_student_learning_plan_pensum, get_course_absences_detail, gmk_count_pending_attendance_sessions, gmk_mark_pending_sessions_as_absent, absence_helpers), ajax toggle endpoint local_grupomakro_toggle_session_revalida, dry-run + apply CLI scripts for the backfill.
$plugin->version   = 20260807000;         // PERFORMANCE: gmk_log() now no-ops unless GMK_DEBUG_LOG is defined; declared 8 new MUC caches (gmkclass_enriched, gmkinstructor, gmklearningplan, gmklearningperiod, gmkcoursecache, gmkclassroom, gmkbbbatrel, gmkattendancesession) and gmk_muc() helper. Rewrote list_classes() with bulk prefetch (subjects, instructors, plans, periods, courses, classrooms, customfields, participants) replacing N+1. Rewrote get_class_events() to bulk-prefetch attendance_sessions + gmk_bbb_attendance_relation + course_modules + user roles per event loop; new gmk_complete_class_event_information_fast / _bbb_fast / _generic_fast handlers drop-in compatible with the originals. Range clamp to 12 months. BUGFIX: student_get_active_classes() now filters gmk_course_progre by userid (was returning rows from every user in the plan).
$plugin->version   = 20260808000;         // Copy session feature: new WS local_grupomakro_check_copy_conflicts + local_grupomakro_copy_activity; gmk_copy_class_activity() in locallib.php creates up to 20 additional attendance_sessions + BBB + gmk_bbb_attendance_relation entries from a source session, extends enddate, updates assigned_dates. UI in ClassSchedule.js: teacher button + admin menu item, modal with dynamic list of date/time rows, conflict verification with force option.
$plugin->version   = 20260809000;         // Delete session feature: new WS local_grupomakro_delete_session + gmk_delete_class_activity() in locallib.php deletes the attendance_sessions row, its calendar event, the gmk_bbb_attendance_relation row, and (via course_delete_module) the BBB course_module + bigbluebuttonbn instance. Refuses when attendance_log records exist unless force=1; always writes a JSON backup to dataroot first. UI in ClassSchedule.js: admin menu item with confirmation modal that pre-checks log count and offers force option.
$plugin->requires = 2014051200;
$plugin->maturity = MATURITY_STABLE;
