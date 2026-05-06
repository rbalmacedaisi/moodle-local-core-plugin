<?php
defined('MOODLE_INTERNAL') || die();

namespace local_grupomakro_core\external\lp;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

class get_lp_students extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'planid'      => new external_value(PARAM_INT,  'Source plan ID (0 = all)', VALUE_DEFAULT, 0),
            'periodid'    => new external_value(PARAM_INT,  'Source period ID (0 = all)', VALUE_DEFAULT, 0),
            'subperiodid' => new external_value(PARAM_INT,  'Source subperiod ID (0 = all)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $planid = 0, int $periodid = 0, int $subperiodid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'planid'      => $planid,
            'periodid'    => $periodid,
            'subperiodid' => $subperiodid,
        ]);

        try {
            $where  = ['llu.userrolename = :role'];
            $sqlparams = ['role' => 'student'];

            if ($params['planid'] > 0) {
                $where[] = 'llu.learningplanid = :planid';
                $sqlparams['planid'] = $params['planid'];
            }
            if ($params['periodid'] > 0) {
                $where[] = 'llu.currentperiodid = :periodid';
                $sqlparams['periodid'] = $params['periodid'];
            }
            if ($params['subperiodid'] > 0) {
                $where[] = 'llu.currentsubperiodid = :subperiodid';
                $sqlparams['subperiodid'] = $params['subperiodid'];
            }

            $sql = "SELECT
                        llu.id          AS lluid,
                        llu.userid,
                        llu.learningplanid AS planid,
                        llu.currentperiodid    AS periodid,
                        llu.currentsubperiodid AS subperiodid,
                        llu.groupname,
                        u.firstname,
                        u.lastname,
                        u.email,
                        llp.name        AS planname,
                        llper.name      AS periodname,
                        llsp.name       AS subperiodname
                    FROM {local_learning_users} llu
                    JOIN {user} u          ON u.id  = llu.userid AND u.deleted = 0
                    JOIN {local_learning_plans} llp ON llp.id = llu.learningplanid
                    LEFT JOIN {local_learning_periods}    llper ON llper.id = llu.currentperiodid
                    LEFT JOIN {local_learning_subperiods} llsp  ON llsp.id  = llu.currentsubperiodid
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY llp.name, llper.name, llsp.name, u.lastname, u.firstname";

            $rows = $DB->get_records_sql($sql, $sqlparams);

            $students = array_values(array_map(function($r) {
                return [
                    'lluid'         => (int)$r->lluid,
                    'userid'        => (int)$r->userid,
                    'planid'        => (int)$r->planid,
                    'periodid'      => (int)($r->periodid ?? 0),
                    'subperiodid'   => (int)($r->subperiodid ?? 0),
                    'groupname'     => (string)($r->groupname ?? ''),
                    'fullname'      => trim($r->firstname . ' ' . $r->lastname),
                    'email'         => (string)$r->email,
                    'planname'      => (string)$r->planname,
                    'periodname'    => (string)($r->periodname ?? ''),
                    'subperiodname' => (string)($r->subperiodname ?? ''),
                    'groupkey'      => $r->planname . '|' . ($r->periodname ?? '') . '|' . ($r->subperiodname ?? ''),
                ];
            }, $rows));

            return ['status' => 1, 'students' => json_encode($students)];
        } catch (\Throwable $e) {
            return ['status' => -1, 'students' => '[]', 'message' => $e->getMessage()];
        }
    }

    public static function execute_returns(): \external_description {
        return new external_single_structure([
            'status'   => new external_value(PARAM_INT,  'Result status', VALUE_DEFAULT, 1),
            'students' => new external_value(PARAM_RAW,  'JSON array of students', VALUE_DEFAULT, '[]'),
            'message'  => new external_value(PARAM_TEXT, 'Error message', VALUE_DEFAULT, ''),
        ]);
    }
}
