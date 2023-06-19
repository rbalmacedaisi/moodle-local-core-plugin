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
                      :items="instructors"
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
                  :events="selectedInstructor.events"
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
                           :class="(selectedInstructor.daysFree.indexOf(date.toString()) !== -1) ? 'availabilityColor rounded-circle' : 'transparent elevation-0'"
                           :style="(selectedInstructor.daysFree.indexOf(date.toString()) !== -1) ? 'event-pointer: none !important;' : ''"
                           >
                            <span class="v-btn__content" :class="(selectedInstructor.daysFree.indexOf(date.toString()) !== -1) ? 'black--text' : ''">{{day}}</span>
                        </v-btn>
                        <div class="d-flex justify-center mt-2">
                            <v-chip
                              v-if="selectedInstructor.daysFree.indexOf(date.toString()) !== -1"
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
        <availabilitymodal v-if="dayModal" :dayselected="dayTo" :hoursFree="times" @close-dialog="closeDialog" :instructorId="selectedInstructorId" :instructorCareers="instructorCareers"></availabilitymodal>
    </v-row>
    `,
    data(){
        return{
            focus:new Date().toISOString().substr(0,10),
            type: 'month',
            select: null,
            weekdays: [
                1, 2, 3, 4, 5, 6, 0
            ],
            userAvailabilityRecords:[],
            dayModal: false,
            dayTo: undefined,
            selectedElement: null,
            selectedOpen: false,
            selectedEvent: {},
            hoursFree: [],
            times: [],
            instructorCareers:{},
            token: '33513bec0b3469194c7756c29bf9fb33',
            selectedInstructorId:undefined
        }
    },
    created(){
        this.getDisponibilityData() 
    },
    mounted () {
        this.$refs.calendar.checkChange();
    },
    computed:{
        lang(){
            return window.strings
        },
        
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php';
        },
        instructors(){
            return window.instructorItems
        },
        selectedInstructor(){
            const userAvailabilityRecord =  this.userAvailabilityRecords.find(userAvailabilityRecord => userAvailabilityRecord.id === this.selectedInstructorId )
            return {
                daysFree:userAvailabilityRecord?userAvailabilityRecord.daysFree.map(dayItem=>(dayItem.day)):[],
                events:userAvailabilityRecord?userAvailabilityRecord.events.map(({color,start,end,modulename,coursename,instructorName,typeLabel,timeRange,classDaysES})=>({
                    color,
                    end,
                    start,
                    modulename,
                    name:coursename,
                    instructor:instructorName,
                    details:typeLabel,
                    hour:timeRange,
                    days:classDaysES.join(" - "),
                    timed:true
                })):[]
            }
            
        }
        
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
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Converts the data returned from the API from JSON string format to object format.
                    const teachersAvailability = JSON.parse(response.data.disponibility)
                    // Add the availability data for each instructor to the current instance's item array.
                    
                    this.userAvailabilityRecords = teachersAvailability.map(availabilityRecord => ({
                        id:availabilityRecord.id,
                        name:availabilityRecord.name,
                        events:availabilityRecord.events,
                        daysFree:Object.keys(availabilityRecord.daysFree).map(date=>({day:date,hours:availabilityRecord.daysFree[date]}))
                    }))
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
        selectInstructor(instructor){
            if (!instructor) return
            this.selectedInstructorId = instructor.id
            this.instructorCareers={}
            
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_sc_learningplans_get_active_learning_plans',
                instructorId:instructor.id
                
            };
            
            window.axios.get(this.siteUrl, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Converts the data returned from the API from JSON string format to object format.
                    this.instructorCareers = JSON.parse(response.data.availablecareers)
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                console.error(error);
            });  
            
        },
        // This method shows or hides the availability modal.
        toggleDayFree(date,day,time){
            this.selectedInstructor.daysFree.indexOf(date.toString()) !== -1 ? this.dayModal = true : this.dayModal = false
            this.dayTo = date
        },
        // This method closes the availability modal.
        closeDialog(){
            this.dayModal = false
        },
        // This method updates the availability information of the selected instructor based on the date selected in the calendar.
        showEvent ( date ) {
            if(this.select){
                const user = this.userAvailabilityRecords.find(user => user.name === this.select.value)
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