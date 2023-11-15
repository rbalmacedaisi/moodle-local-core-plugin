Vue.component('availabilitytable',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-data-table
                   :headers="headers"
                   :items="teacherAvailabilityRecords"
                   class="elevation-1 paneltable"
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
                                    <div class="mt-5 d-flex align-center"> 
                                        <form action="" method='POST' enctype="multipart/form-data" class="custom-file mr-3" id="multiple-users-select" ref="bulkDisponibilitiesForm">
                                            <input @change="openBulkDialog" type="file" class="custom-file-input" id="upload_massive_inst_users" data-initial-value="" name='massivecsv accept=".csv"'
                                            ref="bulkDisponibilitiesInput">
                                            <label class="custom-file-label" for="upload_massive_inst_users">{{lang.availability_bulk_load}}</label>
                                         </form>
                                        
                                        <v-tooltip bottom>
                                            <template v-slot:activator="{ on, attrs }">
                                                <v-btn
                                                  color="primary"
                                                  dark
                                                  class=" mr-5 rounded"
                                                  v-bind="attrs"
                                                  v-on="on"
                                                  @click="dialog = true"
                                                >
                                                    {{lang.add}}
                                                </v-btn>
                                            </template>
                                            <span>{{lang.add_availability}}</span>
                                        </v-tooltip>
                                    </div>
                                </template>
                                
                                <v-card>
                                    <v-card-title>
                                        <span class="text-h5">{{ formTitle }}</span>
                                    </v-card-title>
                    
                                    <v-card-text>
                                        <v-form ref="form" v-model="valid" class="d-flex w-100">
                                            <v-container>
                                                <v-row>
                                                    <v-col cols="12" sm="12" md="12" class="pb-0">
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
                                                    
                                                    <v-col cols="12" sm="6" md="6" class="pt-0">
                                                        <v-combobox
                                                          v-model="selectedskills"
                                                          :items="instructorsSkills"
                                                          :label="lang.competences"
                                                          outlined
                                                          dense
                                                          required
                                                          class="mr-2"
                                                          clearable
                                                          multiple
                                                          :rules="[v => !!v && v.length > 0 || lang.field_required]"
                                                        ></v-combobox>
                                                    </v-col>
                                                    
                                                    <v-col cols="12" sm="6" md="6" class="pt-0">
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
                        </v-toolbar>
                        
                        <v-row justify="start" class="ma-0 mr-3 mb-2">
                            
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
                            
                            <div class="d-flex align-center">
                                <v-btn v-if="!datesFilters" color="primary" outlined @click="filterDate" class="text-capitalize">Filtrar Horario</v-btn>
                                
                                <div v-else class="d-flex align-center">
                                    <v-text-field
                                       v-model="filterstartTime"
                                       :label="lang.start_time"
                                       type="time"
                                       outlined
                                       dense
                                       required
                                       class="mr-2 startTime"
                                       hide-details
                                    ></v-text-field>
                                    
                                    <v-text-field
                                       v-model="filterstimeEnd"
                                       :label="lang.end_time"
                                       type="time"
                                       outlined
                                       dense
                                       required
                                       class="timeEnd"
                                       hide-details
                                    ></v-text-field>
                                    
                                    <v-btn 
                                      v-if="applyActiveButton"
                                      color="secondary" 
                                      small 
                                      @click="saveFilter" 
                                      class="text-capitalize ml-2"
                                      :disabled="!isTimeFieldsValid"
                                    >
                                        {{ lang.apply_filter }}
                                    </v-btn>
                                    
                                    <v-btn 
                                      v-else
                                      small 
                                      @click="resetFilter" 
                                      class="text-capitalize ml-2"
                                    >
                                        Reset
                                    </v-btn>
                                </div>
                            </div>
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
                    
                    <template v-slot:item.instructorSkills="{ item }"></template>
                    
                    <template v-slot:item.days="{ item }"></template>
                    <template v-slot:item.skills="{ item }"></template>
                    
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
                        <v-btn color="primary" text @click="initialize">No hay datos</v-btn>
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
            
            <deletemodal 
              v-if="dialogDelete" 
              @dialog-delete="closeDialogDelete"
              @confirm-delete="confirmAvailabilityRecordDeletion"
            >
            </deletemodal>
            
            <dialogbulkmodal 
              v-if="dialogBulk"
              @cancel-upload="cancelUploadBulkDisponibilities"
              @upload-disponibilities="uploadDisponibilities"
            >
            </dialogbulkmodal>
            
            <errormodal 
              v-if="errorDialog"
              :Message="errorMessage"
              @close-dialog-error="closeDialogError"
            >
            </errormodal>
        </v-row>
    `,
    data(){
        return{
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
                { text: 'instructorSkills', value: 'instructorSkills',sortable: false, class: 'd-none'},
                { text: 'days', value: 'days',sortable: false, class: 'd-none'},
                { text: 'skills', value: 'skills',sortable: false, class: 'd-none'},
                { text: 'Actions', value: 'actions', sortable: false },
            ],
            teacherAvailabilityRecords:[],
            selectedInstructorId:undefined,
            datesFilters: false,
            filterstartTime: '',
            filterstimeEnd: '',
            applyActiveButton: true,
            dialogBulk:false,
            selectedskills: [],
            instructorsSkills:[],
        }
    },
    created(){
        this.initialize()
        this.getSkills()
    },  
    methods:{
        /**
         * Uploads a CSV file for updating teachers' disponibilities.
         * This method performs the following actions:
         * 1. Set request headers for a multipart form data.
         * 2. Get the selected file from the input element.
         * 3. Prepare and send a POST request to upload the CSV file.
         * 4. Check the response status and data to ensure a successful upload.
         * 5. Extract data from the uploaded file response.
         * 6. Prepare request parameters for updating teachers' disponibilities.
         * 7. Send a GET request to update teachers' disponibilities.
         * 8. Log the responses and handle errors.
         */
        async uploadDisponibilities(){
            try{
                // Define request headers.
                const headers = {
                    'Content-Type': 'multipart/form-data'
                }
                
                // Get the selected file from the input element.
                const file =  this.$refs['bulkDisponibilitiesInput'].files[0];
                
                // Prepare the request parameters for uploading the CSV file,
                const uploadDisponibilityCSVRequestParams = {
                    token:this.token,
                    file:file
                }
                
                // Send a POST request to upload the CSV file.
                const uploadDisponibilityCSVResponse = await window.axios.post(`${window.location.origin}/webservice/upload.php`,uploadDisponibilityCSVRequestParams, {headers});
                console.log(uploadDisponibilityCSVResponse)
                // Check the response status and data.
                if(uploadDisponibilityCSVResponse.status !== 200 || !(uploadDisponibilityCSVResponse.data && uploadDisponibilityCSVResponse.data[0])){
                    throw new Error('Error uploading the CSV file');
                }
                
                // Extract data from the uploaded file response.
                const uploadedFileData = uploadDisponibilityCSVResponse.data[0];
                
                // Prepare request parameters for updating teachers' disponibilities.
                const updateTeachersDisponibilitiesRequestParams = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_bulk_update_teachers_disponibility',
                    contextId: uploadedFileData.contextid,
                    itemId: uploadedFileData.itemid,
                    filename: uploadedFileData.filename
                };
               
                // Send a GET request to update teachers' disponibilities.
                const updateTeachersDisponibilitiesResponse = await window.axios.get(this.siteUrl,{params:updateTeachersDisponibilitiesRequestParams});
                window.console.log(updateTeachersDisponibilitiesResponse)
                window.location.reload();
            }catch(error){
                window.console.error(error)
            }
        },
        /**
         * Opens a dialog for bulk file upload and validation.
         * This method performs the following actions:
         * 1. Checks if the selected file is in Excel format, and if not, shows an alert and resets the file input.
         * 2. If the file is in Excel format, it opens the bulk upload dialog.
         *
         * @param {Event} event - The event object triggered when a file is selected for upload.
         */
        openBulkDialog(event){
            
            let fileName =event.target.files[0].name;

            // Use the split method to separate the file name and extension
            let parts = fileName.split('.');
            let fileExtension = parts[parts.length - 1];

            // Check if the selected file is not in Excel format.
            if( fileExtension !== 'xlsx'){
                // Reset the file input and display an alert
                this.bulkForm.reset();
                window.alert('El archivo debe estar en formato Excel');
                return 
            }
            
            // If the file is in Excel format, open the bulk upload dialog.
            this.dialogBulk = true
            return
        },
        /**
         * Opens an error dialog with a specified error message.
         *
         * @param {string} errorMessage - The error message to be displayed in the dialog.
         */
        openErrorDialog(errorMessage){
            
            // Set the error message to be displayed in the dialog.
            this.errorMessage = errorMessage;
            // Open the error dialog
            this.errorDialog = true;
        },
        /**
         * Initializes the data of instructors' availability.
         * This method performs the following actions:
         * 1. Sets the 'overlay' to true to display a loading overlay.
         * 2. Constructs a params object with the necessary parameters for an API call.
         * 3. Makes a GET request to the specified URL, passing the parameters as query options.
         * 4. Sets the 'overlay' to false to hide the loading overlay.
         * 5. Checks if the API response indicates an error, and logs an error message if needed.
         * 6. Parses the data from the API response and assigns it to the 'teacherAvailabilityRecords' array.
         * 7. Iterates through the 'teacherAvailabilityRecords' and extracts the available days for each instructor.
         */
        async initialize () {
            // Set the loading overlay to indicate data retrieval.
            this.overlay = true
            
            // Create a params object with the necessary parameters for the API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_teachers_disponibility',
            };
            
            // Make a GET request to fetch instructor availability data.
            const availabilityResponse = await window.axios.get(this.siteUrl, { params })
            
            // Hide the loading overlay after the request is complete.
            this.overlay = false
            
            // Check if the response indicates an error and log the error message if needed.
            if(availabilityResponse.data.teacherAvailabilityRecords === -1) {
                console.error(availabilityResponse.data.message)
                return
            }
            
            // Parse the data from the API response and assign it to the 'teacherAvailabilityRecords' array.
            this.teacherAvailabilityRecords = JSON.parse(availabilityResponse.data.teacherAvailabilityRecords)
            
            let array_skill = []
            // Extract available days for each instructor.
            this.teacherAvailabilityRecords.forEach(record => {
                const days = Object.keys(record.disponibilityRecords);
                record.days = days;
              
              
                const skillsArray = record.instructorSkills.map(skill => skill.name);
                // Asigna el array de skills a la propiedad skills del instructor
                this.$set(record, 'skills', skillsArray);
              
            });
            
        },
        /**
         * Initiates the editing of an instructor's availability.
         * This method performs the following actions:
         * 1. Activates edit mode by setting 'editMode' to true.
         * 2. Sets the 'selectedInstructorId' and 'pickedInstructorId' to the instructor's ID for editing.
         * 3. Populates the 'selectedDays' property with the days of the week the instructor is available.
         * 4. Iterates through each day to collect the available time slots and structure them into the 'schedules' array.
         * 5. Sets the 'editedIndex' to the index of the instructor in the 'teacherAvailabilityRecords' array.
         * 6. Displays the edit dialog by setting 'dialog' to true.
         *
         * @param {Object} instructor - The instructor data to edit, containing 'instructorId'.
         */
        editItem ({instructorId}) {
            // Activate edit mode.
            this.editMode = true
            
            this.selectedskills = []
            
            // Set the selected instructor for editing.
            this.selectedInstructorId = instructorId
            this.pickedInstructorId =instructorId
            
            // Populate the selectedDays with the available days of the selected instructor.
            this.selectedDays = Object.keys(this.selectedInstructorData.disponibilityRecords);
            
            this.selectedInstructorData.instructorSkills.forEach((element) => {
                this.selectedskills.push({
                    id: element.id,
                    text: element.name
                }) 
            })
            // Iterate through each available day and collect the time slots.
            for (const day in this.selectedInstructorData.disponibilityRecords) {
                // Get the list of available time slots for the current day.
                const timeSlots = this.selectedInstructorData.disponibilityRecords[day];
            
                // Process and structure each available time slot into the schedules array.
                timeSlots.forEach(slot => {
                    const [startTime, endTime] = slot.split(", ");
                    this.schedules.push({
                        day: day,
                        startTime: startTime,
                        timeEnd: endTime
                    });
                });
            }
            
            // Set the editedIndex to the index of the selected instructor data in the teacherAvailabilityRecords.
            this.editedIndex = this.teacherAvailabilityRecords.indexOf(this.selectedInstructorData)

            // Display the edit dialog.
            this.dialog = true
        },
        /**
         * Initiates the deletion of an instructor's availability record.
         * This method takes an 'instructorId' parameter, assigns it to 'selectedInstructorId', and triggers a dialog
         * asking the user to confirm the deletion of the availability record.
         *
         * @param {Object} instructor - The instructor data to delete, containing 'instructorId'.
         */
        deleteAvailabilityRecord ({instructorId}) {
            // Assign the selected instructor for deletion.
            this.selectedInstructorId = instructorId
            
            // Trigger the deletion confirmation dialog.
            this.dialogDelete = true
        },
        /**
         * Initiates the confirmation and deletion of an instructor's availability record.
         * This method performs the following actions:
         * 1. Constructs the URL for the Moodle web service with the necessary parameters.
         * 2. Sends an HTTP GET request to the specified URL with the parameters.
         * 3. Handles the response from the server:
         *    - If the deletion fails, closes the 'dialogDelete' and opens an error dialog with the error message.
         *    - If the deletion is successful, removes the instructor's availability record from the 'teacherAvailabilityRecords' array.
         * 4. Reloads the current page to reflect the changes.
         */
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
            
            // Remove the item from the 'teacherAvailabilityRecords' array.
            this.teacherAvailabilityRecords.splice(this.editedIndex, 1)
            
            // Reload the current page to reflect the changes.
            window.location.reload();
        },
        /**
         * Closes the availability edit dialog and resets related data.
         * This method performs the following actions:
         * 1. Closes the dialog for editing instructor availability.
         * 2. Resets the 'editMode' to false, indicating the end of the editing process.
         * 3. Clears the 'schedules' array, which holds the instructor's availability data.
         * 4. Asynchronously resets the selected and edited instructor-related properties to their initial values.
         */
        close () {
            // Close the dialog for editing instructor availability.
            this.dialog = false
            
            // Reset the 'editMode' to false, indicating the end of the editing process.
            this.editMode = false
            
            // Clear the 'schedules' array, which holds the instructor's availability data.
            this.schedules = []
            
            // Asynchronously reset the selected and edited instructor-related properties to their initial values.
            this.$nextTick(() => {
                this.pickedInstructorId = undefined
                this.selectedInstructorId = undefined
                this.editedIndex = -1
            })
        },
        /**
         * Saves or updates an instructor's availability record by sending an HTTP GET request to a Moodle web service.
         * This method performs the following actions:
         * 1. Validates the form to ensure it's filled out correctly.
         * 2. Clears any previous alert messages.
         * 3. If the form is valid, proceeds to save or update the availability record.
         * 4. Creates a new availability record based on the selected schedules and days.
         * 5. Sets the parameters for the web service request, including the instructor ID and the availability record.
         * 6. Determines whether to add or update the record based on the 'editMode' property.
         * 7. Sends an HTTP GET request to the Moodle web service to perform the desired action.
         * 8. If the request is successful, reloads the page to reflect the changes.
         * 9. If the request fails, closes the dialog and opens an error dialog with the provided message.
         */
        async save () {
            // Validate the form.
            this.$refs.form.validate()
            this.Alert = false
            
            // If the form is valid, proceed to save or update the availability record.
            if(this.valid){
                // Create a new availability record from the selected schedules.
                const newDisponibilityRecord = this.schedulesPerDay.map(daySchedule => {
                    const day = daySchedule.day;
                    const timeslots = daySchedule.schedules.map(schedule => `${schedule.startTime}, ${schedule.timeEnd}`);
                    return { day, timeslots };
                });
                
                // Determine the web service function based on whether it's an update or a new record.
                const wsfunction =this.editMode ? 'local_grupomakro_update_teacher_disponibility':'local_grupomakro_add_teacher_disponibility';
                
               // Set the parameters for the web service request.
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: wsfunction,
                    instructorId:this.editMode? this.selectedInstructorId : this.pickedInstructorId,
                    newDisponibilityRecords: newDisponibilityRecord,
                    newInstructorId:this.editMode?this.pickedInstructorId :null
                };
                
                // Loop through the 'selected' array and generate the parameters.
                for (let i = 0; i < this.selectedskills.length; i++) {
                  const skills = this.selectedskills[i];
                  params[`skills[${i}]`] = skills.id;
                }
                
                // Send the HTTP GET request to the Moodle web service.
                const saveResponse = await window.axios.get(this.siteUrl, { params })
                
                // Handle the response from the web service.
                if(saveResponse.data.status === -1){
                    this.dialog= false

                    this.openErrorDialog(JSON.parse(saveResponse.data.message).join('\n'))
                    return
                }
                
                // Reload the page to reflect the changes.
                window.location.reload();
            }
        },
        /**
         * Adds schedules for selected days when the user clicks the "Add Schedules" button.
         * This method performs the following actions:
         * 1. If no days are selected, it clears the schedules array.
         * 2. For each selected day, it checks if it already has a schedule. If not, it adds a new empty schedule for that day.
         */
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
        /**
         * Groups schedules by day of the week.
         * This method performs the following actions:
         * 1. Creates an empty array to store schedules grouped by day.
         * 2. Iterates through the days of the week.
         * 3. Filters the schedules for the current day.
         * 4. If there are schedules for the current day, creates an object to store the schedules grouped by day and adds it to the array.
         * 5. Removes the day from the selectedDays array if there are no schedules for it.
         * 6. Updates the schedulesPerDay property with the schedules grouped by day.
         */
        groupSchedulesPerDay() {
            // Create an empty array to store schedules grouped by day.
            const schedulesPerDay = []
            
            // Loop through the days of the week.
            this.daysOfWeek.forEach(day => {
                // Filter the schedules for the current day.
                const schedulesOfDay = this.schedules.filter(schedule => schedule.day === day)
                
                // If there are schedules for the current day.
                if (schedulesOfDay.length > 0) {
                    // Create an object to store the schedules grouped by day.
                    const schedulesgrouped = {
                        day: day,
                        schedules: schedulesOfDay
                    }
                    
                    // Add the schedules grouped by day to the array.
                    schedulesPerDay.push(schedulesgrouped)
                } else {
                    const index = this.selectedDays.indexOf(day)
                    if (index > -1) {
                        this.selectedDays.splice(index, 1)
                    }
                }
            })
            
            // Update the schedulesPerDay property with the schedules grouped by day.
            this.schedulesPerDay = schedulesPerDay
        },
        /**
         * Removes a time field from the list of schedules.
         * This method performs the following actions:
         * 1. Finds the index of the selected schedule in the list of schedules.
         * 2. Removes the selected schedule from the list of schedules.
         * 3. Calls the 'groupSchedulesPerDay' method to update the list of schedules grouped by day.
         *
         * @param {Object} schedules - The selected schedule to be removed.
         */
        deleteField(schedules) {
            // Find the index of the selected schedule in the list of schedules.
            const index = this.schedules.indexOf(schedules)
            
            // Remove the selected schedule from the list of schedules.
            this.schedules.splice(index, 1)
            
            // Group schedules by day to update the list of schedules by day.
            this.groupSchedulesPerDay()
        },
       /**
         * Returns a validation function for the end time of a schedule.
         * This function checks if the end time is later than the start time.
         *
         * @param {Object} schedule - The schedule object containing the start time.
         * @returns {function} - A validation function that checks the end time.
         */
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
        /**
         * Handles an event and closes a dialog.
         *
         * @param {Event} e - The event object.
         */
        handler(e){
            this.close()
        },
        /**
         * Activates date filtering by setting the 'datesFilters' flag to true.
         */
        filterDate(){
            this.datesFilters = true
        },
        /**
         * Filters teacher availability records based on the specified time range.
         * This method sends an HTTP GET request to the API to retrieve filtered data.
         * If successful, it updates the 'teacherAvailabilityRecords' with the filtered data.
         * If the request fails, an error is logged to the console.
         */
        saveFilter(){
            // URL of the API to be used for data retrieval.
            const url = this.siteUrl;
            const params = {}
            // Parameters required for making the API call.
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_get_teachers_disponibility',
            params.initTime = this.filterstartTime,
            params.endTime = this.filterstimeEnd
            
            // Perform a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                .then(response => {
                    // Clear the existing teacherAvailabilityRecords array.
                    this.teacherAvailabilityRecords = []
                    
                    // Parse the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.teacherAvailabilityRecords)
                    
                    // Update the 'teacherAvailabilityRecords' with the filtered data.
                    this.teacherAvailabilityRecords = data
                    
                    console.log(this.teacherAvailabilityRecords);
                    
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            }); 
            // Disable the apply filter button to prevent multiple submissions.
            this.applyActiveButton = false
        },
        /**
         * Resets the applied time range filter and retrieves the initial teacher availability records.
         * This method clears the 'teacherAvailabilityRecords' array, resets the time filter values,
         * and calls the 'initialize' method to retrieve the original data.
         * It also re-enables the apply filter button for future filtering.
         */
        resetFilter(){
            // Clear the teacherAvailabilityRecords array.
            this.teacherAvailabilityRecords = []
            
            // Reset the time filter values.
            this.filterstartTime = '',
            this.filterstimeEnd = ''
            
            // Retrieve the initial teacher availability records by calling the 'initialize' method.
            this.initialize()
            
            // Re-enable the apply filter button for future filtering.
            this.applyActiveButton = true
        },
        /**
         * Cancels the bulk upload of disponibilities and closes the dialog.
         * This method is called when the user decides to cancel the bulk upload operation.
         * It resets the bulk form and closes the dialog for bulk uploading.
         */
        cancelUploadBulkDisponibilities(){
            // Reset the bulk form.
            this.bulkForm.reset();
            
            // Close the bulk upload dialog.
            this.dialogBulk = false;
        },
        /**
         * Populate the 'instructorsSkills' array with data from 'teacherSkills'.
         */
        getSkills(){
            // Iterate through the 'teacherSkills' array and create objects for 'instructorsSkills'.
            this.teacherSkills.forEach((element) => {
                this.instructorsSkills.push({
                    text: element.name,
                    id: element.id,
                    shortname: element.shortname
                })
            })
        },
        /**
         * Close the delete confirmation dialog.
         */
        closeDialogDelete(){
            this.dialogDelete = false
        },
        /**
         * Close the error dialog and clear the error message.
         */
        closeDialogError(){
            this.errorDialog = false
            this.errorMessage = ''
        },
    },
    computed: {
        /**
         * Computed property that returns the title for the availability form.
         * If the editedIndex is -1, it returns 'Nueva Disponibilidad' for creating a new record.
         * Otherwise, it returns 'Editar' for editing an existing record.
         */
        formTitle () {
            return this.editedIndex === -1 ? 'Nueva Disponibilidad' : 'Editar'
        },
        /**
         * Returns a validation rule function for vee-validate.
         * The function checks if the provided value is non-empty and returns a boolean.
         * If the value is empty, it returns an error message.
         *
         * @returns {Function} Validation rule function.
         */
        requiredRule() {
          return (value) => !!value || 'Este campo es requerido';
        },
        /**
         * Returns a list of instructors who do not have a disponibility record created.
         * If in edit mode, includes the currently edited instructor in the list.
         *
         * @returns {Array} List of available instructors.
         */
        instructorsPickerOptions(){
            // Filter instructors who have no disponibility record (hasDisponibility === 0).
            const availableInstructors = window.instructorItems.filter(instructor => instructor.hasDisponibility ===0 )
            
            // If in edit mode, include the currently edited instructor in the list.
            return this.editMode? [{...this.editingPickedInstructorData},...availableInstructors]:availableInstructors
        },
        /**
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        },
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang(){
            return window.strings
        },
        /**
         * Computed property that returns the data of the selected instructor.
         *
         * @returns {Object | undefined} Data of the selected instructor, or undefined if not selected.
         */
        selectedInstructorData(){
            return this.selectedInstructorId? this.teacherAvailabilityRecords.find(instructorAvailabilityRecord => instructorAvailabilityRecord.instructorId === this.selectedInstructorId):undefined
        },
        /**
         * Computed property that returns the data of the instructor selected in the form.
         *
         * @returns {Object | undefined} Data of the selected instructor, or undefined if not selected.
         */
        pickedInstructorData(){
            return this.pickedInstructorId? window.instructorItems.find(instructor => instructor.id === this.pickedInstructorId):undefined
        },
        /**
         * Computed property that returns the data of the instructor selected for editing.
         *
         * @returns {Object | undefined} Data of the selected instructor for editing, or undefined if not selected.
         */
        editingPickedInstructorData(){
            return this.pickedInstructorId? window.instructorItems.find(instructor => instructor.id === this.selectedInstructorId):undefined
        },
        /**
         * A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token(){
            return window.userToken;
        },
        /**
         * Determines if the time fields are valid (not empty).
         *
         * @returns {boolean} True if the time fields are not empty; otherwise, false.
         */
        isTimeFieldsValid() {
            return this.filterstartTime.trim() !== '' && this.filterstimeEnd.trim() !== '';
        },
        /**
         * Retrieves a reference to the 'bulkDisponibilitiesForm' form element.
         *
         * @returns {Element} A reference to the form element.
         */
        bulkForm(){
            return this.$refs['bulkDisponibilitiesForm'];
        },
        /**
         * A computed property to retrieve a list of teacher skills from the data source.
         *
         * @return {Array} An array of teacher skills.
         */
        teacherSkills(){
            return window.teacherSkills
        },
    },
    watch: {
        // Watch the 'dialog' property.
        dialog (val) {
            // If the 'dialog' property becomes false, call the 'close' method.
            val || this.close()
        },
        // Watch the 'schedules' property.
        schedules: {
            // Call the 'groupSchedulesPerDay' method when 'schedules' changes.
            handler: 'groupSchedulesPerDay',
            // Watch changes deeply within the 'schedules' property.
            deep: true
        },
    },
})