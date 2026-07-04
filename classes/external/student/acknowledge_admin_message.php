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
 * External: record a student acknowledgement (or a "no-ack" dismissal) of an
 * admin broadcast message.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

class acknowledge_admin_message extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid'    => new external_value(PARAM_INT,  'User ID acknowledging the broadcast',  VALUE_REQUIRED),
            'messageid' => new external_value(PARAM_INT,  'Message id',                            VALUE_REQUIRED),
            'accept'    => new external_value(PARAM_BOOL, 'True when the user clicked accept; false for informational dismiss only.', VALUE_DEFAULT, true),
        ]);
    }

    public static function execute($userid, $messageid, $accept = true) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'    => $userid,
            'messageid' => $messageid,
            'accept'    => $accept,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        if (!$DB->record_exists('user', ['id' => $params['userid'], 'deleted' => 0])) {
            throw new Exception('user_not_found');
        }

        $ok = \local_grupomakro_core\local\announcement_manager::acknowledge(
            (int)$params['userid'],
            (int)$params['messageid'],
            (bool)$params['accept']
        );

        if (!$ok) {
            throw new Exception('not_a_recipient');
        }

        return [
            'success' => true,
            'userid'  => (int)$params['userid'],
            'messageid' => (int)$params['messageid'],
            'accepted' => (bool)$params['accept'],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success'   => new external_value(PARAM_BOOL, 'True when the ack was persisted'),
            'userid'    => new external_value(PARAM_INT,  'User id'),
            'messageid' => new external_value(PARAM_INT,  'Message id'),
            'accepted'  => new external_value(PARAM_BOOL, 'Whether the user accepted the broadcast or just dismissed it'),
        ]);
    }
}
