<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): acciones del estudiante sobre SU solicitud.
 *  - local_grupomakro_letter_cancel: anular mientras nadie la trabaja.
 *  - local_grupomakro_letter_open_ticket: "Hablar de esta carta" (abre o
 *    continua el ticket ligado).
 * Identidad = $USER siempre.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\letter;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\letters\manager;
use local_grupomakro_core\local\letters\tickets;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class student_actions extends external_api {

    public static function cancel_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Request id'),
            'reason' => new external_value(PARAM_TEXT, 'Optional reason', VALUE_DEFAULT, ''),
        ]);
    }

    public static function cancel(int $requestid, string $reason = ''): array {
        global $USER;
        $params = self::validate_parameters(self::cancel_parameters(), ['requestid' => $requestid, 'reason' => $reason]);
        self::validate_context(context_system::instance());
        $request = manager::student_cancel((int)$params['requestid'], (int)$USER->id, (string)$params['reason']);
        return ['success' => true, 'status' => (string)$request->status];
    }

    public static function cancel_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Done'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'New status'),
        ]);
    }

    public static function open_ticket_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Request id'),
            'message' => new external_value(PARAM_RAW, 'First message (required when there is no open ticket)',
                VALUE_DEFAULT, ''),
        ]);
    }

    public static function open_ticket(int $requestid, string $message = ''): array {
        global $USER;
        $params = self::validate_parameters(self::open_ticket_parameters(),
            ['requestid' => $requestid, 'message' => $message]);
        self::validate_context(context_system::instance());
        $ticket = tickets::open_for_student((int)$params['requestid'], (int)$USER->id, (string)$params['message']);
        return ['ticketid' => (int)$ticket->id, 'number' => (string)$ticket->number];
    }

    public static function open_ticket_returns(): external_single_structure {
        return new external_single_structure([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'number' => new external_value(PARAM_TEXT, 'Ticket number'),
        ]);
    }
}
