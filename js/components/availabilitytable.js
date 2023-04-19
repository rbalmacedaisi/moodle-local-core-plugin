Vue.component('availabilitytable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                    :headers="headers"
                    :items="dessertsSorted"
                    class="elevation-1"
                    dense
                >
                    <template v-slot:top>
                        <v-toolbar
                            flat
                        >
                            <v-toolbar-title>Disponibilidad</v-toolbar-title>
                            <v-divider
                              class="mx-4"
                              inset
                              vertical
                            ></v-divider>
                            <span class="font-weight-bold">Cuatrimestre 1 - 2023</span>
                            <v-spacer></v-spacer>
                            <v-dialog
                              v-model="dialog"
                              max-width="700px"
                              persistent
                            >
                                <template v-slot:activator="{ on, attrs }">
                                    <v-tooltip bottom>
                                        <template v-slot:activator="{ on, attrs }">
                                            <v-btn
                                              color="primary"
                                              dark
                                              class="mb-2"
                                              v-bind="attrs"
                                              v-on="on"
                                              @click="dialog = true"
                                              small
                                            >
                                              Agregar
                                            </v-btn>
                                        </template>
                                        <span>Agregar Disponibilidad</span>
                                    </v-tooltip>
                                </template>
                                
                                <v-card>
                                    <v-card-title>
                                        <span class="text-h5">{{ formTitle }}</span>
                                    </v-card-title>
                    
                                    <v-card-text>
                                        <v-container>
                                            <v-row>
                                                <v-col
                                                    cols="12"
                                                    sm="6"
                                                    md="6"
                                                >
                                                    <v-select
                                                      :items="instructors"
                                                      label="Instructor"
                                                      outlined
                                                      dense
                                                      v-model="editedItem.name"
                                                    ></v-select>
                                                </v-col>
                                                <v-col
                                                  cols="12"
                                                  sm="6"
                                                  md="6"
                                                >
                                                    <v-combobox
                                                      v-model="selectedDays"
                                                      :items="daysOfWeek"
                                                      label="Días"
                                                      outlined
                                                      dense
                                                      hide-details
                                                      class="mr-2"
                                                      clearable
                                                      multiple
                                                      @change="addSchedules"
                                                    ></v-combobox>
                                            
                                                </v-col>
                                            </v-row>
                                            <v-divider v-if="selectedDays" class="my-0 mb-3"></v-divider>
                                            <v-row>
                                                <v-col cols="12">
                                                    <div v-for="(schedules, index) in schedulesPerDay" :key="index">
                                                        <h5>{{schedules.day}}</h5>
                                                        <div v-for="(schedules, index) in schedules.schedules" :key="index">
                                                            <div class="d-flex mt-5">
                                                              <v-text-field
                                                                v-model="schedules.startTime"
                                                                label="Hora de inicio"
                                                                type="time"
                                                                outlined
                                                                dense
                                                                hide-details
                                                                class="mr-2"
                                                                style="background-color: #71dc7421;"
                                                              ></v-text-field>
                                                              <v-text-field
                                                                v-model="schedules.timeEnd"
                                                                label="Hora de fin"
                                                                type="time"
                                                                outlined
                                                                dense
                                                                hide-details
                                                                style="background-color: #7199dc21;"
                                                                ></v-text-field>
                                                                <v-btn icon small color="error" fab @click="deleteField(schedules)">
                                                                  <v-icon>mdi-delete</v-icon>
                                                                </v-btn>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex justify-center mt-2">
                                                            <v-tooltip bottom>
                                                                <template v-slot:activator="{ on, attrs }">
                                                                    <v-btn x-small class="mx-2" color="primary" fab @click="addField(schedules)" v-bind="attrs"v-on="on">
                                                                        <v-icon>mdi-plus</v-icon>
                                                                    </v-btn>
                                                                </template>
                                                                <span>Agregar Horario</span>
                                                            </v-tooltip>
                                                        </div>
                                                    </div>
                                                </v-col>
                                            </v-row>
                                        </v-container>
                                    </v-card-text>
                    
                                <v-card-actions>
                                  <v-spacer></v-spacer>
                                  <v-btn
                                    color="blue darken-1"
                                    text
                                    @click="close"
                                  >
                                    Cancelar
                                  </v-btn>
                                  <v-btn
                                    color="blue darken-1"
                                    text
                                    @click="save"
                                  >
                                    Guardar
                                  </v-btn>
                                </v-card-actions>
                              </v-card>
                            </v-dialog>
                            
                            <v-dialog v-model="dialogDelete" max-width="500px">
                              <v-card>
                                <v-card-title class="text-h5">Are you sure you want to delete this item?</v-card-title>
                                <v-card-actions>
                                  <v-spacer></v-spacer>
                                  <v-btn color="blue darken-1" text @click="closeDelete">Cancel</v-btn>
                                  <v-btn color="blue darken-1" text @click="deleteItemConfirm">OK</v-btn>
                                  <v-spacer></v-spacer>
                                </v-card-actions>
                              </v-card>
                            </v-dialog>
                        </v-toolbar>
                    </template>
                    
                    <template v-slot:item.instructorName="{ item }">
                        <v-list class="transparent">
                          <v-list-item>
                            <v-list-item-avatar>
                              <img
                                :src="item.instructorPicture"
                                alt="John"
                              >
                            </v-list-item-avatar>
                
                            <v-list-item-content>
                              <v-list-item-title>{{item.instructorName}}</v-list-item-title>
                            </v-list-item-content>
                          </v-list-item>
                        </v-list>
                    </template>
                    
                    <template v-slot:item.availability="{ item }">
                        <instructoravailability :data="item"></instructoravailability>
                    </template>
                    
                    <template v-slot:item.actions="{ item }">
                        <v-icon
                            small
                            class="mr-2"
                            @click="editItem(item)"
                        >
                            mdi-pencil
                        </v-icon>
                        <v-icon
                            small
                            @click="deleteItem(item)"
                        >
                            mdi-delete
                        </v-icon>
                    </template>
                    
                    <template v-slot:no-data>
                        <v-btn
                            color="primary"
                            @click="initialize"
                        >
                            No hay datos
                        </v-btn>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>
    `,
    data(){
        return{
            dialog: false,
            dialogDelete: false,
            headers: [
                {
                    text: 'Instructor',
                    align: 'start',
                    sortable: false,
                    value: 'instructorName',
                },
                { text: 'Disponibilidad', value: 'availability',sortable: false },
                { text: 'Actions', value: 'actions', sortable: false },
            ],
            desserts: [],
            editedIndex: -1,
            editedItem: {
              every: []
            },
            defaultItem: {
                name: '',
                every: '',
                from: '',
                to: '',
            },
            instructors:undefined,
            start: null,
            end: null,
            daysOfWeek: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
            selectedDays: [],
            schedules: [],
            schedulesPerDay: []
        }
    },
    props:{
        
    },
    created(){
        this.initialize()
        this.instructors = window.instructorItems;
    },
    mounted(){
        
    },  
    methods:{
        initialize () {
            this.desserts = [
              {
                instructorName: 'Nataly Hoyos',
                instructorId: 1,
                instructorPicture: "https://cdn.vuetifyjs.com/images/john.jpg",
                disponibilityRecords:{
                  Lunes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ],
                  Martes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ]
                }
              },
              {
                instructorName: 'Ximena Rincon',
                instructorId: 2,
                instructorPicture: "https://cdn.vuetifyjs.com/images/john.jpg",
                disponibilityRecords:{
                  Lunes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ],
                  Martes:[
                    '09:00, 10:00',
                    '12:00, 14:00'
                  ]
                },
              },
            ]
        },
        editItem (item) {
            this.editedIndex = this.desserts.indexOf(item)
            this.editedItem = Object.assign({}, item)
            this.dialog = true
        },
        deleteItem (item) {
            this.editedIndex = this.desserts.indexOf(item)
            this.editedItem = Object.assign({}, item)
            this.dialogDelete = true
        },
        deleteItemConfirm () {
            this.desserts.splice(this.editedIndex, 1)
            this.closeDelete()
        },
        close () {
            this.dialog = false
            this.schedules = []
            this.$nextTick(() => {
                this.editedItem = Object.assign({}, this.defaultItem)
            this.editedIndex = -1
            })
        },
        closeDelete () {
            this.dialogDelete = false
            this.$nextTick(() => {
                this.editedItem = Object.assign({}, this.defaultItem)
                this.editedIndex = -1
            })
        },
        save () {
            if (this.editedIndex > -1) {
                Object.assign(this.desserts[this.editedIndex], this.editedItem)
            } else {
                this.desserts.push(this.editedItem)
            }
            this.close()
        },
        addSchedules() {
            if (this.selectedDays.length === 0) {
                this.schedules = []
            }
            for (const day of this.selectedDays) {
                const schedulesOfDay = this.schedules.filter(schedule => schedule.day === day)
                if (schedulesOfDay.length === 0) {
                  this.schedules.push({
                    day: day,
                    startTime: null,
                    timeEnd: null
                  })
                }
            }
        },
        addField(schedule) {
            const newSchedule = {
              day: schedule.day,
              startTime: null,
              timeEnd: null
            }
            this.schedules.push(newSchedule)
        },
        groupSchedulesPerDay() {
            const schedulesPerDay = []
              this.daysOfWeek.forEach(day => {
                const schedulesOfDay = this.schedules.filter(schedule => schedule.day === day)
                if (schedulesOfDay.length > 0) {
                  const schedulesgrouped = {
                    day: day,
                    schedules: schedulesOfDay
                  }
                  schedulesPerDay.push(schedulesgrouped)
                } else {
                  const index = this.selectedDays.indexOf(day)
                  if (index > -1) {
                    this.selectedDays.splice(index, 1)
                  }
                }
              })
              this.schedulesPerDay = schedulesPerDay
        },
        deleteField(schedules) {
          const index = this.schedules.indexOf(schedules)
          this.schedules.splice(index, 1)
          this.groupSchedulesPerDay()
        },
    },
    computed: {
        formTitle () {
            return this.editedIndex === -1 ? 'Nueva Disponibilidad' : 'Editar Item'
        },
        dessertsSorted() {
          return this.desserts.sort((a, b) => {
            if (a.name < b.name) return -1;
            if (a.name > b.name) return 1;
            return 0;
          });
        }
    },
    watch: {
        dialog (val) {
            val || this.close()
        },
        dialogDelete (val) {
            val || this.closeDelete()
        },
        schedules: {
            handler: 'groupSchedulesPerDay',
            deep: true
        }
    },
})
