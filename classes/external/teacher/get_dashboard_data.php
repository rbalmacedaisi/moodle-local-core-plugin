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
        $classes = $DB->get_records('gmk_class', ['instructorid' => $params['userid'], 'closed' => 0]);
        $active_classes = [];
        foreach ($classes as $class) {
            $course = $DB->get_record('course', ['id' => $class->courseid], 'id,fullname,shortname');
            $class_data = new stdClass();
            $class_data->id = $class->id;
            $class_data->name = $class->name;
            $class_data->courseid = $class->courseid;
            $class_data->course_fullname = $course ? $course->fullname : '';
            $class_data->course_shortname = $course ? $course->shortname : '';
            $class_data->type = $class->type; // 0: inplace, 1: virtual
            $class_data->next_session = self::get_next_session($class->id);
            $active_classes[] = $class_data;
        }

        // 2. Get Calendar Events (next 30 days)
        $now = time();
        $end = $now + (30 * 24 * 60 * 60);
        $events = $DB->get_records_select('event', 'userid = :userid AND timestart >= :start AND timestart <= :end', 
            ['userid' => $params['userid'], 'start' => $now, 'end' => $end]);
        
        $calendar_events = [];
        foreach ($events as $event) {
            $e = new stdClass();
            $e->id = $event->id;
            $e->name = $event->name;
            $e->timestart = $event->timestart;
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
        $session = $DB->get_record_sql("SELECT * FROM {gmk_class_session} 
            WHERE classid = :classid AND startdate >= :now ORDER BY startdate ASC", 
            ['classid' => $classid, 'now' => (int)$now], IGNORE_MULTIPLE);
        return $session ? (int)$session->startdate : null;
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
                            'next_session' => new external_value(PARAM_TEXT, 'Timestamp of next session', VALUE_OPTIONAL)
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
