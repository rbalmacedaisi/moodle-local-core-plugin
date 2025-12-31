<?php
require_once(__DIR__ . '/../../../config.php');
require_login();

$logPath = $CFG->dirroot . '/local/grupomakro_core/timeline_ajax_debug.log';

echo "<html><head><title>Log Viewer</title></head><body style='font-family: monospace;'>";
echo "<h1>Log Viewer</h1>";
echo "<p><strong>File:</strong> $logPath</p>";

if (file_exists($logPath)) {
    $content = file_get_contents($logPath);
    echo "<div style='background: #f4f4f4; border: 1px solid #ccc; padding: 10px; border-radius: 5px;'>";
    echo nl2br(htmlspecialchars($content));
    echo "</div>";
    
    echo "<br/><form method='post'>";
    echo "<input type='hidden' name='action' value='clear'>";
    echo "<button type='submit' style='padding: 10px; background: #e53935; color: white; border: none; cursor: pointer;'>Clear Log</button>";
    echo "</form>";

    if (optional_param('action', '', PARAM_ALPHA) === 'clear') {
        file_put_contents($logPath, ''); // Empty file
        redirect('view_debug_log.php');
    }
} else {
    echo "<div style='color: red; padding: 10px; border: 1px solid red;'>Log file not found. Make sure you refreshed the Dashboard page.</div>";
}
echo "</body></html>";
