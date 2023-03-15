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

$url = new moodle_url('/local/grupomakro_core/pages/classmanagement.php');

echo <<<'EOT'
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<div id="app">
    <v-app>
      <v-main>
        <v-container>
          <div>
            <v-sheet height="64">
              <v-toolbar
                flat
              >
              <v-btn color="primary" dark class="mr-4" href="https://grupomakro-dev.soluttolabs.com/local/grupomakro_core/pages/classmanagement.php" >
                Agregar
              </v-btn>
                <v-btn
                  outlined
                  class="mr-4"
                  color="grey darken-2"
                  @click="setToday"
                >
                  Hoy
                </v-btn>
                <v-btn
                  fab
                  text
                  small
                  color="grey darken-2"
                  @click="prev"
                >
                  <v-icon small>
                    mdi-chevron-left
                  </v-icon>
                </v-btn>
                <v-btn
                  fab
                  text
                  small
                  color="grey darken-2"
                  @click="next"
                >
                  <v-icon small>
                    mdi-chevron-right
                  </v-icon>
                </v-btn>
                <v-toolbar-title v-if="$refs.calendar">
                  {{ $refs.calendar.title }}
                </v-toolbar-title>
                <v-spacer></v-spacer>
                <v-combobox
                  v-model="select"
                  :items="items"
                  label="Instructores"
                  multiple
                  outlined
                  dense
                  hide-details
                  class="mr-2"
                  small-chips
                ></v-combobox>
                <v-menu
                  bottom
                  right
                >
                  <template v-slot:activator="{ on, attrs }">
                    <v-btn
                      outlined
                      color="grey darken-2"
                      v-bind="attrs"
                      v-on="on"
                    >
                      <span>{{ typeToLabel[type] }}</span>
                      <v-icon right>
                        mdi-menu-down
                      </v-icon>
                    </v-btn>
                  </template>
                  <v-list>
                    <v-list-item @click="type = 'day'">
                      <v-list-item-title>Day</v-list-item-title>
                    </v-list-item>
                    <v-list-item @click="type = 'week'">
                      <v-list-item-title>Week</v-list-item-title>
                    </v-list-item>
                    <v-list-item @click="type = 'month'">
                      <v-list-item-title>Month</v-list-item-title>
                    </v-list-item>
                  </v-list>
                </v-menu>
              </v-toolbar>
            </v-sheet>
            <v-sheet height="800">
              <v-calendar
                ref="calendar"
                v-model="focus"
                color="primary"
                :events="events"
                :event-color="getEventColor"
                :type="type"
                category-show-all
                @click:event="showEvent"
                @click:more="viewDay"
                @click:date="viewDay"
                @change="updateRange"
                locale="es"
                :short-weekdays="false"
                event-timed="timed"
              ></v-calendar>
              <v-menu
                v-model="selectedOpen"
                :close-on-content-click="false"
                :activator="selectedElement"
                offset-x
              >
                <v-card
                  color="grey lighten-4"
                  min-width="350px"
                  flat
                  :max-width="type == 'day' ? '500px' : '100%'"
                  
                >
                  <v-toolbar
                    :color="selectedEvent.color"
                    dark
                  >
                    <v-toolbar-title v-html="selectedEvent.name"></v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon>
                      <v-icon>mdi-dots-vertical</v-icon>
                    </v-btn>
                  </v-toolbar>
                  <v-card-text class="d-flex flex-column">
                    <div class="d-flex align-center">
                      <v-avatar
                        size="36px"
                        class="mr-2"
                      >
                        <v-icon>mdi-cast</v-icon>
                      </v-avatar>
                      <span v-html="selectedEvent.details"></span>
                    </div>
                    
                    <div class="d-flex align-center">
                      <v-avatar
                        size="36px"
                        class="mr-2"
                      >
                        <v-icon>mdi-account-circle</v-icon>
                      </v-avatar>
                      <span v-html="selectedEvent.instructor"></span>
                    </div>
                  </v-card-text>
                  <v-card-actions class="d-flex justify-end">
                    <v-btn
                      text
                      color="secondary"
                      @click="selectedOpen = false"
                    >
                      Cancel
                    </v-btn>
                  </v-card-actions>
                </v-card>
              </v-menu>
            </v-sheet>
          </div>
        </v-container>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/vue.js'));
echo $OUTPUT->footer();
