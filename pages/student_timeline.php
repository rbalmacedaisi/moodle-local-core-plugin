<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
require_login();
require_capability('local/grupomakro_core:view_student_timeline', context_system::instance());

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/student_timeline.php');
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title('Línea de Tiempo de Estudiantes');
$PAGE->set_heading('Línea de Tiempo de Estudiantes');
$PAGE->set_pagelayout('admin');

$token = get_logged_user_token();
$career_page_url = json_encode($CFG->wwwroot . '/local/grupomakro_core/pages/student_timeline_career.php');

echo $OUTPUT->header();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<link href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/styles/timeline.css?v=<?php echo $assetversion; ?>" rel="stylesheet">

<?php if (has_capability('local/grupomakro_core:manage_academic_planning', context_system::instance())) : ?>
<div style="display:flex;justify-content:flex-end;margin:0 0 8px;">
  <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/cohort_planning.php'))->out(); ?>"
     style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:6px;background:#4F46E5;color:#fff;text-decoration:none;font:500 13px Inter,Roboto,sans-serif;"
     title="Ver qué asignaturas le tocan a cada estudiante en el próximo periodo y planificar los grupos">
    &#128197; Planificar periodo
  </a>
</div>
<?php endif; ?>
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
  var userToken = <?php echo $token; ?>;
  var careerPageUrl = <?php echo $career_page_url; ?>;
</script>

<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/career_cards.js?v=<?php echo $assetversion; ?>"></script>

<script>
  new Vue({
    el: '#gmk-timeline-app',
    vuetify: new Vuetify({ theme: { themes: { light: { primary: '#6366F1' } } } }),
    data: { careerPageUrl: careerPageUrl },
  });
</script>
<?php
echo $OUTPUT->footer();
