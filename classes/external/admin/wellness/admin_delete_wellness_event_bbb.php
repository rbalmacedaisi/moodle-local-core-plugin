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
 * Admin: tear down the BBB room attached to a wellness event (RF-04).
 *
 * Deletes the course_module + bigbluebuttonbn instance so the cmid stops
 * resolving and any guest_join.php link becomes a 404. Clears the bbb_cmid
 * column so the UI falls back to the "Generar link" button.
 *
 * No-op (ok=false, not_found=true) if the event doesn't have a room, so the
 * UI can call this defensively after a refresh.
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

class admin_delete_wellness_event_bbb extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid' => new external_value(PARAM_INT, 'Wellness event id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($eventid) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['eventid' => $eventid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $event = $DB->get_record('gmk_wellness_event', ['id' => (int)$params['eventid']],
            'id, bbb_cmid', MUST_EXIST);

        if (empty($event->bbb_cmid)) {
            return ['ok' => false, 'not_found' => true];
        }

        $cmid = (int)$event->bbb_cmid;
        $cm = get_coursemodule_from_id('bigbluebuttonbn', $cmid, 0, false, IGNORE_MISSING);
        if ($cm) {
            // course_delete_module handles the cascade (module row, grade
            // items, calendar events, completion data) and the delete_module
            // hook if any. Wrapped in try because the BBB module is happy
            // to throw if the activity is part of an in-progress session.
            try {
                course_delete_module($cmid);
            } catch (\Throwable $e) {
                throw new Exception('No se pudo eliminar la sala BBB: ' . $e->getMessage());
            }
        }

        $DB->set_field('gmk_wellness_event', 'bbb_cmid', 0, ['id' => (int)$event->id]);
        // Limpia cualquier claim de anfitrion que haya quedado de la sesion
        // anterior: el siguiente evento que reuse el mismo cmid (improbable)
        // no deberia heredar el host.
        $DB->delete_records('gmk_guest_meeting_host', ['cmid' => $cmid]);

        return ['ok' => true, 'not_found' => false];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'        => new external_value(PARAM_BOOL, 'True if a room was removed'),
            'not_found' => new external_value(PARAM_BOOL, 'True if the event had no room to remove'),
        ]);
    }
}
