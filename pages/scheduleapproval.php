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
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$id = required_param('id', PARAM_INT);
$periodsid = required_param('periodsid', PARAM_ALPHANUM);
global $DB;

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/scheduleapproval.php', ['id' => $id, 'periodsid' => $periodsid]));
$context = context_system::instance();
$PAGE->set_context($context);
if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('schedule_panel', $plugin_name), new moodle_url('/local/grupomakro_core/pages/schedulepanel.php'));
}
$PAGE->navbar->add(
    get_string('scheduleapproval', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/scheduleapproval.php', ['id' => $id, 'periodsid' => $periodsid])
);

$PAGE->set_title(get_string('scheduleapproval', $plugin_name));
$PAGE->set_heading(get_string('scheduleapproval', $plugin_name));
$PAGE->set_pagelayout('base');
$id = required_param('id', PARAM_TEXT);


$token = get_logged_user_token();
$themeToken = get_theme_token();

$strings = new stdClass();
$strings->delete_available = get_string('delete_available',$plugin_name);
$strings->remove = get_string('remove',$plugin_name);
$strings->cancel = get_string('cancel',$plugin_name);
$strings->save = get_string('save', $plugin_name);
$strings->close = get_string('close',$plugin_name);
$strings->accept = get_string('accept',$plugin_name);
$strings->schedules = get_string('schedules',$plugin_name);
$strings->waitingusers = get_string('waitingusers',$plugin_name);
$strings->approve_schedules = get_string('approve_schedules',$plugin_name);
$strings->registered_users = get_string('registered_users',$plugin_name);
$strings->waitinglist = get_string('waitinglist',$plugin_name);
$strings->approved = get_string('approved',$plugin_name);
$strings->class_schedule = get_string('class_schedule',$plugin_name);
$strings->class_type = get_string('class_type',$plugin_name);
$strings->quotas_enabled = get_string('quotas_enabled',$plugin_name);
$strings->registered_users = get_string('registered_users',$plugin_name);
$strings->remove = get_string('remove',$plugin_name);
$strings->approve_users = get_string('approve_users',$plugin_name);
$strings->move_to = get_string('move_to',$plugin_name);
$strings->current_location = get_string('current_location',$plugin_name);
$strings->actions = get_string('actions',$plugin_name);
$strings->student = get_string('student',$plugin_name);
$strings->message_approved = get_string('message_approved',$plugin_name);
$strings->maximum_quota_message = get_string('maximum_quota_message',$plugin_name);
$strings->want_to_approve = get_string('want_to_approve',$plugin_name);
$strings->mminimum_quota_message = get_string('mminimum_quota_message',$plugin_name);
$strings->write_reason = get_string('write_reason',$plugin_name);
$strings->users = get_string('users',$plugin_name);
$strings->deleteusersclass = get_string('deleteusersclass',$plugin_name);
$strings->deleteclassMessage = get_string('deleteclassMessage',$plugin_name);
$strings->classschedule = get_string('class',$plugin_name);
$strings->approval_message_title = get_string('approval_message_title',$plugin_name);
$strings->userlist = get_string('userlist',$plugin_name);
$strings->no_users_message = get_string('no_users_message',$plugin_name);
$strings->aproved_message_hinit = get_string('aproved_message_hinit',$plugin_name);
$strings->deletion_message = get_string('deletion_message',$plugin_name);
$strings->deleteusersmessage = get_string('deleteusersmessage',$plugin_name);
$strings = json_encode($strings);

$aproved_img = $CFG->wwwroot.'/local/grupomakro_core/pix/aproved.png';
$aproved_img = json_encode($aproved_img);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="gmk-app">
    <v-app class="transparent">
      <v-main>
        <div>
          <scheduleapproval></scheduleapproval>
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
    .v-application--is-ltr .v-list-item__icon:first-child {
      margin-right: 15px !important;
    }
    .v-alert--prominent .v-alert__icon {
      align-self: start !important;
    }
    .img-aproved{
      position: absolute;
      bottom: 1px;
      right: 1px;
    }
  </style>
   
  <script>
    var strings = $strings;
    var userToken = $token;
    var courseid = $id;
    var aprovedImg = $aproved_img;
    var themeToken = $themeToken || null;
  </script>
  
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/scheduleapproval.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/scheduleApproval/deleteclass.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/scheduleApproval/approveusers.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/scheduleApproval/userslist.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/scheduleApproval/availableschedulesdialog.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/scheduleApproval/schedulevalidationdialog.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/deleteusers.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));

echo $OUTPUT->footer();