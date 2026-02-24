<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../locallib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context); // CRITICAL: Fix for user_picture crashes
require_capability('moodle/site:config', $context);

$classid = optional_param('class_id', 0, PARAM_INT);

echo "<style>
    body { font-family: sans-serif; padding: 20px; line-height: 1.6; }
    pre { background: #f4f4f4; padding: 10px; border: 1px solid #ddd; overflow: auto; max-height: 400px;}
    .class-item { padding: 10px; border-bottom: 1px solid #eee; }
    .class-item:hover { background: #f9f9f9; }
    .badge { padding: 3px 7px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
    .badge-approved { background: #d4edda; color: #155724; }
    .badge-pending { background: #fff3cd; color: #856404; }
</style>";

// --- SEARCH & SCAN TOOLS ---
$search = optional_param('search', '', PARAM_RAW);
$scan = optional_param('scan_duplicates', 0, PARAM_INT);

echo "<div style='background:#e7f3ff; padding:15px; border-radius:8px; margin-bottom:20px; display: flex; gap: 20px;'>
    <div style='flex: 1; border-right: 1px solid #ccc; padding-right: 20px;'>
        <h3>Search Tool (Hash/Email)</h3>
        <form method='get'>
            <input type='text' name='search' value='" . s($search) . "' placeholder='Paste hash or email here...' style='width:250px; padding:5px;'>
            <button type='submit'>Search User</button>
        </form>";

if ($search) {
    $foundUsers = $DB->get_records_sql("SELECT * FROM {user} WHERE email LIKE ? OR username LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR id = ?", ["%$search%", "%$search%", "%$search%", "%$search%", (int)$search]);
    if ($foundUsers) {
        echo "<h4>Search Results:</h4><ul>";
        foreach ($foundUsers as $u) {
            $lpCount = $DB->count_records('local_learning_users', ['userid' => $u->id]);
            $is_hash = preg_match('/^[a-f0-9]{32}$/i', $u->email) ? "<span style='color:red'> [HASH EMAIL]</span>" : "";
            echo "<li><strong>ID: {$u->id}</strong> | Name: " . fullname($u) . " | Email: {$u->email} $is_hash | LP Roles: $lpCount</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No user found matching that search.</p>";
    }
}
echo "</div>
    <div style='flex: 1;'>
        <h3>Duplicate Scanner</h3>
        <p>Finds users with identical First/Last names.</p>
        <form method='get'>
            <input type='hidden' name='scan_duplicates' value='1'>
            <button type='submit' style='background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;'>Scan All Teachers for Duplicates</button>
        </form>";

if ($scan) {
    echo "<h4>Possible Duplicate Groups:</h4>";
    $sql = "SELECT firstname, lastname, COUNT(id) as c 
            FROM {user} 
            WHERE deleted = 0 
            GROUP BY firstname, lastname 
            HAVING COUNT(id) > 1 
            ORDER BY c DESC";
    $duplicates = $DB->get_records_sql($sql);
    
    if ($duplicates) {
        echo "<ul style='max-height: 400px; overflow-y: auto;'>";
        foreach ($duplicates as $dup) {
            $users = $DB->get_records('user', ['firstname' => $dup->firstname, 'lastname' => $dup->lastname], 'id ASC');
            echo "<li style='margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: white;'>";
            echo "<strong>" . fullname($dup) . " ({$dup->c} accounts)</strong><br>";
            foreach ($users as $u) {
                $lpCount = $DB->count_records('local_learning_users', ['userid' => $u->id]);
                $classCount = $DB->count_records('gmk_class', ['instructorid' => $u->id]);
                $is_hash = preg_match('/^[a-f0-9]{32}$/i', $u->email) ? "<span style='color:red;'> [HASH]</span>" : "";
                $style = $is_hash ? "color: #666;" : "color: #000; font-weight: bold;";
                echo "<span style='$style'>- ID: {$u->id} | Email: {$u->email} $is_hash | LP: $lpCount | Classes: $classCount</span><br>";
            }
            echo "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No exact name duplicates found.</p>";
    }
}
echo "</div></div>";
// -------------------

if (!$classid) {
    echo "<h2>Select a Class to Debug</h2>";
    echo "<p>Showing the 50 most recent classes:</p>";
    
    $classes = $DB->get_records('gmk_class', null, 'id DESC', '*', 0, 50);
    
    if (!$classes) {
        echo "<p>No classes found in gmk_class table.</p>";
    } else {
        echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%; max-width: 1000px;'>";
        echo "<tr style='background:#eee;'><th>ID</th><th>Name</th><th>Instructor</th><th>Days</th><th>Status</th></tr>";
        foreach ($classes as $c) {
            $url = new moodle_url('/local/grupomakro_core/pages/debug_mapping_details.php', ['class_id' => $c->id]);
            $approved = $c->approved ? '<span class="badge badge-approved">Approved</span>' : '<span class="badge badge-pending">Draft</span>';
            $hasDays = ($c->classdays !== '0/0/0/0/0/0/0') ? "<strong>$c->classdays</strong>" : "<span style='color:red'>None</span>";
            $instructor = $c->instructorid ? "ID: $c->instructorid" : "<span style='color:orange'>Unassigned</span>";
            
            echo "<tr>";
            echo "<td>{$c->id}</td>";
            echo "<td><a href='{$url}'>{$c->name}</a></td>";
            echo "<td>$instructor</td>";
            echo "<td>$hasDays</td>";
            echo "<td>$approved</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} else {
    echo "<a href='debug_mapping_details.php'>&larr; Back to selection</a>";
    echo "<h2>Inspecting Class ID: $classid</h2>";

    // 1. Raw Class Record
    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if (!$class) {
        die("<div style='color:red'>Class not found in gmk_class table.</div>");
    }

    echo "<h3>1. Raw Data (DB: gmk_class)</h3>";
    echo "<pre>";
    print_r($class);
    echo "</pre>";

    // 2. Component Logic - Days
    echo "<h3>2. Logic Simulation: Days Parsing</h3>";
    $classDaysRaw = trim($class->classdays ?? '');
    echo "Raw string in DB: <code>'{$classDaysRaw}'</code><br>";
    $daysParts = explode('/', $classDaysRaw);
    $classDays = [
        'monday'    => isset($daysParts[0]) && $daysParts[0] === '1',
        'tuesday'   => isset($daysParts[1]) && $daysParts[1] === '1',
        'wednesday' => isset($daysParts[2]) && $daysParts[2] === '1',
        'thursday'  => isset($daysParts[3]) && $daysParts[3] === '1',
        'friday'    => isset($daysParts[4]) && $daysParts[4] === '1',
        'saturday'  => isset($daysParts[5]) && $daysParts[5] === '1',
        'sunday'    => isset($daysParts[6]) && $daysParts[6] === '1'
    ];
    echo "Boolean Map for Frontend:";
    echo "<pre>";
    print_r($classDays);
    echo "</pre>";

    // 3. Teacher Logic
    echo "<h3>3. Logic Simulation: Teacher Finder</h3>";
    $params = [
        'courseId' => $class->courseid,
        'initTime' => $class->inittime,
        'endTime' => $class->endtime,
        'classDays' => $class->classdays,
        'learningPlanId' => $class->learningplanid,
        'classId' => $classid
    ];
    $potentialTeachers = get_potential_class_teachers($params);

    echo "<p>Calling <code>get_potential_class_teachers()</code>...</p>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #eee;'><th>ID (Normalizado)</th><th>Nombre</th><th>Email</th><th>¿Seleccionado?</th><th>Detalles</th></tr>";
    
    $seenIds = [];
    foreach ($potentialTeachers as $pt) {
        $ptId = (int)$pt->id;
        $isSelected = ($ptId === (int)$class->instructorid);
        $rowStyle = $isSelected ? "background: #e8f5e9; font-weight: bold;" : "";
        $isDuplicate = isset($seenIds[$ptId]) ? "<span style='color:red'> [DUPLICADO]</span>" : "";
        
        echo "<tr style='$rowStyle'>";
        echo "<td>$ptId</td>";
        echo "<td>{$pt->fullname} $isDuplicate</td>";
        echo "<td>{$pt->email}</td>";
        echo "<td>" . ($isSelected ? "YES (Matches InstructorID)" : "No") . "</td>";
        echo "<td><pre style='font-size: 0.8em; padding:5px; margin:0;'>" . print_r($pt, true) . "</pre></td>";
        echo "</tr>";
        
        $seenIds[$ptId] = true;
    }
    echo "</table>";

    // 4. Comparison Check
    echo "<h3>4. Comparison Diagnostics</h3>";
    $instructorIdInClass = (int)$class->instructorid;
    echo "Current <code>instructorid</code> stored in Class: <strong>$instructorIdInClass</strong> (Type: " . gettype($class->instructorid) . ")<br>";
    
    echo "<h4>Checking all entries for ID matches:</h4>";
    foreach ($potentialTeachers as $pt) {
        $ptIdInt = (int)$pt->id;
        $match = ($ptIdInt === $instructorIdInClass) ? "<span style='color:green'>MATCH!</span>" : "No match";
        echo "Checking Teacher ID {$pt->id}: (int){$ptIdInt} === (int){$instructorIdInClass}? &rarr; $match<br>";
    }
}
