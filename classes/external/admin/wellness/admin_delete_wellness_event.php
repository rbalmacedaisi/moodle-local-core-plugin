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
 * Admin: hard-delete a wellness event (RF-09.2).
 *
 * Cascade rules:
 *   - gmk_wellness_registration rows for the event are deleted (we don't keep
 *     history for cancelled events; this matches what the partner/event admin
 *     expects when they reach for "Eliminar" rather than "Desactivar").
 *   - gmk_wellness_event_files rows for the event are deleted.
 *   - Any form (gmk_wellness_dynamic_form) tied to this event is detached
 *     (eventid = 0) instead of deleted, so the form can be re-attached to
 *     another event without losing the schema or the student submissions.
 *   - Any BBB room attached via gmk_wellness_event.bbb_cmid is torn down:
 *     course_delete_module() + clear bbb_cmid + drop the
 *     gmk_guest_meeting_host claim so the cmid is fully reusable.
 *
 * No soft-delete alternative for events today; the toggle WS
 * (admin_toggle_wellness_event_active) is the way to hide without losing data.
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

class admin_delete_wellness_event extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Event id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($id) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $event = $DB->get_record('gmk_wellness_event', ['id' => (int)$params['id']],
            'id, bbb_cmid', MUST_EXIST);

        $trans = $DB->start_delegated_transaction();
        try {
            // 1. Cascade registrations and attachments.
            $DB->delete_records('gmk_wellness_registration', ['eventid' => (int)$event->id]);
            $DB->delete_records('gmk_wellness_event_files', ['eventid' => (int)$event->id]);

            // 2. Detach dynamic forms so their submissions survive.
            $DB->set_field('gmk_wellness_dynamic_form', 'eventid', 0,
                ['eventid' => (int)$event->id]);

            // 3. Tear down the BBB room if any. Same shape as
            // admin_delete_wellness_event_bbb, duplicated here so the delete
            // is one click from the events list (no need to disable first).
            if (!empty($event->bbb_cmid)) {
                $cmid = (int)$event->bbb_cmid;
                $cm = get_coursemodule_from_id('bigbluebuttonbn', $cmid, 0, false, IGNORE_MISSING);
                if ($cm) {
                    try {
                        course_delete_module($cmid);
                    } catch (\Throwable $e) {
                        // If the BBB module refuses (live session), keep going
                        // and just clear the link so the orphan cmid can be
                        // cleaned up by the orphan-grade-items CLI later.
                        throw new Exception('No se pudo eliminar la sala BBB: ' . $e->getMessage());
                    }
                }
                $DB->delete_records('gmk_guest_meeting_host', ['cmid' => $cmid]);
            }

            // 4. Drop the event row itself.
            $DB->delete_records('gmk_wellness_event', ['id' => (int)$event->id]);

            $trans->allow_commit();
        } catch (\Throwable $e) {
            $trans->rollback($e);
            throw $e;
        }

        return ['ok' => true];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True on success'),
        ]);
    }
}
