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
 * local_grupomakro_support_desk_action: Acciones del personal: take, assign, unassign, resolve, close, set_priority, set_category.
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

class desk_action extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action'),
            'targetuserid' => new external_value(PARAM_INT, 'Agent for assign', VALUE_DEFAULT, 0),
            'priority' => new external_value(PARAM_INT, 'Priority for set_priority', VALUE_DEFAULT, -1),
            'categoryid' => new external_value(PARAM_INT, 'Topic for set_category', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($ticketid, $action, $targetuserid, $priority, $categoryid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid, 'action' => $action, 'targetuserid' => $targetuserid, 'priority' => $priority, 'categoryid' => $categoryid]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        $ticket = manager::staff_action((int)$params['ticketid'], $userid, (string)$params['action'],
            (int)$params['targetuserid'], (int)$params['priority'], (int)$params['categoryid']);
        try {
            [, $role] = manager::require_ticket((int)$ticket->id, $userid);
        } catch (\moodle_exception $e) {
            // E.g. moved to a confidential topic the caller cannot see any more.
            return ['visible' => false];
        }
        return ['ticket' => manager::export_ticket($ticket, $userid, $role), 'visible' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ticket' => new external_single_structure(structures::ticket()->keys, 'Ticket', VALUE_OPTIONAL),
            'visible' => new external_value(PARAM_BOOL, 'Caller still sees the ticket'),
        ]);
    }
}
