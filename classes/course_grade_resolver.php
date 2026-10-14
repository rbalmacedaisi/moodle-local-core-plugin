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
 * Shared course grade resolution for the grupomakro_core plugin.
 *
 * This class encapsulates the cascade of sources used to determine the
 * effective grade of a student in a course. It mirrors the methodology
 * used by the academic panel grade modal
 * (local_grupomakro_get_student_learning_plan_pensum) so that other
 * views (e.g. the student timeline) can reuse exactly the same rules
 * and avoid divergent calculations.
 *
 * The class is intentionally self-contained: it does not depend on
 * any helper that lives in the existing external function files, to
 * avoid coupling or reference breakage. Any change to the grade
 * resolution logic should be made here and mirrored (or routed) to
 * the original endpoint.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolver that determines a student's effective grade for a course.
 *
 * Typical usage:
 *
 *   $result = course_grade_resolver::resolve_course_grade(
 *       userid:           123,
 *       corecourseid:     456,
 *       learningPlanId:   1,
 *       baseStatus:       5,
 *       progressclassid:  789,
 *       progressgroupid:  12,
 *       currentperiodid:  3,
 *       coursename:       'Matemática I'
 *   );
 *
 *   // $result shape:
 *   // [
 *   //   'grade'      => 85.0,         // null if no grade could be resolved
 *   //   'source'     => 'class_category',
 *   //   'is_module'  => false,
 *   //   'status'     => 3,            // virtual-updated status
 *   //   'progress'   => 100.0,        // 100 when virtually approved, else base progress
 *   //   'semaphore'  => 'green',      // blue/red/orange/green
 *   // ]
 */
class course_grade_resolver
{
    /** Statuses that can be virtually approved based on the resolved grade. */
    const VIRTUAL_APPROVABLE_STATUSES = [0, 1, 5];

    /** Passing grade threshold for the standard virtual-approval rule. */
    const DEFAULT_PASSING_GRADE = 70.0;

    /**
     * Stricter threshold used when the base status was 5 (Reprobada) so
     * that a marginal recovery does not flip a failed subject.
     */
    const RETRY_PASSING_GRADE = 71.0;

    /** Codes returned in the "source" field. Keep stable for logs/tests. */
    const SOURCE_NONE                       = 'none';
    const SOURCE_REVALIDATION               = 'revalidation';
    const SOURCE_MODULE_PRIORITY            = 'module_priority';
    const SOURCE_MANUAL_NOTA_FINAL_INTEGRADA = 'manual_nota_final_integrada';
    const SOURCE_MANUAL_NOTA_FINAL_INTEGRADA_SAME_NAME = 'manual_nota_final_integrada_same_name';
    const SOURCE_CLASS_CATEGORY             = 'class_category';
    const SOURCE_GROUP_CLASS_CATEGORY       = 'group_class_category';
    const SOURCE_MEMBERSHIP_CLASS_CATEGORY  = 'membership_class_category';
    const SOURCE_PLAN_CLASS_CATEGORY        = 'plan_class_category';
    const SOURCE_COURSE_TOTAL               = 'course_total';
    const SOURCE_COURSE_TOTAL_OUT_OF_RANGE  = 'course_total_out_of_range';
    const SOURCE_GMK_COURSE_PROGRE          = 'gmk_course_progre';
    const SOURCE_GMK_COURSE_PROGRE_AFTER_OUT = 'gmk_course_progre_after_out_of_range_course_total';
    const SOURCE_GMK_COURSE_PROGRE_OUT_OF_RANGE = 'gmk_course_progre_out_of_range';

    /** Human-friendly labels for the status field. */
    const STATUS_LABEL = [
        0  => 'No disponible',
        1  => 'Disponible',
        2  => 'Cursando',
        3  => 'Aprobada',
        4  => 'Aprobada',
        5  => 'Reprobada',
        6  => 'Revalida',
        7  => 'Reprobado',
        99 => 'Migración Pendiente',
    ];

    /** Hex colors used to render the status chip. */
    const STATUS_COLOR = [
        0  => '#5e35b1',
        1  => '#1e88e5',
        2  => '#11d1bf',
        3  => '#0cce7b',
        4  => '#0cce7b',
        5  => '#ec407a',
        6  => '#ec407a',
        7  => '#ec407a',
        99 => '#ff9800',
    ];

    /**
     * Resolve the effective grade of a single student in a single course.
     *
     * @param int      $userid          Moodle user id of the student.
     * @param int      $corecourseid    Moodle course id (course.id) the subject is built on.
     * @param int      $learningPlanId  Learning plan id (local_learning_plans.id).
     * @param int      $baseStatus      Persisted status in gmk_course_progre (or 0 if absent).
     * @param int|null $progressclassid Class id (gmk_class.id) recorded in gmk_course_progre, if any.
     * @param int|null $progressgroupid Group id (groups.id) recorded in gmk_course_progre, if any.
     * @param int      $currentperiodid Student current period id (local_learning_users.currentperiodid).
     * @param string|null $coursename   Full course name, used for historical fallback only.
     * @param float|null $baseProgress  Base progress percentage (0..100) for the subject, if known.
     *
     * @return array{grade:?float,source:string,is_module:bool,status:int,progress:float,semaphore:string}
     */
    public static function resolve_course_grade(
        int $userid,
        int $corecourseid,
        int $learningPlanId,
        int $baseStatus = 0,
        ?int $progressclassid = null,
        ?int $progressgroupid = null,
        int $currentperiodid = 0,
        ?string $coursename = null,
        ?float $baseProgress = null
    ): array {
        global $DB;

        if ($userid <= 0 || $corecourseid <= 0) {
            return self::empty_result($baseStatus, $baseProgress);
        }

        // Latest-grade model: read what sync_progress() stored; when the row was
        // not synced yet, resolve live with the same rules.
        $stored = $DB->get_record('gmk_course_progre',
            ['userid' => $userid, 'courseid' => $corecourseid, 'learningplanid' => $learningPlanId],
            'id, grade, status, progress, grade_source', IGNORE_MULTIPLE);
        if ($stored && !empty($stored->grade_source)) {
            $status = (int)$stored->status;
            return [
                'grade'     => $stored->grade !== null ? round((float)$stored->grade, 2) : null,
                'source'    => (string)$stored->grade_source,
                'is_module' => $stored->grade_source === self::SOURCE_LATEST_MODULE,
                'status'    => $status,
                'progress'  => (float)$stored->progress,
                'semaphore' => self::semaphore_for_status($status),
            ];
        }

        $latest = self::resolve_latest_grade($userid, $corecourseid, $learningPlanId,
            (int)($progressclassid ?: 0), (int)($progressgroupid ?: 0), $coursename, $baseStatus);
        if ($latest['grade'] === null) {
            return self::empty_result($baseStatus, $baseProgress);
        }
        $status = self::status_for_latest((int)$baseStatus, $latest);
        $progress = in_array($status, [3, 4], true) ? 100.0 : ($baseProgress !== null ? (float)$baseProgress : 0.0);
        return [
            'grade'     => $latest['grade'],
            'source'    => $latest['source'],
            'is_module' => (bool)$latest['is_module'],
            'status'    => $status,
            'progress'  => $progress,
            'semaphore' => self::semaphore_for_status($status),
        ];
    }

    /**
     * Bulk-resolve grades for every student in a given learning plan, cohort
     * (periodo_ingreso) and jornada that is enrolled in a specific core course.
     *
     * The result is shaped for direct consumption by the student timeline:
     *
     *   [
     *     'userid' => [
     *       'grade'      => 85.0,
     *       'source'     => 'class_category',
     *       'is_module'  => false,
     *       'status'     => 3,
     *       'semaphore'  => 'green',
     *     ],
     *     ...
     *   ]
     *
     * Only students that have any record in gmk_course_progre for the supplied
     * course/plan are evaluated; that mirrors the existing
     * get_courses_with_projections behaviour but uses the unified cascade.
     *
     * @param int    $learningPlanId Learning plan id.
     * @param int    $corecourseid   Course id to evaluate.
     * @param string $cohort         Periodo_ingreso (year or year-suffix, e.g. "2026" or "2026-I").
     * @param string $jornada        "Diurna", "Nocturna", "Sabatina" or "ALL".
     *
     * @return array<int,array<string,mixed>> Map of userid => resolution row.
     */
    public static function resolve_grades_for_course_in_cohort(
        int $learningPlanId,
        int $corecourseid,
        string $cohort,
        string $jornada = 'ALL'
    ): array {
        global $DB;

        $result = [];

        if ($learningPlanId <= 0 || $corecourseid <= 0) {
            return $result;
        }

        // Build cohort/jornada filter matching the timeline's user filter.
        $intakeOperator = preg_match('/^\d{4}$/', $cohort) ? 'LIKE' : '=';
        $intakePattern  = preg_match('/^\d{4}$/', $cohort) ? $cohort . '%' : $cohort;
        $jornadaFilter  = '';
        $jornadaParam   = [];
        if ($jornada !== 'ALL') {
            $jornadaFilter = "AND uid_jornada.data = ?";
            $jornadaParam  = [$jornada];
        }

        // Get all students in the cohort/plan/jornada.
        $studentSql = "
            SELECT DISTINCT lu.userid,
                   lu.currentperiodid,
                   lu.currentsubperiodid,
                   gcp.status        AS base_status,
                   gcp.progress      AS base_progress,
                   gcp.classid       AS progressclassid,
                   gcp.groupid       AS progressgroupid
              FROM {local_learning_users} lu
              JOIN {user_info_data} uid_intake
                ON uid_intake.userid = lu.userid
               AND uid_intake.fieldid = (SELECT id FROM {user_info_field}
                                           WHERE shortname = 'periodo_ingreso' LIMIT 1)
         LEFT JOIN {user_info_data} uid_jornada
                ON uid_jornada.userid = lu.userid
               AND uid_jornada.fieldid = (SELECT id FROM {user_info_field}
                                           WHERE shortname = 'gmkjourney' LIMIT 1)
              JOIN {gmk_course_progre} gcp
                ON gcp.userid = lu.userid
               AND gcp.learningplanid = lu.learningplanid
               AND gcp.courseid = :gcp_courseid
             WHERE lu.learningplanid = :lu_lpid
               AND uid_intake.data $intakeOperator ?
               $jornadaFilter
        ";
        $studentParams = array_merge(
            [
                'gcp_courseid' => $corecourseid,
                'lu_lpid'      => $learningPlanId,
            ],
            $jornadaParam
        );
        $studentParams['intake'] = $intakePattern;
        // Place the intake pattern param in the right order.
        $studentParams = ['gcp_courseid' => $corecourseid, 'lu_lpid' => $learningPlanId, 'intake' => $intakePattern] + $jornadaParam;

        $studentRows = $DB->get_records_sql($studentSql, $studentParams);
        if (empty($studentRows)) {
            return $result;
        }

        // Course name (for historical fallback).
        $courseName = (string)($DB->get_field('course', 'fullname', ['id' => $corecourseid]) ?: '');

        foreach ($studentRows as $row) {
            $baseStatus = isset($row->base_status) ? (int)$row->base_status : 0;
            $baseProgress = isset($row->base_progress) ? (float)$row->base_progress : null;
            $progressclassid = !empty($row->progressclassid) ? (int)$row->progressclassid : null;
            $progressgroupid = !empty($row->progressgroupid) ? (int)$row->progressgroupid : null;
            $currentperiodid = !empty($row->currentperiodid) ? (int)$row->currentperiodid : 0;

            $resolution = self::resolve_course_grade(
                (int)$row->userid,
                $corecourseid,
                $learningPlanId,
                $baseStatus,
                $progressclassid,
                $progressgroupid,
                $currentperiodid,
                $courseName,
                $baseProgress
            );
            $result[(int)$row->userid] = $resolution;
        }

        return $result;
    }

    /**
     * Aggregate per-student resolutions into the approved/failed/pending
     * counts and the semaphore color expected by the timeline frontend.
     *
     * @param array<int,array<string,mixed>> $resolutions Map of userid => resolution.
     * @return array{approved_count:int,failed_count:int,pending_count:int,total_students:int,semaphore:string}
     */
    public static function summarize_for_timeline(array $resolutions): array
    {
        $approved = 0;
        $failed   = 0;
        $pending  = 0;
        foreach ($resolutions as $row) {
            $grade = $row['grade'] ?? null;
            if ($grade === null) {
                $pending++;
            } else if (self::is_passing_grade((float)$grade, (int)($row['status'] ?? 0))) {
                $approved++;
            } else {
                $failed++;
            }
        }
        $total = $approved + $failed + $pending;
        return [
            'approved_count' => $approved,
            'failed_count'   => $failed,
            'pending_count'  => $pending,
            'total_students' => $total,
            'semaphore'      => self::semaphore_for_counts($approved, $failed, $pending),
        ];
    }

    /**
     * Determine if a grade passes the threshold for a given base status.
     */
    public static function is_passing_grade(float $grade, int $baseStatus): bool
    {
        $threshold = ($baseStatus === 5) ? self::RETRY_PASSING_GRADE : self::DEFAULT_PASSING_GRADE;
        return $grade >= $threshold;
    }

    /**
     * Map a status code to the timeline's "semaphore" string.
     */
    public static function semaphore_for_status(int $status): string
    {
        switch ($status) {
            case 3:
            case 4:
            case 6:
                return 'green';
            case 5:
            case 7:
                return 'red';
            case 2:
                return 'orange';
            default:
                return 'blue';
        }
    }

    /**
     * Map approved/failed/pending counts to the timeline's "semaphore" string.
     * Mirrors the original timeline logic to preserve the frontend contract.
     */
    public static function semaphore_for_counts(int $approved, int $failed, int $pending): string
    {
        $total = $approved + $failed + $pending;
        if ($total === 0) {
            return 'blue';
        }
        if ($approved > 0 && $failed === 0 && $pending === 0) {
            return 'green';
        }
        if ($failed > 0 && $pending === 0) {
            return 'red';
        }
        if ($approved > 0 && $failed > 0) {
            return 'orange';
        }
        return 'blue';
    }

    // -------------------------------------------------------------------
    // Internal cascade
    // -------------------------------------------------------------------

    /**
     * Run the full cascade of sources and return the first valid grade.
     *
     * @return array{0:?float,1:string,2:bool} [grade, source, is_module]
     */
    private static function resolve_raw_grade(
        $DB,
        int $userid,
        int $corecourseid,
        int $learningPlanId,
        int $currentperiodid,
        int $progressclassid,
        int $progressgroupid,
        int $baseStatus,
        ?string $coursename
    ): array {
        $moduleClassIds = [];
        $moduleGroupIds = [];

        // Pre-compute the bulk structures the cascade needs.
        $planClassCategoryGradeByCourseId = self::bulk_plan_category_grades(
            $DB, $userid, $corecourseid, $learningPlanId
        );
        $manualIntegratedGradeByCourseId = self::bulk_manual_integrated_grades(
            $DB, $userid, $corecourseid
        );
        $manualIntegratedGradeByCourseName = self::bulk_manual_integrated_grades_by_name(
            $DB, $userid, $coursename
        );
        $gradesByCourseId = self::bulk_course_total_grades(
            $DB, $userid, $corecourseid
        );

        // Class category grades (the heavy part).
        $membershipClassCourseByClassId = self::bulk_membership_classes(
            $DB, $userid, $corecourseid, $learningPlanId
        );
        list(
            $classGradeByClassId,
            $classGradeByGroupId,
            $membershipClassGradesByCourseId,
            $membershipClassIsModuleByCourseId
        ) = self::compute_class_category_grades(
            $DB,
            $userid,
            $corecourseid,
            $learningPlanId,
            $progressclassid,
            $progressgroupid,
            $membershipClassCourseByClassId,
            $moduleClassIds,
            $moduleGroupIds
        );

        $courseidkey = (int)$corecourseid;
        $isModuleGrade = false;
        $coursegrade = null;
        $gradesource = self::SOURCE_NONE;

        // -2) Absolute top priority: a CONSOLIDATED REVALIDATION. Mirrors the
        // same branch in get_student_learning_plan_pensum — a passed
        // revalidation sets the institutional grade to PASS_FINAL_GRADE and
        // that value exists only in gmk_revalidations / gmk_course_progre,
        // never in the gradebook.
        $revalidationgrade = self::revalidation_grade($DB, $userid, $corecourseid, $learningPlanId);
        if ($revalidationgrade !== null) {
            return [$revalidationgrade, self::SOURCE_REVALIDATION, false];
        }

        // -1) Top priority: MODULE grade for this course.
        $moduleGradeCandidate = null;
        if ($progressclassid > 0 && !empty($moduleClassIds[$progressclassid]) && array_key_exists($progressclassid, $classGradeByClassId)) {
            $moduleGradeCandidate = (float)$classGradeByClassId[$progressclassid];
        } else if ($progressgroupid > 0 && !empty($moduleGroupIds[$progressgroupid]) && array_key_exists($progressgroupid, $classGradeByGroupId)) {
            $moduleGradeCandidate = (float)$classGradeByGroupId[$progressgroupid];
        } else if (!empty($membershipClassIsModuleByCourseId[$courseidkey]) && !empty($membershipClassGradesByCourseId[$courseidkey])) {
            $valid = array_values(array_filter(
                array_map('floatval', $membershipClassGradesByCourseId[$courseidkey]),
                function ($v) { return ($v >= 0 && $v <= 100); }
            ));
            if (!empty($valid)) {
                $moduleGradeCandidate = round(max($valid), 2);
            }
        }
        if ($moduleGradeCandidate !== null && $moduleGradeCandidate >= 0 && $moduleGradeCandidate <= 100) {
            $coursegrade = $moduleGradeCandidate;
            $gradesource = self::SOURCE_MODULE_PRIORITY;
            $isModuleGrade = true;
        }

        // 0) Explicit "Nota Final Integrada" (when there is no module grade).
        if ($coursegrade === null && array_key_exists($courseidkey, $manualIntegratedGradeByCourseId)) {
            $candidate = (float)$manualIntegratedGradeByCourseId[$courseidkey];
            if ($candidate >= 0 && $candidate <= 100) {
                $coursegrade = $candidate;
                $gradesource = self::SOURCE_MANUAL_NOTA_FINAL_INTEGRADA;
            }
        }

        // Historical fallback by subject name (only for historical statuses).
        $historicalstatus = in_array((int)$baseStatus, [3, 4, 5, 6, 7], true);
        if ($coursegrade === null && $historicalstatus) {
            $coursenamekey = trim((string)($coursename ?? ''));
            if ($coursenamekey !== '' && array_key_exists($coursenamekey, $manualIntegratedGradeByCourseName)) {
                $candidate = (float)$manualIntegratedGradeByCourseName[$coursenamekey];
                if ($candidate >= 0 && $candidate <= 100) {
                    $coursegrade = $candidate;
                    $gradesource = self::SOURCE_MANUAL_NOTA_FINAL_INTEGRADA_SAME_NAME;
                }
            }
        }

        // 1) Class category grade (progressclassid).
        if ($coursegrade === null && $progressclassid > 0 && array_key_exists($progressclassid, $classGradeByClassId)) {
            $coursegrade = (float)$classGradeByClassId[$progressclassid];
            $gradesource = self::SOURCE_CLASS_CATEGORY;
            $isModuleGrade = !empty($moduleClassIds[$progressclassid]);
        } else if ($coursegrade === null && $progressgroupid > 0 && array_key_exists($progressgroupid, $classGradeByGroupId)) {
            $coursegrade = (float)$classGradeByGroupId[$progressgroupid];
            $gradesource = self::SOURCE_GROUP_CLASS_CATEGORY;
            $isModuleGrade = !empty($moduleGroupIds[$progressgroupid]);
        } else if ($coursegrade === null && !empty($membershipClassGradesByCourseId[$courseidkey])) {
            // 1b) Membership class category.
            $valid = array_values(array_filter(
                array_map('floatval', $membershipClassGradesByCourseId[$courseidkey]),
                function ($v) { return ($v >= 0 && $v <= 100); }
            ));
            if (!empty($valid)) {
                $coursegrade = round(max($valid), 2);
                $gradesource = self::SOURCE_MEMBERSHIP_CLASS_CATEGORY;
                $isModuleGrade = !empty($membershipClassIsModuleByCourseId[$courseidkey]);
            }
        }

        // 1c) Plan class category (any category in the plan with a sane grade).
        if ($coursegrade === null && array_key_exists($courseidkey, $planClassCategoryGradeByCourseId)) {
            $candidate = (float)$planClassCategoryGradeByCourseId[$courseidkey];
            if ($candidate >= 0 && $candidate <= 100) {
                $coursegrade = $candidate;
                $gradesource = self::SOURCE_PLAN_CLASS_CATEGORY;
            }
        }

        // 2) Moodle course total.
        if ($coursegrade === null && array_key_exists($courseidkey, $gradesByCourseId)) {
            $candidate = (float)$gradesByCourseId[$courseidkey];
            if ($candidate >= 0 && $candidate <= 100) {
                $coursegrade = $candidate;
                $gradesource = self::SOURCE_COURSE_TOTAL;
            } else {
                $gradesource = self::SOURCE_COURSE_TOTAL_OUT_OF_RANGE;
            }
        }

        return [$coursegrade, $gradesource, $isModuleGrade];
    }

    /**
     * Consolidated revalidation grade for (student, course, plan), or null when
     * the student has no passed revalidation for that subject.
     *
     * Only 'approved' rows matter: a failed revalidation keeps the original
     * grade, which the rest of the cascade already resolves. learningplanid can
     * be 0 on older rows (it is copied from gmk_course_progre), so those are
     * accepted rather than silently dropped.
     */
    private static function revalidation_grade($DB, int $userid, int $corecourseid, int $learningPlanId): ?float
    {
        if ($userid <= 0 || $corecourseid <= 0) {
            return null;
        }
        $exists = $DB->record_exists_select(
            'gmk_revalidations',
            "userid = :userid AND corecourseid = :ccid AND result = :result
             AND (learningplanid = :lpid OR learningplanid = 0)",
            [
                'userid' => $userid,
                'ccid'   => $corecourseid,
                'result' => 'approved',
                'lpid'   => $learningPlanId,
            ]
        );
        if (!$exists) {
            return null;
        }
        return (float)\local_grupomakro_core\local\revalida_manager::PASS_FINAL_GRADE;
    }

    /**
     * Build the "plan class category" map: [corecourseid => gradeval].
     */
    private static function bulk_plan_category_grades($DB, int $userid, int $corecourseid, int $learningPlanId): array
    {
        $effectiveplanwhere = "(c.learningplanid = :lpid OR EXISTS (
                                  SELECT 1
                                    FROM {local_learning_courses} lpcmap
                                   WHERE lpcmap.id = c.courseid
                                     AND lpcmap.learningplanid = :lpidmap
                              ))";
        $effectiveplanparams = [
            'lpid'     => $learningPlanId,
            'lpidmap'  => $learningPlanId,
        ];

        $rows = $DB->get_records_sql(
            "SELECT c.corecourseid,
                    MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                             THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval
               FROM {gmk_class} c
               JOIN {grade_items} gi
                 ON gi.courseid = c.corecourseid
                AND gi.itemtype = 'category'
                AND gi.iteminstance = c.gradecategoryid
          LEFT JOIN {grade_grades} gg
                 ON gg.itemid = gi.id
                AND gg.userid = :userid
              WHERE $effectiveplanwhere
                AND c.gradecategoryid > 0
                AND c.corecourseid = :cid
           GROUP BY c.corecourseid
           ORDER BY c.corecourseid ASC",
            ['userid' => $userid, 'cid' => $corecourseid] + $effectiveplanparams
        );

        $out = [];
        foreach ($rows as $r) {
            if (!is_null($r->gradeval)) {
                $out[(int)$r->corecourseid] = round((float)$r->gradeval, 2);
            }
        }
        return $out;
    }

    /**
     * Build the "manual Nota Final Integrada" map: [courseid => gradeval].
     */
    private static function bulk_manual_integrated_grades($DB, int $userid, int $corecourseid): array
    {
        $rows = $DB->get_records_sql(
            "SELECT gi.courseid,
                    MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                             THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval
               FROM {grade_items} gi
          LEFT JOIN {grade_grades} gg
                 ON gg.itemid = gi.id
                AND gg.userid = :userid
              WHERE (gi.itemname LIKE :nfi_any
                     OR gi.itemname LIKE :nfi_alt1
                     OR gi.itemname LIKE :nfi_alt2)
                AND gi.courseid = :cid
           GROUP BY gi.courseid
           ORDER BY gi.courseid ASC",
            [
                'userid'   => $userid,
                'cid'      => $corecourseid,
                'nfi_any'  => '%Nota Final Integrada%',
                'nfi_alt1' => '%Final Integrada%',
                'nfi_alt2' => '%Nota Final%',
            ]
        );
        $out = [];
        foreach ($rows as $r) {
            if (!is_null($r->gradeval)) {
                $out[(int)$r->courseid] = round((float)$r->gradeval, 2);
            }
        }
        return $out;
    }

    /**
     * Build the "Nota Final Integrada by fullname" fallback map.
     */
    private static function bulk_manual_integrated_grades_by_name($DB, int $userid, ?string $coursename): array
    {
        if (empty($coursename)) {
            return [];
        }
        $rows = $DB->get_records_sql(
            "SELECT c.fullname,
                    MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                             THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval
               FROM {grade_items} gi
               JOIN {course} c ON c.id = gi.courseid
          LEFT JOIN {grade_grades} gg
                 ON gg.itemid = gi.id
                AND gg.userid = :userid
              WHERE (gi.itemname LIKE :nfi_any
                     OR gi.itemname LIKE :nfi_alt1
                     OR gi.itemname LIKE :nfi_alt2)
                AND c.fullname = :cname
           GROUP BY c.fullname
           ORDER BY c.fullname ASC",
            [
                'userid'   => $userid,
                'cname'    => $coursename,
                'nfi_any'  => '%Nota Final Integrada%',
                'nfi_alt1' => '%Final Integrada%',
                'nfi_alt2' => '%Nota Final%',
            ]
        );
        $out = [];
        foreach ($rows as $r) {
            if (!is_null($r->gradeval)) {
                $out[(string)$r->fullname] = round((float)$r->gradeval, 2);
            }
        }
        return $out;
    }

    /**
     * Build the "Moodle course total" map: [courseid => gradeval].
     */
    private static function bulk_course_total_grades($DB, int $userid, int $corecourseid): array
    {
        $rows = $DB->get_records_sql(
            "SELECT gi.courseid,
                    MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                             THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval_sane,
                    MAX(COALESCE(gg.finalgrade, gg.rawgrade)) AS gradeval_any
               FROM {grade_items} gi
          LEFT JOIN {grade_grades} gg
                 ON gg.itemid = gi.id
                AND gg.userid = :userid
              WHERE gi.itemtype = 'course'
                AND gi.courseid = :cid
           GROUP BY gi.courseid
           ORDER BY gi.courseid ASC",
            ['userid' => $userid, 'cid' => $corecourseid]
        );
        $out = [];
        foreach ($rows as $r) {
            $val = null;
            if (!is_null($r->gradeval_sane)) {
                $val = (float)$r->gradeval_sane;
            } else if (!is_null($r->gradeval_any)) {
                $val = (float)$r->gradeval_any;
            }
            if ($val !== null) {
                $out[(int)$r->courseid] = round($val, 2);
            }
        }
        return $out;
    }

    /**
     * Build the membership classes map: [classid => corecourseid].
     */
    private static function bulk_membership_classes($DB, int $userid, int $corecourseid, int $learningPlanId): array
    {
        $effectiveplanwhere = "(c.learningplanid = :lpid OR EXISTS (
                                  SELECT 1
                                    FROM {local_learning_courses} lpcmap
                                   WHERE lpcmap.id = c.courseid
                                     AND lpcmap.learningplanid = :lpidmap
                              ))";
        $effectiveplanparams = [
            'lpid'    => $learningPlanId,
            'lpidmap' => $learningPlanId,
        ];

        $membershipClassCourseByClassId = [];

        // Plan-scoped membership.
        $rows = $DB->get_records_sql(
            "SELECT c.id, c.corecourseid
               FROM {gmk_class} c
               JOIN {groups_members} gm ON gm.groupid = c.groupid
              WHERE gm.userid = :userid
                AND $effectiveplanwhere
                AND c.gradecategoryid > 0
                AND c.corecourseid = :cid
           ORDER BY c.id ASC",
            ['userid' => $userid, 'cid' => $corecourseid] + $effectiveplanparams
        );
        foreach ($rows as $r) {
            $membershipClassCourseByClassId[(int)$r->id] = (int)$r->corecourseid;
        }

        // Cross-plan module membership.
        $rows = $DB->get_records_sql(
            "SELECT c.id, c.corecourseid
               FROM {gmk_class} c
               JOIN {groups_members} gm ON gm.groupid = c.groupid
              WHERE gm.userid = :userid
                AND c.is_module = 1
                AND c.gradecategoryid > 0
                AND c.corecourseid = :cid
           ORDER BY c.id ASC",
            ['userid' => $userid, 'cid' => $corecourseid]
        );
        foreach ($rows as $r) {
            $membershipClassCourseByClassId[(int)$r->id] = (int)$r->corecourseid;
        }

        return $membershipClassCourseByClassId;
    }

    /**
     * Compute class category grades for the candidate class ids, with weight
     * normalisation and attendance override.
     *
     * @param array<int,int> $membershipClassCourseByClassId
     * @param array<int,bool> $moduleClassIds   Out-parameter populated with is_module class ids.
     * @param array<int,bool> $moduleGroupIds  Out-parameter populated with is_module group ids.
     *
     * @return array{
     *   0: array<int,float>,
     *   1: array<int,float>,
     *   2: array<int,float[]>,
     *   3: array<int,bool>
     * }
     */
    private static function compute_class_category_grades(
        $DB,
        int $userid,
        int $corecourseid,
        int $learningPlanId,
        int $progressclassid,
        int $progressgroupid,
        array $membershipClassCourseByClassId,
        array &$moduleClassIds,
        array &$moduleGroupIds
    ): array {
        $classGradeByClassId = [];
        $classGradeByGroupId = [];
        $membershipClassGradesByCourseId = [];
        $membershipClassIsModuleByCourseId = [];

        $candidateClassIds = array_values(array_unique(array_merge(
            $progressclassid > 0 ? [$progressclassid] : [],
            array_keys($membershipClassCourseByClassId)
        )));
        if (empty($candidateClassIds)) {
            return [$classGradeByClassId, $classGradeByGroupId, $membershipClassGradesByCourseId, $membershipClassIsModuleByCourseId];
        }

        list($classInSql, $classInParams) = $DB->get_in_or_equal($candidateClassIds, SQL_PARAMS_NAMED, 'clid');
        $classrows = $DB->get_records_sql(
            "SELECT c.id, c.groupid, c.corecourseid, c.gradecategoryid, c.is_module, c.coursesectionid
               FROM {gmk_class} c
              WHERE c.id $classInSql
                AND c.gradecategoryid > 0
                AND c.corecourseid > 0
           ORDER BY c.id ASC",
            $classInParams
        );
        if (empty($classrows)) {
            return [$classGradeByClassId, $classGradeByGroupId, $membershipClassGradesByCourseId, $membershipClassIsModuleByCourseId];
        }

        $allCatIds = [];
        $allCourseIds = [];
        foreach ($classrows as $cr) {
            $allCatIds[]    = (int)$cr->gradecategoryid;
            $allCourseIds[] = (int)$cr->corecourseid;
        }
        $allCatIds    = array_values(array_unique($allCatIds));
        $allCourseIds = array_values(array_unique($allCourseIds));
        if (empty($allCatIds) || empty($allCourseIds)) {
            return [$classGradeByClassId, $classGradeByGroupId, $membershipClassGradesByCourseId, $membershipClassIsModuleByCourseId];
        }

        list($catInSql, $catInParams) = $DB->get_in_or_equal($allCatIds, SQL_PARAMS_NAMED, 'pcat');
        list($ccInSql,  $ccInParams)  = $DB->get_in_or_equal($allCourseIds, SQL_PARAMS_NAMED, 'pcc');

        // Aggregation type per category.
        $caggByCatId = [];
        $caggRows = $DB->get_records_sql(
            "SELECT gc.id, gc.aggregation
               FROM {grade_categories} gc
              WHERE gc.id $catInSql",
            $catInParams
        );
        foreach ($caggRows as $ctr) {
            $caggByCatId[(int)$ctr->id] = (int)$ctr->aggregation;
        }

        // Individual grade items (mod + manual) for the categories.
        $gradeitems = $DB->get_records_sql(
            "SELECT gi.id, gi.categoryid, gi.courseid, gi.itemmodule,
                    gi.iteminstance, gi.grademax,
                    gi.aggregationcoef, gi.aggregationcoef2
               FROM {grade_items} gi
              WHERE gi.courseid $ccInSql
                AND gi.categoryid $catInSql
                AND gi.itemtype IN ('mod', 'manual')",
            $catInParams + $ccInParams
        );

        // Compute weight_pct per item (same normalisation as teacher gradebook).
        $catRawSums = [];
        foreach ($gradeitems as $gi) {
            $cagg = $caggByCatId[(int)$gi->categoryid] ?? 13;
            if ($cagg === 10 || $cagg === 2) {
                $catRawSums[(int)$gi->categoryid] =
                    ($catRawSums[(int)$gi->categoryid] ?? 0.0) + (float)$gi->aggregationcoef;
            }
        }
        $itemWeightPct = [];
        foreach ($gradeitems as $gi) {
            $cagg   = $caggByCatId[(int)$gi->categoryid] ?? 13;
            $raww   = ($cagg === 10 || $cagg === 2) ? (float)$gi->aggregationcoef : (float)$gi->aggregationcoef2;
            $catsum = $catRawSums[(int)$gi->categoryid] ?? 0;
            $itemWeightPct[(int)$gi->id] = ($cagg === 10 || $cagg === 2)
                ? ($catsum > 0 ? ($raww / $catsum) * 100 : 0)
                : $raww * 100;
        }

        // Bulk-fetch grade_grades for all items for this student.
        $gradesbyitemid = [];
        $allItemIds = array_values(array_map('intval', array_keys((array)$gradeitems)));
        if (!empty($allItemIds)) {
            list($itemInSql, $itemInParams) = $DB->get_in_or_equal($allItemIds, SQL_PARAMS_NAMED, 'pit');
            $ggrows = $DB->get_records_sql(
                "SELECT gg.itemid, gg.finalgrade
                   FROM {grade_grades} gg
                  WHERE gg.userid = :puid
                    AND gg.itemid $itemInSql",
                ['puid' => $userid] + $itemInParams
            );
            foreach ($ggrows as $ggr) {
                if (!is_null($ggr->finalgrade)) {
                    $gradesbyitemid[(int)$ggr->itemid] = (float)$ggr->finalgrade;
                }
            }

            // Override attendance grades with log-based recalculation.
            $attNow = time();
            foreach ($gradeitems as $gi) {
                if (($gi->itemmodule ?? '') !== 'attendance') continue;
                $attAttid    = (int)$gi->iteminstance;
                $attGrademax = (float)$gi->grademax;
                if ($attAttid <= 0 || $attGrademax <= 0) continue;

                $attTr = $DB->get_record_sql(
                    "SELECT COUNT(s.id) AS total
                       FROM {attendance_sessions} s
                      WHERE s.attendanceid = :attid
                        AND COALESCE(s.is_revalida, 0) = 0
                        AND s.sessdate + s.duration < :now
                        AND (
                            EXISTS (SELECT 1 FROM {attendance_log} l WHERE l.sessionid = s.id)
                            OR COALESCE(s.lasttaken, 0) > 0
                        )",
                    ['attid' => $attAttid, 'now' => $attNow]
                );
                $attTotal = $attTr ? (int)$attTr->total : 0;
                if ($attTotal <= 0) continue;

                $attPr = $DB->get_record_sql(
                    "SELECT COUNT(DISTINCT CASE WHEN ast.grade > 0 THEN s.id END) AS present
                       FROM {attendance_sessions} s
                       JOIN {attendance_log} al ON al.sessionid = s.id AND al.studentid = :uid
                       LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
                      WHERE s.attendanceid = :attid2
                        AND COALESCE(s.is_revalida, 0) = 0
                        AND s.sessdate + s.duration < :now2
                        AND (
                            EXISTS (SELECT 1 FROM {attendance_log} l2 WHERE l2.sessionid = s.id)
                            OR COALESCE(s.lasttaken, 0) > 0
                        )",
                    ['uid' => $userid, 'attid2' => $attAttid, 'now2' => $attNow]
                );
                $present = $attPr ? (int)$attPr->present : 0;
                $gradesbyitemid[(int)$gi->id] = round(($present / $attTotal) * $attGrademax, 2);
            }
        }

        // Group items by category.
        $itemsByCatId = [];
        foreach ($gradeitems as $gi) {
            $itemsByCatId[(int)$gi->categoryid][] = $gi;
        }

        // Compute weighted total per class.
        foreach ($classrows as $cr) {
            $catid = (int)$cr->gradecategoryid;
            $items = $itemsByCatId[$catid] ?? [];
            if (empty($items)) continue;

            $total  = 0.0;
            $hasany = false;
            foreach ($items as $gi) {
                $wpct = $itemWeightPct[(int)$gi->id] ?? 0;
                if ($wpct <= 0) continue;
                $raw   = $gradesbyitemid[(int)$gi->id] ?? null;
                $grade = ($raw !== null) ? (float)$raw : 0.0;
                $max   = ((float)$gi->grademax > 0) ? (float)$gi->grademax : 100.0;
                if ($raw !== null) $hasany = true;
                $total += ($grade / $max) * $wpct;
            }
            if (!$hasany) continue;

            // Independent modules: rescale to 100% regardless of category weight in course.
            if (!empty($cr->is_module)) {
                $activeWeightSum = 0.0;
                foreach ($items as $gi) {
                    $wpct2 = $itemWeightPct[(int)$gi->id] ?? 0;
                    if ($wpct2 > 0 && array_key_exists((int)$gi->id, $gradesbyitemid)) {
                        $activeWeightSum += $wpct2;
                    }
                }
                if ($activeWeightSum > 0.0 && abs($activeWeightSum - 100.0) > 0.01) {
                    $total = ($total / $activeWeightSum) * 100.0;
                }
            }

            $resolvedgrade = round(min($total, 100.0), 2);
            $classGradeByClassId[(int)$cr->id] = $resolvedgrade;
            if (!empty($cr->groupid)) {
                $classGradeByGroupId[(int)$cr->groupid] = $resolvedgrade;
            }
            if (!empty($cr->is_module)) {
                $moduleClassIds[(int)$cr->id] = true;
                if (!empty($cr->groupid)) {
                    $moduleGroupIds[(int)$cr->groupid] = true;
                }
            }
            $classid = (int)$cr->id;
            if (isset($membershipClassCourseByClassId[$classid])) {
                $courseid = (int)$membershipClassCourseByClassId[$classid];
                if (!isset($membershipClassGradesByCourseId[$courseid])) {
                    $membershipClassGradesByCourseId[$courseid] = [];
                }
                $membershipClassGradesByCourseId[$courseid][] = $resolvedgrade;
                if (!empty($cr->is_module)) {
                    $membershipClassIsModuleByCourseId[$courseid] = true;
                }
            }
        }

        // Section-based grade lookup for modules: reads the grade item directly from the
        // module's course section, bypassing category weight distortion. This mirrors the
        // logic in get_student_learning_plan_pensum and ensures module classes whose only
        // activity has aggregationcoef=0 (e.g. fresh MODULO DE MATEMATICA II with a single
        // assign item) still resolve the student's grade. Runs AFTER the weighted loop so
        // it can also OVERRIDE wrong values when needed.
        foreach ($classrows as $cr) {
            if (empty($cr->is_module) || (int)$cr->coursesectionid <= 0 || (int)$cr->corecourseid <= 0) {
                continue;
            }
            $mcSequence = (string)$DB->get_field('course_sections', 'sequence', ['id' => (int)$cr->coursesectionid]);
            if ($mcSequence === '') continue;
            $mcCmids = array_values(array_filter(array_map('intval', explode(',', $mcSequence))));
            if (empty($mcCmids)) continue;

            list($mcCminSql, $mcCminParams) = $DB->get_in_or_equal($mcCmids, SQL_PARAMS_NAMED, 'mcm');
            $mcGi = $DB->get_record_sql(
                "SELECT gi.id, gi.grademax
                   FROM {grade_items} gi
                   JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                   JOIN {modules} mmod ON mmod.id = cm.module
                                        AND gi.itemmodule = mmod.name
                  WHERE cm.id $mcCminSql
                    AND gi.courseid = :mc_cid
                    AND gi.itemtype IN ('mod', 'manual')
                    AND gi.grademax > 0
               ORDER BY gi.id ASC
                  LIMIT 1",
                $mcCminParams + ['mc_cid' => (int)$cr->corecourseid]
            );
            if (!$mcGi) continue;

            $mcGg = $DB->get_record('grade_grades',
                ['itemid' => (int)$mcGi->id, 'userid' => $userid]);
            if (!$mcGg || $mcGg->finalgrade === null) continue;

            $mcGrade = min(
                round(((float)$mcGg->finalgrade / (float)$mcGi->grademax) * 100, 2),
                100.0
            );

            // Override any previously computed category-weighted value (or fill if it was missing).
            $classGradeByClassId[(int)$cr->id] = $mcGrade;
            if (!empty($cr->groupid)) {
                $classGradeByGroupId[(int)$cr->groupid] = $mcGrade;
            }
            if (!isset($moduleClassIds[(int)$cr->id])) {
                $moduleClassIds[(int)$cr->id] = true;
            }
            if (!empty($cr->groupid) && !isset($moduleGroupIds[(int)$cr->groupid])) {
                $moduleGroupIds[(int)$cr->groupid] = true;
            }

            // Also surface the section-derived grade through the membership map so the
            // "fallback by classes where the student is group member" path picks it up.
            $classid = (int)$cr->id;
            if (isset($membershipClassCourseByClassId[$classid])) {
                $ccourseid = (int)$membershipClassCourseByClassId[$classid];
                $membershipClassIsModuleByCourseId[$ccourseid] = true;
                if (!isset($membershipClassGradesByCourseId[$ccourseid])) {
                    $membershipClassGradesByCourseId[$ccourseid] = [];
                }
                // Replace any earlier weighted value with the authoritative section grade.
                $membershipClassGradesByCourseId[$ccourseid] = [$mcGrade];
            }
        }

        return [$classGradeByClassId, $classGradeByGroupId, $membershipClassGradesByCourseId, $membershipClassIsModuleByCourseId];
    }

    /**
     * Default empty result when no resolution can be performed.
     */
    private static function empty_result(int $baseStatus, ?float $baseProgress): array
    {
        return [
            'grade'     => null,
            'source'    => self::SOURCE_NONE,
            'is_module' => false,
            'status'    => $baseStatus,
            'progress'  => $baseProgress !== null ? (float)$baseProgress : 0.0,
            'semaphore' => self::semaphore_for_status($baseStatus),
        ];
    }

    // -------------------------------------------------------------------
    // Bulk resolution (export-scale)
    // -------------------------------------------------------------------

    /**
     * Resolve grades for a large set of (userid, courseid, learningplanid) rows
     * using ONE batched DB pre-load per data type instead of N+1 queries per row.
     *
     * Each input row must be an associative array with keys:
     *   userid, courseid, learningplanid, baseStatus, progressclassid,
     *   progressgroupid, currentperiodid, coursename, baseProgress.
     *
     * The optional key `key` is preserved in the returned map (auto-generated if absent).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>> Map keyed by `key` (per-row resolution).
     */
    public static function bulk_resolve_for_records(array $rows): array {
        global $DB;
        $results = [];
        if (empty($rows)) {
            return $results;
        }

        // One read of the stored latest grades for every (user, course, plan).
        $userids = array_values(array_unique(array_map(function ($r) {
            return (int)($r['userid'] ?? 0);
        }, $rows)));
        $stored = [];
        foreach (array_chunk(array_filter($userids), 500) as $chunk) {
            list($insql, $inparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bru');
            $rs = $DB->get_recordset_sql(
                "SELECT id, userid, courseid, learningplanid, grade, status, progress, grade_source
                   FROM {gmk_course_progre}
                  WHERE userid $insql AND grade_source IS NOT NULL AND grade_source <> ''", $inparams);
            foreach ($rs as $p) {
                $stored[$p->userid . '-' . $p->courseid . '-' . $p->learningplanid] = $p;
            }
            $rs->close();
        }

        foreach ($rows as $idx => $row) {
            $key = isset($row['key']) ? (string)$row['key'] : (string)$idx;
            $uid = (int)($row['userid'] ?? 0);
            $cid = (int)($row['courseid'] ?? 0);
            $lp  = (int)($row['learningplanid'] ?? 0);
            $p = $stored[$uid . '-' . $cid . '-' . $lp] ?? null;
            if ($p) {
                $status = (int)$p->status;
                $results[$key] = [
                    'grade'     => $p->grade !== null ? round((float)$p->grade, 2) : null,
                    'source'    => (string)$p->grade_source,
                    'is_module' => $p->grade_source === self::SOURCE_LATEST_MODULE,
                    'status'    => $status,
                    'progress'  => (float)$p->progress,
                    'semaphore' => self::semaphore_for_status($status),
                ];
                continue;
            }
            $results[$key] = self::resolve_course_grade(
                $uid, $cid, $lp,
                (int)($row['baseStatus'] ?? 0),
                isset($row['progressclassid']) ? (int)$row['progressclassid'] : null,
                isset($row['progressgroupid']) ? (int)$row['progressgroupid'] : null,
                (int)($row['currentperiodid'] ?? 0),
                isset($row['coursename']) ? (string)$row['coursename'] : null,
                isset($row['baseProgress']) ? (float)$row['baseProgress'] : null
            );
        }
        return $results;
    }

    // ------------------------------------------------------------------
    // Latest-grade model (20261001105)
    // ------------------------------------------------------------------
    //
    // The displayed grade of a subject is the LAST grade placed for it, not
    // the winner of a fixed priority ladder. Candidates:
    //   - the latest regular class the student was in (by class start date),
    //     with its weighted category grade;
    //   - the latest module class, same computation;
    //   - the latest revalidation with a result;
    //   - the "Nota Final Integrada" manual item (homologations write there).
    // Each candidate carries the moment it was graded; the most recent wins.
    // Only when none exists do the historical sources (plan category, course
    // total, the value already stored in gmk_course_progre) apply.
    //
    // sync_progress() stores the result in gmk_course_progre (grade,
    // grade_source, grade_at, grade_classid, status) so studenttable, the
    // grade modal, the reports and the LXP all read the same value.

    const SOURCE_LATEST_CLASS        = 'class';
    const SOURCE_LATEST_MODULE       = 'module';
    const SOURCE_LATEST_NFI          = 'nota_final_integrada';
    const SOURCE_HISTORICAL_PROGRE   = 'historical_progre';

    /** Strictly above this is a pass (close_class_grades_and_open_revalids uses the same cut). */
    const LATEST_PASS_ABOVE = 70.9;

    /** Tie-break when two candidates were graded in the same second. */
    const LATEST_TIE_RANK = [
        self::SOURCE_REVALIDATION  => 4,
        self::SOURCE_LATEST_NFI    => 3,
        self::SOURCE_LATEST_MODULE => 2,
        self::SOURCE_LATEST_CLASS  => 1,
    ];

    /**
     * Resolve the last grade placed for a student in a subject.
     *
     * @return array{grade:?float,source:string,gradedat:int,classid:int,is_module:bool,finished:bool,candidates:array}
     */
    public static function resolve_latest_grade(
        int $userid,
        int $corecourseid,
        int $learningPlanId,
        int $progressclassid = 0,
        int $progressgroupid = 0,
        ?string $coursename = null,
        int $baseStatus = 0
    ): array {
        global $DB;

        $none = ['grade' => null, 'source' => self::SOURCE_NONE, 'gradedat' => 0, 'classid' => 0,
                 'is_module' => false, 'finished' => true, 'candidates' => []];
        if ($userid <= 0 || $corecourseid <= 0) {
            return $none;
        }

        $candidates = [];

        // --- Class candidates: latest regular and latest module class -------
        $membership = self::bulk_membership_classes($DB, $userid, $corecourseid, $learningPlanId);
        $moduleClassIds = [];
        $moduleGroupIds = [];
        list($gradeByClass) = self::compute_class_category_grades(
            $DB, $userid, $corecourseid, $learningPlanId, $progressclassid, $progressgroupid,
            $membership, $moduleClassIds, $moduleGroupIds
        );
        if (!empty($gradeByClass)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($gradeByClass), SQL_PARAMS_NAMED, 'lc');
            $classes = $DB->get_records_sql(
                "SELECT id, is_module, gradecategoryid, coursesectionid, initdate, enddate, closed
                   FROM {gmk_class} WHERE id $insql", $inparams);
            $latestbykind = [];
            foreach ($classes as $c) {
                $kind = !empty($c->is_module) ? self::SOURCE_LATEST_MODULE : self::SOURCE_LATEST_CLASS;
                $cur = $latestbykind[$kind] ?? null;
                // "Su ultima inscripcion al ultimo grupo": the class that started last.
                if (!$cur || (int)$c->initdate > (int)$cur->initdate
                        || ((int)$c->initdate === (int)$cur->initdate && (int)$c->id > (int)$cur->id)) {
                    $latestbykind[$kind] = $c;
                }
            }
            foreach ($latestbykind as $kind => $c) {
                $gradedat = self::class_graded_at($DB, $userid, $corecourseid, $c);
                if ($gradedat <= 0) {
                    // Only attendance has a value: nobody placed a grade in this
                    // class yet, so it is not a candidate.
                    continue;
                }
                $finished = !empty($c->closed) || ((int)$c->enddate > 0 && (int)$c->enddate < time());
                $candidates[] = [
                    'source'    => $kind,
                    'grade'     => (float)$gradeByClass[(int)$c->id],
                    'gradedat'  => $gradedat,
                    'classid'   => (int)$c->id,
                    'is_module' => $kind === self::SOURCE_LATEST_MODULE,
                    'finished'  => $finished,
                ];
            }
        }

        // --- Revalidation with a result -------------------------------------
        $reval = $DB->get_records_select('gmk_revalidations',
            "userid = :u AND corecourseid = :c AND (learningplanid = :lp OR learningplanid = 0)
             AND result IS NOT NULL AND result <> ''",
            ['u' => $userid, 'c' => $corecourseid, 'lp' => $learningPlanId],
            'timemodified DESC, id DESC', 'id, result, originalgrade, timemodified', 0, 1);
        if ($reval) {
            $r = reset($reval);
            // Same consolidation as revalida_manager::save_grade(): a pass is
            // PASS_FINAL_GRADE, a fail keeps the original class grade.
            $rgrade = $r->result === 'approved'
                ? (float)\local_grupomakro_core\local\revalida_manager::PASS_FINAL_GRADE
                : ($r->originalgrade !== null ? round((float)$r->originalgrade, 2) : null);
            if ($rgrade !== null) {
                $candidates[] = ['source' => self::SOURCE_REVALIDATION, 'grade' => $rgrade,
                    'gradedat' => (int)$r->timemodified, 'classid' => 0, 'is_module' => false, 'finished' => true];
            }
        }

        // --- Nota Final Integrada (also where homologations land) ------------
        $nfi = $DB->get_record_sql(
            "SELECT gg.finalgrade, gg.timemodified, gi.grademax, gi.id AS itemid
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :u
              WHERE gi.courseid = :c AND gi.itemtype = 'manual' AND gi.itemname = 'Nota Final Integrada'
                AND gg.finalgrade IS NOT NULL",
            ['u' => $userid, 'c' => $corecourseid], IGNORE_MULTIPLE);
        if ($nfi) {
            $max = (float)$nfi->grademax > 0 ? (float)$nfi->grademax : 100.0;
            // Rows loaded by the migration have no timemodified. Date them from the
            // grade history, then from the homologation that wrote them; with no
            // date at all they are older than anything graded in Moodle.
            $nfiat = (int)$nfi->timemodified;
            if ($nfiat <= 0) {
                $nfiat = (int)$DB->get_field_sql(
                    "SELECT MAX(timemodified) FROM {grade_grades_history}
                      WHERE itemid = :i AND userid = :u AND finalgrade IS NOT NULL",
                    ['i' => (int)$nfi->itemid, 'u' => $userid]);
            }
            if ($nfiat <= 0) {
                $nfiat = (int)$DB->get_field_sql(
                    "SELECT MAX(homologation_at) FROM {gmk_course_progre} WHERE userid = :u AND courseid = :c",
                    ['u' => $userid, 'c' => $corecourseid]);
            }
            $candidates[] = ['source' => self::SOURCE_LATEST_NFI,
                'grade' => round(min(((float)$nfi->finalgrade / $max) * 100, 100.0), 2),
                'gradedat' => $nfiat, 'classid' => 0, 'is_module' => false, 'finished' => true];
        }

        if (!empty($candidates)) {
            usort($candidates, function ($a, $b) {
                if ($a['gradedat'] !== $b['gradedat']) {
                    return $b['gradedat'] <=> $a['gradedat'];
                }
                return (self::LATEST_TIE_RANK[$b['source']] ?? 0) <=> (self::LATEST_TIE_RANK[$a['source']] ?? 0);
            });
            $w = $candidates[0];
            return ['grade' => round($w['grade'], 2), 'source' => $w['source'], 'gradedat' => $w['gradedat'],
                    'classid' => $w['classid'], 'is_module' => $w['is_module'], 'finished' => $w['finished'],
                    'candidates' => $candidates];
        }

        // --- Historical fallback: only when nothing above exists --------------
        list($grade, $source) = self::resolve_raw_grade(
            $DB, $userid, $corecourseid, $learningPlanId, 0, $progressclassid, $progressgroupid, $baseStatus, $coursename
        );
        if ($grade !== null) {
            return ['grade' => round((float)$grade, 2), 'source' => 'historical_' . $source, 'gradedat' => 0,
                    'classid' => 0, 'is_module' => false, 'finished' => true, 'candidates' => []];
        }
        return $none;
    }

    /**
     * When the student's grade in a class was last placed: the newest
     * timemodified among their non-null item grades in the class category,
     * or in the module's own section. Attendance items are skipped because
     * their value is recomputed from the attendance log, not "placed".
     */
    private static function class_graded_at($DB, int $userid, int $corecourseid, \stdClass $class): int
    {
        $at = (int)$DB->get_field_sql(
            "SELECT MAX(gg.timemodified)
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :u
              WHERE gi.courseid = :c AND gi.categoryid = :cat
                AND gi.itemtype IN ('mod', 'manual')
                AND COALESCE(gi.itemmodule, '') <> 'attendance'
                AND gg.finalgrade IS NOT NULL",
            ['u' => $userid, 'c' => $corecourseid, 'cat' => (int)$class->gradecategoryid]);
        if (!empty($class->is_module) && (int)$class->coursesectionid > 0) {
            $seq = (string)$DB->get_field('course_sections', 'sequence', ['id' => (int)$class->coursesectionid]);
            $cmids = array_values(array_filter(array_map('intval', explode(',', $seq))));
            if ($cmids) {
                list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cga');
                $at = max($at, (int)$DB->get_field_sql(
                    "SELECT MAX(gg.timemodified)
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                       JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name
                                            AND gi.courseid = :c AND gi.itemtype = 'mod'
                       JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :u
                      WHERE cm.id $insql AND m.name <> 'attendance' AND gg.finalgrade IS NOT NULL",
                    ['u' => $userid, 'c' => $corecourseid] + $inparams));
            }
        }
        // 0 = graded only through attendance (recomputed, never "placed").
        return $at;
    }

    /**
     * Store the latest grade in every gmk_course_progre row of (student, course).
     *
     * Status follows the stored grade, with the workflow states left alone:
     * not taken (0, 1, 99), cursando until the class/module holding the grade
     * has ended or a revalidation has a result (2), pending revalidation while
     * the class grade is what put it there (6) and revalidating until the
     * revalidation has a result (7). Historical sources never change status.
     *
     * @param bool $apply false = dry run, only report what would change.
     * @return array list of changes: [progreid, learningplanid, field => [old, new], ...]
     */
    public static function sync_progress(int $userid, int $corecourseid, bool $apply = true): array
    {
        global $DB;

        // Rollout switch: until local_grupomakro_core | latest_grade_sync_enabled
        // is 1 nothing is written (the CLI dry run, $apply = false, still works).
        if ($apply && !get_config('local_grupomakro_core', 'latest_grade_sync_enabled')) {
            return [];
        }

        $changes = [];
        $rows = $DB->get_records('gmk_course_progre', ['userid' => $userid, 'courseid' => $corecourseid]);
        foreach ($rows as $row) {
            $res = self::resolve_latest_grade($userid, $corecourseid, (int)$row->learningplanid,
                (int)($row->classid ?? 0), (int)($row->groupid ?? 0), (string)$row->coursename, (int)$row->status);

            $new = clone $row;
            if ($res['source'] === self::SOURCE_NONE) {
                // Nothing in the gradebook: keep what was stored (migrated data).
                if ((float)$row->grade > 0 && empty($row->grade_source)) {
                    $new->grade_source = self::SOURCE_HISTORICAL_PROGRE;
                }
            } else {
                $new->grade = $res['grade'];
                $new->grade_source = $res['source'];
                $new->grade_at = $res['gradedat'];
                $new->grade_classid = $res['classid'];
                $new->status = self::status_for_latest((int)$row->status, $res);
                if (in_array((int)$new->status, [3, 4], true)) {
                    $new->progress = 100;
                }
            }

            $diff = [];
            foreach (['grade', 'status', 'progress', 'grade_source', 'grade_at', 'grade_classid'] as $f) {
                $old = $row->$f ?? null;
                $val = $new->$f ?? null;
                $same = (is_numeric($old) && is_numeric($val))
                    ? abs((float)$old - (float)$val) < 0.005
                    : (string)$old === (string)$val;
                if (!$same) {
                    $diff[$f] = [$old, $val];
                }
            }
            if ($diff) {
                $changes[] = ['progreid' => (int)$row->id, 'learningplanid' => (int)$row->learningplanid] + $diff;
                if ($apply) {
                    $new->timemodified = time();
                    $DB->update_record('gmk_course_progre', $new);
                }
            }
        }
        return $changes;
    }

    /**
     * Status that follows the latest grade (see sync_progress for the exceptions).
     */
    public static function status_for_latest(int $current, array $res): int
    {
        $grade = (float)$res['grade'];
        $passed = $grade > self::LATEST_PASS_ABOVE;
        $fromclass = in_array($res['source'], [self::SOURCE_LATEST_CLASS, self::SOURCE_LATEST_MODULE], true);

        if (in_array($current, [0, 1, 99], true)) {
            return $current;
        }
        // Historical sources only fill in a grade; they never decide a status.
        if (strpos((string)$res['source'], 'historical_') === 0) {
            return $current;
        }
        // Cursando ends when the class/module that holds the grade has ended, or
        // with a revalidation result; an older grade does not end it.
        if ($current === 2
                && !($fromclass && $res['finished'])
                && $res['source'] !== self::SOURCE_REVALIDATION) {
            return $current;
        }
        if ($current === 6 && $fromclass && !$passed) {
            return $current;
        }
        if ($current === 7 && $res['source'] !== self::SOURCE_REVALIDATION) {
            return $current;
        }
        if ($passed) {
            return $current === 3 ? 3 : 4;
        }
        return 5;
    }

}
