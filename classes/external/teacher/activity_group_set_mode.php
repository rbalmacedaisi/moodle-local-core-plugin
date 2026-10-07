<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class activity_group_set_mode extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'groupid'    => new external_value(PARAM_INT, 'gmk_activity_group.id', VALUE_REQUIRED),
            'mode'       => new external_value(PARAM_ALPHA, 'open o fixed', VALUE_REQUIRED),
            'maxmembers' => new external_value(PARAM_INT, 'Cupo (0 = no cambiar)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $groupid, string $mode, int $maxmembers = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'groupid'    => $groupid,
            'mode'       => $mode,
            'maxmembers' => $maxmembers,
        ]);
        $groupid    = (int)$params['groupid'];
        $mode       = (string)$params['mode'];
        $maxmembers = (int)$params['maxmembers'];

        if (!in_array($mode, ['open', 'fixed'], true)) {
            return ['status' => 'error', 'message' => 'mode invalido'];
        }

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

        $upd = new stdClass();
        $upd->id           = $groupid;
        $upd->mode         = $mode;
        $upd->timemodified = time();
        $upd->usermodified = (int)$USER->id;
        if ($maxmembers > 0) {
            $upd->maxmembers = $maxmembers;
        }
        $DB->update_record('gmk_activity_group', $upd);

        return ['status' => 'success', 'message' => 'Grupo actualizado'];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success|error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje'),
        ]);
    }
}
