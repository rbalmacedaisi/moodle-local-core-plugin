<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

/**
 * Lista los estudiantes matriculados en el curso de una actividad
 * (assign o quiz) y que tienen capacidad de submit/attempt. Es la lista
 * que el docente ve en el panel de gestion de grupos al crear un grupo
 * o agregar miembros.
 *
 * Antes (20261001080), el panel mostraba un input de texto libre y
 * pedia al docente que tipeara los nombres manualmente, lo cual no
 * escalaba para clases con 30+ estudiantes. Este endpoint resuelve
 * la lista desde mdl_user_enrolments + mdl_role_assignments.
 *
 * Patrón de entrada: igual que los otros activity_group_*, el dispatch
 * de ajax.php envia los parametros en un unico "args" JSON:
 *   { "cmid": int, "modname": "assign|quiz" }
 */
class activity_group_list_students extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'args' => new \external_value(PARAM_RAW, 'JSON con cmid y modname', VALUE_REQUIRED),
        ]);
    }

    public static function execute(string $args): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'args' => $args,
        ]);

        $data = json_decode($params['args'], true);
        if (!is_array($data)) {
            return ['status' => 'error', 'message' => 'invalidjson', 'students' => []];
        }
        $cmid    = (int)($data['cmid'] ?? 0);
        $modname = (string)($data['modname'] ?? '');
        if ($cmid <= 0 || !in_array($modname, ['assign', 'quiz'], true)) {
            return ['status' => 'error', 'message' => 'Parametros invalidos', 'students' => []];
        }

        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return ['status' => 'error', 'message' => 'Actividad no encontrada', 'students' => []];
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        $cap = $modname === 'assign' ? 'mod/assign:grade' : 'mod/quiz:grade';
        require_capability($cap, $context);

        $coursecontext = \context_course::instance($cm->course);
        $submitcap = $modname === 'assign' ? 'mod/assign:submit' : 'mod/quiz:attempt';
        $students = get_enrolled_users(
            $coursecontext,
            $submitcap,
            0,
            'u.id, u.firstname, u.lastname, u.email',
            'u.lastname, u.firstname'
        );

        $out = [];
        foreach ($students as $s) {
            $out[] = [
                'userid'    => (int)$s->id,
                'fullname'  => trim($s->firstname . ' ' . $s->lastname),
                'email'     => (string)$s->email,
            ];
        }

        return [
            'status'   => 'success',
            'message'  => '',
            'students' => $out,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'status'   => new \external_value(PARAM_TEXT, 'success|error'),
            'message'  => new \external_value(PARAM_TEXT, 'Mensaje'),
            'students' => new \external_multiple_structure(
                new \external_single_structure([
                    'userid'   => new \external_value(PARAM_INT, 'ID del usuario'),
                    'fullname' => new \external_value(PARAM_TEXT, 'Nombre completo'),
                    'email'    => new \external_value(PARAM_TEXT, 'Email'),
                ]),
                'Estudiantes matriculados con permiso de submit/attempt'
            ),
        ]);
    }
}
