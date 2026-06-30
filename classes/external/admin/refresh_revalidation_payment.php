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
 * Refreshes the payment state of a single revalidation against Odoo/Express.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/revalida_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_system;

class refresh_revalidation_payment extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'revalidationid' => new external_value(PARAM_INT, 'Revalidation id', VALUE_REQUIRED),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'paid'          => new external_value(PARAM_BOOL, 'True when the invoice is paid'),
            'payment_state' => new external_value(PARAM_RAW, 'Reported payment_state'),
            'invoice_id'    => new external_value(PARAM_RAW, 'Invoice id'),
            'invoice_number'=> new external_value(PARAM_RAW, 'Invoice number'),
            'paidat'        => new external_value(PARAM_INT, 'Paid-at timestamp (0 if not yet)'),
            'message'       => new external_value(PARAM_RAW, 'Diagnostic message'),
        ]);
    }

    public static function execute(int $revalidationid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'revalidationid' => $revalidationid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_revalidations_dashboard', $context);

        $rec = $DB->get_record('gmk_revalidations', ['id' => (int)$params['revalidationid']], '*', MUST_EXIST);

        $paid = \local_grupomakro_core\local\revalida_manager::verify_payment($rec);
        $rec = $DB->get_record('gmk_revalidations', ['id' => $rec->id], '*', MUST_EXIST);

        return [
            'paid'          => $paid,
            'payment_state' => (string)$rec->payment_state,
            'invoice_id'    => (string)$rec->invoice_id,
            'invoice_number'=> (string)$rec->invoice_number,
            'paidat'        => (int)$rec->paidat,
            'message'       => $paid ? 'paid' : 'still_unpaid',
        ];
    }
}