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
                        Hoy
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
                      label="Instructores"
                      outlined
                      dense
                      hide-details
                      class="mr-2 instructor-select"
                      @input="selectInstructor"
                    ></v-combobox>
                </v-toolbar>
            </v-sheet>
            <v-sheet height="600">
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
                           :class="(daysFree.indexOf(date.toString()) !== -1) ? 'availabilityColor' : 'transparent elevation-0'"
                           :style="(daysFree.indexOf(date.toString()) !== -1) ? 'event-pointer: none !important;' : ''"
                           >
                            <span class="v-btn__content black--text">{{day}}</span>
                        </v-btn>
                        <div class="d-flex justify-center mt-2">
                            <v-chip
                              v-if="daysFree.indexOf(date.toString()) !== -1"
                              color="availabilityColor"
                              label
                              x-small
                              class="mt-0 mb-1 black--text"
                            >
                              Disponible
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
            siteUrl: 'https://grupomakro-dev.soluttolabs.com/webservice/rest/server.php',
            token: '33513bec0b3469194c7756c29bf9fb33',
            daysFree: [],
            dayModal: false,
            dayTo: '',
            selectedEvent: {},
            selectedElement: null,
            selectedOpen: false,
            hoursFree: [],
            times: []
        }
    },
    created(){
        this.getDisponibilityData()
    },
    mounted () {
        this.$refs.calendar.checkChange();
        setTimeout(()=>{
            console.log(this.$refs.calendar.checkChange())
        },3000)
        console.log(this.$refs.calendar.checkChange())
    },
    methods: {
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
        getEventColor (event) {
            return event.color
        },
        setToday () {
            this.focus = ''
        },
        prev () {
            this.$refs.calendar.prev()
        },
        next () {
            this.$refs.calendar.next()
        },
        selectInstructor(e){
            console.log(e)
            this.daysFree = []
            this.events = []
            if(e){
                this.users.forEach((element) => {
                    if(element.name == e.value ){
                        element.daysFree.forEach((day) =>{
                            this.daysFree.push(day.day)
                        })
                        this.events = element.events
                    }
                })
            }
        },
        toggleDayFree(date,day,time){
            this.daysFree.indexOf(date.toString()) !== -1 ? this.dayModal = true : this.dayModal = false
            this.dayTo = date
        },
        closeDialog(){
            this.dayModal = false
        },
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