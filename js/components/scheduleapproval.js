/* global wsUrl */
Vue.component('scheduleapproval', {
    template: `
         <v-row justify="center" class="my-2 mx-0 position-relative">
            <v-col cols="12" class="py-0">
                <v-card
                    class="mx-auto"
                    max-width="100%"
                  >
                    <v-card-title class="d-flex">
                        <div class="d-flex flex-column">
                            <span>{{lang.schedules}} - {{dataCourse.name}}</span>
                            <span class="text-caption text--secondary">{{dataCourse.periodNames}}</span>
                        </div>
                        
                        <v-spacer></v-spacer>
                        
                        <v-btn
                          :color="$vuetify.theme.isDark ? 'primary' : 'secondary'"
                          class="mx-2 rounded text-capitalize"
                          small
                          :outlined="$vuetify.theme.isDark"
                          :href="'/local/grupomakro_core/pages/users.php?courseid=' + courseId + '&periodsid=' + periodsIds"
                        >
                          {{lang.users}}
                        </v-btn>
                        
                        <v-btn
                          color="primary"
                          class="mx-2 rounded text-capitalize"
                          small
                          @click="approveAllSchedules"
                          :disabled="disabled"
                        >
                          {{lang.approve_schedules}}
                        </v-btn>
                    </v-card-title>
                    
                    <v-divider class="my-0"></v-divider>
                
                    <v-card-text 
                       class="px-8 py-8 pb-10">
                        <v-row>
                            <v-col cols="12" sm="12" md="6" lg="4" xl="3" v-for="(item, index) in items" :key="item.id" >
                                <v-card
                                    class="rounded-md pa-2"
                                    :color="!$vuetify.theme.isDark ? 'grey lighten-5' : ''"
                                    outlined
                                    style="border-color: rgb(208,208,208) !important;"
                                    width="100%"
                                    height="420"
                                >
                                    <v-list flat color="transparent">
                                        <v-list-item>
                                            <v-list-item-avatar size="65">
                                              <img
                                                :src="item.picture" alt="picture" width="72"
                                              >
                                            </v-list-item-avatar>
                            
                                            <v-list-item-content>
                                                <v-list-item-title>{{item.instructor}}</v-list-item-title>
                                                <v-list-item-subtitle>Instructor</v-list-item-subtitle>
                                            </v-list-item-content>
                            
                                            <v-list-item-action>
                                                <v-menu
                                                    bottom
                                                    left
                                                    v-if="item.isApprove < 1"
                                                >
                                                    <template v-slot:activator="{ on, attrs }">
                                                        <v-btn
                                                           icon
                                                           v-bind="attrs"
                                                           v-on="on"
                                                        >
                                                            <v-icon>mdi-dots-horizontal</v-icon>
                                                        </v-btn>
                                                    </template>
                                        
                                                    <v-list dense>
                                                        <v-list-item-group  v-model="selectedItem" color="primary">
                                                            <v-list-item @click="scheduleSelected(item)">
                                                                <v-list-item-icon>
                                                                    <v-icon>mdi-account-check</v-icon>
                                                                </v-list-item-icon>
                                                                
                                                                <v-list-item-content>
                                                                    <v-list-item-title>{{lang.registered_users}}</v-list-item-title>
                                                                </v-list-item-content>
                                                            </v-list-item>
                                                            
                                                            <v-list-item @click="waitinglist(item)">
                                                                <v-list-item-icon>
                                                                    <v-icon>mdi-account-clock</v-icon>
                                                                </v-list-item-icon>
                                                                
                                                                <v-list-item-content>
                                                                    <v-list-item-title>{{lang.waitinglist}}</v-list-item-title>
                                                                </v-list-item-content>
                                                            </v-list-item>
                                                        </v-list-item-group>
                                                    </v-list>
                                                </v-menu>
                                            </v-list-item-action>
                                        </v-list-item>
                                    </v-list>
                                
                                    <v-card-text class="pt-2">
                                        <div class="d-flex"> 
                                            <h5 class="mb-0 d-flex flex-column">{{item.name}}
                                                <small class="text--disabled text-subtitle-2">{{item.days}}</small>
                                            </h5>
                                        </div>
                                        
                                        <v-row class="mt-2">
                                            <v-col cols="6" class="py-2"> 
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.class_schedule }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.start + ' - ' + item.end }}</p>
                                            </v-col>
                                            
                                            <v-col cols="6" class="py-2"> 
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.class_type }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.type }}</p>
                                            </v-col>
                                            
                                            <v-col cols="6" class="py-2">
                                                <span class="d-block text--disabled  text-subtitle-2">{{ lang.quotas_enabled }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.quotas }}</p>
                                            </v-col>
                                            
                                            <v-col v-if="item.isApprove < 1" cols="6" class="py-2">
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.registered_users }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.users }}</p>
                                            </v-col>
                                            
                                            <v-col v-if="item.isApprove > 0" cols="6" class="py-2">
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.users }}</span>
                                                <p class="text-subtitle-2 font-weight-medium mb-0">{{ item.enroledStudents }}</p>
                                            </v-col>
                                            
                                            <v-col cols="6" class="py-2">
                                                <span class="d-block text--disabled text-subtitle-2">{{ lang.waitingusers }}</span>
                                                <p v-if="item.isApprove < 1"  class="text-subtitle-2 font-weight-medium mb-0">{{ item.waitingusers }}</p>
                                                <p v-else  class="text-subtitle-2 font-weight-medium mb-0">0</p>
                                            </v-col>
                                        </v-row>
                                        
                                        <v-img
                                          v-if="item.isApprove > 0"
                                          :src="img"
                                          width="65"
                                          class="img-aproved"
                                          alt="img-aproved"
                                        ></v-img>
                                    </v-card-text>
                                
                                    <v-card-actions v-if="item.isApprove == 0" class="justify-center">
                                        <v-btn
                                          class="ma-2 rounded text-capitalize"
                                          outlined
                                          color="secondary"
                                          small
                                          @click="showdelete(item)"
                                        >
                                            <v-icon>mdi-delete-forever-outline</v-icon>
                                            {{ lang.remove }}
                                        </v-btn>
                                        
                                        <v-btn
                                          class="ma-2 rounded text-capitalize"
                                          outlined
                                          color="primary"
                                          small
                                          @click="showapprove(item)"
                                          :disabled="item.users == 0"
                                        >
                                            <v-icon>mdi-account-multiple-check-outline</v-icon>
                                            {{ lang.approve_users }}
                                        </v-btn>
                                    </v-card-actions>
                                    
                                    <v-card-actions v-else>
                                        <v-btn
                                          class="ma-2 rounded text-capitalize"
                                          color="primary"
                                          small
                                          outlined
                                          @click="userslist(item)"
                                        >
                                            <v-icon>mdi-account-check</v-icon>
                                            {{lang.userlist}}
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>
            
            <v-dialog
              v-model="dialog"
              max-width="800"
            >
                <v-card>
                    <v-card-title>
                        {{scheldule.title}}
                    </v-card-title>
                    
                    <v-card-subtitle>
                        {{scheldule.days}} {{scheldule.hours}}
                    </v-card-subtitle>
                    
                    <v-card-text>
                        <v-data-table
                            v-model="selectedStudents"
                            :headers="headers"
                            :items="users"
                            item-key="student"
                            show-select
                            dense
                            :items-per-page="50"
                            hide-default-footer
                        >
                            <template v-slot:top>
                                <div class="d-flex">
                                    <h6>{{tabletitle}}</h6>
                                    <v-spacer></v-spacer>
                                    <div v-if="selectedStudents.length > 1"  class="d-flex" style="padding-right: 25px;">
                                        <v-tooltip bottom>
                                            <template v-slot:activator="{ on, attrs }">
                                                <v-btn
                                                  :color="!$vuetify.theme.isDark ? 'secondary' : 'primary'"
                                                  icon
                                                  v-bind="attrs"
                                                  v-on="on"
                                                  small
                                                  class="mr-2"
                                                  @click="moveUsersOtherSchedule(selectedStudents)"
                                                >
                                                    <v-icon
                                                      v-bind="attrs"
                                                      v-on="on"
                                                      
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
                                                  small
                                                  @click="openDeleteUsersFromCourseClassScheduleDialog"
                                                >
                                                    <v-icon
                                                      v-bind="attrs"
                                                      v-on="on"
                                                    >
                                                        mdi-delete
                                                    </v-icon>
                                                </v-btn>
                                            </template>
                                            <span>{{ lang.remove }}</span>
                                        </v-tooltip>
                                    </div>
                                </div>
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
                                           @click="moveItem(item)"
                                           :disabled="!selectedStudents.includes(item)"
                                        >
                                            mdi-folder-move-outline
                                        </v-icon>
                                    </template>
                                    <span>{{ lang.move_to }}</span>
                                </v-tooltip>
                                
                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-icon 
                                           @click="openDeleteUserFromCourseClassScheduleDialog(item)" 
                                           v-bind="attrs"
                                           v-on="on"
                                           :disabled="!selectedStudents.includes(item)"
                                           
                                        >
                                            mdi-delete
                                        </v-icon>
                                    </template>
                                    <span>{{ lang.remove }}</span>
                                </v-tooltip>
                            </template>
                        </v-data-table>
                    </v-card-text>
                    
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn
                           color="primary"
                           text
                           @click="close"
                        >
                            {{ lang.close }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
            
            <v-overlay :value="overlay">
                <v-progress-circular
                  indeterminate
                  size="64"
                ></v-progress-circular>
            </v-overlay>
            
            <availableschedulesdialog 
              v-if="availableschedulesdialog" 
              :moveTitle="moveTitles" 
              :schedules="folders"
              :schelduletitle="scheldule.title"
              @close-move-dialog="closemovedialog"
              @save-class="saveClass"
              @new-class="newClassSelected"
            ></availableschedulesdialog>
            
            <schedulevalidationdialog 
              v-if="showDialog"
              @close-showdialog="closeShowDialog"
              @save-message="saveMessage"
              :bulkConfirmationDialog="bulkConfirmationDialog"
            >
            </schedulevalidationdialog>
            
            <deleteclass v-if="deleteclass" :itemdelete="itemdelete" @close-delete="closedelete"></deleteclass>
            
            <deleteusers :show="showDeleteUserConfirmationDialog" @confirm="deleteStudentFromCourseClassSchedule" @close="closeDeleteUserConfirmationDialog"></deleteusers>
            
            <approveusers v-if="approveusers" :itemapprove="usersapprove" @send-message="sendMessage" @close-approve="closeapprove"></approveusers>
            
            <userslist v-if="userslis" :classId="usersClasId" @close-list="closeList"></userslist>
        </v-row>
    `,
    data() {
        return {
            items: [],
            selectedItem: '',
            dialog: false,
            headers: [
                {
                    text: window.strings.student,
                    align: 'start',
                    sortable: false,
                    value: 'student',
                },
                { text: window.strings.actions, value: 'actions', sortable: false },
            ],
            users: [],
            scheldule: {},
            movedialog: false,
            moveTitle: '',
            folders: [],
            selectedClass: '',
            menu: false,
            tabletitle: '',
            deleteclass: false,
            itemdelete: {},
            approveusers: false,
            usersapprove: {},
            approved: false,
            dataCourse: {},
            schedulesAproveds: [],
            approvalReasonField: false,
            messagesOk: false,
            params: {},
            periodsIds: '',
            showDialog: false,
            message: '',
            overlay: false,
            bulkConfirmationDialog: {
                title: ''
            },
            usersClasId: 0,
            userslis: false,
            disabled: false,
            availableschedulesdialog: false,
            selectedStudents: [],
            selectedStudent: undefined,
            showDeleteUserConfirmationDialog: false,
            selectedUser: null
        }
    },
    props: {},
    created() {
        this.getData()
    },
    mounted() { },
    methods: {
        /**
         * This method is responsible for making an HTTP request to retrieve data about the active schedules of a course.
         * It constructs the API request with the necessary parameters, sends the request, and processes the response.
         */
        getData() {
            // Get the current URL of the page.
            var currentURL = window.location.href;
            var siteurl = new URL(currentURL);

            // Get the value of the "periodsid" parameter from the current URL.
            var periods = siteurl.searchParams.get("periodsid");
            this.periodsIds = periods

            const url = this.siteUrl;
            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_class_schedules',
                courseId: this.courseId,
                periodIds: this.periodsIds
            };

            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Converts the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.courseSchedules)
                    const arrayEntries = Object.entries(data);
                    const array = arrayEntries.map(([clave, valor]) => valor);

                    if (!array || array.length === 0) {
                        console.warn('No class schedules found for this course/period combination.');
                        return;
                    }

                    this.dataCourse.name = array[0].courseName
                    this.dataCourse.id = array[0].courseId
                    this.dataCourse.learningPlanIds = array[0].learningPlanIds
                    this.dataCourse.learningPlanNames = array[0].learningPlanNames
                    this.dataCourse.periodIds = array[0].periodIds
                    this.dataCourse.periodNames = array[0].periodNames
                    this.dataCourse.schedules = array[0].schedules

                    // Add the schedule data to the items array.
                    this.dataCourse.schedules.forEach((element) => {
                        this.items.push({
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
                            enroledStudents: element.enroledStudents
                        })
                    })

                    // We use the every() method to validate if all elements meet the condition.
                    const allComply = this.items.every(elemento => elemento.users === 0 && elemento.waitingusers === 0);

                    if (allComply) {
                        this.disabled = true
                    } else {
                        this.disabled = false
                    }
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
                });
        },
        /**
         * This method is triggered when a schedule item is selected. It retrieves information about students
         * enrolled in the selected class schedule and updates the component's state with the relevant data.
         * @param '{Object} item' - The selected class schedule item.
         */
        scheduleSelected(item) {
            // Clear the 'users' array to prepare for new data.
            this.users = []

            // Construct the API request URL.
            const url = this.siteUrl;

            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_students_by_class_schedule',
                classId: item.clasId
            };
            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Parse the JSON response data.
                    const data = JSON.parse(response.data.classStudents)
                    // Extract the pre-registered students from the data.
                    const preRegisteredStudents = data.preRegisteredStudents

                    // Convert the pre-registered students data to an array.
                    var dataArray = Object.values(preRegisteredStudents);

                    if (dataArray.length > 0) {
                        // Push student information into the 'users' array.
                        dataArray.forEach((element) => {
                            this.users.push({
                                student: element.firstname + ' ' + element.lastname,
                                id: element.userid,
                                email: element.email,
                                img: element.profilePicture,
                                classid: element.classid,
                            })
                        })
                    }
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
                });

            // Update the 'scheldule' object with schedule details.
            this.scheldule.title = item.name
            this.scheldule.days = item.days
            this.scheldule.hours = item.start + ' a ' + item.end

            // Open the dialog to display the selected schedule's information and enrolled students.
            this.dialog = true

            this.tabletitle = this.lang.registered_users
        },
        /**
         * This method is triggered when the "Waiting List" button is clicked for a class schedule.
         * It retrieves information about students on the waiting list for the selected class schedule
         * and updates the component's state with the relevant data.
         * @param '{Object} item' - The selected class schedule item.
         */
        waitinglist(item) {
            // Clear the 'users' array to prepare for new data.
            this.users = []

            // Clear the 'tabletitle' to reset any previous titles.
            this.tabletitle = ''

            // Construct the API request URL.
            const url = this.siteUrl;

            // Create a params object with the parameters needed to make an API call.
            const params = {
                wstoken: this.token,
                moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_get_course_students_by_class_schedule',
                classId: item.clasId
            };

            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Parse the JSON response data.
                    const data = JSON.parse(response.data.classStudents)

                    // Extract the queued students from the data.
                    const queuedStudents = data.queuedStudents

                    // Convert the queued students data to an array.
                    var dataArray = Object.values(queuedStudents);

                    if (dataArray.length > 0) {
                        // Push student information into the 'users' array.
                        dataArray.forEach((element) => {
                            this.users.push({
                                student: element.firstname + ' ' + element.lastname,
                                id: element.userid,
                                email: element.email,
                                img: element.profilePicture,
                                classid: element.classid,
                            })
                        })
                    }
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
                });

            // Update the 'scheldule' object with schedule details.
            this.scheldule.title = item.name
            this.scheldule.days = item.days
            this.scheldule.hours = item.start + ' a ' + item.end

            // Set the 'tabletitle' to indicate that the displayed list is the waiting list.
            this.tabletitle = this.lang.waitingusers

            // Open the dialog to display the waiting list for the selected schedule.
            this.dialog = true
        },
        /**
         * Closes the currently open dialog and resets associated component state.
         * This method is used to close various dialog boxes and clear relevant data.
         */
        close() {
            // Close the main dialog.
            this.dialog = false

            // Clear the 'users' array, which stores student data.
            this.users = []

            // Close the 'movedialog' used for moving students.
            this.movedialog = false

            // Clear the 'selectedItem' variable, which may hold selected items.
            this.selectedItem = ''

            this.selectedStudents = [];
            this.selectedStudent = undefined;
        },
        /**
         * Initiates the process of moving students from one class schedule to another.
         * This method populates the 'folders' array with available class schedules for moving,
         * and opens the 'movedialog' to facilitate the move operation.
         *
         * @param '{Object} item' - The student item to be moved.
         */
        moveItem(item) {
            this.moveTitles = [];
            // Initialize the 'folders' array to store available class schedules for moving.
            this.folders = []
            // Check if the student item is already selected for moving.
            const index = this.selectedStudents.findIndex(selectedItem => selectedItem.student === item.student);

            // If the student item is not already selected, add it to the 'selected' array.
            if (index === -1) {
                this.selectedStudents.push(item);
            }

            // Get the ID of the current class schedule.
            const id = item.classid
            console.log(id)
            // Populate the 'folders' array with class schedules that can be moved to.
            this.items.forEach((element) => {
                console.log(element.id)
                // Ensure the class schedule is not the current one and has not been approved.
                if (element.id != id && element.isApprove == 0) {
                    this.folders.push(element)
                }
            })
            // Set the title for the 'movedialog' to indicate the student being moved.
            this.moveTitles.push(item.student);

            // Open the 'availableschedulesdialog' to facilitate the move operation.
            this.availableschedulesdialog = true
        },
        /**
         * Displays the confirmation dialog for deleting a class schedule.
         * This method sets the 'itemdelete' property to the provided item and sets 'deleteclass' to true
         * to trigger the display of the delete confirmation dialog.
         *
         * @param '{Object} item' - The class schedule item to be deleted.
         */
        showdelete(item) {
            // Set the 'itemdelete' property to the provided class schedule item.
            this.itemdelete = item

            // Set 'deleteclass' to true to trigger the display of the delete confirmation dialog.
            this.deleteclass = true
        },
        /**
         * Closes the delete confirmation dialog.
         * This method sets the 'deleteclass' property to false to hide the delete confirmation dialog.
         */
        closedelete() {
            // Set 'deleteclass' to false to hide the delete confirmation dialog.
            this.deleteclass = false
        },
        /**
         * Closes the approval confirmation dialog.
         * This method sets the 'approveusers' property to false to hide the approval confirmation dialog.
         */
        closeapprove() {
            // Set 'approveusers' to false to hide the approval confirmation dialog.
            this.approveusers = false
        },
        /**
         * Approves or rejects all class schedules in the 'items' array.
         * This method iterates through the 'items' array and constructs parameters for approving schedules.
         * If a schedule requires confirmation due to quota constraints, it prompts the user for a confirmation message.
         * Finally, it calls the 'approvedClass' method to send the approval requests to the server.
         */
        async approveAllSchedules() {
            const params = {};
            for (let index = 0; index < this.items.length; index++) {
                const element = this.items[index];
                // Check if the schedule is not already approved.
                if (element.isApprove != 1) {
                    params[`approvingSchedules[${index}][classId]`] = element.clasId
                    // Check if the schedule's quotas have constraints.
                    if (element.quotas > element.users + element.waitingusers || element.quotas < element.users + element.waitingusers) {
                        // Set the title for the bulk confirmation dialog.
                        this.bulkConfirmationDialog.title = element.name

                        // Show the confirmation dialog.
                        this.showDialog = true;
                        this.message = ''

                        // Await user input for confirmation message.
                        const message = await this.getMessage();

                        // Hide the confirmation dialog.
                        this.showDialog = false;

                        // Set the confirmation message in the parameters.
                        params[`approvingSchedules[${index}][approvalMessage]`] = message
                    }
                }
            }

            // Set common parameters for the API request.
            params.wstoken = this.token;
            params.moodlewsrestformat = 'json';
            params.wsfunction = 'local_grupomakro_approve_course_class_schedules';

            // Send the approval requests to the server.
            this.approvedClass(params)
        },
        /**
         * Asynchronously waits for a message to be saved using a promise.
         * This method creates a promise to wait until the message is saved and resolved.
         * It listens for the 'save-message' event from the dialog and resolves the promise with the entered message.
         *
         * @returns '{Promise<string>}'' A promise that resolves with the entered message.
         */
        async getMessage() {
            // Create a promise to wait until the message is saved.
            return new Promise((resolve) => {
                // Listen for the 'save-message' event from the dialog.
                this.$once('save-message', () => {
                    // Resolve the promise with the entered message.
                    resolve(this.message);
                });
            });
        },
        /**
         * Initiates the process of saving a message by closing a dialog, displaying an overlay, and emitting a custom event.
         * This method performs the following steps:
         * 1. Closes the dialog.
         * 2. Shows an overlay.
         * 3. Delays execution for 2000 milliseconds (2 seconds) to simulate a message saving process.
         * 4. Hides the overlay.
         * 5. Emits the 'save-message' event to indicate that the message has been saved.
         */
        async saveMessage(message) {
            this.message = message
            // Close the dialog.
            this.showDialog = false;

            // Show the overlay.
            this.overlay = true;

            // Delay execution for 2000 milliseconds (2 seconds) to simulate a message saving process.
            await new Promise(resolve => setTimeout(resolve, 2000));

            // Hide the overlay.
            this.overlay = false;

            // Emit the 'save-message' event to indicate that the message has been saved.
            this.$emit('save-message');
        },
        /**
         * Prepares to approve class schedules, initializes parameters, and checks for approval eligibility.
         * This method performs the following steps:
         * 1. Sets 'approveusers' flag to 'false'.
         * 2. Initializes an empty object 'params' to store approval parameters.
         * 3. Adds the selected 'item' to the 'schedulesAproveds' array.
         * 4. Sets 'usersapprove' to the selected 'item'.
         * 5. Sets up the API request parameters for approving class schedules.
         * 6. Iterates through the 'schedulesAproveds' array to generate approval parameters.
         * 7. Checks if the schedule is eligible for approval based on quotas and user counts.
         * 8. If all selected schedules are eligible, triggers the 'approvedClass' method and sets 'approveusers' to 'true'.
         * 9. Sets 'approvalReasonField' to 'false'.
         */
        showapprove(item) {
            // Set 'approveusers' flag to 'false'.
            this.approveusers = false

            // Initialize an empty object 'params' to store approval parameters.
            this.params = {}

            // Add the selected 'item' to the 'schedulesAproveds' array.
            this.schedulesAproveds.push(item)

            // Set 'usersapprove' to the selected 'item'.
            this.usersapprove = item

            // Set up the API request parameters for approving class schedules.
            const url = this.siteUrl;
            this.params.wstoken = this.token
            this.params.moodlewsrestformat = 'json'
            this.params.wsfunction = 'local_grupomakro_approve_course_class_schedules'

            // Initialize a counter for eligible schedules.
            var counter = 0

            // Loop through the 'schedulesAproveds' array and generate approval parameters.
            for (let i = 0; i < this.schedulesAproveds.length; i++) {
                counter++
                const schedule = this.schedulesAproveds[i];

                // Set approval parameter for the class schedule.
                this.params[`approvingSchedules[${i}][classId]`] = schedule.clasId;
                this.schedulesAproveds[i].paramsid = schedule.clasId

                // Check if the schedule is eligible for approval based on quotas and user counts.
                if (schedule.quotas > schedule.users + schedule.waitingusers || schedule.quotas < schedule.users + schedule.waitingusers) {
                    this.approveusers = true
                } else {
                    if (counter == this.schedulesAproveds.length) {
                        // If all selected schedules are eligible, trigger the 'approvedClass' method.
                        this.approvedClass(this.params)
                        this.approveusers = true
                    }

                    // Set 'approvalReasonField' to 'false'.
                    this.approvalReasonField = false
                }
            }
        },
        /**
         * Sends an approval message for class schedules and triggers the 'approvedClass' method.
         * This method performs the following steps:
         * 1. Logs the provided 'message' to the console.
         * 2. Iterates through the 'schedulesAproveds' array.
         * 3. Sets the 'approvalMessage' parameter for each class schedule with the provided 'message'.
         * 4. Triggers the 'approvedClass' method with the approval parameters.
         *
         * @param '{string}'' message - The approval message to be sent.
         */
        sendMessage(message) {
            // Iterate through the 'schedulesAproveds' array.
            for (let i = 0; i < this.schedulesAproveds.length; i++) {
                const schedule = this.schedulesAproveds[i];

                // Set the 'approvalMessage' parameter for each class schedule with the provided 'message'.
                this.params[`approvingSchedules[${i}][approvalMessage]`] = message;
            }

            // Trigger the 'approvedClass' method with the approval parameters.
            this.approvedClass(this.params)
        },
        /**
         * Approves class schedules based on the provided parameters using an HTTP GET request.
         * This method performs the following actions:
         * 1. Sends an HTTP GET request to the specified URL with the provided parameters.
         * 2. Reloads the page if the request is resolved successfully.
         * 3. Logs an error to the console if the request fails.
         *
         * @param {object} params - The parameters for approving class schedules.
         */
        approvedClass(params) {
            // Send an HTTP GET request to the specified URL with the provided parameters.
            window.axios.get(wsUrl, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Reload the page after successful approval.
                    location.reload();
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
                });
        },
        /**
         * Moves selected students to a new class schedule based on the provided parameters using an HTTP GET request.
         * This method performs the following actions:
         * 1. Creates an object to store dynamic parameters.
         * 2. Loops through the selected array and generates the parameters for moving students.
         * 3. Sends an HTTP GET request to the specified URL with the generated parameters.
         * 4. Calls the 'saveClass' method with the parameters to complete the student transfer.
         *
         * @param '{object} schedule' - The class schedule to which students will be moved.
         */
        newClassSelected(schedule) {
            this.availableschedulesdialog = false
            // Create an object to store dynamic parameters.
            const params = {};
            console.log(this.selectedStudents)
            // Loop through the selected array and generate the parameters for moving students.
            for (let i = 0; i < this.selectedStudents.length; i++) {
                const student = this.selectedStudents[i];
                params[`movingStudents[${i}][studentId]`] = student.id;
                params[`movingStudents[${i}][currentClassId]`] = student.classid;
                params[`movingStudents[${i}][newClassId]`] = schedule.clasId;
            }

            // Set the common parameters for the HTTP GET request.
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_change_students_schedules'

            // Call the 'saveClass' method with the parameters to complete the student transfer.
            this.saveClass(params)
        },
        /**
         * Sends an HTTP GET request to save class changes with the provided parameters.
         * This method performs the following actions:
         * 1. Constructs the URL for the HTTP GET request using the 'siteUrl'.
         * 2. Makes an HTTP GET request to the constructed URL, passing the 'params' as query options.
         * 3. Handles the response from the server:
         *    - Logs the response data to the console.
         *    - Closes the 'movedialog' and 'dialog' components.
         *    - Refreshes the current page using 'location.reload()'.
         * 4. Handles errors by logging them to the console.
         *
         * @param {object} params - The parameters needed for the HTTP GET request.
         */
        saveClass(params) {
            // Construct the URL for the HTTP GET request using the 'siteUrl'.
            const url = this.siteUrl;
            // Make an HTTP GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Close the 'movedialog' and 'dialog' components.
                    this.movedialog = false
                    this.dialog = false
                    // Refresh the current page.
                    location.reload();

                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
                });
        },
        openDeleteUserFromCourseClassScheduleDialog(selectedStudent) {
            this.selectedStudents = [];
            this.selectedStudent = selectedStudent;
            this.showDeleteUserConfirmationDialog = true
        },
        openDeleteUsersFromCourseClassScheduleDialog() {
            this.selectedStudent = undefined
            this.showDeleteUserConfirmationDialog = true
        },
        closeDeleteUserConfirmationDialog() {
            this.selectedStudent = undefined
            this.showDeleteUserConfirmationDialog = false
        },
        /**
         * Sends an HTTP GET request to delete student records from a class schedule.
         * This method performs the following actions:
         * 1. Constructs the URL for the HTTP GET request using 'siteUrl'.
         * 2. Sends a GET request to the specified URL with 'params' as query options.
         * 3. Handles the response from the server:
         *    - Closes the 'movedialog' and 'dialog' components.
         *    - Refreshes the current page using 'location.reload()'.
         * 4. Handles errors by logging them to the console.
         *
         * @param {Object} params - Parameters for the DELETE request.
         */
        deleteStudentFromCourseClassSchedule() {
            // Create an object to store dynamic parameters.
            const params = {};
            const studentToBeEliminated = this.selectedStudent ? [this.selectedStudent] : this.selectedStudents;
            console.log(this.selectedStudent)
            // Loop through the 'selected' array and generate the parameters.
            for (let i = 0; i < studentToBeEliminated.length; i++) {
                const student = studentToBeEliminated[i];
                params[`deletedStudents[${i}][studentId]`] = student.id;
                params[`deletedStudents[${i}][classId]`] = student.classid;
            }

            // Set fixed parameters for the HTTP GET request.
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_delete_student_from_class_schedule'
            // Construct the URL for the HTTP GET request.

            const url = this.siteUrl;

            // Make a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                // If the request is resolved successfully, perform the following operations.
                .then(response => {
                    // Close the 'movedialog' and 'dialog' components.
                    this.movedialog = false
                    this.dialog = false

                    // Refresh the current page.
                    window.location.reload();

                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
                });
        },
        /**
         * Set the 'usersClasId' property and show the 'userslis' component.
         *
         * @param {Object} item - The item containing class-related information.
         */
        userslist(item) {
            // Set the 'usersClasId' property.
            this.usersClasId = item.clasId
            // Show the 'userslis' component.
            this.userslis = true
        },
        /**
         * Close the 'userslis' component and reset the 'usersClasId' property.
         */
        closeList() {
            // Hide the 'userslis' component.
            this.userslis = false
            // Reset the 'usersClasId' property to 0.
            this.usersClasId = 0
        },
        /**
         * Close the move dialog and reset the 'moveTitle'.
         */
        closemovedialog() {
            // Reset the 'moveTitle' property.
            this.moveTitle = ''
            // Hide the 'availableschedulesdialog' to facilitate the move operation.
            this.availableschedulesdialog = false
        },
        /**
         * Close the 'showDialog' component.
         */
        closeShowDialog() {
            // Hide the 'showDialog' component.
            this.showDialog = false
        },
        /**
        * Method move to users masive to new Schedule
        */
        moveUsersOtherSchedule(userItems) {
            // Inicializa el arreglo 'folders' para almacenar los horarios de clases disponibles para mover.
            this.folders = [];
            this.moveTitles = [];

            userItems.forEach(item => {
                console.log(item.student)
                // Check if element exits to move
                const index = this.selectedStudents.findIndex(selectedItem => selectedItem.student === item.student);

                // If element not exists add into List 'selectedStudents'.
                if (index === -1) {
                    this.selectedStudents.push(item);
                }

                // Add students Name to Titles
                this.moveTitles.push(item.student);
            });

            // Get the Id of class Actually
            const idClass = userItems[0].classid;

            // Populate the 'folders' array with class schedules that can be moved to.
            this.items.forEach((element) => {
                console.log(element.id)
                // Ensure the class schedule is not the current one and has not been approved.
                if (element.id != idClass && element.isApprove == 0) {
                    this.folders.push(element)
                }
            })

            // Open the dialog
            this.availableschedulesdialog = true;
        },
    },
    computed: {
        /**
         * A computed property that returns a validation rule function for use with the vee-validate library.
         * The validation function checks whether a value is non-empty or not and returns an error message if empty.
         *
         * @returns '{function}' - A validation rule function.
         */
        requiredRule() {
            return (value) => !!value || 'Este campo es requerido';
        },
        /**
         * A computed property that returns the site URL for making API requests.
         * It combines the current origin with the API endpoint path.
         *
         * @returns '{string}' - The constructed site URL.
         */
        siteUrl() {
            return window.location.origin + '/webservice/rest/server.php'
        },
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang() {
            return window.strings
        },
        /**
         * A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token() {
            return window.userToken;
        },
        /**
         * A computed property that returns the course ID from the 'window.courseid' variable.
         *
         * @returns '{string}' - The course ID.
         */
        courseId() {
            return window.courseid;
        },
        /**
         * Computed property that returns the approved image stored in the global 'aprovedImg'.
         */
        img() {
            return window.aprovedImg
        }
    },
})
