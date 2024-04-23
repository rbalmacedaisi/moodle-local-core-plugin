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
 * This page is responsible of managing everything related to the orders.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();

$plugin_name = 'local_grupomakro_core';

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/academiccalendar.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('academiccalendar:academic_calendar_title', $plugin_name));
$PAGE->navbar->add(get_string('academiccalendar:academic_calendar_breadcrumb:academic_panel', $plugin_name), new moodle_url('/local/grupomakro_core/pages/academicpanel.php'));
$PAGE->navbar->add(get_string('academiccalendar:academic_calendar_breadcrumb:academic_calendar', $plugin_name), new moodle_url('/local/grupomakro_core/pages/academiccalendar.php'));

$token = get_logged_user_token();
$themeToken = get_theme_token();

$tableHeaders=[];
$tableHeaders['periods']=new stdClass();
$tableHeaders['periods']->text = get_string('academiccalendar:academic_calendar_table:period_header',$plugin_name);
$tableHeaders['periods']->align = 'start';
$tableHeaders['periods']->value = 'period';
$tableHeaders['periods']->sortable = false;

$tableHeaders['bimesters']=new stdClass();
$tableHeaders['bimesters']->text = get_string('academiccalendar:academic_calendar_table:bimesters_header',$plugin_name);
$tableHeaders['bimesters']->value = 'bimesters';
$tableHeaders['bimesters']->sortable = false;

$tableHeaders['start']=new stdClass();
$tableHeaders['start']->text = get_string('academiccalendar:academic_calendar_table:period_init_header',$plugin_name);
$tableHeaders['start']->value = 'start';
$tableHeaders['start']->sortable = false;

$tableHeaders['end']=new stdClass();
$tableHeaders['end']->text = get_string('academiccalendar:academic_calendar_table:period_end_header',$plugin_name);
$tableHeaders['end']->value = 'end';
$tableHeaders['end']->sortable = false;

$tableHeaders['induction']=new stdClass();
$tableHeaders['induction']->text = get_string('academiccalendar:academic_calendar_table:induction_header',$plugin_name);
$tableHeaders['induction']->value = 'induction';
$tableHeaders['induction']->sortable = false;

$tableHeaders['finalExamRange']=new stdClass();
$tableHeaders['finalExamRange']->text = get_string('academiccalendar:academic_calendar_table:final_exam_header',$plugin_name);
$tableHeaders['finalExamRange']->value = 'finalExamRange';
$tableHeaders['finalExamRange']->sortable = false;

$tableHeaders['loadnotesandclosesubjects']=new stdClass();
$tableHeaders['loadnotesandclosesubjects']->text = get_string('academiccalendar:academic_calendar_table:loadnotesandclosesubjects_header',$plugin_name);
$tableHeaders['loadnotesandclosesubjects']->value = 'loadnotesandclosesubjects';
$tableHeaders['loadnotesandclosesubjects']->sortable = false;

$tableHeaders['delivoflistforrevalbyteach']=new stdClass();
$tableHeaders['delivoflistforrevalbyteach']->text = get_string('academiccalendar:academic_calendar_table:delivoflistforrevalbyteach_header',$plugin_name);
$tableHeaders['delivoflistforrevalbyteach']->value = 'delivoflistforrevalbyteach';
$tableHeaders['delivoflistforrevalbyteach']->sortable = false;

$tableHeaders['notiftostudforrevalidations']=new stdClass();
$tableHeaders['notiftostudforrevalidations']->text = get_string('academiccalendar:academic_calendar_table:notiftostudforrevalidations_header',$plugin_name);
$tableHeaders['notiftostudforrevalidations']->value = 'notiftostudforrevalidations';
$tableHeaders['notiftostudforrevalidations']->sortable = false;

$tableHeaders['deadlforpayofrevalidations']=new stdClass();
$tableHeaders['deadlforpayofrevalidations']->text = get_string('academiccalendar:academic_calendar_table:deadlforpayofrevalidations_header',$plugin_name);
$tableHeaders['deadlforpayofrevalidations']->value = 'deadlforpayofrevalidations';
$tableHeaders['deadlforpayofrevalidations']->sortable = false;

$tableHeaders['revalidationprocess']=new stdClass();
$tableHeaders['revalidationprocess']->text = get_string('academiccalendar:academic_calendar_table:revalidationprocess_header',$plugin_name);
$tableHeaders['revalidationprocess']->value = 'revalidationprocess';
$tableHeaders['revalidationprocess']->sortable = false;

$tableHeaders['registrationRange']=new stdClass();
$tableHeaders['registrationRange']->text = get_string('academiccalendar:academic_calendar_table:registration_header',$plugin_name);
$tableHeaders['registrationRange']->value = 'registrationRange';
$tableHeaders['registrationRange']->sortable = false;

$tableHeaders['graduationdate']=new stdClass();
$tableHeaders['graduationdate']->text = get_string('academiccalendar:academic_calendar_table:graduationdate_header',$plugin_name);
$tableHeaders['graduationdate']->value = 'graduationdate';
$tableHeaders['graduationdate']->sortable = false;

$tableHeaders = json_encode(array_values($tableHeaders));

$strings = new stdClass();
$strings->academic_calendar_title = get_string('academiccalendar:academic_calendar_title',$plugin_name);
$strings->upload_calendar_label = get_string('academiccalendar:upload_calendar_label',$plugin_name);
$strings->calendar_table_title = get_string('academiccalendar:calendar_table_title',$plugin_name);
$strings->upload_modal_title = get_string('academiccalendar:upload_modal_title',$plugin_name);
$strings->cancel = get_string('cancel',$plugin_name);
$strings->accept = get_string('accept',$plugin_name);
$strings = json_encode($strings);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app class="transparent">
      <v-main>
        <div>
            <academiccalendartable></academiccalendartable>
        </div>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <style>
    .tiemfield-from.v-text-field--filled.v-input--dense.v-text-field--single-line>.v-input__control>.v-input__slot{
       background: rgb(84 164 217 / 25%) !important;
    }
    .tiemfield-to.v-text-field--filled.v-input--dense.v-text-field--single-line>.v-input__control>.v-input__slot{
      background: rgb(131 200 117 / 25%) !important;
    }
    .even-item {
      background-color: #71dc7421;
    }
    
    .odd-item {
      background-color: #7199dc21;
    }
    .theme--light.v-application{
      background: transparent !important;
    }
    .startTime .v-input__slot fieldset{
      background-color: #71dc7421;
    }
    .timeEnd .v-input__slot fieldset{
      background-color: #7199dc21;
    }
    .v-select__selections input[type="text"],
    .v-text-field__slot input[type="text"]{
      background: transparent !important;
    }
    .theme--dark.v-application {
      background: transparent;
    }
    .paneltable td:nth-child(4n),
    .paneltable td:nth-child(5n),
    .paneltable td:nth-child(6n){
      display: none !important;
    }
    [data-preset="default"] .calendar-table tbody tr:nth-child(1n){
      background: #E0F2F1 !important;
    }
    [data-preset="default"] .calendar-table tbody tr:nth-child(2n){
      background: #E1F5FE !important;
    }
    [data-preset="default"] .calendar-table tbody tr:nth-child(3n){
      background: #FFF8E1 !important;
    }
    [data-preset="default"] .calendar-table tbody tr:nth-child(4n){
      background: #FBE9E7 !important;
    }
    [data-preset="default"] .calendar-table tbody tr:nth-child(5n){
      background: #E0F2F1 !important;
    }
    [data-preset="default"] .calendar-table tbody tr:nth-child(6n){
      background: #F3E5F5 !important;
    }
    [data-preset="dark"] .calendar-table tbody tr:nth-child(1n){
      background: #182320 !important;
      color: #b1d6b1 !important;
    }
    [data-preset="dark"] .calendar-table tbody tr:nth-child(2n){
      background: #17232d !important;
      color: #acd2e8 !important;
    }
    [data-preset="dark"] .calendar-table tbody tr:nth-child(3n){
      background:#25221c !important;
      color: #f5d08e !important;
    }
    [data-preset="dark"] .calendar-table tbody tr:nth-child(4n){
      background: #281d2b !important;
      color: #ecc1d8 !important;
    }
    [data-preset="dark"] .calendar-table tbody tr:nth-child(5n){
      background: #172327 !important;
      color: #b0d4cd !important;
    }
    [data-preset="dark"] .calendar-table tbody tr:nth-child(6n){
      background: #212030 !important;
      color: #d2c6f7 !important;
    }
   </style>
   
   <script>
    var strings = $strings;
    var userToken = $token || null;
    var themeToken = $themeToken || null;
    var tableHeaders = $tableHeaders;
  </script>
  
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/academiccalendar.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/academicCalendar/uploadcalendarmodal.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/errormodal.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();
