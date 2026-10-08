<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_multiple_structure;
use external_single_structure;

class admin_list extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'status' => new \external_value(PARAM_TEXT, 'Status filter or empty', VALUE_DEFAULT, ''),
            'search' => new \external_value(PARAM_TEXT, 'Free text search', VALUE_DEFAULT, ''),
            'from'   => new \external_value(PARAM_INT, 'Unix ts lower bound', VALUE_DEFAULT, 0),
            'to'     => new \external_value(PARAM_INT, 'Unix ts upper bound', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $status, string $search, int $from, int $to): array {
        global $DB;

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:view_wdr_requests', $context);

        $where = ['1=1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(' . $DB->sql_like('request_number', ':rnx', false) . ' OR '
                     . $DB->sql_like('fullname', ':fnm', false) . ' OR '
                     . $DB->sql_like('id_number', ':idn', false) . ')';
            $params['rnx'] = '%' . $search . '%';
            $params['fnm'] = '%' . $search . '%';
            $params['idn'] = '%' . $search . '%';
        }
        if ($from > 0) {
            $where[] = 'timecreated >= :fromts';
            $params['fromts'] = $from;
        }
        if ($to > 0) {
            $where[] = 'timecreated <= :tots';
            $params['tots'] = $to;
        }

        $sql = 'SELECT * FROM {gmk_wdr} WHERE ' . implode(' AND ', $where) . ' ORDER BY timecreated DESC LIMIT 500';
        $rows = $DB->get_records_sql($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'                  => (int)$r->id,
                'request_number'      => $r->request_number,
                'fullname'            => $r->fullname,
                'id_number'           => $r->id_number,
                'program'             => $r->program,
                'reason'              => $r->reason,
                'status'              => $r->status,
                'timecreated'         => (int)$r->timecreated,
                'received_da_at'      => (int)($r->received_da_at ?? 0),
                'received_admin_at'   => (int)($r->received_admin_at ?? 0),
                'has_scanned'         => !empty($r->scanned_pdf_path),
            ];
        }
        return $out;
    }

    public static function execute_returns(): external_multiple_structure {
        return new \external_multiple_structure(new \external_single_structure([
            'id'                => new \external_value(PARAM_INT, ''),
            'request_number'    => new \external_value(PARAM_TEXT, ''),
            'fullname'          => new \external_value(PARAM_TEXT, ''),
            'id_number'         => new \external_value(PARAM_TEXT, ''),
            'program'           => new \external_value(PARAM_TEXT, ''),
            'reason'            => new \external_value(PARAM_TEXT, ''),
            'status'            => new \external_value(PARAM_TEXT, ''),
            'timecreated'       => new \external_value(PARAM_INT, ''),
            'received_da_at'    => new \external_value(PARAM_INT, ''),
            'received_admin_at' => new \external_value(PARAM_INT, ''),
            'has_scanned'       => new \external_value(PARAM_BOOL, ''),
        ]));
    }
}
