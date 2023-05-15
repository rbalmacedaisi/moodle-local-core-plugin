Vue.component('availabilitytable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                   :headers="headers"
                   :items="teacherAvailabilityRecords"
                   class="elevation-1"
                   dense
                   :search="search"
                >
                    <template v-slot:top>
                        <v-toolbar flat>
                            <v-toolbar-title>{{lang.availability}}</v-toolbar-title>
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
                                              class="mb-2 mt-8 mr-5 rounded"
                                              v-bind="attrs"
                                              v-on="on"
                                              @click="dialog = true"
                                            >
                                                {{lang.add}}
                                            </v-btn>
                                        </template>
                                        <span>{{lang.add_availability}}</span>
                                    </v-tooltip>
                                </template>
                                
                                <v-card>
                                    <v-card-title>
                                        <span class="text-h5">{{ formTitle }}</span>
                                    </v-card-title>
                    
                                    <v-card-text>
                                        <v-form ref="form" v-model="valid" class="d-flex w-100">
                                            <v-container>
                                                <v-row>
                                                    <v-col cols="12" sm="6" md="6">
                                                        <v-select
                                                          :items="instructorsPickerOptions"
                                                          :label="lang.instructors"
                                                          item-value="id"
                                                          no-data-text="No hay instructores disponibles"
                                                          outlined
                                                          dense
                                                          required
                                                          v-model="pickedInstructorId"
                                                          :rules="[v => !!v || lang.field_required]"
                                                        ></v-select> 
                                                    </v-col>
                                                    <v-col cols="12" sm="6" md="6">
                                                        <v-combobox
                                                          v-model="selectedDays"
                                                          :items="daysOfWeek"
                                                          :label="lang.days"
                                                          outlined
                                                          dense
                                                          required
                                                          class="mr-2"
                                                          clearable
                                                          multiple
                                                          @change="addSchedules"
                                                          :rules="[v => !!v && v.length > 0 || lang.field_required]"
                                                        ></v-combobox>
                                                    </v-col>
                                                </v-row>
                                                <v-divider v-if="selectedDays" class="my-0 mb-3"></v-divider>
                                                <v-row>
                                                    <v-overlay :value="Alert">
                                                        <v-alert
                                                          outlined
                                                          type="warning"
                                                          prominent
                                                          border="left"
                                                          v-model="Alert"
                                                          dismissible
                                                          class="white"
                                                          @input="handler"
                                                        >
                                                          {{lang.unable_complete_action}}
                                                        </v-alert>
                                                    </v-overlay>
                                                    
                                                    <v-col cols="12">
                                                        <div v-for="(schedules, index) in schedulesPerDay" :key="index">
                                                            <h5>{{schedules.day}}</h5>
                                                            <div v-for="(schedules, index) in schedules.schedules" :key="index">
                                                                <div class="d-flex mt-5">
                                                                    <v-text-field
                                                                       v-model="schedules.startTime"
                                                                       :label="lang.start_time"
                                                                       type="time"
                                                                       outlined
                                                                       dense
                                                                       :rules="[requiredRule]"
                                                                       required
                                                                       class="mr-2 startTime"
                                                                    ></v-text-field>
                                                                    <v-text-field
                                                                       v-model="schedules.timeEnd"
                                                                       :label="lang.end_time"
                                                                       type="time"
                                                                       outlined
                                                                       dense
                                                                       :rules="[requiredRule, validateEndTime(schedules)]"
                                                                       required
                                                                       class="timeEnd"
                                                                    ></v-text-field>
                                                                    <v-btn icon small color="error" fab @click="deleteField(schedules)">
                                                                      <v-icon>mdi-delete</v-icon>
                                                                    </v-btn>
                                                                </div>
                                                            </div>
                                                            <div class="d-flex justify-center">
                                                                <v-tooltip bottom>
                                                                    <template v-slot:activator="{ on, attrs }">
                                                                        <v-btn x-small class="mx-2" color="primary" fab @click="addField(schedules)" v-bind="attrs"v-on="on">
                                                                            <v-icon>mdi-plus</v-icon>
                                                                        </v-btn>
                                                                    </template>
                                                                    <span>{{lang.add_schedule}}</span>
                                                                </v-tooltip>
                                                            </div>
                                                        </div>
                                                    </v-col>
                                                </v-row>
                                            </v-container>
                                        </v-form>
                                        
                                    </v-card-text>
                    
                                    <v-card-actions>
                                        <v-spacer></v-spacer>
                                        <v-btn
                                           color="primary"
                                           text
                                           @click="close"
                                        >
                                            {{lang.cancel}}
                                        </v-btn>
                                        <v-btn
                                           color="primary"
                                           text
                                           @click="save"
                                        >
                                            {{lang.save}}
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>
                        
                            <v-dialog v-model="dialogDelete" max-width="500px">
                                <v-card>
                                    <v-card-title class="text-subtitle-1 d-flex justify-center">{{lang.delete_available}}</v-card-title>
                                    <v-card-subtitle class="pt-1 d-flex justify-center">{{lang.delete_available_confirm}}</v-card-subtitle>
                                    <v-card-actions>
                                        <v-spacer></v-spacer>
                                        <v-btn color="primary" text @click="dialogDelete = false">{{lang.cancel}}</v-btn>
                                        <v-btn color="primary" text @click="confirmAvailabilityRecordDeletion">{{lang.accept}}</v-btn>
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
                                   :label="lang.search"
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
                    
                    <template v-slot:item.competencies="{ item }">
                        <instructorcompetencies :instructorData="item"></instructorcompetencies>
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
                                   small
                                >
                                    mdi-pencil
                                </v-icon>
                            </template>
                            <span>{{lang.edit}}</span>
                        </v-tooltip>
                        
                        <v-tooltip bottom>
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon 
                                   @click="deleteAvailabilityRecord(item)" 
                                   v-bind="attrs"
                                   v-on="on"
                                   small
                                >
                                    mdi-delete
                                </v-icon>
                            </template>
                            <span>{{lang.remove}}</span>
                        </v-tooltip>
                    </template>
                    
                    <template v-slot:no-data>
                        <v-btn color="primary" @click="initialize">No hay datos</v-btn>
                    </template>
                </v-data-table>
            </v-col>
            <v-col cols="12" v-if="overlay">
                <v-overlay :value="overlay" z-index='200' class="text-center">
                    <v-progress-circular
                        :size="70"
                        :width="7"
                        color="primary"
                        indeterminate
                        class="mt-5"
                    ></v-progress-circular>
                </v-overlay>
            </v-col>
            <v-dialog v-model="errorDialog" max-width="500px">
                <v-card>
                    <v-card-title class="text-subtitle-1 d-flex justify-center">Error</v-card-title>
                    <v-card-subtitle class="pt-1 d-flex justify-center text-center">{{errorMessage}}</v-card-subtitle>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn color="primary" text @click="errorDialog = false">{{lang.accept}}</v-btn>
                        <v-spacer></v-spacer>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-row>
    `,
    data(){
        return{
            token: '33513bec0b3469194c7756c29bf9fb33',
            dialog: false,
            dialogDelete: false,
            overlay: false,
            errorDialog:false,
            errorMessage:undefined,
            editMode: false,
            valid: false,
            Alert: false,
            editedIndex: -1,
            start: null,
            end: null,
            daysOfWeek: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
            selectedDays: [],
            schedules: [],
            schedulesPerDay: [],
            search: '',
            pickedInstructorId: undefined,
            headers: [
                {
                    text: 'Instructor',
                    align: 'start',
                    sortable: false,
                    value: 'instructorName',
                },
                {
                    text: 'Competencias',
                    sortable: false,
                    value: 'competencies',
                },
                { text: 'Disponibilidad', value: 'availability',sortable: false },
                { text: 'Actions', value: 'actions', sortable: false },
            ],
            teacherAvailabilityRecords:[],
            selectedInstructorId:undefined
        }
    },
    props:{},
    created(){
        this.initialize()
    }, 
    mounted(){},  
    methods:{
        openErrorDialog(errorMessage){
            this.errorMessage = errorMessage;
            this.errorDialog = true;
        },
        
        // Function to initialize the data of the instructors.
        async initialize () {
            this.overlay = true
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_teachers_disponibility',
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            const availabilityResponse = await window.axios.get(this.siteUrl, { params })
            this.overlay = false
            if(availabilityResponse.data.teacherAvailabilityRecords === -1) {
                console.error(availabilityResponse.data.message)
                return
            }
            // If the request is resolved successfully, perform the following operations.
            this.teacherAvailabilityRecords = JSON.parse(availabilityResponse.data.teacherAvailabilityRecords)
        },
        // Function to edit an item from the instructor list.
        editItem ({instructorId}) {
            
            // Activate edit mode.
            this.editMode = true
            
            this.selectedInstructorId = instructorId
            this.pickedInstructorId =instructorId
            
            // Set the selectedDays property with an array containing the names of the days of the week the instructor is available, 
            // using the Object.keys() method to get the keys from the availabilityRecords object in the registry.
            this.selectedDays = Object.keys(this.selectedInstructorData.disponibilityRecords);
            // Iterates through each day of the week in the log, getting the list of available times for that day and adding 
            // them to the schedules array with the structure {day: <day_name>, startTime: <start_time>, timeEnd: <end_time> }.
            for (const day in this.selectedInstructorData.disponibilityRecords) {
                // Get the list of available time slots for the current day.
                const timeSlots = this.selectedInstructorData.disponibilityRecords[day];
            
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
            // Set the editedIndex property to the index of the item object in the items array.
            this.editedIndex = this.teacherAvailabilityRecords.indexOf(this.selectedInstructorData)

            // Display the edit dialog by setting the dialog property to true.
            this.dialog = true
        },
        
        // This is a function that gets an "instructorId" parameter, which is assigned to the data this.
        // selectedInstructorId and triggers a dialog asking the user to confirm the deletion of an availability record.
        deleteAvailabilityRecord ({instructorId}) {
            this.selectedInstructorId = instructorId
            this.dialogDelete = true
        },
        // This is a function that makes an HTTP GET request to a specific URL with some parameters. 
        // The function then removes an element from an array and reload the pageg.
        async confirmAvailabilityRecordDeletion () {
            // Build the URL and parameters for the Moodle web service.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_delete_teacher_disponibility',
                instructorId: this.selectedInstructorId
            };
            // Call the Moodle web service to delete the instructor's availability.
            const deleteResponse =await  window.axios.get(this.siteUrl, { params })
            if(deleteResponse.data.status ===-1){
                this.dialogDelete = false
                this.openErrorDialog(deleteResponse.data.message)
                return
            }
            // Remove the item from the items array and reload the page.
            this.teacherAvailabilityRecords.splice(this.editedIndex, 1)
            window.location.reload();
        },
        close () {
            this.dialog = false
            this.editMode = false
            this.schedules = []
            this.$nextTick(() => {
                this.pickedInstructorId = undefined
                this.selectedInstructorId = undefined
                this.editedIndex = -1
            })
        },
        // This is a function that creates a new availability record and sends it to a Moodle web service via an HTTP GET request.
        async save () {
            // Validate the form.
            this.$refs.form.validate()
            this.Alert = false
            // If the form is valid, proceed to save the availability record.
            if(this.valid){
                // Create a new availability record from the selected schedules.
                const newDisponibilityRecord = this.schedulesPerDay.map(daySchedule => {
                    const day = daySchedule.day;
                    const timeslots = daySchedule.schedules.map(schedule => `${schedule.startTime}, ${schedule.timeEnd}`);
                    return { day, timeslots };
                });
                
                // Set the parameters for the web service request.

                const wsfunction =this.editMode ? 'local_grupomakro_update_teacher_disponibility':'local_grupomakro_add_teacher_disponibility';
                
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: wsfunction,
                    instructorId:this.editMode? this.selectedInstructorId : this.pickedInstructorId,
                    newDisponibilityRecords: newDisponibilityRecord,
                    newInstructorId:this.editMode?this.pickedInstructorId :null
                };
                
                // Send the HTTP GET request to the Moodle web service.
                const saveResponse = await window.axios.get(this.siteUrl, { params })
                if(saveResponse.data.status === -1){
                    this.dialog= false
                    this.openErrorDialog(saveResponse.data.message)
                    return
                }
                window.location.reload();
            }
        },
        // This function is called when the user clicks the "Add Schedules" button.
        addSchedules() {
            // If no days are selected, clear the schedules array.
            if (this.selectedDays.length === 0) {
                this.schedules = []
            }
            // For each selected day, check if it already has a schedule. If not, add a new empty schedule for that day.
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
        /**
         * This function adds a new blank schedule field to the list of schedules.
         * @param {Object} schedule - The schedule object to add a new field to.
        */
        addField(schedule) {
            // Create a new blank schedule object with null start and end times.
            const newSchedule = {
                day: schedule.day,
                startTime: null,
                timeEnd: null
            }
            // Add the new schedule object to the schedules list.
            this.schedules.push(newSchedule)
        },
        // This function groups schedules by day of the week.
        groupSchedulesPerDay() {
            // create an empty array to store schedules grouped by day.
            const schedulesPerDay = []
            // loop through the days of the week.
            this.daysOfWeek.forEach(day => {
                // filter the schedules for the current day.
                const schedulesOfDay = this.schedules.filter(schedule => schedule.day === day)
                // if there are schedules for the current day.
                if (schedulesOfDay.length > 0) {
                    // create an object to store the schedules grouped by day.
                    const schedulesgrouped = {
                        day: day,
                        schedules: schedulesOfDay
                    }
                    // add the schedules grouped by day to the array.
                    schedulesPerDay.push(schedulesgrouped)
                } else {
                    const index = this.selectedDays.indexOf(day)
                    if (index > -1) {
                        this.selectedDays.splice(index, 1)
                    }
                }
            })
            // update the schedulesPerDay property with the schedules grouped by day.
            this.schedulesPerDay = schedulesPerDay
        },
        // This function removes a time field from the list of times.
        deleteField(schedules) {
            // Find the index of the selected schedule in the list of schedules.
            const index = this.schedules.indexOf(schedules)
            // Remove the selected schedule from the list of schedules.
            this.schedules.splice(index, 1)
            // Group schedules by day to update the list of schedules by day.
            this.groupSchedulesPerDay()
        },
        // This method returns a validation function for the end time of a schedule.
        validateEndTime(schedule) {
            return (value) => {
                // Check if the end time value is defined and if the start time is greater than or equal to the end time value.
                if (value && schedule.startTime >= value) {
                    // Return an error message indicating that the end time must be later than the start time.
                    return "La hora de fin debe ser posterior a la hora de inicio";
                }
                // If the validation is successful, return true.
                return true;
            };
        },
        handler(e){
            this.close()
        }
    },
    computed: {
        
        // This method returns a string that represents the caption of the form in the user interface. 
        // If editedIndex equals -1, it means that the form is being used to create a new item and the method returns the string 'New Availability'. 
        // Otherwise, if editedIndex is not -1, it means the form is being used to edit an existing element and the method returns the string 'Edit Element'.
        formTitle () {
            return this.editedIndex === -1 ? 'Nueva Disponibilidad' : 'Editar'
        },
        // This method returns a validation rule function for use with vee-validate library.
        // The function takes a value as input and returns a boolean indicating whether the value is non-empty or not.
        requiredRule() {
          return (value) => !!value || 'Este campo es requerido';
        },
        // This method returns the list of instructors that doesnt have a disponibility record created
        instructorsPickerOptions(){
            const availableInstructors = window.instructorItems.filter(instructor => instructor.hasDisponibility ===0 )
            return this.editMode? [{...this.editingPickedInstructorData},...availableInstructors]:availableInstructors
        },
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        },
        lang(){
            return window.strings
        },
        selectedInstructorData(){
            return this.selectedInstructorId? this.teacherAvailabilityRecords.find(instructorAvailabilityRecord => instructorAvailabilityRecord.instructorId === this.selectedInstructorId):undefined
        },
        pickedInstructorData(){
            return this.pickedInstructorId? window.instructorItems.find(instructor => instructor.id === this.pickedInstructorId):undefined
        },
        editingPickedInstructorData(){
            return this.pickedInstructorId? window.instructorItems.find(instructor => instructor.id === this.selectedInstructorId):undefined
        }
    },
    watch: {
        dialog (val) {
            val || this.close()
        },
        schedules: {
            handler: 'groupSchedulesPerDay',
            deep: true
        }
    },
})