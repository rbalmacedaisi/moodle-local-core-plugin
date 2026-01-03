<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_meetings', '', null, '', array('pagelayout' => 'admin'));

// Check permissions
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/manage_meetings.php'));
$PAGE->set_title('Administrar Sesiones de Invitados');
$PAGE->set_heading('Gestor de Sesiones Virtuales');

// Vue & Vuetify - Manual injection to avoid RequireJS/AMD conflicts
echo $OUTPUT->header();

echo '
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<script>
    // Hack to prevent "Mismatched anonymous define() module" error
    // We temporarily hide "define" so Vue/Vuetify register as globals instead of AMD modules
    var _oldDefine = window.define;
    var _oldRequire = window.require;
    window.define = null;
    window.require = null; // Prevent libraries from detecting RequireJS
</script>
<script src="https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<script src="' . $CFG->wwwroot . '/local/grupomakro_core/js/components/MeetingManager.js"></script>
<script>
    // Restore define and require
    window.define = _oldDefine;
    window.require = _oldRequire;
</script>
';

?>

<div id="app">
    <v-app>
        <v-main>
            <meeting-manager></meeting-manager>
        </v-main>
    </v-app>
</div>

<script>
    window.wsUrl = '<?php echo $CFG->wwwroot . "/local/grupomakro_core/ajax.php"; ?>';
    window.wsStaticParams = {
        sesskey: '<?php echo sesskey(); ?>'
    };

    new Vue({
        el: '#app',
        vuetify: new Vuetify(),
    });
</script>

<?php
echo $OUTPUT->footer();
