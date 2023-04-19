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
                  locale="es"
                  :weekdays="weekdays"
                  @change="fetchEvents"
                >
                    <template v-slot:day-label="{date,day}">
                        <v-btn
                           small
                           fab
                           type="button"
                           @click="toggleDayFree(date,day)"
                           :class="(daysFree.indexOf(date.toString()) !== -1) ? 'success' : 'transparent elevation-0'"
                           :style="(daysFree.indexOf(date.toString()) !== -1) ? 'event-pointer: none !important;' : ''"
                           >
                            <span class="v-btn__content">{{day}}</span>
                        </v-btn>
                        <div class="d-flex justify-center mt-2">
                            <v-chip
                              v-if="daysFree.indexOf(date.toString()) !== -1"
                              color="success"
                              label
                              text-color="white"
                              small
                              class="mt-1"
                            >
                              Disponible
                            </v-chip>
                        </div>
                    </template>
                </v-calendar>
            </v-sheet>
        </v-col>
        <availabilitymodal v-if="dayModal" :dayselected="dayTo" @close-dialog="closeDialog"></availabilitymodal>
    </v-row>
    `,
    data(){
        return{
            focus: new Date().toISOString().substr(0,10),
            events: [
                
            ],
            items:[
                {id: 1, text: 'Artur R. Mendoza', value: 'Artur R. Mendoza'},
                {id: 1, text: 'Artur R. Mendoza', value: 'Artur R. Mendoza'},
                {id: 2, text: 'Jorge N. Woods', value: 'Jorge N. Woods'},
                {id: 3, text: 'George R. Mendoza', value: 'George R. Mendoza'},
            ],
            select: null,
            weekdays: [
                1, 2, 3, 4, 5, 6, 0
            ],
            users:[
                {
                    name: 'Artur R. Mendoza',
                    daysFree:{
                        '2023-03-06': ['08:00','09:30','10:00','11:00'],
                        '2023-03-08': ['08:00','09:30','10:00','11:00'],
                        '2023-03-10': ['08:00','09:30','10:00','11:00'],
                        '2023-03-17': ['08:00','09:30','10:00','11:00']
                    },
                    events:[
                        {
                            name: 'Maquinaría',
                            instructor: 'Artur R. Mendoza',
                            details: 'Virtual',
                            color: '#E5B751',
                            start: '2023-03-13 09:15',
                            end: '2023-03-13 11:30',
                            days: 'Lunes - Miércoles - Viernes',
                            hour: '09:15 - 11:30',
                        },
                        {
                            name: 'Maquinaría',
                            instructor: 'Artur R. Mendoza',
                            details: 'Virtual',
                            color: '#E5B751',
                            start: '2023-03-15 09:15',
                            end: '2023-03-15 11:30',
                            days: 'Lunes - Miércoles - Viernes',
                            hour: '09:15 - 11:30'
                        },
                        {
                            name: 'Maquinaría',
                            instructor: 'Artur R. Mendoza',
                            details: 'Virtual',
                            color: '#E5B751',
                            start: '2023-03-20 09:15',
                            end: '2023-03-20 11:30',
                            days: 'Lunes - Miércoles - Viernes',
                            hour: '09:15 - 11:30'
                        },
                        {
                            name: 'Maquinaría',
                            instructor: 'Artur R. Mendoza',
                            details: 'Virtual',
                            color: '#E5B751',
                            start: '2023-03-22 09:15',
                            end: '2023-03-22 11:30',
                            days: 'Lunes - Miércoles - Viernes',
                            hour: '09:15 - 11:30'
                        },
                        {
                            name: 'Maquinaría',
                            instructor: 'Artur R. Mendoza',
                            details: 'Virtual',
                            color: '#E5B751',
                            start: '2023-03-24 09:15',
                            end: '2023-03-24 11:30',
                            days: 'Lunes - Miércoles - Viernes',
                            hour: '09:15 - 11:30'
                        }
                    ]
                },
                {
                    name: 'Jorge N. Woods',
                    daysFree:[
                        '2023-03-08 09:15',
                        '2023-03-09',
                        '2023-03-15',
                        '2023-03-16'
                    ],
                    events:[]
                },
                {
                    name: 'George R. Mendoza',
                    daysFree:[
                        '2023-03-09',
                        '2023-03-11',
                        '2023-03-23',
                        '2023-03-25'
                    ],
                    events:[]
                },
            ],
            daysFree: [],
            dayModal: false,
            dayTo: undefined
        }
    },
    mounted () {
        this.$refs.calendar.checkChange()
    },
    methods: {
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
                        console.log('entra')
                        element.daysFree.forEach((day) =>{
                            this.daysFree.push(day)
                        })
                        this.events = element.events
                    }
                    
                })
            }
        },
        fetchEvents ({ start, end }) {
            const events = []
          this.events = events
        },
        toggleDayFree(date,day,time){
            // console.log(date,day, time)
            this.daysFree.indexOf(date.toString()) !== -1 ? this.dayModal = true : this.dayModal = false
            this.dayTo = date
        },
        closeDialog(){
            this.dayModal = false
        }
    },
})