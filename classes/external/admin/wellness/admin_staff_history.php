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
 * Admin: history of changes to gmk_wellness_staff_role. Powers the
 * v-timeline drawer in the staff panel so RR.HH. can see who replaced whom.
 *
 * Capability: local/grupomakro_core:manage_psychology_appointments.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_staff_manager.php');

class admin_staff_history extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'rolekey' => new external_value(PARAM_TEXT, 'rolekey; empty = all roles (capped at last 200 rows)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($rolekey = '') {
        $params = self::validate_parameters(self::execute_parameters(), ['rolekey' => $rolekey]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_psychology_appointments', $context);

        if ($params['rolekey'] !== '') {
            $rows = \local_grupomakro_core\local\wellness_staff_manager::history($params['rolekey']);
        } else {
            $rows = [];
            foreach (\local_grupomakro_core\local\wellness_staff_manager::SYSTEM_ROLES as $rk => $_) {
                foreach (\local_grupomakro_core\local\wellness_staff_manager::history((string)$rk) as $row) {
                    $rows[] = $row;
                }
            }
            usort($rows, fn($a, $b) => $b['changed_at'] <=> $a['changed_at']);
            if (count($rows) > 200) {
                $rows = array_slice($rows, 0, 200);
            }
        }
        return ['history' => $rows];
    }

    public static function execute_returns() {
        $row = new external_single_structure([
            'id'              => new external_value(PARAM_INT,  'Audit row id'),
            'rolekey'         => new external_value(PARAM_TEXT,'rolekey'),
            'old_userid'      => new external_value(PARAM_INT,  'Previous linked userid (0 = none)'),
            'new_userid'      => new external_value(PARAM_INT,  'New linked userid (0 = none)'),
            'old_fullname'    => new external_value(PARAM_TEXT,'Previous user fullname'),
            'new_fullname'    => new external_value(PARAM_TEXT,'New user fullname'),
            'old_email'       => new external_value(PARAM_TEXT,'Previous email override'),
            'new_email'       => new external_value(PARAM_TEXT,'New email override'),
            'changed_by'      => new external_value(PARAM_INT,  'Userid of the admin who made the change'),
            'changed_by_name' => new external_value(PARAM_TEXT,'Admin display name'),
            'changed_at'      => new external_value(PARAM_INT,  'Unix ts'),
            'note'            => new external_value(PARAM_RAW, 'Optional handoff note'),
        ]);
        return new external_single_structure([
            'history' => new external_multiple_structure($row, 'Audit rows, newest first.'),
        ]);
    }
}