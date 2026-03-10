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
        $userId   = $params['userId'];
        $courseId = $params['courseId'];

        try {
            // ── 1. Find the student's class section ids in this course ────────
            $userGroups = $DB->get_records_sql(
                'SELECT gm.groupid FROM {groups_members} gm
                 JOIN {groups} g ON g.id = gm.groupid
                 WHERE gm.userid = :uid AND g.courseid = :cid',
                ['uid' => $userId, 'cid' => $courseId]
            );
            $groupIds = array_column($userGroups, 'groupid');

            // All grade category ids that belong to ANY class in this course
            $allClassCategoryIds = array_column(
                $DB->get_records('gmk_class', ['corecourseid' => $courseId], '', 'id,gradecategoryid'),
                'gradecategoryid'
            );
            $allClassCategoryIds = array_filter(array_map('intval', $allClassCategoryIds));

            // Grade category ids and attendance module ids belonging to the student's classes
            $studentCategoryIds  = [];
            $attendanceModuleIds = [];
            if (!empty($groupIds)) {
                list($inSql, $inParams) = $DB->get_in_or_equal($groupIds);
                $classes = $DB->get_records_sql(
                    "SELECT id, attendancemoduleid, gradecategoryid
                     FROM {gmk_class}
                     WHERE groupid $inSql AND corecourseid = :cid",
                    array_merge($inParams, ['cid' => $courseId])
                );
                foreach ($classes as $c) {
                    if ($c->attendancemoduleid) {
                        $attendanceModuleIds[] = (int)$c->attendancemoduleid;
                    }
                    if ($c->gradecategoryid) {
                        $studentCategoryIds[] = (int)$c->gradecategoryid;
                    }
                }
            }

            // ── 2. Fetch all grade items for this course ──────────────────────
            $gradeItems = $DB->get_records_sql(
                "SELECT gi.id, gi.categoryid, gi.itemname, gi.itemtype, gi.itemmodule,
                        gi.iteminstance, gi.grademax, gi.aggregationcoef, gi.aggregationcoef2,
                        gi.weightoverride, gi.sortorder,
                        gg.finalgrade, gg.feedback
                 FROM {grade_items} gi
                 LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
                 WHERE gi.courseid = :cid AND gi.itemtype != 'course'
                 ORDER BY gi.sortorder ASC",
                ['uid' => $userId, 'cid' => $courseId]
            );

            // ── 3. Fetch grade categories ─────────────────────────────────────
            $categories = $DB->get_records('grade_categories', ['courseid' => $courseId], 'id ASC');
            $catMap = [];
            foreach ($categories as $cat) {
                $catMap[$cat->id] = $cat->fullname;
            }

            // ── 4. Build flat list of items, skipping irrelevant BBB items ─────
            $items = [];
            foreach ($gradeItems as $gi) {
                // Skip items that belong to another group's grade category.
                // "Global" items (categoryid not owned by any class) are always shown.
                // If the student has a known category, only show items in their category
                // or in global categories (not owned by any other class either).
                $itemCatId = (int)$gi->categoryid;
                if (!empty($allClassCategoryIds)) {
                    // For regular items: filter by their categoryid
                    $belongsToAClass = in_array($itemCatId, $allClassCategoryIds);
                    if ($belongsToAClass && !in_array($itemCatId, $studentCategoryIds)) {
                        continue; // Belongs to a different group's category
                    }
                    // For category-total items (itemtype='category'): their categoryid is 0,
                    // but iteminstance = the grade_category.id they represent.
                    if ($gi->itemtype === 'category') {
                        $representsCatId = (int)$gi->iteminstance;
                        $representsAClass = in_array($representsCatId, $allClassCategoryIds);
                        if ($representsAClass && !in_array($representsCatId, $studentCategoryIds)) {
                            continue; // Total of a different group's category
                        }
                    }
                }

                // Skip attendance items that don't belong to the student's class
                if ($gi->itemmodule === 'attendance') {
                    // Find the course module for this attendance instance
                    $cm = $DB->get_field('course_modules', 'id',
                        ['course' => $courseId, 'module' => $DB->get_field('modules','id',['name'=>'attendance']), 'instance' => $gi->iteminstance]);
                    if ($cm && !in_array((int)$cm, $attendanceModuleIds)) {
                        continue; // Belongs to another section
                    }
                }

                // Skip BBB items entirely (sessions, not grades)
                if ($gi->itemmodule === 'bigbluebuttonbn') {
                    continue;
                }

                // Skip category totals with no grade (e.g. "Total Revalida grade")
                if ($gi->itemtype === 'category' && is_null($gi->finalgrade)) {
                    continue;
                }

                // Format grade
                $gradeFormatted = null;
                $gradePercent   = null;
                if (!is_null($gi->finalgrade) && $gi->grademax > 0) {
                    $gradeFormatted = round($gi->finalgrade, 2);
                    $gradePercent   = round(($gi->finalgrade / $gi->grademax) * 100, 1);
                }

                $categoryName = isset($catMap[$gi->categoryid]) ? $catMap[$gi->categoryid] : 'General';

                // Determine label: use itemname for activities, or a friendly fallback
                $label = $gi->itemname ?: ucfirst($gi->itemmodule ?: $gi->itemtype);

                $items[] = [
                    'id'           => (int) $gi->id,
                    'category'     => $categoryName === '?' ? 'General' : $categoryName,
                    'name'         => $label,
                    'module'       => $gi->itemmodule ?: $gi->itemtype,
                    'grade'        => $gradeFormatted,       // null = sin calificar
                    'grade_max'    => (float) $gi->grademax,
                    'grade_pct'    => $gradePercent,         // % sobre grademax
                    'feedback'     => $gi->feedback ?: '',
                ];
            }

            // ── 5. Group by category ──────────────────────────────────────────
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

            return ['status' => 1, 'gradebook' => json_encode($result)];
        } catch (Exception $e) {
            return ['status' => -1, 'gradebook' => json_encode([])];
        }
    }

    public static function execute_returns(): external_description
    {
        return new external_single_structure([
            'status'    => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'gradebook' => new external_value(PARAM_RAW, 'JSON gradebook grouped by category', VALUE_DEFAULT, '[]'),
        ]);
    }
}
