<?php
/**
 * Library functions for local_grupomakro_core
 */

defined('MOODLE_INTERNAL') || die();

// DEBUG: Confirm file is being loaded by Moodle
// die('LIB_LOADED_GMK');

/**
 * Redirect teachers to their dashboard when they access the site home.
 * Improved with forced redirect and logging.
 */
function local_grupomakro_core_user_home_redirect(&$url) {
    global $DB, $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Skip redirection for admins to allow site management
    if (is_siteadmin()) {
        return;
    }

    $is_teacher = $DB->record_exists('gmk_class', ['instructorid' => $USER->id, 'closed' => 0]);

    if ($is_teacher) {
        $target = new moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php');
        
        // Log the redirection for verification
        $log_file = __DIR__ . '/redirection_log.txt';
        $time = date('Y-m-d H:i:s');
        $msg = "[$time] Redirecting User ID {$USER->id} to Dashboard\n";
        @file_put_contents($log_file, $msg, FILE_APPEND);

        // Force redirect immediately
        redirect($target);
    }
}

/**
 * Redirect teachers to their dashboard when they access the Moodle Dashboard (Dashboard/My).
 */
function local_grupomakro_core_my_home_redirect(&$url) {
    local_grupomakro_core_user_home_redirect($url);
}
