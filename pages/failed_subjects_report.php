<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Failed Subjects Report
 *
 * Admin view that lists active students with failed subjects
 * (gmk_course_progre.status IN 5,7) and matches them against
 * gmk_class rows projected to open in a chosen academic period,
 * taking the student's jornada into account. Lets the admin enrol
 * the student directly into the matching class.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('local/grupomakro_core:view_failed_subjects_report', context_system::instance());

$plugin = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/failed_subjects_report.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('fsr_title', $plugin));
$PAGE->set_heading(get_string('fsr_title', $plugin));
$PAGE->add_body_class('failed-subjects-report-page');

// IMPORTANT: All $PAGE->requires->js/css calls MUST be made before
// $OUTPUT->header() is printed. Moodle's outputrequirementslib.php
// throws a coding_exception otherwise. We register the JS and CSS
// here, then print the header.
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/FailedSubjectsReport.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/FailedSubjectsStudentDrawer.js?v=' . $assetversion));
$PAGE->requires->css(new moodle_url('/local/grupomakro_core/styles/failed_subjects.css?v=' . $assetversion));

// Build a server-to-client config object. Strings are encoded with
// json_encode (which adds the surrounding double quotes) so the values
// land in JS as proper string literals. The whole object is emitted
// into a window.fsConfig namespace which the Vue component reads on
// mount (see FailedSubjectsReport.js -> data().cfg).
//
// We use json_encode instead of htmlspecialchars here because the
// values go inside a <script> block, not an HTML attribute, so we DO
// want the surrounding quotes — and we DO want to escape any quotes
// inside the value. JSON_HEX_TAG / HEX_AMP / HEX_APOS / HEX_QUOT
// keep `<`, `>`, `&`, `'`, `"` from breaking the HTML or JS parser
// even if a sesskey or URL contains them.
$cfg = [
    'sesskey' => sesskey(),
    'ajaxUrl' => (new moodle_url('/local/grupomakro_core/ajax.php'))->out(false),
    'wwwRoot' => $CFG->wwwroot,
];
$cfgJson = json_encode(
    $cfg,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
);
$cfgTag = '<!--__FSR_CFG__' . $cfgJson . '__FSR_CFG__-->';

echo $OUTPUT->header();
?>
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<style>
    .theme--light.v-application { background: transparent !important; }
    .fsr-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
    .fsr-pill-green  { background:#E8F5E9; color:#1B5E20; }
    .fsr-pill-amber  { background:#FFF3E0; color:#E65100; }
    .fsr-pill-red    { background:#FFEBEE; color:#B71C1C; }
    .fsr-pill-blue   { background:#E3F2FD; color:#0D47A1; }
    .fsr-pill-grey   { background:#ECEFF1; color:#37474F; }
    .fsr-pill-purple { background:#EDE7F6; color:#4527A0; }
    .fsr-link { color:#1976d2; text-decoration:none; }
    .fsr-link:hover { text-decoration:underline; }
    .fsr-sem-full   { color:#B71C1C; font-weight:700; }
    .fsr-sem-ok     { color:#1B5E20; font-weight:700; }
    .fsr-sem-warn   { color:#E65100; font-weight:700; }
</style>

<?php echo $cfgTag; ?>

<div id="gmk-fsr-app">
    <v-app class="transparent">
        <v-main>
            <failed-subjects-report></failed-subjects-report>
        </v-main>
    </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Parse the FSR config from the marker comment. This avoids passing
// complex values through Vue attribute binding (where unquoted
// expressions like :ajax-url="https://..." fail to parse).
(function() {
    var html = document.body ? document.body.innerHTML : '';
    var m = html.match(/__FSR_CFG__(.*?)__FSR_CFG__/);
    window.fsConfig = m ? JSON.parse(m[1]) : {};
})();

// Self-contained Vue mount for this page (matches the pattern used by
// RevalidationsDirector).
(function() {
    function mountFsrApp() {
        if (!window.Vue || !window.Vuetify) {
            console.error('[FSR] Vue/Vuetify not loaded yet');
            return;
        }
        if (typeof Swal !== 'undefined') {
            window.Vue.prototype.$swal = Swal;
        }
        var el = document.getElementById('gmk-fsr-app');
        if (!el) return;
        if (el.__vue_app__) return;
        el.__vue_app__ = new window.Vue({
            el: el,
            vuetify: new window.Vuetify({
                treeShake: true,
                theme: {
                    themes: {
                        light: {
                            primary: '#1976d2',
                            secondary: '#424242',
                            success: '#3cd4a0',
                            base: '#f8f9fa'
                        }
                    }
                }
            })
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountFsrApp);
    } else {
        setTimeout(mountFsrApp, 0);
    }
})();
</script>
<?php
echo $OUTPUT->footer();
