<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class activity_group_update_members extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'groupid' => new \external_value(PARAM_INT, 'gmk_activity_group.id', VALUE_REQUIRED),
            'add'     => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'userid'),
                'Estudiantes a anadir',
                VALUE_DEFAULT,
                []
            ),
            'remove'  => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'userid'),
                'Estudiantes a sacar',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(int $groupid, array $add = [], array $remove = []): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'groupid' => $groupid,
            'add'     => $add,
            'remove'  => $remove,
        ]);
        $groupid = (int)$params['groupid'];
        $add     = array_values(array_filter(array_map('intval', (array)$params['add']), function ($x) { return $x > 0; }));
        $remove  = array_values(array_filter(array_map('intval', (array)$params['remove']), function ($x) { return $x > 0; }));

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

        $transaction = $DB->start_delegated_transaction();
        try {
            $added = 0; $removed = 0; $rejected = [];

            foreach ($remove as $uid) {
                $DB->delete_records('gmk_activity_group_member',
                    ['groupid' => $groupid, 'userid' => $uid]);
                $removed++;
            }

            if (!empty($add)) {
                $count = $DB->count_records('gmk_activity_group_member', ['groupid' => $groupid]);
                foreach ($add as $uid) {
                    if ($count >= (int)$group->maxmembers) {
                        $rejected[] = $uid;
                        continue;
                    }
                    // Si ya estaba en este grupo, no contar.
                    $already = $DB->get_record('gmk_activity_group_member',
                        ['groupid' => $groupid, 'userid' => $uid], 'id', IGNORE_MISSING);
                    if ($already) {
                        continue;
                    }
                    // Si esta en OTRO grupo de la misma actividad, sacarlo.
                    $other = $DB->get_record_sql(
                        "SELECT agm.groupid
                           FROM {gmk_activity_group_member} agm
                           JOIN {gmk_activity_group} ag ON ag.id = agm.groupid
                          WHERE ag.cmid = :cmid AND agm.userid = :uid AND ag.id <> :gid",
                        ['cmid' => (int)$group->cmid, 'uid' => $uid, 'gid' => $groupid],
                        IGNORE_MISSING
                    );
                    if ($other) {
                        $DB->delete_records('gmk_activity_group_member',
                            ['groupid' => (int)$other->groupid, 'userid' => $uid]);
                    }
                    $m = new \stdClass();
                    $m->groupid   = $groupid;
                    $m->userid    = $uid;
                    $m->joined_at = time();
                    $DB->insert_record('gmk_activity_group_member', $m);
                    $added++;
                    $count++;
                }
            }

            $DB->update_record('gmk_activity_group', (object)[
                'id'           => $groupid,
                'timemodified' => time(),
                'usermodified' => (int)$USER->id,
            ]);

            $DB->commit_delegated_transaction($transaction);
        } catch (\Throwable $e) {
            $DB->rollback_delegated_transaction($transaction);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return [
            'status'   => 'success',
            'message'  => 'Composicion actualizada',
            'added'    => $added,
            'removed'  => $removed,
            'rejected' => $rejected,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'status'   => new \external_value(PARAM_TEXT, 'success|error'),
            'message'  => new \external_value(PARAM_TEXT, 'Mensaje'),
            'added'    => new \external_value(PARAM_INT, 'Cantidad de miembros anadidos'),
            'removed'  => new \external_value(PARAM_INT, 'Cantidad de miembros sacados'),
            'rejected' => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'userid rechazado por cupo'),
                'IDs rechazados porque el grupo estaba lleno'
            ),
        ]);
    }
}
