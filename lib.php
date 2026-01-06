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

    // Safety: Do not redirect if we are in a module, course, or admin page
    // This allows teachers to access specific activities directly strings references
    $script = $_SERVER['SCRIPT_NAME'];
    if (strpos($script, '/mod/') !== false || 
        strpos($script, '/course/') !== false || 
        strpos($script, '/admin/') !== false || 
        strpos($script, '/lib/ajax/') !== false ||
        defined('AJAX_SCRIPT')) {
        return;
    }

    if ($is_teacher) {
        $dashboard_path = '/local/grupomakro_core/pages/teacher_dashboard.php';
        // Avoid redirect loop by checking if we are already on that script
        if (strpos($_SERVER['SCRIPT_NAME'], $dashboard_path) === false) {
            redirect(new moodle_url($dashboard_path));
        }
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
        $CFG->custommenuitems .= PHP_EOL . '-' . 'Planificación Académica' .
            '|/local/grupomakro_core/pages/academic_planning.php';
    }

    // 2. Redirection logic
    // Avoid recursion if already on the dashboard
    if (strpos($_SERVER['SCRIPT_NAME'], '/local/grupomakro_core/pages/teacher_dashboard.php') !== false) {
        return;
    }

    // Only intercept if we are on the main landing pages
    try {
        // We use URL_MATCH_BASE which is broad, but the redirect function 
        // now has a specific Guard Clause to prevent redirecting module pages.
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
