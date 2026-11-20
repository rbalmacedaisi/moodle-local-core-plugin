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
 * local_grupomakro_support_get_catalog: Areas y temas disponibles para abrir una solicitud (incluye enlaces externos como Finanzas).
 *
 * Mesa de ayuda (20261001120). Identidad = $USER siempre; el acceso lo decide
 * local\support\manager.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\support\manager;
use local_grupomakro_core\local\support\restriction;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_catalog extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'audience' => new external_value(PARAM_ALPHA, 'student (portal) | teacher (Moodle, §8.4)', VALUE_DEFAULT, 'student'),
        ]);
    }

    public static function execute($audience = 'student'): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['audience' => $audience]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        require_capability('local/grupomakro_core:support_create', context_system::instance());
        return [
            'areas' => manager::catalog($userid, $params['audience'] === 'teacher' ? 'teacher' : 'student'),
            'max_open' => \local_grupomakro_core\local\support\config::get_int('tkt_max_open'),
            'max_body' => manager::MAX_BODY,
            'attachlimits' => structures::export_attachlimits(),
            // Mora (20261001132): el portal muestra primero el boton de Cobros.
            'restriction' => self::export_restriction(
                $params['audience'] === 'teacher' ? null : restriction::for_user($userid)),
        ];
    }

    private static function export_restriction(?array $r): array {
        return [
            'active' => $r !== null,
            'reason' => $r['reason'] ?? '',
            'title' => $r['title'] ?? '',
            'message' => $r['message'] ?? '',
            'url' => $r['url'] ?? '',
            'label' => $r['label'] ?? '',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'areas' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Area id'),
                'code' => new external_value(PARAM_ALPHANUMEXT, 'Code'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'description' => new external_value(PARAM_RAW, 'Description'),
                'icon' => new external_value(PARAM_TEXT, 'mdi icon'),
                'hours' => new external_value(PARAM_TEXT, 'Business hours, readable'),
                'isopen' => new external_value(PARAM_BOOL, 'Within business hours now'),
                'external' => new external_value(PARAM_BOOL, 'Does not open tickets: shows a link'),
                'external_url' => new external_value(PARAM_RAW, 'Link with the initial message'),
                'external_label' => new external_value(PARAM_TEXT, 'Button text'),
                'topics' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Topic id'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'confidential' => new external_value(PARAM_BOOL, 'Confidential'),
                    'requires_attachment' => new external_value(PARAM_BOOL, 'Attachment required (phase 3)'),
                ])),
                'faqs' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Common problem id'),
                    'categoryid' => new external_value(PARAM_INT, 'Topic to preselect (0 = first of the area)'),
                    'problem' => new external_value(PARAM_TEXT, 'What the person selects'),
                    'answerhtml' => new external_value(PARAM_RAW, 'Answer as safe HTML (escaped text, lists, bold)'),
                    'links' => new external_multiple_structure(new external_single_structure([
                        'label' => new external_value(PARAM_TEXT, 'Button text'),
                        'url' => new external_value(PARAM_URL, 'https link'),
                        'kind' => new external_value(PARAM_ALPHA, 'web | appstore | playstore'),
                    ])),
                ]), 'Common problems (guided answers)', VALUE_DEFAULT, []),
            ])),
            'max_open' => new external_value(PARAM_INT, 'Max open requests per student'),
            'max_body' => new external_value(PARAM_INT, 'Max characters per message'),
            'attachlimits' => structures::attachlimits(),
            'restriction' => new external_single_structure([
                'active' => new external_value(PARAM_BOOL, 'Portal blocked for a reason the help desk cannot solve'),
                'reason' => new external_value(PARAM_ALPHANUMEXT, 'mora'),
                'title' => new external_value(PARAM_TEXT, 'Heading'),
                'message' => new external_value(PARAM_TEXT, 'Explanation'),
                'url' => new external_value(PARAM_RAW, 'WhatsApp link with the initial message'),
                'label' => new external_value(PARAM_TEXT, 'Button text'),
            ], 'Access restriction to redirect (mora -> Cobros)', VALUE_OPTIONAL),
        ]);
    }
}
