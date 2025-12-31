<?php
/**
 * Library functions for local_grupomakro_core
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Redirect teachers to their dashboard when they access the site home or personal area.
 * This is a catch-all strategy using multiple Moodle hooks.
 */
function local_grupomakro_core_user_home_redirect(&$url) {
    global $DB, $USER;

    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return;
    }

    // Check if user is an instructor in any active class
    $is_teacher = $DB->record_exists('gmk_class', ['instructorid' => $USER->id, 'closed' => 0]);

    if ($is_teacher) {
        $target = new moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php');
        redirect($target);
    }
}

/**
 * Standard Moodle hooks for home page redirection.
 */
function local_grupomakro_core_my_home_redirect(&$url) {
    local_grupomakro_core_user_home_redirect($url);
}

/**
 * Catch-all via navigation extension. 
 * This is called on almost every page and ensures we don't miss the target.
 * Also handles admin menu items (merged from locallib.php).
 */
function local_grupomakro_core_extend_navigation(global_navigation $navigation) {
    global $PAGE, $CFG;
    
    // 1. Admin menu handling (from original locallib.php)
    if (is_siteadmin()) {
        $CFG->custommenuitems = get_string('pluginname', 'local_grupomakro_core');
        if (has_capability('local/grupomakro_core:seeallorders', $PAGE->context)) {
            $CFG->custommenuitems .= PHP_EOL . '-' . get_string('orders', 'local_grupomakro_core') .
                '|/local_grupomakro_core/pages/orders.php';
        }
    }

    // 2. Redirection logic
    // Only intercept if we are on the main landing pages
    try {
        $is_home = $PAGE->url->compare(new moodle_url('/'), URL_MATCH_BASE);
        $is_dashboard = $PAGE->url->compare(new moodle_url('/my/'), URL_MATCH_BASE);
        
        if ($is_home || $is_dashboard) {
            $dummy = null;
            local_grupomakro_core_user_home_redirect($dummy);
        }
    } catch (Exception $e) {
        // Avoid crashing the whole site if URL comparison fails in certain contexts
    }
}
