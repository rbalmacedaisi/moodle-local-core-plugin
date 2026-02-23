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

echo $OUTPUT->header();
echo "<h1>Manage Orphan Classes</h1>";

global $DB;

// Handle deletion
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
    echo "<h2>Orphan Classes Found: " . count($orphans) . "</h2>";
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
    
    foreach ($orphans as $c) {
        $created = date('Y-m-d H:i:s', $c->timecreated);
        
        // Try to find Level info
        $level = $DB->get_record('local_learning_periods', ['id' => $c->periodid], 'id, name');
        $levelname = $level ? $level->name : '<span style="color:red;">Invalid/Missing</span>';
        
        // Try to find LP info
        $lp = $DB->get_record('local_learning_plans', ['id' => $c->learningplanid], 'id, name');
        $lpname = $lp ? $lp->name : '<span style="color:red;">Invalid/Missing</span>';

        $delete_url = new moodle_url($PAGE->url, ['delete_id' => $c->id]);
        echo "<tr>
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
    echo "</table>";
} else {
    echo $OUTPUT->notification("No orphan classes found.", 'notifysuccess');
}

echo $OUTPUT->footer();
