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
                        <div class="v-event-draggable">
                            <strong>{{ event.name }}</strong><v-spacer/>
                            {{ formatEventTime(event.start) }} -
                            {{ formatEventTime(event.end) }}
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

            weekdays: [1, 2, 3, 4, 5, 6, 0],
            events: [],
            instructors: window.instructors,
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
        this.scrollToTime()
        this.updateTime()
    },
    methods: {
        // This method makes an HTTP GET request to retrieve calendar events from the Moodle server. 
        // The received data is processed and relevant information is extracted from each event, which is added to the events array. 
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
            window.location = window.location.origin + '/local/grupomakro_core/pages/editclass.php?class_id='
                + event.classId + '&moduleId=' + event.moduleId + '&sessionId=' + event.sessionId
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
            return this.events?.map(({ coursename, instructorName, typelabel, color, start, end, classDaysES, timeRange, modulename, moduleId, bigBlueButtonActivityUrl, attendanceActivityUrl, classId, className, sessionId, instructorid, visible, courseid }) => ({
                name: coursename,
                instructorId: instructorid,
                instructor: instructorName,
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
            let filteredEvents = this.formattedEvents
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