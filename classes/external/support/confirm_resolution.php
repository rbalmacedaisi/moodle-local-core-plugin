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
 * local_grupomakro_support_confirm_resolution: El solicitante responde si se resolvio su caso (si: cierra; no: reabre).
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

class confirm_resolution extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'resolved' => new external_value(PARAM_BOOL, 'Solved'),
        ]);
    }

    public static function execute($ticketid, $resolved): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid, 'resolved' => $resolved]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        $ticket = manager::confirm_resolution((int)$params['ticketid'], $userid, (bool)$params['resolved']);
        return ['ticket' => manager::export_ticket($ticket, $userid, manager::ROLE_REQUESTER)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['ticket' => structures::ticket()]);
    }
}
