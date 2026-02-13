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
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');
$plugin_name = 'local_grupomakro_core';
require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/curriculum.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('academic_record', $plugin_name));
//$PAGE->set_heading(get_string('academic_panel', $plugin_name));
$PAGE->set_pagelayout('base');

$id = required_param('lp_id', PARAM_TEXT);

$strings = new stdClass();
$strings->delete_available = get_string('delete_available',$plugin_name);
$strings->selection_schedules = get_string('selection_schedules',$plugin_name);
$strings->search = get_string('search',$plugin_name);
$strings->schedules = get_string('schedules',$plugin_name);
$strings->nodata = get_string('nodata', $plugin_name);
$strings->course = get_string('course', $plugin_name);
$strings->item_class = get_string('class', $plugin_name);
$strings->users = get_string('users', $plugin_name);
$strings->actions = get_string('actions', $plugin_name);
$strings->prerequisites = get_string('prerequisites', $plugin_name);
$strings->hours = get_string('hours', $plugin_name);

$strings = json_encode($strings);

$token = get_logged_user_token();
$themeToken = get_theme_token();

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="gmk-app">
    <v-app class="transparent">
      <v-main>
        <div>
          <curriculum></curriculum>
        </div>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <style>
    .theme--light.v-application,
    .theme--dark.v-application{
      background: transparent !important;
    }
    
    .curriculum-card{
      border-color: #404650 !important
      background: var(--v-background-base) !important
    }
    .v-card-border-w{
      border-width: 1px 1px 2px !important
    }
    .period-title:before {
      border-bottom: 1px solid #c4c4c4;
      content: " ";
      display: block;
      height: 15px;
      width: 100%;
      position: absolute;
    }
    .period-title span{
      border: 1px solid #c4c4c4;
      border-radius: 15px;
      left: 50%;
      margin: 0 auto;
      padding: 0.25rem 0.75rem;
      position: absolute;
      -webkit-transform: translate(-50%);
      -moz-transform: translate(-50%);
      -o-transform: translate(-50%);
      -ms-transform: translate(-50%);
      transform: translate(-50%);
      z-index: 20;
    }
    [data-preset="default"] .period-title span{
      background-color: #f8f9fa;
    }
    [data-preset="dark"] .period-title span{
      background-color: #13131a;
    }
    .course-content{
      grid-template-columns: repeat(4,1fr);
      grid-gap: 1rem;
      display: grid;
      margin-top: 1.5rem;
      padding: 2rem 0;
    }
    #page.drawers .main-inner{
      margin-top: 0px !important;
    }
    
    @media only screen and (max-width: 1366px){
      .course-content {
        grid-template-columns: repeat(3,1fr);
      }
    }
    @media only screen and (max-width: 992px){
      .course-content {
        grid-template-columns: repeat(3,1fr);
      }
      #page.drawers{
        padding-left: 0px !important;
        padding-right: 0px !important;
      }
    }
    @media only screen and (max-width: 768px){
      .course-content {
        grid-template-columns: repeat(2,1fr);
      }
    }
    @media only screen and (max-width: 600px){
      .course-content {
        grid-template-columns: repeat(1,1fr);
      }
    }
  </style>
  
  <script>
    var strings = $strings;
    var userToken = $token;
    var themeToken = $themeToken || null;
  </script>
  
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/curriculum.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();
