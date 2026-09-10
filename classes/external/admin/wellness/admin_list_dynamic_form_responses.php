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
 * Admin: list submitted responses for a dynamic form (RF-06 / RF-09.2).
 *
 * Returns the decoded answers per student so the back-office can audit
 * responses without touching the JSON column directly.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

class admin_list_dynamic_form_responses extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'formid' => new external_value(PARAM_INT, 'Form id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($formid) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['formid' => $formid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $sql = "SELECT r.id, r.formid, r.eventid, r.userid, r.answers_json, r.submitted_at,
                       u.firstname, u.lastname, u.email
                  FROM {gmk_wellness_form_resp} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.formid = :formid
              ORDER BY r.submitted_at DESC, r.id DESC";
        $rows = $DB->get_records_sql($sql, ['formid' => (int)$params['formid']]);

        return [
            'responses' => array_values(array_map(function ($r) {
                $decoded = json_decode((string)$r->answers_json, true);
                return [
                    'id'           => (int)$r->id,
                    'formid'       => (int)$r->formid,
                    'eventid'      => (int)$r->eventid,
                    'userid'       => (int)$r->userid,
                    'student_name' => trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')),
                    'email'        => (string)($r->email ?? ''),
                    'submitted_at' => (int)$r->submitted_at,
                    'answers'      => is_array($decoded) ? $decoded : [],
                ];
            }, $rows)),
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'responses' => new external_multiple_structure(new external_single_structure([
                'id'           => new external_value(PARAM_INT,  'Response id'),
                'formid'       => new external_value(PARAM_INT,  'Form id'),
                'eventid'      => new external_value(PARAM_INT,  'Event id'),
                'userid'       => new external_value(PARAM_INT,  'Student id'),
                'student_name' => new external_value(PARAM_TEXT, 'Student name'),
                'email'        => new external_value(PARAM_TEXT, 'Student email'),
                'submitted_at' => new external_value(PARAM_INT,  'Unix ts'),
                'answers'      => new external_single_structure([
                    'answers' => new external_value(PARAM_RAW, 'Raw JSON object of answers'),
                ], 'Decoded answers object', VALUE_OPTIONAL),
            ])),
        ]);
    }
}
