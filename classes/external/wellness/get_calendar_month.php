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
 * Public calendar feed: returns the events that intersect the given month,
 * with start/end clipped to the month bounds so the LXP can render them as
 * multi-day bars. RF-05.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_event_manager.php');

class get_calendar_month extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'year'  => new external_value(PARAM_INT, '4-digit year', VALUE_REQUIRED),
            'month' => new external_value(PARAM_INT, '1-12 month', VALUE_REQUIRED),
        ]);
    }

    public static function execute($year, $month) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'year' => $year, 'month' => $month,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $year  = max(1970, (int)$params['year']);
        $month = max(1, min(12, (int)$params['month']));
        $monthstart = mktime(0, 0, 0, $month, 1, $year);
        $monthend   = mktime(23, 59, 59, $month + 1, 0, $year);

        $rows = \local_grupomakro_core\local\wellness_event_manager::list_for_students(
            '', '', $monthstart, $monthend, true);

        return [
            'year'  => $year,
            'month' => $month,
            'monthstart' => $monthstart,
            'monthend'   => $monthend,
            'events' => array_values(array_map([get_events::class, 'cast_event'], $rows)),
        ];
    }

    public static function execute_returns() {
        $event = new external_single_structure([
            'id'                     => new external_value(PARAM_INT,  'Event id'),
            'title'                  => new external_value(PARAM_TEXT, 'Title'),
            'summary'                => new external_value(PARAM_TEXT, 'Teaser'),
            'description'            => new external_value(PARAM_RAW,  'Body'),
            'category'               => new external_value(PARAM_ALPHA,'deportivo|feria|taller|charla|otro'),
            'startdate'              => new external_value(PARAM_INT,  'Unix ts'),
            'enddate'                => new external_value(PARAM_INT,  'Unix ts'),
            'modality'               => new external_value(PARAM_ALPHA,'presencial|virtual|mixto'),
            'location'               => new external_value(PARAM_TEXT, 'Location'),
            'virtual_url'            => new external_value(PARAM_TEXT, 'Virtual room URL'),
            'capacity'               => new external_value(PARAM_INT,  '0 = unlimited'),
            'requires_registration'  => new external_value(PARAM_INT,  '0/1'),
            'allow_waitlist'         => new external_value(PARAM_INT,  '0/1'),
            'registration_opens_at'  => new external_value(PARAM_INT,  'Unix ts'),
            'registration_closes_at' => new external_value(PARAM_INT,  'Unix ts'),
            'organizer_name'         => new external_value(PARAM_TEXT, 'Organizer'),
            'organizer_email'        => new external_value(PARAM_TEXT, 'Organizer email'),
            'cover_path'             => new external_value(PARAM_TEXT, 'Cover image'),
            'registration_open'      => new external_value(PARAM_BOOL, 'Computed'),
            'event_started'          => new external_value(PARAM_BOOL, 'Computed'),
            'event_ended'            => new external_value(PARAM_BOOL, 'Computed'),
        ]);
        return new external_single_structure([
            'year'        => new external_value(PARAM_INT, 'Year echoed back'),
            'month'       => new external_value(PARAM_INT, 'Month echoed back'),
            'monthstart'  => new external_value(PARAM_INT, 'First-second of the month'),
            'monthend'    => new external_value(PARAM_INT, 'Last-second of the month'),
            'events'      => new external_multiple_structure($event, 'Events that intersect the month'),
        ]);
    }
}
