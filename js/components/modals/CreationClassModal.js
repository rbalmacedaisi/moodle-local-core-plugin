/* global wsUrl */
/* global wsStaticParams */
window.Vue.component('CreationClassModal', {
    template: `
        <v-dialog v-model="dialog" persistent max-width="1400">
            <v-card max-width="1400">
                <v-card-title class="text-h5">{{ strings.available_hours }}</v-card-title>
                <v-card-text>
                    <v-sheet height="600">
                        <v-calendar
                          :events="events"
                          color="primary"
                          type="day"
                          :value="selectedDay"
                          :interval-minutes="30"
                          :interval-count="36"
                          first-time="6"
                          ref="calendar"
                          locale="en-US"
                          @mousedown:event="startDrag"
                          @mousedown:time="startTime"
                          @mousemove:time="mouseMove"
                          @mouseup:time="endDrag"
                          @mouseleave.native="cancelDrag"
                          @click:event="showEvent"
                        >
                            <template v-slot:interval="{ time, date, day, hour, minute }">
                                <div
                                  class="h-100 d-flex align-center justify-center"
                                  :style="getIntervalStyle(time)"
                                  @click="intervalUrl(time, date, day, hour, minute)"
                                >
                                    <div v-if="getIntervalStyle(time).content" class="black--text">
                                        {{ getIntervalStyle(time).content }}
                                    </div>
                                </div>
                            </template>
            
                            <template v-slot:day-body="{ date, week }">
                                <div
                                  class="v-current-time"
                                  :class="{ first: date === week[0].date }"
                                  :style="{ top: nowY }"
                                ></div>
                            </template>
                            <template v-slot:event="{ event, timed, eventSummary }">
                                <div class="v-event-draggable">
                                    <component :is="{ render: eventSummary }"></component>
                                </div>
                                <div
                                  v-if="timed"
                                  class="v-event-drag-bottom"
                                  @mousedown.stop="extendBottom(event)"
                                ></div>
                            </template>
                        </v-calendar>
                        <v-menu
                          v-model="openClassForm"
                          :close-on-content-click="false"
                          offset-y
                          :close-on-click="false"
                          min-width="400px"
                          max-width="800px"
                          absolute
                          content-class="availabilityClassCreationMenu"
                        >
                            <v-card min-width="400px"  flat>
                                <v-toolbar color="primary">
                                    <v-toolbar-title>{{newClass.name?newClass.name:"Nueva clase" }} ({{timeRange}})</v-toolbar-title>
                                    <v-spacer></v-spacer>
                                    <v-btn icon>
                                      <v-icon @click="closeMenu">mdi-close</v-icon>
                                    </v-btn>
                                </v-toolbar>
                
                                <v-card-text>
                                    <v-form ref="createClassForm" v-model="validClassForm">
                                        <v-row class="mt-4">
                                            <v-col cols="12" sm="6">
                                                <v-text-field
                                                    dense
                                                    outlined
                                                    class="my-1"
                                                    v-model="newClass.name"
                                                    :placeholder="strings.name"
                                                    :rules="[formRules.requiredValue]"
                                                    hide-details="auto"
                                                ></v-text-field>
                                            </v-col>
                                            
                                            <v-col cols="12" sm="6">
                                                <v-select
                                                    outlined
                                                    hide-selected
                                                    dense
                                                    v-model="newClass.type"
                                                    class="my-1"
                                                    :placeholder="strings.class_type"
                                                    :items="classTypes"
                                                    item-text="label"
                                                    item-value="value"
                                                    hide-details="auto"
                                                    :rules="[formRules.requiredValue]"
                                                ></v-select>
                                            </v-col>
                                            
                                            <v-col cols="12" sm="6" v-show="showClassroomSelector">
                                                <v-select
                                                    outlined
                                                    hide-selected
                                                    dense
                                                    v-model="newClass.classroomId"
                                                    class="my-1"
                                                    :placeholder="strings.class_room"
                                                    :items="classRooms"
                                                    item-text="label"
                                                    item-value="value"
                                                    hide-details="auto"
                                                    :rules="showClassroomSelector?[formRules.requiredValue]:[]"
                                                ></v-select>
                                            </v-col>
                                            
                                            <v-col cols="12" sm="6">
                                                <v-select
                                                    outlined
                                                    hide-selected
                                                    dense
                                                    v-model="newClass.learningPlanId"
                                                    class="my-1"
                                                    @change="handleLearningPlanSelection"
                                                    :placeholder="strings.class_learningplan_placeholder"
                                                    :items="careerItems"
                                                    item-text="label"
                                                    item-value="value"
                                                    hide-details="auto"
                                                    :rules="[formRules.requiredValue]"
                                                ></v-select>
                                            </v-col>
                                      
                                            <v-col cols="12" sm="6">
                                                <v-select
                                                    outlined
                                                    hide-selected
                                                    dense
                                                    v-model="newClass.periodId"
                                                    class="my-1"
                                                    @change="handlePeriodSelection"
                                                    :placeholder="strings.class_period_placeholder"
                                                    :items="periodItems"
                                                    item-text="label"
                                                    item-value="value"
                                                    hide-details="auto"
                                                    :disabled="!periodItems.length"
                                                    :rules="[formRules.requiredValue]"
                                                ></v-select>
                                            </v-col>
                                      
                                            <v-col cols="12" sm="6">
                                                <v-select
                                                    outlined
                                                    hide-selected
                                                    hide-no-data
                                                    dense
                                                    v-model="newClass.courseId"
                                                    class="my-1"
                                                    :placeholder="strings.class_course_placeholder"
                                                    :items="courseItems"
                                                    item-text="label"
                                                    item-value="value"
                                                    hide-details="auto"
                                                    :disabled="!courseItems.length"
                                                    :rules="[formRules.requiredValue]"
                                                ></v-select>
                                            </v-col>
                                      
                                            <v-col cols="12" sm="6">
                                                <v-autocomplete
                                                    v-model="newClass.classDays"
                                                    :items="daysOfWeek"
                                                    item-text="label"
                                                    item-value="value"
                                                    item-disabled="disabled"
                                                    :placeholder="strings.class_days"
                                                    outlined
                                                    dense
                                                    multiple
                                                    class="my-1"
                                                    :rules="[formRules.requiredClassDays]"
                                                ></v-autocomplete>
                                            </v-col>
                                        </v-row>
                                    </v-form>
                                    <v-alert
                                      dense
                                      outlined
                                      type="error"
                                      v-show="classError"
                                      style="white-space: pre-line"
                                    >
                                      {{classError}}
                                    </v-alert>
                                </v-card-text>
                                <v-divider class="mb-0"></v-divider>
                                <v-card-actions>
                                    <v-spacer></v-spacer>
                                    <v-btn color="primary" outlined class="rounded" @click="closeMenu" :disabled="creatingClass">{{strings.cancel}}</v-btn>
                                    <v-btn color="primary" class="rounded" @click="createClass" :disabled="creatingClass"> {{strings.create}} </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-menu>
                    </v-sheet>
                </v-card-text>
              
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn
                      color="primary"
                      text
                      @click="(dialog = false), $emit('close-dialog')"
                    >
                      {{ strings.close }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    props: {
        // Define the "selectedDay" prop as a string.
        selectedDay: String,
        // Define the "hoursFree" prop as an array.
        hoursFree: Array,

        instructorId: String
    },
    data() {
        return {
            dialog: true,
            ready: false,
            dragEvent: null,
            dragStart: null,
            dragTime: null,
            createEvent: null,
            createStart: null,
            extendOriginal: null,
            newClassEvent: undefined,
            openClassForm: false,
            validClassForm: false,
            newClass: {
                name: undefined,
                type: undefined,
                learningPlanId: undefined,
                periodId: undefined,
                courseId: undefined,
                classDays: undefined,
                classroomId: undefined
            },
            daysOfWeek: [
                { value: "Domingo", label: "Domingo", disabled: false },
                { value: "Lunes", label: "Lunes", disabled: false },
                { value: "Martes", label: "Martes", disabled: false },
                { value: "Miércoles", label: "Miércoles", disabled: false },
                { value: "Jueves", label: "Jueves", disabled: false },
                { value: "Viernes", label: "Viernes", disabled: false },
                { value: "Sábado", label: "Sábado", disabled: false }
            ],
            daysOfWeekLabels: ['Lunes', "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"],
            createClassError: undefined,
            creatingClass: false,

            strings: window.strings,
            instructorCareers: {},
            classTypes: window.classTypes,
            periods: [],
            courses: [],
            formRules: {
                requiredValue: v => !!v || 'Este campo es requerido.',
                requiredClassDays: v => v.length !== 0 || 'Debes escoger al menos un día.'
            },
            classRooms: window.classrooms
        }
    },
    created() {
        this.getLearningPlans();
    },
    mounted() {
        this.ready = true;
        this.scrollToTime();
        this.updateTime();
        this.newClass.classDays = [this.selectedDayLabel];
        this.daysOfWeek[this.daysOfWeek.findIndex(day => day.label === this.selectedDayLabel)].disabled = true;
    },
    methods: {
        async getLearningPlans() {
            try {
                const { data } = await window.axios.get(wsUrl, { params: this.getActiveLearningPlansParameters })
                if (data.status === -1) throw data.message
                this.instructorCareers = JSON.parse(data.availablecareers)
            } catch (error) {
                console.error(error)
            }
        },

        async handleLearningPlanSelection() {
            this.newClass.periodId = undefined
            this.newClass.courseId = undefined
            if (!this.newClass.learningPlanId) return;

            try {
                this.periods = []
                const { data } = await window.axios.get(wsUrl, { params: this.getLearningPlanPeriodsParameters })
                if (data.status === -1) throw data.message
                this.periods = JSON.parse(data.periods)
            } catch (error) {
                console.error(error)
                this.periods = []
                this.newClass.learningPlanId = undefined
            }
        },

        async handlePeriodSelection() {
            this.newClass.courseId = undefined
            if (!this.newClass.periodId) return;

            try {
                const { data } = await window.axios.get(wsUrl, { params: this.getTeacherAvailableCoursesParameters })
                if (data.status === -1) throw data.message
                this.courses = JSON.parse(data.courses)
            } catch (error) {
                this.newClass.periodId = undefined
                console.error(error)
            }
        },
        async getSelectedTimeRangeAvailableDays() {
            try {
                const { data } = await window.axios.get(wsUrl, { params: this.getTeacherAvailableDaysParameters })
                if (data.status === -1) throw data.message
                const availableDays = JSON.parse(data.days)
                this.daysOfWeek.forEach(day => {
                    day.disabled = !availableDays.includes(day.value)
                })
            } catch (error) {
                console.error(error)
            }
        },
        async createClass() {
            this.$refs.createClassForm.validate()
            if (!this.validClassForm || this.classError) return;

            try {
                this.creatingClass = true
                const { data } = await window.axios.get(wsUrl, { params: this.createClassParameters });
                if (data.status === -1) throw data.message
                this.$emit('class-created');
                this.closeMenu()

            } catch (error) {
                console.error(error)
                try {
                    const errorMessages = JSON.parse(error);
                    let errorString = '';
                    errorMessages.forEach(message => {
                        errorString += `${message} \n`
                    })
                    this.createClassError = errorString
                } catch (error2) {
                    this.createClassError = error
                }
            } finally {
                this.creatingClass = false
            }
        },

        showEvent({ nativeEvent }) {
            console.log('showEvent')
            this.getSelectedTimeRangeAvailableDays();
            const open = () => {
                requestAnimationFrame(() =>
                    requestAnimationFrame(() => (this.openClassForm = true))
                );
            };
            if (this.openClassForm) {
                this.openClassForm = false;
                requestAnimationFrame(() => requestAnimationFrame(() => open()));
            } else {
                open();
            }
            nativeEvent.stopPropagation();
        },

        closeMenu() {
            console.log('closeMenu')
            this.newClassEvent = undefined;
            this.openClassForm = false;
            this.$refs.createClassForm.reset()
        },
        startDrag({ event, timed }) {
            console.log('startDrag')
            if (event && timed) {
                this.dragEvent = event;
                this.dragTime = null;
                this.extendOriginal = null;
            }
        },
        startTime(calendarTimestamp) {
            console.log('startTime')
            this.openClassForm = false;
            let selectedEvent = false;
            const nodeName = calendarTimestamp.nativeEvent.target.nodeName;
            const nodeClassName = calendarTimestamp.nativeEvent.target.className;
            if (nodeName === "STRONG") selectedEvent = true;
            else if (
                (nodeName === "DIV" || nodeName === "SPAN") &&
                (nodeClassName.includes("v-event-timed") ||
                    nodeClassName.includes("v-event-draggable") ||
                    nodeClassName.includes("v-event-summary"))
            ) selectedEvent = true;
            if (!selectedEvent) this.newClassEvent = undefined;

            const mouseDownTimestamp = this.getTimestampFromCalendarTimestamp(calendarTimestamp);
            if (this.dragEvent && this.dragTime === null) {
                const start = this.dragEvent.start;
                this.dragTime = mouseDownTimestamp - start;
            } else {
                this.createStart = this.roundTime(mouseDownTimestamp);
                this.createEvent = {
                    name: this.newClass.name ? this.newClass.name : `(Sin nombre)`,
                    start: this.createStart,
                    end: this.createStart + 3600000,
                    timed: true,
                };
                this.newClassEvent = this.createEvent;
            }
        },
        extendBottom(event) {
            console.log('extendBottom')
            this.openClassForm = false;
            this.createEvent = event;
            this.createStart = event.start;
            this.extendOriginal = event.end;
        },
        mouseMove(calendarTimestamp) {
            const mouseTimestamp = this.getTimestampFromCalendarTimestamp(calendarTimestamp);
            if (this.dragEvent && this.dragTime !== null) {
                const start = this.dragEvent.start;
                const end = this.dragEvent.end;
                const duration = end - start;
                const newStartTime = mouseTimestamp - this.dragTime;
                const newStart = this.roundTime(newStartTime);
                const newEnd = newStart + duration;

                this.dragEvent.start = newStart;
                this.dragEvent.end = newEnd;
            } else if (this.createEvent && this.createStart !== null) {
                const mouseRounded = this.roundTime(mouseTimestamp, false);
                const min = Math.min(mouseRounded, this.createStart);
                const max = Math.max(mouseRounded, this.createStart);

                this.createEvent.start = min;
                this.createEvent.end = max;
            }
        },
        endDrag({ nativeEvent }) {
            console.log('endDrag')
            this.dragTime = null;
            this.dragEvent = null;
            this.createEvent = null;
            this.createStart = null;
            this.extendOriginal = null;

            this.showEvent({
                nativeEvent
            });
        },
        cancelDrag() {
            console.log('cancelDrag')
            if (this.createEvent) {
                if (this.extendOriginal) {
                    this.createEvent.end = this.extendOriginal;
                } else {
                    const i = this.events.indexOf(this.createEvent);
                    if (i !== -1) {
                        this.events.splice(i, 1);
                    }
                }
            }
            this.createEvent = null;
            this.createStart = null;
            this.dragTime = null;
            this.dragEvent = null;
        },
        roundTime(time, down = true) {
            const roundTo = 5; // minutes
            const roundDownTime = roundTo * 60 * 1000;

            return down
                ? time - (time % roundDownTime)
                : time + (roundDownTime - (time % roundDownTime));
        },
        getTimestampFromCalendarTimestamp(calendarTimestamp) {
            return new Date(
                calendarTimestamp.year,
                calendarTimestamp.month - 1,
                calendarTimestamp.day,
                calendarTimestamp.hour,
                calendarTimestamp.minute
            ).getTime();

        },


        // This method returns the current time in minutes.
        getCurrentTime() {
            return this.cal ? this.cal.times.now.hour * 60 + this.cal.times.now.minute : 0
        },
        // This method scrolls to the current time on the calendar. It does this by getting the current time and rounding it to the nearest hour. 
        // Then, set the calendar offset to that time.
        scrollToTime() {
            const time = this.getCurrentTime()
            const first = Math.max(0, time - (time % 30) - 30)

            this.cal.scrollToTime(first)
        },
        // This method updates the time every 60 seconds to keep the calendar in sync.
        updateTime() {
            setInterval(() => this.cal.updateTimes(), 60 * 1000)
        },
        // This method Returns the style of a time slot to highlight it on the calendar if it is available or not. 
        // It receives the time in minutes as a parameter and checks if it is within the available hours. If so, 
        // it returns an object with a background color and a message indicating that it is available. Otherwise, it returns an empty object.
        getIntervalStyle(time) {
            for (let i = 0; i < this.hoursFree.length; i++) {
                const element = this.hoursFree[i];
                if (time >= element.startTime && time < element.endTime) {
                    return {
                        background: '#7ef2a8',
                        content: this.strings.available
                    };
                }
            }
            return {}
        }
    },

    computed: {
        getActiveLearningPlansParameters() {
            return {
                ...wsStaticParams,
                wsfunction: 'local_sc_learningplans_get_active_learning_plans'
            }
        },
        getLearningPlanPeriodsParameters() {
            return {
                ...wsStaticParams,
                wsfunction: 'local_sc_learningplans_get_learning_plan_periods',
                learningPlanId: this.newClass.learningPlanId
            }
        },
        getTeacherAvailableCoursesParameters() {
            return {
                ...wsStaticParams,
                wsfunction: 'local_grupomakro_get_teacher_available_courses',
                learningPlanId: this.newClass.learningPlanId,
                periodId: this.newClass.periodId,
                instructorId: this.instructorId
            }
        },
        getTeacherAvailableDaysParameters() {
            return {
                ...wsStaticParams,
                wsfunction: 'local_grupomakro_get_teacher_available_days',
                initTime: this.newClassFormatted.initTime,
                endTime: this.newClassFormatted.endTime,
                instructorId: this.instructorId
            }
        },
        createClassParameters() {
            return {
                ...wsStaticParams,
                wsfunction: 'local_grupomakro_create_class',
                ...this.newClassFormatted
            }
        },
        classError() {
            if (!this.newClassEvent) return true
            if (this.newClassEvent.start === this.newClassEvent.end) return 'La fecha de inicio y finalización no puede ser iguales.'
            if (!this.isRangeContained) return 'El rango horario escogido no se ajusta a la disponibilidad.'
            if (this.createClassError) return this.createClassError;
            return false
        },
        careerItems() {
            return Object.keys(this.instructorCareers).map(career => ({ value: this.instructorCareers[career].lpid, label: career }));
        },


        // The label for the selected day, which is displayed above the calendar.
        dayLabel() {
            return new Date(this.selectedDay).toLocaleDateString('en-US', { weekday: 'narrow' });
        },
        // This method returns the calendar instance if it is ready to use, otherwise it returns null.
        cal() {
            return this.ready ? this.$refs.calendar : null
        },
        // Returns the current position of time on the Y axis of the calendar.
        nowY() {
            return this.cal ? this.cal.timeToY(this.cal.times.now) + 'px' : '-10px'
        },

        events() {
            return this.newClassEvent ? [this.newClassEvent] : [];
        },
        periodItems() {
            return this.periods.map(period => ({ value: period.id, label: period.name }))
        },
        courseItems() {
            return this.courses.map(course => ({ value: course.id, label: course.name }))
        },
        selectedDayLabel() {
            const date = new Date(this.selectedDay);
            const dayOfWeekIndex = date.getDay();
            return this.daysOfWeekLabels[dayOfWeekIndex];
        },
        timeRange() {
            if (!this.newClassEvent) return
            const options = { hour: 'numeric', minute: 'numeric', hour12: true };
            const startTime = new Date(this.newClassEvent.start).toLocaleTimeString('en-US', options);
            const endTime = new Date(this.newClassEvent.end).toLocaleTimeString('en-US', options);
            return `${startTime} - ${endTime}`;
        },

        isRangeContained() {
            if (!this.newClassEvent) return true
            const stDate = new Date(this.newClassEvent.start);
            const etDate = new Date(this.newClassEvent.end);

            for (const range of this.hoursFree) {
                const startParts = range.startTime.split(':');
                const startHours = parseInt(startParts[0], 10);
                const startMinutes = parseInt(startParts[1], 10);

                const endParts = range.endTime.split(':');
                const endHours = parseInt(endParts[0], 10);
                const endMinutes = parseInt(endParts[1], 10);

                if (stDate.getHours() > startHours || (stDate.getHours() === startHours && stDate.getMinutes() >= startMinutes)) {
                    if (etDate.getHours() < endHours || (etDate.getHours() === endHours && etDate.getMinutes() <= endMinutes)) {
                        return true;

                    }
                }
            }
            return false;
        },
        selectedDaysFormatted() {
            if (!this.newClass.classDays) return undefined
            let classDaysString = ''
            this.daysOfWeekLabels.forEach(day => {
                classDaysString += this.newClass.classDays.includes(day) ? '1/' : '0/'
            })
            return classDaysString.slice(0, -1)
        },
        newClassFormatted() {

            if (!this.newClassEvent) return undefined
            const initHour = new Date(this.newClassEvent.start).getHours().toString().padStart(2, '0');
            const inittMinute = new Date(this.newClassEvent.start).getMinutes().toString().padStart(2, '0');
            const initTime = `${initHour}:${inittMinute}`;


            const endHour = new Date(this.newClassEvent.end).getHours().toString().padStart(2, '0');
            const endMinute = new Date(this.newClassEvent.end).getMinutes().toString().padStart(2, '0');
            const endTime = `${endHour}:${endMinute}`;

            return {
                name: this.newClass.name,
                type: this.newClass.type,
                learningPlanId: this.newClass.learningPlanId,
                periodId: this.newClass.periodId,
                courseId: this.newClass.courseId,
                instructorId: this.instructorId,
                initTime,
                endTime,
                classDays: this.selectedDaysFormatted,
                classroomId: this.newClass.classroomId ? this.newClass.classroomId : ''
            }
        },
        showClassroomSelector() {
            return this.newClass.type === 0 || this.newClass.type === 2
        },
    },
    watch: {
        'newClass.name': function handler(newVal) {
            if (this.newClassEvent) this.newClassEvent.name = newVal;
        },
        createClassError: function handler(newVal) {
            if (newVal) {
                setTimeout(() => this.createClassError = undefined, 6000)
            }
        }
    }
})