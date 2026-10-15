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
 * local_grupomakro_msg_open_thread: get or create the (class, student) thread.
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

class open_thread extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'classid'   => new external_value(PARAM_INT, 'gmk_class id'),
            'studentid' => new external_value(PARAM_INT, 'Teacher only: the student to write to', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($classid, $studentid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'classid' => $classid, 'studentid' => $studentid,
        ]);
        self::validate_context(context_system::instance());

        $thread = manager::open_thread((int)$params['classid'], (int)$USER->id, (int)$params['studentid']);
        return ['thread' => manager::export_thread($thread, (int)$USER->id)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'thread' => structures::thread(),
        ]);
    }
}
