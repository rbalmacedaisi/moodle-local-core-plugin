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
 * Creates an extemporaneous revalidation request for a (class, student) pair.
 *
 * Bypasses the academic calendar window but enforces the same eligibility rule
 * as the teacher path. Marks the row with extemporaneous=1 + actor/timestamp/
 * reason for audit.
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

class create_extemporaneous_revalidation extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'classid' => new external_value(PARAM_INT, 'Class id', VALUE_REQUIRED),
            'userid'  => new external_value(PARAM_INT, 'Student userid', VALUE_REQUIRED),
            'reason'  => new external_value(PARAM_TEXT, 'Reason (20-500 chars)', VALUE_REQUIRED),
            'override_session_start' => new external_value(PARAM_INT, 'Optional override of BBB session start (UNIX ts)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'    => new external_value(PARAM_BOOL, 'True on success'),
            'error' => new external_value(PARAM_RAW, 'Error message'),
            'record' => new external_single_structure([
                'id'              => new external_value(PARAM_INT, 'Revalidation id'),
                'classid'         => new external_value(PARAM_INT, 'Class id'),
                'userid'          => new external_value(PARAM_INT, 'Student userid'),
                'corecourseid'    => new external_value(PARAM_INT, 'Core course id'),
                'payment_state'   => new external_value(PARAM_RAW, 'unpaid|paid'),
                'invoice_id'      => new external_value(PARAM_RAW, 'Odoo invoice id'),
                'invoice_number'  => new external_value(PARAM_RAW, 'Odoo invoice number'),
                'payment_link'    => new external_value(PARAM_RAW, 'Odoo payment link'),
                'bbb_url'         => new external_value(PARAM_RAW, 'BBB session url'),
                'sessionstart'    => new external_value(PARAM_INT, 'Session start'),
                'sessionend'      => new external_value(PARAM_INT, 'Session end'),
                'extemporaneous'  => new external_value(PARAM_INT, '1 if extemporaneous'),
                'extemporaneous_reason' => new external_value(PARAM_RAW, 'Extemporaneous reason'),
            ], 'Record details on success', VALUE_DEFAULT, null),
        ]);
    }

    public static function execute(int $classid, int $userid, string $reason, int $override_session_start = 0) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classid' => $classid,
            'userid'  => $userid,
            'reason'  => $reason,
            'override_session_start' => $override_session_start,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:create_extemporaneous_revalidations', $context);

        $override = (int)$params['override_session_start'] > 0 ? (int)$params['override_session_start'] : null;

        $res = \local_grupomakro_core\local\revalida_manager::schedule_extemporaneous(
            (int)$params['classid'],
            (int)$params['userid'],
            (int)$USER->id,
            (string)$params['reason'],
            $override
        );

        if (!$res['ok'] || empty($res['record'])) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? 'unknown',
                'record' => null,
            ];
        }

        $r = $res['record'];
        return [
            'ok' => true,
            'error' => null,
            'record' => [
                'id'              => (int)$r->id,
                'classid'         => (int)$r->classid,
                'userid'          => (int)$r->userid,
                'corecourseid'    => (int)$r->corecourseid,
                'payment_state'   => (string)$r->payment_state,
                'invoice_id'      => (string)($r->invoice_id ?? ''),
                'invoice_number'  => (string)($r->invoice_number ?? ''),
                'payment_link'    => (string)($r->payment_link ?? ''),
                'bbb_url'         => (string)($r->bbb_url ?? ''),
                'sessionstart'    => (int)$r->sessionstart,
                'sessionend'      => (int)$r->sessionend,
                'extemporaneous'  => (int)($r->extemporaneous ?? 1),
                'extemporaneous_reason' => (string)($r->extemporaneous_reason ?? ''),
            ],
        ];
    }
}