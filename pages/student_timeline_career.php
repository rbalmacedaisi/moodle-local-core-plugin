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
$PAGE->set_heading('Línea de Tiempo de Estudiantes');
$PAGE->set_pagelayout('admin');

$token    = get_logged_user_token();
$back_url = json_encode($CFG->wwwroot . '/local/grupomakro_core/pages/student_timeline.php');

echo $OUTPUT->header();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<link href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/styles/timeline.css?v=<?php echo $assetversion; ?>" rel="stylesheet">

<div id="gmk-career-timeline-app">
  <v-app class="transparent">
    <v-main>
      <intake-timeline
        :career-id="careerId"
        :back-url="backUrl"
        @toggle-courses="showSubjectsPanel = !showSubjectsPanel"
        @lp-selected="selectedLearningPlanId = $event"
        @cohort-selected="selectedCohort = $event">
      </intake-timeline>

      <subjects-panel
        v-if="selectedLearningPlanId"
        :learning-plan-id="selectedLearningPlanId || 2"
        :cohort="selectedCohort"
        :jornada="selectedJornada"
        :visible="showSubjectsPanel"
        @close="showSubjectsPanel = false">
      </subjects-panel>
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

<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/modals/studentlistmodal.js?v=<?php echo $assetversion; ?>"></script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/panels/subjectspanel.js?v=<?php echo $assetversion; ?>"></script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/intake_timeline.js?v=<?php echo $assetversion; ?>"></script>

<script>
  new Vue({
    el: '#gmk-career-timeline-app',
    vuetify: new Vuetify({
      theme: {
        themes: {
          light: { primary: '#6366F1', success: '#10B981', warning: '#F59E0B', error: '#EF4444' }
        }
      }
    }),
    data: {
      careerId: <?php echo json_encode($career_id); ?>,
      backUrl:  backUrl,
      showSubjectsPanel: false,
      selectedLearningPlanId: null,
      selectedJornada: 'ALL',
      selectedCohort: '2026',
    },
  });
</script>
<?php
echo $OUTPUT->footer();
