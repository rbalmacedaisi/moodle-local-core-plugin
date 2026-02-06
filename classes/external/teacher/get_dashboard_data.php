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
            $course = $DB->get_record('course', ['id' => $class->courseid], 'id,fullname,shortname,idnumber');
            $class_data = new stdClass();
            $class_data->id = $class->id;
            $class_data->name = $class->name; // Specific class name
            $class_data->courseid = $class->courseid;
            $class_data->course_fullname = $course ? $course->fullname : '';
            $class_data->course_shortname = $course ? $course->idnumber : '';
            
            // Map type: 0 = PRESENCIAL, 1 = VIRTUAL, 2 = MIXTA (based on locallib.php)
            $class_data->type = (int)$class->type;
            $class_data->typelabel = !empty($class->typelabel) ? $class->typelabel : ($class->type == 1 ? 'VIRTUAL' : 'PRESENCIAL');
            
            $class_data->next_session = self::get_next_session($class->id);
            
            // New fields for card
            // Count only REAL students (those in local_learning_users)
            // This avoids counting teachers or administrative accounts in the group
            $sql_count = "SELECT COUNT(DISTINCT gm.userid)
                          FROM {groups_members} gm
                          JOIN {local_learning_users} llu ON llu.userid = gm.userid
                          WHERE gm.groupid = :groupid AND gm.userid != :instructorid";
            $class_data->student_count = $DB->count_records_sql($sql_count, ['groupid' => $class->groupid, 'instructorid' => $class->instructorid]);
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

        // Create Course -> Class ID Map
        $courseToClassId = [];
        foreach ($active_classes as $cls) {
            $courseToClassId[$cls->courseid] = $cls->id;
        }

        // 2. Get Calendar Events (Dynamic range based on active classes)
        // Default to -1 month to +2 months if no classes, otherwise use class limits
        $min_start = $now - (30 * 24 * 60 * 60); 
        $max_end = $now + (60 * 24 * 60 * 60);

        if (!empty($active_classes)) {
            $start_dates = [];
            $end_dates = [];
            foreach ($active_classes as $c) {
                if (!empty($c->initdate)) $start_dates[] = $c->initdate;
                if (!empty($c->enddate)) $end_dates[] = $c->enddate;
            }
            
            if (!empty($start_dates)) {
                // Start from the earliest class start date
                $min_start = min($start_dates);
                // Optional: Add a small buffer backwards if needed, but strict class start is usually fine.
                // However, if we want to show context, maybe 1 week before? 
                // Let's stick to class start date to ensure all sessions are seen.
            }
            
            if (!empty($end_dates)) {
                $max_end = max($end_dates);
            }
        }

        $init_date_str = date('Y-m-d', $min_start);
        $end_date_str = date('Y-m-d', $max_end);
        
        $events = get_class_events($params['userid'], $init_date_str, $end_date_str);
        
        // Build maps for Course Info and Group Info
        $event_course_ids = [];
        $event_group_ids = [];

        foreach ($events as $event) {
            $event_course_ids[] = $event->courseid;
            if (!empty($event->groupid)) {
                $event_group_ids[] = $event->groupid;
            }
        }
        $event_course_ids = array_unique($event_course_ids);
        $event_group_ids = array_unique($event_group_ids);
        
        $course_info_map = [];
        if (!empty($event_course_ids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($event_course_ids);
            // Query COURSE table for fullname and shortname
            $sql_map = "SELECT id, fullname, shortname FROM {course} WHERE id $insql";
            $mapped_courses = $DB->get_records_sql($sql_map, $inparams);
            foreach ($mapped_courses as $row) {
                $course_info_map[$row->id] = $row;
            }
        }

        $group_name_map = [];
        if (!empty($event_group_ids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($event_group_ids);
            $sql_map = "SELECT id, name FROM {groups} WHERE id $insql";
            $mapped_groups = $DB->get_records_sql($sql_map, $inparams);
            foreach ($mapped_groups as $row) {
                $group_name_map[$row->id] = $row->name;
            }
        }

        $calendar_events = [];
        foreach ($events as $event) {
            $e = new stdClass();
            $e->id = $event->id;
            $e->name = $event->name;
            $e->timestart = $event->timestart;
            $e->timeduration = isset($event->timeduration) ? (int)$event->timeduration : 0;
            if ($e->timeduration < 0) $e->timeduration = 0;
            
            $e->courseid = $event->courseid;
            
            // Map to class ID: Prefer existing enriched data, fallback to map
            $e->classid = !empty($event->classId) ? $event->classId : (isset($courseToClassId[$event->courseid]) ? $courseToClassId[$event->courseid] : 0);
            
            // Determine best label (Coursename/Classname)
            if (!empty($event->className)) {
                $label = $event->className;
            } else {
                // Fallback logic
                $label = $event->name;
                if (!empty($event->groupid) && isset($group_name_map[$event->groupid])) {
                    $label = $group_name_map[$event->groupid];
                } elseif (isset($course_info_map[$event->courseid])) {
                    $label = $course_info_map[$event->courseid]->shortname ?: $course_info_map[$event->courseid]->fullname;
                }
            }
 
            $e->classname = $label;
            
            // Pass metadata for frontend
            if (isset($event->bigBlueButtonActivityUrl)) {
                 $e->activityUrl = $event->bigBlueButtonActivityUrl;
            } 
            if (isset($event->color)) {
                 $e->color = $event->color;
            }
            if (!empty($event->is_grading_task)) {
                 $e->is_grading_task = true;
            }

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
                            'courseid' => new external_value(PARAM_INT, 'Course ID'),
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
                            'timeduration' => new external_value(PARAM_INT, 'Duration in seconds', VALUE_OPTIONAL),
                            'courseid' => new external_value(PARAM_INT, 'Course ID'),
                            'classid' => new external_value(PARAM_INT, 'Class ID', VALUE_OPTIONAL),
                            'classname' => new external_value(PARAM_TEXT, 'Class Name from DB', VALUE_OPTIONAL),
                            'is_grading_task' => new external_value(PARAM_BOOL, 'Is a grading task', VALUE_OPTIONAL),
                            'color' => new external_value(PARAM_TEXT, 'Event Color', VALUE_OPTIONAL)
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
