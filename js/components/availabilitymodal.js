// Define a Vue component called "availabilitymodal".
Vue.component('availabilitymodal',{
    template: `
        <v-dialog
          v-model="dialog"
          persistent
          max-width="800"
        >
            <v-card max-width="800">
                <v-card-title class="text-h5">{{lang.available_hours}}</v-card-title>
                <v-card-text>
                    <v-sheet height="600">
                        <v-calendar
                            color="primary"
                            type="day"
                            :value="dayselected"
                            :interval-minutes="30"
                            :interval-count="36"
                            :event-color="getEventColor"
                            first-time="6"
                            ref="calendar"
                            v-model="value"
                            locale="en-US"
                          >
                            <template v-slot:interval="{ time, date, day, hour, minute }">
                                <div
                                    class="h-100 d-flex align-center justify-center"
                                    :style="getIntervalStyle(time)"
                                >
                                    <div v-if="getIntervalStyle(time).content" class="black--text">{{ getIntervalStyle(time).content }}</div>
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
                    </v-sheet>
                </v-card-text>
                <v-card-actions>
                  <v-spacer></v-spacer>
                  <v-btn
                    color="primary"
                    text
                    @click="dialog = false,$emit('close-dialog')"
                  >
                    {{lang.close}}
                  </v-btn>
                </v-card-actions>
          </v-card>
        </v-dialog>
    `,
    props:{
        // Define the "dayselected" prop as a string.
        dayselected:String,
        // Define the "hoursFree" prop as an array.
        hoursFree:Array
    },
    data(){
        return{
            dialog: true,
            value: '',
            ready: false,
            lang: window.strings
        }
    },
    created(){
        
    },
    computed:{
        // The label for the selected day, which is displayed above the calendar.
        dayLabel(){
          return new Date(this.dayselected).toLocaleDateString('en-US', { weekday: 'narrow' });    
        },
        // This method returns the calendar instance if it is ready to use, otherwise it returns null.
        cal () {
            return this.ready ? this.$refs.calendar : null
        },
        // Returns the current position of time on the Y axis of the calendar.
        nowY () {
            return this.cal ? this.cal.timeToY(this.cal.times.now) + 'px' : '-10px'
        },
    },
    mounted () {
        this.ready = true
        this.scrollToTime()
        this.updateTime()
    },
    methods: {
        // This method returns the current time in minutes.
        getCurrentTime () {
            return this.cal ? this.cal.times.now.hour * 60 + this.cal.times.now.minute : 0
        },
        // This method scrolls to the current time on the calendar. It does this by getting the current time and rounding it to the nearest hour. 
        // Then, set the calendar offset to that time.
        scrollToTime () {
            const time = this.getCurrentTime()
            const first = Math.max(0, time - (time % 30) - 30)
    
            this.cal.scrollToTime(first)
        },
        // This method updates the time every 60 seconds to keep the calendar in sync.
        updateTime () {
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
                        content: this.lang.available
                    };
                }
            }
            return {};
        },
    },
})