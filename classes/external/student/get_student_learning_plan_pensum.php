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
 * Class definition for the local_grupomakro_get_student_learning_plan_pensum external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/grade/querylib.php');

/**
 * External function 'local_grupomakro_get_student_learning_plan_pensum' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_student_learning_plan_pensum extends external_api
{

    const STATUS_LABEL = [
        0 => 'No disponible',
        1 => 'Disponible',
        2 => 'Cursando',
        3 => 'Aprobada',
        4 => 'Aprobada',
        5 => 'Reprobada',
        6 => 'Revalida',
        7 => 'Reprobado',
        99 => 'Migración Pendiente',
    ];

    const STATUS_COLOR = [
        0 => '#5e35b1',
        1 => '#1e88e5',
        2 => '#11d1bf',
        3 => '#0cce7b',
        4 => '#0cce7b',
        5 => '#ec407a',
        6 => '#ec407a',
        7 => '#ec407a',
        99 => '#ff9800',  // Orange color for migration pending
    ];

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters(
            [
                'userId' => new external_value(PARAM_TEXT, 'ID of the student.', VALUE_REQUIRED),
                'learningPlanId' => new external_value(PARAM_TEXT, 'ID of the learningPlan.', VALUE_REQUIRED)
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute(
        $userId,
        $learningPlanId
    ) {

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userId' => $userId,
            'learningPlanId' => $learningPlanId
        ]);

        global $DB;
        \gmk_log("DEBUG get_student_learning_plan_pensum - UserID: {$params['userId']} - LPID: {$params['learningPlanId']}");
        try {
            $userPensumCourses = $DB->get_records_sql(
                "
                SELECT lpc.*, lpc.id as learningcourseid, gcp.status, gcp.progress, gcp.credits, gcp.prerequisites, gcp.id as progressid,
                       gcp.grade as progressgrade, gcp.classid as progressclassid, gcp.groupid as progressgroupid
                FROM {local_learning_courses} lpc
                LEFT JOIN {gmk_course_progre} gcp ON (gcp.courseid = lpc.courseid AND gcp.userid = :userid AND gcp.learningplanid = :learningplanid)
                WHERE lpc.learningplanid = :lpid
                ORDER BY lpc.position ASC",
                ['userid' => $params['userId'], 'learningplanid' => $params['learningPlanId'], 'lpid' => $params['learningPlanId']]
            );
            
            \gmk_log("DEBUG get_student_learning_plan_pensum - Courses found: " . count($userPensumCourses));

            // Precompute active class counts using index-friendly queries.
            $activeClassCountByLearningCourse = [];
            $activeClassCountByCoreCourse = [];
            $activeClassCountByCoreCourseCurrentPeriod = [];
            $activeClassCountByCoreCourseAnyPlan = [];
            $courseNamesById = [];
            $gradesByCourseId = [];
            $classGradeByClassId = [];
            $classGradeByGroupId = [];
            $membershipClassGradesByCourseId = [];
            $planClassCategoryGradeByCourseId = [];
            $manualIntegratedGradeByCourseId = [];
            $manualIntegratedGradeByCourseName = [];

            if (!empty($userPensumCourses)) {
                $learningcourseids = [];
                $corecourseids = [];
                $progressclassids = [];
                $membershipclassids = [];
                $membershipClassCourseByClassId = [];
                foreach ($userPensumCourses as $pcourse) {
                    if (!empty($pcourse->learningcourseid)) {
                        $learningcourseids[] = (int)$pcourse->learningcourseid;
                    }
                    if (!empty($pcourse->courseid)) {
                        $corecourseids[] = (int)$pcourse->courseid;
                    }
                    if (!empty($pcourse->progressclassid)) {
                        $progressclassids[] = (int)$pcourse->progressclassid;
                    }
                }

                $learningcourseids = array_values(array_unique($learningcourseids));
                $corecourseids = array_values(array_unique($corecourseids));
                $progressclassids = array_values(array_unique($progressclassids));
                $currentperiodid = (int)$DB->get_field_sql(
                    "SELECT MAX(lu.currentperiodid)
                       FROM {local_learning_users} lu
                      WHERE lu.userid = :userid
                        AND lu.learningplanid = :learningplanid
                        AND (lu.userroleid = :studentrole OR lu.userrolename = :studentrolename)",
                    [
                        'userid' => $params['userId'],
                        'learningplanid' => $params['learningPlanId'],
                        'studentrole' => 5,
                        'studentrolename' => 'student',
                    ]
                );

                // Bulk fetch course names.
                if (!empty($corecourseids)) {
                    list($courseInSql, $courseInParams) = $DB->get_in_or_equal($corecourseids, SQL_PARAMS_NAMED, 'cid');
                    $courserecords = $DB->get_records_select('course', "id $courseInSql", $courseInParams, '', 'id, fullname');
                    foreach ($courserecords as $courserecord) {
                        $courseNamesById[(int)$courserecord->id] = $courserecord->fullname;
                    }

                    // Effective plan match: tolerate stale gmk_class.learningplanid when courseid maps to the plan.
                    $effectiveplanwhere = "(c.learningplanid = :lpid OR EXISTS (
                                              SELECT 1
                                                FROM {local_learning_courses} lpcmap
                                               WHERE lpcmap.id = c.courseid
                                                 AND lpcmap.learningplanid = :lpidmap
                                            ))";
                    $effectiveplanparams = [
                        'lpid' => $params['learningPlanId'],
                        'lpidmap' => $params['learningPlanId'],
                    ];

                    // Candidate classes by real group membership (handles old progress rows with classid/groupid empty).
                    $membershipclasses = $DB->get_records_sql(
                        "SELECT c.id, c.corecourseid
                           FROM {gmk_class} c
                           JOIN {groups_members} gm ON gm.groupid = c.groupid
                          WHERE gm.userid = :userid
                            AND $effectiveplanwhere
                            AND c.gradecategoryid > 0
                            AND c.corecourseid $courseInSql
                       ORDER BY c.id ASC",
                        ['userid' => $params['userId']] + $effectiveplanparams + $courseInParams
                    );
                    foreach ($membershipclasses as $mclass) {
                        $cid = (int)$mclass->id;
                        $membershipclassids[] = $cid;
                        $membershipClassCourseByClassId[$cid] = (int)$mclass->corecourseid;
                    }

                    // Broad fallback: category totals from any class category in this plan+course.
                    $plancategorygrades = $DB->get_records_sql(
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
                            AND c.corecourseid $courseInSql
                       GROUP BY c.corecourseid
                       ORDER BY c.corecourseid ASC",
                        ['userid' => $params['userId']] + $effectiveplanparams + $courseInParams
                    );
                    foreach ($plancategorygrades as $pcg) {
                        if (!is_null($pcg->gradeval)) {
                            $planClassCategoryGradeByCourseId[(int)$pcg->corecourseid] = round((float)$pcg->gradeval, 2);
                        }
                    }

                    // Prefer explicit migrated/final integrated grade when present.
                    $manualgrades = $DB->get_records_sql(
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
                            AND gi.courseid $courseInSql
                       GROUP BY gi.courseid
                       ORDER BY gi.courseid ASC",
                        [
                            'userid' => $params['userId'],
                            'nfi_any' => '%Nota Final Integrada%',
                            'nfi_alt1' => '%Final Integrada%',
                            'nfi_alt2' => '%Nota Final%'
                        ] + $courseInParams
                    );
                    foreach ($manualgrades as $mg) {
                        if (!is_null($mg->gradeval)) {
                            $manualIntegratedGradeByCourseId[(int)$mg->courseid] = round((float)$mg->gradeval, 2);
                        }
                    }

                    // Defensive fallback: if the same subject exists in another Moodle course id,
                    // resolve "Nota Final Integrada" by fullname (historical statuses only later).
                    $targetcoursenames = array_values(array_unique(array_filter(array_values($courseNamesById), function($name) {
                        return trim((string)$name) !== '';
                    })));
                    if (!empty($targetcoursenames)) {
                        list($nameInSql, $nameInParams) = $DB->get_in_or_equal($targetcoursenames, SQL_PARAMS_NAMED, 'cname');
                        $sameNameCourses = $DB->get_records_sql(
                            "SELECT id, fullname
                               FROM {course}
                              WHERE fullname $nameInSql
                           ORDER BY id ASC",
                            $nameInParams
                        );

                        if (!empty($sameNameCourses)) {
                            $sameNameCourseIds = [];
                            foreach ($sameNameCourses as $snc) {
                                $sameNameCourseIds[] = (int)$snc->id;
                            }
                            $sameNameCourseIds = array_values(array_unique($sameNameCourseIds));

                            if (!empty($sameNameCourseIds)) {
                                list($sameCourseInSql, $sameCourseInParams) = $DB->get_in_or_equal($sameNameCourseIds, SQL_PARAMS_NAMED, 'sncid');
                                $manualgradesByName = $DB->get_records_sql(
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
                                        AND gi.courseid $sameCourseInSql
                                   GROUP BY c.fullname
                                   ORDER BY c.fullname ASC",
                                    [
                                        'userid' => $params['userId'],
                                        'nfi_any' => '%Nota Final Integrada%',
                                        'nfi_alt1' => '%Final Integrada%',
                                        'nfi_alt2' => '%Nota Final%'
                                    ] + $sameCourseInParams
                                );
                                foreach ($manualgradesByName as $mgn) {
                                    if (!is_null($mgn->gradeval)) {
                                        $manualIntegratedGradeByCourseName[(string)$mgn->fullname] = round((float)$mgn->gradeval, 2);
                                    }
                                }
                            }
                        }
                    }

                    // Bulk fetch course totals for the student (fallback source only).
                    $gradeSql = "SELECT gi.courseid,
                                        MAX(CASE WHEN COALESCE(gg.finalgrade, gg.rawgrade) BETWEEN 0 AND 100
                                                 THEN COALESCE(gg.finalgrade, gg.rawgrade) END) AS gradeval_sane,
                                        MAX(COALESCE(gg.finalgrade, gg.rawgrade)) AS gradeval_any
                                   FROM {grade_items} gi
                              LEFT JOIN {grade_grades} gg
                                     ON gg.itemid = gi.id
                                    AND gg.userid = :userid
                                  WHERE gi.itemtype = 'course'
                                    AND gi.courseid $courseInSql
                               GROUP BY gi.courseid
                               ORDER BY gi.courseid ASC";
                    $gradeParams = ['userid' => $params['userId']] + $courseInParams;
                    $graderows = $DB->get_records_sql($gradeSql, $gradeParams);
                    foreach ($graderows as $graderow) {
                        $gradeval = null;
                        if (!is_null($graderow->gradeval_sane)) {
                            $gradeval = (float)$graderow->gradeval_sane;
                        } else if (!is_null($graderow->gradeval_any)) {
                            $gradeval = (float)$graderow->gradeval_any;
                        }
                        if (!is_null($gradeval)) {
                            $gradesByCourseId[(int)$graderow->courseid] = round($gradeval, 2);
                        }
                    }
                }

                // Preferred source: class category totals (grade item type=category) for the student's class/group.
                $allcandidateclassids = array_values(array_unique(array_merge($progressclassids, $membershipclassids)));
                if (!empty($allcandidateclassids)) {
                    list($classInSql, $classInParams) = $DB->get_in_or_equal($allcandidateclassids, SQL_PARAMS_NAMED, 'clid');
                    $classrows = $DB->get_records_sql(
                        "SELECT c.id, c.groupid, c.corecourseid, c.gradecategoryid
                           FROM {gmk_class} c
                          WHERE c.id $classInSql
                            AND c.gradecategoryid > 0
                            AND c.corecourseid > 0
                       ORDER BY c.id ASC",
                        $classInParams
                    );

                    if (!empty($classrows)) {
                        $categoryids = [];
                        $categorycourseids = [];
                        foreach ($classrows as $cr) {
                            $categoryids[] = (int)$cr->gradecategoryid;
                            $categorycourseids[] = (int)$cr->corecourseid;
                        }
                        $categoryids = array_values(array_unique($categoryids));
                        $categorycourseids = array_values(array_unique($categorycourseids));

                        if (!empty($categoryids) && !empty($categorycourseids)) {
                            list($catInSql, $catInParams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
                            list($ccInSql, $ccInParams) = $DB->get_in_or_equal($categorycourseids, SQL_PARAMS_NAMED, 'cc');
                            $categoryitems = $DB->get_records_sql(
                                "SELECT gi.id, gi.courseid, gi.iteminstance AS categoryid
                                   FROM {grade_items} gi
                                  WHERE gi.itemtype = 'category'
                                    AND gi.iteminstance $catInSql
                                    AND gi.courseid $ccInSql
                               ORDER BY gi.id ASC",
                                $catInParams + $ccInParams
                            );

                            $itemidbycoursecat = [];
                            foreach ($categoryitems as $ci) {
                                $key = ((int)$ci->courseid) . '-' . ((int)$ci->categoryid);
                                if (!isset($itemidbycoursecat[$key])) {
                                    $itemidbycoursecat[$key] = (int)$ci->id;
                                }
                            }

                            if (!empty($itemidbycoursecat)) {
                                $itemids = array_values(array_unique(array_values($itemidbycoursecat)));
                                list($itemInSql, $itemInParams) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'it');
                                $gradegraderows = $DB->get_records_sql(
                                    "SELECT gg.id, gg.itemid, gg.finalgrade, gg.rawgrade
                                       FROM {grade_grades} gg
                                      WHERE gg.userid = :userid
                                        AND gg.itemid $itemInSql
                                   ORDER BY gg.id ASC",
                                    ['userid' => $params['userId']] + $itemInParams
                                );

                                $gradebyitemid = [];
                                foreach ($gradegraderows as $ggr) {
                                    $gradeval = null;
                                    if (!is_null($ggr->finalgrade)) {
                                        $gradeval = (float)$ggr->finalgrade;
                                    } else if (!is_null($ggr->rawgrade)) {
                                        $gradeval = (float)$ggr->rawgrade;
                                    }
                                    if (is_null($gradeval)) {
                                        continue;
                                    }

                                    $itemid = (int)$ggr->itemid;
                                    // Keep max non-null in case of duplicate grade rows.
                                    if (!array_key_exists($itemid, $gradebyitemid) || $gradeval > $gradebyitemid[$itemid]) {
                                        $gradebyitemid[$itemid] = $gradeval;
                                    }
                                }

                                foreach ($classrows as $cr) {
                                    $key = ((int)$cr->corecourseid) . '-' . ((int)$cr->gradecategoryid);
                                    if (empty($itemidbycoursecat[$key])) {
                                        continue;
                                    }
                                    $itemid = (int)$itemidbycoursecat[$key];
                                    if (!array_key_exists($itemid, $gradebyitemid)) {
                                        continue;
                                    }

                                    $resolvedgrade = round((float)$gradebyitemid[$itemid], 2);
                                    $classGradeByClassId[(int)$cr->id] = $resolvedgrade;
                                    if (!empty($cr->groupid)) {
                                        $classGradeByGroupId[(int)$cr->groupid] = $resolvedgrade;
                                    }
                                    $classid = (int)$cr->id;
                                    if (isset($membershipClassCourseByClassId[$classid])) {
                                        $courseid = (int)$membershipClassCourseByClassId[$classid];
                                        if (!isset($membershipClassGradesByCourseId[$courseid])) {
                                            $membershipClassGradesByCourseId[$courseid] = [];
                                        }
                                        $membershipClassGradesByCourseId[$courseid][] = $resolvedgrade;
                                    }
                                }
                            }
                        }
                    }
                }

                // Active classes matched by learning course (preferred).
                if (!empty($learningcourseids)) {
                    list($inLearningSql, $inLearningParams) = $DB->get_in_or_equal($learningcourseids, SQL_PARAMS_NAMED, 'lc');
                    $learningCounts = $DB->get_records_sql(
                        "SELECT c.courseid AS learningcourseid, COUNT(1) AS total
                           FROM {gmk_class} c
                          WHERE c.approved = 1
                            AND c.closed = 0
                            AND c.enddate >= :now
                            AND (c.learningplanid = :lpid OR EXISTS (
                                  SELECT 1
                                    FROM {local_learning_courses} lpcmap
                                   WHERE lpcmap.id = c.courseid
                                     AND lpcmap.learningplanid = :lpidmap
                                ))
                            AND c.courseid $inLearningSql
                       GROUP BY c.courseid",
                        ['now' => time(), 'lpid' => $params['learningPlanId'], 'lpidmap' => $params['learningPlanId']] + $inLearningParams
                    );
                    foreach ($learningCounts as $row) {
                        $activeClassCountByLearningCourse[(int)$row->learningcourseid] = (int)$row->total;
                    }
                }

                // Fallback active classes by core course.
                if (!empty($corecourseids)) {
                    list($inCoreSql, $inCoreParams) = $DB->get_in_or_equal($corecourseids, SQL_PARAMS_NAMED, 'cc');
                    $coreCounts = $DB->get_records_sql(
                        "SELECT c.corecourseid, COUNT(1) AS total
                           FROM {gmk_class} c
                          WHERE c.approved = 1
                            AND c.closed = 0
                            AND c.enddate >= :now
                            AND (c.learningplanid = :lpid OR EXISTS (
                                  SELECT 1
                                    FROM {local_learning_courses} lpcmap
                                   WHERE lpcmap.id = c.courseid
                                     AND lpcmap.learningplanid = :lpidmap
                                ))
                            AND c.corecourseid $inCoreSql
                       GROUP BY c.corecourseid",
                        ['now' => time(), 'lpid' => $params['learningPlanId'], 'lpidmap' => $params['learningPlanId']] + $inCoreParams
                    );
                    foreach ($coreCounts as $row) {
                        $activeClassCountByCoreCourse[(int)$row->corecourseid] = (int)$row->total;
                    }

                    // Fallback count: same core course in student's current period, independent of class plan.
                    if ($currentperiodid > 0) {
                        $coreCountsCurrentPeriod = $DB->get_records_sql(
                            "SELECT c.corecourseid, COUNT(1) AS total
                               FROM {gmk_class} c
                              WHERE c.approved = 1
                                AND c.closed = 0
                                AND c.enddate >= :now
                                AND c.periodid = :periodid
                                AND c.corecourseid $inCoreSql
                           GROUP BY c.corecourseid",
                            [
                                'now' => time(),
                                'periodid' => $currentperiodid,
                            ] + $inCoreParams
                        );
                        foreach ($coreCountsCurrentPeriod as $row) {
                            $activeClassCountByCoreCourseCurrentPeriod[(int)$row->corecourseid] = (int)$row->total;
                        }
                    }

                    // Last fallback count: any active class for the same core course.
                    $coreCountsAnyPlan = $DB->get_records_sql(
                        "SELECT c.corecourseid, COUNT(1) AS total
                           FROM {gmk_class} c
                          WHERE c.approved = 1
                            AND c.closed = 0
                            AND c.enddate >= :now
                            AND c.corecourseid $inCoreSql
                       GROUP BY c.corecourseid",
                        ['now' => time()] + $inCoreParams
                    );
                    foreach ($coreCountsAnyPlan as $row) {
                        $activeClassCountByCoreCourseAnyPlan[(int)$row->corecourseid] = (int)$row->total;
                    }
                }
            }
            
            $groupedUserPensumCourses = [];
            foreach ($userPensumCourses as $userPensumCourse) {
                // If status is null (no progress record), default to 0 (No disponible) or suitable default
                if (is_null($userPensumCourse->status)) {
                    $userPensumCourse->status = 0; 
                }

                // Ensure progress and credits are present for the frontend (Nuxt app).
                $userPensumCourse->progress = !is_null($userPensumCourse->progress) ? (float)$userPensumCourse->progress : 0;
                $userPensumCourse->credits = !is_null($userPensumCourse->credits) ? (int)$userPensumCourse->credits : 0;

                $periodName = $DB->get_record('local_learning_periods', ['id' => $userPensumCourse->periodid]);

                $userPensumCourse->coursename = $courseNamesById[(int)$userPensumCourse->courseid] ?? 'Unknown Course';
                $userPensumCourse->periodname = $periodName ? $periodName->name : 'Periodo Desconocido';
                $userPensumCourse->grade = '-';
                $coursegrade = null;
                $gradesource = 'none';

                $progressclassid = !empty($userPensumCourse->progressclassid) ? (int)$userPensumCourse->progressclassid : 0;
                $progressgroupid = !empty($userPensumCourse->progressgroupid) ? (int)$userPensumCourse->progressgroupid : 0;
                $courseidkey = (int)$userPensumCourse->courseid;

                // 0) Highest priority: explicit "Nota Final Integrada" (final academic grade).
                if (array_key_exists($courseidkey, $manualIntegratedGradeByCourseId)) {
                    $candidate = (float)$manualIntegratedGradeByCourseId[$courseidkey];
                    if ($candidate >= 0 && $candidate <= 100) {
                        $coursegrade = $candidate;
                        $gradesource = 'manual_nota_final_integrada';
                    }
                }

                // Historical-safe fallback by subject name (prevents forcing old grades on current in-progress subjects).
                $historicalstatus = in_array((int)$userPensumCourse->status, [3, 4, 5, 6, 7], true);
                if (is_null($coursegrade) && $historicalstatus) {
                    $coursenamekey = trim((string)($courseNamesById[$courseidkey] ?? ''));
                    if ($coursenamekey !== '' && array_key_exists($coursenamekey, $manualIntegratedGradeByCourseName)) {
                        $candidate = (float)$manualIntegratedGradeByCourseName[$coursenamekey];
                        if ($candidate >= 0 && $candidate <= 100) {
                            $coursegrade = $candidate;
                            $gradesource = 'manual_nota_final_integrada_same_name';
                        }
                    }
                }

                // 1) Preferred: class category grade (strict class/group scope).
                if (is_null($coursegrade) && $progressclassid > 0 && array_key_exists($progressclassid, $classGradeByClassId)) {
                    $coursegrade = (float)$classGradeByClassId[$progressclassid];
                    $gradesource = 'class_category';
                } else if (is_null($coursegrade) && $progressgroupid > 0 && array_key_exists($progressgroupid, $classGradeByGroupId)) {
                    $coursegrade = (float)$classGradeByGroupId[$progressgroupid];
                    $gradesource = 'group_class_category';
                } else if (is_null($coursegrade)) {
                    // 1b) Fallback by classes where the student is group member in this same course.
                    if (!empty($membershipClassGradesByCourseId[$courseidkey])) {
                        $valid = array_values(array_filter(array_map('floatval', $membershipClassGradesByCourseId[$courseidkey]), function($v) {
                            return ($v >= 0 && $v <= 100);
                        }));
                        if (!empty($valid)) {
                            $coursegrade = round(max($valid), 2);
                            $gradesource = 'membership_class_category';
                        }
                    }
                }

                // 1c) Fallback by any class category in same plan/course that has a sane grade for this student.
                if (is_null($coursegrade)) {
                    if (array_key_exists($courseidkey, $planClassCategoryGradeByCourseId)) {
                        $candidate = (float)$planClassCategoryGradeByCourseId[$courseidkey];
                        if ($candidate >= 0 && $candidate <= 100) {
                            $coursegrade = $candidate;
                            $gradesource = 'plan_class_category';
                        }
                    }
                }

                // 2) Then: Moodle course total if sane (0..100). This restores real historical grades.
                if (is_null($coursegrade) && array_key_exists($courseidkey, $gradesByCourseId)) {
                    $candidate = (float)$gradesByCourseId[$courseidkey];
                    if ($candidate >= 0 && $candidate <= 100) {
                        $coursegrade = $candidate;
                        $gradesource = 'course_total';
                    } else {
                        $gradesource = 'course_total_out_of_range';
                    }
                }

                // 3) Last fallback: persisted grade in gmk_course_progre.
                if (is_null($coursegrade) && isset($userPensumCourse->progressgrade) && !is_null($userPensumCourse->progressgrade)) {
                    $candidate = round((float)$userPensumCourse->progressgrade, 2);
                    if ($candidate >= 0 && $candidate <= 100) {
                        $coursegrade = $candidate;
                        $gradesource = ($gradesource === 'course_total_out_of_range') ? 'gmk_course_progre_after_out_of_range_course_total' : 'gmk_course_progre';
                    } else {
                        $gradesource = 'gmk_course_progre_out_of_range';
                    }
                }

                if (!is_null($coursegrade)) {
                    $userPensumCourse->grade = (string)$coursegrade;

                    // [VIRTUAL FALLBACK]
                    // Auto-upgrade when base state is not-started/available OR an inconsistent failed record.
                    // Keep explicit "Cursando" (2) untouched to avoid showing approved while still active.
                    $basestatus = (int)$userPensumCourse->status;
                    $canvirtualapprove = in_array($basestatus, [0, 1, 5], true);
                    $virtualpassgrade = ($basestatus === 5) ? 71.0 : 70.0;
                    if ($coursegrade >= $virtualpassgrade && $canvirtualapprove) {
                        $userPensumCourse->status = 3; // COURSE_COMPLETED
                        $userPensumCourse->progress = 100.00;
                    }
                }
                $userPensumCourse->gradesource = $gradesource;

                $userPensumCourse->statusLabel = self::STATUS_LABEL[$userPensumCourse->status] ?? 'No disponible';
                $userPensumCourse->statusColor = self::STATUS_COLOR[$userPensumCourse->status] ?? '#5e35b1';

                // Number of active classes available for manual enrollment from Academic Panel.
                $learningKey = (int)$userPensumCourse->learningcourseid;
                $coreKey = (int)$userPensumCourse->courseid;
                $userPensumCourse->activeclasscount = (int)(
                    $activeClassCountByLearningCourse[$learningKey]
                    ?? $activeClassCountByCoreCourse[$coreKey]
                    ?? $activeClassCountByCoreCourseCurrentPeriod[$coreKey]
                    ?? $activeClassCountByCoreCourseAnyPlan[$coreKey]
                    ?? 0
                );
                
                // Handle prerequisites safely
                $userPensumCourse->prerequisites = !empty($userPensumCourse->prerequisites) ? json_decode($userPensumCourse->prerequisites) : [];

                foreach ($userPensumCourse->prerequisites as $prerequisite) {
                    $completion = new \completion_info(get_course($prerequisite->id));
                    $prerequisite->completed = $completion->is_course_complete($params['userId']);
                }
                
                if (!array_key_exists($userPensumCourse->periodid, $groupedUserPensumCourses)) {
                    $groupedUserPensumCourses[$userPensumCourse->periodid]['id'] = $userPensumCourse->periodid;
                    $groupedUserPensumCourses[$userPensumCourse->periodid]['periodName'] = $userPensumCourse->periodname;
                }
                $groupedUserPensumCourses[$userPensumCourse->periodid]['courses'][] = $userPensumCourse;
            }
            
            // Fix: If array is empty, ensure we return an object or array as expected.
            // Original code implied an object of objects keyed by periodId.
            // If empty, return empty object.
            if (empty($groupedUserPensumCourses)) {
                return [
                    'status' => 1,
                    'pensum' => json_encode(new \stdClass()),
                    'message' => 'ok'
                ];
            }

            return [
                'status' => 1,
                'pensum' => json_encode($groupedUserPensumCourses),
                'message' => 'ok'
            ];
        } catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }
    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, '1 for success, -1 for failure', VALUE_DEFAULT, 1),
                'pensum' => new external_value(PARAM_RAW, 'json encode object with the pensum info', VALUE_DEFAULT, null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.', VALUE_DEFAULT, 'ok'),
            )
        );
    }
}
