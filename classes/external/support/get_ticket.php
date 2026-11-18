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
 * local_grupomakro_support_get_ticket: Ticket con sus mensajes (paginados hacia atras) y, para el personal, el historial.
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

class get_ticket extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'beforeid' => new external_value(PARAM_INT, 'Messages older than this id', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Max messages', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute($ticketid, $beforeid, $limit): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid, 'beforeid' => $beforeid, 'limit' => $limit]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        [$ticket, $role] = manager::require_ticket((int)$params['ticketid'], $userid);
        $page = manager::messages($ticket, $userid, $role, (int)$params['beforeid'], (int)$params['limit']);
        $isstaff = $role !== manager::ROLE_REQUESTER;
        $previous = 0;
        if ($isstaff && (int)$params['beforeid'] === 0) {
            // Opening the ticket in the desk is the acknowledgement of its alert (§8.5).
            manager::ack((int)$ticket->id, $userid, 'open');
        }
        if ($isstaff) {
            global $DB;
            $previous = $DB->count_records_select('gmk_tkt_ticket', 'userid = ? AND id <> ?',
                [(int)$ticket->userid, (int)$ticket->id]);
        }
        return [
            'ticket' => manager::export_ticket($ticket, $userid, $role),
            'messages' => $page['messages'],
            'hasmore' => $page['hasmore'],
            'events' => $isstaff ? manager::events($ticket) : [],
            'previoustickets' => $previous,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ticket' => structures::ticket(),
            'messages' => new external_multiple_structure(structures::message()),
            'hasmore' => new external_value(PARAM_BOOL, 'Older messages exist'),
            'events' => new external_multiple_structure(structures::event()),
            'previoustickets' => new external_value(PARAM_INT, 'Other tickets of the requester (staff only)'),
        ]);
    }
}
