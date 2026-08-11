<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Lightweight external function that returns the count of active students
 * broken down by financial status. Powers the 3-card indicator on the
 * academicpanel (total / al-dia / en-mora) with 60s polling.
 *
 * Same definition of "active student" as the academicpanel: estudiantes con
 * al menos una clase activa aprobada, no cerrada, vigente y que no es
 * transversal (TC). See studenttable.js tooltip for the canonical
 * description.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupomakro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class get_financial_counts extends external_api {

    /**
     * Sin parámetros: el indicador global es siempre para todos los
     * estudiantes activos. Si en el futuro se quiere segmentar por
     * carrera/periodo, se agregan aquí sin romper compatibilidad.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Devuelve:
     *   - total:        estudiantes activos (universo)
     *   - al_dia:       gmk_financial_status.status = 'al_dia'
     *   - mora:         gmk_financial_status.status = 'mora'
     *   - becado:       gmk_financial_status.status = 'becado'
     *   - convenio:     gmk_financial_status.status = 'convenio'
     *   - sin_contrato: gmk_financial_status.status = 'sin_contrato_o_usuario'
     *   - pendiente:    sin fila en gmk_financial_status
     *   - server_time:  timestamp del servidor (cliente lo usa para depurar
     *                   drift del cache navegador vs servidor)
     */
    public static function execute(): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), []);

        $context = context_system::instance();
        self::validate_context($context);

        $counts = self::compute_counts();

        return $counts + [
            'server_time' => time(),
        ];
    }

    /**
     * Query principal. Pensada para correr en <100ms con los índices
     * actuales (gmk_class.*, local_learning_users.userid+groupid, y los
     * índices de gmk_financial_status.status y userid).
     *
     * Lógica (misma que get_student_info.php activeUsers):
     *   1. Universo = usuarios con local_learning_users.userrolename='student'
     *      y al menos una clase activa aprobada, no cerrada, vigente y NO TC.
     *      TC se detecta con un customfield "tc" en la tabla customfield_data
     *      (instanceid = gc.corecourseid, value = '1').
     *   2. LEFT JOIN gmk_financial_status por userid.
     *   3. GROUP BY fs.status y contamos.
     *   4. Los NULL son los "pendiente".
     */
    private static function compute_counts(): array {
        global $DB;

        $now = time();

        // TC custom field: si existe, excluir las clases marcadas como TC=1.
        $tcfid = (int)($DB->get_field('customfield_field', 'id', ['shortname' => 'tc']) ?: 0);
        $tcjoin = '';
        $tcwhere = '';
        if ($tcfid > 0) {
            $tcjoin = "
                LEFT JOIN {customfield_data} _tc_chk
                       ON _tc_chk.instanceid = gc.corecourseid
                      AND _tc_chk.fieldid = :tcfid
                      AND _tc_chk.value = '1'
            ";
            $tcwhere = "AND _tc_chk.id IS NULL";
        }

        $sql = "
            SELECT
                COALESCE(fs.status, '__pendiente__') AS fstatus,
                COUNT(DISTINCT u.id) AS cnt
              FROM {user} u
              JOIN {local_learning_users} lpu ON lpu.userid = u.id
              JOIN {gmk_course_progre} cp ON cp.userid = u.id
              JOIN {gmk_class} gc
                ON gc.id = cp.classid
               AND gc.approved = 1
               AND gc.closed = 0
               AND gc.initdate <= :now1
               AND (gc.enddate = 0 OR gc.enddate >= :now2)
              $tcjoin
         LEFT JOIN {gmk_financial_status} fs ON fs.userid = u.id
             WHERE u.deleted = 0
               AND u.suspended = 0
               AND lpu.userrolename = 'student'
               AND cp.status IN (1, 2, 3)
               $tcwhere
          GROUP BY fstatus
        ";

        $params = ['now1' => $now, 'now2' => $now];
        if ($tcfid > 0) {
            $params['tcfid'] = $tcfid;
        }
        $rows = $DB->get_records_sql($sql, $params);

        $result = [
            'total'        => 0,
            'al_dia'       => 0,
            'mora'         => 0,
            'becado'       => 0,
            'convenio'     => 0,
            'sin_contrato' => 0,
            'pendiente'    => 0,
        ];

        foreach ($rows as $r) {
            $key = (string)$r->fstatus;
            $cnt = (int)$r->cnt;
            $result['total'] += $cnt;
            if ($key === '__pendiente__') {
                $result['pendiente'] = $cnt;
            } else if (isset($result[$key])) {
                $result[$key] = $cnt;
            }
        }

        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total'        => new external_value(PARAM_INT, 'Active students total.'),
            'al_dia'       => new external_value(PARAM_INT, 'Active students with financial_status=al_dia.'),
            'mora'         => new external_value(PARAM_INT, 'Active students with financial_status=mora.'),
            'becado'       => new external_value(PARAM_INT, 'Active students with financial_status=becado.'),
            'convenio'     => new external_value(PARAM_INT, 'Active students with financial_status=convenio.'),
            'sin_contrato' => new external_value(PARAM_INT, 'Active students with financial_status=sin_contrato_o_usuario.'),
            'pendiente'    => new external_value(PARAM_INT, 'Active students without a gmk_financial_status row yet.'),
            'server_time'  => new external_value(PARAM_INT, 'Server unix timestamp (epoch).'),
        ]);
    }
}