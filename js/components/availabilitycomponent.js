Vue.component('availabilitycalendar',{
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
                        <v-icon small>mdi-chevron-left</v-icon>
                    </v-btn>
                    <v-btn
                      fab
                      text
                      small
                      color="grey darken-2"
                      @click="next"
                    >
                        <v-icon small>mdi-chevron-right</v-icon>
                    </v-btn>
                    <v-toolbar-title v-if="$refs.calendar">
                        {{ $refs.calendar.title }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-combobox
                      v-model="select"
                      :items="items"
                      :label="lang.instructors"
                      outlined
                      dense
                      hide-details
                      class="mr-2 instructor-select"
                      @input="selectInstructor"
                    ></v-combobox>
                </v-toolbar>
            </v-sheet>
            <v-sheet height="600" class="px-4">
                <v-calendar
                  ref="calendar"
                  v-model="focus"
                  color="primary"
                  type="month"
                  :events="events"
                  locale="en-US"
                  :weekdays="weekdays"
                  @click:day="showEvent"
                >
                    <template v-slot:day-label="{date,day}">
                        <v-btn
                           x-small
                           fab
                           type="button"
                           @click="toggleDayFree(date,day)"
                           :class="(daysFree.indexOf(date.toString()) !== -1) ? 'availabilityColor rounded-circle' : 'transparent elevation-0'"
                           :style="(daysFree.indexOf(date.toString()) !== -1) ? 'event-pointer: none !important;' : ''"
                           >
                            <span class="v-btn__content" :class="(daysFree.indexOf(date.toString()) !== -1) ? 'black--text' : ''">{{day}}</span>
                        </v-btn>
                        <div class="d-flex justify-center mt-2">
                            <v-chip
                              v-if="daysFree.indexOf(date.toString()) !== -1"
                              color="availabilityColor"
                              label
                              x-small
                              class="mt-0 mb-1 black--text"
                            >
                              {{lang.available}}
                            </v-chip>
                        </div>
                    </template>
                </v-calendar>
            </v-sheet>
        </v-col>
        <availabilitymodal v-if="dayModal" :dayselected="dayTo" :hoursFree="times" @close-dialog="closeDialog"></availabilitymodal>
    </v-row>
    `,
    data(){
        return{
            focus: new Date().toISOString().substr(0,10),
            type: 'month',
            events: [],
            items: window.instructorItems,
            select: null,
            weekdays: [
                1, 2, 3, 4, 5, 6, 0
            ],
            users:[],
            siteUrl: window.location.origin + '/webservice/rest/server.php',
            token: '33513bec0b3469194c7756c29bf9fb33',
            daysFree: [],
            dayModal: false,
            dayTo: '',
            selectedEvent: {},
            selectedElement: null,
            selectedOpen: false,
            hoursFree: [],
            times: [],
            lang: window.strings
        }
    },
    created(){
        this.getDisponibilityData()
        
    },
    mounted () {
        this.$refs.calendar.checkChange();
        this.events = []
    },
    methods: {
        // This method calls a web service to get the instructors' availability information and adds it to the users array.
        getDisponibilityData(){
            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_teachers_disponibility_calendar',
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.disponibility)
                    console.log(data);
                    // Add the availability data for each instructor to the current instance's item array.
                    for (const response of data) {
                        const userDaysFree = [];
                    
                        for (const [day, hours] of Object.entries(response.daysFree)) {
                            const dayObj = {
                                day,
                                hours
                            };
                            userDaysFree.push(dayObj);
                        }
                        this.users.push({
                            name: response.name,
                            daysFree: userDaysFree,
                            events: response.events
                        })
                    }
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                console.error(error);
            });  
        },
        // This method sets the selected date on the calendar as the current date.
        setToday () {
            this.focus = ''
        },
        // This method displays the previous month, week, or day on the calendar.
        prev () {
            this.$refs.calendar.prev()
        },
        // This method displays the next month, week, or day on the calendar.
        next () {
            this.$refs.calendar.next()
        },
        // This method updates the availability information for the selected instructor 
        // and adds it to the daysFree and events arrays.
        selectInstructor(e){
            this.daysFree = []
            this.events = []
            if(e){
                this.users.forEach((element) => {
                    if(element.name == e.value ){
                        element.daysFree.forEach((day) =>{
                            this.daysFree.push(day.day)
                        })
                        
                        element.events.forEach((item) => {
                            this.events.push({
                                name: item.coursename,
                                instructor: item.instructorName,
                                details: item.typeLabel,
                                color: item.color,
                                start: item.start,
                                end: item.end,
                                days: item.classDaysES.join(" - "),
                                hour: item.timeRange,
                                timed: true,
                                modulename: item.modulename
                            })
                        })
                    }
                })
            }
        },
        // This method shows or hides the availability modal.
        toggleDayFree(date,day,time){
            this.daysFree.indexOf(date.toString()) !== -1 ? this.dayModal = true : this.dayModal = false
            this.dayTo = date
        },
        // This method closes the availability modal.
        closeDialog(){
            this.dayModal = false
        },
        // This method updates the availability information of the selected instructor based on the date selected in the calendar.
        showEvent ( date ) {
            if(this.select){
                const user = this.users.find(user => user.name === this.select.value)
                user.daysFree.forEach((element) => {
                    if(element.day === date.date){
                        this.hoursFree = element.hours
                    }
                })
                this.times = [];
                for (let i = 0; i < this.hoursFree.length; i += 2) {
                    this.times.push({
                        startTime: this.hoursFree[i],
                        endTime: this.hoursFree[i+1]
                    })
                }
            }else{
                return false
            }
        },
    },
})