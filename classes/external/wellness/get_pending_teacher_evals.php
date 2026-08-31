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
 * Sesiones de clase que el estudiante todavia puede evaluar (RF-08). Excluye clases de modulo y sesiones de revalida.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_teacher_eval_manager.php');

use local_grupomakro_core\local\wellness_teacher_eval_manager as MGR;

class get_pending_teacher_evals extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $out = [];
        foreach (MGR::get_pending_for_student((int)$USER->id) as $r) {
            $out[] = [
                'sessionid'    => (int)$r->sessionid,
                'classid'      => (int)$r->classid,
                'classname'    => (string)$r->classname,
                'instructorid' => (int)$r->instructorid,
                'teacher_name' => (string)$r->teacher_name,
                'sessiondate'  => (int)$r->sessdate,
            ];
        }
        return ['pending' => $out];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'pending' => new external_multiple_structure(new external_single_structure([
                'sessionid'    => new external_value(PARAM_INT,  'Id de la sesion de asistencia'),
                'classid'      => new external_value(PARAM_INT,  'Id de la clase'),
                'classname'    => new external_value(PARAM_TEXT, 'Nombre de la clase'),
                'instructorid' => new external_value(PARAM_INT,  'Id del docente evaluado'),
                'teacher_name' => new external_value(PARAM_TEXT, 'Nombre del docente'),
                'sessiondate'  => new external_value(PARAM_INT,  'Fecha de la sesion (unix ts)'),
            ])),
        ]);
    }
}
