<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_ddwtos_code.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug DDWTOS Code');
echo $OUTPUT->header();

echo "<h3>Locating DDWTOS Question Type Class</h3>";

try {
    $qtype = question_bank::get_qtype('ddwtos');
    $reflector = new ReflectionClass($qtype);
    $filename = $reflector->getFileName();
    
    echo "<p><strong>Class:</strong> " . get_class($qtype) . "</p>";
    echo "<p><strong>File:</strong> " . $filename . "</p>";
    
    if (file_exists($filename)) {
        echo "<h4>Source Code (Header):</h4>";
        // limit to first 500 lines or just read relevant methods
        $content = file_get_contents($filename);
        echo "<textarea style='width:100%; height:800px; font_family:monospace;'>" . htmlspecialchars($content) . "</textarea>";
    } else {
        echo "<div class='alert alert-danger'>File not found!</div>";
    }

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo $OUTPUT->footer();
