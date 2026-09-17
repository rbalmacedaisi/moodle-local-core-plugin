<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;

/**
 * Calificacion grupal de un CUESTIONARIO (mod/quiz).
 *
 * Misma semantica que save_group_grade pero sobre quiz attempts. La unidad
 * de calificacion aqui es el question_attempt: aplicamos la misma nota
 * a la misma pregunta (slot) de los intentos de cada miembro del grupo.
 *
 * Para cuestionarios la "calificacion grupal" suele ser menos habitual
 * (las preguntas son distintas si el cuestionario es aleatorio), asi que el
 * flujo por defecto sigue siendo "un mismo slot = una misma nota".
 */
class save_group_quiz_grade extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'groupid'  => new external_value(PARAM_INT, 'gmk_activity_group.id', VALUE_REQUIRED),
            'slot'     => new external_value(PARAM_INT, 'Numero de slot/pregunta', VALUE_REQUIRED),
            'mark'     => new external_value(PARAM_FLOAT, 'Puntaje a aplicar', VALUE_REQUIRED),
            'comment'  => new external_value(PARAM_RAW, 'Comentario comun', VALUE_DEFAULT, ''),
            'confirm'  => new external_value(PARAM_BOOL,
                'Re-envio confirmando la sobrescritura tras advertencia',
                VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        int $groupid,
        int $slot,
        float $mark,
        string $comment = '',
        bool $confirm = false
    ): array {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'groupid' => $groupid,
            'slot'    => $slot,
            'mark'    => $mark,
            'comment' => $comment ?? '',
            'confirm' => $confirm,
        ]);
        $groupid = (int)$params['groupid'];
        $slot    = (int)$params['slot'];
        $mark    = (float)$params['mark'];
        $comment = (string)($params['comment'] ?? '');
        $confirm = (bool)$params['confirm'];

        $group = $DB->get_record('gmk_activity_group',
            ['id' => $groupid, 'modname' => 'quiz'], '*', MUST_EXIST);

        $cm = get_coursemodule_from_id('quiz', (int)$group->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return $this->error_payload('Cuestionario no encontrado.');
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:grade', $context);

        // Cuestionario -> intento por miembro del grupo.
        $memberrecs = $DB->get_records('gmk_activity_group_member',
            ['groupid' => $groupid], '', 'userid');
        if (empty($memberrecs)) {
            return $this->error_payload('El grupo no tiene miembros.');
        }
        $userids = array_map('intval', array_keys($memberrecs));

        // Buscar el intento "in progress" o "finished" mas reciente por usuario.
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $inparams['quizid'] = (int)$group->cmid;
        $attempts = $DB->get_records_sql(
            "SELECT qa.id, qa.userid, qa.attempt, qa.state, qa.sumgrades, qa.timemodified
               FROM {quiz_attempts} qa
              WHERE qa.quiz = :quizid AND qa.userid $insql",
            $inparams
        );

        // Para detectar "ya calificados" usamos question_attempts con nota > 0
        // en el slot pedido. Si el grupo entero no tiene intentos, pre-flight
        // devuelve warning y el cliente aborta.
        $alreadygraded = [];
        $pendingmembers = [];
        $userattempts = [];
        foreach ($userids as $uid) {
            $ua = null;
            foreach ($attempts as $a) {
                if ((int)$a->userid === $uid) {
                    $ua = $a;
                    break;
                }
            }
            if (!$ua) {
                $u = $DB->get_record('user', ['id' => $uid],
                    'id, firstname, lastname, email', MUST_EXIST);
                $pendingmembers[] = [
                    'userid'   => $uid,
                    'fullname' => fullname($u),
                    'email'    => $u->email,
                ];
                continue;
            }
            $userattempts[$uid] = $ua;
            // Buscar la pregunta y su nota actual.
            $qa = $DB->get_record_sql(
                "SELECT qa.id, qa.questionid, qa.maxmark, qas.id AS stateid,
                        qas.manualmark, qas.fraction, qas.state
                   FROM {question_attempts} qa
                   JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                  WHERE qa.questionusageid = :quid
                    AND qa.slot = :slot
                    AND qas.sequencenumber = (
                        SELECT MAX(sequencenumber) FROM {question_attempt_steps}
                         WHERE questionattemptid = qa.id
                    )",
                ['quid' => (int)$ua->uniqueid, 'slot' => $slot],
                IGNORE_MISSING
            );
            $hasgrade = $qa && $qa->manualmark !== null && (float)$qa->manualmark > 0;
            if ($hasgrade) {
                $u = $DB->get_record('user', ['id' => $uid],
                    'id, firstname, lastname, email', MUST_EXIST);
                $alreadygraded[] = [
                    'userid'       => $uid,
                    'fullname'     => fullname($u),
                    'email'        => $u->email,
                    'currentgrade' => (float)$qa->manualmark,
                    'timemodified' => (int)$ua->timemodified,
                ];
            }
        }

        if (!empty($alreadygraded) && !$confirm) {
            return [
                'status'             => 'warning',
                'message'            => 'Algunos miembros ya tienen calificacion en esta pregunta.',
                'alreadygradedcount' => count($alreadygraded),
                'alreadygraded'      => $alreadygraded,
                'pendingmembers'     => $pendingmembers,
                'gradedcount'        => 0,
            ];
        }

        // Aplicar la nota via la API de Moodle para que se dispare el recompute.
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');
        require_once($CFG->dirroot . '/mod/quiz/report/default.php');

        $gradedcount = 0; $skippedcount = 0;
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($userattempts as $uid => $ua) {
                $qa = $DB->get_record_sql(
                    "SELECT qa.id, qa.questionid, qa.maxmark
                       FROM {question_attempts} qa
                      WHERE qa.questionusageid = :quid AND qa.slot = :slot",
                    ['quid' => (int)$ua->uniqueid, 'slot' => $slot],
                    MUST_EXIST
                );
                $maxmark = (float)$qa->maxmark;
                if ($maxmark <= 0) {
                    $skippedcount++;
                    continue;
                }
                $fraction = $mark / $maxmark;
                if ($fraction < 0) { $fraction = 0; }
                if ($fraction > 1) { $fraction = 1; }

                // Marcar manualmente el slot: actualizamos question_attempt_steps
                // con sequencenumber = max+1 para no perder el rastro.
                $maxseq = (int)$DB->get_field_sql(
                    "SELECT MAX(sequencenumber) FROM {question_attempt_steps}
                      WHERE questionattemptid = :qaid",
                    ['qaid' => (int)$qa->id]
                );
                $step = new stdClass();
                $step->questionattemptid = (int)$qa->id;
                $step->sequencenumber    = $maxseq + 1;
                $step->state             = 'manuallygraded';
                $step->fraction          = $fraction;
                $step->timecreated       = time();
                $step->userid            = (int)$USER->id;
                $step->manualmark        = $mark;
                $DB->insert_record('question_attempt_steps', $step);

                // Comentario (best-effort: si no existe la fila en
                // question_attempt_step_data para el slot, se ignora).
                if ($comment !== '') {
                    $commentfield = 'comments';
                    $DB->execute(
                        "INSERT INTO {question_attempt_step_data}
                            (attemptstepid, name, value)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)",
                        [$step->id, $commentfield, $comment]
                    );
                }

                // Re-sumar el intento y guardar el overall grade.
                $quizobj = new \quiz($quiz, $cm, $course);
                $newsum = $quizobj->get_quizobj()->get_sum_marks_for_quiz_attempt(
                    (int)$ua->uniqueid);
                $DB->set_field('quiz_attempts', 'sumgrades', $newsum,
                    ['id' => (int)$ua->id]);

                $gradedcount++;
            }
            $DB->commit_delegated_transaction($transaction);
        } catch (\Throwable $e) {
            $DB->rollback_delegated_transaction($transaction);
            return $this->error_payload('Error aplicando la calificacion: ' . $e->getMessage());
        }

        return [
            'status'             => 'success',
            'message'            => 'Calificacion grupal aplicada a ' . $gradedcount . ' intento(s).',
            'alreadygradedcount' => count($alreadygraded),
            'alreadygraded'      => $alreadygraded,
            'pendingmembers'     => $pendingmembers,
            'gradedcount'        => $gradedcount,
            'skippedcount'       => $skippedcount,
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
            'skippedcount'       => 0,
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
                    'timemodified' => new external_value(PARAM_INT,  'Timestamp'),
                ])
            ),
            'pendingmembers'     => new external_multiple_structure(
                new external_single_structure([
                    'userid'   => new external_value(PARAM_INT, 'userid'),
                    'fullname' => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'email'    => new external_value(PARAM_TEXT, 'Email'),
                ])
            ),
            'gradedcount'        => new external_value(PARAM_INT, 'Calificados efectivamente'),
            'skippedcount'       => new external_value(PARAM_INT, 'Omitidos por error'),
        ]);
    }
}
