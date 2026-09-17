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

class activity_group_list extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'course_modules.id de la actividad', VALUE_REQUIRED),
            'modname' => new external_value(PARAM_ALPHA, 'assign o quiz', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $cmid, string $modname): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'modname' => $modname,
        ]);
        $cmid    = (int)$params['cmid'];
        $modname = (string)$params['modname'];

        if (!in_array($modname, ['assign', 'quiz'], true)) {
            return ['status' => 'error', 'message' => 'modname invalido', 'flag' => null, 'groups' => []];
        }

        // Permisos: el docente debe poder calificar la actividad.
        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return ['status' => 'error', 'message' => 'Actividad no encontrada', 'flag' => null, 'groups' => []];
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        $cap = $modname === 'assign' ? 'mod/assign:grade' : 'mod/quiz:grade';
        require_capability($cap, $context);

        $flag = gmk_get_activity_grading_flag($cmid);
        $payload = gmk_get_activity_groups($cmid, $modname, null);

        return [
            'status'  => 'success',
            'message' => '',
            'flag'    => $flag ? [
                'cmid'       => (int)$flag->cmid,
                'modname'    => (string)$flag->modname,
                'enabled'    => (int)$flag->enabled,
                'mode'       => (string)$flag->mode,
                'maxmembers' => (int)$flag->maxmembers,
            ] : null,
            'groups'  => $payload['groups'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success|error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje'),
            'flag'    => new external_single_structure([
                'cmid'       => new external_value(PARAM_INT, 'cmid'),
                'modname'    => new external_value(PARAM_TEXT, 'assign|quiz'),
                'enabled'    => new external_value(PARAM_INT, '0|1'),
                'mode'       => new external_value(PARAM_TEXT, 'open|fixed'),
                'maxmembers' => new external_value(PARAM_INT, 'cupo'),
            ], 'Flag de habilitacion, null si la actividad no fue creada con la opcion', VALUE_OPTIONAL),
            'groups'  => new external_multiple_structure(
                new external_single_structure([
                    'id'         => new external_value(PARAM_INT, 'group id'),
                    'cmid'       => new external_value(PARAM_INT, 'cmid'),
                    'modname'    => new external_value(PARAM_TEXT, 'assign|quiz'),
                    'classid'    => new external_value(PARAM_INT, 'gmk_class.id'),
                    'name'       => new external_value(PARAM_TEXT, 'Nombre del grupo'),
                    'maxmembers' => new external_value(PARAM_INT, 'Cupo'),
                    'mode'       => new external_value(PARAM_TEXT, 'open|fixed'),
                    'colorindex' => new external_value(PARAM_INT, '1..5'),
                    'membercount'=> new external_value(PARAM_INT, 'Cantidad actual de miembros'),
                    'isfull'     => new external_value(PARAM_BOOL, 'membercount >= maxmembers'),
                    'isempty'    => new external_value(PARAM_BOOL, 'membercount == 0'),
                    'members'    => new external_multiple_structure(
                        new external_single_structure([
                            'userid'    => new external_value(PARAM_INT, 'userid'),
                            'fullname'  => new external_value(PARAM_TEXT, 'Nombre completo'),
                            'email'     => new external_value(PARAM_TEXT, 'Email'),
                            'avatar'    => new external_value(PARAM_URL,  'Avatar URL'),
                            'joined_at' => new external_value(PARAM_INT,  'Timestamp de union'),
                        ])
                    ),
                ])
            ),
        ]);
    }
}
