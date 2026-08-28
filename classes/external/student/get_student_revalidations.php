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
 * Web service: the student's own revalidations, for the LXP.
 *
 * Replaces the legacy local_grupomakro_student_get_revalids, which read the
 * abandoned status-7 ("Revalidando") flow and therefore always returned an
 * empty list under the teacher-driven model.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_system;

class get_student_revalidations extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($userid) {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $context = context_system::instance();
        self::validate_context($context);

        require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/revalida_manager.php');

        $rows = $DB->get_records_sql(
            "SELECT r.id, r.classid, r.corecourseid, r.result, r.status,
                    r.originalgrade, r.revalidgrade,
                    r.sessionstart, r.sessionend, r.bbbcmid,
                    r.payment_state, r.payment_link, r.invoice_number,
                    r.alert_sent_at, r.alert_dismissed_at,
                    COALESCE(c.fullname, gc.name) AS coursename,
                    gc.name AS classname
               FROM {gmk_revalidations} r
          LEFT JOIN {gmk_class} gc ON gc.id = r.classid
          LEFT JOIN {course} c ON c.id = r.corecourseid
              WHERE r.userid = :userid
           ORDER BY r.sessionstart DESC, r.id DESC",
            ['userid' => (int)$params['userid']]
        );

        $cost = (float)(get_config('local_grupomakro_core', 'revalida_cost') ?: 0);
        $out = [];
        foreach ($rows as $r) {
            $ispending = ((string)$r->result === 'pending');
            $ispaid = ((string)$r->payment_state === 'paid');
            $out[] = [
                'id'             => (int)$r->id,
                'classid'        => (int)$r->classid,
                'courseid'       => (int)$r->corecourseid,
                'coursename'     => (string)($r->coursename ?? ''),
                'result'         => (string)$r->result,
                'status'         => (string)$r->status,
                'originalgrade'  => (float)$r->originalgrade,
                'revalidgrade'   => ($r->revalidgrade !== null) ? (float)$r->revalidgrade : null,
                'sessionstart'   => (int)$r->sessionstart,
                'sessionend'     => (int)$r->sessionend,
                'session_url'    => \local_grupomakro_core\local\revalida_manager::bbb_url((int)$r->bbbcmid),
                'payment_state'  => (string)$r->payment_state,
                'payment_link'   => (string)($r->payment_link ?? ''),
                'invoice_number' => (string)($r->invoice_number ?? ''),
                'cost'           => $cost,
                // The popup is for revalidations still to be sat and paid; a
                // resolved or already-paid one is informational only.
                'needs_payment'  => ($ispending && !$ispaid),
                'alert_dismissed' => !empty($r->alert_dismissed_at),
            ];
        }

        return ['revalidations' => $out];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'revalidations' => new external_multiple_structure(new external_single_structure([
                'id'             => new external_value(PARAM_INT, 'Revalidation id'),
                'classid'        => new external_value(PARAM_INT, 'Class id'),
                'courseid'       => new external_value(PARAM_INT, 'Core course id'),
                'coursename'     => new external_value(PARAM_RAW, 'Subject name'),
                'result'         => new external_value(PARAM_TEXT, 'pending|approved|failed'),
                'status'         => new external_value(PARAM_TEXT, 'scheduled|graded|consolidated'),
                'originalgrade'  => new external_value(PARAM_FLOAT, 'Grade before the revalidation'),
                'revalidgrade'   => new external_value(PARAM_FLOAT, 'Revalidation exam grade', VALUE_OPTIONAL, null, NULL_ALLOWED),
                'sessionstart'   => new external_value(PARAM_INT, 'Session start (unix ts)'),
                'sessionend'     => new external_value(PARAM_INT, 'Session end (unix ts)'),
                'session_url'    => new external_value(PARAM_RAW, 'BBB session url'),
                'payment_state'  => new external_value(PARAM_TEXT, 'unpaid|paid'),
                'payment_link'   => new external_value(PARAM_RAW, 'Odoo payment link'),
                'invoice_number' => new external_value(PARAM_TEXT, 'Invoice consecutive'),
                'cost'           => new external_value(PARAM_FLOAT, 'Configured revalidation cost'),
                'needs_payment'  => new external_value(PARAM_BOOL, 'Pending and unpaid'),
                'alert_dismissed' => new external_value(PARAM_BOOL, 'Student dismissed the popup'),
            ])),
        ]);
    }
}
