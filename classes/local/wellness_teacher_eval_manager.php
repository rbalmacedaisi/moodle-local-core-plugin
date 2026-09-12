<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Evaluacion docente post-sesion (RF-08, Fase 4).
 *
 * Decisiones de producto confirmadas:
 *  - SIN ANONIMATO: la fila guarda el userid del estudiante y el back-office
 *    puede mostrarlo. No se usa hash ni seudonimo.
 *  - POR SESION: el estudiante evalua cada sesion de clase a la que pudo
 *    asistir. Una fila por (sessionid, userid), garantizada por indice UNIQUE.
 *
 * Exclusiones acordadas:
 *  - Clases de modulo independiente (gmk_class.is_module = 1).
 *  - Sesiones de revalida (existe fila en gmk_revalidations para esa clase y
 *    ese estudiante con sessionstart el mismo dia).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_teacher_eval_manager {

    /** Estados de la fila. */
    public const STATUS_SENT      = 'enviada';
    public const STATUS_DISMISSED = 'descartada';

    /** Cuantos dias hacia atras se puede evaluar una sesion ya ocurrida. */
    public const WINDOW_DAYS = 14;

    /**
     * Minimo de respuestas para que el promedio de un docente se considere
     * representativo. Por debajo se marca low_sample: una media de 5,0 sobre
     * una sola respuesta no es comparable con 4,2 sobre cuarenta, y presentar
     * ambas como equivalentes es la forma mas rapida de tomar una decision
     * injusta con un docente.
     */
    public const MIN_SAMPLE = 5;

    /** Media por debajo de la cual, YA con muestra suficiente, se marca para revisar. */
    public const ATTENTION_THRESHOLD = 3.0;

    /** Tope de pendientes que se devuelven de una vez al portal. */
    public const MAX_PENDING = 5;

    /**
     * Sesiones que el estudiante todavia puede evaluar.
     *
     * Reglas: la sesion ya ocurrio, cae dentro de la ventana, el estudiante
     * pertenece al grupo de la clase, la clase tiene docente, no es clase de
     * modulo, no es una sesion de revalida suya, y no hay fila previa.
     *
     * @return array<int,object>
     */
    public static function get_pending_for_student(int $userid, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $from = $now - (self::WINDOW_DAYS * 86400);

        $sql = "SELECT s.id AS sessionid,
                       s.sessdate,
                       c.id AS classid,
                       c.name AS classname,
                       c.corecourseid,
                       c.instructorid,
                       u.firstname AS teacher_firstname,
                       u.lastname  AS teacher_lastname
                  FROM {gmk_class} c
                  JOIN {course_modules} cm     ON cm.id = c.attendancemoduleid
                  JOIN {attendance} a          ON a.id = cm.instance
                  JOIN {attendance_sessions} s ON s.attendanceid = a.id
                  JOIN {groups_members} gm     ON gm.groupid = c.groupid AND gm.userid = :uid
                  JOIN {user} u                ON u.id = c.instructorid
             LEFT JOIN {gmk_wellness_teacher_eval} ev
                       ON ev.sessionid = s.id AND ev.userid = :uid2
                 WHERE s.sessdate <= :now
                   AND s.sessdate >= :fromts
                   AND c.is_module = 0
                   AND c.instructorid > 0
                   AND ev.id IS NULL
              ORDER BY s.sessdate DESC";

        $rows = $DB->get_records_sql($sql, [
            'uid' => $userid, 'uid2' => $userid, 'now' => $now, 'fromts' => $from,
        ], 0, self::MAX_PENDING * 4);

        // Excluir sesiones de revalida del propio estudiante (mismo dia).
        $out = [];
        foreach ($rows as $r) {
            if (self::is_revalida_session((int)$r->classid, $userid, (int)$r->sessdate)) {
                continue;
            }
            $r->sessionid    = (int)$r->sessionid;
            $r->classid      = (int)$r->classid;
            $r->corecourseid = (int)$r->corecourseid;
            $r->instructorid = (int)$r->instructorid;
            $r->sessdate     = (int)$r->sessdate;
            $r->teacher_name = trim($r->teacher_firstname . ' ' . $r->teacher_lastname);
            $out[] = $r;
            if (count($out) >= self::MAX_PENDING) {
                break;
            }
        }
        return $out;
    }

    /**
     * ¿La sesion de esa clase, ese dia, corresponde a una revalida del alumno?
     */
    public static function is_revalida_session(int $classid, int $userid, int $sessdate): bool {
        global $DB;
        $daystart = strtotime('today', $sessdate);
        $dayend   = $daystart + 86400;
        return $DB->record_exists_select('gmk_revalidations',
            'classid = :cid AND userid = :uid AND sessionstart >= :ds AND sessionstart < :de',
            ['cid' => $classid, 'uid' => $userid, 'ds' => $daystart, 'de' => $dayend]);
    }

    /**
     * Guarda la evaluacion. Idempotente por el UNIQUE(sessionid, userid):
     * si ya existe fila se actualiza en vez de duplicar.
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function submit(int $sessionid, int $userid, array $ratings, string $comment = ''): array {
        global $DB;

        // Una fila ya enviada no se reescribe; una descartada si puede
        // convertirse en evaluacion si el alumno cambia de idea.
        $existing = $DB->get_record('gmk_wellness_teacher_eval',
            ['sessionid' => $sessionid, 'userid' => $userid]);
        if ($existing && (string)$existing->status === self::STATUS_SENT) {
            return ['ok' => false, 'error' => 'already_submitted'];
        }
        $eligible = $existing ?: self::find_eligible($sessionid, $userid);
        if (!$eligible) {
            return ['ok' => false, 'error' => 'not_eligible'];
        }
        // La fila descartada ya trae los metadatos; la sesion pendiente los
        // trae con otros nombres de columna.
        $sessdate = (int)($eligible->sessdate ?? $eligible->sessiondate ?? 0);

        $overall = self::clamp_rating($ratings['overall'] ?? 0);
        if ($overall < 1) {
            return ['ok' => false, 'error' => 'rating_required'];
        }

        $now = time();
        $record = (object)[
            'classid'            => (int)$eligible->classid,
            'sessionid'          => $sessionid,
            'sessiondate'        => $sessdate,
            'corecourseid'       => (int)$eligible->corecourseid,
            'instructorid'       => (int)$eligible->instructorid,
            'userid'             => $userid,
            'status'             => self::STATUS_SENT,
            'rating_overall'     => $overall,
            'rating_clarity'     => self::clamp_rating($ratings['clarity'] ?? 0),
            'rating_punctuality' => self::clamp_rating($ratings['punctuality'] ?? 0),
            'comment'            => \core_text::substr(trim($comment), 0, 2000),
            'submitted_at'       => $now,
            'timemodified'       => $now,
        ];

        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record('gmk_wellness_teacher_eval', $record);
            return ['ok' => true, 'id' => (int)$existing->id];
        }
        $record->timecreated = $now;
        return ['ok' => true, 'id' => (int)$DB->insert_record('gmk_wellness_teacher_eval', $record)];
    }

    /**
     * El estudiante descarta el popup sin penalizacion: se ocupa el hueco para
     * que no vuelva a preguntarse por esa sesion.
     */
    public static function dismiss(int $sessionid, int $userid): array {
        global $DB;
        if ($DB->record_exists('gmk_wellness_teacher_eval',
                ['sessionid' => $sessionid, 'userid' => $userid])) {
            return ['ok' => true, 'already' => true];
        }
        $eligible = self::find_eligible($sessionid, $userid);
        if (!$eligible) {
            return ['ok' => false, 'error' => 'not_eligible'];
        }
        $now = time();
        $DB->insert_record('gmk_wellness_teacher_eval', (object)[
            'classid'            => (int)$eligible->classid,
            'sessionid'          => $sessionid,
            'sessiondate'        => (int)$eligible->sessdate,
            'corecourseid'       => (int)$eligible->corecourseid,
            'instructorid'       => (int)$eligible->instructorid,
            'userid'             => $userid,
            'status'             => self::STATUS_DISMISSED,
            'rating_overall'     => 0,
            'rating_clarity'     => 0,
            'rating_punctuality' => 0,
            'comment'            => null,
            'submitted_at'       => 0,
            'timecreated'        => $now,
            'timemodified'       => $now,
        ]);
        return ['ok' => true];
    }

    /**
     * Resultados de un docente o de una clase, para Coordinacion Academica.
     * SIN ANONIMATO: se devuelve el nombre del estudiante.
     */
    public static function list_results(int $instructorid = 0, int $classid = 0,
                                        int $from = 0, int $to = 0): array {
        global $DB;
        $where = "ev.status = :st";
        $params = ['st' => self::STATUS_SENT];
        if ($instructorid > 0) { $where .= ' AND ev.instructorid = :iid'; $params['iid'] = $instructorid; }
        if ($classid > 0)      { $where .= ' AND ev.classid = :cid';      $params['cid'] = $classid; }
        if ($from > 0)         { $where .= ' AND ev.sessiondate >= :fr';  $params['fr']  = $from; }
        if ($to > 0)           { $where .= ' AND ev.sessiondate <= :to';  $params['to']  = $to; }

        $rows = $DB->get_records_sql(
            "SELECT ev.*, c.name AS classname,
                    stu.firstname AS student_firstname, stu.lastname AS student_lastname,
                    t.firstname AS teacher_firstname, t.lastname AS teacher_lastname
               FROM {gmk_wellness_teacher_eval} ev
          LEFT JOIN {gmk_class} c ON c.id = ev.classid
          LEFT JOIN {user} stu    ON stu.id = ev.userid
          LEFT JOIN {user} t      ON t.id = ev.instructorid
              WHERE $where
           ORDER BY ev.sessiondate DESC, ev.id DESC", $params);

        return array_values(array_map(function ($r) {
            $r->id           = (int)$r->id;
            $r->classid      = (int)$r->classid;
            $r->sessionid    = (int)$r->sessionid;
            $r->sessiondate  = (int)$r->sessiondate;
            $r->instructorid = (int)$r->instructorid;
            $r->userid       = (int)$r->userid;
            $r->student_name = trim(($r->student_firstname ?? '') . ' ' . ($r->student_lastname ?? ''));
            $r->teacher_name = trim(($r->teacher_firstname ?? '') . ' ' . ($r->teacher_lastname ?? ''));
            return $r;
        }, $rows));
    }

    /**
     * Promedios por docente, con el contexto necesario para interpretarlos:
     * tamano de la muestra, distribucion de notas, cuantos dejaron comentario
     * y cuando fue la ultima evaluacion.
     *
     * Un promedio sin su "n" enganya: 5,0 sobre una respuesta no es
     * comparable con 4,2 sobre cuarenta. Por eso se devuelve low_sample.
     */
    public static function aggregates(int $from = 0, int $to = 0): array {
        global $DB;
        $where = "ev.status = :st";
        $params = ['st' => self::STATUS_SENT];
        if ($from > 0) { $where .= ' AND ev.sessiondate >= :fr'; $params['fr'] = $from; }
        if ($to > 0)   { $where .= ' AND ev.sessiondate <= :to'; $params['to'] = $to; }

        $rows = $DB->get_records_sql(
            "SELECT ev.instructorid AS id, ev.instructorid,
                    COUNT(*) AS total,
                    AVG(ev.rating_overall) AS avg_overall,
                    AVG(ev.rating_clarity) AS avg_clarity,
                    AVG(ev.rating_punctuality) AS avg_punctuality,
                    MIN(ev.rating_overall) AS min_overall,
                    MAX(ev.rating_overall) AS max_overall,
                    MAX(ev.sessiondate) AS last_eval,
                    COUNT(DISTINCT ev.classid) AS classes_count,
                    SUM(CASE WHEN ev.comment IS NOT NULL AND ev.comment <> '' THEN 1 ELSE 0 END) AS with_comments,
                    SUM(CASE WHEN ev.rating_overall = 1 THEN 1 ELSE 0 END) AS d1,
                    SUM(CASE WHEN ev.rating_overall = 2 THEN 1 ELSE 0 END) AS d2,
                    SUM(CASE WHEN ev.rating_overall = 3 THEN 1 ELSE 0 END) AS d3,
                    SUM(CASE WHEN ev.rating_overall = 4 THEN 1 ELSE 0 END) AS d4,
                    SUM(CASE WHEN ev.rating_overall = 5 THEN 1 ELSE 0 END) AS d5,
                    t.firstname, t.lastname
               FROM {gmk_wellness_teacher_eval} ev
          LEFT JOIN {user} t ON t.id = ev.instructorid
              WHERE $where
           GROUP BY ev.instructorid, t.firstname, t.lastname
           ORDER BY avg_overall DESC", $params);

        return array_values(array_map(function ($r) {
            $total = (int)$r->total;
            return (object)[
                'instructorid'    => (int)$r->instructorid,
                'teacher_name'    => trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')),
                'total'           => $total,
                'avg_overall'     => round((float)$r->avg_overall, 2),
                'avg_clarity'     => round((float)$r->avg_clarity, 2),
                'avg_punctuality' => round((float)$r->avg_punctuality, 2),
                'min_overall'     => (int)$r->min_overall,
                'max_overall'     => (int)$r->max_overall,
                'last_eval'       => (int)$r->last_eval,
                'classes_count'   => (int)$r->classes_count,
                'with_comments'   => (int)$r->with_comments,
                'dist'            => [(int)$r->d1, (int)$r->d2, (int)$r->d3, (int)$r->d4, (int)$r->d5],
                // Por debajo de este umbral el promedio no es representativo.
                'low_sample'      => $total < self::MIN_SAMPLE,
                'needs_attention' => $total >= self::MIN_SAMPLE
                                     && (float)$r->avg_overall < self::ATTENTION_THRESHOLD,
            ];
        }, $rows));
    }

    /**
     * Indicadores del instituto en su conjunto.
     *
     * La participacion es el indicador que decide si el resto sirve: un
     * promedio calculado sobre el 5% de las sesiones no representa nada.
     * Se miden dos cosas distintas y complementarias:
     *   - tasa de respuesta: de los popups que el alumno ATENDIO
     *     (respondio o descarto), cuantos respondio.
     *   - cobertura: de todas las oportunidades ELEGIBLES del periodo,
     *     cuantas acabaron en evaluacion.
     */
    public static function global_kpis(int $from = 0, int $to = 0): object {
        global $DB;
        $where = '1=1';
        $params = [];
        if ($from > 0) { $where .= ' AND ev.sessiondate >= :fr'; $params['fr'] = $from; }
        if ($to > 0)   { $where .= ' AND ev.sessiondate <= :to'; $params['to'] = $to; }

        $r = $DB->get_record_sql(
            "SELECT SUM(CASE WHEN ev.status = 'enviada' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN ev.status = 'descartada' THEN 1 ELSE 0 END) AS dismissed,
                    COUNT(DISTINCT CASE WHEN ev.status = 'enviada' THEN ev.instructorid END) AS teachers,
                    COUNT(DISTINCT CASE WHEN ev.status = 'enviada' THEN ev.userid END) AS students,
                    COUNT(DISTINCT CASE WHEN ev.status = 'enviada' THEN ev.classid END) AS classes,
                    AVG(CASE WHEN ev.status = 'enviada' THEN ev.rating_overall END) AS avg_overall,
                    AVG(CASE WHEN ev.status = 'enviada' THEN ev.rating_clarity END) AS avg_clarity,
                    AVG(CASE WHEN ev.status = 'enviada' THEN ev.rating_punctuality END) AS avg_punctuality,
                    SUM(CASE WHEN ev.status = 'enviada' AND ev.comment IS NOT NULL AND ev.comment <> '' THEN 1 ELSE 0 END) AS with_comments,
                    SUM(CASE WHEN ev.status = 'enviada' AND ev.rating_overall = 1 THEN 1 ELSE 0 END) AS d1,
                    SUM(CASE WHEN ev.status = 'enviada' AND ev.rating_overall = 2 THEN 1 ELSE 0 END) AS d2,
                    SUM(CASE WHEN ev.status = 'enviada' AND ev.rating_overall = 3 THEN 1 ELSE 0 END) AS d3,
                    SUM(CASE WHEN ev.status = 'enviada' AND ev.rating_overall = 4 THEN 1 ELSE 0 END) AS d4,
                    SUM(CASE WHEN ev.status = 'enviada' AND ev.rating_overall = 5 THEN 1 ELSE 0 END) AS d5
               FROM {gmk_wellness_teacher_eval} ev
              WHERE $where", $params);

        $sent = (int)($r->sent ?? 0);
        $dismissed = (int)($r->dismissed ?? 0);
        $acted = $sent + $dismissed;
        $eligible = self::count_eligible_opportunities($from, $to);

        return (object)[
            'sent'            => $sent,
            'dismissed'       => $dismissed,
            'eligible'        => $eligible,
            // De lo que el alumno atendio, cuanto respondio.
            'response_rate'   => $acted > 0 ? round($sent * 100 / $acted, 1) : 0.0,
            // De todas las oportunidades del periodo, cuantas se evaluaron.
            'coverage_rate'   => $eligible > 0 ? round($sent * 100 / $eligible, 1) : 0.0,
            'teachers'        => (int)($r->teachers ?? 0),
            'students'        => (int)($r->students ?? 0),
            'classes'         => (int)($r->classes ?? 0),
            'avg_overall'     => round((float)($r->avg_overall ?? 0), 2),
            'avg_clarity'     => round((float)($r->avg_clarity ?? 0), 2),
            'avg_punctuality' => round((float)($r->avg_punctuality ?? 0), 2),
            'with_comments'   => (int)($r->with_comments ?? 0),
            'dist'            => [(int)($r->d1 ?? 0), (int)($r->d2 ?? 0), (int)($r->d3 ?? 0),
                                  (int)($r->d4 ?? 0), (int)($r->d5 ?? 0)],
        ];
    }

    /**
     * Cuantas oportunidades de evaluacion hubo en el periodo: pares
     * (sesion, estudiante) que cumplen las mismas reglas que el popup.
     *
     * Es el denominador de la cobertura. Aplica los mismos filtros que
     * get_pending_for_student, incluida la exclusion de revalidas, que aqui
     * se resuelve con NOT EXISTS y una ventana de +-12 h alrededor de la
     * sesion (equivalente a "el mismo dia" sin recurrir a funciones de fecha
     * propias de un motor concreto).
     */
    public static function count_eligible_opportunities(int $from = 0, int $to = 0): int {
        global $DB;
        $where = 'c.is_module = 0 AND c.instructorid > 0 AND s.sessdate <= :now';
        $params = ['now' => time()];
        if ($from > 0) { $where .= ' AND s.sessdate >= :fr'; $params['fr'] = $from; }
        if ($to > 0)   { $where .= ' AND s.sessdate <= :to'; $params['to'] = $to; }

        return (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {gmk_class} c
               JOIN {course_modules} cm ON cm.id = c.attendancemoduleid
               JOIN {attendance} a ON a.id = cm.instance
               JOIN {attendance_sessions} s ON s.attendanceid = a.id
               JOIN {groups_members} gm ON gm.groupid = c.groupid
              WHERE $where
                AND NOT EXISTS (
                      SELECT 1 FROM {gmk_revalidations} rv
                       WHERE rv.classid = c.id AND rv.userid = gm.userid
                         AND rv.sessionstart > s.sessdate - 43200
                         AND rv.sessionstart < s.sessdate + 43200)", $params);
    }

    /**
     * Serie mensual: volumen y promedio. Sirve para ver si la percepcion
     * mejora o empeora, que es lo que de verdad se puede accionar.
     *
     * Se agrupa en PHP y no con funciones de fecha del motor para no atar
     * la consulta a MySQL.
     */
    public static function trend(int $from = 0, int $to = 0): array {
        global $DB;
        $where = "ev.status = :st";
        $params = ['st' => self::STATUS_SENT];
        if ($from > 0) { $where .= ' AND ev.sessiondate >= :fr'; $params['fr'] = $from; }
        if ($to > 0)   { $where .= ' AND ev.sessiondate <= :to'; $params['to'] = $to; }

        $rows = $DB->get_records_sql(
            "SELECT ev.id, ev.sessiondate, ev.rating_overall
               FROM {gmk_wellness_teacher_eval} ev
              WHERE $where
           ORDER BY ev.sessiondate ASC", $params);

        $buckets = [];
        foreach ($rows as $r) {
            $key = date('Y-m', (int)$r->sessiondate);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['n' => 0, 'sum' => 0];
            }
            $buckets[$key]['n']++;
            $buckets[$key]['sum'] += (int)$r->rating_overall;
        }
        $out = [];
        foreach ($buckets as $key => $b) {
            $out[] = (object)[
                'period' => $key,
                'total'  => $b['n'],
                'avg'    => $b['n'] > 0 ? round($b['sum'] / $b['n'], 2) : 0.0,
            ];
        }
        return $out;
    }

    // -- helpers ------------------------------------------------------------

    /** Devuelve la sesion si el estudiante puede evaluarla; null si no. */
    private static function find_eligible(int $sessionid, int $userid): ?object {
        foreach (self::get_pending_for_student($userid) as $row) {
            if ((int)$row->sessionid === $sessionid) {
                return $row;
            }
        }
        return null;
    }

    private static function clamp_rating($value): int {
        $v = (int)$value;
        if ($v < 0) { return 0; }
        return $v > 5 ? 5 : $v;
    }
}
