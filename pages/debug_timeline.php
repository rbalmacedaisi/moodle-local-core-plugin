<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');

// Adjust this ID to a known class ID you want to test
$classid = optional_param('classid', 0, PARAM_INT);

echo "<pre>";

if (!$classid) {
    echo "Please provide a classid parameter (e.g., ?classid=123)\n";
    echo "Available Classes:\n";
    $classes = $DB->get_records('gmk_class', null, '', 'id, name, groupid, corecourseid');
    foreach ($classes as $c) {
        echo "ID: {$c->id} | Name: {$c->name} | Group: {$c->groupid} | Course: {$c->corecourseid}\n";
    }
    die();
}

echo "Debugging Timeline for Class ID: $classid\n";
$class = $DB->get_record('gmk_class', ['id' => $classid]);

if (!$class) {
    die("Class not found.");
}

echo "Class Info:\n";
print_r($class);

$tstart = strtotime('-6 month');
$tend = strtotime('+6 months');
$groups = [$class->groupid];
$courses = [$class->corecourseid];

echo "\nParameters for calendar_get_events:\n";
echo "Start: " . date('Y-m-d H:i:s', $tstart) . "\n";
echo "End: " . date('Y-m-d H:i:s', $tend) . "\n";
echo "Groups: " . implode(',', $groups) . "\n";
echo "Courses: " . implode(',', $courses) . "\n";

// Raw Calendar Call
$events = calendar_get_events($tstart, $tend, null, $groups, $courses);

echo "\nRaw Events Found: " . count($events) . "\n";

foreach ($events as $e) {
    echo "--------------------------------------------------\n";
    echo "Event ID: {$e->id}\n";
    echo "Name: {$e->name}\n";
    echo "Module Name: {$e->modulename}\n";
    echo "Instance: {$e->instance}\n";
    echo "Time: " . date('Y-m-d H:i:s', $e->timestart) . "\n";
    
    // Check BBB Link Logic
    if ($e->modulename === 'attendance') {
        $sql = "SELECT rel.bbbactivityid, sess.id as sessionid
                FROM {attendance_sessions} sess
                JOIN {gmk_bbb_attendance_relation} rel ON rel.attendancesessionid = sess.id
                WHERE sess.caleventid = :caleventid";
        $rel = $DB->get_record_sql($sql, ['caleventid' => $e->id]);
        echo "Linked BBB Relation: " . ($rel ? "YES (BBB ID: {$rel->bbbactivityid})" : "NO") . "\n";
    }
}
echo "</pre>";
