<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_meetings', '', null, '', array('pagelayout' => 'admin'));

// Check permissions
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/manage_meetings.php'));
$PAGE->set_title('Administrar Sesiones de Invitados');
$PAGE->set_heading('Gestor de Sesiones Virtuales');

// Add Vue and Vuetify
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.js'));
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js'));
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css'));
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js'));

// Add our components
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/MeetingManager.js'));

echo $OUTPUT->header();

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
