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
 * Detail of a single wellness event, with attachments and registration
 * count. RF-04 / RF-09.2.
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

class get_event_detail extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid' => new external_value(PARAM_INT, 'Event id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($eventid) {
        $params = self::validate_parameters(self::execute_parameters(), ['eventid' => $eventid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $event = \local_grupomakro_core\local\wellness_event_manager::get((int)$params['eventid']);
        if (!$event) {
            return ['event' => null, 'attachments' => []];
        }
        if ((int)$event->active !== 1) {
            // Hide inactive events from students; staff can read via the admin WS.
            return ['event' => null, 'attachments' => []];
        }

        $casted = get_events::cast_event($event);
        $attachments = array_values(array_map(function ($a) {
            return [
                'id'         => (int)$a->id,
                'kind'       => (string)$a->kind,
                'label'      => (string)$a->label,
                'url'        => (string)$a->url,
                'file_path'  => (string)$a->file_path,
                'mimetype'   => (string)$a->mimetype,
                'filesize'   => (int)$a->filesize,
                'sort'       => (int)$a->sort,
            ];
        }, $event->attachments));

        return [
            'event'       => $casted,
            'attachments' => $attachments,
            'registered_count'      => (int)$event->registered_confirmed,
            'registered_waitlist'   => (int)$event->registered_waitlist,
        ];
    }

    public static function execute_returns() {
        // Re-use the event structure from get_events but inline it here
        // because Moodle's external_single_structure expects a concrete
        // structure (not a callable).
        $event = new external_single_structure([
            'id'                     => new external_value(PARAM_INT,  'Event id'),
            'title'                  => new external_value(PARAM_TEXT, 'Title'),
            'summary'                => new external_value(PARAM_TEXT, 'One-line teaser'),
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
            'cover_path'             => new external_value(PARAM_TEXT, 'Cover image pluginfile path'),
            'registration_open'      => new external_value(PARAM_BOOL, 'Computed flag'),
            'event_started'          => new external_value(PARAM_BOOL, 'Computed flag'),
            'event_ended'            => new external_value(PARAM_BOOL, 'Computed flag'),
        ]);
        $att = new external_single_structure([
            'id'        => new external_value(PARAM_INT,  'Attachment id'),
            'kind'      => new external_value(PARAM_ALPHA,'handout|recording|link|other'),
            'label'     => new external_value(PARAM_TEXT, 'Label'),
            'url'       => new external_value(PARAM_TEXT, 'External URL (when kind=link)'),
            'file_path' => new external_value(PARAM_TEXT, 'Pluginfile path (when kind=handout|recording|other)'),
            'mimetype'  => new external_value(PARAM_TEXT, 'MIME'),
            'filesize'  => new external_value(PARAM_INT,  'Bytes'),
            'sort'      => new external_value(PARAM_INT,  'Order'),
        ]);
        return new external_single_structure([
            'event'                => $event,
            'attachments'          => new external_multiple_structure($att, 'Attachments'),
            'registered_count'     => new external_value(PARAM_INT, 'Confirmed registrations'),
            'registered_waitlist'  => new external_value(PARAM_INT, 'Waitlist registrations'),
        ]);
    }
}
