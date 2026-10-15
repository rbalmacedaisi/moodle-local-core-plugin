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
 * Student <-> teacher messaging (fase 1).
 *
 * Un hilo por (gmk_class, estudiante). Los docentes NO se guardan en el hilo:
 * se derivan de gmk_class.instructorid / supportinstructorid en cada lectura,
 * asi si cambian al docente de la clase el nuevo hereda las conversaciones.
 *
 * Toda decision de acceso pasa por esta clase. Los WS nunca deben confiar en
 * ids que manda el cliente sin pasar por require_thread() / open_thread().
 *
 * Pertenencia del estudiante a la clase: misma regla que el roster del docente
 * (get_student_info): miembros del grupo Moodle de la clase cuando
 * gmk_class.groupid > 0; si no, gmk_course_progre.classid.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\messaging;

use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class manager {

    /** Max characters per message body. */
    const MAX_BODY = 4000;

    /** Max messages a user may send in RATE_WINDOW seconds. */
    const RATE_LIMIT = 30;
    const RATE_WINDOW = HOURSECS;

    const ROLE_STUDENT = 'student';
    const ROLE_TEACHER = 'teacher';
    const ROLE_SUPPORT = 'support';

    const STATUS_OPEN = 0;

    /** Characters of the last message shown in the inbox. */
    const EXCERPT_LENGTH = 140;

    // ---------------------------------------------------------------------
    // Access rules
    // ---------------------------------------------------------------------

    /**
     * @throws moodle_exception when the class does not exist.
     */
    public static function get_class(int $classid): stdClass {
        global $DB;
        $class = $DB->get_record('gmk_class', ['id' => $classid],
            'id, name, corecourseid, groupid, instructorid, supportinstructorid, closed');
        if (!$class) {
            throw new moodle_exception('msg_err_notfound', 'local_grupomakro_core');
        }
        return $class;
    }

    /**
     * Role of $userid as teacher of $class, or null when not a teacher of it.
     */
    public static function teacher_role(stdClass $class, int $userid): ?string {
        if ($userid <= 0) {
            return null;
        }
        if ((int)$class->instructorid === $userid) {
            return self::ROLE_TEACHER;
        }
        if ((int)($class->supportinstructorid ?? 0) === $userid) {
            return self::ROLE_SUPPORT;
        }
        return null;
    }

    /**
     * Teacher user ids of a class (main instructor first).
     *
     * @return int[]
     */
    public static function class_teacher_ids(stdClass $class): array {
        $ids = [];
        foreach ([(int)$class->instructorid, (int)($class->supportinstructorid ?? 0)] as $id) {
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function is_class_student(stdClass $class, int $userid): bool {
        global $DB;
        if ($userid <= 0 || self::teacher_role($class, $userid) !== null) {
            return false;
        }
        if ((int)$class->groupid > 0) {
            return $DB->record_exists('groups_members', ['groupid' => (int)$class->groupid, 'userid' => $userid]);
        }
        return $DB->record_exists('gmk_course_progre', ['classid' => (int)$class->id, 'userid' => $userid]);
    }

    /**
     * Resolves what $userid can do on $thread.
     *
     * @return array [role, canwrite, class|null]
     * @throws moodle_exception when the user has no access at all.
     */
    public static function resolve_access(stdClass $thread, int $userid): array {
        global $DB;
        $class = $DB->get_record('gmk_class', ['id' => (int)$thread->classid],
            'id, name, corecourseid, groupid, instructorid, supportinstructorid, closed');

        if ((int)$thread->studentid === $userid) {
            $canwrite = $class
                && empty($class->closed)
                && (int)$thread->status === self::STATUS_OPEN
                && !empty(self::class_teacher_ids($class))
                && self::is_class_student($class, $userid);
            return [self::ROLE_STUDENT, (bool)$canwrite, $class ?: null];
        }

        $role = $class ? self::teacher_role($class, $userid) : null;
        if ($role !== null) {
            $canwrite = empty($class->closed) && (int)$thread->status === self::STATUS_OPEN;
            return [$role, $canwrite, $class];
        }

        throw new moodle_exception('msg_err_noaccess', 'local_grupomakro_core');
    }

    /**
     * Loads a thread checking that $userid may read it.
     *
     * @return array [thread, class|null, role, canwrite]
     */
    public static function require_thread(int $threadid, int $userid): array {
        global $DB;
        $thread = $DB->get_record('gmk_msg_thread', ['id' => $threadid]);
        if (!$thread) {
            throw new moodle_exception('msg_err_notfound', 'local_grupomakro_core');
        }
        [$role, $canwrite, $class] = self::resolve_access($thread, $userid);
        return [$thread, $class, $role, $canwrite];
    }

    // ---------------------------------------------------------------------
    // Operations
    // ---------------------------------------------------------------------

    /**
     * Returns the (class, student) thread, creating it when needed.
     *
     * A student opens the thread of a class they belong to (studentid is
     * ignored). A teacher must pass the studentid of a student of that class.
     */
    public static function open_thread(int $classid, int $userid, int $studentid = 0): stdClass {
        global $DB;
        $class = self::get_class($classid);

        if (self::teacher_role($class, $userid) !== null) {
            if ($studentid <= 0 || !self::is_class_student($class, $studentid)) {
                throw new moodle_exception('msg_err_notstudent', 'local_grupomakro_core');
            }
        } else {
            if (!self::is_class_student($class, $userid)) {
                throw new moodle_exception('msg_err_noaccess', 'local_grupomakro_core');
            }
            if (empty(self::class_teacher_ids($class))) {
                throw new moodle_exception('msg_err_noteacher', 'local_grupomakro_core');
            }
            $studentid = $userid;
        }

        $existing = $DB->get_record('gmk_msg_thread', ['classid' => $classid, 'studentid' => $studentid]);
        if ($existing) {
            return $existing;
        }
        if (!empty($class->closed)) {
            throw new moodle_exception('msg_err_readonly', 'local_grupomakro_core');
        }

        $now = time();
        $thread = (object)[
            'classid'       => $classid,
            'courseid'      => (int)$class->corecourseid,
            'studentid'     => $studentid,
            'createdby'     => $userid,
            'status'        => self::STATUS_OPEN,
            'lastmessageid' => 0,
            'lastmessageat' => 0,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ];
        try {
            $thread->id = $DB->insert_record('gmk_msg_thread', $thread);
        } catch (\dml_write_exception $e) {
            // Concurrent open of the same (class, student): the unique index won.
            $existing = $DB->get_record('gmk_msg_thread', ['classid' => $classid, 'studentid' => $studentid]);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
        return $thread;
    }

    /**
     * Sends a message on a thread and notifies the other side.
     *
     * @return array [message, thread] (thread re-read after the update)
     */
    public static function send(int $threadid, int $userid, string $body): array {
        global $DB;
        [$thread, $class, $role, $canwrite] = self::require_thread($threadid, $userid);
        if (!$canwrite) {
            throw new moodle_exception('msg_err_readonly', 'local_grupomakro_core');
        }

        $body = self::clean_body($body);
        if ($body === '') {
            throw new moodle_exception('msg_err_empty', 'local_grupomakro_core');
        }
        if (\core_text::strlen($body) > self::MAX_BODY) {
            throw new moodle_exception('msg_err_toolong', 'local_grupomakro_core', '', self::MAX_BODY);
        }

        $now = time();
        $recent = $DB->count_records_select('gmk_msg_message', 'senderid = :u AND timecreated > :since',
            ['u' => $userid, 'since' => $now - self::RATE_WINDOW]);
        if ($recent >= self::RATE_LIMIT) {
            throw new moodle_exception('msg_err_ratelimit', 'local_grupomakro_core');
        }

        $transaction = $DB->start_delegated_transaction();
        $message = (object)[
            'threadid'       => (int)$thread->id,
            'senderid'       => $userid,
            'senderrole'     => $role,
            'body'           => $body,
            'hasattachments' => 0,
            'deleted'        => 0,
            'timecreated'    => $now,
        ];
        $message->id = $DB->insert_record('gmk_msg_message', $message);

        $DB->update_record('gmk_msg_thread', (object)[
            'id'            => (int)$thread->id,
            'lastmessageid' => $message->id,
            'lastmessageat' => $now,
            'timemodified'  => $now,
        ]);
        self::set_read_pointer((int)$thread->id, $userid, (int)$message->id);
        $transaction->allow_commit();

        $thread->lastmessageid = $message->id;
        $thread->lastmessageat = $now;

        try {
            self::notify($thread, $class, $message);
        } catch (\Throwable $e) {
            // A failed notification must never lose the message already stored.
            if (function_exists('gmk_log')) {
                gmk_log('messaging notify failed for message ' . $message->id . ': ' . $e->getMessage());
            }
        }

        return [$message, $thread];
    }

    /**
     * Moves the read pointer of $userid forward (never backwards).
     *
     * @return int the resulting lastreadmessageid
     */
    public static function mark_read(int $threadid, int $userid, int $uptomessageid = 0): int {
        [$thread] = self::require_thread($threadid, $userid);
        $target = (int)$thread->lastmessageid;
        if ($uptomessageid > 0) {
            $target = min($target, $uptomessageid);
        }
        return self::set_read_pointer((int)$thread->id, $userid, $target);
    }

    public static function unread_count(int $userid): int {
        global $DB;
        $sql = "SELECT COUNT(m.id)
                  FROM {gmk_msg_message} m
                  JOIN {gmk_msg_thread} t ON t.id = m.threadid
                  JOIN {gmk_class} c ON c.id = t.classid
             LEFT JOIN {gmk_msg_read} r ON r.threadid = t.id AND r.userid = :u0
                 WHERE (t.studentid = :u1 OR c.instructorid = :u2 OR c.supportinstructorid = :u3)
                   AND m.senderid <> :u4
                   AND m.deleted = 0
                   AND m.id > COALESCE(r.lastreadmessageid, 0)";
        return (int)$DB->count_records_sql($sql, ['u0' => $userid, 'u1' => $userid, 'u2' => $userid,
            'u3' => $userid, 'u4' => $userid]);
    }

    /**
     * Threads visible to $userid that have at least one message, newest first.
     *
     * @return array ['threads' => exported threads, 'total' => int]
     */
    public static function inbox(int $userid, int $classid = 0, bool $unreadonly = false, string $search = '',
            int $page = 0, int $perpage = 50): array {
        global $DB;

        $params = ['u0' => $userid, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u5' => $userid];
        $where = ['(t.studentid = :u1 OR c.instructorid = :u2 OR c.supportinstructorid = :u3)', 't.lastmessageid > 0'];
        if ($classid > 0) {
            $where[] = 't.classid = :classid';
            $params['classid'] = $classid;
        }
        $search = trim($search);
        if ($search !== '') {
            $like = [];
            foreach (['s.firstname', 's.lastname', 'c.name', 'co.fullname'] as $i => $field) {
                $like[] = $DB->sql_like($field, ':q' . $i, false, false);
                $params['q' . $i] = '%' . $DB->sql_like_escape($search) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }

        $sql = "SELECT t.*, c.name AS classname, c.instructorid, c.supportinstructorid, c.closed,
                       c.groupid, co.fullname AS coursename, m.body AS lastbody, m.senderid AS lastsenderid,
                       (SELECT COUNT(1)
                          FROM {gmk_msg_message} um
                         WHERE um.threadid = t.id AND um.deleted = 0 AND um.senderid <> :u5
                           AND um.id > COALESCE(r.lastreadmessageid, 0)) AS unread
                  FROM {gmk_msg_thread} t
                  JOIN {gmk_class} c ON c.id = t.classid
                  JOIN {user} s ON s.id = t.studentid
             LEFT JOIN {course} co ON co.id = t.courseid
             LEFT JOIN {gmk_msg_message} m ON m.id = t.lastmessageid
             LEFT JOIN {gmk_msg_read} r ON r.threadid = t.id AND r.userid = :u0
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY t.lastmessageat DESC, t.id DESC";
        $rows = array_values($DB->get_records_sql($sql, $params));

        if ($unreadonly) {
            $rows = array_values(array_filter($rows, function($r) {
                return (int)$r->unread > 0;
            }));
        }
        $total = count($rows);
        $rows = array_slice($rows, max(0, $page) * $perpage, $perpage);

        return ['threads' => self::export_threads($rows, $userid), 'total' => $total];
    }

    /**
     * Messages of a thread, oldest first, paging backwards from $beforeid.
     *
     * @return array ['messages' => exported, 'hasmore' => bool]
     */
    public static function messages(stdClass $thread, int $userid, int $beforeid = 0, int $limit = 30): array {
        global $DB;
        $limit = max(1, min(100, $limit));
        $params = ['threadid' => (int)$thread->id];
        $select = 'threadid = :threadid AND deleted = 0';
        if ($beforeid > 0) {
            $select .= ' AND id < :beforeid';
            $params['beforeid'] = $beforeid;
        }
        $rows = $DB->get_records_select('gmk_msg_message', $select, $params, 'id DESC', '*', 0, $limit + 1);
        $hasmore = count($rows) > $limit;
        $rows = array_reverse(array_slice(array_values($rows), 0, $limit));

        $users = self::load_users(array_map(function($m) {
            return (int)$m->senderid;
        }, $rows));
        $out = [];
        foreach ($rows as $m) {
            $out[] = self::export_message($m, $userid, $users);
        }
        return ['messages' => $out, 'hasmore' => $hasmore];
    }

    /**
     * Who $userid can write to.
     *
     * Student: their open classes that have a teacher, with the teachers and
     * the existing thread. Teacher: their open classes; when $classid is one
     * of them, also its students with their thread/unread.
     */
    public static function contacts(int $userid, int $classid = 0): array {
        global $DB;

        $teaching = $DB->get_records_sql(
            "SELECT c.id, c.name, c.corecourseid, c.groupid, c.instructorid, c.supportinstructorid, c.closed,
                    co.fullname AS coursename
               FROM {gmk_class} c
          LEFT JOIN {course} co ON co.id = c.corecourseid
              WHERE c.closed = 0 AND (c.instructorid = :u1 OR c.supportinstructorid = :u2)
           ORDER BY c.name", ['u1' => $userid, 'u2' => $userid]);

        if (!empty($teaching)) {
            $classes = [];
            foreach ($teaching as $class) {
                $item = self::export_contact_class($class);
                if ($classid > 0 && (int)$class->id === $classid) {
                    $item['students'] = self::class_students_for_teacher($class, $userid);
                }
                $classes[] = $item;
            }
            return ['role' => self::ROLE_TEACHER, 'classes' => $classes];
        }

        $mine = $DB->get_records_sql(
            "SELECT c.id, c.name, c.corecourseid, c.groupid, c.instructorid, c.supportinstructorid, c.closed,
                    co.fullname AS coursename
               FROM {gmk_class} c
          LEFT JOIN {course} co ON co.id = c.corecourseid
              WHERE c.closed = 0
                AND (c.instructorid > 0 OR c.supportinstructorid > 0)
                AND ((c.groupid > 0 AND EXISTS (SELECT 1 FROM {groups_members} gm
                                                 WHERE gm.groupid = c.groupid AND gm.userid = :u1))
                  OR (c.groupid = 0 AND EXISTS (SELECT 1 FROM {gmk_course_progre} p
                                                 WHERE p.classid = c.id AND p.userid = :u2)))
           ORDER BY co.fullname, c.name", ['u1' => $userid, 'u2' => $userid]);

        $threads = [];
        if (!empty($mine)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($mine), SQL_PARAMS_NAMED, 'cl');
            $inparams['sid'] = $userid;
            $inparams['u0'] = $userid;
            $inparams['u1'] = $userid;
            $threads = $DB->get_records_sql(
                "SELECT t.classid, t.id,
                        (SELECT COUNT(1) FROM {gmk_msg_message} um
                          WHERE um.threadid = t.id AND um.deleted = 0 AND um.senderid <> :u1
                            AND um.id > COALESCE(r.lastreadmessageid, 0)) AS unread
                   FROM {gmk_msg_thread} t
              LEFT JOIN {gmk_msg_read} r ON r.threadid = t.id AND r.userid = :u0
                  WHERE t.studentid = :sid AND t.classid $insql", $inparams);
        }

        $teacherids = [];
        foreach ($mine as $class) {
            $teacherids = array_merge($teacherids, self::class_teacher_ids($class));
        }
        $users = self::load_users($teacherids);

        $classes = [];
        foreach ($mine as $class) {
            $item = self::export_contact_class($class);
            foreach (self::class_teacher_ids($class) as $tid) {
                if (isset($users[$tid])) {
                    $item['teachers'][] = self::export_user($users[$tid],
                        self::teacher_role($class, $tid));
                }
            }
            if (isset($threads[$class->id])) {
                $item['threadid'] = (int)$threads[$class->id]->id;
                $item['unread'] = (int)$threads[$class->id]->unread;
            }
            $classes[] = $item;
        }
        return ['role' => self::ROLE_STUDENT, 'classes' => $classes];
    }

    // ---------------------------------------------------------------------
    // Export helpers (shapes match external\messaging\structures)
    // ---------------------------------------------------------------------

    /**
     * Exports one thread row (as stored) for $userid.
     */
    public static function export_thread(stdClass $thread, int $userid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT t.*, c.name AS classname, c.instructorid, c.supportinstructorid, c.closed, c.groupid,
                    co.fullname AS coursename, m.body AS lastbody, m.senderid AS lastsenderid,
                    (SELECT COUNT(1) FROM {gmk_msg_message} um
                      WHERE um.threadid = t.id AND um.deleted = 0 AND um.senderid <> :u1
                        AND um.id > COALESCE(r.lastreadmessageid, 0)) AS unread
               FROM {gmk_msg_thread} t
          LEFT JOIN {gmk_class} c ON c.id = t.classid
          LEFT JOIN {course} co ON co.id = t.courseid
          LEFT JOIN {gmk_msg_message} m ON m.id = t.lastmessageid
          LEFT JOIN {gmk_msg_read} r ON r.threadid = t.id AND r.userid = :u0
              WHERE t.id = :id", ['id' => (int)$thread->id, 'u0' => $userid, 'u1' => $userid]);
        $exported = self::export_threads(array_values($rows), $userid);
        return reset($exported);
    }

    /**
     * @param stdClass[] $rows thread rows joined with class/course/last message/unread
     */
    private static function export_threads(array $rows, int $userid): array {
        $userids = [];
        foreach ($rows as $r) {
            $userids[] = (int)$r->studentid;
            $userids[] = (int)($r->instructorid ?? 0);
            $userids[] = (int)($r->supportinstructorid ?? 0);
        }
        $users = self::load_users($userids);

        $out = [];
        foreach ($rows as $r) {
            $class = (object)[
                'id' => (int)$r->classid,
                'instructorid' => (int)($r->instructorid ?? 0),
                'supportinstructorid' => (int)($r->supportinstructorid ?? 0),
                'closed' => (int)($r->closed ?? 1),
            ];
            $isstudent = (int)$r->studentid === $userid;
            $myrole = $isstudent ? self::ROLE_STUDENT : (self::teacher_role($class, $userid) ?? '');

            $counterpart = [];
            if ($isstudent) {
                foreach (self::class_teacher_ids($class) as $tid) {
                    if (isset($users[$tid])) {
                        $counterpart[] = self::export_user($users[$tid], self::teacher_role($class, $tid));
                    }
                }
            } else if (isset($users[(int)$r->studentid])) {
                $counterpart[] = self::export_user($users[(int)$r->studentid], self::ROLE_STUDENT);
            }

            $readonly = !empty($class->closed) || (int)$r->status !== self::STATUS_OPEN
                || ($isstudent && empty(self::class_teacher_ids($class)));

            $out[] = [
                'id'            => (int)$r->id,
                'classid'       => (int)$r->classid,
                'classname'     => (string)($r->classname ?? ''),
                'courseid'      => (int)$r->courseid,
                'coursename'    => (string)($r->coursename ?? ''),
                'studentid'     => (int)$r->studentid,
                'myrole'        => $myrole,
                'readonly'      => $readonly,
                'lastmessageid' => (int)$r->lastmessageid,
                'lastmessageat' => (int)$r->lastmessageat,
                'lastmessage'   => self::excerpt((string)($r->lastbody ?? '')),
                'lastsenderid'  => (int)($r->lastsenderid ?? 0),
                'unread'        => (int)($r->unread ?? 0),
                'counterpart'   => $counterpart,
            ];
        }
        return $out;
    }

    public static function export_message(stdClass $m, int $userid, array $users = null): array {
        if ($users === null) {
            $users = self::load_users([(int)$m->senderid]);
        }
        $sender = $users[(int)$m->senderid] ?? null;
        return [
            'id'          => (int)$m->id,
            'threadid'    => (int)$m->threadid,
            'senderid'    => (int)$m->senderid,
            'sendername'  => $sender ? fullname($sender) : '',
            'senderrole'  => (string)$m->senderrole,
            'body'        => (string)$m->body,
            'timecreated' => (int)$m->timecreated,
            'mine'        => (int)$m->senderid === $userid,
        ];
    }

    private static function export_contact_class(stdClass $class): array {
        return [
            'classid'    => (int)$class->id,
            'classname'  => (string)$class->name,
            'courseid'   => (int)$class->corecourseid,
            'coursename' => (string)($class->coursename ?? ''),
            'threadid'   => 0,
            'unread'     => 0,
            'teachers'   => [],
            'students'   => [],
        ];
    }

    private static function export_user(stdClass $user, string $role): array {
        return [
            'id'       => (int)$user->id,
            'fullname' => fullname($user),
            'imageurl' => self::user_image_url($user),
            'role'     => $role,
            'threadid' => 0,
            'unread'   => 0,
        ];
    }

    /**
     * Students of a class with their thread id / unread count for the teacher.
     */
    private static function class_students_for_teacher(stdClass $class, int $teacherid): array {
        global $DB;
        $fields = self::user_fields_sql('u');
        $params = ['classid' => (int)$class->id, 'groupid' => (int)$class->groupid, 'tid' => $teacherid,
            'u0' => $teacherid];
        $teacherids = self::class_teacher_ids($class);
        [$notin, $notinparams] = $DB->get_in_or_equal($teacherids ?: [0], SQL_PARAMS_NAMED, 'tch', false);
        $params += $notinparams;

        $membership = (int)$class->groupid > 0
            ? "EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.groupid = :groupid AND gm.userid = u.id)"
            : "EXISTS (SELECT 1 FROM {gmk_course_progre} p WHERE p.classid = :classid AND p.userid = u.id)";
        if ((int)$class->groupid > 0) {
            unset($params['classid']);
        } else {
            unset($params['groupid']);
        }
        $params['classid2'] = (int)$class->id;

        $rows = $DB->get_records_sql(
            "SELECT $fields, t.id AS threadid,
                    (SELECT COUNT(1) FROM {gmk_msg_message} um
                      WHERE um.threadid = t.id AND um.deleted = 0 AND um.senderid <> :tid
                        AND um.id > COALESCE(r.lastreadmessageid, 0)) AS unread
               FROM {user} u
          LEFT JOIN {gmk_msg_thread} t ON t.classid = :classid2 AND t.studentid = u.id
          LEFT JOIN {gmk_msg_read} r ON r.threadid = t.id AND r.userid = :u0
              WHERE u.deleted = 0 AND u.suspended = 0 AND u.id $notin AND $membership
           ORDER BY u.lastname, u.firstname", $params);

        $out = [];
        foreach ($rows as $u) {
            $item = self::export_user($u, self::ROLE_STUDENT);
            $item['threadid'] = (int)($u->threadid ?? 0);
            $item['unread'] = (int)($u->unread ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private static function set_read_pointer(int $threadid, int $userid, int $messageid): int {
        global $DB;
        $now = time();
        $existing = $DB->get_record('gmk_msg_read', ['threadid' => $threadid, 'userid' => $userid]);
        if ($existing) {
            if ($messageid > (int)$existing->lastreadmessageid) {
                $DB->update_record('gmk_msg_read', (object)['id' => $existing->id,
                    'lastreadmessageid' => $messageid, 'timeread' => $now]);
                return $messageid;
            }
            return (int)$existing->lastreadmessageid;
        }
        try {
            $DB->insert_record('gmk_msg_read', (object)['threadid' => $threadid, 'userid' => $userid,
                'lastreadmessageid' => $messageid, 'timeread' => $now]);
        } catch (\dml_write_exception $e) {
            // Concurrent first read: retry as an update.
            return self::set_read_pointer($threadid, $userid, $messageid);
        }
        return $messageid;
    }

    /**
     * Plain text only: no HTML, normalised newlines, at most 2 blank lines in a row.
     */
    public static function clean_body(string $body): string {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = strip_tags($body);
        $body = preg_replace("/[^\P{C}\n\t]/u", '', $body) ?? '';
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? '';
        return trim($body);
    }

    private static function excerpt(string $body): string {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if (\core_text::strlen($body) > self::EXCERPT_LENGTH) {
            $body = \core_text::substr($body, 0, self::EXCERPT_LENGTH - 1) . '…';
        }
        return $body;
    }

    private static function user_fields_sql(string $alias): string {
        $fields = \core_user\fields::for_userpic()->get_required_fields();
        $fields = array_unique(array_merge(['id'], $fields));
        return implode(', ', array_map(function($f) use ($alias) {
            return $alias . '.' . $f;
        }, $fields));
    }

    /**
     * @param int[] $ids
     * @return stdClass[] keyed by id
     */
    private static function load_users(array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'usr');
        return $DB->get_records_sql("SELECT " . self::user_fields_sql('u') . " FROM {user} u WHERE u.id $insql",
            $params);
    }

    private static function user_image_url(stdClass $user): string {
        global $PAGE;
        try {
            $picture = new \user_picture($user);
            $picture->size = 1;
            return $picture->get_url($PAGE)->out(false);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Popup notification to the other side of the thread.
     */
    private static function notify(stdClass $thread, ?stdClass $class, stdClass $message): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/message/lib.php');
        if (!$class) {
            return;
        }

        $senderid = (int)$message->senderid;
        if ((int)$thread->studentid === $senderid) {
            $recipients = self::class_teacher_ids($class);
            $url = (new \moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php',
                ['gmk_page' => 'messages', 'gmk_thread' => (int)$thread->id]))->out(false);
        } else {
            $recipients = [(int)$thread->studentid];
            $base = rtrim((string)get_config('local_grupomakro_core', 'student_app_url'), '/');
            if ($base === '') {
                $base = $CFG->wwwroot;
            }
            $url = $base . '/mensajes?thread=' . (int)$thread->id;
        }
        $recipients = array_diff($recipients, [$senderid]);
        if (empty($recipients)) {
            return;
        }

        $sender = \core_user::get_user($senderid);
        $a = (object)[
            'sender'    => fullname($sender),
            'classname' => format_string((string)$class->name),
            'excerpt'   => self::excerpt((string)$message->body),
            'url'       => $url,
        ];
        $subject = get_string('msg_notification_subject', 'local_grupomakro_core', $a);
        $bodytext = get_string('msg_notification_body', 'local_grupomakro_core', $a);
        $courseid = (int)$thread->courseid > 0 && $DB->record_exists('course', ['id' => (int)$thread->courseid])
            ? (int)$thread->courseid : SITEID;

        foreach ($recipients as $recipientid) {
            $recipient = \core_user::get_user($recipientid);
            if (!$recipient || !empty($recipient->deleted) || !empty($recipient->suspended)) {
                continue;
            }
            $msg = new \core\message\message();
            $msg->component         = 'local_grupomakro_core';
            $msg->name              = 'class_message';
            $msg->userfrom          = $sender;
            $msg->userto            = $recipient;
            $msg->subject           = $subject;
            $msg->fullmessage       = $bodytext;
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml   = nl2br(s($bodytext));
            $msg->smallmessage      = get_string('msg_notification_small', 'local_grupomakro_core', $a);
            $msg->notification      = 1;
            $msg->contexturl        = $url;
            $msg->contexturlname    = $a->classname;
            $msg->courseid          = $courseid;
            message_send($msg);
        }
    }
}
