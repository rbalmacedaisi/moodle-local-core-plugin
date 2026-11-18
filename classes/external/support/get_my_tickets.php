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
 * local_grupomakro_support_get_my_tickets: Solicitudes del usuario actual.
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

class get_my_tickets extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'filter' => new external_value(PARAM_ALPHA, 'all | open | closed', VALUE_DEFAULT, 'all'),
        ]);
    }

    public static function execute($filter): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['filter' => $filter]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        return [
            'tickets' => manager::my_tickets($userid, (string)$params['filter']),
            'unreadtotal' => manager::requester_unread($userid),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'tickets' => structures::tickets(),
            'unreadtotal' => new external_value(PARAM_INT, 'Unread messages in my requests'),
        ]);
    }
}
