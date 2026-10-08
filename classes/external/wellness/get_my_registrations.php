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
 * List the registrations of the calling student.
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

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_registration_manager.php');

class get_my_registrations extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $rows = \local_grupomakro_core\local\wellness_registration_manager::list_for_user((int)$USER->id);
        $casted = array_values(array_map(function ($r) {
            return [
                'id'             => (int)$r->id,
                'eventid'        => (int)$r->eventid,
                'status'         => (string)$r->status,
                'modality'       => (string)$r->modality,
                'registered_at'  => (int)$r->registered_at,
                'attended_at'    => (int)$r->attended_at,
                'cancelled_at'   => (int)$r->cancelled_at,
                'title'          => (string)$r->title,
                'summary'        => (string)($r->summary ?? ''),
                'startdate'      => (int)$r->startdate,
                'enddate'        => (int)$r->enddate,
                'event_modality' => (string)$r->event_modality,
                'location'       => (string)$r->location,
                'virtual_url'    => (string)$r->virtual_url,
                'cover_path'     => (string)$r->cover_path,
                'category'       => (string)$r->category,
                'capacity'       => (int)$r->capacity,
            ];
        }, $rows));
        return ['registrations' => $casted];
    }

    public static function execute_returns() {
        $row = new external_single_structure([
            'id'             => new external_value(PARAM_INT, 'Registration id'),
            'eventid'        => new external_value(PARAM_INT, 'Event id'),
            'status'         => new external_value(PARAM_TEXT, 'confirmada|lista_de_espera|cancelada|asistio|no_asistio'),
            'modality'       => new external_value(PARAM_TEXT, 'presencial|virtual'),
            'registered_at'  => new external_value(PARAM_INT, 'Unix ts'),
            'attended_at'    => new external_value(PARAM_INT, 'Unix ts'),
            'cancelled_at'   => new external_value(PARAM_INT, 'Unix ts'),
            'title'          => new external_value(PARAM_TEXT, 'Event title'),
            'summary'        => new external_value(PARAM_TEXT, 'Event teaser'),
            'startdate'      => new external_value(PARAM_INT, 'Event start ts'),
            'enddate'        => new external_value(PARAM_INT, 'Event end ts'),
            'event_modality' => new external_value(PARAM_TEXT, 'Event modality'),
            'location'       => new external_value(PARAM_TEXT, 'Location'),
            'virtual_url'    => new external_value(PARAM_TEXT, 'Virtual room URL'),
            'cover_path'     => new external_value(PARAM_TEXT, 'Cover image'),
            'category'       => new external_value(PARAM_TEXT, 'Event category'),
            'capacity'       => new external_value(PARAM_INT,  'Event capacity'),
        ]);
        return new external_single_structure([
            'registrations' => new external_multiple_structure($row, 'Registrations of the calling student'),
        ]);
    }
}
