<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_persistence.php'));
$PAGE->set_title('Diagnostic: Draft Persistence');
$PAGE->set_heading('Diagnostic: Draft Persistence');

echo $OUTPUT->header();

$periodid = optional_param('periodid', 0, PARAM_INT);
if (!$periodid) {
    // Try to get first available period
    $first = $DB->get_record('gmk_academic_periods', [], 'id ASC', 'id, name');
    if ($first) $periodid = $first->id;
}

// 1. Environment Info
echo "<h3>1. Environment Info</h3>";
echo "<ul>";
echo "<li><b>post_max_size:</b> " . ini_get('post_max_size') . "</li>";
echo "<li><b>upload_max_filesize:</b> " . ini_get('upload_max_filesize') . "</li>";
echo "<li><b>memory_limit:</b> " . ini_get('memory_limit') . "</li>";
echo "<li><b>max_execution_time:</b> " . ini_get('max_execution_time') . "</li>";
echo "<li><b>max_input_vars:</b> " . ini_get('max_input_vars') . "</li>";
echo "</ul>";

// 2. Database Schema Check
echo "<h3>2. Database Column Check</h3>";
$columns = $DB->get_columns('gmk_academic_periods');
if (isset($columns['draft_schedules'])) {
    $col = $columns['draft_schedules'];
    echo "<ul>";
    echo "<li><b>Name:</b> draft_schedules</li>";
    echo "<li><b>Type:</b> " . $col->type . "</li>";
    echo "<li><b>Max Length:</b> " . ($col->max_length > 0 ? $col->max_length : 'Unlimited/Long') . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color:red;'>Column 'draft_schedules' NOT FOUND in gmk_academic_periods!</p>";
}

// 3. Manual Write Test
echo "<h3>3. Manual Write Test</h3>";
if ($periodid) {
    echo "<p>Testing period: <b>" . $DB->get_field('gmk_academic_periods', 'name', ['id' => $periodid]) . " (ID: $periodid)</b></p>";
    
    $testAction = optional_param('test_write', '', PARAM_ALPHA);
    if ($testAction === 'yes') {
        $testData = "DIAGNOSTIC_TEST_" . time() . "_" . str_repeat("X", 50000); // 50KB test
        $DB->set_field('gmk_academic_periods', 'draft_schedules', $testData, ['id' => $periodid]);
        echo "<div style='padding:10px; background:#d4edda; border:1px solid #c3e6cb; color:#155724;'>Write successful! Wrote 50KB.</div>";
    }

    $currentLen = $DB->get_field_sql("SELECT LENGTH(draft_schedules) FROM {gmk_academic_periods} WHERE id = ?", [$periodid]);
    echo "<p>Current Content Length in DB: <b>" . ($currentLen ?: 0) . " characters</b></p>";
    
    echo "<form method='post'>";
    echo "<input type='hidden' name='test_write' value='yes'>";
    echo "<input type='hidden' name='periodid' value='$periodid'>";
    echo "<button type='submit' style='padding:10px; background:#007bff; color:white; border:none; cursor:pointer;'>Run Manual 50KB Write Test</button>";
    echo "</form>";
}

// 4. AJAX Simulation / Diagnostic
echo "<h3>4. Interactive AJAX Diagnostic</h3>";
echo "
<div style='background:#f8f9fa; padding:15px; border:1px solid #dee2e6; border-radius:5px;'>
    <p>This button will send a 200KB payload to <b>ajax.php</b> using the <code>local_grupomakro_save_draft</code> action.</p>
    <button id='ajaxTestBtn' style='padding:10px; background:#28a745; color:white; border:none; cursor:pointer;'>Trigger AJAX Test (200KB)</button>
    <div id='ajaxResults' style='margin-top:15px; font-family:monospace; white-space:pre-wrap; background:white; padding:10px; border:1px solid #ccc; min-height:50px;'>Results will appear here...</div>
</div>

<script>
document.getElementById('ajaxTestBtn').onclick = async function() {
    const results = document.getElementById('ajaxResults');
    results.innerHTML = 'Sending...';
    
    const payload = 'A'.repeat(200000); // 200KB
    const body = {
        action: 'local_grupomakro_save_draft',
        periodid: $periodid,
        schedules: JSON.stringify({test: payload}),
        sesskey: M.cfg.sesskey
    };

    try {
        const response = await fetch(window.location.origin + '/local/grupomakro_core/ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const text = await response.text();
        results.innerHTML = 'HTTP Status: ' + response.status + '\\n\\n' + text;
    } catch (e) {
        results.innerHTML = 'Error: ' + e.message;
    }
};
</script>
";

echo $OUTPUT->footer();
