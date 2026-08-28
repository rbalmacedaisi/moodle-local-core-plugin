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
 * Returns the calling student's digital carnet (RF-07). Lazily issues
 * one on the first call. Also returns the absolute URL the QR encodes
 * so the LXP can render the QR locally without a second round-trip.
 * Capability: local/grupomakro_core:view_wellness.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_carnet_manager.php');

class get_carnet extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        global $USER, $CFG;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        if (!isloggedin() || isguestuser()) {
            throw new Exception('user_not_logged_in');
        }

        try {
            $carnet = \local_grupomakro_core\local\wellness_carnet_manager::issue((int)$USER->id, 0);
        } catch (\Throwable $e) {
            // F-24: do NOT leak the internal message to the client. Detail
            // goes to error_log for the admin; the WS gets a generic
            // error code.
            error_log('[grupomakro_core] carnet issue FAILED userid=' . (int)$USER->id . ': ' . $e->getMessage());
            throw new Exception('carnet_issue_failed');
        }

        $photoUrl = \local_grupomakro_core\local\wellness_carnet_manager::photo_url(
            $carnet, (int)$USER->id, $CFG->wwwroot);
        $qrUrl    = \local_grupomakro_core\local\wellness_carnet_manager::build_qr_url(
            $CFG->wwwroot, (int)$USER->id, (string)$carnet->qr_token);

        $now = time();
        $expired = $carnet->valid_until > 0 && $carnet->valid_until < $now;

        return [
            'carnet' => [
                'id'                 => (int)$carnet->id,
                'userid'             => (int)$carnet->userid,
                'fullname'           => (string)$carnet->fullname,
                'documentnumber'     => (string)$carnet->documentnumber,
                'learning_plan_name' => (string)$carnet->learning_plan_name,
                'admission_date'     => (int)$carnet->admission_date,
                'valid_from'         => (int)$carnet->valid_from,
                'valid_until'        => (int)$carnet->valid_until,
                'status'             => (string)$carnet->status,
                'is_expired'         => (bool)$expired,
                'issued_at'          => (int)$carnet->issued_at,
                'photo_url'          => (string)$photoUrl,
                'qr_url'             => (string)$qrUrl,
                'logo_url'           => (string)(get_config('local_grupomakro_core', 'wellness_carnet_logo_url') ?: ''),
            ],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'carnet' => new external_single_structure([
                'id'                 => new external_value(PARAM_INT,  'Carnet id'),
                'userid'             => new external_value(PARAM_INT,  'Student userid'),
                'fullname'           => new external_value(PARAM_TEXT,'Student fullname'),
                'documentnumber'     => new external_value(PARAM_TEXT,'Student document number'),
                'learning_plan_name' => new external_value(PARAM_TEXT,'Career / programme name'),
                'admission_date'     => new external_value(PARAM_INT,  'Unix ts; 0 = unknown'),
                'valid_from'         => new external_value(PARAM_INT,  'Unix ts'),
                'valid_until'        => new external_value(PARAM_INT,  'Unix ts'),
                'status'             => new external_value(PARAM_TEXT,'activo|suspendido|egresado'),
                'is_expired'         => new external_value(PARAM_BOOL,'valid_until < now'),
                'issued_at'          => new external_value(PARAM_INT,  'Unix ts'),
                'photo_url'          => new external_value(PARAM_TEXT,'Photo URL (custom upload or user profile picture)'),
                'qr_url'             => new external_value(PARAM_TEXT,'Absolute URL the QR encodes'),
                'logo_url'           => new external_value(PARAM_TEXT,'Institutional logo URL from settings'),
            ]),
        ]);
    }
}