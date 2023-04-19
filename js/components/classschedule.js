Vue.component('classschedule',{
    template: `
        <div>
            <v-sheet :dark="dark">
                <v-toolbar
                    flat
                    id="first"
                >
                    <v-btn color="primary" dark class="mr-4" :href="urlClass" >
                        Agregar
                    </v-btn>
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
                      color="primary"
                      :href="urlAvailability"
                    >
                      Disponibilidad
                    </v-btn>
                </v-toolbar>
            </v-sheet>
            
            <v-row class="mb-1 mx-0 align-center">
                
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
                            <v-list-item-title>Día</v-list-item-title>
                        </v-list-item>
                        <v-list-item @click="type = 'week'">
                            <v-list-item-title>Semana</v-list-item-title>
                        </v-list-item>
                        <v-list-item @click="type = 'month'">
                            <v-list-item-title>Mes</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-menu>
                <v-spacer></v-spacer>
                <v-col cols="3">
                    <v-combobox
                      v-if="!rolInstructor"
                      v-model="selectedInstructors"
                      :items="instructors"
                      label="Instructores"
                      outlined
                      dense
                      hide-details
                      class="mr-2"
                      clearable
                      multiple
                    ></v-combobox>
                </v-col>
                <v-col cols="3">
                    <v-combobox
                      v-model="selectclass"
                      :items="classitems"
                      label="Clases programadas"
                      multiple
                      outlined
                      dense
                      hide-details
                      class="mr-2"
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
                    @change="getEvents"
                >
                    <template v-slot:event="{ event }">
                      <div class="v-event-draggable">
                        <strong>{{ event.name }}</strong
                        ><br />
                        {{ formatEventTime(event.start) }} -
                        {{ formatEventTime(event.end) }}
                      </div>
                    </template>
                </v-calendar>
                <v-menu
                    v-model="selectedOpen"
                    :close-on-content-click="false"
                    :activator="selectedElement"
                    offset-x
                >
                    <v-card
                      color="grey lighten-4"
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
                                        <v-list-item>
                                          <v-list-item-icon class="mr-2">
                                            <v-icon >mdi-calendar-edit</v-icon>
                                          </v-list-item-icon>
                                          <v-list-item-content>
                                            <v-list-item-title>Editar</v-list-item-title>
                                          </v-list-item-content>
                                        </v-list-item>
                                        <v-list-item>
                                          <v-list-item-icon class="mr-2">
                                            <v-icon >mdi-trash-can-outline</v-icon>
                                          </v-list-item-icon>
                                          <v-list-item-content>
                                            <v-list-item-title>Eliminar</v-list-item-title>
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
                                      Reprogramar
                                    </v-btn>
                                </template>
                        
                                <v-card>
                                    <v-card-title class="text-h5 text-white" style="background: #e5b751;" >
                                      {{selectedEvent.name}}
                                    </v-card-title>
                        
                                    <v-card-text>
                                      <v-row class="pt-3">
                                        <v-col
                                          cols="12"
                                          md="12"
                                        >
                                          <v-textarea
                                            name="input-7-1"
                                            label="Describa el motivo para reprogramar"
                                            value=""
                                            rows="2"
                                            hide-details
                                            color="#e5b751"
                                          ></v-textarea>
                                        </v-col>
                                      </v-row>
                                    </v-card-text>
                        
                                    <v-divider></v-divider>
                        
                                    <v-card-actions>
                                      <v-spacer></v-spacer>
                                      <v-btn
                                        color="#e5b751"
                                        outlined
                                        small
                                        @click="dialog = false"
                                      >
                                        Cancelar
                                      </v-btn>
                                      
                                      <v-btn
                                        color="#e5b751"
                                        small
                                        @click="sendSolit"
                                      >
                                        Aceptar
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
                        </v-card-text>
                        <v-card-actions class="d-flex justify-end">
                            <v-btn
                              text
                              color="secondary"
                              @click="selectedOpen = false"
                            >
                              Cerrar
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
                month: 'Mes',
                week: 'Semana',
                day: 'Día',
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
            urlClass: '',
            urlAvailability: '',
            URLdomain: window.location.origin,
            token: '0a9e53bb26a56bcb930002d6a7d6392a'
        }
    },
    props:{
        
    },
    created(){
        this.classitems = window.classItems;
        this.instructors = window.instructorItems;
        this.rolInstructor = false //window.rolInstructor===1;
        this.getEvents();
        var URLdomain = window.location.origin;
        this.urlClass =  'classmanagement.php'
        this.urlAvailability = 'availability.php'
    },
    mounted(){
        this.$refs.calendar.checkChange();
    },  
    methods:{
        getEvents(){
            this.events = []
            
            const year = new Date().getFullYear()
            let month = new Date().getMonth()
            let currentMonth = 0
            month > 0 ? currentMonth = month +1 : currentMonth = 0
            const url = this.URLdomain +
                '/webservice/rest/server.php?wstoken='+ this.token + 
                '&moodlewsrestformat=json&wsfunction=local_grupomakro_calendar_get_calendar_events'; 
            fetch(url)
            .then(res => res.json())
            .then(res => {
                const data = JSON.parse(res.events)
                data.forEach((element) => {
                    this.events.push({
                        name: element.coursename,
                        instructor: element.instructorName,
                        details: element.typeLabel,
                        color: element.color,
                        start: element.initDate,
                        end: element.endDate,
                        days: element.classDaysES.join(" - "),
                        hour: element.timeRange,
                        timed: true,
                        modulename: element.modulename
                    })
                })
            })
            .catch( err => console.error(err))
        },
        
        viewDay ({ date }) {
            this.focus = date
            this.type = 'day'
        },
        setToday () {
            this.focus = this.today
        },
        prev () {
            this.$refs.calendar.prev()
        },
        next () {
            this.$refs.calendar.next()
        },
        getEventColor (event) {
            return event.color
        },
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
        updateRange ({ start, end }) {
            // You could load events from an outside source (like database) now that we have the start and end dates on the calendar
            this.start = start
            this.end = end
        },
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
        sendSolit(){
            this.dialog = false;
            this.dialogconfirm = true;
        },
        hidenDialog(){
            this.dialogconfirm = false;
            this.reschedulemodal = false
        },
        formatEventTime(date) {
          return new Date(date).toLocaleTimeString("es-CO", {
            hour: "2-digit",
            minute: "2-digit",
            hour12: true,
          });
        },
    },
    computed: {
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
    },
    
})
