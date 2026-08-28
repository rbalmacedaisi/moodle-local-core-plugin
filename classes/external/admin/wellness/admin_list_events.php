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
 * Admin: list every wellness event + categories. RF-09.2.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_event_manager.php');

class admin_list_events extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $rows = \local_grupomakro_core\local\wellness_event_manager::list_for_admin();
        return [
            'events' => array_values(array_map(function ($r) {
                return [
                    'id'                     => (int)$r->id,
                    'title'                  => (string)$r->title,
                    'summary'                => (string)$r->summary,
                    'category'               => (string)$r->category,
                    'startdate'              => (int)$r->startdate,
                    'enddate'                => (int)$r->enddate,
                    'modality'               => (string)$r->modality,
                    'location'               => (string)$r->location,
                    'virtual_url'            => (string)$r->virtual_url,
                    'capacity'               => (int)$r->capacity,
                    'requires_registration'  => (int)$r->requires_registration,
                    'allow_waitlist'         => (int)$r->allow_waitlist,
                    'registration_opens_at'  => (int)$r->registration_opens_at,
                    'registration_closes_at' => (int)$r->registration_closes_at,
                    'organizer_name'         => (string)$r->organizer_name,
                    'organizer_email'        => (string)$r->organizer_email,
                    'cover_path'             => (string)$r->cover_path,
                    'active'                 => (int)$r->active,
                    'registered_count'       => (int)$r->registered_count,
                    'timecreated'            => (int)$r->timecreated,
                    'timemodified'           => (int)$r->timemodified,
                ];
            }, $rows)),
        ];
    }

    public static function execute_returns() {
        $event = new external_single_structure([
            'id'                     => new external_value(PARAM_INT,  'Event id'),
            'title'                  => new external_value(PARAM_TEXT, 'Title'),
            'summary'                => new external_value(PARAM_TEXT, 'Teaser'),
            'category'               => new external_value(PARAM_TEXT, 'Category'),
            'startdate'              => new external_value(PARAM_INT,  'Unix ts'),
            'enddate'                => new external_value(PARAM_INT,  'Unix ts'),
            'modality'               => new external_value(PARAM_TEXT, 'Modality'),
            'location'               => new external_value(PARAM_TEXT, 'Location'),
            'virtual_url'            => new external_value(PARAM_TEXT, 'Virtual URL'),
            'capacity'               => new external_value(PARAM_INT,  'Capacity (0 = unlimited)'),
            'requires_registration'  => new external_value(PARAM_INT,  '0/1'),
            'allow_waitlist'         => new external_value(PARAM_INT,  '0/1'),
            'registration_opens_at'  => new external_value(PARAM_INT,  'Unix ts'),
            'registration_closes_at' => new external_value(PARAM_INT,  'Unix ts'),
            'organizer_name'         => new external_value(PARAM_TEXT, 'Organizer name'),
            'organizer_email'        => new external_value(PARAM_TEXT, 'Organizer email'),
            'cover_path'             => new external_value(PARAM_TEXT, 'Cover path'),
            'active'                 => new external_value(PARAM_INT,  '0/1'),
            'registered_count'       => new external_value(PARAM_INT,  'Confirmed registrations'),
            'timecreated'            => new external_value(PARAM_INT,  'Unix ts'),
            'timemodified'           => new external_value(PARAM_INT,  'Unix ts'),
        ]);
        return new external_single_structure([
            'events' => new external_multiple_structure($event, 'All events'),
        ]);
    }
}
