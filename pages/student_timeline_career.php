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
  .clickable-number { cursor: pointer !important; transition: opacity 0.2s; }
  .clickable-number:hover { opacity: 0.7; text-decoration: underline; }
  
  /* Courses Panel */
  .courses-panel-wrapper {
    position: fixed;
    top: 0;
    right: 0;
    width: 380px;
    height: 100vh;
    z-index: 1600;
    display: flex;
    flex-direction: column;
    background: #fff;
    box-shadow: -2px 0 10px rgba(0,0,0,0.15);
    transition: transform 0.3s ease;
  }
  .courses-panel-wrapper.hidden-panel {
    transform: translateX(100%);
  }
  .panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    border-bottom: 1px solid #e0e0e0;
    background: #1976D2;
    color: white;
  }
  .panel-header h3 { margin: 0; font-size: 18px; font-weight: 500; }
  .btn-close-panel {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    line-height: 1;
  }
  .btn-close-panel:hover { opacity: 0.7; }
  .panel-search { padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
  .search-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
  }
  .search-input:focus { outline: none; border-color: #1976D2; }
  .panel-content { flex: 1; overflow-y: auto; padding: 12px; }
  .loading-state { display: flex; justify-content: center; padding: 40px; }
  .error-state { color: #C62828; padding: 16px; text-align: center; }
  .no-courses { color: #666; text-align: center; padding: 40px; }
  
  /* Course Cards */
  .course-card {
    background: #fafafa;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
    cursor: grab;
    transition: all 0.2s;
  }
  .course-card:hover {
    border-color: #1976D2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }
  .course-card.required { border-left: 3px solid #1976D2; }
  .course-card.dragging { opacity: 0.5; }
  .course-card.is-dragging { opacity: 0.5; }
  
  .course-card-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
  }
  .course-position {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: #1976D2;
    color: white;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 600;
  }
  .course-name {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .required-badge {
    font-size: 10px;
    background: #388E3C;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
  }
  .course-card-meta {
    font-size: 12px;
    color: #666;
    margin-bottom: 8px;
  }
  .meta-sep { margin: 0 4px; }
  .course-card-stats { display: flex; gap: 16px; }
  .stat-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #666;
  }
  .stat-item .v-icon { font-size: 16px; }
  .stat-num { font-weight: 600; }
  .stat-label { color: #999; }
  .stat-item.pending.active { color: #F57C00; }
  .stat-item.pending.active .v-icon { color: #F57C00; }
  
  /* Subjects Panel - Fixed sidebar */
  .subjects-panel-wrapper {
    position: fixed;
    top: 0;
    right: 0;
    width: 380px;
    height: 100vh;
    z-index: 1600;
    display: flex;
    flex-direction: column;
    background: #fff;
    box-shadow: -2px 0 10px rgba(0,0,0,0.15);
    transition: transform 0.3s ease;
  }
  .subjects-panel-wrapper.hidden-panel {
    transform: translateX(100%);
  }
</style>

<div id="gmk-career-timeline-app">
  <v-app class="transparent">
    <v-main>
      <div class="timeline-layout">
        <intake-timeline 
          :career-id="careerId" 
          :back-url="backUrl"
          @toggle-courses="showSubjectsPanel = !showSubjectsPanel"
          @lp-selected="selectedLearningPlanId = $event"
          @cohort-selected="selectedCohort = $event">
        </intake-timeline>
        
        <div 
          v-if="selectedLearningPlanId || showSubjectsPanel"
          class="subjects-panel-wrapper"
          :class="{ 'hidden-panel': !showSubjectsPanel }">
          <subjects-panel 
            :learning-plan-id="selectedLearningPlanId || 2"
            :cohort="selectedCohort"
            :jornada="selectedJornada"
            :visible="showSubjectsPanel"
            @close="showSubjectsPanel = false">
          </subjects-panel>
        </div>
      </div>
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
          light: { primary: '#1976D2', success: '#388E3C', warning: '#F57C00', error: '#C62828' }
        }
      }
    }),
    data: {
      careerId: <?php echo json_encode($career_id); ?>,
      backUrl:  backUrl,
      showSubjectsPanel: false,
      selectedLearningPlanId: null,
      selectedJornada: 'ALL',
      selectedCohort: '2026'
    },
  });
</script>
<?php
echo $OUTPUT->footer();