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
 * Academic Director — Revalidations Dashboard.
 *
 * Lists revalidation requests across the institution, lets the director refresh
 * payments against Odoo, drill into individual records, and create
 * extemporaneous requests for students who meet the eligibility criteria.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('local/grupomakro_core:view_revalidations_dashboard', context_system::instance());

$cancreateextemp = has_capability('local/grupomakro_core:create_extemporaneous_revalidations',
    context_system::instance());

$plugin = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/revalidations_director.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('revalidations_director_title', $plugin));
$PAGE->set_heading(get_string('revalidations_director_title', $plugin));
$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('revalidations-director-page');

$sesskey = sesskey();
$ajaxurl = new moodle_url('/local/grupomakro_core/ajax.php');
$ajaxurlstr = $ajaxurl->out(false);

echo $OUTPUT->header();
?>
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<style>
    .theme--light.v-application { background: transparent !important; }
    .rd-table th { white-space: nowrap; }
    .rd-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
    .rd-pill-amber  { background:#FFF3E0; color:#E65100; }
    .rd-pill-blue   { background:#E3F2FD; color:#0D47A1; }
    .rd-pill-green  { background:#E8F5E9; color:#1B5E20; }
    .rd-pill-red    { background:#FFEBEE; color:#B71C1C; }
    .rd-pill-grey   { background:#ECEFF1; color:#37474F; }
    .rd-pill-purple { background:#EDE7F6; color:#4527A0; }
    .rd-picked { background: #FFFDE7; }
</style>

<div id="gmk-rev-dir-app">
    <v-app class="transparent">
        <v-main>
            <revalidations-director
                :can-create-extemp="<?php echo $cancreateextemp ? 'true' : 'false'; ?>"
            ></revalidations-director>
        </v-main>
    </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
window.wsUrl = window.wsUrl || <?php echo json_encode($ajaxurlstr); ?>;
window.Y = window.Y || {};
window.Y.config = window.Y.config || {};
window.Y.config.sesskey = window.Y.config.sesskey || <?php echo json_encode($sesskey); ?>;
</script>

<?php
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/RevalidationsDirector.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/CreateExtemporaneousRevalidationModal.js?v=' . $assetversion));
?>
<script>
// Self-contained Vue mount for this page (avoids depending on app.js which targets #gmk-app).
(function() {
    function mountRevDirApp() {
        if (!window.Vue || !window.Vuetify) {
            console.error('[RevDir] Vue/Vuetify not loaded yet');
            return;
        }
        if (typeof Swal !== 'undefined') {
            window.Vue.prototype.$swal = Swal;
        }
        var el = document.getElementById('gmk-rev-dir-app');
        if (!el) return;
        if (el.__vue_app__) return; // already mounted
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
        document.addEventListener('DOMContentLoaded', mountRevDirApp);
    } else {
        // Wait one tick so the component definitions load.
        setTimeout(mountRevDirApp, 0);
    }
})();
</script>
<?php
echo $OUTPUT->footer();