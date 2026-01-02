<?php
// pages/debug_activities.php

// Adjust path to find config.php from local/grupomakro_core/pages/
$config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) {
    // Fallback if structure is different
    $config_path = __DIR__ . '/../../../../config.php';
}
require_once($config_path);
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login(); // Ensure user is logged in and $USER is set

// Define a simple log/print helper
function debug_print($msg) {
    echo "<div style='font-family: monospace; border-bottom: 1px solid #ccc; padding: 2px;'>$msg</div>";
}

echo "<h1>Debug Activities Logic</h1>";

$classid = optional_param('classid', 0, PARAM_INT);

if (!$classid) {
    echo "<p>Please select a class to debug:</p>";
    $now = time();
    $sql = "SELECT c.id, c.name, c.corecourseid 
            FROM {gmk_class} c
            WHERE c.instructorid = :userid 
              AND c.closed = 0
            ORDER BY c.id DESC";
    
    $classes = $DB->get_records_sql($sql, ['userid' => $USER->id]);
    
    if (empty($classes)) {
        echo "<p>No active classes found for user $USER->username (ID: $USER->id)</p>";
    } else {
        echo "<ul>";
        foreach ($classes as $c) {
            $course = $DB->get_record('course', ['id' => $c->corecourseid], 'fullname');
            $coursename = $course ? $course->fullname : "Unknown Course";
            echo "<li><a href='?classid={$c->id}'><strong>{$c->name}</strong></a> - $coursename (ID: {$c->id})</li>";
        }
        echo "</ul>";
    }
    die();
}

debug_print("Checking Class ID: $classid");

try {
    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if (!$class) {
        throw new Exception("Class with ID $classid not found in DB.");
    }
    debug_print("Class Found: " . $class->name . " (Type: " . $class->type . ")");
    debug_print("Core Course ID: " . $class->corecourseid);

    require_once($CFG->libdir . '/modinfolib.php');
    $modinfo = get_fast_modinfo($class->corecourseid);
    $cms = $modinfo->get_cms();
    debug_print("Total CMs in course: " . count($cms));

    // Check excluded instances
    debug_print("Querying gmk_bbb_attendance_relation for excluded instances...");
    $sql = "SELECT bbbid FROM {gmk_bbb_attendance_relation} WHERE classid = :classid AND bbbid IS NOT NULL";
    $excluded_instances_raw = $DB->get_fieldset_sql($sql, ['classid' => $class->id]);
    
    if (!$excluded_instances_raw) {
        $excluded_instances = [];
        debug_print("No excluded instances found.");
    } else {
        $excluded_instances = $excluded_instances_raw;
        debug_print("Excluded BBB Instances: " . implode(', ', $excluded_instances));
    }

    $activities = [];
    $count_visible = 0;
    $count_hidden = 0;
    $count_excluded_bbb = 0;
    $count_excluded_label = 0;

    foreach ($cms as $cm) {
        if (!$cm->uservisible) {
            $count_hidden++;
            continue;
        }
        
        if ($cm->modname === 'label') {
             $count_excluded_label++;
             continue;
        }

        if ($cm->modname === 'bigbluebuttonbn' && in_array($cm->instance, $excluded_instances)) {
            debug_print("Skipping BBB Instance {$cm->instance} (CM {$cm->id}) found in timeline exclusion.");
            $count_excluded_bbb++;
            continue;
        }

        debug_print("<strong>Found Activity:</strong> {$cm->name} (Type: {$cm->modname}, ID: {$cm->id})");
        
        try {
            $tags = \core_tag_tag::get_item_tags('core', 'course_modules', $cm->id);
            $tagNames = array_map(function($t) { return $t->rawname; }, $tags);
            debug_print(" - Tags: " . implode(', ', $tagNames));
        } catch (Exception $e) {
            debug_print(" - Tag Error: " . $e->getMessage());
        }

        $activities[] = [
            'id' => $cm->id,
            'name' => $cm->name
        ];
        $count_visible++;
    }

    debug_print("<br><strong>Summary:</strong>");
    debug_print("Visible: $count_visible");
    debug_print("Hidden (User Not Visible): $count_hidden");
    debug_print("Excluded (Label): $count_excluded_label");
    debug_print("Excluded (BBB Timeline): $count_excluded_bbb");

    echo "<pre>" . json_encode($activities, JSON_PRETTY_PRINT) . "</pre>";

} catch (Exception $e) {
    debug_print("<strong>EXCEPTION:</strong> " . $e->getMessage());
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
