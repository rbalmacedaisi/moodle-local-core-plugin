<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/datalib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

echo $OUTPUT->header();
echo "<h1>Diagnostic: Calendar Events & Deadlines</h1>";

// Summary of event types in the whole system
echo "<h3>System Event Types Summary</h3>";
$types = $DB->get_records_sql("SELECT DISTINCT eventtype, modulename FROM {event} WHERE timestart > 0");
echo "<ul>";
foreach ($types as $t) {
    echo "<li>Mod: <b>" . s($t->modulename) . "</b>, Type: <b>" . s($t->eventtype) . "</b></li>";
}
echo "</ul>";

$courseid = optional_param('courseid', 0, PARAM_INT);

if (!$courseid) {
    echo "<h3>Select a Course to inspect events</h3>";
    $courses = $DB->get_records('course', [], 'id DESC', 'id, fullname, shortname', 0, 20);
    echo "<ul>";
    foreach ($courses as $c) {
        echo "<li><a href='?courseid={$c->id}'>[ID: {$c->id}] {$c->fullname} ({$c->shortname})</a></li>";
    }
    echo "</ul>";
} else {
    echo "<h2>Course ID: $courseid</h2>";
    echo "<a href='debug_calendar_events.php'>&larr; Back to list</a><br><br>";

    // Fetch events from mdl_event
    $events = $DB->get_records('event', ['courseid' => $courseid], 'timestart ASC');

    echo "<h3>Events in mdl_event for this course</h3>";
    if (empty($events)) {
        echo "<p>No events found.</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
        echo "<tr>
                <th>ID</th>
                <th>Name</th>
                <th>Event Type</th>
                <th>Module</th>
                <th>Instance ID</th>
                <th>Time Start (Date)</th>
                <th>Visible</th>
              </tr>";
        foreach ($events as $e) {
            $date = date('Y-m-d H:i:s', $e->timestart);
            echo "<tr>";
            echo "<td>{$e->id}</td>";
            echo "<td>" . s($e->name) . "</td>";
            echo "<td>" . s($e->eventtype) . "</td>";
            echo "<td>" . s($e->modulename) . "</td>";
            echo "<td>{$e->instance}</td>";
            echo "<td>$date</td>";
            echo "<td>{$e->visible}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Also check assignment specific due dates if they are not in event table
    echo "<h3>Assignments in this course (mdl_assign)</h3>";
    $assigns = $DB->get_records('assign', ['course' => $courseid]);
    if (empty($assigns)) {
        echo "<p>No assignments found.</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
        echo "<tr>
                <th>ID</th>
                <th>Name</th>
                <th>Due Date</th>
                <th>Allow Submissions From</th>
                <th>Grade Deadline (cutoff)</th>
              </tr>";
        foreach ($assigns as $a) {
            $duedate = $a->duedate ? date('Y-m-d H:i:s', $a->duedate) : 'N/A';
            $allowfrom = $a->allowsubmissionsfromdate ? date('Y-m-d H:i:s', $a->allowsubmissionsfromdate) : 'N/A';
            $cutoff = $a->cutoffdate ? date('Y-m-d H:i:s', $a->cutoffdate) : 'N/A';
            echo "<tr>";
            echo "<td>{$a->id}</td>";
            echo "<td>" . s($a->name) . "</td>";
            echo "<td>$duedate</td>";
            echo "<td>$allowfrom</td>";
            echo "<td>$cutoff</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

echo $OUTPUT->footer();
