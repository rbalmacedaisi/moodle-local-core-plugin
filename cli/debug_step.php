<?php
// Debug: add lines one at a time to find where it hangs
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

echo "1. Loaded config\n";

date_default_timezone_set('America/Panama');
echo "2. TZ set\n";

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
echo "3. Loaded locallib\n";

echo "4. Before cron_setup_user\n";
cron_setup_user();
echo "5. After cron_setup_user\n";

global $DB, $USER;
echo "6. USER=" . ($USER ? $USER->id : 'null') . "\n";

$class = $DB->get_record('gmk_class', ['id' => 9618], '*', MUST_EXIST);
echo "7. Got class: {$class->name}\n";

$class->course = get_course($class->corecourseid);
echo "8. Got course\n";

$attendanceCm = get_coursemodule_from_id('attendance', $class->attendancemoduleid, 0, false, MUST_EXIST);
echo "9. Got attendance cm\n";

$attendanceRecord = $DB->get_record('attendance', ['id' => $attendanceCm->instance], '*', MUST_EXIST);
echo "10. Got attendance record\n";

$BBBmoduleId = $DB->get_field('modules', 'id', ['name' => 'bigbluebuttonbn']);
echo "11. BBBmoduleId=$BBBmoduleId\n";

$schedules = $DB->get_records('gmk_class_schedules', ['classid' => 9618]);
echo "12. Got " . count($schedules) . " schedules\n";

echo "13. END DEBUG\n";