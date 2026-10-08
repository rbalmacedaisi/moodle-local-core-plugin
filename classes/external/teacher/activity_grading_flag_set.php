<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

/**
 * Marca una actividad como "permite calificacion grupal". Pensado para ser
 * llamado por create_express_activity() justo despues de crear el course_module.
 *
 * NO se permite modificar el flag despues (decision de producto): las
 * actividades existentes quedan sin flag y se califican individualmente.
 * Si el cmid ya tiene un flag se respeta el original (idempotente).
 */
class activity_grading_flag_set extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'       => new external_value(PARAM_INT, 'course_modules.id', VALUE_REQUIRED),
            'modname'    => new external_value(PARAM_ALPHA, 'assign o quiz', VALUE_REQUIRED),
            'enabled'    => new external_value(PARAM_INT, '0 o 1', VALUE_DEFAULT, 1),
            'mode'       => new external_value(PARAM_ALPHA, 'open o fixed', VALUE_DEFAULT, 'open'),
            'maxmembers' => new external_value(PARAM_INT, 'Cupo', VALUE_DEFAULT, 5),
        ]);
    }

    public static function execute(int $cmid, string $modname, int $enabled = 1,
                                    string $mode = 'open', int $maxmembers = 5): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'modname'    => $modname,
            'enabled'    => $enabled,
            'mode'       => $mode,
            'maxmembers' => $maxmembers,
        ]);
        $cmid       = (int)$params['cmid'];
        $modname    = (string)$params['modname'];
        $enabled    = ((int)$params['enabled']) ? 1 : 0;
        $mode       = in_array((string)$params['mode'], ['open', 'fixed'], true)
                        ? (string)$params['mode'] : 'open';
        $maxmembers = max(1, (int)$params['maxmembers']);

        if (!in_array($modname, ['assign', 'quiz'], true)) {
            return ['status' => 'error', 'message' => 'modname invalido'];
        }
        if ($enabled === 0) {
            return ['status' => 'success', 'message' => 'No se creo flag (enabled=0)'];
        }

        // Verificar que el cmid existe.
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id', MUST_EXIST);

        // Idempotente: si ya hay fila, respeta el original.
        $existing = $DB->get_record('gmk_activity_grading_flag', ['cmid' => $cmid], '*', IGNORE_MISSING);
        if ($existing) {
            return ['status' => 'success', 'message' => 'Flag ya existia (idempotente)'];
        }

        $rec = new \stdClass();
        $rec->cmid        = $cmid;
        $rec->modname     = $modname;
        $rec->enabled     = $enabled;
        $rec->mode        = $mode;
        $rec->maxmembers  = $maxmembers;
        $rec->timecreated = time();
        $DB->insert_record('gmk_activity_grading_flag', $rec);

        return ['status' => 'success', 'message' => 'Flag creado'];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success|error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje'),
        ]);
    }
}
