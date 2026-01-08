<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');
require_once($CFG->dirroot . '/mod/attendance/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;
use context_course;
use mod_attendance_structure;

class attendance_manager extends external_api {

    /**
     * Get attendance sessions for the current class (Group)
     */
    public static function get_sessions($classid) {
        global $DB; // $USER not needed yet
        
        // 1. Get Course and Group ID from Class
        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        
        // 2. Find the Attendance Activity in this Course
        // Assuming one main attendance instance per course, or we filter by visible?
        $att = $DB->get_record('attendance', ['course' => $class->courseid], 'id, name, grade', IGNORE_MULTIPLE);
        
        if (!$att) {
            return ['status' => 'error', 'message' => 'No se encontró una actividad de asistencia en este curso.'];
        }

        $cm = get_coursemodule_from_instance('attendance', $att->id, $class->courseid);
        
        // Init Structure
        $att_structure = new mod_attendance_structure($att, $cm, $class->courseid, \context_module::instance($cm->id));

        // 3. Get Sessions for "Today" (or recent) filtered by Group
        // mod_attendance usually filters by group mode.
        // We want sessions specifically for this $class class (Group).
        
        // Get all sessions for this group?
        $today = time();
        $start_date = strtotime('today', $today);
        $end_date = strtotime('tomorrow', $today) - 1;

        // get_sessions($startdate, $enddate, $users) - Note: method signature varies.
        // Let's use get_filtered_sessions or direct DB query if needed to be safe.
        // Debug output showed: get_filtered_sessions
        
        // Using direct SQL for precision given we want specific Group
        $sql = "SELECT s.* 
                FROM {attendance_sessions} s
                WHERE s.attendanceid = :attid
                  AND s.groupid = :groupid
                  AND s.sessdate >= :start
                  AND s.sessdate <= :end
                ORDER BY s.sessdate ASC";
        
        $sessions = $DB->get_records_sql($sql, [
            'attid' => $att->id,
            'groupid' => $class->groupid,
            'start' => $start_date, // Today
            'end' => $end_date + (7 * 24 * 3600) // Show Next 7 days? Or just today? Let's show Today + Future
        ]);
        
        // Format for frontend
        $result = [];
        foreach ($sessions as $s) {
            $item = new stdClass();
            $item->id = $s->id;
            $item->date = userdate($s->sessdate, get_string('strftimedatefullshort', 'langconfig'));
            $item->time = userdate($s->sessdate, '%H:%M') . ' - ' . userdate($s->sessdate + $s->duration, '%H:%M');
            $item->description = $s->description;
            $item->state = ($s->sessdate < time()) ? 'Pasada' : 'Futura';
            
            // Check if passwords exist (for QR)
            $item->has_qr = !empty($s->includeqrcode);
            
            $result[] = $item;
        }

        return ['status' => 'success', 'sessions' => $result, 'attendance_id' => $att->id, 'instance_name' => $att->name];
    }

    /**
     * Generate/Render QR for a session
     */
    public static function get_qr($sessionid) {
        global $DB, $CFG;
        
        $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $att = $DB->get_record('attendance', ['id' => $session->attendanceid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('attendance', $att->id, $att->course);
        
        // Passwords logic
        // If rotate is on, we might need a specific password.
        // Calling available functions from debug list
        if (function_exists('attendance_generate_passwords')) {
           $password = attendance_generate_passwords($session);
        } else {
           $password = $session->studentpassword; // Fallback
        }

        // Capture Output
        ob_start();
        if (function_exists('attendance_renderqrcode')) {
            attendance_renderqrcode($session, $password);
        } else {
            echo "Error: Función QR no encontrada.";
        }
        $html = ob_get_clean();

        return [
            'status' => 'success', 
            'html' => $html, 
            'password' => $password,
            'rotate' => $session->rotateqrcode
        ];
    }
}
