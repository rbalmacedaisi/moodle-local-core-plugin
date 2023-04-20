Vue.component('availabilitytable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                   :headers="headers"
                   :items="items"
                   class="elevation-1"
                   dense
                   :search="search"
                >
                    <template v-slot:top>
                        <v-toolbar flat>
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
                                              class="mb-2 mt-8 mr-5"
                                              v-bind="attrs"
                                              v-on="on"
                                              @click="dialog = true"
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
                                                <v-col cols="12" sm="6" md="6">
                                                    <v-select
                                                      :items="instructors"
                                                      label="Instructor"
                                                      outlined
                                                      dense
                                                      v-model="selectedInstructor"
                                                    ></v-select>
                                                </v-col>
                                                <v-col cols="12" sm="6" md="6">
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
                                    <v-card-title class="text-h6">¿Estás seguro de que quieres eliminar este item?</v-card-title>
                                    <v-card-actions>
                                        <v-spacer></v-spacer>
                                        <v-btn color="blue darken-1" text @click="closeDelete">Cancelar</v-btn>
                                        <v-btn color="blue darken-1" text @click="deleteItemConfirm">Aceptar</v-btn>
                                        <v-spacer></v-spacer>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>
                        </v-toolbar>
                        
                        <v-row justify="start" class="ma-0 mr-3">
                            <v-col cols="4">
                                <v-text-field
                                   v-model="search"
                                   append-icon="mdi-magnify"
                                   label="Search"
                                   hide-details
                                   outlined
                                   dense
                                ></v-text-field>
                            </v-col>
                        </v-row>
                    </template>
                    
                    <template v-slot:item.instructorName="{ item }">
                        <v-list class="transparent">
                            <v-list-item>
                                <v-list-item-avatar>
                                    <img :src="item.instructorPicture" alt="picture">
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
                        <v-tooltip bottom>
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon
                                   class="mr-2"
                                   @click="editItem(item)"
                                   v-bind="attrs"
                                   v-on="on"
                                >
                                    mdi-pencil
                                </v-icon>
                            </template>
                            <span>Editar</span>
                        </v-tooltip>
                        
                        <v-tooltip bottom>
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon 
                                   @click="deleteItem(item)" 
                                   v-bind="attrs"
                                   v-on="on"
                                >
                                    mdi-delete
                                </v-icon>
                            </template>
                            <span>Eliminar</span>
                        </v-tooltip>
                    </template>
                    
                    <template v-slot:no-data>
                        <v-btn color="primary" @click="initialize">No hay datos</v-btn>
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
            items: [],
            editedIndex: -1,
            editedItem: {
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
            schedulesPerDay: [],
            selectedInstructor:[],
            token: '0deabd5798084addc080286f4acccd87',
            search: '',
            siteUrl: 'https://grupomakro-dev.soluttolabs.com/webservice/rest/server.php',
            itemDelete:{},
            editMode: false
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
        // This is a function that is used by the Axios library to make an HTTP GET request to a specific URL with some parameters. 
        // The response is then parsed as JSON data, and the function populates an array named "items"
        // with some properties obtained from the response data.
        initialize () {
            const url = this.siteUrl;
            const params = {
                wstoken: '0deabd5798084addc080286f4acccd87',
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_teachers_disponibility',
            };
            axios.get(url, { params })
                .then(response => {
                    const data = JSON.parse(response.data.teacherAvailabilityRecords)
                    console.log(data);
                    data.forEach((element) => {
                        this.items.push({
                            instructorName: element.instructorName,
                            instructorId: element.instructorId,
                            instructorPicture: element.instructorPicture,
                            disponibilityRecords: element.disponibilityRecords
                        })
                    })
                })
                .catch(error => {
                console.error(error);
            });
        },
        editItem (item) {
            this.editMode = true
            console.log(item)
            this.selectedInstructor = item.instructorName
            this.selectedDays = Object.keys(item.disponibilityRecords);
            
            for (const day in item.disponibilityRecords) {
                // Get the list of available time slots for the current day.
                const timeSlots = item.disponibilityRecords[day];
            
                // Cycle through each available time slot and add it to schedules.
                timeSlots.forEach(slot => {
                    const [startTime, endTime] = slot.split(", ");
                    this.schedules.push({
                        day: day,
                        startTime: startTime,
                        timeEnd: endTime
                    });
                });
            }
            
            this.editedIndex = this.items.indexOf(item)
            this.editedItem = Object.assign({}, item)
            this.dialog = true
        },
        // This is a function that gets an "item" parameter, which is assigned to the data this.
        // itemDelete and triggers a dialog asking the user to confirm the deletion of an item.
        deleteItem (item) {
            console.log(item)
            this.itemDelete = item
            this.dialogDelete = true
        },
        // This is a function that makes an HTTP GET request to a specific URL with some parameters. 
        // The function then removes an element from an array and closes a dialog.
        deleteItemConfirm () {
            const url = this.siteUrl;
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_delete_teacher_disponibility',
                instructorId: this.itemDelete
            };
            axios.get(url, { params })
                .then(response => {
                  console.log(response.data);
                })
                .catch(error => {
                console.error(error);
            });
            this.items.splice(this.editedIndex, 1)
            this.closeDelete()
        },
        close () {
            this.dialog = false
            this.schedules = []
            this.selectedInstructor = []
            this.$nextTick(() => {
                this.editedItem = Object.assign({}, this.defaultItem)
            this.editedIndex = -1
            })
        },
        closeDelete () {
            this.dialogDelete = false
            this.itemDelete = {}
            location.reload();
            /*this.$nextTick(() => {
                this.editedItem = Object.assign({}, this.defaultItem)
                this.editedIndex = -1
            })*/
        },
        // This is a function that creates a new availability record and sends it to a Moodle web service via an HTTP GET request.
        save () {
            const newDisponibilityRecord = this.schedulesPerDay.map(daySchedule => {
                const day = daySchedule.day;
                const timeslots = daySchedule.schedules.map(schedule => `${schedule.startTime}, ${schedule.timeEnd}`);
                return { day, timeslots };
            });
            const selectedInstructor = this.instructors.find(instructor => instructor.value === this.selectedInstructor);
    
            // Get the text and the id of the selected instructor
            const selectedInstructorText = selectedInstructor.text;
            const selectedInstructorId = selectedInstructor.id;
            
            const url = 'https://grupomakro-dev.soluttolabs.com/webservice/rest/server.php';
            let wsfunction = ''
            this.editedIndex === -1 ? wsfunction = 'local_grupomakro_add_teacher_disponibility' : wsfunction = 'local_grupomakro_update_teacher_disponibility'
            const params = {
                wstoken: '0deabd5798084addc080286f4acccd87',
                moodlewsrestformat: 'json',
                wsfunction: wsfunction,
                instructorId: selectedInstructorId,
                newDisponibilityRecords: newDisponibilityRecord
            };
            axios.get(url, { params })
                .then(response => {
                    if(response.data.message == 'ok'){
                        location.reload();
                    }
                })
                .catch(error => {
                console.error(error);
            });
            
            this.close()
        },
        // This is a method that adds schedules to the component's schedules array based on the days that are currently selected in the selectedDays array.
        // First, it checks if selectedDays is empty, and if so, it sets schedules to an empty array.
        // Then, it loops through each day in selectedDays, and filters schedules to find any existing schedules for that day. 
        // If there are no existing schedules, it adds a new object to the schedules array with the day property set to the current day, 
        // and the startTime and timeEnd properties set to null.
        addSchedules() {
            console.log('entro')
            console.log(this.selectedDays)
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
        // This is a method that adds a new empty schedule object to the schedules array, based on a given schedule object.
        // First, it creates a new schedule object newSchedule with the same day property as the schedule parameter, and with startTime and timeEnd properties set to null.
        // Then, it pushes the new newSchedule object onto the schedules array.
        // Overall, this method allows for the addition of a new, empty schedule to the schedules array for a given day.
        addField(schedule) {
            const newSchedule = {
                day: schedule.day,
                startTime: null,
                timeEnd: null
            }
            this.schedules.push(newSchedule)
        },
        // This is a method that groups the schedules in the schedules array by day, and stores the result in the schedulesPerDay array.
        // First, it creates an empty array schedulesPerDay to hold the grouped schedules.
        // Then, it loops through each day in the daysOfWeek array, and filters the schedules array to find any schedules for that day. 
        // If there are schedules for the current day, it creates a new object with a day property set to the current day, 
        // and a schedules property set to the array of schedules for that day. It then pushes this object onto the schedulesPerDay array.
        // If there are no schedules for the current day, it checks if the day is still selected in selectedDays, and if so, removes it from the array.
        // Finally, it sets the component's schedulesPerDay property to the newly created schedulesPerDay array.
        // Overall, this method allows for the schedules in the schedules array to be grouped by day, 
        // and for the resulting arrays to be used in the component's template to display the schedules for each day.
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
        // The deleteField method receives an object schedules which represents a day with its schedules. 
        // It first finds the index of the schedules object within the schedules array using the indexOf() method. 
        // It then removes the schedules object from the array using the splice() method. 
        // Finally, it calls the groupSchedulesPerDay() method to re-group the remaining schedules per day.
        deleteField(schedules) {
            const index = this.schedules.indexOf(schedules)
            this.schedules.splice(index, 1)
            this.groupSchedulesPerDay()
        },
    },
    computed: {
        // This method returns a string that represents the caption of the form in the user interface. 
        // If editedIndex equals -1, it means that the form is being used to create a new item and the method returns the string 'New Availability'. 
        // Otherwise, if editedIndex is not -1, it means the form is being used to edit an existing element and the method returns the string 'Edit Element'.
        formTitle () {
            return this.editedIndex === -1 ? 'Nueva Disponibilidad' : 'Editar'
        },
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
