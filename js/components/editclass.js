Vue.component('editclass',{
    template: `
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12 px-md-0">
                <div class="card py-3 px-4">
                    <div v-if="templatedata.reschedulingActivity" id="fields-groups" class="row pb-5 mt-2 mx-0">
                        <div class="col-12">
                            <h5 class="text-secondary mb-0">{{className}}</h5>
                            <hr> </hr>
                        </div>
                        
                        <div id="newDate-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="newDate">{{lang.new_date}}</label>
                            <input type="date" class="form-control" id="newDate" :value="templatedata.activityProposedDate" required>
                        </div>
                        
                        <div id="newStartTime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="newStartTime">{{lang.start_time}}</label>
                            <input type="time" class="form-control" id="newStartTime" :value="templatedata.activityProposedInitTime" required>
                        </div>
                        
                        <div id="newEndTime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="newEndTime">{{lang.end_time}}</label>
                            <input type="time" class="form-control" id="newEndTime" :value="templatedata.activityProposedEndTime" required>
                        </div>
                    </div>
                    
                    <div v-else id="fields-groups" class="row pb-5 mt-2 mx-0">
                        <div class="col-12">
                            <h5 class="text-secondary mb-0">{{ templatedata.className }}</h5>
                            <hr> </hr>
                        </div>    
                            
                        <div id="classname-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="classname">{{ lang.class_name }}</label>
                            <input type="text" class="form-control" id="classname" :value="templatedata.className" required>
                        </div>
                            
                        <div id="classtype-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="class_type">{{ lang.class_type }}</label>
                            <select name="class_type" id="class_type" class="form-control" required>
                                <option value=''>{{ lang.select_type_class }}</option>
                                <option v-for="(item, index) in classTypes" :value="item.value" :selected="item.selected">{{item.label}}</option> 
                            </select>
                        </div>
                            
                        <div id="learning-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="career">{{ lang.manage_careers }}</label>
                            <select name="career" id="career" class="form-control" required  @change="getTeachers">
                                <option value=''>{{ lang.select_careers }}</option>
                                <option v-for="(item, index) in templatedata.availableCareers" :value="item.value" :selected="item.selected">{{item.label}}</option>
                            </select>
                        </div>
                            
                        <div id="period-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="period">{{ lang.period }}</label>
                            <select name="period" id="period" class="form-control" required>
                               <option value=''>{{ lang.select_period }}</option>
                               <option v-for="(item, index) in templatedata.periods" :value="item.value" :selected="item.selected">{{item.label}}</option>
                            </select>
                        </div>
                            
                        <div id="courses-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="courses">{{ lang.courses }}</label>
                            <select name="courses" id="courses" class="form-control" required @change="getTeachers">
                                <option value=''>{{ lang.select_courses }}</option>
                                <option v-for="(item, index) in templatedata.courses" :value="item.value" :selected="item.selected">{{item.label}}</option>
                            </select>
                        </div>
                        
                        <div class="col-sm-12 pb-0">
                            <v-divider></v-divider>
                            <h6 class="mt-6">{{lang.class_schedule_days}}</h6>
                        </div>
                            
                        <div id="starttime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="starttime">{{ lang.start_time }}</label>
                            <input type="time" class="form-control" id="starttime"  v-model="starttimeField" @change="getTeachers" required>
                        </div>
                            
                        <div id="endtime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="endtime">{{ lang.end_time }}</label>
                            <input type="time" class="form-control" id="endtime" :value="templatedata.endTime" @change="getTeachers" required>
                        </div>
                            
                        <div id="starttime-fieldset" class="row form-group py-2 mx-0 px-2">
                            <div class="col-12">
                                <label>{{ lang.classdays}}</label>
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
                        
                        <!--<div id="instructor-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="instructor">{{ lang.instructor }}</label>
                            <select name="instructor" id="instructor" class="form-control" required>
                                <option value=''>{{ lang.select_instructor }}</option>
                                <option v-for="(item, index) in templatedata.teachers" :value="item.value" :selected="item.selected">{{item.label}}</option> 
                            </select>
                        </div>-->
                            
                        <div class="d-flex px-3 mt-6">
                            <h6>{{lang.list_available_instructors}}</h6>
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
                                <v-list-item color="success">
                                    <template v-slot:default="{ active }">
                                        <v-list-item-icon>
                                            <v-icon>mdi-school</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-content>
                                            <v-list-item-title>{{ teacherEditSelected.label }}</v-list-item-title>
                                        </v-list-item-content>
                                
                                        <v-list-item-action>
                                            <v-checkbox
                                              v-model="checkbox"
                                              :input-value="active"
                                              color="success"
                                            ></v-checkbox>
                                        </v-list-item-action>
                                    </template>
                                </v-list-item>
                                
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
                        
                        
                    
                        <div class="d-flex card-footer bg-transparent px-0">
                            <div class="spacer"></div>
                            <a href="#" class="btn btn-secondary m-1"></a>
                            <a id="rescheduleActivityButton" data-target='#rescheduleActivityModalCenter' data-toggle='modal' class="btn btn-primary m-1"></a>
                        </div>
                    
                    <div class="d-flex card-footer bg-transparent px-0">
                        <div class="spacer"></div>
                        <a href="#" class="btn btn-secondary m-1"></a>
                        <a id="saveClassButton" class="btn btn-primary m-1"></a>
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
            teacherEditSelected: {
                label: '',
                value: ''
            },
            checkbox: true,
            starttimeField: ''
        }
    },
    created(){
        this.mondayChecked = this.templatedata.mondayValue ? true: false;
        this.tuesdayChecked = this.templatedata.tuesdayValue ? true: false;
        this.wednesdayChecked = this.templatedata.wednesdayValue ? true: false;
        this.thursdayChecked = this.templatedata.thursdayValue ? true: false;
        this.fridayChecked = this.templatedata.fridayValue ? true: false;
        this.saturdayChecked = this.templatedata.saturdayValue ? true: false;
        this.sundayChecked = this.templatedata.sundayValue ? true: false;
        this.starttimeField = templatedata.initTime
        
        this.findSelectedTeacher()
    },
    methods:{
        cancelUrl(){
            window.location = '/local/grupomakro_core/pages/classmanagement.php'
        },
        selectedCheck(item){
            console.log(item)
            item.selected = !item.selected;
            console.log(item.selected)
            const instructorId = document.getElementById('instructorId')
            if(item.selected){
                instructorId.value = item.id
                this.checkbox = false
            }else{
                instructorId.value = ''
            }
            console.log(instructorId.value)
        },
        getTeachers(){
            // URL of the API to be used for data retrieval.
            const url = this.siteUrl;
            
            const careerSelector = document.getElementById("career")
            const courseId = document.getElementById("courses")
            const timeInput = document.getElementById("starttime");
            const endTime = document.getElementById("endtime");
            
            this.paramsInstructors.lpId = careerSelector.value
            this.paramsInstructors.courseId = courses.value
            this.paramsInstructors.initTime = timeInput.value
            this.paramsInstructors.endTime = endTime.value
            
            const params = {
                learningPlanId: this.paramsInstructors.lpId,
            }
           
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
            // Parameters required for making the API call.
            params.wstoken = this.token
            params.moodlewsrestformat = 'json'
            params.wsfunction = 'local_grupomakro_get_potential_class_teachers'
            
            console.log(params)
            // Perform a GET request to the specified URL, passing the parameters as query options.
            window.axios.get(url, { params })
                .then(response => {
                    console.log(response)
                    // Parse the data returned from the API from JSON string format to object format.
                    const data = JSON.parse(response.data.teachers)
                    console.log(data)
                    
                    this.instructors = data
                })
                // If the request fails, log an error to the console.
                .catch(error => {
                    console.error(error);
            }); 
        },
        findSelectedTeacher() {
            const teacherSelected = this.templatedata.teachers.find(teacher => teacher.selected === "selected");
            console.log(teacherSelected)
            this.teacherEditSelected.label = teacherSelected.label
            this.teacherEditSelected.value = teacherSelected.value
            //this.instructors.push(teacherSelected)
        }
    },
    computed:{
        lang(){
            return window.strings;
        },
        classTypes(){
            return window.classTypes
        },
        
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
        },
        token(){
            return window.userToken;
        },
        dayString() {
            const days = [
                this.mondayChecked ? '1' : '0',
                this.tuesdayChecked ? '1' : '0',
                this.wednesdayChecked ? '1' : '0',
                this.thursdayChecked ? '1' : '0',
                this.fridayChecked ? '1' : '0',
                this.saturdayChecked ? '1' : '0',
                this.sundayChecked ? '1' : '0',
            ];
            return days.join('/');
        },
        className(){
            return window.clasname
        },
        templatedata(){
            return window.templatedata
        }
    },
    watch: {
        dayString: 'getTeachers',
    },
})