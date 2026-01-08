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

    // 1. Check for Active Teachers (Existing Logic)
    $is_active_teacher = $DB->record_exists('gmk_class', ['instructorid' => $USER->id, 'closed' => 0]);

    if ($is_active_teacher) {
        $dashboard_path = '/local/grupomakro_core/pages/teacher_dashboard.php';
        $quiz_editor_path = '/local/grupomakro_core/pages/quiz_editor.php';
        
        $current_script = $_SERVER['SCRIPT_NAME'];
        
        if (strpos($current_script, $dashboard_path) === false && 
            strpos($current_script, $quiz_editor_path) === false) {
            redirect(new moodle_url($dashboard_path));
        }
        return; // Done
    }

    // 2. Check for Inactive Teachers (New Logic)
    // Only redirect if they are actively trying to access Home or Dashboard (caller ensures this)
    $has_past = $DB->record_exists('gmk_class', ['instructorid' => $USER->id]);
    $has_skills = $DB->record_exists('gmk_teacher_skill_relation', ['userid' => $USER->id]);
    $has_disp = $DB->record_exists('gmk_teacher_disponibility', ['userid' => $USER->id]);

    if ($has_past || $has_skills || $has_disp) {
        $inactive_path = '/local/grupomakro_core/pages/inactive_teacher_dashboard.php';
        
        // Prevent redirect loop
        if (strpos($_SERVER['SCRIPT_NAME'], $inactive_path) === false) {
             redirect(new moodle_url($inactive_path));
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
