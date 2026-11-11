<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tablero de planificacion por cohortes.
 *
 * Fase 1 (solo lectura): demanda del periodo por asignatura, cohortes, estudiantes, repitentes y
 * casos a revisar. Los pasos de grupos, horarios y publicacion se agregan en fases siguientes.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

require_login();
$context = context_system::instance();
require_capability('local/grupomakro_core:manage_academic_planning', $context);

$periodid = optional_param('periodid', 0, PARAM_INT);
$careerid = optional_param('career_id', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/cohort_planning.php',
    array_filter(['periodid' => $periodid, 'career_id' => $careerid])));
$PAGE->set_context($context);
$PAGE->set_title('Planificación por cohortes');
$PAGE->set_heading('Planificación por cohortes');
$PAGE->set_pagelayout('admin');

$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
$token = get_logged_user_token();
$config = [
    'periodid' => $periodid,
    'careerid' => $careerid,
    'timelineUrl' => (new moodle_url('/local/grupomakro_core/pages/student_timeline.php'))->out(false),
    'careerUrl' => (new moodle_url('/local/grupomakro_core/pages/student_timeline_career.php'))->out(false),
    'profileUrl' => (new moodle_url('/user/profile.php'))->out(false),
];

echo $OUTPUT->header();
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="gmk-cohort-planning-app">
  <v-app class="transparent">
    <v-main>
      <cohort-planning :config="config"></cohort-planning>
    </v-main>
  </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>
<script>
  var userToken = <?php echo $token; ?>;
  var cohortPlanningConfig = <?php echo json_encode($config); ?>;
</script>
<script src="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/js/components/planning/cohort_planning.js?v=<?php echo $assetversion; ?>"></script>
<script>
  new Vue({
    el: '#gmk-cohort-planning-app',
    vuetify: new Vuetify({ theme: { themes: { light: { primary: '#4F46E5' } } } }),
    data: { config: cohortPlanningConfig },
  });
</script>
<?php
echo $OUTPUT->footer();
