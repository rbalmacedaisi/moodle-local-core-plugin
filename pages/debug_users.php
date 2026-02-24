<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_users.php'));
$PAGE->set_title('Debug: Users Data');
$PAGE->set_heading('Debug: Users Data');
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

global $DB;

$classId = optional_param('classid', 0, PARAM_INT);
$courseId = optional_param('courseid', 50, PARAM_INT);

echo "<h3>Debug: Users Data</h3>";

// 1. List open classes for this course
echo "<h4>1. Open classes for corecourseid=$courseId</h4>";
$classes = $DB->get_records('gmk_class', ['corecourseid' => $courseId, 'closed' => 0]);
if (empty($classes)) {
    // Try via local_learning_courses
    $subjects = $DB->get_records('local_learning_courses', ['courseid' => $courseId], '', 'id');
    if (!empty($subjects)) {
        foreach (array_keys($subjects) as $sid) {
            $found = $DB->get_records('gmk_class', ['courseid' => $sid, 'closed' => 0]);
            $classes = array_merge($classes, $found);
        }
    }
}

echo "<p>Found " . count($classes) . " open classes</p>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
echo "<tr><th>class.id</th><th>name</th><th>Pre-Reg</th><th>Queued</th><th>Enrolled</th><th>Action</th></tr>";
foreach ($classes as $c) {
    $preReg = $DB->count_records('gmk_class_pre_registration', ['classid' => $c->id]);
    $queued = $DB->count_records('gmk_class_queue', ['classid' => $c->id]);
    $enrolled = 0;
    if (!empty($c->groupid)) {
        $enrolled = $DB->count_records('groups_members', ['groupid' => $c->groupid]);
    }
    echo "<tr>";
    echo "<td>{$c->id}</td>";
    echo "<td>{$c->name}</td>";
    echo "<td>$preReg</td>";
    echo "<td>$queued</td>";
    echo "<td>$enrolled</td>";
    echo "<td><a href='?courseid=$courseId&classid={$c->id}'>Inspect</a></td>";
    echo "</tr>";
}
echo "</table>";

// 2. If a classId is selected, show detailed student data
if ($classId > 0) {
    echo "<hr><h4>2. Students for class $classId</h4>";
    
    $class = $DB->get_record('gmk_class', ['id' => $classId]);
    if (!$class) {
        echo "<p style='color:red;'>Class $classId not found!</p>";
    } else {
        echo "<p>Class: <strong>{$class->name}</strong></p>";
        
        // Pre-registered students
        $preRegStudents = $DB->get_records('gmk_class_pre_registration', ['classid' => $classId]);
        echo "<h5>Pre-registered students: " . count($preRegStudents) . "</h5>";
        if (!empty($preRegStudents)) {
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
            echo "<tr><th>reg.id</th><th>userid</th><th>courseid</th><th>User Exists?</th><th>firstname</th><th>lastname</th><th>email</th></tr>";
            foreach ($preRegStudents as $s) {
                $user = $DB->get_record('user', ['id' => $s->userid], 'id, firstname, lastname, email, deleted');
                echo "<tr>";
                echo "<td>{$s->id}</td>";
                echo "<td>{$s->userid}</td>";
                echo "<td>{$s->courseid}</td>";
                if ($user) {
                    $deleted = $user->deleted ? ' <span style="color:red;">(DELETED)</span>' : '';
                    echo "<td>Yes$deleted</td>";
                    echo "<td>{$user->firstname}</td>";
                    echo "<td>{$user->lastname}</td>";
                    echo "<td>{$user->email}</td>";
                } else {
                    echo "<td style='color:red;'>NO - user not found</td>";
                    echo "<td>-</td><td>-</td><td>-</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Queued students
        $queuedStudents = $DB->get_records('gmk_class_queue', ['classid' => $classId]);
        echo "<h5>Queued students: " . count($queuedStudents) . "</h5>";
        if (!empty($queuedStudents)) {
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
            echo "<tr><th>queue.id</th><th>userid</th><th>courseid</th><th>User Exists?</th><th>firstname</th><th>lastname</th><th>email</th></tr>";
            foreach ($queuedStudents as $s) {
                $user = $DB->get_record('user', ['id' => $s->userid], 'id, firstname, lastname, email, deleted');
                echo "<tr>";
                echo "<td>{$s->id}</td>";
                echo "<td>{$s->userid}</td>";
                echo "<td>{$s->courseid}</td>";
                if ($user) {
                    $deleted = $user->deleted ? ' <span style="color:red;">(DELETED)</span>' : '';
                    echo "<td>Yes$deleted</td>";
                    echo "<td>{$user->firstname}</td>";
                    echo "<td>{$user->lastname}</td>";
                    echo "<td>{$user->email}</td>";
                } else {
                    echo "<td style='color:red;'>NO - user not found</td>";
                    echo "<td>-</td><td>-</td><td>-</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Test user_get_users_by_id
        echo "<h5>Testing user_get_users_by_id()</h5>";
        $allStudents = array_merge(
            array_values($preRegStudents),
            array_values($queuedStudents)
        );
        if (!empty($allStudents)) {
            $testUserId = $allStudents[0]->userid;
            echo "<p>Testing with userid=$testUserId:</p>";
            
            // Check if function exists
            echo "<p>Function exists: <strong>" . (function_exists('user_get_users_by_id') ? 'YES' : 'NO') . "</strong></p>";
            
            if (function_exists('user_get_users_by_id')) {
                $result = user_get_users_by_id([$testUserId]);
                echo "<p>Result type: " . gettype($result) . "</p>";
                echo "<p>Result count: " . (is_array($result) ? count($result) : 'N/A') . "</p>";
                echo "<p>Has key [$testUserId]: " . (isset($result[$testUserId]) ? 'YES' : 'NO') . "</p>";
                echo "<pre>" . htmlspecialchars(print_r($result, true)) . "</pre>";
            } else {
                // Try core_user functions
                echo "<p>Trying \$DB->get_record('user'):</p>";
                $user = $DB->get_record('user', ['id' => $testUserId]);
                if ($user) {
                    echo "<p>Found: {$user->firstname} {$user->lastname} ({$user->email})</p>";
                } else {
                    echo "<p style='color:red;'>User not found in DB!</p>";
                }
            }
        }
    }
}

echo $OUTPUT->footer();
