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
 * Submit a student's answers to a dynamic form (RF-06).
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

class submit_dynamic_form extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'formid'  => new external_value(PARAM_INT,  'Form id', VALUE_REQUIRED),
            'answers' => new external_value(PARAM_RAW,  'JSON-encoded object {field: value}', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function execute($formid, $answers = '{}') {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'formid' => $formid, 'answers' => $answers,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $decoded = json_decode((string)$params['answers'], true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $r = \local_grupomakro_core\local\wellness_dynamic_form_manager::submit(
            (int)$params['formid'], (int)$USER->id, $decoded);

        // Forma FIJA, pase lo que pase. `submit()` devuelve `field_errors`
        // cuando las respuestas no validan, y en ese caso no devuelve
        // `responseid`: devolver eso tal cual chocaba con execute_returns() y
        // Moodle lanzaba invalid_response_exception, asi que al alumno le
        // reventaba el envio en vez de decirle que campo le falta.
        $fielderrors = [];
        if (!empty($r['field_errors']) && is_array($r['field_errors'])) {
            $fielderrors = $r['field_errors'];
        }
        return [
            'ok'           => !empty($r['ok']),
            'responseid'   => (int)($r['responseid'] ?? 0),
            'error'        => (string)($r['error'] ?? ''),
            // Mapa campo -> codigo de error, como JSON: el numero y el nombre
            // de los campos los decide cada formulario, asi que no se puede
            // declarar una estructura fija.
            'field_errors' => json_encode((object)$fielderrors, JSON_UNESCAPED_UNICODE),
            'formid'       => (int)$params['formid'],
            'userid'       => (int)$USER->id,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'           => new external_value(PARAM_BOOL, 'True on success'),
            'responseid'   => new external_value(PARAM_INT,  'Response row id, 0 on failure'),
            'error'        => new external_value(PARAM_TEXT, 'Error code, empty on success'),
            'field_errors' => new external_value(PARAM_RAW,  'JSON map field -> error code'),
            'formid'       => new external_value(PARAM_INT,  'Echoed form id'),
            'userid'       => new external_value(PARAM_INT,  'Echoed user id'),
        ]);
    }
}
