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
 * local_grupomakro_support_snooze: "Posponer 5 min" en el bloqueo a pantalla
 * completa (máximo tkt_snooze_max veces por alerta). No es un acuse.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\support\badge;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class snooze extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
        ]);
    }

    public static function execute($ticketid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid]);
        self::validate_context(context_system::instance());
        return ['until' => badge::snooze((int)$params['ticketid'], (int)$USER->id)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['until' => new external_value(PARAM_INT, 'Snoozed until')]);
    }
}
