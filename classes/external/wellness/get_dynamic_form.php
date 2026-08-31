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
 * Read the schema of the dynamic form attached to an event (RF-06).
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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_dynamic_form_manager.php');

class get_dynamic_form extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid' => new external_value(PARAM_INT, 'Event id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($eventid) {
        $params = self::validate_parameters(self::execute_parameters(), ['eventid' => $eventid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $form = \local_grupomakro_core\local\wellness_dynamic_form_manager::get_for_event(
            (int)$params['eventid']);
        if (!$form) {
            return ['form' => null];
        }
        return [
            'form' => [
                'id'          => (int)$form->id,
                'eventid'     => (int)$form->eventid,
                'title'       => (string)$form->title,
                'description' => (string)$form->description,
            'cover_path'  => (string)($form->cover_path ?? ''),
                'schema'      => $form->schema,
                'active'      => (int)$form->active,
            ],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'form' => new external_single_structure([
                'id'          => new external_value(PARAM_INT,  'Form id'),
                'eventid'     => new external_value(PARAM_INT,  'Event id (0 when reusable)'),
                'title'       => new external_value(PARAM_TEXT, 'Title'),
                'description' => new external_value(PARAM_RAW,  'Description'),
            'cover_path'  => new external_value(PARAM_RAW,  'URL absoluta de la portada (vacio = sin portada)'),
                'schema'      => new external_single_structure([
                    'fields' => new external_value(PARAM_RAW, 'JSON array of field definitions'),
                ]),
                'active'      => new external_value(PARAM_INT, '0/1'),
            ], 'null when the event has no active form attached'),
        ]);
    }
}
