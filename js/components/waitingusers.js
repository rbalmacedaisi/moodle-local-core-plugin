Vue.component('waitingusers',{
    template: `
        <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-card class="overflow-hidden">
                    <v-app-bar
                      absolute
                      elevate-on-scroll
                      scroll-target="#scrolling-techniques-7"
                      app
                      max-height="60"
                    >
                        <v-toolbar-title>{{lang.waitinglists}}</v-toolbar-title>
        
                        <v-spacer></v-spacer>
              
                        <div v-if="totalSelectedUsers.length > 0"  class="px-3 mb-0 d-flex">
                            <v-spacer></v-spacer>
                            
                            <v-tooltip bottom>
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                      :color="!$vuetify.theme.isDark ? 'secondary' : 'primary'"
                                      icon
                                      v-bind="attrs"
                                      v-on="on"
                                      large
                                      @click="moveAll"
                                    >
                                        <v-icon
                                          v-bind="attrs"
                                          v-on="on"
                                          @click="moveAllItems"
                                        >
                                            mdi-folder-move-outline
                                        </v-icon>
                                    </v-btn>
                                </template>
                                <span>{{lang.move_to}}</span>
                            </v-tooltip>
                    
                            <v-tooltip bottom>
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                      :color="!$vuetify.theme.isDark ? 'secondary' : 'primary'"
                                      icon
                                      v-bind="attrs"
                                      v-on="on"
                                      large
                                      @click="deleteUsers"
                                    >
                                        <v-icon
                                          v-bind="attrs"
                                          v-on="on"
                                        >
                                            mdi-trash-can-outline
                                        </v-icon>
                                    </v-btn>
                                </template>
                                <span>{{ lang.remove }}</span>
                            </v-tooltip>
                        </div>
                    </v-app-bar>
             
                    <v-sheet
                      id="scrolling-techniques-7"
                      class="overflow-y-auto px-0"
                      max-height="700"
                    >
                        <v-card-text class="mt-10">
                            <div class="px-0 mb-2">
                                <v-checkbox 
                                  v-model="selectAll" 
                                  :label="lang.selectall" 
                                  id="selectall" class="px-3" 
                                  hide-details 
                                  :indeterminate="totalSelectedUsers.length > 0 && totalSelectedUsers.length < totalStudent"
                                  :input-value="valuechecked"
                                ></v-checkbox>
                            </div>
                            
                            <waitingtable
                              v-for="(classData, index) in filteredClassArray"
                              :key="index"
                              :classData="classData"
                              class="mb-8"
                              :selectusers="selectAll"
                              @selection-changed="updateTotalSelected"
                              @delete-users="deleteUsers"
                              @move-item="moveAll"
                              :icondisabled="icondisabled"
                              ref="waitingtable"
                            ></waitingtable>
                        </v-card-text>
                    </v-sheet>
                </v-card>
            </v-col>
            
            <movestudents v-if="movedialog" @classmoveselected="moveItem" @close="closeMove" @class-all-emit="classallemit" :icondisabled="icondisabled"></movestudents>
            <deleteusers v-if="deleteusers" @delete-users="deleteAvailabilityRecord" @close-delete="closedelete"></deleteusers>
        </v-row>
    `,
    data(){
        return{
            selectAll: false,
            movedialog: false,
            deleteusers: false,
            itemdelete: {},
            classArray: [],
            selectAllStudents: false,
            selected: [],
            totalSelectedUsers: [],
            indeterminate: false,
            totalStudent: 0,
            valuechecked: false,
            icondisabled: false,
            individualmoveclass: {},
        }
    },
    props:{},
    created(){
        this.getUsers()
    }, 
    mounted(){},  
    methods:{
        // Fetches class schedules and queues data from an external API.
        getUsers(){
            // Define the URL for the API endpoint.
            const url = this.siteUrl;
            
            // Create an object with parameters required for the API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_class_schedules_queues',
                courseId: this.courseId,
                periodIds: this.periodsid
            };
            
            // Make a GET request to the specified URL, passing the parameters as query parameters.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Convert the API response data from JSON string format to an object.
                    const data = JSON.parse(response.data.courseSchedulesQueues)
                    
                    // Update the classArray data property with the fetched data.
                    this.classArray = data
                    
                    // Calculate the total number of students.
                    this.calculateTotalStudents()
                })
                // Log an error message to the console if the request fails.
                .catch(error => {
                    console.error(error);
            });
        },
        /**
         * Opens a dialog for moving a class or item.
         * @param '{Object} item' - The class or item to be moved.
         */
        moveAll(item){
            // Set the 'movedialog' property to true to open the move dialog.
            this.movedialog = true
            
            // Store the selected class or item in the 'individualmoveclass' property.
            this.individualmoveclass = item
        },
        /**
         * Opens a dialog for deleting a class or item.
         * @param '{Object} item' - The class or item to be deleted.
         */
        deleteUsers(item){
            // Set the 'deleteusers' property to true to open the delete dialog.
            this.deleteusers = true
            
            // Initialize the 'itemdelete' property with the selected class or item.
            this.itemdelete = {}
        },
        /**
         * Deletes selected availability records for students from a class schedule.
         * This method generates dynamic parameters based on the selected students and their class IDs.
         */
        deleteAvailabilityRecord(){
            // Create an object to store dynamic parameters.
            const params = {};
            
            // Loop through the selected array and generate the parameters.
            for (let i = 0; i < this.totalSelectedUsers.length; i++) {
              const student = this.totalSelectedUsers[i];
              params[`deletedStudents[${i}][studentId]`] = student.userid;
              params[`deletedStudents[${i}][classId]`] = student.classid;
            }
            
            // Set additional parameters for the API request.
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_delete_student_from_class_schedule'
            
            // Call the 'deleteStudent' method with the generated parameters.
            this.deleteStudent(params)
        },
        /**
         * Sends a request to delete a student from a class schedule using the provided parameters.
         * @param '{Object} params' - The parameters required for the API call.
         */
        deleteStudent(params) {
            // Define the URL for the API endpoint.
            const url = this.siteUrl;
            
            // Make a GET request to the specified URL, passing the parameters as query parameters.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Reload the current page to reflect changes.
                    location.reload();
                    
                    // Close the delete dialog.
                    this.closedelete()
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            });
        },
        // Closes the delete dialog by setting the 'deleteusers' property to false.
        closedelete(){
            // Set the 'deleteusers' property to false to close the delete dialog.
            this.deleteusers = false
        },
        /**
         * Moves a student from one class schedule to another using the provided parameters.
         * @param '{Object} item' - The target class or schedule to move the student to.
         */
        moveItem(item){
            // Define parameters for the API request.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_change_students_schedules',
                'movingStudents[0][studentId]': this.individualmoveclass.userid,
                'movingStudents[0][currentClassId]': this.individualmoveclass.classid,
                'movingStudents[0][newClassId]': item.classid
            };
            
            // Call the 'updateclassselected' method with the generated parameters.
            this.updateclassselected(params)
        },
        /**
         * Moves a group of students from their current classes to a new class schedule.
         * @param '{Object} item' - The target class or schedule to move the students to.
         */
        classallemit(item){
            // Create an object to store dynamic parameters.
            const params = {};
            
            // Loop through the selected array of students and generate the parameters.
            for (let i = 0; i < this.totalSelectedUsers.length; i++) {
              const student = this.totalSelectedUsers[i];
              params[`movingStudents[${i}][studentId]`] = student.userid;
              params[`movingStudents[${i}][currentClassId]`] = student.classid;
              params[`movingStudents[${i}][newClassId]`] = item.classid; 
            }
            
            // Set additional parameters for the API request.
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_change_students_schedules'
            
            // Call the 'updateclassselected' method with the generated parameters.
            this.updateclassselected(params)
        },
        // Opens a dialog for moving all selected items.
        moveAllItems(){
            // Set the 'movedialog' property to true to open the move dialog.
            this.movedialog = true
        },
        /**
         * Sends a request to update class selections based on the provided parameters.
         * @param {Object} params - The parameters required for the API call.
         */
        updateclassselected(params){
            // Define the URL for the API endpoint.
            const url = this.siteUrl;
            
            // Make a GET request to the specified URL, passing the parameters as query parameters.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Close the move dialog.
                    this.movedialog = false
                    
                    // Reload the current page to reflect changes.
                    location.reload();
                    
                })
                // Log an error message to the console if the request fails.
                .catch(error => {
                    console.error(error);
            });
        },
        /**
         * Updates the list of total selected users based on user selections in child components.
         * @param '{Object} e' - The event object containing user selections.
         */
        updateTotalSelected(e) {
            // Clear the existing list of total selected users.
            this.totalSelectedUsers = []
            
            // Iterate through the child components using the '$refs' property.
            this.$refs.waitingtable.forEach((element) => {
                // Iterate through the selected items in each child component.
                element.selected.forEach((item) => {
                    // Add each selected item to the total selected users list.
                    this.totalSelectedUsers.push(item)
                })
            })
            
            // Disable or enable icons based on the number of selected users.
            this.totalSelectedUsers.length < 1 ? this.icondisabled = false : this.icondisabled = true
            
            // Perform any additional actions as needed, such as calling 'inputvalue()'.
            this.inputvalue()
        },
        // Calculates the total number of students across all classes.
        calculateTotalStudents(){
            // Initialize the 'totalStudent' property to 0.
            this.totalStudent = 0;
            
            if(this.classArray !== null && this.classArray !== undefined){
                // Iterate through each class in the 'classArray'.
                this.classArray.forEach((clase) => {
                    // Check if the class has a 'queue' property and 'queuedStudents' sub-property.
                    if (clase.queue && clase.queue.queuedStudents) {
                        // Add the count of queued students to the 'totalStudent' property.
                        this.totalStudent += Object.keys(clase.queue.queuedStudents).length;
                    }
                });
            }
        },
        // Updates the 'valuechecked' and 'selectAll' properties based on the current selection status.
        inputvalue(){
            // If all students are selected, set 'valuechecked' and 'selectAll' to true.
            if(this.totalSelectedUsers.length > 0 && this.totalSelectedUsers.length == this.totalStudent){
                this.valuechecked = true
                this.selectAll = true
            }else if(this.totalSelectedUsers.length > 0 && this.totalSelectedUsers.length < this.totalStudent){
                // If some students are selected, set 'valuechecked' to false.
                this.valuechecked = false
            }else if(this.totalSelectedUsers.length == 0 ){
                // If no students are selected, set 'valuechecked' and 'selectAll' to false.
                this.valuechecked = false
                this.selectAll = false
            }
        },
        // Closes the move dialog and clears the 'individualmoveclass' property.
        closeMove(){
            // Set the 'movedialog' property to false to close the move dialog.
            this.movedialog = false
            
            // Clear the 'individualmoveclass' property.
            this.individualmoveclass = {}
        }
    },
    computed: {
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
         * A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token(){
            return window.userToken;
        },
        /**
         * A computed property that returns the course ID from the 'window.courseid' variable.
         *
         * @returns '{string}' - The course ID.
         */
        courseId(){
            return window.courseid;
        },
        /**
         * Retrieves the value of the "periodsid" parameter from the current URL.
         * @returns '{string|null}'' The value of the "periodsid" parameter, or null if it's not found.
         */
        periodsid(){
            // Get the current URL of the page.
            var currentURL = window.location.href;
            
            // Create a URL object based on the current URL.
            var siteurl = new URL(currentURL);
            
            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            return periods
        },
        /**
         * Filters the class array to include only classes with students in the queue.
         * @returns '{Array}'' An array containing classes with queued students.
         */
        filteredClassArray() {
            // Check if this.classArray is not null or undefined.
            if (this.classArray !== null && this.classArray !== undefined) {
                // Filter classes that have students in the queue based on the length of 'queuedStudents'.
                return this.classArray.filter(classData => {
                    return Object.keys(classData.queue.queuedStudents).length > 0;
                });
            } else {
                return [];
            }
        },
    },
})