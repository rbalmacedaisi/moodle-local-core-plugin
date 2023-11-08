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
            selectedInstructorId:undefined
        }
    },
    created(){
        // this.getDisponibilityData();
    },
    mounted () {
        this.$refs.calendar.checkChange();
    },
    methods: {
        /**
         * Calls a web service to retrieve instructors' availability information and updates the userAvailabilityRecords array.
         */
        getDisponibilityData(instructorId){
            // URL for the API request.
            const url = this.siteUrl;
            
            // Create an object with the parameters required for the API call
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_teachers_disponibility_calendar',
                instructorId
            };
            
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                .then(response => {
                    // Parse the JSON data returned from the API.
                    const teachersAvailability = JSON.parse(response.data.disponibility)
                    
                    // Transform the data into a format suitable for the userAvailabilityRecords array.
                    this.userAvailabilityRecords = teachersAvailability.map(availabilityRecord => ({
                        id:availabilityRecord.id,
                        name:availabilityRecord.name,
                        events:availabilityRecord.events,
                        daysFree:Object.keys(availabilityRecord.daysFree).map(date=>({day:date,hours:availabilityRecord.daysFree[date]}))
                    }))
                })
                // Log any errors to the console in case of a request failure.
                .catch(error => {
                    console.error(error);
            });  
        },
        /**
         * Clear the current focus date to reset the calendar view to the current date.
         */
        setToday () {
            // Clear the focus to reset the calendar to the current date.
            this.focus = ''
        },
        /**
         * Navigate to the previous view or time period in the calendar.
         */
        prev () {
            // Use the Vue.js calendar component's 'prev' method to navigate back.
            this.$refs.calendar.prev()
        },
        /**
         * Navigate to the next view or time period in the calendar.
         */
        next () {
            // Use the Vue.js calendar component's 'next' method to navigate forward.
            this.$refs.calendar.next()
        },
        /**
         * Update the availability information for the selected instructor, adding it to the daysFree and events arrays.
         *
         * @param {Object} instructor - The selected instructor for which to retrieve availability data.
         */
        selectInstructor(instructor){
            // Return early if no instructor is selected.
            if (!instructor) return
            
            // Set the selected instructor's ID.
            this.selectedInstructorId = instructor.id
            
            // Clear the instructorCareers data.
            this.instructorCareers={}
            
            // Create parameters for making an API request.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_sc_learningplans_get_active_learning_plans',
                instructorId:instructor.id
                
            };
            
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(this.siteUrl, { params })
                .then(response => {
                    // Parse the JSON data returned from the API.
                    this.instructorCareers = JSON.parse(response.data.availablecareers)
                })
                // Log any errors to the console in case of a request failure.
                .catch(error => {
                console.error(error);
            });
            
            this.getDisponibilityData(instructor.id);
            
        },
        /**
         * Show or hide the availability modal for a specific date.
         *
         * @param {Date} date - The date for which to show or hide the availability modal.
         * @param {String} day - The day of the week associated with the date.
         * @param {Array} time - The available time slots for the selected date.
         */
        toggleDayFree(date,day,time){
            // Check if the date is in the list of available days, and set the dayModal accordingly.
            this.selectedInstructor.daysFree.indexOf(date.toString()) !== -1 ? this.dayModal = true : this.dayModal = false
            
            // Set the selected date for the availability modal.
            this.dayTo = date
        },
        /**
         * Close the availability modal.
         */
        closeDialog(){
            // Set the 'dayModal' property to false to hide the modal.
            this.dayModal = false
        },
        /**
         * Update the availability information of the selected instructor based on the date selected in the calendar.
         *
         * @param {Object} date - The date object representing the selected date in the calendar.
         */
        showEvent ( date ) {
            if(this.select){
                // Find the user's availability record based on the selected instructor's name.
                const user = this.userAvailabilityRecords.find(user => user.name === this.select.value)
                
                // Iterate through the daysFree array to find availability for the selected date.
                user.daysFree.forEach((element) => {
                    if(element.day === date.date){
                        // Update the hoursFree array with the available hours for the selected date.
                        this.hoursFree = element.hours
                    }
                })
                
                // Initialize the 'times' array to store time slots.
                this.times = [];
                
                // Split the 'hoursFree' array into pairs of start and end times.
                for (let i = 0; i < this.hoursFree.length; i += 2) {
                    this.times.push({
                        startTime: this.hoursFree[i],
                        endTime: this.hoursFree[i+1]
                    })
                }
            }else{
                // Return false if no instructor is selected
                return false
            }
        },
    },
    computed:{
        /**
         * Computed property that returns the language strings stored in the global 'strings' object.
         */
        lang(){
            return window.strings
        },
        /**
         * Computed property that constructs the API endpoint URL using the current window location.
         */
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php';
        },
        /**
         * Computed property that returns the list of instructors from the global 'instructorItems'.
         */
        instructors(){
            return window.instructorItems
        },
        /**
         * Computed property that retrieves and formats data for the selected instructor.
         */
        selectedInstructor(){
            const userAvailabilityRecord =  this.userAvailabilityRecords.find(userAvailabilityRecord => userAvailabilityRecord.id === this.selectedInstructorId )
            return {
                daysFree:userAvailabilityRecord?userAvailabilityRecord.daysFree.map(dayItem=>(dayItem.day)):[],
                events:userAvailabilityRecord?userAvailabilityRecord.events.map(({color,start,end,modulename,coursename,instructorName,typelabel,timeRange,classDaysES})=>({
                    color,
                    end,
                    start,
                    modulename,
                    name:coursename,
                    instructor:instructorName,
                    details:typelabel,
                    hour:timeRange,
                    days:classDaysES.join(" - "),
                    timed:true
                })):[]
            }
        },
        /**
         * Computed property that returns the authentication token from the global 'token'.
         */
        token(){
            return window.token;
        }
    },
})