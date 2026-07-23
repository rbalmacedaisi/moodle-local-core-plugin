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
 * Homologation engine: computes and applies homologations from a set of
 * origin->destination course rules across the students enrolled in both plans.
 *
 * CRITICAL: the origin grade is taken from the RESOLVED grade
 * (course_grade_resolver, the same value the grades modal shows), NOT from the
 * gmk_course_progre.grade snapshot — that snapshot is stale for many courses
 * (placeholder 70.0/Reprobada) and would produce wrong grades.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/course_grade_resolver.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/homologate_course_grade.php');

/**
 * Preview + bulk-apply homologations.
 */
class homologation_engine {

    /** Grade at/above which a homologation results in an approved destination course. */
    const PASS_GRADE = 71.0;

    /** Statuses considered "already approved" on the destination course. */
    const APPROVED_STATUSES = [3, 4];

    /**
     * Compute the pending homologations for a set of rules.
     *
     * @param array $rules Each: [origin_planid, origin_courseid, dest_planid, dest_courseid, homologation_type]
     * @return array ['rows' => [...], 'summary' => [...]]
     */
    public static function compute_pending(array $rules): array {
        global $DB;

        $rules = self::normalize_rules($rules);
        if (empty($rules)) {
            return ['rows' => [], 'summary' => self::empty_summary()];
        }

        // Students enrolled in BOTH the origin and destination plan, cached per pair.
        $studentsByPair = [];
        foreach ($rules as $r) {
            $pk = $r['origin_planid'] . '-' . $r['dest_planid'];
            if (!isset($studentsByPair[$pk])) {
                $studentsByPair[$pk] = self::students_in_both_plans($r['origin_planid'], $r['dest_planid']);
            }
        }

        // Collect the ids we need to pre-load.
        $courseIds = [];
        $planIds   = [];
        $userIds   = [];
        foreach ($rules as $r) {
            $courseIds[$r['origin_courseid']] = true;
            $courseIds[$r['dest_courseid']]   = true;
            $planIds[$r['origin_planid']]     = true;
            $planIds[$r['dest_planid']]       = true;
            foreach ($studentsByPair[$r['origin_planid'] . '-' . $r['dest_planid']] as $u) {
                $userIds[(int)$u->id] = true;
            }
        }
        if (empty($userIds)) {
            return ['rows' => [], 'summary' => self::empty_summary()];
        }

        $courseIdList = array_keys($courseIds);
        $userIdList   = array_keys($userIds);
        $planIdList   = array_keys($planIds);

        // Course names.
        $courseNames = [];
        list($cin, $cp) = $DB->get_in_or_equal($courseIdList, SQL_PARAMS_NAMED, 'cn');
        foreach ($DB->get_records_select('course', "id $cin", $cp, '', 'id, fullname') as $c) {
            $courseNames[(int)$c->id] = $c->fullname;
        }

        // Current period per (user, plan).
        $periodByUserPlan = [];
        list($uin, $up) = $DB->get_in_or_equal($userIdList, SQL_PARAMS_NAMED, 'up');
        list($pin, $pp) = $DB->get_in_or_equal($planIdList, SQL_PARAMS_NAMED, 'pp');
        $rs = $DB->get_recordset_sql(
            "SELECT userid, learningplanid, MAX(currentperiodid) AS mp
               FROM {local_learning_users}
              WHERE userrolename = 'student' AND userid $uin AND learningplanid $pin
           GROUP BY userid, learningplanid",
            $up + $pp
        );
        foreach ($rs as $row) {
            $periodByUserPlan[$row->userid . '-' . $row->learningplanid] = (int)$row->mp;
        }
        $rs->close();

        // Progress rows for the needed (user, course, plan).
        $progreByKey = [];
        list($ccin, $ccp) = $DB->get_in_or_equal($courseIdList, SQL_PARAMS_NAMED, 'pc');
        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, courseid, learningplanid, status, grade, classid, groupid, progress, homologation_type
               FROM {gmk_course_progre}
              WHERE userid $uin AND courseid $ccin AND learningplanid $pin",
            $up + $ccp + $pp
        );
        foreach ($rs as $row) {
            $progreByKey[$row->userid . '|' . $row->courseid . '|' . $row->learningplanid] = $row;
        }
        $rs->close();

        // Build resolver input (one entry per distinct user/course/plan involved).
        $resolverInput = [];
        $seen = [];
        foreach ($rules as $r) {
            foreach ($studentsByPair[$r['origin_planid'] . '-' . $r['dest_planid']] as $u) {
                foreach ([[$r['origin_courseid'], $r['origin_planid']], [$r['dest_courseid'], $r['dest_planid']]] as $pair) {
                    list($cid, $pid) = $pair;
                    $k = $u->id . '|' . $cid . '|' . $pid;
                    if (isset($seen[$k])) {
                        continue;
                    }
                    $seen[$k] = true;
                    $cprow = $progreByKey[$k] ?? null;
                    $resolverInput[] = [
                        'key'             => $k,
                        'userid'          => (int)$u->id,
                        'courseid'        => (int)$cid,
                        'learningplanid'  => (int)$pid,
                        'baseStatus'      => $cprow ? (int)$cprow->status : 0,
                        'progressclassid' => ($cprow && !empty($cprow->classid)) ? (int)$cprow->classid : null,
                        'progressgroupid' => ($cprow && !empty($cprow->groupid)) ? (int)$cprow->groupid : null,
                        'currentperiodid' => $periodByUserPlan[$u->id . '-' . $pid] ?? 0,
                        'coursename'      => $courseNames[$cid] ?? '',
                        'baseProgress'    => ($cprow && $cprow->progress !== null) ? (float)$cprow->progress : null,
                    ];
                }
            }
        }

        $resolutions = \local_grupomakro_core\course_grade_resolver::bulk_resolve_for_records($resolverInput);

        // Build the pending list.
        $rows = [];
        $summary = self::empty_summary();
        foreach ($rules as $r) {
            $students = $studentsByPair[$r['origin_planid'] . '-' . $r['dest_planid']];
            $summary['students_scanned'] += count($students);
            foreach ($students as $u) {
                $ok = $u->id . '|' . $r['origin_courseid'] . '|' . $r['origin_planid'];
                $dk = $u->id . '|' . $r['dest_courseid'] . '|' . $r['dest_planid'];
                $ores = $resolutions[$ok] ?? null;
                $dres = $resolutions[$dk] ?? null;

                $ograde  = ($ores && $ores['grade'] !== null) ? round((float)$ores['grade'], 2) : null;
                $dstatus = $dres ? (int)$dres['status'] : 0;
                $dcp     = $progreByKey[$dk] ?? null;

                $destHomol    = $dcp && !empty($dcp->homologation_type);
                $destApproved = in_array($dstatus, self::APPROVED_STATUSES, true);
                $originApproved = ($ograde !== null && $ograde >= self::PASS_GRADE);

                if ($destApproved || $destHomol) {
                    $summary['dest_already_done']++;
                    continue;
                }
                if (!$originApproved) {
                    $summary['origin_not_approved']++;
                    continue;
                }

                $rows[] = [
                    'userid'            => (int)$u->id,
                    'fullname'          => trim($u->firstname . ' ' . $u->lastname),
                    'idnumber'          => (string)($u->idnumber ?? ''),
                    'origin_planid'     => $r['origin_planid'],
                    'origin_courseid'   => $r['origin_courseid'],
                    'origin_coursename' => $courseNames[$r['origin_courseid']] ?? ('#' . $r['origin_courseid']),
                    'origin_grade'      => $ograde,
                    'dest_planid'       => $r['dest_planid'],
                    'dest_courseid'     => $r['dest_courseid'],
                    'dest_coursename'   => $courseNames[$r['dest_courseid']] ?? ('#' . $r['dest_courseid']),
                    'dest_current_status' => $dstatus,
                    'result_status'     => ($ograde >= self::PASS_GRADE) ? 4 : 5,
                    'homologation_type' => $r['homologation_type'],
                ];
                $summary['pending']++;
            }
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * Apply the pending homologations for the given rules.
     *
     * @param array $rules
     * @return array ['applied' => int, 'errors' => int, 'results' => [...], 'summary' => [...]]
     */
    public static function apply(array $rules): array {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $preview = self::compute_pending($rules);
        $results = [];
        $applied = 0;
        $errors  = 0;

        foreach ($preview['rows'] as $row) {
            $obs = 'Homologación desde ' . $row['origin_coursename']
                 . ' (nota ' . number_format((float)$row['origin_grade'], 2) . '). Gestor de homologaciones.';
            try {
                $res = \local_grupomakro_core\external\student\homologate_course_grade::execute(
                    (int)$row['userid'],
                    (int)$row['dest_planid'],
                    (int)$row['dest_courseid'],
                    (float)$row['origin_grade'],
                    (string)$row['homologation_type'],
                    $obs
                );
                $okrow = (($res['status'] ?? '') === 'ok');
                if ($okrow) {
                    $applied++;
                } else {
                    $errors++;
                }
                $results[] = [
                    'userid'        => $row['userid'],
                    'fullname'      => $row['fullname'],
                    'dest_courseid' => $row['dest_courseid'],
                    'dest_coursename' => $row['dest_coursename'],
                    'grade'         => $row['origin_grade'],
                    'status'        => $okrow ? 'ok' : 'error',
                    'message'       => $res['message'] ?? '',
                ];
            } catch (\Throwable $e) {
                $errors++;
                $results[] = [
                    'userid'        => $row['userid'],
                    'fullname'      => $row['fullname'],
                    'dest_courseid' => $row['dest_courseid'],
                    'dest_coursename' => $row['dest_coursename'],
                    'grade'         => $row['origin_grade'],
                    'status'        => 'error',
                    'message'       => $e->getMessage(),
                ];
            }
        }

        return [
            'applied' => $applied,
            'errors'  => $errors,
            'results' => $results,
            'summary' => $preview['summary'],
        ];
    }

    /**
     * Load all active saved rules from gmk_homologation_rules in the normalized shape.
     *
     * @return array
     */
    public static function load_active_rules(): array {
        global $DB;
        $rows = $DB->get_records('gmk_homologation_rules', ['active' => 1], 'id ASC');
        $rules = [];
        foreach ($rows as $r) {
            $rules[] = [
                'origin_planid'     => (int)$r->origin_planid,
                'origin_courseid'   => (int)$r->origin_courseid,
                'dest_planid'       => (int)$r->dest_planid,
                'dest_courseid'     => (int)$r->dest_courseid,
                'homologation_type' => (string)$r->homologation_type,
            ];
        }
        return $rules;
    }

    /**
     * Students who are 'student' in BOTH plans (or just the plan when equal).
     *
     * @return array<int,\stdClass> objects with id, firstname, lastname, idnumber
     */
    private static function students_in_both_plans(int $originPlanId, int $destPlanId): array {
        global $DB;
        if ($originPlanId <= 0 || $destPlanId <= 0) {
            return [];
        }
        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.idnumber
               FROM {user} u
              WHERE u.deleted = 0
                AND EXISTS (SELECT 1 FROM {local_learning_users} lu1
                             WHERE lu1.userid = u.id AND lu1.learningplanid = :op AND lu1.userrolename = 'student')
                AND EXISTS (SELECT 1 FROM {local_learning_users} lu2
                             WHERE lu2.userid = u.id AND lu2.learningplanid = :dp AND lu2.userrolename = 'student')
           ORDER BY u.firstname, u.lastname",
            ['op' => $originPlanId, 'dp' => $destPlanId]
        );
    }

    /**
     * Coerce rule fields to ints / valid type and drop invalid ones.
     */
    private static function normalize_rules(array $rules): array {
        $allowedtypes = ['suficiencia', 'migracion', 'homologacion', 'practica'];
        $out = [];
        foreach ($rules as $r) {
            $r = (array)$r;
            $op = (int)($r['origin_planid'] ?? 0);
            $oc = (int)($r['origin_courseid'] ?? 0);
            $dp = (int)($r['dest_planid'] ?? 0);
            $dc = (int)($r['dest_courseid'] ?? 0);
            $type = trim((string)($r['homologation_type'] ?? 'homologacion'));
            if (!in_array($type, $allowedtypes, true)) {
                $type = 'homologacion';
            }
            if ($op <= 0 || $oc <= 0 || $dp <= 0 || $dc <= 0) {
                continue;
            }
            $out[] = [
                'origin_planid'     => $op,
                'origin_courseid'   => $oc,
                'dest_planid'       => $dp,
                'dest_courseid'     => $dc,
                'homologation_type' => $type,
            ];
        }
        return $out;
    }

    private static function empty_summary(): array {
        return [
            'pending'             => 0,
            'dest_already_done'   => 0,
            'origin_not_approved' => 0,
            'students_scanned'    => 0,
        ];
    }
}
