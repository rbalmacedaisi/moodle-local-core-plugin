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
 * External function `local_grupomakro_execute_status_change`.
 *
 * Executes an aplazamiento or retiro from the LXP wizard: drops active
 * courses, flips local_learning_users + studentstatus, writes a
 * gmk_student_suspension row with origin=lxp and dispatches the same
 * effect to Odoo through the Express proxy (best-effort).
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

class status_change_execute extends external_api
{
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'userid'          => new external_value(PARAM_INT, 'Student user id.', VALUE_REQUIRED),
            'action_name'     => new external_value(PARAM_ALPHANEXT, 'aplazar | retirar', VALUE_REQUIRED),
            'reason'          => new external_value(PARAM_TEXT, 'Free-text reason (>= 10 chars).', VALUE_REQUIRED),
            'target_period_id' => new external_value(PARAM_INT, 'For aplazar only: gmk_academic_periods.id.', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $userid, string $action_name, string $reason, int $target_period_id = 0): array
    {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'           => $userid,
            'action_name'      => $action_name,
            'reason'           => $reason,
            'target_period_id' => $target_period_id,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manageacademicstatus', $context);

        $result = \local_grupomakro_status_change_manager::execute(
            (int)$params['userid'],
            $params['action_name'],
            $params['reason'],
            $params['target_period_id'] ?: null
        );

        return [
            'status'  => $result['status'] ?? 'error',
            'message' => $result['message'] ?? '',
            'data'    => $result['data'] ?? null,
        ];
    }

    public static function execute_returns(): external_single_structure
    {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success | error'),
            'message' => new external_value(PARAM_TEXT, 'Result message.'),
            'data'    => new external_value(PARAM_RAW, 'Structured result (userid, newstatus, suspension_id, courses_dropped, odoo_sync).', VALUE_OPTIONAL),
        ]);
    }
}