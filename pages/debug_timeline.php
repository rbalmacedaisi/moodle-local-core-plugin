<?php
require_once(__DIR__ . '/../../../config.php');
require_login();

// Param: classid
$classid = optional_param('classid', 0, PARAM_INT);

echo "<html><head><title>Timeline Debugger</title><style>table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #ccc; padding: 8px; text-align: left; } .match { background-color: #e6fffa; } .partial { background-color: #fff8e1; } .fail { background-color: #ffebee; }</style></head><body>";

echo "<h1>Timeline Debugger</h1>";

if (!$classid) {
    echo "<p>Please append <code>?classid=YOUR_CLASS_ID</code> to the URL.</p>";
    // List Classes
    $classes = $DB->get_records('gmk_class');
    echo "<h3>Available Classes:</h3><ul>";
    foreach($classes as $c) {
        echo "<li><a href='?classid={$c->id}'>ID: {$c->id} - {$c->name} (Group: {$c->groupid})</a></li>";
    }
    echo "</ul>";
    die();
}

$class = $DB->get_record('gmk_class', ['id' => $classid]);
if (!$class) die("Class ID $classid not found.");

echo "<h2>Class Details</h2>";
echo "<ul>";
echo "<li><strong>Name:</strong> {$class->name}</li>";
echo "<li><strong>Class Group ID:</strong> {$class->groupid}</li>";
echo "<li><strong>Course ID:</strong> {$class->corecourseid}</li>";
echo "</ul>";

echo "<h2>Events Analysis</h2>";

// 1. Fetch ALL events for this Course
$sql = "SELECT e.* FROM {event} e WHERE e.courseid = :courseid ORDER BY e.timestart ASC";
$events = $DB->get_records_sql($sql, ['courseid' => $class->corecourseid]);

echo "<p>Found <strong>" . count($events) . "</strong> total events for Course ID {$class->corecourseid}.</p>";

echo "<table>";
echo "<thead><tr><th>ID</th><th>Name</th><th>Module</th><th>Group ID</th><th>Time</th><th>Match Status</th><th>Reason</th></tr></thead><tbody>";

foreach ($events as $e) {
    $classAttr = "";
    $status = "";
    $reason = "";
    
    $isModuleMatch = in_array($e->modulename, ['attendance', 'bigbluebuttonbn']);
    $isGroupMatch = ($e->groupid == $class->groupid || empty($e->groupid));
    
    if ($isModuleMatch && $isGroupMatch) {
        $classAttr = "match";
        $status = "PASS";
    } else {
        $classAttr = "fail";
        $status = "FAIL";
        if (!$isModuleMatch) $reason .= "Module '$e->modulename' not allowed. ";
        if (!$isGroupMatch) $reason .= "Group ID '$e->groupid' != '{$class->groupid}'. ";
    }
    
    echo "<tr class='$classAttr'>";
    echo "<td>{$e->id}</td>";
    echo "<td>{$e->name}</td>";
    echo "<td>{$e->modulename}</td>";
    echo "<td>" . ($e->groupid ?: '0/NULL') . "</td>";
    echo "<td>" . userdate($e->timestart) . "</td>";
    echo "<td><strong>$status</strong></td>";
    echo "<td>$reason</td>";
    echo "</tr>";
}
echo "</tbody></table>";
echo "</body></html>";
