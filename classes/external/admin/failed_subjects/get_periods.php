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
 * Returns the list of academic periods (gmk_academic_periods) so the
 * admin can pick the period to analyze in the failed-subjects report.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\failed_subjects;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

class get_periods extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_failed_subjects_report', $context);

        $records = $DB->get_records('gmk_academic_periods', null, 'startdate DESC');
        $out = [];
        foreach ($records as $r) {
            $out[] = [
                'id'        => (int)$r->id,
                'name'      => (string)$r->name,
                'startdate' => (int)$r->startdate,
                'enddate'   => (int)$r->enddate,
                'status'    => (int)$r->status,
            ];
        }
        return $out;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'        => new external_value(PARAM_INT, 'Period id'),
                'name'      => new external_value(PARAM_TEXT, 'Period name'),
                'startdate' => new external_value(PARAM_INT, 'Start date (timestamp)'),
                'enddate'   => new external_value(PARAM_INT, 'End date (timestamp)'),
                'status'    => new external_value(PARAM_INT, 'Status (1=active, 0=closed)'),
            ])
        );
    }
}
