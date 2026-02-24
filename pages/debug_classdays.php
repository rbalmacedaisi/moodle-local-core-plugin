<?php
/**
 * Debug: Class Days Diagnostic Page
 * 
 * Shows the full picture of how classdays is stored and derived for a given class ID.
 * Compares gmk_class.classdays vs gmk_class_schedules to identify mismatches.
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_classdays.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: ClassDays Diagnostic');
$PAGE->set_heading('Debug: ClassDays Diagnostic');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

$classId = optional_param('class_id', 0, PARAM_INT);
$periodId = optional_param('period_id', 0, PARAM_INT);

// ---- Get available periods ----
$periods = $DB->get_records('gmk_academic_periods', [], 'id DESC', 'id, name', 0, 20);

echo '<style>
    .dbg-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px; }
    .dbg-title { font-size:14px; font-weight:700; color:#334155; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.05em; }
    .dbg-table { width:100%; border-collapse:collapse; font-size:13px; }
    .dbg-table th { background:#f8fafc; color:#64748b; font-weight:700; text-align:left; padding:8px 12px; border-bottom:2px solid #e2e8f0; font-size:11px; text-transform:uppercase; }
    .dbg-table td { padding:8px 12px; border-bottom:1px solid #f1f5f9; color:#334155; }
    .dbg-table tr:hover td { background:#f8fafc; }
    .dbg-ok { color:#16a34a; font-weight:700; }
    .dbg-bad { color:#dc2626; font-weight:700; }
    .dbg-warn { color:#d97706; font-weight:700; }
    .dbg-mono { font-family:monospace; background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:12px; }
    .dbg-form { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:20px; padding:16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; }
    .dbg-form label { font-size:12px; font-weight:700; color:#64748b; display:block; margin-bottom:4px; }
    .dbg-form input, .dbg-form select { padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; }
    .dbg-form button { padding:6px 16px; background:#3b82f6; color:#fff; border:none; border-radius:4px; font-weight:700; cursor:pointer; font-size:13px; }
    .dbg-form button:hover { background:#2563eb; }
    .dbg-section { margin-bottom:24px; }
    .dbg-badge { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:10px; font-weight:800; text-transform:uppercase; }
    .dbg-badge-ok { background:#dcfce7; color:#16a34a; }
    .dbg-badge-bad { background:#fef2f2; color:#dc2626; }
    .dbg-badge-warn { background:#fffbeb; color:#d97706; }
    .dbg-highlight { background:#fef3c7; padding:8px 12px; border-radius:6px; border-left:4px solid #f59e0b; margin:12px 0; font-size:13px; }
    .dbg-log { background:#1e293b; color:#94a3b8; padding:12px; border-radius:6px; font-family:monospace; font-size:11px; max-height:300px; overflow-y:auto; white-space:pre-wrap; }
    .dbg-log .log-line { border-bottom:1px solid #334155; padding:2px 0; }
</style>';

// ---- Form ----
echo '<div class="dbg-form">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label>Class ID (specific)</label>
            <input type="number" name="class_id" value="' . $classId . '" placeholder="e.g. 123" style="width:120px;">
        </div>
        <div>
            <label>Or browse by Period</label>
            <select name="period_id">
                <option value="0">-- Seleccionar --</option>';
foreach ($periods as $p) {
    $sel = ($p->id == $periodId) ? 'selected' : '';
    echo "<option value=\"{$p->id}\" $sel>{$p->name} (ID: {$p->id})</option>";
}
echo '      </select>
        </div>
        <button type="submit">🔍 Diagnosticar</button>
    </form>
</div>';

// Helper function
function render_days_visual($classdaysStr) {
    $dayNames = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
    $parts = explode('/', $classdaysStr);
    $html = '';
    for ($i = 0; $i < 7; $i++) {
        $active = isset($parts[$i]) && $parts[$i] === '1';
        $bg = $active ? '#3b82f6' : '#e2e8f0';
        $color = $active ? '#fff' : '#94a3b8';
        $html .= "<span style='display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:4px;background:$bg;color:$color;font-weight:700;font-size:11px;margin-right:2px;'>{$dayNames[$i]}</span>";
    }
    return $html;
}

function derive_classdays_from_schedules($schedules) {
    $dayMap = ['Lunes' => 0, 'Martes' => 1, 'Miércoles' => 2, 'Miercoles' => 2, 'Jueves' => 3, 'Viernes' => 4, 'Sábado' => 5, 'Sabado' => 5, 'Domingo' => 6];
    $mask = [0, 0, 0, 0, 0, 0, 0];
    foreach ($schedules as $s) {
        $day = trim($s->day ?? '');
        if (isset($dayMap[$day])) {
            $mask[$dayMap[$day]] = 1;
        }
    }
    return implode('/', $mask);
}

// ---- Single Class Diagnosis ----
if ($classId > 0) {
    $class = $DB->get_record('gmk_class', ['id' => $classId]);
    if (!$class) {
        echo '<div class="dbg-card"><p class="dbg-bad">❌ Class ID ' . $classId . ' not found in gmk_class table.</p></div>';
    } else {
        // Get schedules from gmk_class_schedules
        $schedules = $DB->get_records('gmk_class_schedules', ['classid' => $classId]);
        $derivedDays = derive_classdays_from_schedules($schedules);
        
        $storedDays = trim($class->classdays ?? '');
        $allZero = ($storedDays === '' || $storedDays === '0/0/0/0/0/0/0');
        $derivedAllZero = ($derivedDays === '0/0/0/0/0/0/0');
        $mismatch = ($storedDays !== $derivedDays && !$derivedAllZero);
        
        // Get course name
        $courseName = $DB->get_field('course', 'fullname', ['id' => $class->corecourseid]) ?: 'N/A';
        // Get instructor name
        $instructor = $DB->get_record('user', ['id' => $class->instructorid], 'id, firstname, lastname');
        $instructorName = $instructor ? "{$instructor->firstname} {$instructor->lastname}" : 'Sin asignar';
        
        echo '<div class="dbg-card">';
        echo '<div class="dbg-title">📋 Class Record: ' . htmlspecialchars($class->name) . ' (ID: ' . $classId . ')</div>';
        echo '<table class="dbg-table">';
        echo '<tr><th>Field</th><th>Value</th><th>Status</th></tr>';
        echo '<tr><td>Name</td><td>' . htmlspecialchars($class->name) . '</td><td></td></tr>';
        echo '<tr><td>Course</td><td>' . htmlspecialchars($courseName) . ' (ID: ' . $class->corecourseid . ')</td><td></td></tr>';
        echo '<tr><td>Instructor</td><td>' . htmlspecialchars($instructorName) . ' (ID: ' . ($class->instructorid ?: '0') . ')</td>
              <td>' . (empty($class->instructorid) ? '<span class="dbg-badge dbg-badge-warn">⚠ Sin instructor</span>' : '<span class="dbg-badge dbg-badge-ok">✓</span>') . '</td></tr>';
        echo '<tr><td>Init Time / End Time</td><td><span class="dbg-mono">' . ($class->inittime ?: 'NULL') . '</span> — <span class="dbg-mono">' . ($class->endtime ?: 'NULL') . '</span></td><td></td></tr>';
        echo '<tr><td>Init Date / End Date</td><td>' . ($class->initdate ? date('Y-m-d', $class->initdate) : 'NULL') . ' — ' . ($class->enddate ? date('Y-m-d', $class->enddate) : 'NULL') . '</td><td></td></tr>';
        echo '<tr><td><strong>gmk_class.classdays</strong></td>
              <td>' . render_days_visual($storedDays) . ' <span class="dbg-mono">' . ($storedDays ?: 'EMPTY') . '</span></td>
              <td>' . ($allZero ? '<span class="dbg-badge dbg-badge-bad">✗ All zeros</span>' : '<span class="dbg-badge dbg-badge-ok">✓</span>') . '</td></tr>';
        echo '<tr><td><strong>Derived from gmk_class_schedules</strong></td>
              <td>' . render_days_visual($derivedDays) . ' <span class="dbg-mono">' . $derivedDays . '</span></td>
              <td>' . ($derivedAllZero ? '<span class="dbg-badge dbg-badge-warn">⚠ No schedules</span>' : '<span class="dbg-badge dbg-badge-ok">✓</span>') . '</td></tr>';
        echo '</table>';
        
        if ($mismatch) {
            echo '<div class="dbg-highlight">⚠️ <strong>MISMATCH DETECTED:</strong> The <code>gmk_class.classdays</code> field (<code>' . $storedDays . '</code>) does NOT match the days derived from <code>gmk_class_schedules</code> (<code>' . $derivedDays . '</code>). This is the root cause — <code>save_generation_result</code> is not correctly syncing the <code>classdays</code> bitmask from the sessions/day data.</div>';
        }
        if ($allZero && !$derivedAllZero) {
            echo '<div class="dbg-highlight">🔴 <strong>ROOT CAUSE:</strong> <code>gmk_class.classdays</code> is all zeros, but <code>gmk_class_schedules</code> shows this class IS scheduled on days: <code>' . $derivedDays . '</code>. The Planning Board\'s <code>save_generation_result</code> stores the day in <code>gmk_class_schedules</code> but doesn\'t derive the <code>classdays</code> bitmask from it.</div>';
        }
        echo '</div>';
        
        // Show schedules detail
        echo '<div class="dbg-card">';
        echo '<div class="dbg-title">📅 gmk_class_schedules Records (' . count($schedules) . ')</div>';
        if (empty($schedules)) {
            echo '<p style="color:#94a3b8;font-style:italic;">No schedule records found for this class.</p>';
        } else {
            echo '<table class="dbg-table">';
            echo '<tr><th>ID</th><th>Day</th><th>Start</th><th>End</th><th>Classroom ID</th><th>Excluded Dates</th></tr>';
            foreach ($schedules as $s) {
                echo '<tr>';
                echo '<td>' . $s->id . '</td>';
                echo '<td><strong>' . htmlspecialchars($s->day) . '</strong></td>';
                echo '<td><span class="dbg-mono">' . ($s->start_time ?? '') . '</span></td>';
                echo '<td><span class="dbg-mono">' . ($s->end_time ?? '') . '</span></td>';
                echo '<td>' . ($s->classroomid ?: '-') . '</td>';
                echo '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">' . htmlspecialchars($s->excluded_dates ?? '-') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }
}

// ---- Period Overview: Show all classes with day mismatches ----
if ($periodId > 0) {
    $classes = $DB->get_records('gmk_class', ['periodid' => $periodId], 'name ASC');
    
    echo '<div class="dbg-card">';
    echo '<div class="dbg-title">📊 Period Overview — All Classes (Period ID: ' . $periodId . ', Count: ' . count($classes) . ')</div>';
    
    $mismatchCount = 0;
    $allZeroCount = 0;
    
    echo '<table class="dbg-table">';
    echo '<tr><th>ID</th><th>Name</th><th>gmk_class.classdays</th><th>Derived (schedules)</th><th>Match?</th><th>Action</th></tr>';
    foreach ($classes as $c) {
        $schedules = $DB->get_records('gmk_class_schedules', ['classid' => $c->id]);
        $derived = derive_classdays_from_schedules($schedules);
        $stored = trim($c->classdays ?? '');
        $storedAllZero = ($stored === '' || $stored === '0/0/0/0/0/0/0');
        $derivedAllZero = ($derived === '0/0/0/0/0/0/0');
        $match = ($stored === $derived) || ($storedAllZero && $derivedAllZero);
        
        if (!$match) $mismatchCount++;
        if ($storedAllZero && !$derivedAllZero) $allZeroCount++;
        
        $statusBadge = $match 
            ? '<span class="dbg-badge dbg-badge-ok">✓ OK</span>' 
            : '<span class="dbg-badge dbg-badge-bad">✗ MISMATCH</span>';
        
        if ($storedAllZero && $derivedAllZero) {
            $statusBadge = '<span class="dbg-badge dbg-badge-warn">⚠ Unscheduled</span>';
        }

        echo '<tr>';
        echo '<td>' . $c->id . '</td>';
        echo '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;">' . htmlspecialchars($c->name) . '</td>';
        echo '<td>' . render_days_visual($stored) . '</td>';
        echo '<td>' . render_days_visual($derived) . '</td>';
        echo '<td>' . $statusBadge . '</td>';
        echo '<td><a href="?class_id=' . $c->id . '" style="color:#3b82f6;font-weight:700;font-size:12px;">Detail</a></td>';
        echo '</tr>';
    }
    echo '</table>';
    
    echo '<div style="margin-top:12px; font-size:13px; color:#64748b;">';
    echo 'Summary: <strong>' . $mismatchCount . '</strong> mismatches, <strong>' . $allZeroCount . '</strong> classes with zeros in gmk_class but scheduled in gmk_class_schedules.';
    echo '</div>';
    echo '</div>';
    
    // Offer a fix button
    if ($allZeroCount > 0) {
        $doFix = optional_param('fix_sync', 0, PARAM_INT);
        if ($doFix === 1) {
            // Apply the fix: derive classdays from gmk_class_schedules for all affected classes
            $fixed = 0;
            foreach ($classes as $c) {
                $schedules = $DB->get_records('gmk_class_schedules', ['classid' => $c->id]);
                $derived = derive_classdays_from_schedules($schedules);
                $stored = trim($c->classdays ?? '');
                $storedAllZero = ($stored === '' || $stored === '0/0/0/0/0/0/0');
                $derivedAllZero = ($derived === '0/0/0/0/0/0/0');
                
                if ($storedAllZero && !$derivedAllZero) {
                    $DB->set_field('gmk_class', 'classdays', $derived, ['id' => $c->id]);
                    gmk_log("DEBUG debug_classdays.php: Fixed classdays for class {$c->id} ({$c->name}): '' -> '$derived'");
                    $fixed++;
                }
            }
            echo '<div class="dbg-highlight" style="border-left-color:#16a34a;">✅ <strong>Fixed ' . $fixed . ' classes!</strong> Their <code>classdays</code> field has been updated to match <code>gmk_class_schedules</code>. <a href="?period_id=' . $periodId . '">Reload to verify</a>.</div>';
        } else {
            echo '<div class="dbg-highlight">';
            echo '🔧 <strong>' . $allZeroCount . ' classes</strong> have <code>classdays = 0/0/0/0/0/0/0</code> but have schedule records. ';
            echo '<a href="?period_id=' . $periodId . '&fix_sync=1" style="color:#dc2626;font-weight:700;" onclick="return confirm(\'¿Sincronizar classdays desde gmk_class_schedules para ' . $allZeroCount . ' clases?\');">🔧 Fix: Sync classdays from schedules</a>';
            echo '</div>';
        }
    }
}

// ---- Show recent logs ----
$logfile = __DIR__ . '/../gmk_debug.log';
if (file_exists($logfile)) {
    echo '<div class="dbg-card">';
    echo '<div class="dbg-title">📝 Recent gmk_debug.log (last 20 lines)</div>';
    $lines = file($logfile);
    $last20 = array_slice($lines, -20);
    echo '<div class="dbg-log">';
    foreach ($last20 as $line) {
        echo '<div class="log-line">' . htmlspecialchars(trim($line)) . '</div>';
    }
    echo '</div>';
    echo '</div>';
} else {
    echo '<div class="dbg-card"><div class="dbg-title">📝 gmk_debug.log</div><p style="color:#94a3b8;">Log file not found at: ' . htmlspecialchars($logfile) . '</p></div>';
}

echo $OUTPUT->footer();
