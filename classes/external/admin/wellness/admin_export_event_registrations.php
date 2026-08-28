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
 * Admin: export the registrations of an event as CSV. RF-09.2.
 * Returns the CSV body as a single string that the Vue side turns into
 * a Blob to trigger a browser download.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_event_manager.php');

class admin_export_event_registrations extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid' => new external_value(PARAM_INT, 'Event id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($eventid) {
        $params = self::validate_parameters(self::execute_parameters(), ['eventid' => $eventid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        try {
            $csv = \local_grupomakro_core\local\wellness_event_manager::export_registrations_csv(
                (int)$params['eventid']);
        } catch (\moodle_exception $e) {
            throw new Exception($e->getMessage());
        }
        return [
            'csv'     => $csv,
            'eventid' => (int)$params['eventid'],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'csv'     => new external_value(PARAM_RAW, 'CSV body'),
            'eventid' => new external_value(PARAM_INT, 'Event id'),
        ]);
    }
}
