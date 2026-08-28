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
 * Cancel a wellness event registration (RF-02 / RF-09.2).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_registration_manager.php');

class cancel_registration extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid' => new external_value(PARAM_INT, 'Event id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($eventid) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['eventid' => $eventid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        return \local_grupomakro_core\local\wellness_registration_manager::cancel(
            (int)$params['eventid'], (int)$USER->id, true);
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'      => new external_value(PARAM_BOOL, 'True on success'),
            'already' => new external_value(PARAM_BOOL, 'True when the registration was already cancelled'),
            'error'   => new external_value(PARAM_TEXT, 'Error code'),
        ]);
    }
}
