const templateData = window.templatedata;
const wsurl = window.location.origin + '/webservice/rest/server.php';
const wstoken = window.userToken;
const wsDefaultParams = {
    wstoken,
    moodlewsrestformat: 'json'
}
window.Vue.component('createclass', {
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
                                <label class="w-100" for="className">{{lang.class_name}}</label>
                                <input v-model="classData.name" ref="className" type="text" class="form-control" id="className" required :placeholder="lang.class_name_placeholder">
                            </div>
                            
                            <div id="classtype-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="classType">{{lang.class_type}}</label>
                                <select v-model="classData.type" ref="classType" id="classType" class="form-control" required>
                                    <option :value="undefined">{{lang.class_type_placeholder}}</option>
                                    <option v-for="classType in templateData.classTypes" :value="classType.value">{{classType.label}}</option>
                                </select>
                            </div>
                            
                            <div id="classroom-fieldset" class="col-sm-12 col-md-6 py-2" v-show="showClassRoomSelector">
                                <label class="w-100" for="classRoom">{{lang.class_room}}</label>
                                <select  v-model="classData.classRoomIndex" ref="classRoom" id="classRoom" class="form-control" :required="showClassRoomSelector">
                                    <option :value="undefined">{{lang.class_room_placeholder}}</option>
                                    <option v-for="(classRoom,index) in templateData.classRooms" :value="index">{{classRoom.label}}</option>
                                </select>
                            </div>
                            
                            <div id="learning-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="classLearningPlan">{{lang.class_learning_plan}}</label>
                                <select v-model="classData.learningPlanId" ref="classLearningPlan" id="classLearningPlan" class="form-control" @change="handleLearningPlanChange" required>
                                    <option :value="undefined">{{lang.class_learningplan_placeholder}}</option>
                                    <option v-for="learningPlan in templateData.learningPlans" :value="learningPlan.value">{{learningPlan.label}}</option>
                                </select>
                            </div>
                           
                            <div id="period-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="classPeriod">{{lang.class_period}}</label>
                                <select v-model="classData.periodId" ref="classPeriod" id="classPeriod" class="form-control" required @change="handleLearningPlanPeriodChange">
                                   <option :value="undefined">{{lang.class_period_placeholder}}</option>
                                   <option v-for="period in periods" :value="period.value">{{period.label}}</option>
                                </select>
                            </div>
                            
                            <div id="courses-fieldset" class="col-sm-12 col-md-6 py-2">
                                <label class="w-100" for="classCourse">{{lang.class_course}}</label>
                                <select v-model="classData.courseId" ref="classCourse" name="courses" id="classCourse" class="form-control" required @change="getPotentialTeachers">
                                    <option :value="undefined">{{lang.class_course_placeholder}}</option>
                                    <option v-for="course in courses" :value="course.value">{{course.label}}</option>
                                </select>
                            </div>
                            
                            <div class="col-sm-12 pb-0">
                                <v-divider></v-divider>
                                <h6 class="mt-6">{{lang.class_date_time}}</h6>
                            </div>
                            
                            <div id="starttime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                                <label class="w-100" for="classInitTime">{{lang.class_start_time}}</label>
                                <input v-model="classData.initTime" ref="classInitTime" type="time" class="form-control" id="classInitTime" required @change="getPotentialTeachers">
                            </div>
                            
                            <div id="endtime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                                <label class="w-100" for="classEndTime">{{lang.class_end_time}}</label>
                                <input v-model="classData.endTime" ref="classEndTime" type="time" class="form-control" id="classEndTime" required @change="getPotentialTeachers">
                            </div>
                          
                            <!-- Dates Row -->
                            <div class="row align-center px-2">
                                <div class="col-12 col-md-6 px-3">
                                    <v-menu
                                        v-model="menuInitDate"
                                        :close-on-content-click="false"
                                        :nudge-right="40"
                                        transition="scale-transition"
                                        offset-y
                                        min-width="auto"
                                    >
                                        <template v-slot:activator="{ on, attrs }">
                                            <v-text-field
                                                v-model="classData.initDate"
                                                :label="lang.init_date"
                                                prepend-icon="mdi-calendar"
                                                readonly
                                                v-bind="attrs"
                                                v-on="on"
                                                dense
                                                outlined
                                            ></v-text-field>
                                        </template>
                                        <v-date-picker
                                            v-model="classData.initDate"
                                            @input="menuInitDate = false"
                                            locale="es-ES"
                                        ></v-date-picker>
                                    </v-menu>
                                </div>

                                <div class="col-12 col-md-6 px-3">
                                    <v-menu
                                        v-model="menuEndDate"
                                        :close-on-content-click="false"
                                        :nudge-right="40"
                                        transition="scale-transition"
                                        offset-y
                                        min-width="auto"
                                    >
                                        <template v-slot:activator="{ on, attrs }">
                                            <v-text-field
                                                v-model="classData.endDate"
                                                :label="lang.end_date"
                                                prepend-icon="mdi-calendar"
                                                readonly
                                                v-bind="attrs"
                                                v-on="on"
                                                dense
                                                outlined
                                            ></v-text-field>
                                        </template>
                                        <v-date-picker
                                            v-model="classData.endDate"
                                            @input="menuEndDate = false"
                                            locale="es-ES"
                                        ></v-date-picker>
                                    </v-menu>
                                </div>
                            </div>

                            <!-- Days Row -->
                            <div id="days-fieldset" class="row form-group py-2 mx-0 px-2">
                                <div class="col-12 pb-2">
                                    <label>{{lang.class_days}}</label>
                                </div>
                            
                                <div class="col-6 col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input v-model="classData.classDays.monday" type="checkbox" class="custom-control-input" id="customSwitchMonday" ref="switchMonday">
                                        <label class="custom-control-label" for="customSwitchMonday">{{lang.monday}}</label>
                                    </div>
                                </div>
                                
                                <div class="col-6 col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input v-model="classData.classDays.tuesday" type="checkbox" class="custom-control-input" id="customSwitchTuesday" ref="switchTuesday">
                                        <label class="custom-control-label" for="customSwitchTuesday">{{lang.tuesday}}</label>
                                    </div>
                                </div>

                                <div class="col-6 col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input v-model="classData.classDays.wednesday" type="checkbox" class="custom-control-input" id="customSwitchWednesday" ref="switchWednesday">
                                        <label class="custom-control-label" for="customSwitchWednesday">{{lang.wednesday}}</label>
                                    </div>
                                </div>

                                <div class="col-6 col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input v-model="classData.classDays.thursday" type="checkbox" class="custom-control-input" id="customSwitchThursday" ref="switchThursday">
                                        <label class="custom-control-label" for="customSwitchThursday">{{lang.thursday}}</label>
                                    </div>
                                </div>
                                
                                <div class="col-6 col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input v-model="classData.classDays.friday" type="checkbox" class="custom-control-input" id="customSwitchFriday" ref="switchFriday">
                                        <label class="custom-control-label" for="customSwitchFriday">{{lang.friday}}</label>
                                    </div>
                                </div>

                                <div class="col-6 col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input v-model="classData.classDays.saturday" type="checkbox" class="custom-control-input" id="customSwitchSaturday" ref="switchSaturday">
                                        <label class="custom-control-label" for="customSwitchSaturday">{{lang.saturday}}</label>
                                    </div>
                                </div>

                                <div class="col-6 col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input v-model="classData.classDays.sunday" type="checkbox" class="custom-control-input" id="customSwitchSunday" ref="switchSunday">
                                        <label class="custom-control-label" for="customSwitchSunday">{{lang.sunday}}</label>
                                    </div>
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
                        <input  ref="hiddenTeacherInput" style="visibility:hidden;">
                        <v-list dense two-line>
                            <v-list-item-group v-model="classData.teacherIndex">
                                <template v-for="teacher in teachers">
                                    <v-list-item color="success">
                                        <template v-slot:default="{ active }">
                                            <v-list-item-icon>
                                                <v-icon>mdi-school</v-icon>
                                            </v-list-item-icon>
                                            <v-list-item-content>
                                                <v-list-item-title>{{ teacher.fullname }}</v-list-item-title>
                                                <v-list-item-subtitle class="text-caption" v-text="teacher.email"></v-list-item-subtitle>
                                            </v-list-item-content>
                                    
                                            <v-list-item-action>
                                                <v-checkbox
                                                  :input-value="active"
                                                  color="success"
                                                ></v-checkbox>
                                            </v-list-item-action>
                                        </template>
                                    </v-list-item>
                                </template>
                            </v-list-item-group>
                        </v-list>
                    </form>
                        
                    <div class="d-flex card-footer bg-transparent mt-3 px-0">
                        <div class="spacer"></div>
                        <v-btn @click="returnToClassManagement" class="ma-2" small color="secondary">{{lang.cancel}}</v-btn>
                        <v-btn id="saveClassButton" :loading="savingClass" class="ma-2" small color="primary" @click="saveClass">{{lang.save}}</v-btn>
                    </div>
                </div>
            </div>
        </div>
        
        <errormodal :show="showErrorDialog" :message="errorMessage" @close="closeErrorDialog"/>
    </div>
    `,
    name: 'create-class',
    data() {
        return {
            menuInitDate: false,
            menuEndDate: false,
            classData: {
                name: undefined,
                type: undefined,
                classRoomIndex: undefined,
                learningPlanId: undefined,
                periodId: undefined,
                courseId: undefined,
                teacherIndex: undefined,
                initTime: undefined,
                endTime: undefined,
                initDate: new Date().toISOString().substr(0, 10), // Default to today
                endDate: new Date(new Date().setMonth(new Date().getMonth() + 2)).toISOString().substr(0, 10), // Default +2 months
                classDays: {
                    monday: false,
                    tuesday: false,
                    wednesday: false,
                    thursday: false,
                    friday: false,
                    saturday: false,
                    sunday: false
                }
            },
            periods: [],
            courses: [],
            teachers: [],
            savingClass: false,
            showErrorDialog: false,
            errorMessage: undefined,
            templateData
        }
    },
    methods: {
        async handleLearningPlanChange() {
            const learningPlanId = this.classData.learningPlanId
            if (!learningPlanId) {
                return;
            }
            try {

                let { data } = await window.axios.get(wsurl, { params: this.getLearningPlanPeriodsParameters })
                let { periods } = data
                periods = JSON.parse(periods)
                this.periods = periods.map(period => ({ label: period.name, value: period.id }))
                if (!learningPlanId) {
                    this.periods = [];
                    this.classData.teacherIndex = undefined;
                    this.teachers = [];
                }
                this.classData.periodId = undefined
                this.classData.courseId = undefined
                this.courses = [];
                this.getPotentialTeachers()
            }
            catch (error) {
                console.error(error)
            }
        },
        async handleLearningPlanPeriodChange() {
            if (!this.classData.periodId) {
                this.getPotentialTeachers()
                return;
            }
            try {
                let { data } = await window.axios.get(wsurl, { params: this.getLearningPlanPeriodCoursesParameters })
                let { courses } = data
                courses = JSON.parse(courses)
                this.classData.courseId = undefined
                this.courses = courses.map(course => ({ label: course.name, value: course.id }))
            }
            catch (error) {
                console.error(error)
            }
        },
        // Fetches a list of teachers based on specified parameters.
        async getPotentialTeachers() {
            if (!this.classData.learningPlanId) {
                return;
            }
            const selectedTeacherId = this.selectedClassTeacher?.id
            try {
                let { data } = await window.axios.get(wsurl, { params: this.getPotentialTeachersParameters })
                console.log('getPotentialTeachers response:', data);
                if (data && data.teachers) {
                    let { teachers } = data;
                    teachers = typeof teachers === 'string' ? JSON.parse(teachers) : teachers;
                    this.teachers = teachers.map(teacher => ({ email: teacher.email, fullname: teacher.fullname, id: teacher.id }))
                    this.classData.teacherIndex = selectedTeacherId ? this.teachers.findIndex(teacher => teacher.id === selectedTeacherId) : undefined
                } else {
                    console.warn('Response does not contain teachers property', data);
                    this.teachers = [];
                }
            }
            catch (error) {
                console.error('Error fetching teachers:', error)
            }
        },
        async saveClass() {

            if (!this.validateClassInputs()) {
                return;
            }
            this.savingClass = true;

            try {
                let { data } = await window.axios.get(wsurl, { params: this.saveClassParameters });
                let { status, message, exception } = data;
                if (status === -1) {
                    throw new Error(JSON.parse(message).join('\n'));
                }
                else if (exception) {
                    throw new Error(message);
                }
                this.savingClass = false
                this.returnToClassManagement()
            }
            catch (error) {
                console.error(error)
                this.errorMessage = error.message;
                this.showErrorDialog = true;
                this.savingClass = false;
            }
        },
        validateClassInputs() {
            this.$refs.classEndTime.setCustomValidity('');
            const valid = this.classInputs.every(input => {
                return input.reportValidity();
            });
            if (!valid) {
                return false
            }
            if (!this.validTimeRange) {
                this.$refs.classEndTime.setCustomValidity('La hora de finalización debe ser mayor a la hora de inicio.');
                this.$refs.classEndTime.reportValidity();
                return false
            }
            if (this.classDaysString === '0/0/0/0/0/0/0') {
                this.$refs.switchMonday.setCustomValidity('Se debe seleccionar al menos un día de clase.')
                this.$refs.switchMonday.reportValidity();
                return false
            }
            if (!this.selectedClassTeacher) {
                this.$refs.hiddenTeacherInput.setCustomValidity('Se debe seleccionar un instructor.')
                this.$refs.hiddenTeacherInput.reportValidity();
                return false
            }
            return true
        },
        closeErrorDialog() {
            this.errorMessage = undefined;
            this.showErrorDialog = false;
        },
        /**
         * Redirect to the specified URL when cancel action is triggered.
         */
        returnToClassManagement() {
            // Redirect the user to the '/local/grupomakro_core/pages/classmanagement.php' URL
            window.location = '/local/grupomakro_core/pages/classmanagement.php'
        },
    },
    computed: {
        classInputs() {
            return [
                this.$refs.className,
                this.$refs.classType,
                this.$refs.classRoom,
                this.$refs.classLearningPlan,
                this.$refs.classPeriod,
                this.$refs.classCourse,
                this.$refs.classInitTime,
                this.$refs.classEndTime
            ]
        },
        getLearningPlanPeriodsParameters() {
            return {
                ...wsDefaultParams,
                wsfunction: 'local_sc_learningplans_get_learning_plan_periods',
                learningPlanId: this.classData.learningPlanId
            }
        },
        getLearningPlanPeriodCoursesParameters() {
            return {
                ...wsDefaultParams,
                wsfunction: 'local_sc_learningplans_get_learning_plan_courses',
                learningPlanId: this.classData.learningPlanId,
                periodId: this.classData.periodId
            }
        },
        getPotentialTeachersParameters() {
            return {
                ...wsDefaultParams,
                wsfunction: 'local_grupomakro_get_potential_class_teachers',
                courseId: this.classData.courseId,
                initTime: this.classData.initTime,
                endTime: this.classData.endTime,
                classDays: this.classDaysString,
                learningPlanId: this.classData.learningPlanId,
                classId: this.classData.id,
            }
        },
        selectedClassTeacher() {
            return this.teachers[this.classData.teacherIndex]
        },
        showClassRoomSelector() {
            return this.classData.type === 0;
        },
        saveClassParameters() {
            const { name, type, learningPlanId, periodId, courseId, initTime, endTime } = this.classData
            return {
                ...wsDefaultParams,
                wsfunction: 'local_grupomakro_create_class',
                name,
                type,
                learningPlanId,
                periodId,
                courseId,
                instructorId: this.selectedClassTeacher?.id,
                initTime,
                endTime,
                classDays: this.classDaysString,
                classroomId: this.selectedClassRoom?.value,
                classroomCapacity: this.selectedClassRoom?.capacity
            }
        },
        validTimeRange() {
            return this.classData.initTime < this.classData.endTime
        },
        selectedClassRoom() {
            return this.templateData.classRooms[this.classData.classRoomIndex]
        },
        /**
         * A computed property that returns language-related data from the 'window.strings' object.
         * It allows access to language strings for localization purposes.
         *
         * @returns '{object}' - Language-related data.
         */
        lang() {
            return window.strings;
        },
        /**
         * Computed property that generates a string representation of selected days.
         */
        classDaysString() {
            const classDays = this.classData.classDays
            const days = [
                classDays.monday ? '1' : '0',
                classDays.tuesday ? '1' : '0',
                classDays.wednesday ? '1' : '0',
                classDays.thursday ? '1' : '0',
                classDays.friday ? '1' : '0',
                classDays.saturday ? '1' : '0',
                classDays.sunday ? '1' : '0',
            ];
            return days.join('/');
        },
    },
    watch: {
        classDaysString: 'getPotentialTeachers',
    },
})