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
 * Web Service: student creates a RET-01 withdrawal request (20261001080).
 *
 * Capability is declared at the service layer (db/services.php) and at the
 * function level as defense-in-depth (PR 20261001007).
 *
 * @package     local_grupomakro_core
 */

namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class create_request extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'reason'                => new \external_value(PARAM_TEXT, 'A|B|C|D|E|F'),
            'payment_option'        => new \external_value(PARAM_TEXT, 'cambio_carrera|transferencia_derechos|no_aplica', VALUE_DEFAULT, ''),
            'payment_option_detail' => new \external_value(PARAM_TEXT, 'Detail text (career / third party id)', VALUE_DEFAULT, ''),
            'observations'          => new \external_value(PARAM_TEXT, 'Free observations', VALUE_DEFAULT, ''),
            'current_period'        => new \external_value(PARAM_TEXT, 'Current period (free text)', VALUE_DEFAULT, ''),
            'last_period'           => new \external_value(PARAM_TEXT, 'Last period attending (free text)', VALUE_DEFAULT, ''),
            'phone'                 => new \external_value(PARAM_TEXT, 'Phone', VALUE_DEFAULT, ''),
            'id_number'             => new \external_value(PARAM_TEXT, 'ID number', VALUE_DEFAULT, ''),
            'email'                 => new \external_value(PARAM_TEXT, 'Email', VALUE_DEFAULT, ''),
            'payment_mode'          => new \external_value(PARAM_TEXT, 'mensual|quincenal', VALUE_DEFAULT, ''),
            'fullname'              => new \external_value(PARAM_TEXT, 'Snapshot fullname', VALUE_DEFAULT, ''),
            'program'               => new \external_value(PARAM_TEXT, 'Snapshot program', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        string $reason, string $payment_option, string $payment_option_detail,
        string $observations, string $current_period, string $last_period,
        string $phone, string $id_number, string $email, string $payment_mode,
        string $fullname, string $program
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'reason'                => $reason,
            'payment_option'        => $payment_option,
            'payment_option_detail' => $payment_option_detail,
            'observations'          => $observations,
            'current_period'        => $current_period,
            'last_period'           => $last_period,
            'phone'                 => $phone,
            'id_number'             => $id_number,
            'email'                 => $email,
            'payment_mode'          => $payment_mode,
            'fullname'              => $fullname,
            'program'               => $program,
        ]);

        // Defence in depth.
        $context = \context_system::instance();
        require_capability('local/grupomakro_core:create_wdr_request', $context);

        $row = \local_grupomakro_core\local\wdr_manager::create_request((int)$USER->id, $params);

        return [
            'id'             => (int)$row->id,
            'request_number' => $row->request_number,
            'status'         => $row->status,
            'timecreated'    => (int)$row->timecreated,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'id'             => new \external_value(PARAM_INT, 'Row id'),
            'request_number' => new \external_value(PARAM_TEXT, 'RET-{YYYY}-{NNNN}'),
            'status'         => new \external_value(PARAM_TEXT, 'Status code'),
            'timecreated'    => new \external_value(PARAM_INT, 'Unix ts'),
        ]);
    }
}
