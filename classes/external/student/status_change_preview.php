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

/**
 * External function `local_grupomakro_get_status_change_preview`.
 *
 * Builds the preview payload for the LXP status change wizard (aplazar /
 * retirar / reactivar). Combines Moodle-side data with a best-effort fetch
 * of pending invoices from Odoo through the Express proxy.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/status_change_manager.php');

class status_change_preview extends external_api
{
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student user id.', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $userid): array
    {
        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manageacademicstatus', $context);

        $preview = \local_grupomakro_status_change_manager::build_preview((int)$params['userid']);
        return $preview;
    }

    public static function execute_returns(): external_single_structure
    {
        return new external_single_structure([
            'userid'         => new external_value(PARAM_INT, 'Student user id.'),
            'username'       => new external_value(PARAM_TEXT, 'Moodle username.'),
            'fullname'       => new external_value(PARAM_TEXT, 'Student fullname.'),
            'vat'            => new external_value(PARAM_TEXT, 'Student VAT (cedula).', VALUE_OPTIONAL),
            'email'          => new external_value(PARAM_TEXT, 'Student email.', VALUE_OPTIONAL),
            'studentstatus'  => new external_value(PARAM_TEXT, 'Institutional status from profile field.', VALUE_OPTIONAL),
            'academicstatus' => new external_value(PARAM_TEXT, 'Worst academic status across plans.'),
            'isreactivation' => new external_value(PARAM_BOOL, 'True if student is retirado/aplazado/suspendido/desertor.'),
            'carrers'        => new external_value(PARAM_RAW, 'Per-plan rows including active courses.'),
            'pending_invoices' => new external_value(PARAM_RAW, 'List of pending invoices fetched from Odoo (best-effort).'),
            'pending_invoices_unavailable' => new external_value(PARAM_BOOL, 'True if the Odoo proxy could not be reached.'),
            'target_periods' => new external_value(PARAM_RAW, 'List of gmk_academic_periods available as targets.'),
        ]);
    }
}