<?php
/**
 * Library functions for local_grupomakro_core
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Redirect teachers to their dashboard when they access the site home.
 * This is more robust than a login observer as it handles existing sessions.
 * 
 * @param moodle_url $url The default URL Moodle would redirect to.
 * @return void
 */
function local_grupomakro_core_user_home_redirect(&$url) {
    global $DB, $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Check if user is an instructor in any active class in gmk_class
    $is_teacher = $DB->record_exists('gmk_class', ['instructorid' => $USER->id, 'closed' => 0]);

    if ($is_teacher) {
        // Change the redirection URL to the teacher dashboard
        $url = new moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php');
    }
}
