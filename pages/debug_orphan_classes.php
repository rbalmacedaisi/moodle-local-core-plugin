<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_orphan_classes.php'));
$PAGE->set_title("Manage Orphan Classes");

$delete_id = optional_param('delete_id', null, PARAM_INT);
$confirm = optional_param('confirm', false, PARAM_BOOL);
$bulk_delete = optional_param('bulk_delete', false, PARAM_BOOL);

echo $OUTPUT->header();
echo "<h1>Manage Orphan Classes</h1>";

global $DB;

// Handle bulk deletion
if ($bulk_delete && $confirm) {
    core_php_time_limit::set(600); // 10 minutes
    raise_memory_limit(MEMORY_HUGE);

    $sql = "SELECT c.id 
            FROM {gmk_class} c
            LEFT JOIN {gmk_academic_periods} p ON c.periodid = p.id
            LEFT JOIN {local_learning_periods} lp ON c.periodid = lp.id
            LEFT JOIN {local_learning_plans} lplan ON c.learningplanid = lplan.id
            WHERE (c.periodid = 0 OR p.id IS NULL) AND lp.id IS NULL AND lplan.id IS NULL";
    
    $to_delete = $DB->get_records_sql($sql);
    $count = 0;
    $errors = 0;
    foreach ($to_delete as $c) {
        try {
            if (delete_class($c->id, 'Bulk deleted via debug tool (no level/no LP)')) {
                $count++;
            }
        } catch (Exception $e) {
            $errors++;
            gmk_log("Error bulk deleting class {$c->id}: " . $e->getMessage());
        }
    }
    echo $OUTPUT->notification("Successfully deleted $count invalid classes." . ($errors ? " ($errors errors logged)" : ""), 'notifysuccess');
} else if ($bulk_delete) {
    echo $OUTPUT->confirm(
        "Are you sure you want to delete ALL classes that have no Learning Plan AND no level metadata?",
        new moodle_url($PAGE->url, ['bulk_delete' => 1, 'confirm' => 1]),
        $PAGE->url
    );
     echo $OUTPUT->footer();
     die();
}

// Handle single deletion
if ($delete_id && $confirm) {
    try {
        // Use the proper deletion function to clean up related objects
        if (delete_class($delete_id, 'Orphan class deleted via debug tool')) {
            echo $OUTPUT->notification("Class $delete_id deleted successfully.", 'notifysuccess');
        } else {
            echo $OUTPUT->notification("Failed to delete class $delete_id.", 'notifyproblem');
        }
    } catch (Exception $e) {
        echo $OUTPUT->notification("Error deleting class $delete_id: " . $e->getMessage(), 'notifyproblem');
    }
} else if ($delete_id) {
    echo $OUTPUT->confirm(
        "Are you sure you want to delete class ID $delete_id and all its related content (groups, sections, activities)?",
        new moodle_url($PAGE->url, ['delete_id' => $delete_id, 'confirm' => 1]),
        $PAGE->url
    );
     echo $OUTPUT->footer();
     die();
}

// Identification Logic
// We consider orphan if periodid is 0 OR if it doesn't match any record in gmk_academic_periods
// Note: In some contexts periodid might refer to local_learning_periods (levels)
$sql = "SELECT c.* 
        FROM {gmk_class} c
        LEFT JOIN {gmk_academic_periods} p ON c.periodid = p.id
        WHERE c.periodid = 0 OR p.id IS NULL";

$orphans = $DB->get_records_sql($sql);

if ($orphans) {
    $invalid_count = 0;
    $table_content = "";
    
    foreach ($orphans as $c) {
        $created = date('Y-m-d H:i:s', $c->timecreated);
        
        // Try to find Level info
        $level = $DB->get_record('local_learning_periods', ['id' => $c->periodid], 'id, name');
        $levelname = $level ? $level->name : '<span style="color:red;">Invalid/Missing</span>';
        
        // Try to find LP info
        $lp = $DB->get_record('local_learning_plans', ['id' => $c->learningplanid], 'id, name');
        $lpname = $lp ? $lp->name : '<span style="color:red;">Invalid/Missing</span>';

        if (!$level && !$lp) {
            $invalid_count++;
        }

        $delete_url = new moodle_url($PAGE->url, ['delete_id' => $c->id]);
        $table_content .= "<tr>
                <td>{$c->id}</td>
                <td>" . s($c->name) . "</td>
                <td>{$c->corecourseid}</td>
                <td>{$c->periodid}</td>
                <td>$levelname</td>
                <td>$lpname</td>
                <td>$created</td>
                <td><a href='$delete_url' class='btn btn-danger btn-sm'>Delete</a></td>
              </tr>";
    }

    echo "<div class='alert alert-info d-flex justify-content-between align-items-center'>
            <span>Orphan Classes Found: " . count($orphans) . " (Severely Invalid: $invalid_count)</span>";
    
    if ($invalid_count > 0) {
        $bulk_url = new moodle_url($PAGE->url, ['bulk_delete' => 1]);
        echo "<a href='$bulk_url' class='btn btn-warning'>Delete All $invalid_count Invalid (No LP & No Level)</a>";
    }
    
    echo "</div>";

    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #eee;'>
            <th>ID</th>
            <th>Name</th>
            <th>Moodle Course ID</th>
            <th>Period ID (Value)</th>
            <th>Level (local_learning_periods)</th>
            <th>Learning Plan</th>
            <th>Time Created</th>
            <th>Actions</th>
          </tr>";
    echo $table_content;
    echo "</table>";
}

echo $OUTPUT->footer();
