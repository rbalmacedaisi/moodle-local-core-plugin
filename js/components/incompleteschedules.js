Vue.component('incompleteschedules',{
    template: `
      <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                   :headers="headers"
                   :items="users"
                   class="elevation-1 paneltable"
                   :search="search"
                   dense
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>{{lang.users}}</v-toolbar-title>
                            <v-divider
                              class="mx-4"
                              inset
                              vertical
                            ></v-divider>
                            <v-spacer></v-spacer>
                        </v-toolbar>
                        
                        <v-row justify="start" class="ma-0 mr-3">
                            <v-col cols="4">
                                <v-text-field
                                   v-model="search"
                                   append-icon="mdi-magnify"
                                   :label="lang.search"
                                   hide-details
                                   outlined
                                   dense
                                ></v-text-field>
                            </v-col>
                        </v-row>
                    </template>
                    
                    <template v-slot:item.student="{ item }">
                        <v-list class="transparent">
                          <v-list-item class="pl-0">
                            <v-list-item-avatar>
                              <img :src="item.img" alt="picture">
                            </v-list-item-avatar>
    
                            <v-list-item-content>
                              <v-list-item-title>{{item.student}}</v-list-item-title>
                              <v-list-item-subtitle>{{item.email}}</v-list-item-subtitle>
                            </v-list-item-content>
                          </v-list-item>
                        </v-list>
                    </template>
                      
                    <template v-slot:item.actions="{ item }">
                        <v-tooltip bottom>
                          <template v-slot:activator="{ on, attrs }">
                            <v-icon
                             class="mr-2"
                             v-bind="attrs"
                             v-on="on"
                             @click="addschedule(item)"
                            >
                              mdi-calendar-arrow-right
                            </v-icon>
                          </template>
                          <span>{{ lang.add_schedules }}</span>
                        </v-tooltip>
                    </template>
                  
                    <template v-slot:no-data>
                        <span >{{lang.nodata}}</span>
                    </template>
                </v-data-table>
            </v-col>
            
            <v-dialog
              v-model="dialog"
              max-width="400px"
            >
                <v-card>
                    <v-card-title>
                        <span class="text-h5">{{ lang.add_schedules }}</span>
                    </v-card-title>
    
                    <v-card-text>
                        <v-row>
                            <v-col cols="12">
                                <v-list
                                  flat
                                  three-line
                                >
                            
                                  <v-list-item-group
                                    v-model="settings"
                                    multiple
                                    active-class=""
                                  >
                                    <v-list-item v-for="item in items" :key="item.id" >
                                      <template v-slot:default="{ active}">
                                        <v-list-item-action>
                                          <v-checkbox :input-value="active" ></v-checkbox>
                                        </v-list-item-action>
                            
                                        <v-list-item-content>
                                          <v-list-item-title>{{item.name}}</v-list-item-title>
                                          <v-list-item-subtitle>{{item.days}}</v-list-item-subtitle>
                                          <v-list-item-subtitle>{{item.start + ' - ' + item.end}}</v-list-item-subtitle>
                                        </v-list-item-content>
                                      </template>
                                    </v-list-item>
                            
                                  </v-list-item-group>
                                </v-list>
                            </v-col>
                        </v-row>
                    </v-card-text>
                    
                    <v-divider class="my-0"></v-divider>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                            <v-btn
                                color="primary"
                                text
                                @click="dialog = false"
                            >
                                {{ lang.cancel }}
                              </v-btn>
                            <v-btn
                                color="primary" text
                                @click="save"
                            >
                                {{ lang.save}}
                            </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-row>
    `,
    data(){
      return{
        headers: [
          {
            text: 'Estudiante',
            align: 'start',
            sortable: false,
            value: 'student',
          },
          { text: 'Horarios', value: 'schedules', sortable: false, align: 'center' },
          { text: 'Actions', value: 'actions', sortable: false },
        ],
        users: [
            /*{
                id: 1,
                img: 'https://cdn.vuetifyjs.com/images/lists/1.jpg',
                student: 'Jason Oner',
                email: 'jasononer@gmail.com',
                schedules: 2,
                selectedtimes:[
                    {
                        id: 1,
                        name: 'Introducción',
                        days: 'Lunes - Miércoles',
                        start: "10:00 am",
                        end: "12:00 pm",
                        instructor: "John Leider",
                        type: "Presencial",
                        quotas: 30,
                        users: 20,
                        waitingusers: 0,
                        isApprove: true,
                        classtype: 1
                    },
                    {
                        id: 5,
                        name: 'Medición de maquinaría',
                        days: 'Jueves - Viernes ',
                        start: "07:00 am",
                        end: "09:00 am",
                        instructor: "Nataly Hoyos",
                        type: "Virtual",
                        quotas: 30,
                        users: 1,
                        waitingusers: 5,
                        isApprove: false,
                        classtype: 2
                    }
                ]
            },
            {
                id: 2,
                img: 'https://cdn.vuetifyjs.com/images/lists/2.jpg',
                student: 'Mike Carlson',
                email: 'mikecarlson@gmail.com',
                schedules: 1,
                selectedtimes:[
                    {
                        id: 1,
                        name: 'Introducción',
                        days: 'Lunes - Miércoles',
                        start: "10:00 am",
                        end: "12:00 pm",
                        instructor: "John Leider",
                        type: "Presencial",
                        quotas: 30,
                        users: 20,
                        waitingusers: 0,
                        isApprove: true,
                        classtype: 1
                    },
                ]
            },
            {
                id: 3,
                img: 'https://cdn.vuetifyjs.com/images/lists/3.jpg',
                student: 'Cindy Baker',
                email: 'cindybaker@gmail.com',
                schedules: 2,
                selectedtimes:[
                    {
                        id: 1,
                        name: 'Introducción',
                        days: 'Lunes - Miércoles',
                        start: "10:00 am",
                        end: "12:00 pm",
                        instructor: "John Leider",
                        type: "Presencial",
                        quotas: 30,
                        users: 20,
                        waitingusers: 0,
                        isApprove: true,
                        classtype: 1
                    },
                    {
                        id: 5,
                        name: 'Medición de maquinaría',
                        days: 'Jueves - Viernes ',
                        start: "07:00 am",
                        end: "09:00 am",
                        instructor: "Nataly Hoyos",
                        type: "Virtual",
                        quotas: 30,
                        users: 1,
                        waitingusers: 5,
                        isApprove: false,
                        classtype: 2
                    }
                ]
            },
            {
                id: 4,
                img: 'https://cdn.vuetifyjs.com/images/lists/4.jpg',
                student: 'Ali Connors',
                email: 'aliconnors@gmail.com',
                schedules: 0,
                selectedtimes: []
            },*/
        ],
        deleteusers: false,
        itemdelete: {},
        search: '',
        menu: false,
        itemselected: {},
        schedules: [
            {
                id: 1,
                name: 'Introducción',
                days: 'Lunes - Miércoles',
                start: "10:00 am",
                end: "12:00 pm",
                instructor: "John Leider",
                type: "Presencial",
                picture: 'https://berrydashboard.io/vue/assets/avatar-1-8ab8bc8e.png',
                quotas: 30,
                users: 20,
                waitingusers: 0,
                isApprove: true,
                classtype: 1
            },
            {
                id: 2,
                name: 'Introducción',
                days: 'Martes - Jueves',
                start: "07:00 am",
                end: "09:00 am",
                instructor: "Ximena Rincon",
                type: "Virtual",
                picture: 'https://berrydashboard.io/vue/assets/avatar-3-7182280e.png',
                quotas: 30,
                users: 32,
                waitingusers: 5,
                isApprove: false,
                classtype: 1
            },
            {
                id: 3,
                name: 'Introducción',
                days: 'Jueves - Viernes ',
                start: "07:00 am",
                end: "09:00 am",
                instructor: "Luz Lopez",
                type: "Virtual",
                picture: 'https://berrydashboard.io/vue/assets/avatar-7-8fe392c1.png',
                quotas: 30,
                users: 1,
                waitingusers: 5,
                isApprove: false,
                classtype: 1
            },
            {
                id: 4,
                name: 'Introducción',
                days: 'Sabado',
                start: "07:00 am",
                end: "09:00 am",
                instructor: "Luz Lopez",
                type: "Presencial",
                picture: 'https://berrydashboard.io/vue/assets/avatar-7-8fe392c1.png',
                quotas: 30,
                users: 0,
                waitingusers: 0,
                isApprove: false,
                classtype: 1
            },
            {
                id: 5,
                name: 'Medición de maquinaría',
                days: 'Jueves - Viernes ',
                start: "07:00 am",
                end: "09:00 am",
                instructor: "Nataly Hoyos",
                type: "Virtual",
                picture: 'https://berrydashboard.io/vue/assets/avatar-7-8fe392c1.png',
                quotas: 30,
                users: 1,
                waitingusers: 5,
                isApprove: false,
                classtype: 2
            },
            {
                id: 6,
                name: 'Matemáticas',
                days: 'Jueves - Viernes ',
                start: "07:00 am",
                end: "09:00 am",
                instructor: "Nataly Hoyos",
                type: "Virtual",
                picture: 'https://berrydashboard.io/vue/assets/avatar-7-8fe392c1.png',
                quotas: 30,
                users: 1,
                waitingusers: 5,
                isApprove: false,
                classtype: 3
            },
        ],
        dialog: false,
        settings: [],
        items: []
      }
    },
    props:{},
    created(){
    }, 
    mounted(){},  
    methods:{
        addschedule(item){
            this.itemselected = item
            this.dialog = true
            this.items = [];

            for (const schedule of this.schedules) {
                const isDifferent = !item.selectedtimes.some(selected => selected.classtype === schedule.classtype );
                if (isDifferent ) {
                    this.items.push(schedule);
                }
                
            }
        },
        save(){},
        updateSettings() {
            this.settings = this.items.filter(item => item.selected);
        }
    },
    computed: {
        lang(){
            return window.strings
        },
    },
    watch: {
    
    }
})