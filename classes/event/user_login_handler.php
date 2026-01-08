<?php

namespace local_grupomakro_core\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event handler for user login events.
 */
class user_login_handler {

    /**
     * Handle user_loggedin event.
     * 
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event) {
        global $DB, $CFG;

        $userid = $event->userid;
        
        // DEBUG LOGGING
        $log_file = $CFG->dirroot . '/local/grupomakro_core/redirect_debug.log';
        $log_msg = date('Y-m-d H:i:s') . " - [Handler: user_login_handler] Login Event for User ID: $userid\n";

        // 1. Check for ACTIVE classes (Target: Teacher Dashboard)
        $has_active_classes = $DB->record_exists('gmk_class', ['instructorid' => $userid, 'closed' => 0]);
        $log_msg .= " - Has Active Classes: " . ($has_active_classes ? 'YES' : 'NO') . "\n";

        if ($has_active_classes) {
            file_put_contents($log_file, $log_msg . " - REDIRECTING to Teacher Dashboard\n", FILE_APPEND);
            $url = new \moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php');
            redirect($url);
        }

        // 2. Check for INACTIVE Teacher status (Target: Inactive Dashboard)
        $has_past_classes = $DB->record_exists('gmk_class', ['instructorid' => $userid]);
        $has_skills = $DB->record_exists('gmk_teacher_skill_relation', ['userid' => $userid]);
        $has_availability = $DB->record_exists('gmk_teacher_disponibility', ['userid' => $userid]);

        $log_msg .= " - Past Classes: " . ($has_past_classes ? 'YES' : 'NO') . "\n";
        $log_msg .= " - Skills: " . ($has_skills ? 'YES' : 'NO') . "\n";
        $log_msg .= " - Availability: " . ($has_availability ? 'YES' : 'NO') . "\n";

        if ($has_past_classes || $has_skills || $has_availability) {
            file_put_contents($log_file, $log_msg . " - REDIRECTING to Inactive Dashboard\n", FILE_APPEND);
            $url = new \moodle_url('/local/grupomakro_core/pages/inactive_teacher_dashboard.php');
            redirect($url);
        }

        file_put_contents($log_file, $log_msg . " - NO REDIRECT (Standard Moodle Behavior)\n", FILE_APPEND);
    }
}
