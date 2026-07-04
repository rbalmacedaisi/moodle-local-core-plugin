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
$plugin->requires = 2014051200;
$plugin->maturity = MATURITY_STABLE;
