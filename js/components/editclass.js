const classData = window.templatedata
const token = window.usertoken
for (let day in classData.classDays) {
  classData.classDays[day]=classData.classDays[day]==="1"?true:false;
}
console.log(classData);

Vue.component('editclass',{
    template: `
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12 px-md-0">
                <div class="card py-3 px-4">
                    <!-- <div v-if="templatedata.reschedulingActivity" id="fields-groups" class="row pb-5 mt-2 mx-0">
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
                    </div> -->
                    
                    
                    <div id="fields-groups" class="row pb-5 mt-2 mx-0">
                        <div class="col-12">
                            <h5 class="text-secondary mb-0">{{ classData.name}}</h5>
                            <hr> </hr>
                        </div>    
                            
                        <div id="classname-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="className">{{ lang.class_name }}</label>
                            <input v-model="classData.name" ref="className" type="text" class="form-control" id="className" required>
                        </div>
                            
                        <div id="classtype-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="classType">{{ lang.class_type }}</label>
                            <select v-model="classData.type" ref="classType" id="classType" class="form-control" required>
                                <option value=''>{{ lang.select_type_class }}</option>
                                <option v-for="(item, index) in classTypes" :value="item.value">{{item.label}}</option> 
                            </select>
                        </div>
                            
                        <div id="learning-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="classLearningPlan">{{ lang.manage_careers }}</label>
                            <select v-model="classData.learningPlanId" ref="classLearningPlan" id="classLearningPlan" class="form-control" required   @change="getTeachers">
                                <option value=''>{{ lang.select_careers }}</option>
                                <option v-for="(item, index) in learningPlans" :value="item.value">{{item.label}}</option>
                            </select>
                        </div>
                            
                        <div id="period-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="classPeriod">{{ lang.period }}</label>
                            <select v-model="classData.periodId" ref="classPeriod" id="classPeriod" class="form-control" required>
                               <option value=''>{{ lang.select_period }}</option>
                               <option v-for="(item, index) in periods" :value="item.value">{{item.label}}</option>
                            </select>
                        </div>
                            
                        <div id="courses-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="classCourse">{{ lang.courses }}</label>
                            <select v-model="classData.courseId" ref="classCourse" id="classCourse" class="form-control" required @change="getTeachers">
                                <option value=''>{{ lang.select_courses }}</option>
                                <option v-for="(item, index) in courses" :value="item.value">{{item.label}}</option>
                            </select>
                        </div>
                        
                        <div class="col-sm-12 pb-0">
                            <v-divider></v-divider>
                            <h6 class="mt-6">{{lang.class_schedule_days}}</h6>
                        </div>
                            
                        <div id="starttime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="classInitTime">{{ lang.start_time }}</label>
                            <input v-model="classData.initTime" ref="classInitTime" type="time" class="form-control" id="classInitTime" @change="getTeachers" required>
                        </div>
                            
                        <div id="endtime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="classEndTime">{{ lang.end_time }}</label>
                            <input v-model="classData.endTime" ref="classEndTime" type="time" class="form-control" id="classEndTime" @change="getTeachers" required>
                        </div>
                            
                        <div id="starttime-fieldset" class="row form-group py-2 mx-0 px-2">
                            <div class="col-12">
                                <label>{{ lang.classdays}}</label>
                            </div>
                        
                            <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input v-model="classData.classDays.monday" type="checkbox" class="custom-control-input" id="customSwitchMonday">
                                    <label class="custom-control-label" for="customSwitchMonday">{{lang.monday}}</label>
                                </div>
                                
                                <div class="custom-control custom-switch col-6 col-sm-4">
                                    <input v-model="classData.classDays.tuesday" type="checkbox" class="custom-control-input" id="customSwitchTuesday">
                                    <label class="custom-control-label" for="customSwitchTuesday">{{lang.tuesday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input v-model="classData.classDays.wednesday" type="checkbox" class="custom-control-input" id="customSwitchWednesday">
                                    <label class="custom-control-label" for="customSwitchWednesday">{{lang.wednesday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4">
                                    <input v-model="classData.classDays.thursday" type="checkbox" class="custom-control-input" id="customSwitchThursday">
                                    <label class="custom-control-label" for="customSwitchThursday">{{lang.thursday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input v-model="classData.classDays.friday" type="checkbox" class="custom-control-input" id="customSwitchFriday">
                                    <label class="custom-control-label" for="customSwitchFriday">{{lang.friday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4">
                                    <input v-model="classData.classDays.saturday" type="checkbox" class="custom-control-input" id="customSwitchSaturday">
                                    <label class="custom-control-label" for="customSwitchSaturday">{{lang.saturday}}</label>
                                </div>
                                <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                    <input v-model="classData.classDays.sunday" type="checkbox" class="custom-control-input" id="customSwitchSunday">
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
                            <v-list-item-group v-model="teacherIndex">
                                <template v-for="(item, index) in teachers">
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
                        
                        
                    
                        <!--<div v-if="templatedata.reschedulingActivity" class="d-flex card-footer bg-transparent px-0">
                            <div class="spacer"></div>
                            <a href="#" class="btn btn-secondary m-1"></a>
                            <a id="rescheduleActivityButton" data-target='#rescheduleActivityModalCenter' data-toggle='modal' class="btn btn-primary m-1"></a>
                        </div>-->
                    
                        <div class="d-flex card-footer bg-transparent px-0">
                            <div class="spacer"></div>
                            <v-btn @click="cancelUrl" class="ma-2" small color="secondary">{{lang.cancel}}</v-btn>
                            <v-btn @click="saveClass" id="saveClassButton" class="ma-2" small color="primary">{{lang.save}}</v-btn>
                        </div>
                    
                </div>
            </div>
            
        </div>
    </div>
    `,
    data(){
        return{
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
            classData:{
                name: undefined,
                type: undefined,
                learningPlanId:undefined, 
                periodId:undefined, 
                courseId:undefined, 
                teacherindex:undefined,
                initTime:undefined,
                endTime:undefined, 
                classDays:{
                    monday:false,
                    tuesday:false,
                    wednesday:false,
                    thursday:false,
                    friday:false,
                    saturday:false,
                    sunday:false
                }
            },
            teacherIndex: undefined,
            filledInputs:false,
            classTypes:[],
            learningPlans:[],
            periods:[],
            courses:[],
            teachers:[]
        }
    },
    created(){
        this.fillInputs()
        
        // this.findSelectedTeacher()
    },
    methods:{
        cancelUrl(){
            window.location = '/local/grupomakro_core/pages/classmanagement.php'
        },
        fillInputs(){
            this.classData.name = classData.className;
            
            this.classTypes = classData.classTypes.options;
            this.classData.type= classData.classTypes.selected;
            
            this.learningPlans = classData.classLearningPlans.options;
            this.classData.learningPlanId= classData.classLearningPlans.selected;
            
            this.periods= classData.classPeriods.options;
            this.classData.periodId= classData.classPeriods.selected;
            
            this.courses= classData.classCourses.options;
            this.classData.courseId= classData.classCourses.selected;
            
            this.classData.initTime = classData.initTime;
            this.classData.endTime = classData.endTime;
            
            this.classData.classDays = classData.classDays;
            
            this.teachers = classData.classTeachers;
            this.teacherIndex  = this.teachers.findIndex(teacher => teacher.selected)
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
            if(!this.filledInputs) {
                return;
            }
            
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
        validateInputs(){
            const valid = this.inputs.every(input => {
                return input.reportValidity();
            });
            console.log(valid)
        },
        async saveClass(){
            this.validateInputs();
            
            return
            
            endTimeInput.get(0).setCustomValidity('');
            // Check the select inputs and the time inputs
            const valid = selectors.every(selector => {
                return selector.get(0).reportValidity();
            });
            if (!valid) {
                return;
            }
            //
    
            // Check if the init time is less than the end time of the class
            if (initTimeInput.val() >= endTimeInput.val()) {
                endTimeInput.get(0).setCustomValidity('La hora de finalización debe ser mayor a la hora de inicio.');
                endTimeInput.get(0).reportValidity();
                return;
            }
            //
    
            // Check if at least one day of the week is selected
            const daySelected = switches.some(day => {
                return day.is(":checked");
            });
            if (!daySelected) {
                mondaySwitch.get(0).setCustomValidity('Se debe seleccionar al menos un día de clase.');
                mondaySwitch.get(0).reportValidity();
                return;
            }
            //
            const args = {
                classId,
                name: classNameInput.val(),
                type: typeSelector.val(),
                learningPlanId: careerSelector.val(),
                periodId: periodSelector.val(),
                courseId: courseSelector.val(),
                instructorId: teacherSelector.val(),
                initTime: initTimeInput.val(),
                endTime: endTimeInput.val(),
                classDays: formatSelectedClassDays(),
            };
            const promise = Ajax.call([{
                methodname: 'local_grupomakro_update_class',
                args
            }]);
            promise[0].done(function(response) {
                window.console.log(response);
                if (response.status === -1) {
                    // Add the error message to the modal content.
                    try {
                        const errorMessages = JSON.parse(response.message);
                        let errorHTMLString = '';
                        errorMessages.forEach(message=>{
                            errorHTMLString += `<p class="text-center">${message}</p>`;
                        });
                        errorModalContent.html(errorHTMLString);
                    } catch (error) {
                        errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                    } finally {
                        errorModal.modal('show');
                    }
                }
                window.location.href = '/local/grupomakro_core/pages/classmanagement.php';
            }).fail(function(error) {
                window.console.error(error);
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
        inputs(){
            return [
                this.$refs.className,
                this.$refs.classType,
                this.$refs.classLearningPlan,
                this.$refs.classPeriod,
                this.$refs.classCourse,
                this.$refs.classInitTime,
                this.$refs.classEndTime
            ]  
        },
        lang(){
            return window.strings;
        },
        
        siteUrl(){
            return window.location.origin + '/webservice/rest/server.php'
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
        }
    },
    watch: {
        dayString: 'getTeachers',
    },
})