<?php
// pages/debug_activities.php

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

// Define a simple log/print helper
function debug_print($msg) {
    echo "<div style='font-family: monospace; border-bottom: 1px solid #ccc; padding: 2px;'>$msg</div>";
}

echo "<h1>Debug Activities Logic</h1>";

$classid = optional_param('classid', 0, PARAM_INT);
if (!$classid) {
    echo "<form>Class ID: <input name='classid' type='number'><button type='submit'>Check</button></form>";
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
    $sql = "SELECT bbbactivityid FROM {gmk_bbb_attendance_relation} WHERE classid = :classid AND bbbactivityid IS NOT NULL";
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
