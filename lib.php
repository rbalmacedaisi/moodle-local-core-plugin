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
 * Resolve the landing page a staff user should be sent to after login.
 *
 * Before this existed, every gmk_* role was redirected blindly to
 * academicpanel.php, which requires view_academic_panel. Only Director and
 * Secretaría hold that capability, so Registros Académicos, Soporte TI,
 * Bienestar, Psicólogo and the legacy 'administrative' role landed on
 * "Lo sentimos, pero no tiene los permisos para hacer esto
 * ([[grupomakro_core:view_academic_panel]])" the moment they logged in.
 *
 * The map is ordered from "most panel-like" to "most specific": the first
 * entry whose capability the user holds wins. Adding a capability to a role
 * in db/upgradelib.php is enough for that role to get a sensible landing —
 * no change needed here unless the new role's workflow has no page listed.
 *
 * @return moodle_url|null Landing URL, or null when the user holds none of
 *                         these capabilities (students and teachers keep
 *                         their own flow further down the redirect chain).
 */
function local_grupomakro_core_get_staff_landing_url(): ?moodle_url {
    $context = context_system::instance();

    // capability => page, in priority order.
    $candidates = [
        'view_academic_panel'            => 'academicpanel.php',
        'manage_wellness'                => 'wellness_dashboard.php',
        'manage_psychology_appointments' => 'wellness_psychology_panel.php',
        'managerequests'                 => 'letterrequests.php',
        'manageletters'                  => 'lettertypes.php',
        'view_student_population'        => 'student_population.php',
        'viewabsencedashboard'           => 'absence_dashboard.php',
        'manage_meetings'                => 'manage_meetings.php',
        'manage_financial_config'        => 'bypass_financial.php',
        'manage_debug'                   => 'check_webservices.php',
    ];

    foreach ($candidates as $capability => $page) {
        // Fourth arg false: never throw for a guest / not-logged-in user,
        // this runs on every page load through extend_navigation.
        if (has_capability('local/grupomakro_core:' . $capability, $context, null, false)) {
            return new moodle_url('/local/grupomakro_core/pages/' . $page);
        }
    }

    return null;
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

    // 0. Check for GMK ADMIN role (workflow matrix PR1+). Without this, the
    // new matrix roles (gmk_director_academico, gmk_secretaria_academica, etc.)
    // hit /my/ or the LXP and are shown "no tienes contrato" because the LXP
    // is the student interface. Route them to the academic panel which IS the
    // proper landing for any gmk-capable user.
    // The landing is resolved per capability instead of being hard-coded to
    // academicpanel.php: that page needs view_academic_panel, which only
    // Director and Secretaría hold, so every other staff role used to be
    // redirected straight into a "no tiene los permisos" error page.
    $landing = local_grupomakro_core_get_staff_landing_url();
    if ($landing !== null) {
        $landing_path = $landing->out_as_local_url(false);
        if (strpos($_SERVER['SCRIPT_NAME'], basename(parse_url($landing_path, PHP_URL_PATH))) === false) {
            redirect($landing);
        }
        return;
    }

    // 1. Check for Active Teachers (Existing Logic). A "teacher" here is either the
    // main instructor or the support teacher (gmk_class.supportinstructorid) — both
    // get the same redirect-to-dashboard treatment so the support teacher lands on
    // their classes too.
    // IMPORTANT: Moodle's record_exists_sql counts placeholders literally — two
    // `:uid` references need two distinct array keys (`uid` and `uid2`), otherwise
    // it throws "Número incorrecto de parámetros de consulta" and the redirect
    // is silently swallowed (the event handler never completes).
    // ALSO: don't add our own LIMIT clause — record_exists_sql appends its own
    // "LIMIT 0, 1" and two stacked LIMITs produce a SQL syntax error.
    $is_active_teacher = $DB->record_exists_sql(
        "SELECT 1 FROM {gmk_class}
          WHERE (instructorid = :uid OR supportinstructorid = :uid2)
            AND closed = 0",
        ['uid' => (int)$USER->id, 'uid2' => (int)$USER->id]
    );

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
    $has_past = $DB->record_exists_sql(
        "SELECT 1 FROM {gmk_class}
          WHERE (instructorid = :uid OR supportinstructorid = :uid2)",
        ['uid' => (int)$USER->id, 'uid2' => (int)$USER->id]
    );
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
    //
    // The menu is built from the caller's capabilities, not from is_siteadmin().
    // It used to be wrapped in `if (is_siteadmin())`, so the workflow-matrix
    // roles could reach their pages by URL but saw no links at all: Secretaria
    // Academica holds 33 capabilities and rendered exactly 2 entries. Site
    // admins are unaffected — has_capability() returns true for them on every
    // capability, so they still get the full menu.
    //
    // Each entry is [capability, page, label]. A category whose entries are all
    // filtered out is dropped entirely rather than rendered as an empty
    // dropdown. To expose a new page, add a row here with the capability its
    // own require_capability() call checks — the role bundles themselves live
    // in db/upgradelib.php.
    if (isloggedin() && !isguestuser()) {
        $syscontext = context_system::instance();
        $pluginname = 'local_grupomakro_core';

        // A couple of lang strings already start with their own emoji
        // (revalidations_director_menu, announcements_menu), so those entries
        // carry no prefix here — adding one renders it twice.
        $menu = [
            'Planificación' => [
                ['manage_academic_planning', 'academic_planning.php', '📅 Planificación Académica'],
                ['manage_academic_calendar', 'academiccalendar.php', '🗓️ Calendario Académico'],
                ['view_academic_demand_gaps', 'academic_demand_gaps.php', '📉 Brechas de Demanda'],
                ['view_overlap_analytics', 'overlap_analytics.php', '🔍 Analítica de Solapamientos'],
            ],
            get_string('admin_category_label', $pluginname) => [
                ['view_academic_panel', 'academicpanel.php', '🎯 ' . get_string('academic_director_panel', $pluginname)],
                ['view_classmanagement', 'classmanagement.php', '📘 ' . get_string('class_management', $pluginname)],
                ['manage_schedules', 'schedules.php', '🗓️ ' . get_string('class_schedules', $pluginname)],
                ['manage_schedules', 'schedulepanel.php', '🕒 ' . get_string('schedules_panel', $pluginname)],
                ['manage_teacher_availability', 'availabilitypanel.php', '🧑‍🏫 ' . get_string('availability_panel', $pluginname)],
                ['manage_teacher_availability', 'availability.php', '📆 ' . get_string('availability_calendar', $pluginname)],
                ['manage_teachers', 'teachers.php', '👩‍🏫 ' . get_string('admin_teachers_management', $pluginname)],
                ['manage_courses', 'manage_courses.php', '📂 Gestor de Cursos'],
                ['manage_modules', 'module_management.php', '📚 Gestión de Módulos Independientes'],
                ['manage_meetings', 'manage_meetings.php', '🎥 Gestor de Sesiones Virtuales'],
                ['bulk_enroll', 'bulk_enroll.php', '📋 Matrícula Masiva a Plan'],
                // Homologations are still gated by moodle/site:config on the page
                // itself, so the link must use that same check or every
                // operational role would click into a permission error.
                ['@moodle/site:config', 'homologation_manager.php', '🔀 Gestor de Homologaciones'],
            ],
            'Estudiantes' => [
                ['view_student_population', 'student_population.php', '👥 Población Estudiantil'],
                ['view_active_students_by_class', 'active_students_by_class.php', '🧑‍🎓 Activos por Clase'],
                ['view_student_timeline', 'student_timeline.php', '🧭 Línea de Tiempo'],
                ['manage_users', 'users.php', '👤 Gestión de Usuarios'],
                ['import_users', 'import_users.php', '⬆️ Importar Usuarios'],
            ],
            'Asistencia y Notas' => [
                ['viewabsencedashboard', 'absence_dashboard.php', '📊 ' . get_string('absence_dashboard', $pluginname)],
                ['view_grade_report', 'grade_report.php', '📈 Informe de Calificaciones'],
                ['view_failed_subjects_report', 'failed_subjects_report.php', '📝 ' . get_string('fsr_menu', $pluginname)],
                ['view_revalidations_dashboard', 'revalidations_director.php', get_string('revalidations_director_menu', $pluginname)],
            ],
            'Cartas, Contratos y Diplomas' => [
                ['managerequests', 'letterrequests.php', '📬 Bandeja de Cartas'],
                ['manageletters', 'lettertypes.php', '🗂️ Catálogo de Cartas'],
                ['manage_orders', 'orders.php', '🧾 Órdenes'],
                ['manage_institutions', 'institutionmanagement.php', '🏢 Instituciones'],
                ['manage_institutional_contracts', 'institutionalcontracts.php', '📄 Contratos Institucionales'],
                ['view_credit_report', 'credit_report.php', '💳 Informe de Créditos'],
                ['view_financial_planning', 'financial_planning.php', '💰 Análisis Financiero Docente'],
                ['viewdiplomas', 'diplomageneration.php', '🎓 ' . get_string('diploma_generation', $pluginname)],
                ['managediplomas', 'diplomatemplates.php', '🖼️ ' . get_string('diploma_templates', $pluginname)],
            ],
            'Bienestar' => [
                ['manage_wellness', 'wellness_dashboard.php', '🤝 ' . get_string('wellness_dashboard_menu', $pluginname)],
                ['manage_psychology_appointments', 'wellness_psychology_panel.php', '🧠 Psicología (agenda)'],
                ['manage_psychology_appointments', 'wellness_staff_panel.php', '👥 Personal asignado'],
                ['manageannouncements', 'announcements.php', get_string('announcements_menu', $pluginname)],
            ],
            'Sistema' => [
                ['manage_debug', 'check_webservices.php', '🔌 Verificar Web Services'],
                ['view_log', 'view_log.php', '📜 Registro del Sistema'],
                ['manage_financial_config', 'bypass_financial.php', '💵 Bypass Financiero'],
                ['manage_financial_config', 'grace_period.php', '⏳ Período de Gracia'],
            ],
        ];

        $lines = [];
        foreach ($menu as $category => $entries) {
            $visible = [];
            foreach ($entries as $entry) {
                list($capability, $page, $label) = $entry;
                // A '@' prefix means the capability is not one of ours.
                $fullcap = ($capability[0] === '@')
                    ? substr($capability, 1)
                    : 'local/grupomakro_core:' . $capability;
                // Fourth arg false: this runs on every page load, never throw.
                if (has_capability($fullcap, $syscontext, null, false)) {
                    $visible[] = '-' . $label . '|/local/grupomakro_core/pages/' . $page;
                }
            }
            if ($visible) {
                $lines[] = $category;
                $lines = array_merge($lines, $visible);
            }
        }

        // Prepend rather than replace: the site-level custommenuitems setting is
        // empty today, but overwriting it would silently drop any menu an admin
        // configures later. Users with no staff capability at all produce no
        // lines and keep the site menu untouched.
        if ($lines) {
            $existing = isset($CFG->custommenuitems) ? trim((string)$CFG->custommenuitems) : '';
            $CFG->custommenuitems = implode(PHP_EOL, $lines)
                . ($existing !== '' ? PHP_EOL . $existing : '');
        }
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
 * Inject the Wellness dashboard menu entry for users with the
 * manage_wellness capability (e.g. custom Bienestar role), even when they
 * are not siteadmins.
 */
function local_grupomakro_core_wellness_menu_inject() {
    global $CFG, $PAGE;

    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return;
    }

    try {
        if (!has_capability('local/grupomakro_core:manage_wellness',
            context_system::instance(), null, false)) {
            return;
        }
    } catch (Exception $e) {
        return;
    }

    $existing = isset($CFG->custommenuitems) ? (string)$CFG->custommenuitems : '';
    if (strpos($existing, '/local/grupomakro_core/pages/wellness_dashboard.php') !== false) {
        return; // Already added.
    }
    $CFG->custommenuitems = trim($existing) . PHP_EOL . '-🤝 '
        . get_string('wellness_dashboard_menu', 'local_grupomakro_core')
        . '|/local/grupomakro_core/pages/wellness_dashboard.php';
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
    // F-16: wellness_carnet_photo filearea removed (no write side yet);
    // the carnet uses user.picture profile photo as fallback via
    // wellness_carnet_manager::photo_url().
    // Portadas de Bienestar (convenios, eventos, formularios). Son imagenes
    // promocionales, sin datos personales, y las consume la LXP desde OTRO
    // dominio (students.isi.edu.pa): con SameSite=Lax el navegador no manda la
    // cookie de Moodle en una peticion de <img> cross-site, asi que exigir
    // sesion las dejaria rotas. Se sirven publicas a proposito.
    $publicareas = ['wellness_partner_logo', 'wellness_event_cover', 'wellness_form_cover'];
    if (in_array($filearea, $publicareas, true)) {
        $itemid   = array_shift($args);
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_grupomakro_core', $filearea, (int)$itemid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, 60 * 60 * 24, 0, false, $options);
        return true;
    }

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

    // Carnet photos are per-user: only the owner (or a manager) can fetch them.
    // The carnet filearea is keyed by userid, so $itemid MUST equal $USER->id
    // when the requester is a regular student.
    if ($filearea === 'wellness_carnet_photo') {
        $canmanage = has_capability('local/grupomakro_core:manage_wellness', context_system::instance());
        if (!$canmanage && (int)$itemid !== (int)$USER->id) {
            return false;
        }
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
