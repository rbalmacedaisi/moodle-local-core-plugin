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

    /** Promedios por docente en un rango. */
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
                    t.firstname, t.lastname
               FROM {gmk_wellness_teacher_eval} ev
          LEFT JOIN {user} t ON t.id = ev.instructorid
              WHERE $where
           GROUP BY ev.instructorid, t.firstname, t.lastname
           ORDER BY avg_overall DESC", $params);

        return array_values(array_map(function ($r) {
            return (object)[
                'instructorid'    => (int)$r->instructorid,
                'teacher_name'    => trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')),
                'total'           => (int)$r->total,
                'avg_overall'     => round((float)$r->avg_overall, 2),
                'avg_clarity'     => round((float)$r->avg_clarity, 2),
                'avg_punctuality' => round((float)$r->avg_punctuality, 2),
            ];
        }, $rows));
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
