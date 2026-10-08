<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class activity_group_delete extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'groupid'     => new external_value(PARAM_INT, 'gmk_activity_group.id', VALUE_REQUIRED),
            'force'       => new external_value(PARAM_BOOL, 'Si true, borra incluso con miembros',
                                                VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $groupid, bool $force = false): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'groupid' => $groupid,
            'force'   => $force,
        ]);
        $groupid = (int)$params['groupid'];
        $force   = (bool)$params['force'];

        $group = $DB->get_record('gmk_activity_group', ['id' => $groupid], '*', MUST_EXIST);
        if (!in_array($group->modname, ['assign', 'quiz'], true)) {
            return ['status' => 'error', 'message' => 'modname invalido'];
        }
        $cm = get_coursemodule_from_id($group->modname, (int)$group->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return ['status' => 'error', 'message' => 'Actividad no encontrada'];
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        $cap = $group->modname === 'assign' ? 'mod/assign:grade' : 'mod/quiz:grade';
        require_capability($cap, $context);

        $membercount = $DB->count_records('gmk_activity_group_member', ['groupid' => $groupid]);
        if ($membercount > 0 && !$force) {
            return [
                'status'  => 'error',
                'message' => 'El grupo tiene ' . $membercount
                           . ' miembro(s). Marca "forzar borrado" si quieres eliminarlo de todos modos.',
            ];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($force) {
                $DB->delete_records('gmk_activity_group_member', ['groupid' => $groupid]);
            }
            $DB->delete_records('gmk_activity_group', ['id' => $groupid]);
            $DB->commit_delegated_transaction($transaction);
        } catch (\Throwable $e) {
            $DB->rollback_delegated_transaction($transaction);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return ['status' => 'success', 'message' => 'Grupo eliminado'];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success|error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje'),
        ]);
    }
}
