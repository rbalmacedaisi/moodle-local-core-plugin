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
 * local_grupomakro_support_rate_ticket: el solicitante califica la atención
 * de un ticket cerrado (1-5 estrellas, "¿Te resolvimos?" y comentario), o la
 * pospone con "Ahora no" (dismiss=1; reaparece una sola vez al día siguiente).
 * Una calificación baja avisa a los supervisores. Fase 2, §7.1.
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
use local_grupomakro_core\local\support\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class rate_ticket extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'rating' => new external_value(PARAM_INT, '1..5 (ignored when dismiss)', VALUE_DEFAULT, 0),
            'solved' => new external_value(PARAM_BOOL, 'Did we solve it?', VALUE_DEFAULT, true),
            'comment' => new external_value(PARAM_RAW, 'Optional comment', VALUE_DEFAULT, ''),
            'dismiss' => new external_value(PARAM_BOOL, '"Ahora no"', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute($ticketid, $rating, $solved, $comment, $dismiss): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid, 'rating' => $rating,
            'solved' => $solved, 'comment' => $comment, 'dismiss' => $dismiss]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        if (!empty($params['dismiss'])) {
            manager::dismiss_rating((int)$params['ticketid'], $userid);
        } else {
            manager::rate((int)$params['ticketid'], $userid, (int)$params['rating'], (bool)$params['solved'],
                (string)$params['comment']);
        }
        return ['ok' => true, 'pending' => count(manager::pending_ratings($userid))];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Done'),
            'pending' => new external_value(PARAM_INT, 'Ratings still pending'),
        ]);
    }
}
