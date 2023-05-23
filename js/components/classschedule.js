Vue.component('classschedule',{
    template: `
        <div>
            <v-sheet :dark="dark">
                <v-toolbar
                    flat
                    id="first"
                >
                    <v-btn v-if="!rolInstructor" color="primary" dark class="mr-4" :href="urlClass" >
                        {{lang.add}}
                    </v-btn>
                    <v-btn
                      outlined
                      class="mr-4"
                      color="grey darken-2"
                      @click="setToday"
                    >
                      {{lang.today}}
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
                    <v-btn
                      v-if="!rolInstructor"
                      color="primary"
                      :href="urlAvailability"
                    >
                      {{lang.availability}}
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
                            <span>{{ typeToLabel[type] }}</span>
                            <v-icon right>
                                mdi-menu-down
                            </v-icon>
                        </v-btn>
                    </template>
                    
                    <v-list>
                        <v-list-item @click="type = 'day'">
                            <v-list-item-title>{{lang.day}}</v-list-item-title>
                        </v-list-item>
                        <v-list-item @click="type = 'week'">
                            <v-list-item-title>{{lang.week}}</v-list-item-title>
                        </v-list-item>
                        <v-list-item @click="type = 'month'">
                            <v-list-item-title>{{lang.month}}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-menu>
                <v-spacer></v-spacer>
                <v-col v-if="!rolInstructor" cols="12" sm="3" md="3" lg="2" class="px-1">
                    <v-combobox
                      v-model="selectedInstructors"
                      :items="instructors"
                      :label="lang.instructors"
                      outlined
                      dense
                      hide-details
                      clearable
                      multiple
                    ></v-combobox>
                </v-col>
                <v-col cols="12" sm="3" md="3" :lg="rolInstructor ? '3' : '2'" class="px-1">
                    <v-combobox
                      v-model="selectclass"
                      :items="classitems"
                      :label="lang.scheduledclasses"
                      multiple
                      outlined
                      dense
                      hide-details
                      small-chips
                      clearable
                      @input="handleInput"
                    ></v-combobox>
                </v-col>
            </v-row>
            
            <v-sheet height="800">
                <v-calendar
                    ref="calendar"
                    v-model="focus"
                    color="primary"
                    @click:event="showEvent"
                    @click:more="viewDay"
                    @click:date="viewDay"
                    locale="en-US"
                    :short-weekdays="false"
                    :events="filteredEvents"
                    :type="type"
                    first-time="7"
                    interval-count="14"
                    :weekdays="weekdays"
                >
                    <template v-slot:event="{ event }">
                        <div class="v-event-draggable">
                            <strong>{{ event.name }}</strong><br />
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
                    v-model="selectedOpen"
                    :close-on-content-click="false"
                    :activator="selectedElement"
                    :max-width="type == 'day' ? '300px' : '100%'"
                >
                    <v-card
                      min-width="300px"
                      flat
                      :max-width="type == 'day' ? '300px' : '100%'"
                      
                    >
                        <v-toolbar
                            :color="selectedEvent.color"
                            dark
                        >
                            <v-toolbar-title class="pl-2" v-html="selectedEvent.name"></v-toolbar-title>
                            <v-spacer></v-spacer>
                            <v-menu
                              v-if="!rolInstructor"
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
                                        <v-list-item @click="editEvent(selectedEvent)">
                                          <v-list-item-icon class="mr-2">
                                            <v-icon >mdi-calendar-edit</v-icon>
                                          </v-list-item-icon>
                                          <v-list-item-content>
                                            <v-list-item-title>{{lang.edit}}</v-list-item-title>
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
                                      v-if="rolInstructor"
                                      color="error"
                                      x-small
                                      v-bind="attrs"
                                      v-on="on"
                                    >
                                      {{lang.reschedule}}
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
                                                      :label="lang.causes_rescheduling"
                                                      multiple
                                                      :menu-props="{ bottom: true, offsetY: true }"
                                                      dense
                                                      :color="selectedEvent.color"
                                                      outlined
                                                      required
                                                      :rules="[v => !!v && v.length > 0 || lang.field_required]"
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
                                                              :label="lang.select_possible_date"
                                                              append-icon="mdi-calendar"
                                                              readonly
                                                              v-bind="attrs"
                                                              v-on="on"
                                                              :color="selectedEvent.color"
                                                              outlined
                                                              dense
                                                              required
                                                              :rules="[v => !!v || lang.field_required]"
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
                                                                {{lang.cancel}}
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
                                                              :label="lang.new_class_time"
                                                              append-icon="mdi-clock-time-four-outline"
                                                              readonly
                                                              v-bind="attrs"
                                                              v-on="on"
                                                              :color="selectedEvent.color"
                                                              outlined
                                                              dense
                                                              required
                                                              :rules="[v => !!v || lang.field_required]"
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
                                            {{lang.cancel}}
                                        </v-btn>
                                      
                                        <v-btn
                                          small
                                          @click="sendSolit(selectedEvent)"
                                          class="rounded"
                                          text
                                          color="secondary"
                                        >
                                            {{lang.accept}}
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
                            <div v-if="rolInstructor">
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
                                      {{lang.activity}}
                                    </v-btn>
                                </div>
                            </div>
                            
                        </v-card-text>
                        <v-card-actions class="d-flex justify-end">
                            <v-btn
                              text
                              color="secondary"
                              @click="selectedOpen = false"
                            >
                              {{lang.close}}
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-menu>
            </v-sheet>
            <eventdialog v-if="dialogconfirm" @hiden-dialog="hidenDialog"></eventdialog>
        </div>
    `,
    data(){
        return{
            today: new Date().toISOString().substr(0,10),
            focus: new Date().toISOString().substr(0,10),
            type: 'week',
            typeToLabel: {
                month: window.strings.month,
                week: window.strings.week,
                day: window.strings.day,
            },
            classitems:undefined,
            instructors:undefined,
            rolInstructor: undefined,
            start: null,
            end: null,
            selectedEvent: {},
            selectedElement: null,
            selectedOpen: false,
            events: [],
            mode: 'column',
            dialog: false,
            selectedInstructors: [],
            selectclass:[],
            dark: false,
            listItem: '',
            dialogconfirm: false,
            reschedulemodal: false,
            urlClass: 'classmanagement.php',
            urlAvailability: 'availability.php',
            URLdomain: window.location.origin,
            token: '0deabd5798084addc080286f4acccd87',
            siteUrl: window.location.origin + '/webservice/rest/server.php',
            weekdays: [1, 2, 3, 4, 5, 6, 0],
            ready: false,
            lang: window.strings,
            userId: window.userid,
            value: '',
            items: [
                {
                    id: 1,
                    text: 'Cita Medica',
                    value: 'Cita Medica'
                },
                {
                    id: 2,
                    text: 'Fallas de Internet',
                    value: 'Fallas de Internet'
                },
                {
                    id: 3,
                    text: 'Incapacidad médica',
                    value: 'Incapacidad médica'
                },
                
            ],
            causes: [],
            date: (new Date(Date.now() - (new Date()).getTimezoneOffset() * 60000)).toISOString().substr(0, 10),
            menu: false,
            startTime: '',
            time: null,
            menu2: false,
            selectedcompetences: [],
            competences: [],
            valid: false,
            rescheduleError:undefined
        }
    },
    props:{
        
    },
    watch:{
        rescheduleError:function handler(newVal, oldVal){
            if(newVal){
                setTimeout(()=>this.rescheduleError = undefined, 6000)
            }
        }
    },
    created(){
        this.classitems = window.classItems;
        this.instructors = window.instructorItems;
        this.rolInstructor =false//window.rolInstructor===1;
        this.getEvents();
    },
    mounted(){
        this.$refs.calendar.checkChange();
        this.ready = true
        this.scrollToTime()
        this.updateTime()
    },  
    methods:{
        // This method makes an HTTP GET request to retrieve calendar events from the Moodle server. 
        // The received data is processed and relevant information is extracted from each event, which is added to the events array. 
        // This method also handles errors if the request fails.
        getEvents(){
            // Initialize the events property to an empty array.
            this.events = []
            
            // Get the Moodle site URL from the siteUrl property.
            const url = this.siteUrl;
            let params = {}
            if(this.rolInstructor){
                // Define the parameters of the HTTP request.
                params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_calendar_get_calendar_events',
                    userId: this.userId
                };
            }else{
                // Define the parameters of the HTTP request.
                params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_calendar_get_calendar_events',
                };
            }
            
            // Make an HTTP GET request with Axios.
            window.axios.get(url, { params })
                // If the request is successful, process the received data
                .then(response => {
                    // Convert the JSON response to an objec.
                    const data = JSON.parse(response.data.events)
                    // Iterate over each element in the received data.
                    data.forEach((element) => {
                        // Extract the relevant information from each event and add it to the events array.
                        this.events.push({
                            name: element.coursename,
                            instructor: element.instructorName,
                            details: element.typeLabel,
                            color: element.color,
                            start: element.start,
                            end: element.end,
                            days: element.classDaysES.join(" - "),
                            hour: element.timeRange,
                            timed: true,
                            modulename: element.modulename,
                            moduleId: element.moduleId,
                            bigBlueButtonActivityUrl: element.bigBlueButtonActivityUrl ? element.bigBlueButtonActivityUrl : null,
                            attendanceActivityUrl: element.attendanceActivityUrl ? element.attendanceActivityUrl : null,
                            classId: element.classId,
                            className: element.className,
                            sessionId: element.sessionId ? element.sessionId : null,
                            instructorId: element.userid,
                            visible: element.visible
                        })
                    })
                })
                // If the request fails, display the error on the console.
                .catch(error => {
                console.error(error);
            });
        },
        // This method updates the calendar view to display a specific day.
        viewDay ({ date }) {
            this.focus = date
            this.type = 'day'
        },
        // This method sets the calendar view to the current day.
        setToday () {
            this.focus = this.today
        },
        // This method navigates to the previous calendar view.
        prev () {
            this.$refs.calendar.prev()
        },
        // This method navigates to the next calendar view.
        next () {
            this.$refs.calendar.next()
        },
        // This method returns the color of a given event.
        getEventColor (event) {
            return event.color
        },
        // This method displays information about a specific event when it is clicked by the user.
        showEvent ({ nativeEvent, event }) {
            const open = () => {
                this.selectedEvent = event
                this.selectedElement = nativeEvent.target
                setTimeout(() => this.selectedOpen = true, 10)
            }

            if (this.selectedOpen) {
              this.selectedOpen = false
              setTimeout(open, 10)
            } else {
              open()
            }

            nativeEvent.stopPropagation()
        },
        // This method updates the start and end dates of the calendar range.
        updateRange ({ start, end }) {
            this.start = start
            this.end = end
        },
        // This method hides the current dialog box and displays the confirmation dialog box.
        async sendSolit(event){
            this.$refs.reschedulingform.validate()
            console.log(event)
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            if(this.valid){
                const config = {
                    headers: { 'Content-Type': 'multipart/form-data' },
                }
                const params = new FormData()
                params.append('wstoken',this.token)
                params.append('wsfunction',  'local_grupomakro_check_reschedule_conflicts')
                params.append('moodlewsrestformat', 'json')
                params.append('classId',event.classId)
                params.append('moduleId', event.moduleId)
                params.append('date',this.date)
                params.append('initTime', this.time)
                params.append('endTime', null)
                params.append('sessionId',event.sessionId)

                try {
                    const checkResponse= await window.axios.post(url, params,config)
                    console.log(checkResponse)
                    if(!checkResponse.data.status || checkResponse.data.status === -1) throw Error(checkResponse.data.message);
                    const sendRescheduleMessageParams = {
                        wstoken: this.token,
                        moodlewsrestformat: 'json',
                        wsfunction: 'local_grupomakro_send_reschedule_message',
                        instructorId: event.instructorId,
                        classId: event.classId,
                        causes:this.causes.join(','),
                        moduleId: event.moduleId,
                        originalDate:event.start.split(" ")[0],
                        originalHour:event.hour,
                        sessionId: event.sessionId,
                        proposedDate:this.date,
                        proposedHour:this.time,
                    };
                    const messageResponse= await window.axios.get(url, { params:sendRescheduleMessageParams })
                    if (messageResponse.data.status===-1) throw Error(messageResponse.data.message)
                    this.dialog = false;
                    this.dialogconfirm = true;
                }
                catch (error){
                    this.rescheduleError = error.message
                }
            }
        },
        // This method hides the current dialog box and reschedule modal.
        hidenDialog(){
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
        getCurrentTime () {
            return this.cal ? this.cal.times.now.hour * 60 + this.cal.times.now.minute : 0
        },
        // This method scrolls the calendar to the current time.
        scrollToTime () {
            const time = this.getCurrentTime()
            const first = Math.max(0, time - (time % 30) - 30)
    
            this.cal.scrollToTime(first)
        },
        // This method updates the time displayed on the calendar every minute.
        updateTime () {
            setInterval(() => this.cal.updateTimes(), 60 * 1000)
        },
        // This method retrieves events from the server based on the selected classes and instructors, 
        // and filters the events that match the selected classes.
        handleInput(e){
            this.getEvents()
            let data = []
            if(e.length > 0){
                this.events.forEach((element) => {
                    e.forEach((item) =>{
                        if(element.name == item.value ){
                            data.push(element)
                        }
                    })
                })
            }
        },
        editEvent(event){
            console.log(event)
            window.location = this.URLdomain + '/local/grupomakro_core/pages/editclass.php?class_id=' 
            + event.classId + '&moduleId=' + event.moduleId + '&sessionId=' + event.sessionId
        }
    },
    computed: {
        // This method returns an array of events filtered based on the selections made by the user. 
        // If any instructor has been selected, it returns the events related to that instructor. 
        // If any class type has been selected, it returns the events related to that class type. 
        // If no selection has been made, returns all events. 
        filteredEvents() {
            let select = []
            
            if(this.selectedInstructors.length > 0){
                this.selectedInstructors.forEach((element) =>{
                    select.push(element.text)
                })
                return this.events.filter((event) =>
                    select.includes(event.instructor)
                );
            }
            
            if(this.selectclass.length > 0){
                this.selectclass.forEach((element) =>{
                    select.push(element.text)
                })
                return this.events.filter((event) =>
                    select.includes(event.name)
                );
            }
            
            if (this.selectedInstructors.length === 0 && this.selectclass.length === 0) {
              return this.events;
            }
        },
        // This method returns the calendar instance if it is ready to use, otherwise it returns null.
        cal () {
            return this.ready ? this.$refs.calendar : null
        },
        // This method Returns the current vertical position of the current time indicator on the calendar.
        nowY () {
            return this.cal ? this.cal.timeToY(this.cal.times.now) + 'px' : '-10px'
        },
        // This method returns a validation rule function for use with vee-validate library.
        // The function takes a value as input and returns a boolean indicating whether the value is non-empty or not.
        requiredRule() {
          return (value) => !!value || 'Este campo es requerido';
        },
        
        rescheduleCauses(){
            return window.rescheduleCauses.map(cause => ({text:cause.causename,id:cause.id,value:cause.id}));
        }
    },
    
})