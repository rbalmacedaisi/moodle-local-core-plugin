<?php
/**
 * Library functions for local_grupomakro_core
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

/**
 * When the staged absence alert system is enabled, intercept direct
 * access to a course whose class is blocked and redirect to the friendly
 * "course blocked" page.
 *
 * The hook is registered in the navigation extension (extend_navigation_user)
 * but Moodle calls it for nearly every request, so it works for direct
 * /course/view.php?id=X URLs and deep links alike.
 *
 * @param int $courseid
 * @return void
 */
function local_grupomakro_core_guard_blocked_course(int $courseid): void {
    global $USER;

    if (!absd_is_staged_alerts_enabled() || !absd_is_blocking_enabled()) {
        return;
    }
    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return;
    }
    if (empty($USER->id) || empty($courseid)) {
        return;
    }

    $payload = absd_get_course_absence_for_user((int)$USER->id, (int)$courseid);
    if ($payload && $payload['blocked']) {
        $url = new moodle_url('/local/grupomakro_core/pages/course_blocked.php', [
            'classid'  => (int)$payload['classid'],
            'courseid' => (int)$courseid,
        ]);
        redirect($url);
    }
}

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
        $CFG->custommenuitems = 'Planificación';
        $CFG->custommenuitems .= PHP_EOL . '-' . 'Planificación Académica' .
            '|/local/grupomakro_core/pages/academic_planning.php';

        // Gestión Académica dropdown menu
        $CFG->custommenuitems .= PHP_EOL . get_string('admin_category_label', 'local_grupomakro_core');
        $CFG->custommenuitems .= PHP_EOL . '-📘 ' . get_string('class_management', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/classmanagement.php';
        $CFG->custommenuitems .= PHP_EOL . '-🗓️ ' . get_string('class_schedules', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/schedules.php';
        $CFG->custommenuitems .= PHP_EOL . '-🧑‍🏫 ' . get_string('availability_panel', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/availabilitypanel.php';
        $CFG->custommenuitems .= PHP_EOL . '-📆 ' . get_string('availability_calendar', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/availability.php';
        $CFG->custommenuitems .= PHP_EOL . '-🕒 ' . get_string('schedules_panel', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/schedulepanel.php';
        $CFG->custommenuitems .= PHP_EOL . '-🎯 ' . get_string('academic_director_panel', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/academicpanel.php';
        $CFG->custommenuitems .= PHP_EOL . '-🧾 ' . get_string('revalidations_director_menu', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/revalidations_director.php';
        $CFG->custommenuitems .= PHP_EOL . '-📚 Gestión de Módulos Independientes' .
            '|/local/grupomakro_core/pages/module_management.php';
        $CFG->custommenuitems .= PHP_EOL . '-📊 ' . get_string('absence_dashboard', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/absence_dashboard.php';
        $CFG->custommenuitems .= PHP_EOL . '-👩‍🏫 ' . get_string('admin_teachers_management', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/teachers.php';
        $CFG->custommenuitems .= PHP_EOL . '-📂 Gestor de Cursos' .
            '|/local/grupomakro_core/pages/manage_courses.php';
        $CFG->custommenuitems .= PHP_EOL . '-🎥 Gestor de Sesiones Virtuales' .
            '|/local/grupomakro_core/pages/manage_meetings.php';
        $CFG->custommenuitems .= PHP_EOL . '-📋 Matrícula Masiva a Plan' .
            '|/local/grupomakro_core/pages/bulk_enroll.php';
        $CFG->custommenuitems .= PHP_EOL . '-🔍 Analítica de Solapamientos' .
            '|/local/grupomakro_core/pages/overlap_analytics.php';
        $CFG->custommenuitems .= PHP_EOL . '-🎓 ' . get_string('diploma_generation', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/diplomageneration.php';
        $CFG->custommenuitems .= PHP_EOL . '-🖼️ ' . get_string('diploma_templates', 'local_grupomakro_core') .
            '|/local/grupomakro_core/pages/diplomatemplates.php';
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

        // Absence guard: block direct access to courses the student is
        // blocked from due to the staged absence alert system.
        if (strpos($_SERVER['SCRIPT_NAME'], '/course/view.php') !== false
                || strpos($_SERVER['SCRIPT_NAME'], '/mod/') === 0
                || strpos($_SERVER['SCRIPT_NAME'], '/local/grupomakro_core/pages/course_blocked.php') === false) {
            $courseid = optional_param('id', 0, PARAM_INT);
            if (!$courseid && !empty($PAGE->context->instanceid) && $PAGE->context->contextlevel == CONTEXT_COURSE) {
                $courseid = (int)$PAGE->context->instanceid;
            }
            if ($courseid > 0) {
                local_grupomakro_core_guard_blocked_course($courseid);
            }
        }
    } catch (Exception $e) {
        // Avoid crashing the whole site if URL comparison fails in certain contexts
    }
}

/**
 * Inject the "Panel de Reválidas" custom menu entry for users with the
 * view_revalidations_dashboard capability (manager archetype), even when they
 * are not siteadmins (the siteadmin block above already covers them).
 */
function local_grupomakro_core_before_http_headers() {
    global $CFG, $PAGE;

    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return;
    }

    try {
        if (!has_capability('local/grupomakro_core:view_revalidations_dashboard',
            context_system::instance(), null, false)) {
            return;
        }
    } catch (Exception $e) {
        return;
    }

    $existing = isset($CFG->custommenuitems) ? (string)$CFG->custommenuitems : '';
    if (strpos($existing, '/local/grupomakro_core/pages/revalidations_director.php') !== false) {
        return; // Already added by the siteadmin block.
    }
    $CFG->custommenuitems = trim($existing) . PHP_EOL . '-🧾 '
        . get_string('revalidations_director_menu', 'local_grupomakro_core')
        . '|/local/grupomakro_core/pages/revalidations_director.php';
}

/**
 * Serves plugin files stored under our component (diploma backgrounds and
 * generated diploma PDFs). Without this callback Moodle rejects every
 * /pluginfile.php request hitting our component, even when the file exists.
 *
 * @param stdClass $course Course object (unused here, file is in SYSTEM).
 * @param cm_info $cm Course-module object (unused).
 * @param context $context Context the file is being requested from.
 * @param string $filearea File area name.
 * @param array $args Remaining URL parts.
 * @param bool $forcedownload Whether the user agent requested a download.
 * @param array $options Extra options (headers etc.).
 * @return bool True if file served, false to fall through to next plugin.
 */
function local_grupomakro_core_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options = []) {
    global $USER;

    // All our files live in the system context.
    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        return false;
    }

    // File areas served by this plugin and their required capability.
    $areas = [
        'diploma_background' => 'local/grupomakro_core:managediplomas',
        'diploma_document'   => 'local/grupomakro_core:viewdiplomas',
    ];
    if (!isset($areas[$filearea])) {
        return false;
    }
    $requiredcap = $areas[$filearea];

    // Must be logged in and authorised.
    if (!isloggedin() || isguestuser()) {
        // The background is admin-only. The generated diploma PDF can be
        // served to anonymous visitors for the public verification flow
        // only when the URL contains a 'public' token.
        $allowpublic = ($filearea === 'diploma_document' && !empty($args[0]) && strpos((string)$args[0], 'public') === 0);
        if ($filearea === 'diploma_background' || !$allowpublic) {
            return false;
        }
    }

    require_capability($requiredcap, context_system::instance());

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_grupomakro_core', $filearea, (int)$itemid, $filepath, $filename);
    if (!$file) {
        $file = $fs->get_file_by_id((int)$itemid);
        if (!$file || $file->get_component() !== 'local_grupomakro_core' || $file->get_filearea() !== $filearea) {
            return false;
        }
    }

    if (!$file->is_visible()) {
        require_capability($requiredcap, $context);
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
