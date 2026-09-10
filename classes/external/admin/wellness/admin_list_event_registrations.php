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
 * Admin: list every registration for an event with student details (RF-02 / RF-09.2).
 *
 * Returns every row in gmk_wellness_registration for the event (including
 * 'cancelada' so the admin can audit), enriched with the student's name,
 * email and username from mdl_user. The status filter is applied client-side
 * because keeping the WS shape stable lets the panel add a "show cancelled"
 * toggle without an extra round-trip.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_registration_manager.php');

class admin_list_event_registrations extends external_api {

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

        $rows = \local_grupomakro_core\local\wellness_registration_manager::list_event_registrations(
            (int)$params['eventid']
        );
        return [
            'registrations' => array_values(array_map(function ($r) {
                return [
                    'id'             => (int)$r->id,
                    'eventid'        => (int)$r->eventid,
                    'userid'         => (int)$r->userid,
                    'fullname'       => trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')),
                    'email'          => (string)($r->email ?? ''),
                    'username'       => (string)($r->username ?? ''),
                    'status'         => (string)$r->status,
                    'modality'       => (string)($r->modality ?? ''),
                    'registered_at'  => (int)$r->registered_at,
                    'cancelled_at'   => (int)$r->cancelled_at,
                    'attended_at'    => (int)$r->attended_at,
                    'source'         => (string)$r->source,
                    'registered_by'  => (int)$r->registered_by,
                ];
            }, $rows)),
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'registrations' => new external_multiple_structure(new external_single_structure([
                'id'             => new external_value(PARAM_INT,  'Registration id'),
                'eventid'        => new external_value(PARAM_INT,  'Event id'),
                'userid'         => new external_value(PARAM_INT,  'Student user id'),
                'fullname'       => new external_value(PARAM_TEXT, 'Student fullname'),
                'email'          => new external_value(PARAM_TEXT, 'Student email'),
                'username'       => new external_value(PARAM_TEXT, 'Moodle username'),
                'status'         => new external_value(PARAM_ALPHA,'confirmada|lista_de_espera|cancelada|asistio|no_asistio'),
                'modality'       => new external_value(PARAM_TEXT, 'presencial|virtual|(empty)'),
                'registered_at'  => new external_value(PARAM_INT,  'Unix ts'),
                'cancelled_at'   => new external_value(PARAM_INT,  'Unix ts'),
                'attended_at'    => new external_value(PARAM_INT,  'Unix ts'),
                'source'         => new external_value(PARAM_TEXT, 'lxp|backoffice'),
                'registered_by'  => new external_value(PARAM_INT,  'userid of staff who registered them, 0 if self-service'),
            ])),
        ]);
    }
}
