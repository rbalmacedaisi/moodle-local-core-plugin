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
 * Builder for the student credit report (grouped by cuatrimestre).
 *
 * Produces a single structured array consumed by both the on-screen view
 * (AJAX) and the downloadable PDF/Excel report, so all three stay in sync.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

use local_grupomakro_core\external\student\get_student_learning_plan_pensum;
use local_sc_learningplans\local\credit_resolver;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/classes/external/student/get_student_learning_plan_pensum.php');
require_once($GLOBALS['CFG']->dirroot . '/local/sc_learningplans/classes/local/credit_resolver.php');

/**
 * Credit report data builder.
 */
class credit_report {

    /** @var int[] Course statuses considered "with academic history" (used by the 'enrolled' scope). */
    const ENROLLED_STATUSES = [2, 3, 4, 5, 6, 7];

    /**
     * Build the credit report structure for a student.
     *
     * @param int    $userid The student user id.
     * @param int    $planid Optional learning plan id; 0 = all the student's plans.
     * @param string $scope  'all' = every course in the plan; 'enrolled' = only courses with academic history.
     * @return array Structured report (see return shape at the bottom of this method).
     */
    public static function build(int $userid, int $planid = 0, string $scope = 'all'): array {
        global $DB;

        $scope = ($scope === 'enrolled') ? 'enrolled' : 'all';

        // Resolve the plans to report on.
        $plans = [];
        if ($planid > 0) {
            $planname = $DB->get_field('local_learning_plans', 'name', ['id' => $planid]);
            $plans[$planid] = $planname ?: 'Plan';
        } else {
            $planrows = $DB->get_records_sql(
                "SELECT DISTINCT lpu.learningplanid, lp.name
                   FROM {local_learning_users} lpu
                   JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
                  WHERE lpu.userid = :userid
                    AND lpu.userrolename = :rolename
               ORDER BY lp.name ASC",
                ['userid' => $userid, 'rolename' => 'student']
            );
            foreach ($planrows as $pr) {
                $plans[(int)$pr->learningplanid] = $pr->name ?: 'Plan';
            }
        }

        $careers = [];
        foreach ($plans as $pid => $pname) {
            $career = self::build_career($userid, (int)$pid, (string)$pname, $scope);
            if ($career !== null) {
                $careers[] = $career;
            }
        }

        return [
            'student'     => self::resolve_student($userid),
            'generatedat' => date('d/m/Y H:i'),
            'scope'       => $scope,
            'careers'     => $careers,
        ];
    }

    /**
     * Build the report for a single learning plan (career).
     *
     * @param int    $userid
     * @param int    $planid
     * @param string $careername
     * @param string $scope
     * @return array|null Null when the plan has no reportable cuatrimestres.
     */
    private static function build_career(int $userid, int $planid, string $careername, string $scope): ?array {
        global $DB;

        // Reuse the pensum resolver: it returns courses with resolved grade/status/credits.
        $result = get_student_learning_plan_pensum::execute((string)$userid, (string)$planid);
        if (empty($result['pensum'])) {
            return null;
        }
        $pensum = json_decode($result['pensum'], true);
        if (!is_array($pensum) || empty($pensum)) {
            return null;
        }

        // Credit map: read from the canonical per-(plan, course) store, with the
        // legacy gmk_course_progre snapshot kept as last-resort fallback for cases
        // where a (plan, course) has no explicit definition yet.
        $creditmap = credit_resolver::get_for_plan($planid);
        if (empty($creditmap)) {
            $credrows = $DB->get_records_sql(
                "SELECT courseid, MAX(credits) AS cr
                   FROM {gmk_course_progre}
                  WHERE learningplanid = :lpid
                    AND credits > 0
               GROUP BY courseid",
                ['lpid' => $planid]
            );
            foreach ($credrows as $cr) {
                $creditmap[(int)$cr->courseid] = (int)$cr->cr;
            }
        }

        $cuatrimestres = [];
        $sumtotal = 0;
        $sumapproved = 0;
        $sumincourse = 0;

        // Order cuatrimestres by periodid (numeric key of the pensum object).
        $periodids = array_map('intval', array_keys($pensum));
        sort($periodids);

        foreach ($periodids as $periodid) {
            $periodinfo = $pensum[$periodid] ?? $pensum[(string)$periodid] ?? null;
            if (!is_array($periodinfo)) {
                continue;
            }
            $rawcourses = isset($periodinfo['courses']) && is_array($periodinfo['courses'])
                ? $periodinfo['courses'] : [];

            $courses = [];
            $cuatritotal = 0;
            $cuatriapproved = 0;

            foreach ($rawcourses as $c) {
                $status = (int)($c['status'] ?? 0);
                if ($scope === 'enrolled' && !in_array($status, self::ENROLLED_STATUSES, true)) {
                    continue;
                }

                $courseid = (int)($c['courseid'] ?? 0);
                $credits = (int)($c['credits'] ?? 0);
                if ($credits <= 0 && isset($creditmap[$courseid])) {
                    $credits = $creditmap[$courseid];
                }

                $statuslabel = (string)($c['statusLabel'] ?? 'No disponible');
                $isapproved = ($statuslabel === 'Aprobada');
                $isincourse = ($statuslabel === 'Cursando');

                $courses[] = [
                    'coursename'  => (string)($c['coursename'] ?? 'Asignatura'),
                    'credits'     => $credits,
                    'statusLabel' => $statuslabel,
                    'statusColor' => (string)($c['statusColor'] ?? '#5e35b1'),
                    'grade'       => (string)($c['grade'] ?? '-'),
                    'is_module'   => !empty($c['is_module']) ? 1 : 0,
                ];

                $cuatritotal += $credits;
                if ($isapproved) {
                    $cuatriapproved += $credits;
                    $sumapproved += $credits;
                } else if ($isincourse) {
                    $sumincourse += $credits;
                }
                $sumtotal += $credits;
            }

            if (empty($courses)) {
                continue;
            }

            $cuatrimestres[] = [
                'periodid' => (int)$periodid,
                'name'     => (string)($periodinfo['periodName'] ?? 'Cuatrimestre'),
                'courses'  => $courses,
                'subtotal' => [
                    'total'    => $cuatritotal,
                    'approved' => $cuatriapproved,
                ],
            ];
        }

        if (empty($cuatrimestres)) {
            return null;
        }

        $pending = max(0, $sumtotal - $sumapproved - $sumincourse);
        $pct = $sumtotal > 0 ? round(($sumapproved / $sumtotal) * 100, 1) : 0.0;

        return [
            'career'        => $careername,
            'planid'        => $planid,
            'cuatrimestres' => $cuatrimestres,
            'summary'       => [
                'approved' => $sumapproved,
                'incourse' => $sumincourse,
                'pending'  => $pending,
                'total'    => $sumtotal,
                'pct'      => $pct,
            ],
        ];
    }

    /**
     * Resolve the student's display info (name, email, identification).
     *
     * @param int $userid
     * @return array{name:string,email:string,identification:string}
     */
    private static function resolve_student(int $userid): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email, idnumber');
        if (!$user) {
            return ['name' => '--', 'email' => '--', 'identification' => '--'];
        }

        // Prefer the 'documentnumber' custom profile field; fall back to idnumber.
        $identification = (string)($user->idnumber ?? '');
        $fielddoc = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
        if ($fielddoc) {
            $docval = $DB->get_field('user_info_data', 'data', ['fieldid' => $fielddoc->id, 'userid' => $userid]);
            if ($docval !== false && !empty($docval)) {
                $identification = (string)$docval;
            }
        }

        return [
            'name'           => trim($user->firstname . ' ' . $user->lastname),
            'email'          => (string)($user->email ?? '--'),
            'identification' => $identification !== '' ? $identification : '--',
        ];
    }
}
