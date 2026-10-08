<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class get_request_detail extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'id' => new \external_value(PARAM_INT, 'Request id'),
        ]);
    }

    public static function execute(int $id): array {
        global $USER;

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:view_wdr_requests', $context);

        $isadmin = has_capability('local/grupomakro_core:manage_wdr_requests', $context);
        $row = \local_grupomakro_core\local\wdr_manager::get_for_user($id, (int)$USER->id, $isadmin);

        return [
            'id'                     => (int)$row->id,
            'request_number'         => $row->request_number,
            'userid'                 => (int)$row->userid,
            'fullname'               => $row->fullname,
            'program'                => $row->program,
            'current_period'         => $row->current_period,
            'last_period'            => $row->last_period,
            'phone'                  => $row->phone,
            'id_number'              => $row->id_number,
            'email'                  => $row->email,
            'payment_mode'           => $row->payment_mode,
            'reason'                 => $row->reason,
            'payment_option'         => $row->payment_option,
            'payment_option_detail'  => $row->payment_option_detail,
            'observations'           => $row->observations,
            'status'                 => $row->status,
            'received_da_at'         => (int)($row->received_da_at ?? 0),
            'received_da_by'         => (int)($row->received_da_by ?? 0),
            'received_admin_at'      => (int)($row->received_admin_at ?? 0),
            'received_admin_by'      => (int)($row->received_admin_by ?? 0),
            'has_scanned'            => !empty($row->scanned_pdf_path),
            'rejection_reason'       => $row->rejection_reason ?? '',
            'timecreated'            => (int)$row->timecreated,
            'timemodified'           => (int)$row->timemodified,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'id'                     => new \external_value(PARAM_INT, ''),
            'request_number'         => new \external_value(PARAM_TEXT, ''),
            'userid'                 => new \external_value(PARAM_INT, ''),
            'fullname'               => new \external_value(PARAM_TEXT, ''),
            'program'                => new \external_value(PARAM_TEXT, ''),
            'current_period'         => new \external_value(PARAM_TEXT, ''),
            'last_period'            => new \external_value(PARAM_TEXT, ''),
            'phone'                  => new \external_value(PARAM_TEXT, ''),
            'id_number'              => new \external_value(PARAM_TEXT, ''),
            'email'                  => new \external_value(PARAM_TEXT, ''),
            'payment_mode'           => new \external_value(PARAM_TEXT, ''),
            'reason'                 => new \external_value(PARAM_TEXT, ''),
            'payment_option'         => new \external_value(PARAM_TEXT, ''),
            'payment_option_detail'  => new \external_value(PARAM_TEXT, ''),
            'observations'           => new \external_value(PARAM_TEXT, ''),
            'status'                 => new \external_value(PARAM_TEXT, ''),
            'received_da_at'         => new \external_value(PARAM_INT, ''),
            'received_da_by'         => new \external_value(PARAM_INT, ''),
            'received_admin_at'      => new \external_value(PARAM_INT, ''),
            'received_admin_by'      => new \external_value(PARAM_INT, ''),
            'has_scanned'            => new \external_value(PARAM_BOOL, ''),
            'rejection_reason'       => new \external_value(PARAM_TEXT, ''),
            'timecreated'            => new \external_value(PARAM_INT, ''),
            'timemodified'           => new \external_value(PARAM_INT, ''),
        ]);
    }
}
