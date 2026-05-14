<?php
namespace local_grupomakro_core\external\student;

use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/grade/lib.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->libdir  . '/gradelib.php');

/**
 * Returns a clean, structured gradebook for a student in a course.
 * Groups grade items by category and returns only items relevant to the
 * student's class section (via gmk_class / gmk_bbb_attendance_relation).
 */
class get_student_gradebook extends external_api
{
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'userId'   => new external_value(PARAM_INT, 'Student user id',  VALUE_REQUIRED),
            'courseId' => new external_value(PARAM_INT, 'Moodle course id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($userId, $courseId)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userId'   => $userId,
            'courseId' => $courseId,
        ]);
        $userId   = (int)$params['userId'];
        $courseId = (int)$params['courseId'];

        try {
            // ── 1. Section-based discovery (same proven logic as get_student_course_pensum_activities)
            $coursemod          = get_fast_modinfo($courseId, $userId);
            $userGroups         = $coursemod->get_groups();
            $gradableActivities = grade_get_gradable_activities($courseId);

            // ── 2. Grade categories for grouping and weight computation ────────
            $categories = $DB->get_records('grade_categories', ['courseid' => $courseId], 'id ASC');
            $catMap    = [];
            $catAggMap = [];
            foreach ($categories as $cat) {
                $catMap[$cat->id]    = trim($cat->fullname) ?: 'General';
                $catAggMap[$cat->id] = (int)$cat->aggregation;
            }

            // ── 3. Walk the student's class sections and collect grade items ───
            $items            = [];
            $seenGradeItemIds = [];
            $classGradeCatIds = [];

            foreach ($userGroups as $userGroup) {
                $groupClassSection = $DB->get_field('gmk_class', 'coursesectionid', ['groupid' => $userGroup]);
                if (!$groupClassSection) {
                    continue;
                }
                $gradeCatId = (int)$DB->get_field('gmk_class', 'gradecategoryid', ['groupid' => $userGroup]);
                if ($gradeCatId > 0) {
                    $classGradeCatIds[] = $gradeCatId;
                }

                try {
                    $section = $coursemod->get_section_info_by_id($groupClassSection);
                    if (!$section) continue;
                    $sectionNumber = $section->__get('section');
                } catch (Exception $secEx) {
                    continue;
                }

                if (!isset($coursemod->get_sections()[$sectionNumber])) {
                    continue;
                }

                foreach ($coursemod->get_sections()[$sectionNumber] as $sectionModule) {
                    $module       = $coursemod->get_cm($sectionModule);
                    $moduleRecord = $module->get_course_module_record(true);
                    $moduleType   = $moduleRecord->modname;

                    if ($moduleType === 'bigbluebuttonbn'
                            || !array_key_exists($moduleRecord->id, $gradableActivities)) {
                        continue;
                    }

                    // Fetch the grade_item record for this module
                    $gi = $DB->get_record_sql(
                        "SELECT gi.id, gi.categoryid, gi.itemname, gi.grademax,
                                gi.aggregationcoef, gi.aggregationcoef2, gi.sortorder,
                                gg.finalgrade, gg.feedback
                           FROM {grade_items} gi
                           LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
                          WHERE gi.courseid = :cid
                            AND gi.itemtype = 'mod'
                            AND gi.itemmodule = :modname
                            AND gi.iteminstance = :instance
                            AND gi.itemnumber = 0",
                        ['uid'      => $userId,
                         'cid'      => $courseId,
                         'modname'  => $moduleType,
                         'instance' => (int)$moduleRecord->instance]
                    );

                    if (!$gi || in_array((int)$gi->id, $seenGradeItemIds)) {
                        continue;
                    }
                    $seenGradeItemIds[] = (int)$gi->id;

                    $gradeFormatted = null;
                    $gradePercent   = null;

                    if ($moduleType === 'attendance') {
                        // Only count sessions where attendance was actually taken:
                        // sessions with at least one log entry (any student) OR lasttaken > 0.
                        // Sessions with no logs at all are "phantom" (class cancelled / teacher
                        // forgot) — the absence_dashboard hides them for the same reason.
                        $attRow = $DB->get_record_sql(
                            "SELECT COUNT(s.id)                                                    AS total,
                                    SUM(CASE WHEN al.id IS NOT NULL AND ast.grade > 0 THEN 1
                                             ELSE 0 END)                                           AS present
                               FROM {attendance_sessions} s
                               LEFT JOIN {attendance_log}      al  ON al.sessionid = s.id
                                                                   AND al.studentid = :uid
                               LEFT JOIN {attendance_statuses} ast ON ast.id = al.statusid
                              WHERE s.attendanceid = :attid
                                AND s.sessdate + s.duration < :now
                                AND (
                                    EXISTS (SELECT 1 FROM {attendance_log} l WHERE l.sessionid = s.id)
                                    OR COALESCE(s.lasttaken, 0) > 0
                                )",
                            ['uid'   => $userId,
                             'attid' => (int)$moduleRecord->instance,
                             'now'   => time()]
                        );
                        if ($attRow && (int)$attRow->total > 0 && $gi->grademax > 0) {
                            $gradePercent   = round(((int)$attRow->present / (int)$attRow->total) * 100, 1);
                            $gradeFormatted = round($gradePercent * (float)$gi->grademax / 100, 2);
                        }
                    } elseif (!is_null($gi->finalgrade) && $gi->grademax > 0) {
                        $gradeFormatted = round((float)$gi->finalgrade, 2);
                        $gradePercent   = round(((float)$gi->finalgrade / (float)$gi->grademax) * 100, 1);
                    }

                    $itemCatId    = (int)$gi->categoryid;
                    $categoryName = isset($catMap[$itemCatId]) ? $catMap[$itemCatId] : 'General';
                    $itemCatAgg   = $catAggMap[$itemCatId] ?? 13;
                    $rawWeight    = ($itemCatAgg === 10 || $itemCatAgg === 2)
                        ? (float)$gi->aggregationcoef
                        : (float)$gi->aggregationcoef2;

                    $label = $gi->itemname ?: $moduleRecord->name;

                    $items[] = [
                        'id'           => (int)$gi->id,
                        'category'     => $categoryName === '?' ? 'General' : $categoryName,
                        '_categoryid'  => $itemCatId,
                        '_cagg'        => $itemCatAgg,
                        '_raw_weight'  => $rawWeight,
                        '_sortorder'   => (int)($gi->sortorder ?? 0),
                        'name'         => $label,
                        'module'       => $moduleType,
                        'grade'        => $gradeFormatted,
                        'grade_max'    => (float)$gi->grademax,
                        'grade_pct'    => $gradePercent,
                        'feedback'     => $gi->feedback ?: '',
                        'weight_pct'   => 0,
                        'weighted_contribution' => null,
                    ];
                }
            }

            // ── 3b. Manual grade items — not linked to any section module.
            //        Queried directly by gmk_class.gradecategoryid.
            //        Uses SQL_PARAMS_NAMED to avoid mixing named/positional param types.
            $classGradeCatIds = array_unique($classGradeCatIds);
            if (!empty($classGradeCatIds)) {
                list($insql, $inparams) = $DB->get_in_or_equal($classGradeCatIds, SQL_PARAMS_NAMED, 'catid');
                $manualGis = $DB->get_records_sql(
                    "SELECT gi.id, gi.categoryid, gi.itemname, gi.grademax,
                            gi.aggregationcoef, gi.aggregationcoef2, gi.sortorder,
                            gg.finalgrade, gg.feedback
                       FROM {grade_items} gi
                       LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
                      WHERE gi.courseid = :cid
                        AND gi.itemtype = 'manual'
                        AND gi.categoryid $insql",
                    array_merge(['uid' => $userId, 'cid' => $courseId], $inparams)
                );
                foreach ($manualGis as $gi) {
                    if (in_array((int)$gi->id, $seenGradeItemIds)) continue;
                    if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false
                            && is_null($gi->finalgrade)) continue;
                    $seenGradeItemIds[] = (int)$gi->id;

                    $gradeFormatted = null;
                    $gradePercent   = null;
                    if (!is_null($gi->finalgrade) && $gi->grademax > 0) {
                        $gradeFormatted = round((float)$gi->finalgrade, 2);
                        $gradePercent   = round(((float)$gi->finalgrade / (float)$gi->grademax) * 100, 1);
                    }

                    $itemCatId    = (int)$gi->categoryid;
                    $categoryName = isset($catMap[$itemCatId]) ? $catMap[$itemCatId] : 'General';
                    $itemCatAgg   = $catAggMap[$itemCatId] ?? 13;
                    $rawWeight    = ($itemCatAgg === 10 || $itemCatAgg === 2)
                        ? (float)$gi->aggregationcoef
                        : (float)$gi->aggregationcoef2;

                    $items[] = [
                        'id'           => (int)$gi->id,
                        'category'     => $categoryName === '?' ? 'General' : $categoryName,
                        '_categoryid'  => $itemCatId,
                        '_cagg'        => $itemCatAgg,
                        '_raw_weight'  => $rawWeight,
                        '_sortorder'   => (int)($gi->sortorder ?? 0),
                        'name'         => $gi->itemname ?: 'Item Manual',
                        'module'       => 'manual',
                        'grade'        => $gradeFormatted,
                        'grade_max'    => (float)$gi->grademax,
                        'grade_pct'    => $gradePercent,
                        'feedback'     => $gi->feedback ?: '',
                        'weight_pct'   => 0,
                        'weighted_contribution' => null,
                    ];
                }
            }

            // Sort by sortorder so items appear in the same order as in the gradebook
            usort($items, fn($a, $b) => $a['_sortorder'] <=> $b['_sortorder']);

            // ── 4. Compute weight_pct / weighted_contribution ─────────────────
            $catRawSums = [];
            foreach ($items as $item) {
                if ($item['_cagg'] === 10 || $item['_cagg'] === 2) {
                    $catRawSums[$item['_categoryid']] = ($catRawSums[$item['_categoryid']] ?? 0.0) + $item['_raw_weight'];
                }
            }
            foreach ($items as &$item) {
                $cagg = $item['_cagg'];
                $cid  = $item['_categoryid'];
                if ($cagg === 10 || $cagg === 2) {
                    $sum = $catRawSums[$cid] ?? 0;
                    $item['weight_pct'] = $sum > 0 ? round(($item['_raw_weight'] / $sum) * 100, 1) : 0;
                } else {
                    $item['weight_pct'] = round($item['_raw_weight'] * 100, 1);
                }
                if (!is_null($item['grade']) && $item['grade_max'] > 0 && $item['weight_pct'] > 0) {
                    $item['weighted_contribution'] = round(($item['grade'] / $item['grade_max']) * $item['weight_pct'], 1);
                }
                unset($item['_categoryid'], $item['_cagg'], $item['_raw_weight'], $item['_sortorder']);
            }
            unset($item);

            // ── 6. Group by category ──────────────────────────────────────────
            $grouped = [];
            foreach ($items as $item) {
                $grouped[$item['category']][] = $item;
            }

            $result = [];
            foreach ($grouped as $catName => $catItems) {
                $result[] = [
                    'category' => $catName,
                    'items'    => $catItems,
                ];
            }

            // ── 7. Official Moodle course-level final grade ───────────────────
            $courseGrade = null;
            $courseTotalGi = $DB->get_record('grade_items',
                ['courseid' => $courseId, 'itemtype' => 'course'], 'id,grademax');
            if ($courseTotalGi && $courseTotalGi->grademax > 0) {
                $gg = $DB->get_record('grade_grades',
                    ['itemid' => $courseTotalGi->id, 'userid' => $userId], 'finalgrade');
                if ($gg && $gg->finalgrade !== null) {
                    $courseGrade = round(($gg->finalgrade / $courseTotalGi->grademax) * 100, 2);
                }
            }

            return ['status' => 1, 'gradebook' => json_encode($result), 'course_grade' => $courseGrade];
        } catch (Exception $e) {
            return ['status' => -1, 'gradebook' => json_encode([]), 'course_grade' => null];
        }
    }

    public static function execute_returns(): external_description
    {
        return new external_single_structure([
            'status'      => new external_value(PARAM_INT,   '1 ok, -1 error',                       VALUE_DEFAULT, 1),
            'gradebook'   => new external_value(PARAM_RAW,   'JSON gradebook grouped by category',   VALUE_DEFAULT, '[]'),
            'course_grade'=> new external_value(PARAM_FLOAT, 'Official Moodle final grade 0-100',    VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }
}
