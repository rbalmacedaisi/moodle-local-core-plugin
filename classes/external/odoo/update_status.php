<?php
namespace local_grupomakro_core\external\odoo;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use moodle_exception;

class update_status extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'username'     => new external_value(PARAM_RAW, 'The username of the student', VALUE_REQUIRED),
                'status'       => new external_value(PARAM_TEXT, 'The new status (active, suspended)', VALUE_REQUIRED),
                'reason'       => new external_value(PARAM_TEXT, 'Reason for update', VALUE_DEFAULT, '')
            )
        );
    }

    public static function execute($username, $status, $reason = '') {
        global $DB, $USER;

        // Validation
        $params = self::validate_parameters(self::execute_parameters(), array(
            'username'     => $username,
            'status'       => $status,
            'reason'       => $reason
        ));

        // 1. Resolve User
        $lookupUsername = \core_text::strtolower($params['username']);
        $user = $DB->get_record('user', ['username' => $lookupUsername, 'deleted' => 0]);
        if (!$user) {
            throw new moodle_exception('invaliduser', 'error', '', $params['username'] . " (mapped to $lookupUsername)");
        }

        $status = strtolower($params['status']);
        $update_needed = false;

        // 2. Map Status to Moodle 'suspended' field
        // Moodle: 0 = Active, 1 = Suspended
        if ($status === 'active') {
            if ($user->suspended != 0) {
                $user->suspended = 0;
                $update_needed = true;
            }
        } elseif ($status === 'suspended' || $status === 'inactive') {
            if ($user->suspended != 1) {
                $user->suspended = 1;
                $update_needed = true;
            }
        } else {
             throw new moodle_exception('invalidstatus', 'local_grupomakro_core', '', 'Allowed values: active, suspended');
        }

        if ($update_needed) {
            $user->timemodified = time();
            $DB->update_record('user', $user);
            
            // Log the action?
            // Could add logging here if needed.
        }

        return [
            'status' => 'success',
            'message' => 'User status updated to ' . $status
        ];
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_TEXT, 'Status of the operation'),
                'message' => new external_value(PARAM_TEXT, 'Result message')
            )
        );
    }
}
