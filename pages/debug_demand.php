<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

use local_grupomakro_core\local\planning_manager;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_demand.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Demand Projection');
$PAGE->set_heading('Debug Demand Projection Logic');

echo $OUTPUT->header();

$courseId = optional_param('courseid', 0, PARAM_INT);
$periodId = optional_param('periodid', 1, PARAM_INT); // Default to 1 for debug

$planningData = planning_manager::get_planning_data($periodId);
$students = $planningData['students'];
$allSubjects = $planningData['all_subjects'];

echo "<style>
    .debug-table { width:100%; border-collapse:collapse; margin-top:20px; font-size:0.9em; }
    .debug-table th, .debug-table td { border:1px solid #ddd; padding:8px; text-align:left; }
    .debug-table th { background:#f4f4f4; }
    .status-ok { color:green; font-weight:bold; }
    .status-fail { color:red; font-weight:bold; }
    .status-warn { color:orange; font-weight:bold; }
    .highlight { background:#fff3cd; }
</style>";

echo "<h3>Demand Breakdown per Subject</h3>";
echo "<form method='get'>
        Select Subject: <select name='courseid' onchange='this.form.submit()'>
            <option value='0'>-- All Subjects --</option>";
foreach ($allSubjects as $sub) {
    $sel = ($courseId == $sub['id']) ? 'selected' : '';
    echo "<option value='{$sub['id']}' $sel>{$sub['name']} (Level {$sub['semester_num']})</option>";
}
echo "</select></form>";

if ($courseId > 0) {
    $courseName = "";
    foreach ($allSubjects as $sub) if ($sub['id'] == $courseId) $courseName = $sub['name'];
    
    echo "<h4>Demand for: $courseName</h4>";
    echo "<table class='debug-table'>
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Theoretical Level</th>
                    <th>Prereqs Met?</th>
                    <th>Is Reprobada?</th>
                    <th>Included in P-I?</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>";
    
    $count = 0;
    foreach ($students as $stu) {
        foreach ($stu['pendingSubjects'] as $ps) {
            if ($ps['id'] == $courseId) {
                $isMet = !empty($ps['isPreRequisiteMet']);
                $isReprobada = !empty($ps['isReprobada']);
                
                // --- CURRENT LOGIC (Refined) ---
                $included = $isMet; 
                $reason = "";
                if (!$isMet) $reason = "Missing Prerequisites";
                else $reason = "OK - Included (" . ($isReprobada ? "Reprobada" : "Pending") . ")";

                $rowClass = $included ? "highlight" : "";
                if ($included) $count++;
                
                echo "<tr class='$rowClass'>
                        <td>{$stu['name']}</td>
                        <td>{$stu['currentSemConfig']}</td>
                        <td class='" . ($isMet ? "status-ok" : "status-fail") . "'>" . ($isMet ? "YES" : "NO") . "</td>
                        <td class='" . ($isReprobada ? "status-fail" : "") . "'>" . ($isReprobada ? "YES" : "NO") . "</td>
                        <td class='" . ($included ? "status-ok" : "status-fail") . "'>" . ($included ? "YES" : "NO") . "</td>
                        <td>$reason</td>
                      </tr>";
            }
        }
    }
    echo "</tbody></table>";
    echo "<p><strong>Total Demand (P-I) for this course: $count</strong></p>";
} else {
    echo "<p>Please select a subject to see the student breakdown.</p>";
}

echo $OUTPUT->footer();
