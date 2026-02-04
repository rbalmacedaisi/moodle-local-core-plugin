<?php
/**
 * Simple Log Viewer for GMK Debugging
 */
require_once(__DIR__ . '/../../../config.php');
require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_log_viewer.php'));
$PAGE->set_title('GMK Debug Log');
$PAGE->set_heading('GMK Debug Log Viewer');

echo $OUTPUT->header();

$logfile = __DIR__ . '/../gmk_debug.log';

if (optional_param('clear', 0, PARAM_INT)) {
    file_put_contents($logfile, "--- Log Cleared " . date('Y-m-d H:i:s') . " ---\n");
    redirect(new moodle_url('/local/grupomakro_core/pages/debug_log_viewer.php'));
}

echo '<div class="mb-3">
        <a href="debug_log_viewer.php" class="btn btn-primary">Refresh</a>
        <a href="debug_log_viewer.php?clear=1" class="btn btn-danger">Clear Log</a>
      </div>';

if (file_exists($logfile)) {
    $content = file_get_contents($logfile);
    echo '<pre style="background: #f5f5f5; border: 1px solid #ccc; padding: 10px; max-height: 600px; overflow-y: scroll;">';
    echo htmlspecialchars($content);
    echo '</pre>';
} else {
    echo '<div class="alert alert-info">Log file does not exist yet. Perform an action to generate logs.</div>';
}

echo $OUTPUT->footer();
