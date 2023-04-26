Vue.component('availabilitymodal',{
    template: `
        <v-dialog
          v-model="dialog"
          persistent
          max-width="800"
        >
            <v-card max-width="800">
                <v-card-title class="text-h5">Horas Disponibles</v-card-title>
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
                                    class="h-100"
                                    :style="getIntervalStyle(time)"
                                >
                                    <div v-if="getIntervalStyle(time).content">{{ getIntervalStyle(time).content }}</div>
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
                    Cerrar
                  </v-btn>
                </v-card-actions>
          </v-card>
        </v-dialog>
    `,
    props:{
        dayselected:String,
        hoursFree:Array
    },
    data(){
        return{
            dialog: true,
            value: '',
            ready: false,
        }
    },
    created(){
        
    },
    computed:{
        dayLabel(){
          return new Date(this.dayselected).toLocaleDateString('en-US', { weekday: 'narrow' });    
        },
        cal () {
            return this.ready ? this.$refs.calendar : null
        },
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
        getCurrentTime () {
            return this.cal ? this.cal.times.now.hour * 60 + this.cal.times.now.minute : 0
        },
        scrollToTime () {
            const time = this.getCurrentTime()
            const first = Math.max(0, time - (time % 30) - 30)
    
            this.cal.scrollToTime(first)
        },
        updateTime () {
            setInterval(() => this.cal.updateTimes(), 60 * 1000)
        },
        getIntervalStyle(time) {
            for (let i = 0; i < this.hoursFree.length; i++) {
                const element = this.hoursFree[i];
                if (time >= element.startTime && time < element.endTime) {
                    return {
                        background: '#7ef2a8',
                        content: 'Disponible'
                    };
                }
            }
            return {};
        },
        getEventColor(event) {
          return '#ffcc00';
        }
    },
})