<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
// Tambien cambia con cada despliegue: fecha del JS/CSS mas reciente del plugin (si no, el navegador usa la version vieja).
$assetfiles = array_merge(glob(__DIR__ . '/../js/components/*/*.js') ?: [], glob(__DIR__ . '/../js/*.js') ?: [], glob(__DIR__ . '/../styles/*.css') ?: []);
$assetversion .= '-' . ($assetfiles ? max(array_map('filemtime', $assetfiles)) : 0);
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
  // Proyeccion del proximo periodo (solo lectura): requiere permiso de planificacion.
  window.gmkCanPlan = <?php echo has_capability('local/grupomakro_core:manage_academic_planning', context_system::instance()) ? 'true' : 'false'; ?>;
</script>

<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/board_engine.js?v=<?php echo $assetversion; ?>"></script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/schedule_board.js?v=<?php echo $assetversion; ?>"></script>
<?php
// Logo de los informes impresos: el mismo del Reporte de Notas (logo del tema); si no hay, el del plugin.
$reportlogo = $OUTPUT->get_logo_url();
if (!$reportlogo) {
    try {
        $th = theme_config::load($CFG->theme);
        if (!empty($th->settings->logo)) {
            $reportlogo = new moodle_url('/theme/' . $CFG->theme . '/pix/static/' . basename($th->settings->logo));
        }
    } catch (\Throwable $e) {
        $reportlogo = null;
    }
}
$reportlogo = $reportlogo ? $reportlogo->out(false) : $CFG->wwwroot . '/local/grupomakro_core/pix/institute-logo.png';
?>
<script>window.gmkReportLogo = <?php echo json_encode($reportlogo); ?>;</script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/board_report.js?v=<?php echo $assetversion; ?>"></script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/period_projection.js?v=<?php echo $assetversion; ?>"></script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/subject_picker.js?v=<?php echo $assetversion; ?>"></script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/timeline/planning_config.js?v=<?php echo $assetversion; ?>"></script>
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
