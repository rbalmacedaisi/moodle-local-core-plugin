const removeDiacriticAndLowerCase = (string) => {
    return string.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase()
}

Vue.component('academiccalendartable',{
    template: `
        <div>
            <div class="d-flex align-center pl-4 mb-4">
                <h1 class="h2 mb-0">{{lang.academic_calendar_title}}</h1>
                <v-divider
                  class="mx-2"
                  inset
                  vertical
                >
                </v-divider>
                <span class="font-weight-bold text-h5">{{'2024'}}</span>
                <v-spacer></v-spacer>
                
                <div class="mt-1 d-flex align-center"> 
                    <form action="" method='POST' enctype="multipart/form-data" class="custom-file mr-3" id="upload-calendar-form" ref="uploadCalendarForm">
                        <input @change="openUploadDialog" type="file" class="custom-file-input" id="upload_calendar" data-initial-value="" name="massivecsv" accept=".xlsx"
                        ref="uploadCalendarInput">
                        <label class="custom-file-label" for="upload_calendar">{{lang.upload_calendar_label}}</label>
                     </form>
                </div>
            </div>
            
            <v-row justify="center" class="my-2 mx-0 position-relative">
                <v-col cols="12" class="py-0">
                    <v-data-table
                       :headers="tableHeaders"
                       :items="academicCalendarRecords"
                       class="elevation-1 calendar-table"
                       hide-default-footer
                    >
                        <template v-slot:top>
                            <v-toolbar flat>
                                <v-toolbar-title>{{lang.calendar_table_title}}</v-toolbar-title>
                            </v-toolbar>
                        </template>
                        
                        <template v-slot:item.period="{ item }">
                            <p class="my-2 text-no-wrap">{{item.period}}</p>
                        </template>
                        
                        <template v-slot:item.bimesters="{ item }">
                            <div v-for="(bimester,key, index) in item.bimesters">
                                <p class="my-2 text-no-wrap">{{bimester}}</p>
                                <v-divider v-if="index < Object.keys(item.bimesters).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
    
                        <template v-slot:item.start="{ item }">
                            <div v-for="(date,key, index) in item.start">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.start).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.end="{ item }">
                            <div v-for="(date,key, index) in item.end">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.end).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.induction="{ item }">
                            <p class="my-2 text-no-wrap">{{item.induction}}</p>
                        </template>
                        
                        <template v-slot:item.finalExamRange="{ item }">
                            <div v-for="(date,key, index) in item.finalExamRange">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.finalExamRange).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.loadnotesandclosesubjects="{ item }">
                            <div v-for="(date,key, index) in item.loadnotesandclosesubjects">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.loadnotesandclosesubjects).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.delivoflistforrevalbyteach="{ item }">
                            <div v-for="(date,key, index) in item.delivoflistforrevalbyteach">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.delivoflistforrevalbyteach).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.notiftostudforrevalidations="{ item }">
                            <div v-for="(date,key, index) in item.notiftostudforrevalidations">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.notiftostudforrevalidations).length - 1" class="my-0"></v-divider>
                            </div>    
                        </template>
                        
                        <template v-slot:item.deadlforpayofrevalidations="{ item }">
                            <div v-for="(date,key, index) in item.deadlforpayofrevalidations">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.deadlforpayofrevalidations).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.revalidationprocess="{ item }">
                            <div v-for="(date,key, index) in item.revalidationprocess">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.revalidationprocess).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.registrationRange="{ item }">
                            <div v-for="(date,key, index) in item.registrationRange">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.registrationRange).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:item.graduationdate="{ item }">
                            <div v-for="(date,key, index) in item.graduationdate">
                                <p class="my-2 text-no-wrap">{{date}}</p>
                                <v-divider v-if="index < Object.keys(item.graduationdate).length - 1" class="my-0"></v-divider>
                            </div>
                        </template>
                        
                        <template v-slot:no-data>
                            <v-btn color="primary" text @click="initialize">No hay datos</v-btn>
                        </template>
                    </v-data-table>
                </v-col>
                
                <v-col cols="12" v-if="fetchingData">
                    <v-overlay :value="fetchingData" z-index='200' class="text-center">
                        <v-progress-circular
                            :size="70"
                            :width="7"
                            color="primary"
                            indeterminate
                            class="mt-5"
                        ></v-progress-circular>
                    </v-overlay>
                </v-col>
                
                <uploadcalendarmodal 
                  :show="uploadDialog"
                  :uploading="uploadingCalendar"
                  @close="cancelUploadCalendar"
                  @confirm="uploadCalendar"
                >
                </uploadcalendarmodal>
                
                <errormodal 
                  :show="errorDialog"
                  :message="errorMessage"
                  @close="closeDialogError"
                >
                </errormodal>
            </v-row>
        </div>
    `,
    data(){
        return{
            tableHeaders: undefined,
            fetchingData: false,
            academicCalendarRecords:undefined,
            lang:window.strings,
            uploadDialog:false,
            uploadingCalendar:false,
            errorMessage:undefined,
            errorDialog:false,
            uploadCalendarResults:undefined
        }
    },
    created(){
        this.tableHeaders = window.tableHeaders;
        this.initialize()
    },  
    methods:{
        /**
         * Initializes the data of instructors' availability.
         * This method performs the following actions:
         * 1. Sets the '' to true to display a loading .
         * 2. Constructs a params object with the necessary parameters for an API call.
         * 3. Makes a GET request to the specified URL, passing the parameters as query options.
         * 4. Sets the '' to false to hide the loading .
         * 5. Checks if the API response indicates an error, and logs an error message if needed.
         * 6. Parses the data from the API response and assigns it to the 'teacherAvailabilityRecords' array.
         * 7. Iterates through the 'teacherAvailabilityRecords' and extracts the available days for each instructor.
         */
        async initialize () {
            
            try{
                // Set the loading  to indicate data retrieval.
                this.fetchingData = true
                
                // Create a params object with the necessary parameters for the API call.
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_get_academic_calendar_period',
                    year:new Date().getFullYear()
                };
                
                // Make a GET request to fetch instructor availability data.
                const academicCalendarPeriods = await window.axios.get(this.siteUrl, { params })
                if(academicCalendarPeriods.data.status === -1) throw academicCalendarPeriods.data.message;
                
                // Parse the data from the API response and assign it to the 'teacherAvailabilityRecords' array.
                this.academicCalendarRecords = JSON.parse(academicCalendarPeriods.data.academicPeriodRecords)   
            }catch(error){
                // Check if the response indicates an error and log the error message if needed.
                window.console.error(error)
            }finally{
                this.fetchingData = false
            }
        },
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
        async uploadCalendar(){
            this.uploadingCalendar=true
            try{
                // Define request headers.
                const headers = {
                    'Content-Type': 'multipart/form-data'
                }
                
                // Get the selected file from the input element.
                const file =  this.$refs['uploadCalendarInput'].files[0];
                
                // Prepare the request parameters for uploading the CSV file,
                const uploadCalendarExcelRequestParams = {
                    token:this.token,
                    file:file
                }
                
                // Send a POST request to upload the CSV file.
                const uploadCalendarExcelResponse = await window.axios.post(`${window.location.origin}/webservice/upload.php`,uploadCalendarExcelRequestParams, {headers});
                console.log(uploadCalendarExcelResponse)
                
                // Check the response status and data.
                if(uploadCalendarExcelResponse.status !== 200 || !(uploadCalendarExcelResponse.data && uploadCalendarExcelResponse.data[0])){
                    throw new Error('Error uploading the Excel file');
                }
                
                // Extract data from the uploaded file response.
                const uploadedFileData = uploadCalendarExcelResponse.data[0];
                
                // Prepare request parameters for updating teachers' disponibilities.
                const updateCalendarRequestParams = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_upload_academic_calendar_period',
                    contextId: uploadedFileData.contextid,
                    itemId: uploadedFileData.itemid,
                    filename: uploadedFileData.filename
                };
               
                // Send a GET request to update teachers' disponibilities.
                const updateCalendarResponse = await window.axios.get(this.siteUrl,{params:updateCalendarRequestParams});
                this.uploadingCalendar=false
                if(updateCalendarResponse.data.exception || updateCalendarResponse.data.status === -1){
                    throw new Error(updateCalendarResponse.data.message);
                }
                window.location.reload();
                
                // this.uploadCalendarResults=JSON.parse(updateCalendarResponse.data.results);
            }catch(error){
                this.cancelUploadCalendar();
                this.openErrorDialog(error.message);
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
        openUploadDialog(event){
            
            let fileName =event.target.files[0].name;

            // Use the split method to separate the file name and extension
            let parts = fileName.split('.');
            let fileExtension = parts[parts.length - 1];

            // Check if the selected file is not in Excel format.
            if( fileExtension !== 'xlsx'){
                // Reset the file input and display an alert
                this.uploadForm.reset();
                window.alert('El archivo debe estar en formato Excel');
                return 
            }
            
            // If the file is in Excel format, open the bulk upload dialog.
            this.uploadDialog = true
            return
        },
        /**
         * Cancels the bulk upload of disponibilities and closes the dialog.
         * This method is called when the user decides to cancel the bulk upload operation.
         * It resets the bulk form and closes the dialog for bulk uploading.
         */
        cancelUploadCalendar(){
            this.uploadingCalendar = false;
            // Reset the bulk form.
            this.uploadForm.reset();
            
            // Close the bulk upload dialog.
            this.uploadDialog = false;
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
         * Close the error dialog and clear the error message.
         */
        closeDialogError(){
            this.errorDialog = false
            this.errorMessage = ''
        },
    },
    computed: {
        /**
         * A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token(){
            return window.userToken;
        },
        /**
         * Retrieves a reference to the 'bulkDisponibilitiesForm' form element.
         *
         * @returns {Element} A reference to the form element.
         */
        uploadForm(){
            return this.$refs['uploadCalendarForm'];
        },
        /**
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        }
    }
})