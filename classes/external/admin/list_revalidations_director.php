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
 * Lists revalidation requests for the academic director dashboard.
 *
 * Filters:
 *   - status: unpaid | paid_ungraded | graded | all  (default unpaid)
 *   - classid, periodid, instructorid, learningplanid
 *   - search (matches student name, idnumber, email)
 *   - include_extemporaneous (bool, default true)
 *   - created_by_director (bool, default false → only teacher-created)
 *   - page, perpage (pagination)
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
use external_multiple_structure;
use context_system;
use stdClass;

class list_revalidations_director extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'status_filter'   => new external_value(PARAM_TEXT, 'unpaid|paid_ungraded|graded|all', VALUE_DEFAULT, 'unpaid'),
            'classid'         => new external_value(PARAM_INT, 'Class id', VALUE_DEFAULT, 0),
            'periodid'        => new external_value(PARAM_INT, 'Academic period id', VALUE_DEFAULT, 0),
            'instructorid'    => new external_value(PARAM_INT, 'Instructor userid', VALUE_DEFAULT, 0),
            'learningplanid'  => new external_value(PARAM_INT, 'Learning plan id', VALUE_DEFAULT, 0),
            'search'          => new external_value(PARAM_TEXT, 'Free-text search on student', VALUE_DEFAULT, ''),
            'include_extemporaneous' => new external_value(PARAM_BOOL, 'Include extemporaneous rows', VALUE_DEFAULT, true),
            'created_by_director_only' => new external_value(PARAM_BOOL, 'Only rows created by director', VALUE_DEFAULT, false),
            'page'            => new external_value(PARAM_INT, 'Page (0-based)', VALUE_DEFAULT, 0),
            'perpage'         => new external_value(PARAM_INT, 'Items per page', VALUE_DEFAULT, 25),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows'  => new external_multiple_structure(new external_single_structure([
                'id'                   => new external_value(PARAM_INT, 'Revalidation id'),
                'classid'              => new external_value(PARAM_INT, 'Class id'),
                'classname'            => new external_value(PARAM_RAW, 'Class name'),
                'userid'               => new external_value(PARAM_INT, 'Student userid'),
                'student_name'         => new external_value(PARAM_RAW, 'Student fullname'),
                'student_idnumber'     => new external_value(PARAM_RAW, 'Student idnumber'),
                'student_email'        => new external_value(PARAM_RAW, 'Student email'),
                'corecourseid'         => new external_value(PARAM_INT, 'Core course id'),
                'coursename'           => new external_value(PARAM_RAW, 'Course name'),
                'instructorid'         => new external_value(PARAM_INT, 'Instructor userid'),
                'instructor_name'      => new external_value(PARAM_RAW, 'Instructor name'),
                'periodid'             => new external_value(PARAM_INT, 'Academic period id'),
                'periodname'           => new external_value(PARAM_RAW, 'Period name'),
                'learningplanid'       => new external_value(PARAM_INT, 'Learning plan id'),
                'originalgrade'        => new external_value(PARAM_FLOAT, 'Original grade'),
                'revalidgrade'         => new external_value(PARAM_FLOAT, 'Revalidation grade'),
                'result'               => new external_value(PARAM_RAW, 'pending|approved|failed'),
                'payment_state'        => new external_value(PARAM_RAW, 'unpaid|paid'),
                'paidat'               => new external_value(PARAM_INT, 'Paid-at timestamp'),
                'invoice_id'           => new external_value(PARAM_RAW, 'Odoo invoice id'),
                'invoice_number'       => new external_value(PARAM_RAW, 'Odoo invoice number'),
                'payment_link'         => new external_value(PARAM_RAW, 'Odoo payment link'),
                'bbb_url'              => new external_value(PARAM_RAW, 'BBB session url'),
                'sessionstart'         => new external_value(PARAM_INT, 'BBB session start'),
                'sessionend'           => new external_value(PARAM_INT, 'BBB session end'),
                'status'               => new external_value(PARAM_RAW, 'scheduled|consolidated'),
                'extemporaneous'       => new external_value(PARAM_INT, 'Extemporaneous flag'),
                'extemporaneous_reason'=> new external_value(PARAM_RAW, 'Extemporaneous reason'),
                'extemporaneous_by'    => new external_value(PARAM_INT, 'Extemporaneous creator userid'),
                'extemporaneous_by_name' => new external_value(PARAM_RAW, 'Extemporaneous creator name'),
                'extemporaneous_at'    => new external_value(PARAM_INT, 'Extemporaneous created-at timestamp'),
                'createdby'            => new external_value(PARAM_INT, 'Original creator'),
                'createdby_name'       => new external_value(PARAM_RAW, 'Original creator name'),
                'timecreated'          => new external_value(PARAM_INT, 'Created timestamp'),
            ])),
            'counts' => new external_single_structure([
                'unpaid'        => new external_value(PARAM_INT, 'Unpaid count'),
                'paid_ungraded' => new external_value(PARAM_INT, 'Paid but ungraded count'),
                'graded'        => new external_value(PARAM_INT, 'Graded count'),
                'total'         => new external_value(PARAM_INT, 'Total count'),
            ]),
            'page'    => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Per page'),
            'total'   => new external_value(PARAM_INT, 'Total rows for current filter'),
        ]);
    }

    public static function execute(
        string $status_filter = 'unpaid',
        int $classid = 0,
        int $periodid = 0,
        int $instructorid = 0,
        int $learningplanid = 0,
        string $search = '',
        bool $include_extemporaneous = true,
        bool $created_by_director_only = false,
        int $page = 0,
        int $perpage = 25
    ) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'status_filter'   => $status_filter,
            'classid'         => $classid,
            'periodid'        => $periodid,
            'instructorid'    => $instructorid,
            'learningplanid'  => $learningplanid,
            'search'          => $search,
            'include_extemporaneous' => $include_extemporaneous,
            'created_by_director_only' => $created_by_director_only,
            'page'            => $page,
            'perpage'         => $perpage,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_revalidations_dashboard', $context);

        $perpage = max(1, min(200, (int)$params['perpage']));
        $page    = max(0, (int)$params['page']);

        [$where, $whereparams] = self::build_where($params);

        // Counts (across all status buckets, with the same other filters).
        [$basewhere, $baseparams] = self::build_base_where($params);
        $counts = self::compute_counts($basewhere, $baseparams);

        $sql = "SELECT r.*, u.firstname, u.lastname, u.email, u.idnumber,
                       c.fullname AS coursename,
                       gc.name AS classname, gc.instructorid, gc.periodid AS classperiodid,
                       i.firstname AS instructor_firstname, i.lastname AS instructor_lastname,
                       cb.firstname AS createdby_firstname, cb.lastname AS createdby_lastname,
                       eb.firstname AS extby_firstname, eb.lastname AS extby_lastname
                  FROM {gmk_revalidations} r
                  JOIN {user} u       ON u.id = r.userid
                  JOIN {course} c     ON c.id = r.corecourseid
                  JOIN {gmk_class} gc ON gc.id = r.classid
                  JOIN {user} i       ON i.id = gc.instructorid
                  LEFT JOIN {user} cb  ON cb.id = r.createdby
                  LEFT JOIN {user} eb  ON eb.id = r.extemporaneous_by
                 WHERE $where
              ORDER BY r.timecreated DESC, r.id DESC";

        $total = (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM {gmk_revalidations} r
                JOIN {user} u ON u.id = r.userid
                JOIN {gmk_class} gc ON gc.id = r.classid
               WHERE $where",
            $whereparams
        );

        $rows = $DB->get_records_sql($sql, $whereparams, $page * $perpage, $perpage);

        $out = [];
        foreach ($rows as $r) {
            $out[] = self::format_row($r);
        }

        return [
            'rows'   => $out,
            'counts' => $counts,
            'page'   => $page,
            'perpage'=> $perpage,
            'total'  => $total,
        ];
    }

    private static function build_base_where(array $p): array {
        $where = ['1=1'];
        $params = [];

        if (!empty($p['classid'])) {
            $where[] = 'r.classid = :classid';
            $params['classid'] = (int)$p['classid'];
        }
        if (!empty($p['periodid'])) {
            $where[] = 'gc.periodid = :periodid';
            $params['periodid'] = (int)$p['periodid'];
        }
        if (!empty($p['instructorid'])) {
            $where[] = 'gc.instructorid = :instructorid';
            $params['instructorid'] = (int)$p['instructorid'];
        }
        if (!empty($p['learningplanid'])) {
            $where[] = 'r.learningplanid = :lpid';
            $params['lpid'] = (int)$p['learningplanid'];
        }
        if (!empty($p['search'])) {
            $like = '%' . $p['search'] . '%';
            $where[] = '(' . $DB->sql_like('u.firstname', ':s1', false) . ' OR '
                          . $DB->sql_like('u.lastname', ':s2', false) . ' OR '
                          . $DB->sql_like('u.idnumber', ':s3', false) . ' OR '
                          . $DB->sql_like('u.email', ':s4', false) . ')';
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
            $params['s4'] = $like;
        }
        if (!$p['include_extemporaneous']) {
            $where[] = 'r.extemporaneous = 0';
        }
        if (!empty($p['created_by_director_only'])) {
            $where[] = 'r.extemporaneous = 1';
        }

        return [implode(' AND ', $where), $params];
    }

    private static function build_where(array $p): array {
        [$basewhere, $baseparams] = self::build_base_where($p);
        $extra = '';
        switch ($p['status_filter']) {
            case 'unpaid':
                $extra = " AND r.payment_state = 'unpaid'";
                break;
            case 'paid_ungraded':
                $extra = " AND r.payment_state = 'paid' AND r.status = 'scheduled'";
                break;
            case 'graded':
                $extra = " AND r.status = 'consolidated'";
                break;
            case 'all':
            default:
                $extra = '';
                break;
        }
        return [$basewhere . $extra, $baseparams];
    }

    private static function compute_counts(string $basewhere, array $baseparams): array {
        global $DB;
        $unpaid = (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM {gmk_revalidations} r
                JOIN {user} u ON u.id = r.userid
                JOIN {gmk_class} gc ON gc.id = r.classid
               WHERE $basewhere AND r.payment_state = 'unpaid'", $baseparams);
        $paidun = (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM {gmk_revalidations} r
                JOIN {user} u ON u.id = r.userid
                JOIN {gmk_class} gc ON gc.id = r.classid
               WHERE $basewhere AND r.payment_state = 'paid' AND r.status = 'scheduled'", $baseparams);
        $graded = (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM {gmk_revalidations} r
                JOIN {user} u ON u.id = r.userid
                JOIN {gmk_class} gc ON gc.id = r.classid
               WHERE $basewhere AND r.status = 'consolidated'", $baseparams);
        $total  = (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM {gmk_revalidations} r
                JOIN {user} u ON u.id = r.userid
                JOIN {gmk_class} gc ON gc.id = r.classid
               WHERE $basewhere", $baseparams);
        return [
            'unpaid' => $unpaid,
            'paid_ungraded' => $paidun,
            'graded' => $graded,
            'total' => $total,
        ];
    }

    private static function format_row(stdClass $r): array {
        global $DB;
        $periodname = '';
        if (!empty($r->classperiodid)) {
            $periodname = (string)$DB->get_field('gmk_academic_periods', 'name', ['id' => (int)$r->classperiodid]);
        }
        return [
            'id' => (int)$r->id,
            'classid' => (int)$r->classid,
            'classname' => (string)($r->classname ?? ''),
            'userid' => (int)$r->userid,
            'student_name' => trim((string)$r->firstname . ' ' . (string)$r->lastname),
            'student_idnumber' => (string)($r->idnumber ?? ''),
            'student_email' => (string)($r->email ?? ''),
            'corecourseid' => (int)$r->corecourseid,
            'coursename' => (string)($r->coursename ?? ''),
            'instructorid' => (int)($r->instructorid ?? 0),
            'instructor_name' => trim((string)($r->instructor_firstname ?? '') . ' ' . (string)($r->instructor_lastname ?? '')),
            'periodid' => (int)($r->classperiodid ?? 0),
            'periodname' => $periodname,
            'learningplanid' => (int)($r->learningplanid ?? 0),
            'originalgrade' => (float)$r->originalgrade,
            'revalidgrade' => isset($r->revalidgrade) ? (float)$r->revalidgrade : null,
            'result' => (string)$r->result,
            'payment_state' => (string)$r->payment_state,
            'paidat' => (int)$r->paidat,
            'invoice_id' => (string)($r->invoice_id ?? ''),
            'invoice_number' => (string)($r->invoice_number ?? ''),
            'payment_link' => (string)($r->payment_link ?? ''),
            'bbb_url' => \local_grupomakro_core\local\revalida_manager::bbb_url((int)$r->bbbcmid),
            'sessionstart' => (int)$r->sessionstart,
            'sessionend' => (int)$r->sessionend,
            'status' => (string)$r->status,
            'extemporaneous' => (int)($r->extemporaneous ?? 0),
            'extemporaneous_reason' => (string)($r->extemporaneous_reason ?? ''),
            'extemporaneous_by' => (int)($r->extemporaneous_by ?? 0),
            'extemporaneous_by_name' => trim((string)($r->extby_firstname ?? '') . ' ' . (string)($r->extby_lastname ?? '')),
            'extemporaneous_at' => (int)($r->extemporaneous_at ?? 0),
            'createdby' => (int)($r->createdby ?? 0),
            'createdby_name' => trim((string)($r->createdby_firstname ?? '') . ' ' . (string)($r->createdby_lastname ?? '')),
            'timecreated' => (int)$r->timecreated,
        ];
    }
}