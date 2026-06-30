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
$plugin->requires = 2014051200;
$plugin->maturity = MATURITY_STABLE;
