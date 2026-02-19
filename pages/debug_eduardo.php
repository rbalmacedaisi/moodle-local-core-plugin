<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_eduardo.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Eduardo Data');

echo $OUTPUT->header();

$email = 'eduardomunozm214@gmail.com'; // From user context
$user = $DB->get_record('user', ['email' => $email]);

if (!$user) {
    echo "User not found by email. Trying by name...<br>";
    $user = $DB->get_record('user', ['firstname' => 'Eduardo Eustasio', 'lastname' => 'Muñoz Campbell']);
}

if ($user) {
    echo "<h3>User Info</h3>";
    echo "ID: {$user->id}<br>";
    echo "Name: {$user->firstname} {$user->lastname}<br>";
    echo "ID Number: {$user->idnumber}<br>";

    echo "<h3>Learning Plan Enrollment</h3>";
    $llu = $DB->get_records('local_learning_users', ['userid' => $user->id]);
    foreach ($llu as $l) {
        $plan = $DB->get_record('local_learning_plans', ['id' => $l->learningplanid]);
        echo "Plan ID: {$l->learningplanid} - Name: " . ($plan ? $plan->name : 'Unknown') . " - Role: {$l->userrolename}<br>";
    }

    echo "<h3>Progress Records (gmk_course_progre)</h3>";
    $sql = "SELECT p.*, c.fullname as coursename 
            FROM {gmk_course_progre} p 
            JOIN {course} c ON c.id = p.courseid 
            WHERE p.userid = ?";
    $progre = $DB->get_records_sql($sql, [$user->id]);
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>
            <tr><th>Course ID</th><th>Course Name</th><th>Status</th><th>Grade</th></tr>";
    foreach ($progre as $p) {
        echo "<tr>
                <td>{$p->courseid}</td>
                <td>{$p->coursename}</td>
                <td>{$p->status}</td>
                <td>{$p->grade}</td>
              </tr>";
    }
    echo "</table>";

    echo "<h3>Course Completions</h3>";
    $completions = $DB->get_records('course_completions', ['userid' => $user->id]);
    echo "<ul>";
    foreach ($completions as $c) {
        $course = $DB->get_record('course', ['id' => $c->course]);
        echo "<li>ID: {$c->course} - Name: " . ($course ? $course->fullname : 'Unknown') . " - Time Completed: " . userdate($c->timecompleted) . "</li>";
    }
    echo "</ul>";

} else {
    echo "User not found.";
}

echo $OUTPUT->footer();
