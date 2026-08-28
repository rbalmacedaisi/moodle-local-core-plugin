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
 * Admin: toggle a wellness event's active flag (soft delete / restore).
 * RF-09.2.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_event_manager.php');

class admin_toggle_event_active extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'id'     => new external_value(PARAM_INT,  'Event id', VALUE_REQUIRED),
            'active' => new external_value(PARAM_BOOL, 'True to enable, false to soft-delete', VALUE_REQUIRED),
        ]);
    }

    public static function execute($id, $active) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id, 'active' => $active]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $ok = \local_grupomakro_core\local\wellness_event_manager::set_active(
            (int)$params['id'], (int)$params['active'] ? 1 : 0, (int)$USER->id);
        return ['ok' => $ok];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True when the row exists'),
        ]);
    }
}
