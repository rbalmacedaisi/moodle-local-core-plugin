/* global wsUrl */
/* global wsStaticParams */
const eventsDaysOffset = {
    month: 7,
    week: 2,
    day: 2
}
window.Vue.component('classschedule', {
    template: `
        <div>
            <v-sheet :dark="dark">
                <v-toolbar
                    flat
                    id="first"
                >
                    <v-btn v-if="isAdmin" color="primary" dark class="mr-4" @click="openLink('classmanagement')">
                        {{strings.add}}
                    </v-btn>
                    <v-btn
                      outlined
                      class="mr-4"
                      color="grey darken-2"
                      @click="focus = today"
                    >
                      {{strings.today}}
                    </v-btn>
                    <v-btn
                        outlined
                        class="mr-4"
                        color="grey darken-2"
                        @click="getEvents"
                    >
                        <v-icon small>
                            mdi-reload
                        </v-icon>
                    </v-btn>
                    <v-btn
                      fab
                      text
                      small
                      color="grey darken-2"
                      @click="$refs.calendar.prev()"
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
                      @click="$refs.calendar.next()"
                    >
                      <v-icon small>
                        mdi-chevron-right
                      </v-icon>
                    </v-btn>
                    
                    <v-toolbar-title v-if="$refs.calendar">
                      {{ $refs.calendar.title }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn
                      outlined
                      class="mr-2"
                      color="grey darken-2"
                      @click="printSchedule"
                    >
                      <v-icon small class="mr-1">mdi-printer</v-icon>
                      PDF
                    </v-btn>
                    <v-btn
                      v-if="isAdmin"
                      color="primary"
                      @click="openLink('availability')"
                    >
                      {{strings.availability}}
                    </v-btn>
                </v-toolbar>
            </v-sheet>
            <v-row class="mb-1 mx-0 align-center" :class="$vuetify.theme.isDark ? 'mt-1': ''">
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
                            <span>{{ typeToLabel[calendarType] }}</span>
                            <v-icon right>
                                mdi-menu-down
                            </v-icon>
                        </v-btn>
                    </template>
                    
                    <v-list>
                        <v-list-item @click="calendarType = 'day'">
                            <v-list-item-title>{{strings.day}}</v-list-item-title>
                        </v-list-item>
                        <v-list-item @click="calendarType = 'week'">
                            <v-list-item-title>{{strings.week}}</v-list-item-title>
                        </v-list-item>
                        <v-list-item @click="calendarType = 'month'">
                            <v-list-item-title>{{strings.month}}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-menu>
                <v-spacer></v-spacer>
                <v-col v-if="isAdmin" cols="12" sm="3" md="3" lg="2" class="px-1">
                    <v-combobox
                      v-model="selectedInstructors"
                      :items="instructors"
                      :label="strings.instructors"
                      outlined
                      dense
                      hide-details
                      clearable
                      multiple
                    ></v-combobox>
                </v-col>
                <v-col cols="12" sm="3" md="3" :lg="!isAdmin ? '3' : '2'" class="px-1">
                    <v-combobox
                      v-model="selectedCourses"
                      :items="coursesWithCreatedClasses"
                      :label="strings.scheduledclasses"
                      multiple
                      outlined
                      dense
                      hide-details
                      small-chips
                      clearable
                    ></v-combobox>
                </v-col>
            </v-row>
            
            <v-sheet height="800">
                <v-calendar
                    v-model="focus"
                    ref="calendar"
                    color="primary"
                    locale="es-ES"
                    :short-weekdays="false"
                    :events="filteredEvents"
                    :type="calendarType"
                    event-overlap-mode="stack"
                    first-time="06:00"
                    interval-count="18"
                    interval-minutes="60"
                    :weekdays="weekdays"
                    @click:event="showEvent"
                    @click:more="viewDay"
                    @click:date="viewDay"
                    @change="getEvents"
                >
                    <template v-slot:event="{ event }">
                        <div class="v-event-draggable" style="padding:2px 4px;line-height:1.3;overflow:hidden">
                            <strong style="font-size:11px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ event.name }}</strong>
                            <span style="font-size:10px;display:block;opacity:0.9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ formatEventTime(event.start) }} – {{ formatEventTime(event.end) }}</span>
                            <span v-if="event.instructor" style="font-size:10px;display:block;opacity:0.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">👤 {{ event.instructor }}</span>
                            <span v-if="event.room" style="font-size:10px;display:block;opacity:0.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">📍 {{ event.room }}</span>
                        </div>
                    </template>
                    <template v-slot:day-body="{ date, week }">
                        <div
                        class="v-current-time"
                        :class="{ first: date === week[0].date }"
                        :style="{ top: nowY }"
                        ></div>
                  </template>
                </v-calendar>
                <v-menu
                    v-model="showSelectedEvent"
                    :close-on-content-click="false"
                    :activator="selectedElement"
                    :max-width="calendarType === 'day' ? '300px' : '100%'"
                >
                    <v-card
                      min-width="300px"
                      flat
                      :max-width="calendarType === 'day' ? '300px' : '100%'"
                      
                    >
                        <v-toolbar
                            :color="selectedEvent.color"
                            dark
                        >
                            <v-toolbar-title class="pl-2" v-html="selectedEvent.name"></v-toolbar-title>
                            <v-spacer></v-spacer>
                            <v-menu
                              v-if="isAdmin"
                              content-class="menuitems"
                              bottom
                              min-width="180"
                              rounded
                              offset-y
                              left
                              close-on-click
                            >
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                      dark
                                      icon
                                      v-bind="attrs"
                                      v-on="on"
                                    >
                                        <v-icon>mdi-dots-vertical</v-icon>
                                    </v-btn>
                                </template>
                                <v-card width="180">
                                    <v-list dense>
                                      <v-list-item-group v-model="listItem">
                                        <v-list-item @click="editEvent(selectedEvent)" >
                                          <v-list-item-icon class="mr-2">
                                            <v-icon >mdi-calendar-edit</v-icon>
                                          </v-list-item-icon>
                                          <v-list-item-content>
                                            <v-list-item-title>{{strings.edit}}</v-list-item-title>
                                          </v-list-item-content>
                                        </v-list-item>
                                        <v-list-item @click="openCopyDialog(selectedEvent)" >
                                          <v-list-item-icon class="mr-2">
                                            <v-icon >mdi-content-copy</v-icon>
                                          </v-list-item-icon>
                                          <v-list-item-content>
                                            <v-list-item-title>{{strings.copySession}}</v-list-item-title>
                                          </v-list-item-content>
                                        </v-list-item>
                                        <v-list-item @click="openDeleteDialog(selectedEvent)" >
                                          <v-list-item-icon class="mr-2">
                                            <v-icon color="error">mdi-delete</v-icon>
                                          </v-list-item-icon>
                                          <v-list-item-content>
                                            <v-list-item-title class="error--text">{{strings.deleteSession}}</v-list-item-title>
                                          </v-list-item-content>
                                        </v-list-item>
                                      </v-list-item-group>
                                    </v-list>
                                </v-card>
                            </v-menu>
                    
                            <v-dialog
                              v-model="dialog"
                              width="500"
                            >
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                      v-if="!isAdmin"
                                      color="error"
                                      x-small
                                      v-bind="attrs"
                                      v-on="on"
                                    >
                                      {{strings.reschedule}}
                                    </v-btn>
                                </template>
                        
                                <v-card>
                                    <v-card-title class="text-h5 white--text" :style="{ background: selectedEvent.color }" >
                                      {{selectedEvent.name}}
                                    </v-card-title>
                        
                                    <v-card-text>
                                        <v-form ref="reschedulingform" v-model="valid">
                                            <v-row class="pt-3 mt-3">
                                                <v-col cols="12" class="py-0">
                                                    <v-select
                                                      v-model="causes"
                                                      :items="rescheduleCauses"
                                                      small-chips
                                                      :label="strings.causes_rescheduling"
                                                      multiple
                                                      :menu-props="{ bottom: true, offsetY: true }"
                                                      dense
                                                      :color="selectedEvent.color"
                                                      outlined
                                                      required
                                                      :rules="[v => !!v && v.length > 0 || strings.field_required]"
                                                    ></v-select>
                                                </v-col>
                                                <v-col cols="12" class="py-0">
                                                    <v-menu
                                                      ref="menu"
                                                      v-model="menu"
                                                      :close-on-content-click="false"
                                                      :return-value.sync="date"
                                                      transition="scale-transition"
                                                      offset-y
                                                      min-width="auto"
                                                    >
                                                        <template v-slot:activator="{ on, attrs }">
                                                            <v-text-field
                                                              v-model="date"
                                                              :label="strings.select_possible_date"
                                                              append-icon="mdi-calendar"
                                                              readonly
                                                              v-bind="attrs"
                                                              v-on="on"
                                                              :color="selectedEvent.color"
                                                              outlined
                                                              dense
                                                              required
                                                              :rules="[v => !!v || strings.field_required]"
                                                          ></v-text-field>
                                                        </template>
                                                        <v-date-picker
                                                          v-model="date"
                                                          no-title
                                                          scrollable
                                                        >
                                                            <v-spacer></v-spacer>
                                                            <v-btn
                                                              text
                                                              :color="selectedEvent.color"
                                                              @click="menu = false"
                                                            >
                                                                {{strings.cancel}}
                                                            </v-btn>
                                                            <v-btn
                                                              text
                                                              :color="selectedEvent.color"
                                                              @click="$refs.menu.save(date)"
                                                            >
                                                                OK
                                                            </v-btn>
                                                        </v-date-picker>
                                                    </v-menu>
                                                </v-col>
                                                <v-col clos="12" class="py-0">
                                                    <v-menu
                                                        ref="menu2"
                                                        v-model="menu2"
                                                        :close-on-content-click="false"
                                                        :nudge-right="40"
                                                        :return-value.sync="time"
                                                        transition="scale-transition"
                                                        offset-y
                                                        max-width="290px"
                                                        min-width="290px"
                                                    >
                                                        <template v-slot:activator="{ on, attrs }">
                                                            <v-text-field
                                                              v-model="time"
                                                              :label="strings.new_class_time"
                                                              append-icon="mdi-clock-time-four-outline"
                                                              readonly
                                                              v-bind="attrs"
                                                              v-on="on"
                                                              :color="selectedEvent.color"
                                                              outlined
                                                              dense
                                                              required
                                                              :rules="[v => !!v || strings.field_required]"
                                                            ></v-text-field>
                                                        </template>
                                                        <v-time-picker
                                                          v-if="menu2"
                                                          v-model="time"
                                                          full-width
                                                          :color="selectedEvent.color"
                                                          @click:minute="$refs.menu2.save(time)"
                                                        ></v-time-picker>
                                                    </v-menu>
                                                </v-col>
                                            </v-row>
                                        </v-form>
                                        <v-alert
                                          dense
                                          outlined
                                          type="error"
                                          v-show="rescheduleError"
                                        >
                                          {{rescheduleError}}
                                        </v-alert>
                                    </v-card-text>
                        
                                    <v-divider></v-divider>
                        
                                    <v-card-actions>
                                        <v-spacer></v-spacer>
                                        <v-btn
                                            small
                                            @click="dialog = false"
                                            class="rounded"
                                            text
                                            color="secondary"
                                        >
                                            {{strings.cancel}}
                                        </v-btn>

                                        <v-btn
                                          small
                                          @click="sendSolit(selectedEvent)"
                                          class="rounded"
                                          text
                                          color="secondary"
                                        >
                                            {{strings.accept}}
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog
                              v-model="copyDialog"
                              width="640"
                            >
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                      v-if="!isAdmin"
                                      color="primary"
                                      x-small
                                      dark
                                      class="ml-2"
                                      v-bind="attrs"
                                      v-on="on"
                                      @click="openCopyDialog(selectedEvent)"
                                    >
                                      {{strings.copySession}}
                                    </v-btn>
                                </template>

                                <v-card>
                                    <v-card-title class="text-h6 white--text" :style="{ background: selectedEvent.color }">
                                      {{strings.copySessionTitle}}: {{selectedEvent.name}}
                                    </v-card-title>
                                    <v-card-text class="pt-4">
                                      <div class="mb-3">
                                        <strong>{{strings.sourceSession}}:</strong>
                                        {{selectedEvent.start ? selectedEvent.start.split(' ')[0] : ''}}
                                        {{selectedEvent.hour}}
                                      </div>
                                      <div class="mb-2 text-caption grey--text text--darken-1">
                                        {{strings.copySessionHelp}}
                                      </div>

                                      <v-form ref="copyForm" v-model="copyValid">
                                        <div v-for="(row, idx) in copyRows" :key="idx" class="mb-3 pa-2 grey lighten-4 rounded">
                                          <v-row dense align="center">
                                            <v-col cols="12" md="5">
                                              <v-menu
                                                :ref="'copyDateMenu' + idx"
                                                v-model="row.dateMenu"
                                                :close-on-content-click="false"
                                                :return-value.sync="row.date"
                                                transition="scale-transition"
                                                offset-y
                                                min-width="auto"
                                              >
                                                <template v-slot:activator="{ on, attrs }">
                                                  <v-text-field
                                                    v-model="row.date"
                                                    :label="strings.targetDate"
                                                    append-icon="mdi-calendar"
                                                    readonly
                                                    v-bind="attrs"
                                                    v-on="on"
                                                    outlined
                                                    dense
                                                    required
                                                    :rules="[v => !!v || strings.field_required]"
                                                  ></v-text-field>
                                                </template>
                                                <v-date-picker
                                                  v-model="row.date"
                                                  no-title
                                                  scrollable
                                                  :min="today"
                                                >
                                                  <v-spacer></v-spacer>
                                                  <v-btn text :color="selectedEvent.color" @click="row.dateMenu = false">
                                                    {{strings.cancel}}
                                                  </v-btn>
                                                  <v-btn text :color="selectedEvent.color" @click="$refs['copyDateMenu' + idx][0].save(row.date)">
                                                    OK
                                                  </v-btn>
                                                </v-date-picker>
                                              </v-menu>
                                            </v-col>
                                            <v-col cols="5" md="3">
                                              <v-text-field
                                                v-model="row.initTime"
                                                :label="strings.targetInitTime"
                                                type="time"
                                                outlined
                                                dense
                                                required
                                                :rules="[v => !!v || strings.field_required]"
                                              ></v-text-field>
                                            </v-col>
                                            <v-col cols="5" md="3">
                                              <v-text-field
                                                v-model="row.endTime"
                                                :label="strings.targetEndTime"
                                                type="time"
                                                outlined
                                                dense
                                                required
                                                :rules="[v => !!v || strings.field_required]"
                                              ></v-text-field>
                                            </v-col>
                                            <v-col cols="2" md="1" class="d-flex align-center justify-center">
                                              <v-btn
                                                icon
                                                small
                                                :disabled="copyRows.length <= 1"
                                                @click="removeCopyRow(idx)"
                                              >
                                                <v-icon>mdi-close</v-icon>
                                              </v-btn>
                                            </v-col>
                                          </v-row>
                                        </div>
                                      </v-form>

                                      <v-btn
                                        outlined
                                        color="primary"
                                        small
                                        :disabled="copyRows.length >= 20"
                                        @click="addCopyRow"
                                      >
                                        <v-icon small left>mdi-plus</v-icon>
                                        {{strings.addDate}}
                                      </v-btn>
                                      <span v-if="copyRows.length >= 20" class="ml-3 caption red--text">
                                        {{strings.maxDatesReached}}
                                      </span>

                                      <v-alert
                                        v-if="copyError"
                                        dense
                                        outlined
                                        type="error"
                                        class="mt-3"
                                      >
                                        {{copyError}}
                                      </v-alert>

                                      <v-alert
                                        v-if="copyConflicts && Object.keys(copyConflicts).length"
                                        dense
                                        outlined
                                        type="warning"
                                        class="mt-3"
                                      >
                                        <div class="font-weight-bold mb-1">{{strings.copyConflictsTitle}}:</div>
                                        <div v-for="(msgs, date) in copyConflicts" :key="date" class="mb-1">
                                          <strong>{{date}}:</strong>
                                          <ul class="ml-3">
                                            <li v-for="(m, i) in msgs" :key="i">{{m}}</li>
                                          </ul>
                                        </div>
                                      </v-alert>
                                      <v-alert
                                        v-else-if="copyVerified"
                                        dense
                                        outlined
                                        type="success"
                                        class="mt-3"
                                      >
                                        {{strings.copyNoConflicts}}
                                      </v-alert>
                                    </v-card-text>

                                    <v-divider></v-divider>

                                    <v-card-actions>
                                      <v-spacer></v-spacer>
                                      <v-btn
                                        small
                                        @click="copyDialog = false"
                                        class="rounded"
                                        text
                                        color="secondary"
                                      >
                                        {{strings.cancel}}
                                      </v-btn>
                                      <v-btn
                                        small
                                        :loading="copyLoading"
                                        @click="verifyCopyConflicts"
                                        class="rounded"
                                        text
                                        color="info"
                                      >
                                        {{strings.verifyConflicts}}
                                      </v-btn>
                                      <v-btn
                                        v-if="copyConflicts && Object.keys(copyConflicts).length"
                                        small
                                        :loading="copyLoading"
                                        @click="sendCopy(true)"
                                        class="rounded"
                                        text
                                        color="warning"
                                      >
                                        {{strings.forceCopy}}
                                      </v-btn>
                                      <v-btn
                                        v-else
                                        small
                                        :loading="copyLoading"
                                        @click="sendCopy(false)"
                                        class="rounded"
                                        text
                                        color="secondary"
                                      >
                                        {{strings.accept}}
                                      </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>
                        </v-toolbar>
                    
                        <v-card-text class="d-flex flex-column">
                            <div class="d-flex align-center">
                                <v-avatar
                                    size="36px"
                                    class="mr-2"
                                >
                                    <v-icon v-if="selectedEvent.details == 'Virtual'">mdi-cast</v-icon>
                                    <v-icon v-else >mdi-account-group</v-icon>
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
                            <div v-if="selectedEvent.room" class="d-flex align-center">
                                <v-avatar size="36px" class="mr-2">
                                    <v-icon>mdi-map-marker</v-icon>
                                </v-avatar>
                                <span v-html="selectedEvent.room"></span>
                            </div>
                            <div class="d-flex align-center">
                                <v-avatar
                                 size="36px"
                                 class="mr-2"
                                >
                                    <v-icon>mdi-clock-time-eight-outline</v-icon>
                                </v-avatar>
                                <span v-html="selectedEvent.hour"></span>
                            </div>
                            <div class="d-flex align-center">
                                <v-avatar
                                 size="36px"
                                 class="mr-2"
                                >
                                    <v-icon>mdi-calendar-cursor</v-icon>
                                </v-avatar>
                                <span v-html="selectedEvent.days"></span>
                            </div>
                            <div v-if="!isAdmin">
                                <div v-if="selectedEvent.details == 'Virtual' || selectedEvent.details == 'Mixta'" class="d-flex align-center">
                                    <v-avatar
                                     size="36px"
                                     class="mr-2"
                                    >
                                        <v-icon>mdi-desktop-mac</v-icon>
                                    </v-avatar>
                                    <v-btn 
                                       text 
                                       small 
                                       :color="selectedEvent.color" 
                                       :href="selectedEvent.bigBlueButtonActivityUrl"
                                        class="text-capitalize"
                                    >
                                      Aula Virtual
                                    </v-btn>
                                </div>
                                
                                <div v-if="selectedEvent.details == 'Presencial' || selectedEvent.details == 'Mixta'" class="d-flex align-center">
                                    <v-avatar
                                     size="36px"
                                     class="mr-2"
                                    >
                                        <v-icon>mdi-link</v-icon>
                                    </v-avatar>
                                    <v-btn 
                                       text 
                                       small 
                                       :color="selectedEvent.color" 
                                       :href="selectedEvent.attendanceActivityUrl"
                                        class="text-capitalize"
                                    >
                                      {{strings.activity}}
                                    </v-btn>
                                </div>
                            </div>
                            
                        </v-card-text>
                        <v-card-actions class="d-flex justify-end">
                            <v-btn
                              text
                              color="secondary"
                              @click="showSelectedEvent = false"
                            >
                              {{strings.close}}
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-menu>
                <v-overlay :value="fetchingEvents">
                    <v-progress-circular
                        color="primary"
                        indeterminate
                        size="64"
                    ></v-progress-circular>
                </v-overlay>
            </v-sheet>
            <eventdialog v-if="dialogconfirm" @hiden-dialog="hidenDialog"></eventdialog>

            <v-dialog v-model="copySuccessDialog" max-width="420">
                <v-card>
                    <v-card-title class="text-h6 success white--text">
                        <v-icon left dark>mdi-check-circle</v-icon>
                        {{strings.copySuccessTitle}}
                    </v-card-title>
                    <v-card-text class="pt-4">
                        {{strings.copySuccess.replace('{$a}', copySuccessCount)}}
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text color="primary" @click="copySuccessDialog = false">{{strings.accept}}</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <v-dialog v-model="deleteSuccessDialog" max-width="420">
                <v-card>
                    <v-card-title class="text-h6 success white--text">
                        <v-icon left dark>mdi-check-circle</v-icon>
                        {{strings.deleteSessionTitle}}
                    </v-card-title>
                    <v-card-text class="pt-4">
                        {{strings.deleteSuccess}}
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text color="primary" @click="deleteSuccessDialog = false">{{strings.accept}}</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <v-dialog v-model="deleteDialog" max-width="520" persistent>
                <v-card>
                    <v-card-title class="text-h6 error white--text">
                        <v-icon left dark>mdi-delete-alert</v-icon>
                        {{strings.deleteSessionTitle}}
                    </v-card-title>
                    <v-card-text class="pt-4">
                        <div class="mb-2">
                            <strong>{{selectedEvent.start ? selectedEvent.start.split(' ')[0] : ''}}</strong>
                            {{selectedEvent.hour}}
                        </div>
                        <div class="mb-3">
                            {{strings.deleteSessionConfirm}}
                        </div>
                        <v-alert
                            v-if="deleteHasBBB"
                            dense
                            outlined
                            type="warning"
                            class="mb-3"
                        >
                            {{strings.deleteSessionHasBBB}}
                        </v-alert>
                        <v-alert
                            v-else
                            dense
                            outlined
                            type="info"
                            class="mb-3"
                        >
                            {{strings.deleteSessionNoBBB}}
                        </v-alert>
                        <v-alert
                            v-if="deleteLogCount > 0"
                            dense
                            outlined
                            type="error"
                            class="mb-3"
                        >
                            {{strings.deleteSessionHasLogs.replace('{$a}', deleteLogCount)}}
                            <v-checkbox
                                v-model="deleteForce"
                                :label="strings.deleteSessionForceLabel"
                                color="error"
                                dense
                                class="mt-1"
                            ></v-checkbox>
                        </v-alert>
                        <v-alert v-if="deleteError" dense outlined type="error">
                            {{deleteError}}
                        </v-alert>
                    </v-card-text>
                    <v-divider></v-divider>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn small text @click="deleteDialog = false" :disabled="deleteLoading">
                            {{strings.cancel}}
                        </v-btn>
                        <v-btn
                            small
                            :loading="deleteLoading"
                            :disabled="deleteLogCount > 0 && !deleteForce"
                            color="error"
                            @click="sendDelete"
                        >
                            {{strings.deleteSession}}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `,
    data() {
        return {
            today: new Date().toISOString().substr(0, 10),
            focus: new Date().toISOString().substr(0, 10),
            start: null,
            end: null,
            mode: 'column',
            dialog: false,
            selectedInstructors: [],
            selectedCourses: [],
            dark: false,
            listItem: '',
            dialogconfirm: false,
            reschedulemodal: false,
            ready: false,

            selectedEvent: {},
            selectedElement: null,
            showSelectedEvent: false,
            strings: window.strings,
            value: '',
            causes: [],
            date: (new Date(Date.now() - (new Date()).getTimezoneOffset() * 60000)).toISOString().substr(0, 10),
            menu: false,
            startTime: '',
            time: null,
            menu2: false,
            selectedcompetences: [],
            competences: [],
            valid: false,
            rescheduleError: undefined,

            // Copy session state
            copyDialog: false,
            copyLoading: false,
            copyVerified: false,
            copyError: '',
            copyConflicts: null,
            copyRows: [
                { date: (new Date(Date.now() - (new Date()).getTimezoneOffset() * 60000)).toISOString().substr(0, 10), initTime: '', endTime: '', dateMenu: false }
            ],
            copyValid: false,
            copySuccessDialog: false,
            copySuccessCount: 0,

            // Delete session state
            deleteDialog: false,
            deleteLoading: false,
            deleteError: '',
            deleteHasBBB: false,
            deleteLogCount: 0,
            deleteForce: false,
            deleteSuccessDialog: false,

            weekdays: [1, 2, 3, 4, 5, 6, 0],
            events: [],
            instructors: [],
            coursesWithCreatedClasses: window.coursesWithCreatedClasses,
            isAdmin: window.userRole === 'admin',
            userId: window.userId,
            fetchingEvents: false,
            typeToLabel: {
                month: window.strings.month,
                week: window.strings.week,
                day: window.strings.day,
            },
            calendarType: 'week',
            rescheduleCauses: window.rescheduleCauses.map(cause => ({ text: cause.causename, id: cause.id, value: cause.id }))
        }
    },
    props: {},
    watch: {
        rescheduleError: function handler(newVal, oldVal) {
            if (newVal) {
                setTimeout(() => this.rescheduleError = undefined, 6000)
            }
        }
    },
    created() {
    },
    mounted() {
        this.$refs.calendar.checkChange();
        this.ready = true
        this.getEvents();
        this.fetchInstructors();
        this.scrollToTime()
        this.updateTime()
    },
    methods: {
        // This method makes an HTTP GET request to retrieve calendar events from the Moodle server. 
        // The received data is processed and relevant information is extracted from each event, which is added to the events array. 
        printSchedule() {
            const events = (this.filteredEvents || []).slice().sort((a, b) => new Date(a.start) - new Date(b.start));
            const dayNames = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
            const monthNames = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            const byDate = {};
            events.forEach(e => {
                const d = e.start.substring(0, 10);
                if (!byDate[d]) byDate[d] = [];
                byDate[d].push(e);
            });
            let rows = '';
            Object.entries(byDate).forEach(([date, evts]) => {
                const d = new Date(date + 'T00:00:00');
                const label = dayNames[d.getDay()] + ', ' + d.getDate() + ' de ' + monthNames[d.getMonth()] + ' de ' + d.getFullYear();
                rows += `<tr><td colspan="5" style="background:#1e3a5f;color:#fff;font-weight:700;padding:7px 12px;font-size:12px">${label}</td></tr>`;
                evts.forEach(e => {
                    const typeBg = e.details === 'Virtual' ? '#dbeafe' : e.details === 'Presencial' ? '#dcfce7' : '#f3e8ff';
                    const typeFg = e.details === 'Virtual' ? '#1e40af' : e.details === 'Presencial' ? '#166534' : '#6d28d9';
                    rows += `<tr>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#1e293b">${e.name || ''}</td>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;color:#475569">${e.instructor || '—'}</td>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;color:#475569">${e.room || '—'}</td>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;white-space:nowrap;color:#475569">${this.formatEventTime(e.start)} – ${this.formatEventTime(e.end)}</td>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0"><span style="background:${typeBg};color:${typeFg};border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600">${e.details || ''}</span></td>
                    </tr>`;
                });
            });
            const generated = new Date().toLocaleDateString('es-ES', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
            const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Horario de Clases</title>
            <style>body{font-family:Arial,sans-serif;font-size:12px;margin:20px;color:#1e293b}
            h1{font-size:17px;margin-bottom:2px}p{color:#64748b;font-size:11px;margin-bottom:14px}
            table{width:100%;border-collapse:collapse}
            th{background:#1e3a5f;color:#fff;padding:7px 10px;text-align:left;font-size:12px}
            tr:nth-child(even){background:#f8fafc}
            @media print{@page{size:A4 landscape;margin:12mm}}</style></head>
            <body><h1>Horario de Clases</h1><p>Generado el ${generated}</p>
            <table><thead><tr><th>Asignatura</th><th>Docente</th><th>Aula</th><th>Horario</th><th>Modalidad</th></tr></thead>
            <tbody>${rows}</tbody></table></body></html>`;
            const win = window.open('', '_blank', 'width=960,height=720');
            win.document.write(html);
            win.document.close();
            win.onload = () => win.print();
        },
        // This method also handles errors if the request fails.
        async getEvents() {
            // Initialize the events property to an empty array.
            this.events = []
            try {
                this.fetchingEvents = true;
                const { data } = await window.axios.get(wsUrl, { params: this.getEventsParameters })
                if (data.status === -1) throw data.message;
                this.events = JSON.parse(data.events)
            } catch (error) {
                this.events = []
                console.error(error);
            } finally {
                this.fetchingEvents = false;
            }
            return;
        },
        async fetchInstructors() {
            if (!this.isAdmin) {
                return;
            }
            try {
                const url = window.location.origin + '/local/grupomakro_core/ajax.php';
                const { data } = await window.axios.get(url, {
                    params: {
                        action: 'local_grupomakro_get_instructors_with_disponibility',
                    },
                });
                if (data && data.status === 'success' && Array.isArray(data.data)) {
                    this.instructors = data.data;
                }
            } catch (error) {
                console.error('fetchInstructors failed', error);
            }
        },
        // This method updates the calendar view to display a specific day.
        viewDay({ date }) {
            this.focus = date
            this.calendarType = 'day'
        },
        // This method sets the calendar view to the current day.
        setToday() {
            this.focus = this.today
        },
        // This method returns the color of a given event.
        getEventColor(event) {
            return event.color
        },
        // This method displays information about a specific event when it is clicked by the user.
        showEvent({ nativeEvent, event }) {
            const open = () => {
                this.selectedEvent = event
                this.selectedElement = nativeEvent.target
                setTimeout(() => this.showSelectedEvent = true, 10)
            }

            if (this.showSelectedEvent) {
                this.showSelectedEvent = false
                setTimeout(open, 10)
            } else {
                open()
            }

            nativeEvent.stopPropagation()
        },
        // This method updates the start and end dates of the calendar range.
        updateRange({ start, end }) {
            this.start = start
            this.end = end
        },
        // This method hides the current dialog box and displays the confirmation dialog box.
        async sendSolit(event) {
            this.$refs.reschedulingform.validate()
            console.log(event)
            // Create a params object with the parameters needed to make an API call.
            if (this.valid) {
                const config = {
                    headers: { 'Content-Type': 'multipart/form-data' },
                }
                const params = new FormData()
                params.append('wstoken', this.token)
                params.append('wsfunction', 'local_grupomakro_check_reschedule_conflicts')
                params.append('moodlewsrestformat', 'json')
                params.append('classId', event.classId)
                params.append('moduleId', event.moduleId)
                params.append('date', this.date)
                params.append('initTime', this.time)
                params.append('endTime', null)
                params.append('sessionId', event.sessionId)

                try {
                    const checkResponse = await window.axios.post(wsUrl, params, config)
                    console.log(checkResponse)
                    if (!checkResponse.data.status || checkResponse.data.status === -1) throw Error(checkResponse.data.message);
                    const sendRescheduleMessageParams = {
                        wstoken: this.token,
                        moodlewsrestformat: 'json',
                        wsfunction: 'local_grupomakro_send_reschedule_message',
                        instructorId: event.instructorId,
                        classId: event.classId,
                        causes: this.causes.join(','),
                        moduleId: event.moduleId,
                        originalDate: event.start.split(" ")[0],
                        originalHour: event.hour,
                        sessionId: event.sessionId,
                        proposedDate: this.date,
                        proposedHour: this.time,
                    };
                    const messageResponse = await window.axios.get(url, { params: sendRescheduleMessageParams })
                    if (messageResponse.data.status === -1) throw Error(messageResponse.data.message)
                    this.dialog = false;
                    this.dialogconfirm = true;
                }
                catch (error) {
                    this.rescheduleError = error.message
                }
            }
        },
        // This method hides the current dialog box and reschedule modal.
        hidenDialog() {
            this.dialogconfirm = false;
            this.reschedulemodal = false
        },
        // This method formats a given date object to display only the time in hours and minutes.
        formatEventTime(date) {
            return new Date(date).toLocaleTimeString("es-CO", {
                hour: "2-digit",
                minute: "2-digit",
                hour12: true,
            });
        },
        // This method returns the current time in minutes, based on the current time on the calendar.
        getCurrentTime() {
            return this.cal ? this.cal.times.now.hour * 60 + this.cal.times.now.minute : 0
        },
        // This method scrolls the calendar to the current time.
        scrollToTime() {
            const time = this.getCurrentTime()
            const first = Math.max(0, time - (time % 30) - 30)

            this.cal.scrollToTime(first)
        },
        // This method updates the time displayed on the calendar every minute.
        updateTime() {
            setInterval(() => this.cal.updateTimes(), 60 * 1000)
        },
        editEvent(event) {
            console.log(event)
            let url = window.location.origin + '/local/grupomakro_core/pages/editclass.php?class_id=' + event.classId
            if (event.moduleId && parseInt(event.moduleId) > 0) {
                url += '&moduleId=' + event.moduleId
            }
            if (event.sessionId && parseInt(event.sessionId) > 0) {
                url += '&sessionId=' + event.sessionId
            }
            window.location = url
        },
        // Open the Copy dialog prefilled with the source session date/time.
        openCopyDialog(event) {
            if (!event || !event.sessionId) {
                this.copyError = 'Sesión sin ID';
                return;
            }
            this.selectedEvent = event;
            this.copyError = '';
            this.copyConflicts = null;
            this.copyVerified = false;
            // Pre-fill first row with the source date + hour.
            const todayStr = (new Date(Date.now() - (new Date()).getTimezoneOffset() * 60000)).toISOString().substr(0, 10);
            let sourceDate = todayStr;
            let sourceInit = '';
            let sourceEnd = '';
            try {
                if (event.start) {
                    const parts = event.start.split(' ');
                    sourceDate = parts[0];
                    if (parts[1]) {
                        sourceInit = parts[1].substring(0, 5);
                    }
                }
                if (event.end) {
                    const parts = event.end.split(' ');
                    if (parts[1]) {
                        sourceEnd = parts[1].substring(0, 5);
                    }
                }
            } catch (e) { /* ignore */ }

            // Default: same day next week
            let defaultDate = sourceDate;
            try {
                const d = new Date(sourceDate + 'T00:00:00');
                if (!isNaN(d.getTime())) {
                    d.setDate(d.getDate() + 7);
                    defaultDate = d.toISOString().substr(0, 10);
                }
            } catch (e) { /* ignore */ }

            this.copyRows = [{
                date: defaultDate,
                initTime: sourceInit,
                endTime: sourceEnd,
                dateMenu: false
            }];
            this.copyDialog = true;
        },
        addCopyRow() {
            if (this.copyRows.length >= 20) {
                return;
            }
            const last = this.copyRows[this.copyRows.length - 1];
            let nextDate = this.today;
            try {
                const d = new Date(last.date + 'T00:00:00');
                if (!isNaN(d.getTime())) {
                    d.setDate(d.getDate() + 7);
                    nextDate = d.toISOString().substr(0, 10);
                }
            } catch (e) { /* ignore */ }
            this.copyRows.push({
                date: nextDate,
                initTime: last.initTime,
                endTime: last.endTime,
                dateMenu: false
            });
        },
        removeCopyRow(idx) {
            if (this.copyRows.length <= 1) return;
            this.copyRows.splice(idx, 1);
            this.copyVerified = false;
            this.copyConflicts = null;
        },
        // POST helper that sends FormData to the WS endpoint.
        // Use URLSearchParams (application/x-www-form-urlencoded) instead of FormData
        // (multipart/form-data). With PARAM_RAW JSON-shaped values Moodle
        // auto-decodes them as PHP arrays, which breaks json_decode() in the WS.
        async postWs(wsfunction, params) {
            const config = { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } };
            const resp = await window.axios.post(wsUrl, params, config);
            return resp.data;
        },
        buildCopyFormData(force) {
            const params = new URLSearchParams();
            params.append('wstoken', this.token);
            params.append('wsfunction', 'local_grupomakro_copy_activity');
            params.append('moodlewsrestformat', 'json');
            params.append('classId', this.selectedEvent.classId);
            params.append('sourceSessionId', this.selectedEvent.sessionId);
            const datesArr = this.copyRows.map(r => ({
                date: r.date,
                initTime: r.initTime,
                endTime: r.endTime
            }));
            params.append('dates', JSON.stringify(datesArr));
            params.append('force', force ? 1 : 0);
            return params;
        },
        buildCheckFormData() {
            const params = new URLSearchParams();
            params.append('wstoken', this.token);
            params.append('wsfunction', 'local_grupomakro_check_copy_conflicts');
            params.append('moodlewsrestformat', 'json');
            params.append('classId', this.selectedEvent.classId);
            const datesArr = this.copyRows.map(r => ({
                date: r.date,
                initTime: r.initTime,
                endTime: r.endTime
            }));
            params.append('dates', JSON.stringify(datesArr));
            return params;
        },
        async verifyCopyConflicts() {
            this.copyError = '';
            this.copyVerified = false;
            this.copyConflicts = null;
            // Validate local form first.
            if (this.$refs.copyForm && !this.$refs.copyForm.validate()) {
                return;
            }
            this.copyLoading = true;
            try {
                const resp = await this.postWs('local_grupomakro_check_copy_conflicts', this.buildCheckFormData());
                if (resp.status === -1) {
                    throw new Error(resp.message || 'Error');
                }
                let conflictsByDate = {};
                try {
                    conflictsByDate = JSON.parse(resp.conflictsByDate || '{}');
                } catch (e) { conflictsByDate = {}; }
                this.copyConflicts = conflictsByDate;
                this.copyVerified = Object.keys(conflictsByDate).length === 0;
            } catch (e) {
                this.copyError = (e && e.message) ? e.message : 'Error al verificar conflictos';
            } finally {
                this.copyLoading = false;
            }
        },
        async sendCopy(force) {
            this.copyError = '';
            if (this.$refs.copyForm && !this.$refs.copyForm.validate()) {
                return;
            }
            this.copyLoading = true;
            try {
                const resp = await this.postWs('local_grupomakro_copy_activity', this.buildCopyFormData(force));
                if (resp.status === -1) {
                    if (resp.hasConflicts) {
                        let conflictsByDate = {};
                        try {
                            conflictsByDate = JSON.parse(resp.conflictsByDate || '{}');
                        } catch (e) { conflictsByDate = {}; }
                        this.copyConflicts = conflictsByDate;
                        this.copyVerified = false;
                        this.copyError = this.strings.copyConflictsTitle;
                    } else {
                        throw new Error(resp.message || 'Error');
                    }
                    return;
                }
                let created = [];
                try {
                    const parsed = JSON.parse(resp.message || '{}');
                    created = parsed.created || [];
                } catch (e) { /* ignore */ }
                this.copyDialog = false;
                this.copyConflicts = null;
                this.copyVerified = false;
                this.copyRows = [{
                    date: this.today,
                    initTime: '',
                    endTime: '',
                    dateMenu: false
                }];
                // Show success dialog.
                this.copySuccessCount = created.length;
                this.copySuccessDialog = true;
                // Refresh calendar.
                this.getEvents();
            } catch (e) {
                this.copyError = (e && e.message) ? e.message : 'Error al copiar';
            } finally {
                this.copyLoading = false;
            }
        },
        openDeleteDialog(event) {
            if (!event || !event.sessionId) {
                return;
            }
            this.selectedEvent = event;
            this.deleteError = '';
            this.deleteForce = false;
            // We don't know yet if the session has a BBB or logs; check on open.
            // For UI, optimistically show the warning about BBB if modulename === 'bigbluebuttonbn'
            this.deleteHasBBB = (event.modulename === 'bigbluebuttonbn');
            this.deleteLogCount = 0;
            this.deleteDialog = true;
            // Fetch log count via WS (best effort).
            this.fetchSessionLogCount(event.sessionId);
        },
        async fetchSessionLogCount(sessionId) {
            // We use the event list data: the backend bundles attendance sessions with their
            // log counts nowhere, so we fall back to a dedicated WS if available, or simply
            // skip the pre-check (server enforces protection via force flag).
            // For now, we leave deleteLogCount=0 unless we already know it.
            // The server will reject the call if logs exist and force=0.
        },
        async sendDelete() {
            this.deleteError = '';
            if (!this.selectedEvent || !this.selectedEvent.sessionId) return;
            this.deleteLoading = true;
            try {
                const params = new URLSearchParams();
                params.append('wstoken', this.token);
                params.append('wsfunction', 'local_grupomakro_delete_session');
                params.append('moodlewsrestformat', 'json');
                params.append('classId', this.selectedEvent.classId);
                params.append('sessionId', this.selectedEvent.sessionId);
                params.append('force', this.deleteForce ? 1 : 0);
                const data = await this.postWs('local_grupomakro_delete_session', params);
                if (data.status === -1) {
                    if (data.hasLogs) {
                        this.deleteLogCount = data.logCount || this.deleteLogCount;
                        this.deleteForce = false;
                        this.deleteError = data.message || this.strings.deleteSessionHasLogs.replace('{$a}', this.deleteLogCount);
                    } else {
                        throw new Error(data.message || 'Error al eliminar');
                    }
                    return;
                }
                this.deleteDialog = false;
                this.deleteSuccessDialog = true;
                this.getEvents();
            } catch (e) {
                this.deleteError = (e && e.message) ? e.message : 'Error al eliminar';
            } finally {
                this.deleteLoading = false;
            }
        },
        formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0'); // Month is zero-based
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },
        openLink(link) {
            window.open(`${window.location.origin}/local/grupomakro_core/pages/${link}.php`)
        },
    },
    computed: {
        getEventsParameters() {

            let initDate = new Date(this.$refs.calendar.lastStart.date)
            let endDate = new Date(this.$refs.calendar.lastEnd.date)
            const daysOffSet = eventsDaysOffset[this.calendarType]

            initDate.setDate(initDate.getDate() - daysOffSet);
            endDate.setDate(endDate.getDate() + daysOffSet);

            initDate = this.formatDate(initDate);
            endDate = this.formatDate(endDate);

            return {
                ...wsStaticParams,
                wsfunction: 'local_grupomakro_calendar_get_calendar_events',
                userId: !this.isAdmin ? this.userId : null,
                initDate,
                endDate
            }
        },
        formattedEvents() {
            return this.events?.map(({ coursename, instructorName, typelabel, color, start, end, classDaysES, timeRange, modulename, moduleId, bigBlueButtonActivityUrl, attendanceActivityUrl, classId, className, sessionId, instructorid, visible, courseid, classroomName, room }) => ({
                name: coursename,
                instructorId: instructorid,
                instructor: instructorName,
                room: room || classroomName || '',
                details: typelabel,
                days: classDaysES.join(" - "),
                hour: timeRange,
                timed: true,
                color,
                start,
                end,
                modulename,
                moduleId,
                bigBlueButtonActivityUrl,
                attendanceActivityUrl,
                classId,
                className,
                sessionId,
                visible,
                courseid,
            }))
        },
        // This method returns an array of events filtered based on the selections made by the user. 
        // If any instructor has been selected, it returns the events related to that instructor. 
        // If any class type has been selected, it returns the events related to that class type. 
        // If no selection has been made, returns all events. 
        filteredEvents() {
            const selectedInstructorsIds = this.selectedInstructors.map(instructor => instructor.id)
            const selectedCoursesIds = this.selectedCourses.map(course => course.id)
            // Only show class sessions — exclude tasks, assignments and deadline events.
            let filteredEvents = (this.formattedEvents || []).filter(e =>
                e.modulename === 'attendance' || e.modulename === 'bigbluebuttonbn'
            )
            if (!selectedInstructorsIds.length && !selectedCoursesIds.length) {
                return filteredEvents;
            }
            if (selectedInstructorsIds.length) {
                filteredEvents = filteredEvents.filter(event => selectedInstructorsIds.includes(event.instructorId))
            }
            if (selectedCoursesIds.length) {
                filteredEvents = filteredEvents.filter(event => selectedCoursesIds.includes(event.courseid))
            }
            return filteredEvents;
        },
        // This method returns the calendar instance if it is ready to use, otherwise it returns null.
        cal() {
            return this.ready ? this.$refs.calendar : null
        },
        // This method Returns the current vertical position of the current time indicator on the calendar.
        nowY() {
            return this.cal ? this.cal.timeToY(this.cal.times.now) + 'px' : '-10px'
        },
        // This method returns a validation rule function for use with vee-validate library.
        // The function takes a value as input and returns a boolean indicating whether the value is non-empty or not.
        requiredRule() {
            return (value) => !!value || 'Este campo es requerido';
        },
    },

})