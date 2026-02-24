<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_scheduleapproval.php'));
$PAGE->set_title('Debug: Schedule Approval Query');
$PAGE->set_heading('Debug: Schedule Approval Query');
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

global $DB;

// Get optional params from URL
$courseId = optional_param('courseid', 50, PARAM_INT);
$periodIds = optional_param('periodids', '20,5', PARAM_RAW);

echo "<h3>Debug: Schedule Approval Query</h3>";
echo "<p><strong>URL Params:</strong> courseid=$courseId, periodids=$periodIds</p>";

// 1. Check what's in gmk_class for this corecourseid
echo "<hr><h4>1. Classes in gmk_class with corecourseid=$courseId</h4>";
$classes = $DB->get_records('gmk_class', ['corecourseid' => $courseId]);
echo "<p>Found: <strong>" . count($classes) . "</strong> classes</p>";
if (!empty($classes)) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
    echo "<tr><th>id</th><th>name</th><th>courseid</th><th>corecourseid</th><th>periodid</th><th>learningplanid</th><th>closed</th><th>approved</th><th>classdays</th></tr>";
    foreach ($classes as $c) {
        $highlight = ($c->closed == 0) ? 'background:#d4edda;' : 'background:#f8d7da;';
        echo "<tr style='$highlight'>";
        echo "<td>{$c->id}</td>";
        echo "<td>{$c->name}</td>";
        echo "<td>{$c->courseid}</td>";
        echo "<td>{$c->corecourseid}</td>";
        echo "<td>{$c->periodid}</td>";
        echo "<td>{$c->learningplanid}</td>";
        echo "<td>{$c->closed}</td>";
        echo "<td>{$c->approved}</td>";
        echo "<td>" . ($c->classdays ?? 'null') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>No classes found with corecourseid=$courseId</p>";
    
    // Try finding by courseid instead
    echo "<h5>Trying with courseid=$courseId instead:</h5>";
    $classesByCourseid = $DB->get_records('gmk_class', ['courseid' => $courseId]);
    echo "<p>Found: <strong>" . count($classesByCourseid) . "</strong> classes with courseid=$courseId</p>";
    if (!empty($classesByCourseid)) {
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
        echo "<tr><th>id</th><th>name</th><th>courseid</th><th>corecourseid</th><th>periodid</th><th>learningplanid</th><th>closed</th><th>approved</th></tr>";
        foreach ($classesByCourseid as $c) {
            echo "<tr>";
            echo "<td>{$c->id}</td>";
            echo "<td>{$c->name}</td>";
            echo "<td>{$c->courseid}</td>";
            echo "<td>{$c->corecourseid}</td>";
            echo "<td>{$c->periodid}</td>";
            echo "<td>{$c->learningplanid}</td>";
            echo "<td>{$c->closed}</td>";
            echo "<td>{$c->approved}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

// 2. Check with only periodid filter
echo "<hr><h4>2. Period IDs check</h4>";
$periods = explode(",", $periodIds);
foreach ($periods as $pid) {
    $pid = trim($pid);
    $count = $DB->count_records('gmk_class', ['periodid' => $pid, 'closed' => 0]);
    $countAll = $DB->count_records('gmk_class', ['periodid' => $pid]);
    echo "<p>periodid=$pid: <strong>$count open</strong> classes (total: $countAll)</p>";
    
    // Check with both corecourseid and periodid
    $combo = $DB->count_records('gmk_class', ['corecourseid' => $courseId, 'periodid' => $pid, 'closed' => 0]);
    $comboAll = $DB->count_records('gmk_class', ['corecourseid' => $courseId, 'periodid' => $pid]);
    echo "<p>&nbsp;&nbsp;→ with corecourseid=$courseId: <strong>$combo open</strong> (total: $comboAll)</p>";
}

// 3. Check the Moodle course record
echo "<hr><h4>3. Moodle course info (id=$courseId)</h4>";
$course = $DB->get_record('course', ['id' => $courseId], 'id, fullname, shortname, visible');
if ($course) {
    echo "<p>Course: <strong>{$course->fullname}</strong> ({$course->shortname}) - Visible: {$course->visible}</p>";
} else {
    echo "<p style='color:red;'>No Moodle course found with id=$courseId</p>";
}

// 4. Check local_learning_courses for this courseid
echo "<hr><h4>4. Subjects in local_learning_courses with courseid=$courseId</h4>";
$subjects = $DB->get_records('local_learning_courses', ['courseid' => $courseId], '', 'id, courseid, learningplanid, periodid');
echo "<p>Found: <strong>" . count($subjects) . "</strong> subjects</p>";
if (!empty($subjects)) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
    echo "<tr><th>Subject ID (llc.id)</th><th>courseid (Moodle)</th><th>learningplanid</th><th>periodid (level)</th></tr>";
    foreach ($subjects as $s) {
        echo "<tr>";
        echo "<td>{$s->id}</td>";
        echo "<td>{$s->courseid}</td>";
        echo "<td>{$s->learningplanid}</td>";
        echo "<td>{$s->periodid}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 5. Check gmk_class for these subject IDs
    echo "<hr><h4>5. Classes with courseid matching subject IDs from above</h4>";
    foreach ($subjects as $s) {
        $classesForSubj = $DB->get_records('gmk_class', ['courseid' => $s->id], '', 'id, name, courseid, corecourseid, periodid, learningplanid, closed, approved');
        if (!empty($classesForSubj)) {
            echo "<p><strong>Subject ID={$s->id}</strong> (plan={$s->learningplanid}): " . count($classesForSubj) . " classes</p>";
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
            echo "<tr><th>class.id</th><th>name</th><th>courseid</th><th>corecourseid</th><th>periodid</th><th>learningplanid</th><th>closed</th><th>approved</th></tr>";
            foreach ($classesForSubj as $c) {
                $highlight = ($c->closed == 0) ? 'background:#d4edda;' : 'background:#f8d7da;';
                echo "<tr style='$highlight'>";
                echo "<td>{$c->id}</td>";
                echo "<td>{$c->name}</td>";
                echo "<td>{$c->courseid}</td>";
                echo "<td>{$c->corecourseid}</td>";
                echo "<td>{$c->periodid}</td>";
                echo "<td>{$c->learningplanid}</td>";
                echo "<td>{$c->closed}</td>";
                echo "<td>{$c->approved}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
}

// 6. List ALL distinct periodid values in gmk_class that have this corecourseid (even closed)
echo "<hr><h4>6. All distinct periodid values for classes related to course $courseId</h4>";
$sql = "SELECT DISTINCT gc.periodid, COUNT(*) as cnt, 
        SUM(CASE WHEN gc.closed = 0 THEN 1 ELSE 0 END) as open_cnt
        FROM {gmk_class} gc 
        WHERE gc.corecourseid = ? OR gc.courseid IN (SELECT id FROM {local_learning_courses} WHERE courseid = ?)
        GROUP BY gc.periodid
        ORDER BY gc.periodid";
$periodStats = $DB->get_records_sql($sql, [$courseId, $courseId]);
if (!empty($periodStats)) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
    echo "<tr><th>periodid</th><th>Total Classes</th><th>Open (closed=0)</th><th>Period Name</th></tr>";
    foreach ($periodStats as $ps) {
        $periodName = $DB->get_field('gmk_academic_periods', 'name', ['id' => $ps->periodid]);
        if (!$periodName) {
            $periodName = $DB->get_field('local_learning_periods', 'name', ['id' => $ps->periodid]);
        }
        $inUrl = in_array($ps->periodid, $periods) ? ' ✅ (in URL)' : '';
        echo "<tr>";
        echo "<td>{$ps->periodid}{$inUrl}</td>";
        echo "<td>{$ps->cnt}</td>";
        echo "<td>{$ps->open_cnt}</td>";
        echo "<td>" . ($periodName ?: 'Unknown') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>No classes found at all for course $courseId</p>";
}

// 7. Show what the schedule panel URL should use
echo "<hr><h4>7. Correct URL for this course</h4>";
if (!empty($periodStats)) {
    $correctPeriods = [];
    foreach ($periodStats as $ps) {
        if ($ps->open_cnt > 0) {
            $correctPeriods[] = $ps->periodid;
        }
    }
    if (!empty($correctPeriods)) {
        $correctPeriodsStr = implode(',', $correctPeriods);
        echo "<p>The correct <code>periodsid</code> for this course should be: <strong>$correctPeriodsStr</strong></p>";
        $correctUrl = $CFG->wwwroot . "/local/grupomakro_core/pages/scheduleapproval.php?id=$courseId&periodsid=$correctPeriodsStr";
        echo "<p><a href='$correctUrl' target='_blank'>Try this URL →</a></p>";
    }
}

echo $OUTPUT->footer();
