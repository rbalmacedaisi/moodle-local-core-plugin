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
 * Admin: upsert a wellness dynamic form (RF-06 / RF-09.2).
 *
 * Receives title, description, eventid (optional, 0 = reusable form),
 * the schema_json string and the cover_path (already-uploaded via
 * admin_upload_wellness_image). Validation of the schema (field names,
 * types, options) is delegated to wellness_dynamic_form_manager::upsert,
 * which throws moodle_exception on any structural problem.
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
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_dynamic_form_manager.php');

class admin_save_dynamic_form extends external_api {

    public static function execute_parameters() {
        // OJO con el ORDEN: Moodle valida los argumentos por nombre, los ordena
        // segun ESTA declaracion y luego invoca execute() POSICIONALMENTE. Si
        // este orden no coincide con el de la firma de execute(), cada valor
        // aterriza en la variable equivocada. Debe ir igual que la firma.
        return new external_function_parameters([
            'title'       => new external_value(PARAM_TEXT, 'Form title', VALUE_REQUIRED),
            'schema_json' => new external_value(PARAM_RAW,  'JSON with shape {fields:[...]}', VALUE_REQUIRED),
            'id'          => new external_value(PARAM_INT,  '0 to create, otherwise the form id', VALUE_DEFAULT, 0),
            'description' => new external_value(PARAM_RAW,  'Free-text description', VALUE_DEFAULT, ''),
            'eventid'     => new external_value(PARAM_INT,  'Event id (0 = standalone / reusable)', VALUE_DEFAULT, 0),
            'cover_path'  => new external_value(PARAM_TEXT, 'Absolute pluginfile URL (or empty)', VALUE_DEFAULT, ''),
            'active'      => new external_value(PARAM_BOOL, 'Active flag', VALUE_DEFAULT, true),
        ]);
    }

    public static function execute(
        $title, $schema_json,
        $id = 0, $description = '', $eventid = 0,
        $cover_path = '', $active = true
    ) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'title' => $title, 'description' => $description,
            'eventid' => $eventid, 'schema_json' => $schema_json,
            'cover_path' => $cover_path, 'active' => $active,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        try {
            $newid = \local_grupomakro_core\local\wellness_dynamic_form_manager::upsert($params, (int)$USER->id);
        } catch (\moodle_exception $e) {
            // Propagate the lang-string code so the client can render the
            // matching toast without having to translate the message.
            throw new Exception($e->errorcode ?: $e->getMessage());
        }
        return ['ok' => true, 'id' => (int)$newid];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True on success'),
            'id' => new external_value(PARAM_INT,  'Form id (created or updated)'),
        ]);
    }
}
