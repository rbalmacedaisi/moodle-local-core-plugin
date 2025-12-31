<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;

class get_dashboard_data extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'The ID of the teacher', VALUE_REQUIRED)
            )
        );
    }

    public static function execute($userid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), array('userid' => $userid));
        
        $context = \context_system::instance();
        self::validate_context($context);

        // 1. Get Active Classes
        $now = time();
        $sql = "SELECT c.* 
                FROM {gmk_class} c
                WHERE c.instructorid = :instructorid 
                  AND c.closed = 0 
                  AND c.enddate >= :now
                  AND EXISTS (
                      SELECT 1 FROM {gmk_bbb_attendance_relation} r 
                      WHERE r.classid = c.id
                  )";
        $classes = $DB->get_records_sql($sql, ['instructorid' => $params['userid'], 'now' => $now]);
        
        $active_classes = [];
        foreach ($classes as $class) {
            $course = $DB->get_record('course', ['id' => $class->courseid], 'id,fullname,shortname');
            $class_data = new stdClass();
            $class_data->id = $class->id;
            $class_data->name = $class->name; // Specific class name
            $class_data->courseid = $class->courseid;
            $class_data->course_fullname = $course ? $course->fullname : '';
            $class_data->course_shortname = $course ? $course->shortname : '';
            
            // Map type: 0 = PRESENCIAL, 1 = VIRTUAL, 2 = MIXTA (based on locallib.php)
            $class_data->type = (int)$class->type;
            $class_data->typelabel = !empty($class->typelabel) ? $class->typelabel : ($class->type == 1 ? 'VIRTUAL' : 'PRESENCIAL');
            
            $class_data->next_session = self::get_next_session($class->id);
            
            // New fields for card
            // Count students assigned to this class in gmk_course_progre
            $class_data->student_count = $DB->count_records('gmk_course_progre', ['classid' => $class->id]);
            $class_data->initdate = $class->initdate;
            $class_data->enddate = $class->enddate;
            
            // Format schedule (L/M/X/J/V/S/D)
            $day_labels = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
            $days_raw = explode('/', $class->classdays);
            $active_days = [];
            foreach ($days_raw as $index => $active) {
                if ($active == '1' && isset($day_labels[$index])) {
                    $active_days[] = $day_labels[$index];
                }
            }
            $class_data->schedule_text = implode('/', $active_days) ?: 'S/D';
            
            $active_classes[] = $class_data;
        }

        // 2. Get Calendar Events (next 30 days) using established logic
        $init_date_str = date('Y-m-d', $now);
        $end_date_str = date('Y-m-d', $now + (30 * 24 * 60 * 60));
        
        $events = get_class_events($params['userid'], $init_date_str, $end_date_str);
        
        $calendar_events = [];
        foreach ($events as $event) {
            $e = new stdClass();
            $e->id = $event->id;
            $e->name = $event->name;
            $e->timestart = $event->timestart;
            $e->timeduration = isset($event->timeduration) ? $event->timeduration : 3600;
            $e->courseid = $event->courseid;
            $calendar_events[] = $e;
        }

        // 3. Pending Tasks (Count submissions to grade)
        $pending_tasks = [];
        foreach ($active_classes as $class) {
            $assigns = $DB->get_records('assign', ['course' => $class->courseid]);
            $count = 0;
            foreach ($assigns as $assign) {
                $count += $DB->count_records_sql("SELECT COUNT(s.id) FROM {assign_submission} s 
                    JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                    WHERE s.assignment = :assignid AND s.status = 'submitted' AND g.grade IS NULL", 
                    ['assignid' => $assign->id]);
            }
            $task = new stdClass();
            $task->classid = $class->id;
            $task->count = $count;
            $pending_tasks[] = $task;
        }

        // 4. Health Status (Simplified logic for now)
        $health_status = [];
        foreach ($active_classes as $class) {
            $status = new stdClass();
            $status->classid = $class->id;
            $status->level = 'green'; // Default
            
            // Check for low attendance students (dummy logic for spec)
            $low_attendance = $DB->count_records_select('gmk_course_progre', 'classid = :classid AND progress < 70', ['classid' => $class->id]);
            if ($low_attendance > 0) {
                $status->level = 'yellow';
            }
            if ($low_attendance > 5) {
                $status->level = 'red';
            }
            $health_status[] = $status;
        }

        return array(
            'active_classes' => $active_classes,
            'calendar_events' => $calendar_events,
            'pending_tasks' => $pending_tasks,
            'health_status' => $health_status
        );
    }

    private static function get_next_session($classid) {
        global $DB;
        $now = time();
        
        // Query next session from Moodle events linked to this class
        $sql = "SELECT e.timestart 
                FROM {event} e
                JOIN {attendance_sessions} asess ON asess.caleventid = e.id
                JOIN {gmk_bbb_attendance_relation} rel ON rel.attendancesessionid = asess.id
                WHERE rel.classid = :classid AND e.timestart >= :now
                ORDER BY e.timestart ASC";
        
        $session = $DB->get_record_sql($sql, ['classid' => $classid, 'now' => $now], IGNORE_MULTIPLE);
        return $session ? (int)$session->timestart : null;
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'active_classes' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'Class ID'),
                            'name' => new external_value(PARAM_TEXT, 'Class Name'),
                            'course_fullname' => new external_value(PARAM_TEXT, 'Course Fullname'),
                            'course_shortname' => new external_value(PARAM_TEXT, 'Course Shortname'),
                            'type' => new external_value(PARAM_INT, 'Type (0: inplace, 1: virtual)'),
                            'typelabel' => new external_value(PARAM_TEXT, 'Type Label', VALUE_OPTIONAL),
                            'next_session' => new external_value(PARAM_TEXT, 'Timestamp of next session', VALUE_OPTIONAL),
                            'student_count' => new external_value(PARAM_INT, 'Student count', VALUE_OPTIONAL),
                            'initdate' => new external_value(PARAM_INT, 'Start date', VALUE_OPTIONAL),
                            'enddate' => new external_value(PARAM_INT, 'End date', VALUE_OPTIONAL),
                            'schedule_text' => new external_value(PARAM_TEXT, 'Formatted schedule', VALUE_OPTIONAL)
                        )
                    )
                ),
                'calendar_events' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'Event ID'),
                            'name' => new external_value(PARAM_TEXT, 'Event Name'),
                            'timestart' => new external_value(PARAM_INT, 'Start time'),
                            'courseid' => new external_value(PARAM_INT, 'Course ID')
                        )
                    )
                ),
                'pending_tasks' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'classid' => new external_value(PARAM_INT, 'Class ID'),
                            'count' => new external_value(PARAM_INT, 'Count of pending tasks')
                        )
                    )
                ),
                'health_status' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'classid' => new external_value(PARAM_INT, 'Class ID'),
                            'level' => new external_value(PARAM_TEXT, 'Status level (red, yellow, green)')
                        )
                    )
                )
            )
        );
    }
}
