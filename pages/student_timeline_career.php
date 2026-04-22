<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
require_login();

$career_id = required_param('career_id', PARAM_INT);

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/student_timeline_career.php', ['career_id' => $career_id]);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title('Línea de Tiempo — Carrera');
$PAGE->set_heading('Línea de Tiempo Estudiantes');
$PAGE->set_pagelayout('admin');

$token    = get_logged_user_token();
$back_url = json_encode($CFG->wwwroot . '/local/grupomakro_core/pages/student_timeline.php');

echo $OUTPUT->header();
?>
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<style>
  .theme--light.v-application { background: transparent !important; }
  .timeline-scroll-wrap { overflow-x: auto; }
  .timeline-row { display: flex; align-items: flex-start; gap: 0; min-width: max-content; padding: 8px 0; }
  .stage-wrapper { display: flex; flex-direction: column; }
  .stage-label { font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; }
  .arrow-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; padding-bottom: 20px; min-width: 50px; }
  .stage-card { border-radius: 10px !important; }
  .quarter-card { border-radius: 8px !important; }
  .bimestre-card { border-radius: 6px !important; }
  .v-expansion-panel { border-radius: 8px !important; margin-bottom: 8px !important; }
  .v-expansion-panel::before { box-shadow: none !important; }
</style>

<div id="gmk-career-timeline-app">
  <v-app class="transparent">
    <v-main>
      <intake-timeline :career-id="careerId" :back-url="backUrl"></intake-timeline>
    </v-main>
  </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>

<script>
  var userToken = <?php echo $token; ?>;
  var backUrl   = <?php echo $back_url; ?>;
</script>

<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/intake_timeline.js?v=<?php echo $assetversion; ?>"></script>

<script>
  new Vue({
    el: '#gmk-career-timeline-app',
    vuetify: new Vuetify({
      theme: {
        themes: {
          light: { primary: '#1976D2', success: '#388E3C', warning: '#F57C00', error: '#C62828' }
        }
      }
    }),
    data: {
      careerId: <?php echo json_encode($career_id); ?>,
      backUrl:  backUrl,
    },
  });
</script>
<?php
echo $OUTPUT->footer();
