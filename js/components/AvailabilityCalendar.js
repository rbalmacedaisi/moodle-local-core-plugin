const siteUrl = window.location.origin + '/webservice/rest/server.php';
const wsStaticParams = {
    wstoken: window.token,
    moodlewsrestformat: 'json',
}

window.Vue.component('availabilitycalendar', {
    template: `
    <v-row class="fill-height">
        <v-col>
            <v-sheet height="64">
                <v-toolbar
                  flat
                >
                    <v-btn
                      outlined
                      class="mr-4"
                      color="grey darken-2"
                      @click="dateFocused = ''"
                    >
                        {{strings.today}}
                    </v-btn>
                    <v-btn
                      fab
                      text
                      small
                      color="grey darken-2"
                      @click="$refs.calendar.prev()"
                    >
                        <v-icon small>mdi-chevron-left</v-icon>
                    </v-btn>
                    <v-btn
                      fab
                      text
                      small
                      color="grey darken-2"
                      @click="$refs.calendar.next()"
                    >
                        <v-icon small>mdi-chevron-right</v-icon>
                    </v-btn>
                    <v-toolbar-title v-if="$refs.calendar">
                        {{ $refs.calendar.title }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-combobox
                      v-model="selectedInstructor"
                      :items="instructors"
                      :label="strings.instructors"
                      :disabled="fetchingAvailability"
                      outlined
                      dense
                      hide-details
                      class="mr-2 instructor-select"
                      @input="getInstructorAvailability"
                    ></v-combobox>
                </v-toolbar>
            </v-sheet>
            <v-sheet height="600" class="px-4">
                <v-calendar
                  ref="calendar"
                  v-model="dateFocused"
                  color="primary"
                  type="month"
                  :events="selectedInstructorAvailability.events"
                  locale="en-US"
                  :weekdays="weekdays"
                  @click:day="showEvent"
                  @change="getInstructorAvailability"
                >
                    <template v-slot:day-label="{date,day}">
                        <v-btn
                           x-small
                           fab
                           type="button"
                           :class="dateInInstructorFreeTime(date) ? 'availabilityColor rounded-circle' : 'transparent elevation-0'"
                           :style="dateInInstructorFreeTime(date)  ? 'event-pointer: none !important;' : ''"
                           >
                            <span class="v-btn__content" :class="dateInInstructorFreeTime(date) ? 'black--text' : ''">{{day}}</span>
                        </v-btn>
                        <div class="d-flex justify-center mt-2">
                            <v-chip
                              v-if="dateInInstructorFreeTime(date) "
                              color="availabilityColor"
                              label
                              x-small
                              class="mt-0 mb-1 black--text"
                            >
                              {{strings.available}}
                            </v-chip>
                        </div>
                    </template>
                </v-calendar>
                <v-overlay :value="fetchingAvailability">
                    <v-progress-circular
                        color="primary"
                        indeterminate
                        size="64"
                    ></v-progress-circular>
                </v-overlay>
            </v-sheet>
            
            
        </v-col>
        <CreationClassModal v-if="showDayModal" :selectedDay="selectedDay" :hoursFree="dayAvailableHours" @close-dialog="closeDialog" @class-created="reloadCalendarDayView" :instructorId="selectedInstructor?.id"/>
    </v-row>
    `,
    data() {
        return {
            dateFocused: new Date().toISOString().substr(0, 10),
            weekdays: [
                1, 2, 3, 4, 5, 6, 0
            ],
            selectedInstructor: null,
            strings: window.strings,
            instructors: window.instructors,
            fetchingAvailability: false,
            teacherAvailabilityCalendarData: undefined,
            dayAvailableHours: [],
            showDayModal: false,
            selectedDay: undefined,

        }
    },
    created() { },
    mounted() {
        this.$refs.calendar.checkChange();
    },
    methods: {
        /**
         * Calls a web service to retrieve instructors' availability information and updates the userAvailabilityRecords array.
         */
        async getInstructorAvailability() {
            if (!this.selectedInstructor) return;
            try {
                this.teacherAvailabilityCalendarData = undefined
                this.fetchingAvailability = true
                const { data } = await window.axios.get(siteUrl, { params: this.getTeachersDisponibilityCalendarParams })
                if (data.status === -1) throw data.message;
                this.teacherAvailabilityCalendarData = JSON.parse(data.disponibility)
            } catch (error) {
                console.error(error)
                this.teacherAvailabilityCalendarData = null
                this.selectedInstructor = null
            }
            finally {
                this.fetchingAvailability = false
            }
            return;
        },
        async reloadCalendarDayView() {
            await this.getInstructorAvailability()
            console.log(this.selectedDay)
            await this.showEvent({ date: this.selectedDay })
            return
        },
        /**
         * Close the availability modal.
         */
        closeDialog() {
            // Set the 'dayModal' property to false to hide the modal.
            this.showDayModal = false
        },
        /**
         * Update the availability information of the selected instructor based on the date selected in the calendar.
         *
         * @param {Object} date - The date object representing the selected date in the calendar.
         */
        showEvent({ date }) {
            if (!this.selectedInstructor) return;

            this.dayAvailableHours = [];
            const selectedDayAvailableHours = this.teacherAvailabilityCalendarData.daysFree[date]
            if (!selectedDayAvailableHours) return

            for (let i = 0; i < selectedDayAvailableHours.length; i += 2) {
                this.dayAvailableHours.push({
                    startTime: selectedDayAvailableHours[i],
                    endTime: selectedDayAvailableHours[i + 1]
                })
            }
            this.selectedDay = date
            this.showDayModal = true
            return;
        },
        formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0'); // Month is zero-based
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },
        dateInInstructorFreeTime(date) {
            return date.toString() in this.selectedInstructorAvailability.daysFree
        },
    },
    computed: {
        getTeachersDisponibilityCalendarParams() {
            let initDate = new Date(this.$refs.calendar.lastStart.date)
            let endDate = new Date(this.$refs.calendar.lastEnd.date)

            initDate.setDate(initDate.getDate() - 7);
            endDate.setDate(endDate.getDate() + 7);

            initDate = this.formatDate(initDate);
            endDate = this.formatDate(endDate);

            return {
                ...wsStaticParams,
                wsfunction: 'local_grupomakro_get_teachers_disponibility_calendar',
                instructorId: this.selectedInstructor?.id,
                initDate,
                endDate
            }
        },
        /**
         * Computed property that retrieves and formats data for the selected instructor.
         */
        selectedInstructorAvailability() {
            return {
                daysFree: this.teacherAvailabilityCalendarData ? this.teacherAvailabilityCalendarData.daysFree : {},
                events: this.teacherAvailabilityCalendarData ? this.teacherAvailabilityCalendarData.events.map(({ color, start, end, modulename, coursename, instructorName, typelabel, timeRange, classDaysES }) => ({
                    color,
                    end,
                    start,
                    modulename,
                    name: coursename,
                    instructor: instructorName,
                    details: typelabel,
                    hour: timeRange,
                    days: classDaysES.join(" - "),
                    timed: true
                })) : []
            }
        },
    },
})