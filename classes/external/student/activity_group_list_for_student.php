<?php
namespace local_grupomakro_core\external\student;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

/**
 * Endpoint de LECTURA consumido por el LXPStudents para mostrar el selector
 * de grupos de la actividad. Devuelve:
 *   - flag (null si la actividad NO admite calificacion grupal)
 *   - groups[] con cada grupo + sus miembros (incluyendo el campo
 *     current_user_is_member para que el front pueda saber si ya pertenece)
 *   - user_current_group_id (0 si no pertenece a ninguno)
 */
class activity_group_list_for_student extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'cmid'    => new \external_value(PARAM_INT, 'course_modules.id', VALUE_REQUIRED),
            'modname' => new \external_value(PARAM_ALPHA, 'assign o quiz', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $cmid, string $modname): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'modname' => $modname,
        ]);
        $cmid    = (int)$params['cmid'];
        $modname = (string)$params['modname'];

        if (!in_array($modname, ['assign', 'quiz'], true)) {
            return ['flag' => null, 'groups' => [], 'user_current_group_id' => 0];
        }

        $flag = gmk_get_activity_grading_flag($cmid);
        if (!$flag || (int)$flag->enabled !== 1) {
            // Actividad no habilitada para calificacion grupal: no devolver
            // informacion (asi el front ni siquiera muestra el selector).
            return ['flag' => null, 'groups' => [], 'user_current_group_id' => 0];
        }

        $payload = gmk_get_activity_groups($cmid, $modname, (int)$USER->id);

        return [
            'flag' => [
                'cmid'       => (int)$flag->cmid,
                'modname'    => (string)$flag->modname,
                'enabled'    => (int)$flag->enabled,
                'mode'       => (string)$flag->mode,
                'maxmembers' => (int)$flag->maxmembers,
            ],
            'groups' => $payload['groups'],
            'user_current_group_id' => (int)$payload['user_current_group_id'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'flag'                 => new \external_single_structure([
                'cmid'       => new \external_value(PARAM_INT, 'cmid'),
                'modname'    => new \external_value(PARAM_TEXT, 'assign|quiz'),
                'enabled'    => new \external_value(PARAM_INT, '0|1'),
                'mode'       => new \external_value(PARAM_TEXT, 'open|fixed'),
                'maxmembers' => new \external_value(PARAM_INT, 'cupo'),
            ], 'null si la actividad no es grupal', VALUE_OPTIONAL),
            'groups'               => new \external_multiple_structure(
                new \external_single_structure([
                    'id'                     => new \external_value(PARAM_INT, 'group id'),
                    'cmid'                   => new \external_value(PARAM_INT, 'cmid'),
                    'modname'                => new \external_value(PARAM_TEXT, 'assign|quiz'),
                    'classid'                => new \external_value(PARAM_INT, 'gmk_class.id'),
                    'name'                   => new \external_value(PARAM_TEXT, 'Nombre'),
                    'maxmembers'             => new \external_value(PARAM_INT, 'Cupo'),
                    'mode'                   => new \external_value(PARAM_TEXT, 'open|fixed'),
                    'colorindex'             => new \external_value(PARAM_INT, '1..5'),
                    'membercount'            => new \external_value(PARAM_INT, 'Cantidad actual'),
                    'isfull'                 => new \external_value(PARAM_BOOL, 'Lleno'),
                    'isempty'                => new \external_value(PARAM_BOOL, 'Vacio'),
                    'current_user_is_member' => new \external_value(PARAM_BOOL, 'El usuario actual pertenece'),
                    'members'                => new \external_multiple_structure(
                        new \external_single_structure([
                            'userid'   => new \external_value(PARAM_INT, 'userid'),
                            'fullname' => new \external_value(PARAM_TEXT, 'Nombre completo'),
                            'email'    => new \external_value(PARAM_TEXT, 'Email'),
                            'avatar'   => new \external_value(PARAM_URL,  'Avatar'),
                            'joined_at'=> new \external_value(PARAM_INT,  'Timestamp'),
                        ])
                    ),
                ])
            ),
            'user_current_group_id'=> new \external_value(PARAM_INT, '0 si no pertenece a ninguno'),
        ]);
    }
}
