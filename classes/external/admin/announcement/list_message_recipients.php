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
 * External (admin): flat list of recipients of one broadcast, with their
 * acknowledgement state. Used by the admin drawer / popup in the dashboard.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\announcement;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

class list_message_recipients extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'messageid' => new external_value(PARAM_INT, 'Broadcast id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($messageid) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['messageid' => $messageid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:viewannouncements', $context);

        if (!$DB->record_exists('gmk_admin_message', ['id' => $params['messageid']])) {
            throw new Exception('message_not_found');
        }

        $rows = \local_grupomakro_core\local\announcement_manager::list_recipients((int)$params['messageid']);
        return [
            'recipients' => $rows,
            'count'      => count($rows),
        ];
    }

    public static function execute_returns() {
        $rowstructure = new external_single_structure([
            'userid'           => new external_value(PARAM_INT,  'Moodile user id'),
            'name'             => new external_value(PARAM_TEXT, 'Student full name'),
            'email'            => new external_value(PARAM_TEXT, 'Student email'),
            'careerid'         => new external_value(PARAM_INT,  'Snapshot career id'),
            'careername'       => new external_value(PARAM_TEXT, 'Snapshot career name'),
            'acked'            => new external_value(PARAM_BOOL, 'True if the user already acknowledged'),
            'timeacknowledged' => new external_value(PARAM_INT,  'Unix ts of the acknowledgement'),
        ]);

        return new external_single_structure([
            'recipients' => new external_multiple_structure($rowstructure, 'Per-recipient rows'),
            'count'      => new external_value(PARAM_INT, 'Total recipient count'),
        ]);
    }
}
