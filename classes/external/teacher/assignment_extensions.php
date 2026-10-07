<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * WS para que el docente cree, liste o borre prorrogas individuales de
 * entrega en actividades tipo Assign desde el teacher_dashboard.
 *
 * @package    local_grupomakro_core
 */

namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/assignment_extension_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use local_grupomakro_core\local\assignment_extension_manager;

class assignment_extensions extends external_api {

    public static function set_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignid'  => new external_value(PARAM_INT, 'mdl_assign.id', VALUE_REQUIRED),
            'userid'    => new external_value(PARAM_INT, 'mdl_user.id del estudiante', VALUE_REQUIRED),
            'duedate'   => new external_value(PARAM_INT, 'Nuevo due date (Unix timestamp en el FUTURO)', VALUE_REQUIRED),
            'reason'    => new external_value(PARAM_TEXT, 'Razon opcional', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Crea/actualiza una prorroga individual para un estudiante en una
     * actividad. El cap del servicio esta vacio y el chequeo real es a nivel
     * de funcion (mismo patron que el resto de las WS teacher).
     */
    public static function set(int $assignid, int $userid, int $duedate, string $reason = ''): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::set_parameters(), [
            'assignid' => $assignid,
            'userid'   => $userid,
            'duedate'  => $duedate,
            'reason'   => $reason,
        ]);

        $assign = $DB->get_record('assign', ['id' => $params['assignid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assign->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:manageoverrides', $context);

        if ($params['duedate'] <= time()) {
            return ['status' => 'error', 'message' => 'La fecha debe estar en el futuro.'];
        }

        try {
            $result = assignment_extension_manager::set_user_override(
                (int)$params['assignid'],
                (int)$params['userid'],
                (int)$params['duedate'],
                (int)$USER->id,
                (string)$params['reason']
            );
            return [
                'status'      => 'success',
                'message'     => 'Prorroga aplicada.',
                'override_id' => $result['override_id'],
                'old_duedate' => $result['old_duedate'] ?? 0,
                'new_duedate' => $result['new_duedate'],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'No se pudo aplicar la prorroga: ' . $e->getMessage()];
        }
    }

    public static function set_returns(): external_single_structure {
        return new external_single_structure([
            'status'      => new external_value(PARAM_TEXT, 'success o error'),
            'message'     => new external_value(PARAM_TEXT, 'Mensaje de resultado'),
            'override_id' => new external_value(PARAM_INT, 'ID del row creado o actualizado', VALUE_DEFAULT, 0),
            'old_duedate' => new external_value(PARAM_INT, 'Due date previo (0 si no existia)', VALUE_DEFAULT, 0),
            'new_duedate' => new external_value(PARAM_INT, 'Nuevo due date', VALUE_DEFAULT, 0),
        ]);
    }

    public static function list_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'mdl_assign.id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Lista los overrides vigentes + historial de auditoria de una actividad.
     */
    public static function list_overrides(int $assignid): array {
        global $DB;
        $params = self::validate_parameters(self::list_parameters(), ['assignid' => $assignid]);

        $assign = $DB->get_record('assign', ['id' => $params['assignid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assign->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:manageoverrides', $context);

        $info = assignment_extension_manager::list_extensions((int)$params['assignid']);

        $overrides = [];
        foreach ($info['overrides'] as $o) {
            $overrides[] = [
                'override_id' => $o['override_id'],
                'userid'      => $o['userid'],
                'user_name'   => $o['user_name'],
                'user_email'  => $o['user_email'],
                'duedate'     => $o['duedate'],
            ];
        }
        $history = [];
        foreach ($info['history'] as $h) {
            $history[] = [
                'id'          => $h['id'],
                'userid'      => $h['userid'],
                'user_name'   => $h['user_name'],
                'old_duedate' => $h['old_duedate'] ?? 0,
                'new_duedate' => $h['new_duedate'],
                'reason'      => $h['reason'],
                'actor_name'  => $h['actor_name'],
                'timecreated' => $h['timecreated'],
            ];
        }
        return [
            'assign_id'        => $info['assign_id'],
            'assign_name'      => $info['assign_name'],
            'default_duedate'  => $info['default_duedate'] ?? 0,
            'overrides'        => $overrides,
            'history'          => $history,
        ];
    }

    public static function list_overrides_returns(): external_single_structure {
        return new external_single_structure([
            'assign_id'       => new external_value(PARAM_INT, 'mdl_assign.id'),
            'assign_name'     => new external_value(PARAM_TEXT, 'Nombre de la actividad'),
            'default_duedate' => new external_value(PARAM_INT, 'Due date por defecto (0 si no tiene)', VALUE_DEFAULT, 0),
            'overrides' => new external_multiple_structure(
                new external_single_structure([
                    'override_id' => new external_value(PARAM_INT, 'ID'),
                    'userid'      => new external_value(PARAM_INT, 'ID usuario'),
                    'user_name'   => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'user_email'  => new external_value(PARAM_TEXT, 'Email'),
                    'duedate'     => new external_value(PARAM_INT, 'Due date vigente'),
                ])
            ),
            'history' => new external_multiple_structure(
                new external_single_structure([
                    'id'          => new external_value(PARAM_INT, 'ID'),
                    'userid'      => new external_value(PARAM_INT, 'ID usuario'),
                    'user_name'   => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'old_duedate' => new external_value(PARAM_INT, 'Due date previo (0 si no existia)'),
                    'new_duedate' => new external_value(PARAM_INT, 'Nuevo due date (0 si fue borrado)'),
                    'reason'      => new external_value(PARAM_TEXT, 'Razon'),
                    'actor_name'  => new external_value(PARAM_TEXT, 'Docente que aplico el cambio'),
                    'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
                ])
            ),
        ]);
    }

    public static function delete_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'mdl_assign.id', VALUE_REQUIRED),
            'userid'   => new external_value(PARAM_INT, 'mdl_user.id del estudiante', VALUE_REQUIRED),
        ]);
    }

    public static function delete_override(int $assignid, int $userid): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::delete_parameters(), [
            'assignid' => $assignid,
            'userid'   => $userid,
        ]);

        $assign = $DB->get_record('assign', ['id' => $params['assignid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assign->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:manageoverrides', $context);

        $deleted = assignment_extension_manager::delete_user_override(
            (int)$params['assignid'],
            (int)$params['userid'],
            (int)$USER->id
        );
        return [
            'status'  => $deleted ? 'success' : 'error',
            'message' => $deleted ? 'Prorroga borrada.' : 'No se encontro la prorroga.',
        ];
    }

    public static function delete_override_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success o error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje de resultado'),
        ]);
    }

    public static function list_course_assignments_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'mdl_course.id de la clase', VALUE_REQUIRED),
        ]);
    }

    /**
     * Lista las actividades Assign de un curso (para el dropdown del modal).
     */
    public static function list_course_assignments(int $courseid): array {
        global $DB;
        $params = self::validate_parameters(self::list_course_assignments_parameters(), ['courseid' => $courseid]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('mod/assign:manageoverrides', $context);

        $rows = assignment_extension_manager::list_course_assignments((int)$params['courseid']);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'cmid'     => $r['cmid'],
                'assignid' => $r['assignid'],
                'name'     => $r['name'],
                'duedate'  => $r['duedate'] ?? 0,
            ];
        }
        return ['assignments' => $out];
    }

    public static function list_course_assignments_returns(): external_single_structure {
        return new external_single_structure([
            'assignments' => new external_multiple_structure(
                new external_single_structure([
                    'cmid'     => new external_value(PARAM_INT, 'course module id'),
                    'assignid' => new external_value(PARAM_INT, 'mdl_assign id'),
                    'name'     => new external_value(PARAM_TEXT, 'Nombre de la actividad'),
                    'duedate'  => new external_value(PARAM_INT, 'Due date por defecto (0 si no tiene)'),
                ])
            ),
        ]);
    }

    public static function list_course_students_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'mdl_course.id de la clase', VALUE_REQUIRED),
            'assignid' => new external_value(PARAM_INT, 'mdl_assign.id (opcional, anade override_duedate si se pasa)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Lista los estudiantes matriculados del curso, opcionalmente con su
     * override individual vigente para una asignacion.
     */
    public static function list_course_students(int $courseid, int $assignid = 0): array {
        global $DB;
        $params = self::validate_parameters(self::list_course_students_parameters(), [
            'courseid' => $courseid,
            'assignid' => $assignid,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('mod/assign:manageoverrides', $context);

        $rows = assignment_extension_manager::list_course_students(
            (int)$params['courseid'],
            (int)$params['assignid'] > 0 ? (int)$params['assignid'] : null
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'userid'           => $r['userid'],
                'user_name'        => $r['user_name'],
                'user_email'       => $r['user_email'],
                'override_duedate' => $r['override_duedate'] ?? 0,
            ];
        }
        return ['students' => $out];
    }

    public static function list_course_students_returns(): external_single_structure {
        return new external_single_structure([
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'userid'           => new external_value(PARAM_INT, 'ID'),
                    'user_name'        => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'user_email'       => new external_value(PARAM_TEXT, 'Email'),
                    'override_duedate' => new external_value(PARAM_INT, 'Due date override vigente (0 si no tiene)'),
                ])
            ),
        ]);
    }
}