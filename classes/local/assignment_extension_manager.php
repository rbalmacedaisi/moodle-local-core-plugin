<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Manager para prorrogas individuales de entrega en actividades tipo Assign.
 *
 * Una prorroga se modela como una fila en mdl_assign_overrides con
 * groupid=NULL y userid=X (mecanismo nativo de Moodle). Adicionalmente
 * auditamos cada creacion/actualizacion/borrado en gmk_assignment_extension_log
 * para tener un historial que sobreviva a que el docente borre el override.
 *
 * @package    local_grupomakro_core
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class assignment_extension_manager {

    /**
     * Resolve a caller-supplied "course id" to a real mdl_course.id.
     *
     * The frontend (TeacherDashboard / ManageClass) sends the
     * gmk_class.id of the class the teacher is currently looking at.
     * Internally the manager needs the underlying mdl_course.id to
     * look up course modules and the course context. This helper
     * does that mapping:
     *
     *   - If the id exists in {gmk_class} with a non-null corecourseid,
     *     return that corecourseid.
     *   - Otherwise, assume the caller already passed a real
     *     mdl_course.id and return it unchanged.
     *
     * The previous version of the manager skipped this step and
     * called context_course::instance($gmk_class_id), which
     * threw 'No se puede encontrar registro de datos en la tabla
     * course de la base de datos' (the exact message the teacher
     * saw in the console when opening the Excepciones modal).
     */
    private static function resolve_course_id(int $id): int {
        global $DB;
        if ($id <= 0) {
            return $id;
        }
        $corecourseid = $DB->get_field('gmk_class', 'corecourseid', ['id' => $id], IGNORE_MISSING);
        if (!empty($corecourseid)) {
            return (int)$corecourseid;
        }
        return $id;
    }

    /**
     * Public wrapper for the external WS layer.
     *
     * The WS classes (classes/external/teacher/assignment_extensions.php)
     * need the same gmk_class -> mdl_course mapping for their
     * MUST_EXIST course lookups and context_course::instance() calls.
     * They cannot call the private resolve_course_id() directly, so
     * we expose it here.
     */
    public static function resolve_course_id_public(int $id): int {
        return self::resolve_course_id($id);
    }

    /**
     * Crea o actualiza una prorroga individual para un estudiante en una
     * actividad. UPSERT: si ya existe un override (groupid NULL + userid) para
     * esa asignacion, se actualiza in-place; si no, se crea. Audita el antes y
     * el despues en gmk_assignment_extension_log.
     *
     * @param int    $assignid     mdl_assign.id
     * @param int    $userid       mdl_user.id del estudiante
     * @param int    $newduedate   nuevo due date (Unix timestamp > now)
     * @param int    $actorid      mdl_user.id del docente que aplica
     * @param string $reason       razon opcional
     * @return array {override_id:int, old_duedate:int|null, new_duedate:int}
     */
    public static function set_user_override(int $assignid, int $userid, int $newduedate, int $actorid, string $reason = ''): array {
        global $DB;

        if ($assignid <= 0 || $userid <= 0 || $newduedate <= time()) {
            throw new \invalid_parameter_exception('Parametros invalidos: assignid, userid y duedate (futuro) son requeridos.');
        }

        $now = time();
        $existing = $DB->get_record('assign_overrides', [
            'assignid' => $assignid,
            'userid'   => $userid,
            'groupid'  => null,
        ], '*', IGNORE_MULTIPLE);

        $oldduedate = null;
        if ($existing) {
            $oldduedate = $existing->duedate;
            $existing->duedate = $newduedate;
            $DB->update_record('assign_overrides', $existing);
            $overrideid = (int)$existing->id;
        } else {
            $record = (object)[
                'assignid' => $assignid,
                'groupid'  => null,
                'userid'   => $userid,
                'sortorder' => 0,
                'allowsubmissionsfromdate' => null,
                'duedate'  => $newduedate,
                'cutoffdate' => null,
                'timelimit' => null,
            ];
            $overrideid = (int)$DB->insert_record('assign_overrides', $record);
        }

        // Auditoria.
        $DB->insert_record('gmk_assignment_extension_log', (object)[
            'assignid'      => $assignid,
            'userid'        => $userid,
            'old_duedate'   => $oldduedate,
            'new_duedate'   => $newduedate,
            'actor_userid'  => $actorid,
            'reason'        => $reason,
            'timecreated'   => $now,
        ]);

        return [
            'override_id' => $overrideid,
            'old_duedate' => $oldduedate,
            'new_duedate' => $newduedate,
        ];
    }

    /**
     * Borra una prorroga individual. Audita el borrado con reason='deleted'
     * para mantener trazabilidad.
     *
     * @return bool
     */
    public static function delete_user_override(int $assignid, int $userid, int $actorid): bool {
        global $DB;
        $existing = $DB->get_record('assign_overrides', [
            'assignid' => $assignid,
            'userid'   => $userid,
            'groupid'  => null,
        ], '*', IGNORE_MULTIPLE);
        if (!$existing) {
            return false;
        }
        $DB->insert_record('gmk_assignment_extension_log', (object)[
            'assignid'      => $assignid,
            'userid'        => $userid,
            'old_duedate'   => $existing->duedate,
            'new_duedate'   => 0,
            'actor_userid'  => $actorid,
            'reason'        => 'deleted',
            'timecreated'   => time(),
        ]);
        $DB->delete_records('assign_overrides', ['id' => $existing->id]);
        return true;
    }

    /**
     * Lista prorrogas individuales vigentes de una actividad + historial de
     * auditoria de las ultimas N.
     *
     * @return array
     */
    public static function list_extensions(int $assignid, int $audithistorylimit = 50): array {
        global $DB;
        $assign = $DB->get_record('assign', ['id' => $assignid], 'id,duedate,allowsubmissionsfromdate,cutoffdate,name', IGNORE_MISSING);
        if (!$assign) {
            throw new \invalid_parameter_exception('Actividad no encontrada.');
        }

        $overrides = $DB->get_records_sql(
            'SELECT o.id, o.assignid, o.userid, o.duedate, o.allowsubmissionsfromdate, o.cutoffdate,
                    u.firstname, u.lastname, u.email
             FROM {assign_overrides} o
             JOIN {user} u ON u.id = o.userid
             WHERE o.assignid = :aid AND o.userid IS NOT NULL AND o.groupid IS NULL
             ORDER BY u.lastname, u.firstname',
            ['aid' => $assignid]
        );

        $rows = [];
        foreach ($overrides as $o) {
            $rows[] = [
                'override_id' => (int)$o->id,
                'userid'      => (int)$o->userid,
                'user_name'   => trim($o->firstname . ' ' . $o->lastname),
                'user_email'  => (string)$o->email,
                'duedate'     => (int)$o->duedate,
            ];
        }

        $history = $DB->get_records_sql(
            'SELECT l.id, l.assignid, l.userid, l.old_duedate, l.new_duedate, l.reason, l.timecreated,
                    u.firstname, u.lastname, u.email,
                    a.firstname AS actor_firstname, a.lastname AS actor_lastname
             FROM {gmk_assignment_extension_log} l
             JOIN {user} u ON u.id = l.userid
             LEFT JOIN {user} a ON a.id = l.actor_userid
             WHERE l.assignid = :aid
             ORDER BY l.timecreated DESC',
            ['aid' => $assignid],
            0,
            $audithistorylimit
        );
        $hrows = [];
        foreach ($history as $h) {
            $hrows[] = [
                'id'             => (int)$h->id,
                'userid'         => (int)$h->userid,
                'user_name'      => trim($h->firstname . ' ' . $h->lastname),
                'old_duedate'    => $h->old_duedate !== null ? (int)$h->old_duedate : null,
                'new_duedate'    => (int)$h->new_duedate,
                'reason'         => (string)($h->reason ?? ''),
                'actor_name'     => trim(($h->actor_firstname ?? '') . ' ' . ($h->actor_lastname ?? '')),
                'timecreated'    => (int)$h->timecreated,
            ];
        }

        return [
            'assign_id'   => $assignid,
            'assign_name' => (string)$assign->name,
            'default_duedate' => $assign->duedate ? (int)$assign->duedate : null,
            'overrides'   => $rows,
            'history'     => $hrows,
        ];
    }

    /**
     * Lista las actividades tipo Assign de un curso (cmid + assignid + name).
     * Solo incluye instancias uservisible.
     *
     * @param int $courseid
     * @return array
     */
    public static function list_course_assignments(int $courseid): array {
        global $DB;
        $corecourseid = self::resolve_course_id($courseid);
        $sql = 'SELECT cm.id AS cmid, a.id AS assignid, a.duedate, a.name, a.allowsubmissionsfromdate, a.cutoffdate
                FROM {course_modules} cm
                JOIN {assign} a ON a.id = cm.instance
                JOIN {modules} m ON m.id = cm.module AND m.name = "assign"
                WHERE cm.course = :cid AND cm.deletioninprogress = 0 AND cm.visible = 1
                ORDER BY a.duedate DESC, a.name';
        $rows = $DB->get_records_sql($sql, ['cid' => $corecourseid]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'cmid'         => (int)$r->cmid,
                'assignid'     => (int)$r->assignid,
                'name'         => (string)$r->name,
                'duedate'      => $r->duedate ? (int)$r->duedate : null,
            ];
        }
        return $out;
    }

    /**
     * Lista los estudiantes matriculados de un curso con su nombre, email y
     * eventual override vigente para una asignacion concreta (si pasas assignid).
     *
     * @param int      $courseid
     * @param int|null $assignid Si se pasa, anade el override individual vigente.
     * @return array
     */
    public static function list_course_students(int $courseid, ?int $assignid = null): array {
        global $DB;
        $corecourseid = self::resolve_course_id($courseid);
        $ctx = \context_course::instance($corecourseid);
        $students = get_enrolled_users($ctx, 'mod/assign:submit', 0, 'u.id,u.firstname,u.lastname,u.email', 'u.lastname, u.firstname');
        $overrides = [];
        if ($assignid) {
            $rows = $DB->get_records('assign_overrides', [
                'assignid' => $assignid,
                'groupid'  => null,
            ]);
            foreach ($rows as $r) {
                $overrides[(int)$r->userid] = (int)$r->duedate;
            }
        }
        $out = [];
        foreach ($students as $s) {
            $out[] = [
                'userid'       => (int)$s->id,
                'user_name'    => trim($s->firstname . ' ' . $s->lastname),
                'user_email'   => (string)$s->email,
                'override_duedate' => $overrides[(int)$s->id] ?? null,
            ];
        }
        return $out;
    }
}