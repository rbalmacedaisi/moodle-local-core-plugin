// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../config.php';
}
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../../config.php';
}
require_once($config_path);

// Safe require for attendance
$att_lib = $CFG->dirroot . '/mod/attendance/lib.php';
$att_locallib = $CFG->dirroot . '/mod/attendance/locallib.php';

if (file_exists($att_lib)) require_once($att_lib);
if (file_exists($att_locallib)) require_once($att_locallib);

// Security Check - Allow if Logged In (Debug only)
require_login();
$context = context_system::instance();
// Removed explicit capability check to allow instructors to see this debug page
// require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_attendance_api.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Attendance API');
$PAGE->set_heading('Debug Attendance API');

echo $OUTPUT->header();

echo '<h2>Classes in mod_attendance</h2>';
$classes = get_declared_classes();
$att_classes = array_filter($classes, function($c) { return strpos($c, 'attendance') !== false; });
echo '<pre>' . implode("\n", $att_classes) . '</pre>';

echo '<h2>Functions containing "attendance"</h2>';
$funcs = get_defined_functions()['user'];
$att_funcs = array_filter($funcs, function($f) { return strpos($f, 'attendance') !== false; });
echo '<pre>' . implode("\n", $att_funcs) . '</pre>';

// Inspect mod_attendance_structure if exists
if (class_exists('mod_attendance_structure')) {
    echo '<h2>Methods of mod_attendance_structure</h2>';
    $methods = get_class_methods('mod_attendance_structure');
    echo '<pre>' . implode("\n", $methods) . '</pre>';
}

echo $OUTPUT->footer();
