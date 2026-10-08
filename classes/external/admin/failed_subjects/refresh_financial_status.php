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
 * Refresh a single student's financial status against Odoo via the
 * Express proxy. Same flow as the "Refrescar financiero" button on
 * the academicpanel student table.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\failed_subjects;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/failed_subjects_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_system;

class refresh_financial_status extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_failed_subjects_report', $context);

        return \local_grupomakro_core\local\failed_subjects_manager::refresh_financial_status($userid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'            => new external_value(PARAM_RAW, 'ok|error'),
            'updated'           => new external_value(PARAM_INT, 'Number of users updated (1 on success)', VALUE_OPTIONAL),
            'financial_status'  => new external_value(PARAM_RAW, 'New financial status code', VALUE_OPTIONAL),
            'financial_label'   => new external_value(PARAM_RAW, 'New financial status label', VALUE_OPTIONAL),
            'financial_reason'  => new external_value(PARAM_RAW, 'New financial reason', VALUE_OPTIONAL),
            'message'           => new external_value(PARAM_RAW, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
