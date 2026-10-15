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
 * local_grupomakro_msg_get_messages: a thread and its messages (paged backwards).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\messaging;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\messaging\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_messages extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'threadid' => new external_value(PARAM_INT, 'Thread id'),
            'beforeid' => new external_value(PARAM_INT, 'Only messages older than this id (0 = latest)', VALUE_DEFAULT, 0),
            'limit'    => new external_value(PARAM_INT, 'Max messages (1-100)', VALUE_DEFAULT, 30),
        ]);
    }

    public static function execute($threadid, $beforeid = 0, $limit = 30): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'threadid' => $threadid, 'beforeid' => $beforeid, 'limit' => $limit,
        ]);
        self::validate_context(context_system::instance());

        $userid = (int)$USER->id;
        [$thread] = manager::require_thread((int)$params['threadid'], $userid);
        $result = manager::messages($thread, $userid, (int)$params['beforeid'], (int)$params['limit']);
        $result['thread'] = manager::export_thread($thread, $userid);
        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'thread'   => structures::thread(),
            'messages' => new external_multiple_structure(structures::message(), 'Messages, oldest first'),
            'hasmore'  => new external_value(PARAM_BOOL, 'True when older messages exist'),
        ]);
    }
}
