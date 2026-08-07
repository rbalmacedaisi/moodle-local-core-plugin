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
 * External function `local_grupomakro_get_status_change_history`.
 *
 * Returns the chronological list of postponement/withdrawal/renovation rows
 * from gmk_student_suspension for a user, formatted to feed the v-timeline
 * in the academic panel grade modal.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/status_change_manager.php');

class status_change_history extends external_api
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

        $rows = \local_grupomakro_status_change_manager::get_history((int)$params['userid']);
        return $rows;
    }

    public static function execute_returns(): external_multiple_structure
    {
        return new external_multiple_structure(
            new external_single_structure([
                'id'         => new external_value(PARAM_INT, 'Row id.'),
                'status'     => new external_value(PARAM_TEXT, 'aplazo | retiro | renovacion.'),
                'origin'     => new external_value(PARAM_TEXT, 'lxp | odoo | cron.'),
                'reason'     => new external_value(PARAM_TEXT, 'Free-text reason.', VALUE_OPTIONAL),
                'target_period_id'   => new external_value(PARAM_INT, 'Target period id.', VALUE_OPTIONAL),
                'target_period_name' => new external_value(PARAM_TEXT, 'Target period name.', VALUE_OPTIONAL),
                'active_courses_dropped' => new external_value(PARAM_RAW, 'JSON list of corecourseids.', VALUE_OPTIONAL),
                'details'    => new external_value(PARAM_RAW, 'Structured details JSON.', VALUE_OPTIONAL),
                'actor'      => new external_single_structure([
                    'id'       => new external_value(PARAM_INT, 'Actor user id.'),
                    'username' => new external_value(PARAM_TEXT, 'Actor username.', VALUE_OPTIONAL),
                    'fullname' => new external_value(PARAM_TEXT, 'Actor fullname.', VALUE_OPTIONAL),
                ]),
                'timecreated' => new external_value(PARAM_INT, 'Unix timestamp.'),
            ])
        );
    }
}