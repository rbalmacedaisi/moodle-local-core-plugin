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
 * Admin: upsert / toggle a psychology schedule slot. Capability gated.
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

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_psychology_manager.php');

class admin_save_psychology_slots extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHA,'list|upsert|toggle', VALUE_DEFAULT, 'list'),
            'slot'   => new external_value(PARAM_RAW, 'JSON-encoded object for upsert (ignored by list/toggle)', VALUE_DEFAULT, '{}'),
            'slotid' => new external_value(PARAM_INT, 'Slot id (for toggle)', VALUE_DEFAULT, 0),
            'active' => new external_value(PARAM_BOOL,'New active flag (for toggle)', VALUE_DEFAULT, true),
        ]);
    }

    public static function execute($action = 'list', $slot = '{}', $slotid = 0, $active = true) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action, 'slot' => $slot, 'slotid' => $slotid, 'active' => $active,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_psychology_appointments', $context);

        global $USER;
        $action = (string)$params['action'];
        if ($action === 'upsert') {
            $decoded = json_decode((string)$params['slot'], true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            try {
                $id = \local_grupomakro_core\local\wellness_psychology_manager::upsert_slot(
                    $decoded, (int)$USER->id);
            } catch (\moodle_exception $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'slots' => []];
            }
            return [
                'ok'    => true,
                'id'    => (int)$id,
                'slots' => self::slot_rows(),
            ];
        }
        if ($action === 'toggle') {
            $ok = \local_grupomakro_core\local\wellness_psychology_manager::set_slot_active(
                (int)$params['slotid'], (int)$params['active'] ? 1 : 0);
            return [
                'ok'    => $ok,
                'id'    => (int)$params['slotid'],
                'slots' => self::slot_rows(),
            ];
        }
        // list (default)
        return [
            'ok'    => true,
            'id'    => 0,
            'slots' => self::slot_rows(),
        ];
    }

    private static function slot_rows(): array {
        $rows = \local_grupomakro_core\local\wellness_psychology_manager::list_slots(false);
        // Join specialist name for the Vue table.
        global $DB;
        $userIds = array_values(array_unique(array_filter(array_map(function ($r) { return (int)$r->psychologist_userid; }, $rows))));
        $users = [];
        if (!empty($userIds)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userIds, SQL_PARAMS_NAMED, 'uid');
            foreach ($DB->get_records_sql(
                "SELECT id, firstname, lastname FROM {user} WHERE id $insql", $inparams) as $u) {
                $users[(int)$u->id] = trim(((string)$u->firstname) . ' ' . ((string)$u->lastname));
            }
        }
        return array_values(array_map(function ($r) use ($users) {
            return [
                'id'                  => (int)$r->id,
                'psychologist_userid' => (int)$r->psychologist_userid,
                'psychologist_name'   => (string)($users[(int)$r->psychologist_userid] ?? ''),
                'weekday'             => (int)$r->weekday,
                'starttime'           => (string)$r->starttime,
                'endtime'             => (string)$r->endtime,
                'modality'            => (string)$r->modality,
                'duration_minutes'    => (int)$r->duration_minutes,
                'location'            => (string)$r->location,
                'valid_from'          => (int)$r->valid_from,
                'valid_until'         => (int)$r->valid_until,
                'active'              => (int)$r->active,
            ];
        }, $rows));
    }

    public static function execute_returns() {
        $row = new external_single_structure([
            'id'                  => new external_value(PARAM_INT,  'Slot id'),
            'psychologist_userid' => new external_value(PARAM_INT,  'Specialist userid'),
            'psychologist_name'   => new external_value(PARAM_TEXT,'Specialist display name'),
            'weekday'             => new external_value(PARAM_INT,  '0-6 weekday'),
            'starttime'           => new external_value(PARAM_TEXT,'HH:MM'),
            'endtime'             => new external_value(PARAM_TEXT,'HH:MM'),
            'modality'            => new external_value(PARAM_TEXT,'presencial|virtual|mixto'),
            'duration_minutes'    => new external_value(PARAM_INT,  'Duration'),
            'location'            => new external_value(PARAM_TEXT,'Office or virtual room'),
            'valid_from'          => new external_value(PARAM_INT,  'Unix ts'),
            'valid_until'         => new external_value(PARAM_INT,  'Unix ts'),
            'active'              => new external_value(PARAM_INT,  '0/1'),
        ]);
        return new external_single_structure([
            'ok'    => new external_value(PARAM_BOOL, 'True on success'),
            'id'    => new external_value(PARAM_INT,  'Touched slot id (0 for list)'),
            'error' => new external_value(PARAM_TEXT, 'Error code on failure'),
            'slots' => new external_multiple_structure($row, 'Updated slots catalogue'),
        ]);
    }
}