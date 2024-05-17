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
 * External calendar API
 *
 * @package    core_calendar
 * @category   external
 * @copyright  2012 Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.5
 */

namespace local_grupomakro_core\external\event;

use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;


defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class get_calendar_events extends external_api
{

    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters(
            [
                'userId' => new external_value(PARAM_INT, 'Id of the user',  VALUE_DEFAULT, null),
                'initDate' => new external_value(PARAM_TEXT, 'init date to look for the events',  VALUE_DEFAULT, null),
                'endDate' => new external_value(PARAM_TEXT, 'Id of the user',  VALUE_DEFAULT, null)
            ]
        );
    }

    /**
     * Get data for the monthly calendar view.
     *7
     * @param int $year The year to be shown
     * @return  array
     */
    public static function execute($userId = null, $initDate = null, $endDate = null)
    {

        // Parameter validation.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userId' => $userId,
            'initDate' => $initDate,
            'endDate' => $endDate
        ]);

        try {
            $eventDaysFiltered = get_class_events($params['userId'], $params['initDate'], $params['endDate']);

            return ['events' => json_encode(array_values($eventDaysFiltered))];
        } catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, '1 or -1 if success/error', VALUE_DEFAULT, 1),
                'events' => new external_value(PARAM_RAW, 'Events for the month', VALUE_DEFAULT, null),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.', VALUE_DEFAULT, 'ok'),
            )
        );
    }
}
