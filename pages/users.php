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
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';

require_login();


$id = required_param('courseid', PARAM_INT);
$periodsid = required_param('periodsid', PARAM_ALPHANUM);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/users.php', ['courseid' => $id, 'periodsid' => $periodsid]));
$context = context_system::instance();
$PAGE->set_context($context);
if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('schedule_panel', $plugin_name), new moodle_url('/local/grupomakro_core/pages/schedulepanel.php'));
    $PAGE->navbar->add(get_string('scheduleapproval', $plugin_name), new moodle_url('/local/grupomakro_core/pages/scheduleapproval.php', ['id' => $id, 'periodsid' => $periodsid]));
}
$PAGE->navbar->add(
    get_string('users', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/users.php', ['courseid' => $id, 'periodsid' => $periodsid])
);
$PAGE->set_title(get_string('users', $plugin_name));
$PAGE->set_heading(get_string('users', $plugin_name));
$PAGE->set_pagelayout('base');

$service = $DB->get_record('external_services', array('shortname' =>'moodle_mobile_app', 'enabled' => 1));
$token = json_encode(external_generate_token_for_current_user($service)->token);

$strings = new stdClass();
$strings->delete_available = get_string('delete_available',$plugin_name);
$strings->remove = get_string('remove',$plugin_name);
$strings->cancel = get_string('cancel',$plugin_name);
$strings->save = get_string('save', $plugin_name);
$strings->close = get_string('close',$plugin_name);
$strings->accept = get_string('accept',$plugin_name);
$strings->users = get_string('users',$plugin_name);
$strings->registered_users = get_string('registered_users',$plugin_name);
$strings->add_schedule = get_string('add_schedule',$plugin_name);
$strings->add_schedules = get_string('add_schedules',$plugin_name);
$strings->waitinglists = get_string('waitinglists',$plugin_name);
$strings->move_to = get_string('move_to',$plugin_name);
$strings->remove = get_string('remove',$plugin_name);
$strings->selectall = get_string('selectall',$plugin_name);
$strings = json_encode($strings);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app class="transparent">
      <v-main class="pt-0">
        <div>
          <users></users>
        </div>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <style>
    .theme--light.v-application{
      background: transparent !important;
    }
    .v-alert--prominent .v-alert__icon {
      align-self: start !important;
    }
    .v-data-table__wrapper{
      padding-top: 10px;
    }
    body#page-local-grupomakro_core-pages-waitingusers #topofscroll{
      height: 100% !important;
    }
    label[for="selectall"]{
      margin-bottom: 0px !important;
    }
    .check-table thead .v-data-table__checkbox.v-simple-checkbox{
      display: none !important;
    }
  </style>
   
  <script>
    var strings = $strings;
    var courseid = $id ;
    var periodsid = $periodsid;
    var userToken = $token;
  </script>
  
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/waitingtable.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/waitingusers.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/users.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/deleteusers.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/incompleteschedules.js'));

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));

echo $OUTPUT->footer();