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
 * local_grupomakro_support_send_message: Envia un mensaje (el personal puede mandar nota interna o pedir informacion).
 *
 * Mesa de ayuda (20261001120). Identidad = $USER siempre; el acceso lo decide
 * local\support\manager.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\support\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class send_message extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'body' => new external_value(PARAM_RAW, 'Plain text message'),
            'internal' => new external_value(PARAM_BOOL, 'Internal note (staff only)', VALUE_DEFAULT, false),
            'askinfo' => new external_value(PARAM_BOOL, 'Ask the requester for information (staff only)', VALUE_DEFAULT, false),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area with the attachments (support_upload.php)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($ticketid, $body, $internal, $askinfo, $draftitemid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid, 'body' => $body,
            'internal' => $internal, 'askinfo' => $askinfo, 'draftitemid' => $draftitemid]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        [$message, $ticket] = manager::send((int)$params['ticketid'], $userid, (string)$params['body'],
            (bool)$params['internal'], (bool)$params['askinfo'], (int)$params['draftitemid']);
        [, $role] = manager::require_ticket((int)$ticket->id, $userid);
        return [
            'message' => manager::export_message($message, $userid),
            'ticket' => manager::export_ticket($ticket, $userid, $role),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => structures::message(),
            'ticket' => structures::ticket(),
        ]);
    }
}
