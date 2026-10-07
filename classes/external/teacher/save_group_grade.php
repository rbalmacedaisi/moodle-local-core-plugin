<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;

/**
 * Calificacion grupal de una TAREA (mod/assign).
 *
 * Flujo:
 *   1) Docencia abre QuickGrader para una entrega X que pertenece a un grupo G.
 *   2) QuickGrader hace pre-flight: llama SIN confirm=1.
 *   3) Backend revisa notas previas de los miembros del grupo:
 *      - Si ninguno tiene calificacion previa: responde status='ok' y aplica
 *        la nota a todos inmediatamente.
 *      - Si alguno ya estaba calificado: responde status='warning' con la lista
 *        de usuarios ya calificados y sus notas previas. NO escribe nada.
 *   4) El frontend muestra el modal de confirmacion (Atras / Continuar).
 *      Si Continuar: re-llama con confirm=1 y esta vez se sobrescribe.
 *
 * El flag confirm=1 es el unico interruptor para diferenciar entre los dos
 * modos. Es deliberadamente explicito (no inferido por el tamano del payload
 * ni por la presencia del flag alreadyGraded) para que el cliente nunca pueda
 * pisaar notas sin querer.
 */
class save_group_grade extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'assign.id', VALUE_REQUIRED),
            'groupid'      => new external_value(PARAM_INT, 'gmk_activity_group.id', VALUE_REQUIRED),
            'grade'        => new external_value(PARAM_FLOAT, 'Calificacion a aplicar (0-100)', VALUE_REQUIRED),
            'feedback'     => new external_value(PARAM_RAW, 'Feedback comun', VALUE_DEFAULT, ''),
            'confirm'      => new external_value(PARAM_BOOL,
                'Re-envio confirmando la sobrescritura tras advertencia',
                VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        int $assignmentid,
        int $groupid,
        float $grade,
        string $feedback = '',
        bool $confirm = false
    ): array {
        global $DB, $CFG, $USER, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'groupid'      => $groupid,
            'grade'        => $grade,
            'feedback'     => $feedback ?? '',
            'confirm'      => $confirm,
        ]);
        $assignmentid = (int)$params['assignmentid'];
        $groupid      = (int)$params['groupid'];
        $grade        = (float)$params['grade'];
        $feedback     = (string)($params['feedback'] ?? '');
        $confirm      = (bool)$params['confirm'];

        // Permisos: mod/assign:grade sobre el cm de la tarea.
        $cm = get_coursemodule_from_instance('assign', $assignmentid);
        if (!$cm) {
            return $this->error_payload('Actividad no encontrada.');
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        $group = $DB->get_record('gmk_activity_group',
            ['id' => $groupid, 'cmid' => (int)$cm->id, 'modname' => 'assign'], '*', MUST_EXIST);

        if ($grade < 0 || $grade > 100) {
            return $this->error_payload('La calificacion debe estar entre 0 y 100.');
        }

        $existing = gmk_get_group_existing_grades($assignmentid, $groupid);
        if (empty($existing)) {
            return $this->error_payload('El grupo no tiene miembros o no se encontraron notas asociadas.');
        }

        $alreadygraded = [];
        foreach ($existing as $row) {
            if ($row['currentgrade'] !== null) {
                $u = $DB->get_record('user', ['id' => $row['userid']],
                    'id, firstname, lastname, email, picture, imagealt', MUST_EXIST);
                $alreadygraded[] = [
                    'userid'       => (int)$u->id,
                    'fullname'     => fullname($u),
                    'email'        => $u->email,
                    'currentgrade' => (float)$row['currentgrade'],
                    'timemodified' => (int)$row['timemodified'],
                ];
            }
        }

        // Pre-flight: advertir si hay notas previas y el cliente NO ha confirmado.
        if (!empty($alreadygraded) && !$confirm) {
            return [
                'status'             => 'warning',
                'message'            => 'Algunos miembros ya tienen calificacion previa. Revisa la lista y confirma la sobrescritura.',
                'alreadygradedcount' => count($alreadygraded),
                'alreadygraded'      => $alreadygraded,
                'pendingmembers'     => array_values(array_map(function ($r) use ($DB) {
                    $u = $DB->get_record('user', ['id' => $r['userid']],
                        'id, firstname, lastname, email', MUST_EXIST);
                    return [
                        'userid'   => (int)$u->id,
                        'fullname' => fullname($u),
                        'email'    => $u->email,
                    ];
                }, array_filter($existing, function ($r) {
                    return $r['currentgrade'] === null;
                }))),
                'gradedcount'        => 0,
            ];
        }

        // Aplicar la calificacion a cada miembro.
        $assignrecord = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
        $course       = $DB->get_record('course', ['id' => $assignrecord->course], '*', MUST_EXIST);
        $assign       = new \assign($context, $cm, $course);

        $gradedcount = 0; $skippedcount = 0;
        foreach ($existing as $row) {
            $userid = (int)$row['userid'];
            $data = new stdClass();
            $data->grade            = $grade;
            $data->attemptnumber    = -1;
            $data->applytoall       = 0;
            $data->addattempt       = 0;
            $data->sendstudentnotifications = false;
            if (!empty($assignrecord->markingworkflow)) {
                $data->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
            } else {
                $data->workflowstate = '';
            }
            $data->assignfeedbackcomments_editor = [
                'text'   => $feedback,
                'format' => FORMAT_HTML,
            ];

            try {
                $assign->save_grade($userid, $data);
                if ($feedback !== '') {
                    $gradeitem = $DB->get_record_sql(
                        "SELECT id FROM {grade_items}
                          WHERE itemtype = 'mod' AND itemmodule = 'assign'
                            AND iteminstance = :assignid AND courseid = :courseid",
                        ['assignid' => $assignmentid, 'courseid' => $assignrecord->course],
                        IGNORE_MISSING
                    );
                    if ($gradeitem) {
                        $current = $DB->get_record('grade_grades', [
                            'itemid' => $gradeitem->id,
                            'userid' => $userid,
                        ], 'id', IGNORE_MISSING);
                        if ($current) {
                            $DB->set_field('grade_grades', 'feedback',
                                $feedback, ['id' => $current->id]);
                            $DB->set_field('grade_grades', 'feedbackformat',
                                FORMAT_HTML, ['id' => $current->id]);
                        }
                    }
                }
                $gradedcount++;
            } catch (\Throwable $e) {
                $skippedcount++;
            }
        }

        $alreadygradedids = array_map(function ($r) { return (int)$r['userid']; }, $alreadygraded);

        return [
            'status'             => 'success',
            'message'            => 'Calificacion grupal aplicada a ' . $gradedcount . ' miembro(s).',
            'gradedcount'        => $gradedcount,
            'skippedcount'       => $skippedcount,
            'alreadygradedcount' => count($alreadygradedids),
            'alreadygraded'      => $alreadygraded,
            'pendingmembers'     => [],
        ];
    }

    private static function error_payload(string $msg): array {
        return [
            'status'             => 'error',
            'message'            => $msg,
            'alreadygradedcount' => 0,
            'alreadygraded'      => [],
            'pendingmembers'     => [],
            'gradedcount'        => 0,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'             => new external_value(PARAM_TEXT, 'success|warning|error'),
            'message'            => new external_value(PARAM_TEXT, 'Mensaje'),
            'alreadygradedcount' => new external_value(PARAM_INT, 'Cantidad con nota previa'),
            'alreadygraded'      => new external_multiple_structure(
                new external_single_structure([
                    'userid'       => new external_value(PARAM_INT, 'userid'),
                    'fullname'     => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'email'        => new external_value(PARAM_TEXT, 'Email'),
                    'currentgrade' => new external_value(PARAM_FLOAT, 'Nota previa'),
                    'timemodified' => new external_value(PARAM_INT,  'Timestamp de modificacion'),
                ])
            ),
            'pendingmembers'     => new external_multiple_structure(
                new external_single_structure([
                    'userid'   => new external_value(PARAM_INT, 'userid'),
                    'fullname' => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'email'    => new external_value(PARAM_TEXT, 'Email'),
                ])
            ),
            'gradedcount'        => new external_value(PARAM_INT, 'Miembros efectivamente calificados'),
            'skippedcount'       => new external_value(PARAM_INT, 'Miembros omitidos por error'),
        ]);
    }
}
