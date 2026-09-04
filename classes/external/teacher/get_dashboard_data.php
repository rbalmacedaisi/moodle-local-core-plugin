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

        // Defence-in-depth: a teacher can only load their own dashboard.
        // Siteadmins can load any user's dashboard for support purposes.
        if ($USER->id != $params['userid'] && !is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view another user\'s dashboard');
        }

        // Cache the assembled dashboard payload (active_classes + pending_tasks +
        // health_status). The calendar events intentionally live elsewhere — see
        // local_grupomakro_calendar_get_calendar_events — so this cache stays
        // compact and the dashboard renders from cache on warm hits in <50ms
        // instead of recomputing everything (which previously took 15-20s for
        // teachers with many classes).
        $cache = \cache::make('local_grupomakro_core', 'teacher_dashboard');
        // The 'w2' marker is part of the key so a deploy that changes the payload
        // shape (here: the gradebook-weights fields) never serves a stale cached
        // array that lacks them.
        $cachekey = 'uid_' . (int)$params['userid'] . '_w2_d_' . date('Ymd');
        $cached = $cache->get($cachekey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // 1. Get Active Classes. A "teacher" here is BOTH the main instructor
        // (c.instructorid) AND the support teacher (c.supportinstructorid) — both
        // get the same dashboard so the support teacher can grade, mark attendance
        // and see events for the classes they share with the main instructor.
        // Two distinct placeholders (:instructorid, :instructorid2) because Moodle
        // counts named placeholders literally.
        $now = time();
        $is_admin = is_siteadmin($params['userid']);

        $where_instructor = $is_admin
            ? ''
            : ' AND (c.instructorid = :instructorid OR c.supportinstructorid = :instructorid2)';
        $sql = "SELECT c.*
                FROM {gmk_class} c
                WHERE c.closed = 0
                  $where_instructor";

        $query_params = [];
        if (!$is_admin) {
            $query_params['instructorid']  = (int)$params['userid'];
            $query_params['instructorid2'] = (int)$params['userid'];
        }

        $classes = $DB->get_records_sql($sql, $query_params);
        
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
            
            // Keep student_count consistent with Schedule Approval and class participants logic:
            // - If class has groupid, count group members (excluding instructor).
            // - If class is approved and has no groupid, count from gmk_course_progre.
            $classparticipants = get_class_participants($class);
            $class_data->student_count = count((array)$classparticipants->enroledStudents);
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

            // Gradebook weights: surfaced so the dashboard can warn the teacher
            // when the class category doesn't total 100%. Only reported once the
            // class is past its grace period (week 3) — before that an
            // in-progress gradebook is expected, not a problem.
            $weights = \local_grupomakro_core\local\revalida_manager::get_weights_status((int)$class->id);
            $class_data->weights_pct = (float)$weights['pct'];
            $class_data->weights_ok = !empty($weights['ok']);
            $class_data->weights_applicable = !empty($weights['applicable']);
            $class_data->weights_unweighted_items = (int)$weights['unweighted'];
            $class_data->weights_warning = \local_grupomakro_core\local\revalida_manager::weights_warning_due($class, $now);
            $class_data->gradebook_url = \local_grupomakro_core\local\revalida_manager::gradebook_setup_url($class);

            $active_classes[] = $class_data;
        }

// Calendar events are NOT loaded here. They are fetched lazily by the
        // frontend via local_grupomakro_calendar_get_calendar_events when the
        // teacher opens the "Ver Calendario Completo" dialog. Loading them
        // here takes ~5s+ per teacher because of get_class_events() and the
        // per-event gmk_complete_class_event_information_fast() enrichment,
        // and they're only used inside the modal — not on the dashboard.

        // 3. Pending Tasks (Count submissions to grade)
        $pending_tasks = [];
        foreach ($active_classes as $class) {
            $tasks = gmk_get_pending_grading_items($params['userid'], $class->id);
            $task = new stdClass();
            $task->classid = $class->id;
            $task->count = count($tasks);
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

        $payload = array(
            'active_classes' => $active_classes,
            'pending_tasks' => $pending_tasks,
            'health_status' => $health_status
        );
        $cache->set($cachekey, $payload);
        return $payload;
    }

    private static function get_next_session($classid) {
        global $DB;
        $now = time();

        // Primary path: attendance_sessions linked via gmk_bbb_attendance_relation.
        // Most reliable because it doesn't depend on the Moodle calendar {event} row
        // being present (caleventid can be 0 right after a session is created).
        $sql = "SELECT asess.sessdate
                FROM {attendance_sessions} asess
                JOIN {gmk_bbb_attendance_relation} rel ON rel.attendancesessionid = asess.id
                WHERE rel.classid = :classid AND asess.sessdate >= :now
                ORDER BY asess.sessdate ASC";

        $session = $DB->get_record_sql($sql, ['classid' => $classid, 'now' => $now], IGNORE_MULTIPLE);
        if ($session) {
            return (int)$session->sessdate;
        }

        // Fallback path: Moodle's calendar {event} table. Used when the primary path
        // returns nothing because the existing relations all point to past (deleted)
        // attendance_sessions, but new calendar events still exist for this class.
        // Without this fallback, classes would render 'Sin fecha programada' on the
        // dashboard card and would be excluded from the 'Próximas Sesiones' panel
        // even though the events clearly show on the calendar dialog.
        $class = $DB->get_record('gmk_class', ['id' => $classid], 'courseid,corecourseid');
        if ($class) {
            $courseids = array_values(array_filter([
                (int)$class->courseid,
                (int)$class->corecourseid,
            ]));
            if (!empty($courseids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cbcid');
                $params = array_merge($inparams, ['now' => $now]);
                $event = $DB->get_record_sql(
                    "SELECT e.timestart
                       FROM {event} e
                      WHERE e.modulename IN ('attendance', 'bigbluebuttonbn')
                        AND e.courseid $insql
                        AND e.timestart >= :now
                   ORDER BY e.timestart ASC",
                    $params,
                    IGNORE_MULTIPLE
                );
                if ($event) {
                    return (int)$event->timestart;
                }
            }
        }

        return null;
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
                            'schedule_text' => new external_value(PARAM_TEXT, 'Formatted schedule', VALUE_OPTIONAL),
                            'weights_pct' => new external_value(PARAM_FLOAT, 'Sum of gradebook item weights (%)', VALUE_OPTIONAL),
                            'weights_ok' => new external_value(PARAM_BOOL, 'True when weights total 100%', VALUE_OPTIONAL),
                            'weights_applicable' => new external_value(PARAM_BOOL, 'False when the category is not weight-aggregated', VALUE_OPTIONAL),
                            'weights_unweighted_items' => new external_value(PARAM_INT, 'Items with weight 0', VALUE_OPTIONAL),
                            'weights_warning' => new external_value(PARAM_BOOL, 'True when weights are incomplete past the grace period', VALUE_OPTIONAL),
                            'gradebook_url' => new external_value(PARAM_RAW, 'Deep link to the gradebook setup screen', VALUE_OPTIONAL)
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
