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
 * local_grupomakro_msg_send: post a message on a thread.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\messaging;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\messaging\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class send_message extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'threadid' => new external_value(PARAM_INT, 'Thread id'),
            // RAW on purpose: manager::clean_body() strips HTML and control chars
            // while keeping newlines, which PARAM_TEXT would not guarantee.
            'body'     => new external_value(PARAM_RAW, 'Plain text message'),
        ]);
    }

    public static function execute($threadid, $body): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'threadid' => $threadid, 'body' => $body,
        ]);
        self::validate_context(context_system::instance());

        $userid = (int)$USER->id;
        [$message, $thread] = manager::send((int)$params['threadid'], $userid, (string)$params['body']);
        return [
            'message' => manager::export_message($message, $userid),
            'thread'  => manager::export_thread($thread, $userid),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => structures::message(),
            'thread'  => structures::thread(),
        ]);
    }
}
