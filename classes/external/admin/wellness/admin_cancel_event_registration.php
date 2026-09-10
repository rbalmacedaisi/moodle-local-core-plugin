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
 * Admin: cancel a specific student's registration to an event (RF-02 / RF-09.2).
 *
 * Distinct from the student-facing cancel_registration WS: this one takes
 * the userid to cancel explicitly (the admin acts on behalf of anyone),
 * sets source='backoffice' on the audit trail, and requires the
 * manage_wellness capability instead of view_wellness.
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

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_registration_manager.php');

class admin_cancel_event_registration extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid' => new external_value(PARAM_INT, 'Event id', VALUE_REQUIRED),
            'userid'  => new external_value(PARAM_INT, 'Student user id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($eventid, $userid) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'eventid' => $eventid, 'userid' => $userid,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        // $byuser=false tells the manager this is a back-office cancellation.
        $r = \local_grupomakro_core\local\wellness_registration_manager::cancel(
            (int)$params['eventid'],
            (int)$params['userid'],
            false
        );
        if (empty($r['ok'])) {
            $msg = (string)($r['error'] ?? 'cancel_failed');
            throw new Exception($msg);
        }
        return ['ok' => true, 'already' => !empty($r['already'])];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'      => new external_value(PARAM_BOOL, 'True on success'),
            'already' => new external_value(PARAM_BOOL, 'True when the registration was already cancelled', VALUE_DEFAULT),
        ]);
    }
}
