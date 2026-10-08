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
 * Admin: manage a student's carnet (RF-07, RF-09.4).
 * - renew: extend validity and reset status=activo.
 * - suspend: set status=suspendido.
 * - reinstate: set status=activo and extend validity.
 * - regenerate_token: issue a new qr_token (compromised / lost card).
 *
 * Capability: local/grupomakro_core:manage_wellness.
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

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_carnet_manager.php');

class admin_manage_carnet extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            // N-02: PARAM_ALPHA strips underscores — use PARAM_ALPHAEXT
            // so `regenerate_token` (and any future underscore-style action)
            // survives validate_parameters().
            'action' => new external_value(PARAM_ALPHAEXT,'renew|suspend|reinstate|regenerate_token|graduate', VALUE_REQUIRED),
            'userid' => new external_value(PARAM_INT,  'Target student userid', VALUE_REQUIRED),
        ]);
    }

    public static function execute($action, $userid) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action, 'userid' => $userid,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $mgr = '\\local_grupomakro_core\\local\\wellness_carnet_manager';

        switch ($params['action']) {
            case 'renew':
                $row = $mgr::renew((int)$params['userid'], (int)$USER->id);
                return ['ok' => (bool)$row, 'status' => $row ? $row->status : ''];
            case 'suspend':
                $ok = $mgr::set_status((int)$params['userid'], $mgr::STATUS_SUSPENDIDO, (int)$USER->id);
                return ['ok' => $ok, 'status' => $ok ? $mgr::STATUS_SUSPENDIDO : ''];
            case 'reinstate':
                $row = $mgr::renew((int)$params['userid'], (int)$USER->id);
                return ['ok' => (bool)$row, 'status' => $row ? $row->status : ''];
            case 'regenerate_token':
                $ok = $mgr::regenerate_token((int)$params['userid'], (int)$USER->id);
                return ['ok' => $ok, 'status' => ''];
            case 'graduate':
                $ok = $mgr::set_status((int)$params['userid'], $mgr::STATUS_EGRESADO, (int)$USER->id);
                return ['ok' => $ok, 'status' => $ok ? $mgr::STATUS_EGRESADO : ''];
            default:
                // N-02: explicit error so the JS side can surface a toast
                // instead of silently returning ok:false on a typo.
                throw new \moodle_exception('invalid_parameter', 'core',
                    '', null, 'unknown action: ' . $params['action']);
        }
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'     => new external_value(PARAM_BOOL,'True on success'),
            'status' => new external_value(PARAM_TEXT,'New carnet status (when applicable)'),
        ]);
    }
}