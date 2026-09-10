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
 * Admin: provision a BBB guest-meeting room for a wellness event (RF-04).
 *
 * Wraps local_grupomakro_create_express_activity(-1, 'bigbluebuttonbn', ...)
 * (the path already used by pages/manage_meetings.php) and records the new
 * course module id on gmk_wellness_event.bbb_cmid. The returned cmid and
 * guest URL are then surfaced in the panel so the admin can copy/paste them
 * into the event announcement / chat / email to attendees.
 *
 * If the event already has a bbb_cmid, this WS is a no-op and returns the
 * existing link (so the UI button "Generar link" can't accidentally create
 * a second room for the same event).
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

class admin_create_wellness_event_bbb extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid' => new external_value(PARAM_INT, 'Wellness event id', VALUE_REQUIRED),
            // Optional override; defaults to the event title + intro.
            'name'    => new external_value(PARAM_TEXT, 'Override BBB meeting name', VALUE_DEFAULT, ''),
            // Optional override for the welcome message shown inside the room.
            'welcome' => new external_value(PARAM_RAW, 'Override BBB welcome text', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($eventid, $name = '', $welcome = '') {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'eventid' => $eventid, 'name' => $name, 'welcome' => $welcome,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $event = $DB->get_record('gmk_wellness_event', ['id' => (int)$params['eventid']],
            '*', MUST_EXIST);

        // Idempotency: don't spawn a second room if the admin double-clicks or
        // reloads while the dialog still shows the previous room. The button
        // hides itself once bbb_cmid>0, so the WS is the second line of defence.
        if (!empty($event->bbb_cmid)) {
            $existingurl = \local_grupomakro_core\local\wellness_event_manager::resolve_guest_url((int)$event->bbb_cmid);
            return ['ok' => true, 'cmid' => (int)$event->bbb_cmid, 'guest_url' => $existingurl, 'already' => true];
        }

        $meetingname = trim($name) !== '' ? trim($name) : ('Bienestar: ' . $event->title);
        $intro = trim($welcome) !== ''
            ? trim($welcome)
            : ('Sala virtual del evento "' . $event->title . '". El primer participante en entrar sera el anfitrion.');

        try {
            // Reuses the same code path as manage_meetings.php so the guest
            // flow (gmk_guest_meeting_host + first-joiner-wins-moderator) works
            // identically. classid=-1 is the sentinel for "no class, use the
            // front page" inside local_grupomakro_create_express_activity.
            $result = local_grupomakro_create_express_activity(-1, 'bigbluebuttonbn',
                $meetingname, $intro, ['guest' => true]);
        } catch (\Throwable $e) {
            throw new Exception('No se pudo crear la sala BBB: ' . $e->getMessage());
        }

        $cmid = isset($result->coursemodule) ? (int)$result->coursemodule : 0;
        if ($cmid <= 0) {
            throw new Exception('La creacion de la sala BBB no devolvio un cmid valido.');
        }

        $DB->set_field('gmk_wellness_event', 'bbb_cmid', $cmid, ['id' => (int)$event->id]);
        $guesturl = \local_grupomakro_core\local\wellness_event_manager::resolve_guest_url($cmid);

        return ['ok' => true, 'cmid' => $cmid, 'guest_url' => $guesturl, 'already' => false];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'        => new external_value(PARAM_BOOL, 'True on success'),
            'cmid'      => new external_value(PARAM_INT,  'Course module id of the new BBB activity'),
            'guest_url' => new external_value(PARAM_TEXT, 'Guest join URL'),
            // True if the call was a no-op because the event already had a room.
            'already'   => new external_value(PARAM_BOOL, 'True if the event already had a room'),
        ]);
    }
}
