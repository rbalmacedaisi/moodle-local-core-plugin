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
$plugin_name = 'local_grupomakro_core';
require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/schedules.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('schedules', $plugin_name));
$PAGE->set_heading(get_string('schedules', $plugin_name));
$PAGE->set_pagelayout('base');


echo $OUTPUT->header();

echo <<<'EOT'
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<div id="app">
    <v-app>
      <v-main>
        <v-container>
          <div>
            <v-sheet
              tile
              height="54"
              class="d-flex"
            >
              <v-btn
                icon
                class="ma-2"
                @click="$refs.calendar.prev()"
              >
                <v-icon>mdi-chevron-left</v-icon>
              </v-btn>
              <v-select
                v-model="type"
                :items="types"
                dense
                outlined
                hide-details
                class="ma-2"
                label="type"
              ></v-select>
              <v-select
                v-model="mode"
                :items="modes"
                dense
                outlined
                hide-details
                label="event-overlap-mode"
                class="ma-2"
              ></v-select>
              <v-select
                v-model="weekday"
                :items="weekdays"
                dense
                outlined
                hide-details
                label="weekdays"
                class="ma-2"
              ></v-select>
              <v-spacer></v-spacer>
              <v-btn
                icon
                class="ma-2"
                @click="$refs.calendar.next()"
              >
                <v-icon>mdi-chevron-right</v-icon>
              </v-btn>
            </v-sheet>
            <v-sheet height="600">
              <v-calendar
                ref="calendar"
                v-model="value"
                :weekdays="weekday"
                :type="type"
                :events="events"
                :event-overlap-mode="mode"
                :event-overlap-threshold="30"
                :event-color="getEventColor"
                @change="getEvents"
              ></v-calendar>
            </v-sheet>
          </div>
        </v-container>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/vue.js',array('type'=> 'module')));
echo $OUTPUT->footer();
