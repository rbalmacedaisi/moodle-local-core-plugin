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
 * Admin: list every dynamic form (RF-06 / RF-09.2). Powers the back-office
 * Forms tab.
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

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_dynamic_form_manager.php');

class admin_list_dynamic_forms extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $rows = \local_grupomakro_core\local\wellness_dynamic_form_manager::list_all();
        return [
            'forms' => array_values(array_map(function ($r) {
                return [
                    'id'             => (int)$r->id,
                    'eventid'        => (int)$r->eventid,
                    'event_title'    => (string)($r->event_title ?? ''),
                    'title'          => (string)$r->title,
                    'description'    => (string)$r->description,
                    'schema_json'    => (string)$r->schema_json,
                    'active'         => (int)$r->active,
                    'response_count' => (int)$r->response_count,
                    'timecreated'    => (int)$r->timecreated,
                    'timemodified'   => (int)$r->timemodified,
                ];
            }, $rows)),
        ];
    }

    public static function execute_returns() {
        $form = new external_single_structure([
            'id'             => new external_value(PARAM_INT,  'Form id'),
            'eventid'        => new external_value(PARAM_INT,  'Event id'),
            'event_title'    => new external_value(PARAM_TEXT, 'Event title'),
            'title'          => new external_value(PARAM_TEXT, 'Form title'),
            'description'    => new external_value(PARAM_RAW,  'Description'),
            'schema_json'    => new external_value(PARAM_RAW,  'Raw JSON Schema'),
            'active'         => new external_value(PARAM_INT,  '0/1'),
            'response_count' => new external_value(PARAM_INT,  'Submitted responses'),
            'timecreated'    => new external_value(PARAM_INT,  'Unix ts'),
            'timemodified'   => new external_value(PARAM_INT,  'Unix ts'),
        ]);
        return new external_single_structure([
            'forms' => new external_multiple_structure($form, 'All dynamic forms'),
        ]);
    }
}
