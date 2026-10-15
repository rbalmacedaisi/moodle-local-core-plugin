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
 * local_grupomakro_msg_get_inbox: conversations of the caller (student or teacher).
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

class get_inbox extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'classid'    => new external_value(PARAM_INT, 'Only threads of this class (0 = all)', VALUE_DEFAULT, 0),
            'unreadonly' => new external_value(PARAM_BOOL, 'Only threads with unread messages', VALUE_DEFAULT, false),
            'search'     => new external_value(PARAM_TEXT, 'Filter by student, class or course name', VALUE_DEFAULT, ''),
            'page'       => new external_value(PARAM_INT, 'Page (0-based)', VALUE_DEFAULT, 0),
            'perpage'    => new external_value(PARAM_INT, 'Threads per page (max 100)', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute($classid = 0, $unreadonly = false, $search = '', $page = 0, $perpage = 50): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'classid' => $classid, 'unreadonly' => $unreadonly, 'search' => $search,
            'page' => $page, 'perpage' => $perpage,
        ]);
        self::validate_context(context_system::instance());

        $perpage = max(1, min(100, (int)$params['perpage']));
        $result = manager::inbox((int)$USER->id, (int)$params['classid'], (bool)$params['unreadonly'],
            (string)$params['search'], (int)$params['page'], $perpage);
        $result['unreadtotal'] = manager::unread_count((int)$USER->id);
        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'threads'     => new external_multiple_structure(structures::thread(), 'Threads, newest first'),
            'total'       => new external_value(PARAM_INT, 'Threads matching the filters'),
            'unreadtotal' => new external_value(PARAM_INT, 'Unread messages across all the caller threads'),
        ]);
    }
}
