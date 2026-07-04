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
 * External (admin): enable / disable (soft-delete) an existing broadcast.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\announcement;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

class set_message_active extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'messageid' => new external_value(PARAM_INT,  'Broadcast id',  VALUE_REQUIRED),
            'active'    => new external_value(PARAM_BOOL, 'true=enable, false=disable', VALUE_REQUIRED),
        ]);
    }

    public static function execute($messageid, $active) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'messageid' => $messageid,
            'active'    => $active,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manageannouncements', $context);

        $ok = \local_grupomakro_core\local\announcement_manager::set_active(
            (int)$params['messageid'], $params['active'] ? 1 : 0
        );

        if (!$ok) {
            throw new Exception('message_not_found');
        }

        return [
            'success'   => true,
            'id'        => (int)$params['messageid'],
            'active'    => (bool)$params['active'],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True when the toggle was persisted'),
            'id'      => new external_value(PARAM_INT,  'Message id'),
            'active'  => new external_value(PARAM_BOOL, 'New active flag'),
        ]);
    }
}
