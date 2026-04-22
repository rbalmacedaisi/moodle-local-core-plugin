<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/student_timeline.php');
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title('Línea de Tiempo Estudiantes');
$PAGE->set_heading('Línea de Tiempo Estudiantes');
$PAGE->set_pagelayout('admin');

// get_logged_user_token() ya retorna el valor con json_encode aplicado
$token = get_logged_user_token();
$career_page_url = json_encode($CFG->wwwroot . '/local/grupomakro_core/pages/student_timeline_career.php');

echo $OUTPUT->header();
?>
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<style>
  .theme--light.v-application { background: transparent !important; }
  .career-icon-wrap {
    width: 52px; height: 52px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
  }
  .career-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.12) !important; }
</style>

<div id="gmk-timeline-app">
  <v-app class="transparent">
    <v-main>
      <career-cards :career-page-url="careerPageUrl"></career-cards>
    </v-main>
  </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>

<script>
  // Token global — mismo patrón que el resto del plugin
  var userToken = <?php echo $token; ?>;
  var careerPageUrl = <?php echo $career_page_url; ?>;
</script>

<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/career_cards.js?v=<?php echo $assetversion; ?>"></script>

<script>
  new Vue({
    el: '#gmk-timeline-app',
    vuetify: new Vuetify({ theme: { themes: { light: { primary: '#1976D2' } } } }),
    data: { careerPageUrl: careerPageUrl },
  });
</script>
<?php
echo $OUTPUT->footer();
