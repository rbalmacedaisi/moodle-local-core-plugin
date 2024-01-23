Vue.component('createclass',{
    template: `
    <div class="container-fluid">   
        <div class="row">
            <div class="col-sm-12 col-md-12 col-lg-12 col-xl-12 px-md-0">
                <div class="card px-4 pt-3">
                    <form>
                        <div id="fields-groups" class="row pb-5 mt-2 mx-0">
                            <div class="col-sm-12 pb-0">
                                <h6>{{lang.class_general_data}}</h6>
                            </div>
                            <div id="classname-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="classname">{{lang.class_name}}</label>
                                <input type="text" class="form-control" id="classname" required :placeholder="lang.class_name_placeholder">
                            </div>
                            
                            <div id="classtype-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="classtype">{{lang.class_type}}</label>
                                <select name="classtype" id="classtype" class="form-control" required>
                                    <option value=''>{{lang.class_type_placeholder}}</option>
                                    <option v-for="(item, index) in classTypes" :value="item.value">{{item.label}}</option>
                                </select>
                            </div>
                            
                            <div id="classroom-fieldset" class="col-sm-12 col-md-6 py-2 d-none">
                                <label class="w-100" for="classroom">{{lang.class_room}}</label>
                                <select name="classroom" id="classroom" class="form-control" required>
                                    <option value=''>{{lang.class_room_placeholder}}</option>
                                    <option v-for="(item, index) in classrooms" :value="item.value">{{item.label}}</option>
                                </select>
                            </div>
                            
                            <div id="learning-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="career">{{lang.class_learning_plan}}</label>
                                <select name="career" id="career" class="form-control" required @change="getTeachers">
                                    <option value=''>{{lang.class_learningplan_placeholder}}</option>
                                    <option v-for="(item, index) in availableCareers" :value="item.value">{{item.label}}</option>
                                </select>
                            </div>
                           
                            <div id="period-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="period">{{lang.class_period}}</label>
                                <select name="period" id="period" class="form-control" required>
                                   <option value=''>{{lang.class_period_placeholder}}</option>
                                </select>
                            </div>
                            
                            <div id="courses-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="courses">{{lang.class_course}}</label>
                                <select name="courses" id="courses" class="form-control" required @change="getTeachers">
                                   <option value=''>{{lang.class_course_placeholder}}</option>
                                </select>
                            </div>
                            
                            <div class="col-sm-12 pb-0">
                                <v-divider></v-divider>
                                <h6 class="mt-6">{{lang.class_date_time}}</h6>
                            </div>
                            
                            <div id="starttime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                                <label class="w-100" for="starttime">{{lang.class_start_time}}</label>
                                <input type="time" class="form-control" id="starttime" required @change="getTeachers">
                            </div>
                            
                            <div id="endtime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                                <label class="w-100" for="endtime">{{lang.class_end_time}}</label>
                                <input type="time" class="form-control" id="endtime" required @change="getTeachers">
                            </div>
                          
                            <div id="starttime-fieldset" class="row form-group py-2 mx-0 px-2">
                                <div class="col-12 pb-0">
                                    <label>{{lang.class_days}}</label>
                                </div>
                            
                                <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input type="checkbox" class="custom-control-input" id="customSwitchMonday" v-model="mondayChecked">
                                    <label class="custom-control-label" for="customSwitchMonday">{{lang.monday}}</label>
                                </div>
                                
                                <div class="custom-control custom-switch col-6 col-sm-4">
                                    <input type="checkbox" class="custom-control-input" id="customSwitchTuesday" v-model="tuesdayChecked">
                                    <label class="custom-control-label" for="customSwitchTuesday">{{lang.tuesday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input type="checkbox" class="custom-control-input" id="customSwitchWednesday" v-model="wednesdayChecked">
                                    <label class="custom-control-label" for="customSwitchWednesday">{{lang.wednesday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4">
                                    <input type="checkbox" class="custom-control-input" id="customSwitchThursday" v-model="thursdayChecked">
                                    <label class="custom-control-label" for="customSwitchThursday">{{lang.thursday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input type="checkbox" class="custom-control-input" id="customSwitchFriday" v-model="fridayChecked">
                                    <label class="custom-control-label" for="customSwitchFriday">{{lang.friday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4">
                                    <input type="checkbox" class="custom-control-input" id="customSwitchSaturday" v-model="saturdayChecked">
                                    <label class="custom-control-label" for="customSwitchSaturday">{{lang.saturday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input type="checkbox" class="custom-control-input" id="customSwitchSunday" v-model="sundayChecked">
                                    <label class="custom-control-label" for="customSwitchSunday">{{lang.sunday}}</label>
                                </div>
                            </div>
                        </div>
                        
                        <v-divider></v-divider>
                        
                        <div class="d-flex px-3 mt-6">
                            <h6>{{lang.class_available_instructors}}</h6>
                            <v-spacer></v-spacer>
                            <v-btn
                              text
                              color="primary"
                              small
                              class="text-decoration-underline text-capitalize"
                              href="/local/grupomakro_core/pages/availabilitypanel.php"
                              target="blank"
                            >
                              {{lang.see_availability}}
                            </v-btn>
                        </div>
                        
                        <v-list dense two-line>
                            <v-list-item-group v-model="settings">
                                <template v-for="(item, index) in instructors">
                                    <v-list-item color="success">
                                        <template v-slot:default="{ active }">
                                            <v-list-item-icon>
                                                <v-icon>mdi-school</v-icon>
                                            </v-list-item-icon>
                                            <v-list-item-content>
                                                <v-list-item-title>{{ item.fullname }}</v-list-item-title>
                                                <v-list-item-subtitle class="text-caption" v-text="item.email"></v-list-item-subtitle>
                                            </v-list-item-content>
                                    
                                            <v-list-item-action>
                                                <v-checkbox
                                                  :input-value="active"
                                                  color="success"
                                                  @change="selectedCheck(item)"
                                                ></v-checkbox>
                                            </v-list-item-action>
                                        </template>
                                    </v-list-item>
                                </template>
                            </v-list-item-group>
                        </v-list>
                        <input type="hidden" id="instructorId">
                    </form>
                        
                    <div class="d-flex card-footer bg-transparent mt-3 px-0">
                        <div class="spacer"></div>
                        <v-btn @click="cancelUrl" class="ma-2" small color="secondary">{{lang.cancel}}</v-btn>
                        <v-btn id="saveClassButton" class="ma-2" small color="primary">{{lang.save}}</v-btn>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="modal fade" id="errorModal" tabindex="-1" aria-labelledby="errorModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="errorModalLabel">Error</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div id="error-modal-content" class="modal-body"></div>
                    <div class="modal-footer">
                        <v-btn small color="secondary" data-dismiss="modal">{{lang.close}}</v-btn>
                    </div>
                </div>
            </div>
        </div>
    </div>
    `,
    data(){
        return{
            settings: "",
            dialog: false,
            instructors: [],
            paramsInstructors: {
                lpId: '',
                courseId: '',
                initTime: null,
                endTime: null,
            },
            mondayChecked: false,
            tuesdayChecked: false,
            wednesdayChecked: false,
            thursdayChecked: false,
            fridayChecked: false,
            saturdayChecked: false,
            sundayChecked: false,
        }
    },
    methods:{
        /**
         * Redirect to the specified URL when cancel action is triggered.
         */
        cancelUrl(){
            // Redirect the user to the '/local/grupomakro_core/pages/classmanagement.php' URL
            window.location = '/local/grupomakro_core/pages/classmanagement.php'
        },
        /**
         * Toggle the selection of an item and update an input field.
         *
         * @param {Object} item - The item to select or deselect.
         */
        selectedCheck(item){
            // Toggle the selected state of the item
            item.selected = !item.selected;
            
            // Get the DOM element with the ID 'instructorId'
            const instructorId = document.getElementById('instructorId')
            
            // Check if the item is selected
            if(item.selected){
                // If selected, set the item's ID value to 'instructorId'.
                instructorId.value = item.id
            }else{
                // If not selected, clear the value of 'instructorId'.
                instructorId.value = ''
            }
            console.log(instructorId.value)
        },
        // Fetches a list of teachers based on specified parameters.
        getTeachers(){
            // Get the base URL for the API request.
            const url = this.siteUrl;
            
            // Get references to various form elements.
            const careerSelector = document.getElementById("career")
            const courseId = document.getElementById("courses")
            const timeInput = document.getElementById("starttime");
            const endTime = document.getElementById("endtime");
            
            // Update parameters with values from form elements.
            this.paramsInstructors.lpId = careerSelector.value
            this.paramsInstructors.courseId = courses.value
            this.paramsInstructors.initTime = timeInput.value
            this.paramsInstructors.endTime = endTime.value
            
            // Create an object to hold the API request parameters.
            const params = {
                learningPlanId: this.paramsInstructors.lpId,
            }
           
           // Check if additional parameters should be added.
            if(this.paramsInstructors.courseId){
                params.courseId = this.paramsInstructors.courseId
            }
            if(this.paramsInstructors.initTime){
                params.initTime = this.paramsInstructors.initTime
            }
            if(this.paramsInstructors.endTime){
                params.endTime = this.paramsInstructors.endTime
            }
            if (this.dayString) {
                params.classDays = this.dayString;
            }
            
            // Set required parameters for the API call
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_get_potential_class_teachers'
            
            // Perform a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                .then(response => {
                    // Parse the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.teachers)
                    
                    // Update the 'instructors' data property with the fetched data.
                    this.instructors = data
                    console.log(this.instructors)
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            }); 
        }
    },
    computed:{
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang(){
            return window.strings;
        },
        /**
         * Computed property that returns the value of the global 'classTypes'.
         */
        classTypes(){
            return window.classTypes
        },
        /**
         * Computed property that returns the value of the global 'classrooms'.
         */
        classrooms(){
            return window.classrooms
        },
        /**
         * Computed property that returns the value of the global 'classrooms'.
         */
        availableCareers(){
            return window.availableCareers
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
         * A computed property that returns the user token from the 'window.userToken' variable.
         *
         * @returns '{string}' - The user token.
         */
        token(){
            return window.userToken;
        },
        /**
         * Computed property that generates a string representation of selected days.
         */
        dayString() {
            // Create an array of binary values for each day (1 if checked, 0 if not)
            const days = [
                this.mondayChecked ? '1' : '0',
                this.tuesdayChecked ? '1' : '0',
                this.wednesdayChecked ? '1' : '0',
                this.thursdayChecked ? '1' : '0',
                this.fridayChecked ? '1' : '0',
                this.saturdayChecked ? '1' : '0',
                this.sundayChecked ? '1' : '0',
            ];
            // Join the binary values with '/' to form a formatted string
            return days.join('/');
        },
    },
    watch: {
        /**
         * Watch for changes in the 'dayString' property and call the 'getTeachers' method when it changes.
         */
        dayString: 'getTeachers',
    },
})