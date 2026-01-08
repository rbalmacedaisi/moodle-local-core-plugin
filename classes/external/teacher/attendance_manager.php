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
        // Check ALL attendance instances in this course to see what's going on
        $all_atts = $DB->get_records('attendance', ['course' => $class->courseid]);
        
        if (empty($all_atts)) {
             // Debugging: Try to find it by name logic "Asistencia [Class Name]"
             // The screenshot shows "Asistencia 2025-VI (S) INGLÉS II (PRESENCIAL)-125"
             // Let's try to match loosely
             $name_pattern = "%" . $class->name . "%";
             $found_by_name = $DB->get_records_select('attendance', "name LIKE ?", [$name_pattern]);

             $debug_msg = "No se encontró NINGUNA actividad de asistencia en la tabla mdl_attendance para el curso ID: {$class->courseid}.";
             
             if (!empty($found_by_name)) {
                 $first = reset($found_by_name);
                 $debug_msg .= " PERO sí encontré una actividad llamada '{$first->name}' en el Curso ID: {$first->course}. ¡Hay una discrepancia de IDs!";
             } else {
                 $debug_msg .= " Tampoco encontré ninguna actividad con nombre similar a '{$class->name}'.";
             }

             // Debugging: Check if course exists at all
             $course_exists = $DB->record_exists('course', ['id' => $class->courseid]);
             return [
                 'status' => 'error', 
                 'message' => $debug_msg,
                 'debug_info' => [
                     'class_id' => $classid,
                     'expected_course_id' => $class->courseid,
                     'found_by_name_count' => count($found_by_name),
                     'first_found_course_id' => !empty($found_by_name) ? reset($found_by_name)->course : null
                 ]
             ];
        }

        // Just take the first one found
        $att = reset($all_atts);

        // $att = $DB->get_record('attendance', ['course' => $class->courseid], 'id, name, grade', IGNORE_MULTIPLE);
        
        // if (!$att) { ... } // Removed old check

        $cm = get_coursemodule_from_instance('attendance', $att->id, $class->courseid);
        
        // Init Structure
        // $att_structure = new mod_attendance_structure($att, $cm, $class->courseid, \context_module::instance($cm->id));

        // 3. Get Sessions for "Today" (or recent) filtered by Group
        // mod_attendance usually filters by group mode.
        // We want sessions specifically for this $class class (Group).
        
        // Use class/period dates to fetch all relevant sessions
        // Add buffer: 1 month before start, 2 months after end to cover exams or schedule changes
        $start_date = $class->initdate - (30 * 24 * 3600);
        $end_date = !empty($class->enddate) ? $class->enddate + (60 * 24 * 3600) : time() + (365 * 24 * 3600);

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
            'start' => $start_date,
            'end' => $end_date 
        ]);
        
        // Format for frontend
        $result = [];
        foreach ($sessions as $s) {
            $item = new stdClass();
            $item->id = $s->id;
            $item->sessdate = $s->sessdate; // Required for JS comparison
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

        try {
            $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);
            $att = $DB->get_record('attendance', ['id' => $session->attendanceid], '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('attendance', $att->id, $att->course);
            
            // Passwords logic
            if (function_exists('attendance_generate_passwords')) {
               $password = attendance_generate_passwords($session);
            } else {
               $password = $session->studentpassword;
            }

            // Fix: Ensure TCPDF Barcode class is loaded
            if (!class_exists('TCPDF2DBarcode')) {
                require_once($CFG->libdir . '/tcpdf/tcpdf_barcodes_2d.php');
            }

            // Capture Output
            ob_start();
            if (function_exists('attendance_renderqrcode')) {
                attendance_renderqrcode($session, $password);
            } else {
                echo "Error: Función QR no encontrada. Asegúrese de que el plugin attendance está instalado.";
            }
            $html = ob_get_clean();

            return [
                'status' => 'success', 
                'html' => $html, 
                'password' => $password,
                'rotate' => $session->rotateqrcode ?? 0
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Error al generar QR: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine()
            ];
        }
    }
}
