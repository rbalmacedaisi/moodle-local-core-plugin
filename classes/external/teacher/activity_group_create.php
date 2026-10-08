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

class activity_group_create extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'       => new external_value(PARAM_INT, 'course_modules.id', VALUE_REQUIRED),
            'modname'    => new external_value(PARAM_ALPHA, 'assign o quiz', VALUE_REQUIRED),
            'name'       => new external_value(PARAM_TEXT, 'Nombre del grupo', VALUE_REQUIRED),
            'maxmembers' => new external_value(PARAM_INT, 'Cupo maximo (default 5)', VALUE_DEFAULT, 5),
            'mode'       => new external_value(PARAM_ALPHA, 'open o fixed (default open)', VALUE_DEFAULT, 'open'),
            'memberids'  => new external_multiple_structure(
                new external_value(PARAM_INT, 'userid'),
                'Estudiantes a incluir al crear (override docente)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(int $cmid, string $modname, string $name, int $maxmembers = 5,
                                    string $mode = 'open', array $memberids = []): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'modname'    => $modname,
            'name'       => $name,
            'maxmembers' => $maxmembers,
            'mode'       => $mode,
            'memberids'  => $memberids,
        ]);
        $cmid       = (int)$params['cmid'];
        $modname    = (string)$params['modname'];
        $name       = trim((string)$params['name']);
        $maxmembers = max(1, (int)$params['maxmembers']);
        $mode       = in_array((string)$params['mode'], ['open', 'fixed'], true)
                        ? (string)$params['mode'] : 'open';
        $memberids  = array_map('intval', (array)$params['memberids']);
        $memberids  = array_values(array_filter($memberids, function ($x) {
            return $x > 0;
        }));

        if (!in_array($modname, ['assign', 'quiz'], true)) {
            return ['status' => 'error', 'message' => 'modname invalido', 'groupid' => 0];
        }
        if ($name === '') {
            return ['status' => 'error', 'message' => 'El nombre del grupo es obligatorio', 'groupid' => 0];
        }
        if (count($memberids) > $maxmembers) {
            return [
                'status'  => 'error',
                'message' => 'No puedes asignar mas miembros que el cupo (' . $maxmembers . ').',
                'groupid' => 0,
            ];
        }

        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return ['status' => 'error', 'message' => 'Actividad no encontrada', 'groupid' => 0];
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        $cap = $modname === 'assign' ? 'mod/assign:grade' : 'mod/quiz:grade';
        require_capability($cap, $context);

        // Determinar colorindex: rotar 1..5 segun cuantos grupos hay ya.
        $existingcount = $DB->count_records('gmk_activity_group',
            ['cmid' => $cmid, 'modname' => $modname]);
        $colorindex = (($existingcount) % 5) + 1;

        // Resolver classid: usar el de la primera fila existente o el
        // derivado de la primera coincidencia en gmk_class por courseid.
        $classid = (int)$DB->get_field('gmk_activity_group', 'classid',
            ['cmid' => $cmid, 'modname' => $modname], IGNORE_MISSING);
        if ($classid <= 0) {
            $classid = (int)$DB->get_field('gmk_class', 'id',
                ['corecourseid' => (int)$cm->course, 'closed' => 0], IGNORE_MISSING);
        }

        $rec = new \stdClass();
        $rec->cmid         = $cmid;
        $rec->modname      = $modname;
        $rec->classid      = $classid;
        $rec->name         = $name;
        $rec->maxmembers   = $maxmembers;
        $rec->mode         = $mode;
        $rec->colorindex   = $colorindex;
        $rec->usermodified = (int)$USER->id;
        $rec->timecreated  = time();
        $rec->timemodified = time();

        $transaction = $DB->start_delegated_transaction();
        try {
            $groupid = (int)$DB->insert_record('gmk_activity_group', $rec);
            if (!empty($memberids)) {
                // Si algun miembro ya estaba en OTRO grupo de la misma actividad,
                // sacarlo de ahi antes de anadirlo al nuevo.
                foreach ($memberids as $uid) {
                    $other = $DB->get_record_sql(
                        "SELECT agm.groupid
                           FROM {gmk_activity_group_member} agm
                           JOIN {gmk_activity_group} ag ON ag.id = agm.groupid
                          WHERE ag.cmid = :cmid AND agm.userid = :uid AND ag.id <> :gid",
                        ['cmid' => $cmid, 'uid' => $uid, 'gid' => $groupid],
                        IGNORE_MISSING
                    );
                    if ($other) {
                        $DB->delete_records('gmk_activity_group_member',
                            ['groupid' => (int)$other->groupid, 'userid' => $uid]);
                    }
                    $m = new \stdClass();
                    $m->groupid   = $groupid;
                    $m->userid    = (int)$uid;
                    $m->joined_at = time();
                    $DB->insert_record('gmk_activity_group_member', $m);
                }
            }
            $DB->commit_delegated_transaction($transaction);
        } catch (\Throwable $e) {
            $DB->rollback_delegated_transaction($transaction);
            return ['status' => 'error', 'message' => $e->getMessage(), 'groupid' => 0];
        }

        return ['status' => 'success', 'message' => 'Grupo creado', 'groupid' => $groupid];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success|error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje'),
            'groupid' => new external_value(PARAM_INT, 'ID del grupo creado'),
        ]);
    }
}
