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
                        <template v-if="dataSchedule">
                             <span>Actualmente no hay clases disponibles. Por favor, cree una nueva clase para asignar a los usuarios que no tienen horarios.</span>
                        </template>
                        
                        <!-- Mostrar la lista de horarios si hay datos -->
                        <template v-else>
                            <v-row>
                                <v-col cols="12">
                                    <v-list flat three-line>
                                        <v-list-item-group v-model="settings" active-class="">
                                            <v-list-item v-for="item in schedules" :key="item.id">
                                                <template v-slot:default="{ active}">
                                                    <v-list-item-action>
                                                        <v-checkbox :input-value="active" @change="handleCheckboxChange(item)"></v-checkbox>
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
                        </template>
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
                { text: 'Actions', value: 'actions', sortable: false },
            ],
            users: [],
            deleteusers: false,
            itemdelete: {},
            search: '',
            menu: false,
            itemselected: {},
            schedules: [],
            dialog: false,
            settings: [],
            items: [],
            dataCourse: {},
            selectedItems: [],
            dataSchedule : false,
        }
    },
    props:{},
    created(){
        this.getStudent()
        this.getschedules()
    }, 
    mounted(){},  
    methods:{
        // Retrieves a list of students who do not have a schedule for a specific period.
        getStudent(){
            // Get the current URL of the page.
            var currentURL = window.location.href;
            
            // Create a URL object based on the current URL.
            var siteurl = new URL(currentURL);
            
            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            
            // Define the URL for the API endpoint.
            const url = this.siteUrl;
            
            // Create an object with parameters required for the API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_scheduleless_students',
                courseId: this.courseId,
                periodIds: periods
            };
            
            // Make a GET request to the specified URL, passing the parameters as query parameters.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Convert the API response data from JSON string format to an object.
                    const data = JSON.parse(response.data.schedulelessStudents)
                    
                    // Convert the data object to an array.
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    console.log(array)
                    
                    // Iterate through the array and add student information to the 'users' array.
                    array.forEach((element)=>{
                        this.users.push({
                            id: element.id,
                            img: element.profilePicture,
                            student: element.firstname + ' ' + element.lastname,
                            email: element.email,
                        })
                    })
                })
                // Log an error message to the console if the request fails.
                .catch(error => {
                    console.error(error);
            });  
        },
        // Retrieves class schedules for a specific course and period.
        getschedules(){
            // Get the current URL of the page.
            var currentURL = window.location.href;
            
            // Create a URL object based on the current URL.
            var siteurl = new URL(currentURL);
            
            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            
            // Define the URL for the API endpoint.
            const url = this.siteUrl;

            // Create an object with parameters required for the API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_class_schedules',
                courseId: this.courseId,
                periodIds: periods,
                skipApproved: 1
            };
            
            // Make a GET request to the specified URL, passing the parameters as query parameters.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Convert the API response data from JSON string format to an object.
                    const data = JSON.parse(response.data.courseSchedules)
                    if(data.length == 0){
                        this.dataSchedule = true
                    }
                    
                    // Convert the data object to an array.
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);
                    
                    // Populate the 'dataCourse' object with course and schedule data.
                    this.dataCourse.name = array[0].courseName
                    this.dataCourse.id = array[0].courseId
                    this.dataCourse.learningPlanIds = array[0].learningPlanIds
                    this.dataCourse.learningPlanNames = array[0].learningPlanNames
                    this.dataCourse.periodIds = array[0].periodIds
                    this.dataCourse.periodNames = array[0].periodNames
                    this.dataCourse.schedules = array[0].schedules
                    
                    // Iterate through the schedule data and add it to the 'schedules' array.
                    this.dataCourse.schedules.forEach((element)=>{
                        this.schedules.push({
                            id: element.id,
                            name: element.name,
                            days: element.classDaysString,
                            start: element.inithourformatted,
                            end: element.endhourformatted,
                            instructor: element.instructorName,
                            type: element.typelabel,
                            picture: element.instructorProfileImage,
                            quotas: element.classroomcapacity,
                            users: element.preRegisteredStudents,
                            waitingusers: element.queuedStudents,
                            isApprove: element.approved,
                            clasId: element.id,
                            selected: false
                        })
                    })
                    
                })
                // Log an error message to the console if the request fails.
                .catch(error => {
                    console.error(error);
            });
        },
        /**
         * Opens a dialog for adding a new class schedule and sets the 'itemselected' property.
         * @param '{Object} item' - The item or class schedule to be added.
         */
        addschedule(item){
            // Set the 'itemselected' property to the selected item.
            this.itemselected = item
            
            // Set the 'dialog' property to true to open the dialog.
            this.dialog = true
        },
        // Enrolls a student in a selected class and sends an API request for enrollment.
        save(){
            // Define the parameters for the API request.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_student_class_enrol',
                userId: this.itemselected.id,
                classId: this.selectedItems[0].clasId,
                forceQueue: 0
            };
            
            // Define the URL for the API endpoint.
            const url = this.siteUrl;
            
            // Make a GET request to the specified URL, passing the parameters as query parameters.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Close the dialog after enrollment is successful.
                    this.dialog = false
                    
                    // Reload the current page to reflect changes.
                    location.reload();
                    
                })
                // Log an error message to the console if the request fails.
                .catch(error => {
                    console.error(error);
            });
        },
        // Updates the 'settings' property based on selected items from the 'items' array.
        updateSettings() {
            // Update the 'settings' property with the selected items from the 'items' array.
            this.settings = this.items.filter(item => item.selected);
        },
        /**
         * Handles the change of a checkbox state for a given item.
         * @param '{Object} item' - The item associated with the checkbox.
         */
        handleCheckboxChange(item) {
            // Toggle the 'selected' property of the item.
            item.selected = !item.selected;
            
            // Find the index of the item in the 'selectedItems' array.
            const index = this.selectedItems.findIndex((selectedItem) => selectedItem.id === item.id);
            
            // If the item is selected and not already in 'selectedItems', add it.
            if (index === -1 && item.selected) {
                this.selectedItems.push(item);
            } else if (index !== -1 && !item.selected) {
                // If the item is deselected and exists in 'selectedItems', remove it.
                this.selectedItems.splice(index, 1);
            }
        },
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
        }
    },
})