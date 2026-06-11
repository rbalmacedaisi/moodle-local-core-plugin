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

        $progressclassid = $progressclassid ?: 0;
        $progressgroupid = $progressgroupid ?: 0;

        // --- 1) Resolve raw grade using the cascade of sources ---
        list($grade, $source, $isModuleGrade) = self::resolve_raw_grade(
            $DB,
            $userid,
            $corecourseid,
            $learningPlanId,
            $currentperiodid,
            $progressclassid,
            $progressgroupid,
            $baseStatus,
            $coursename
        );

        // --- 2) Apply virtual approval rule (only if we have a grade) ---
        $resolvedStatus = (int)$baseStatus;
        $resolvedProgress = $baseProgress !== null ? (float)$baseProgress : 0.0;
        if ($grade !== null) {
            $canVirtualApprove = in_array($resolvedStatus, self::VIRTUAL_APPROVABLE_STATUSES, true);
            $passingGrade = ($resolvedStatus === 5) ? self::RETRY_PASSING_GRADE : self::DEFAULT_PASSING_GRADE;
            if ($canVirtualApprove && $grade >= $passingGrade) {
                $resolvedStatus = 3; // COURSE_COMPLETED
                $resolvedProgress = 100.00;
            }
        }

        return [
            'grade'     => $grade,
            'source'    => $source,
            'is_module' => $isModuleGrade,
            'status'    => $resolvedStatus,
            'progress'  => $resolvedProgress,
            'semaphore' => self::semaphore_for_status($resolvedStatus),
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
            "SELECT c.id, c.groupid, c.corecourseid, c.gradecategoryid, c.is_module
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
}
