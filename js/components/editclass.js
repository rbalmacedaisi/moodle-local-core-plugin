const classData = window.templatedata;
const classTeacherId = classData.classTeachers.find(teacher => teacher.selected).id
const wstoken = window.userToken;
const wsurl = window.location.origin + '/webservice/rest/server.php';
const wsDefaultParams = {
    wstoken,
    moodlewsrestformat: 'json'
}
for (let day in classData.classDays) {
    classData.classDays[day] = classData.classDays[day] === "1" ? true : false;
}

window.Vue.component('editclass', {
    template: `
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12 px-md-0">
                <div class="card py-3 px-4">
                    <div v-if="reschedulingActivity" id="fields-groups" class="row pb-5 mt-2 mx-0">
                        <div class="col-12">
                            <h5 class="text-secondary mb-0">{{lang.rescheduling_activity}} {{reschedulingActivityTitle}}</h5>
                            <hr> </hr>
                        </div>
                        
                        <div id="newDate-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="newDate">{{lang.activity_new_date}}</label>
                            <input v-model="activityRescheduleData.activityProposedDate" type="date" class="form-control" id="newDate" ref="newDate" required>
                        </div>
                        
                        <div id="newStartTime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="newStartTime">{{lang.activity_start_time}}</label>
                            <input v-model="activityRescheduleData.activityProposedInitTime" type="time" class="form-control" id="newStartTime" ref="newInitTime" required>
                        </div>
                        
                        <div id="newEndTime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="newEndTime">{{lang.activity_end_time}}</label>
                            <input v-model="activityRescheduleData.activityProposedEndTime" type="time" class="form-control" id="newEndTime" ref="newEndTime" required>
                        </div>
                    </div>
                    
                    
                    <div v-if="!reschedulingActivity" id="fields-groups" class="row pb-5 mt-2 mx-0">
                        <div class="col-12">
                            <h5 class="text-secondary mb-0">{{ classData.name}}</h5>
                            <hr> </hr>
                        </div>    
                            
                        <div id="classname-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="className">{{ lang.class_name }}</label>
                            <input v-model="classData.name" ref="className" type="text" class="form-control" id="className" :placeholder="lang.class_name_placeholder" required>
                        </div>
                            
                        <div id="classtype-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="classType">{{ lang.class_type }}</label>
                            <select v-model="classData.type" ref="classType" id="classType" class="form-control" required>
                                <option :value="undefined">{{ lang.class_type_placeholder }}</option>
                                <option v-for="classType in classTypes" :value="classType.value">{{classType.label}}</option> 
                            </select>
                        </div>
                            
                        <div id="learning-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="classLearningPlan">{{ lang.class_learning_plan }}</label>
                            <select v-model="classData.learningPlanId" ref="classLearningPlan" id="classLearningPlan" class="form-control" required @change="handleLearningPlanChange">
                                <option :value="undefined">{{ lang.class_learningplan_placeholder }}</option>
                                <option v-for="learningPlan in learningPlans" :value="learningPlan.value">{{learningPlan.label}}</option>
                            </select>
                        </div>
                            
                        <div id="period-fieldset" class="col-sm-12 col-md-6 py-2">
                            <label class="w-100" for="classPeriod">{{ lang.class_period }}</label>
                            <select v-model="classData.periodId" ref="classPeriod" id="classPeriod" class="form-control" required @change="handleLearningPlanPeriodChange">
                               <option :value="undefined">{{ lang.class_period_placeholder }}</option>
                               <option v-for="period in periods" :value="period.value">{{period.label}}</option>
                            </select>
                        </div>
                            
                        <div id="courses-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="classCourse">{{ lang.class_course }}</label>
                            <select v-model="classData.courseId" ref="classCourse" id="classCourse" class="form-control" required @change="getPotentialTeachers">
                                <option :value="undefined">{{ lang.class_course_placeholder }}</option>
                                <option v-for="course in courses" :value="course.value">{{course.label}}</option>
                            </select>
                        </div>
                        
                        <div class="col-sm-12 pb-0">
                            <v-divider></v-divider>
                            <h6 class="mt-6">{{lang.class_date_time}}</h6>
                        </div>

                         <!-- Dates Row -->
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
                            
                        <div id="starttime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="classInitTime">{{ lang.class_start_time }}</label>
                            <input v-model="classData.initTime" ref="classInitTime" type="time" class="form-control" id="classInitTime" @change="getPotentialTeachers" required>
                        </div>
                            
                        <div id="endtime-fieldset" class="col-sm-12 col-md-6 form-group py-2">
                            <label class="w-100" for="classEndTime">{{ lang.class_end_time }}</label>
                            <input v-model="classData.endTime" ref="classEndTime" type="time" class="form-control" id="classEndTime" @change="getPotentialTeachers" required>
                        </div>
                            
                        <div id="starttime-fieldset" class="row form-group py-2 mx-0 px-2">
                            <div class="col-12">
                                <label>{{ lang.class_days}}</label>
                            </div>
                        
                            <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                <input v-model="classData.classDays.monday" type="checkbox" class="custom-control-input" id="customSwitchMonday" ref="switchMonday">
                                <label class="custom-control-label" for="customSwitchMonday">{{lang.monday}}</label>
                            </div>
                            
                            <div class="custom-control custom-switch col-6 col-sm-4">
                                <input v-model="classData.classDays.tuesday" type="checkbox" class="custom-control-input" id="customSwitchTuesday" ref="switchTuesday">
                                <label class="custom-control-label" for="customSwitchTuesday">{{lang.tuesday}}</label>
                            </div>
                            <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                <input v-model="classData.classDays.wednesday" type="checkbox" class="custom-control-input" id="customSwitchWednesday" ref="switchWednesday">
                                <label class="custom-control-label" for="customSwitchWednesday">{{lang.wednesday}}</label>
                            </div>
                            <div class="custom-control custom-switch col-6 col-sm-4">
                                <input v-model="classData.classDays.thursday" type="checkbox" class="custom-control-input" id="customSwitchThursday" ref="switchThursday">
                                <label class="custom-control-label" for="customSwitchThursday">{{lang.thursday}}</label>
                            </div>
                            <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                <input v-model="classData.classDays.friday" type="checkbox" class="custom-control-input" id="customSwitchFriday" ref="switchFriday">
                                <label class="custom-control-label" for="customSwitchFriday">{{lang.friday}}</label>
                            </div>
                            <div class="custom-control custom-switch col-6 col-sm-4">
                                <input v-model="classData.classDays.saturday" type="checkbox" class="custom-control-input" id="customSwitchSaturday" ref="switchSaturday">
                                <label class="custom-control-label" for="customSwitchSaturday">{{lang.saturday}}</label>
                            </div>
                            <div class="custom-control custom-switch col-6 col-sm-4 ml-11">
                                <input v-model="classData.classDays.sunday" type="checkbox" class="custom-control-input" id="customSwitchSunday" ref="switchSunday">
                                <label class="custom-control-label" for="customSwitchSunday">{{lang.sunday}}</label>
                            </div>
                        </div>
                    </div>
                        
                        
                            
                    <div v-if="!reschedulingActivity">
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
                                <template v-for="(item, index) in teachers">
                                    <v-list-item color="success">
                                        <template v-slot:default="{ active }">
                                            <v-list-item-icon>
                                                <v-icon>mdi-school</v-icon>
                                            </v-list-item-icon>
                                            <v-list-item-content>
                                                <v-list-item-title>{{ item.fullname }} <span v-if="item.id === classTeacherId">(Actual)</span></v-list-item-title>
                                                <v-list-item-subtitle class="text-caption" v-text="item.email"></v-list-item-subtitle>
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
                    </div>
                    
                    <div class="d-flex card-footer bg-transparent px-0">
                        <div class="spacer"></div>
                        <v-btn @click="returnToLastPage" :disabled="savingClass || checkingRescheduling" class="ma-2" small color="secondary">{{lang.cancel}}</v-btn>
                        <v-btn @click="handleActionButtonClick" :loading="savingClass || checkingRescheduling" id="saveClassButton" class="ma-2" small color="primary">{{buttonLabel}}</v-btn>
                    </div>
                    
                </div>
            </div>
            
        </div>
        <errormodal :show="showErrorDialog" :message="errorMessage" @close="closeErrorDialog"/>
        <reschedulemodal :loading="rescheduling" :show="showRescheduleDialog" :message="rescheduleMessage" @close="closeRescheduleDialog" @confirm="rescheduleActivity"/>
    </div>
    `,
    props: {},
    data() {
        return {
            showErrorDialog: false,
            showRescheduleDialog: false,
            menuInitDate: false,
            menuEndDate: false,
            activityRescheduleData: {
                activityInitDate: undefined,
                activityInitTime: undefined,
                activityEndTime: undefined,
                activityProposedDate: undefined,
                activityProposedInitTime: undefined,
                activityProposedEndTime: undefined,
                moduleId: undefined,
                sessionId: undefined
            },
            classData: {
                id: undefined,
                name: undefined,
                type: undefined,
                learningPlanId: undefined,
                periodId: undefined,
                courseId: undefined,
                teacherIndex: undefined,
                initDate: undefined,
                endDate: undefined,
                initTime: undefined,
                endTime: undefined,
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
            filledInputs: false,
            classTypes: [],
            learningPlans: [],
            periods: [],
            courses: [],
            teachers: [],
            classTeacherId,
            errorMessage: undefined,
            rescheduleMessage: undefined,
            savingClass: false,
            rescheduling: false,
            checkingRescheduling: false
        }
    },
    created() {
        this.fillInputs()
    },
    methods: {
        returnToLastPage() {
            window.location = this.reschedulingActivity ? '/local/grupomakro_core/pages/schedules.php' : '/local/grupomakro_core/pages/classmanagement.php';
            this.savingClass = false;
            this.rescheduling = false;
            this.checkingRescheduling = false;
        },
        fillInputs() {

            this.classTeacherId = classTeacherId;
            this.classData.id = classData.classId;
            this.classData.name = classData.className;

            this.classTypes = classData.classTypes.options;
            this.classData.type = classData.classTypes.selected;

            this.learningPlans = classData.classLearningPlans.options;
            this.classData.learningPlanId = classData.classLearningPlans.selected;

            this.periods = classData.classPeriods.options;
            this.classData.periodId = classData.classPeriods.selected;

            this.courses = classData.classCourses.options;
            this.classData.courseId = classData.classCourses.selected;

            this.classData.initTime = classData.initTime;
            this.classData.endTime = classData.endTime;
            this.classData.initDate = classData.initDate;
            this.classData.endDate = classData.endDate;

            this.classData.classDays = classData.classDays;

            this.teachers = classData.classTeachers;
            this.classData.teacherIndex = this.teachers.findIndex(teacher => teacher.id === classTeacherId)

            if (this.reschedulingActivity) {
                this.activityRescheduleData.activityEndTime = classData.activityEndTime
                this.activityRescheduleData.activityInitDate = classData.activityInitDate
                this.activityRescheduleData.activityInitTime = classData.activityInitTime
                this.activityRescheduleData.activityProposedDate = classData.activityProposedDate
                this.activityRescheduleData.activityProposedEndTime = classData.activityProposedEndTime
                this.activityRescheduleData.activityProposedInitTime = classData.activityProposedInitTime
                this.activityRescheduleData.moduleId = classData.moduleId
                this.activityRescheduleData.sessionId = classData.sessionId
            }

            setTimeout(() => {
                this.filledInputs = true;
            }, 1000)
        },
        async handleLearningPlanChange() {
            if (!this.classData.learningPlanId || !this.filledInputs) {
                return;
            }
            try {
                let { data } = await window.axios.get(wsurl, { params: this.getLearningPlanPeriodsParameters })
                let { periods } = data
                periods = JSON.parse(periods)
                this.periods = periods.map(period => ({ label: period.name, value: period.id }))
            }
            catch (error) {
                console.error(error)
            }
        },
        async handleLearningPlanPeriodChange() {
            if (!this.classData.periodId || !this.filledInputs) {
                return;
            }
            try {
                let { data } = await window.axios.get(wsurl, { params: this.getLearningPlanPeriodCoursesParameters })
                let { courses } = data
                courses = JSON.parse(courses)
                this.courses = courses.map(course => ({ label: course.name, value: course.id }))
            }
            catch (error) {
                console.error(error)
            }
        },
        async getPotentialTeachers() {
            if (!this.classData.learningPlanId || !this.filledInputs) {
                return;
            }
            const selectedTeacherId = this.selectedClassTeacher?.id
            try {
                let { data } = await window.axios.get(wsurl, { params: this.getPotentialTeachersParameters })
                let { teachers } = data;
                teachers = JSON.parse(teachers)
                this.teachers = teachers.map(teacher => ({ email: teacher.email, fullname: teacher.fullname, id: teacher.id }))
                this.classData.teacherIndex = selectedTeacherId ? this.teachers.findIndex(teacher => teacher.id === selectedTeacherId) : undefined
            }
            catch (error) {
                console.error(error)
            }
        },
        handleActionButtonClick() {
            if (this.reschedulingActivity) {
                this.checkRescheduleAvailability();
                return
            }
            this.saveClass();
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

                this.returnToLastPage()
            }
            catch (error) {
                console.error(error)
                this.errorMessage = error.message;
                this.showErrorDialog = true;
                this.savingClass = false;
            }
        },
        validateRescheduleInputs() {
            this.$refs.newEndTime.setCustomValidity('');

            const valid = this.rescheduleInputs.every(input => {
                return input.reportValidity();
            });
            if (!valid) {
                return false
            }

            if (!this.validRescheduleTimeRange) {
                this.$refs.newEndTime.setCustomValidity('La hora de finalización debe ser mayor a la hora de inicio.');
                this.$refs.newEndTime.reportValidity();
                return false
            }
            return true
        },
        async checkRescheduleAvailability() {

            if (!this.validateRescheduleInputs()) {
                return;
            }
            this.checkingRescheduling = true;
            try {
                let { data } = await window.axios.get(wsurl, { params: this.checkRescheduleActivityParameters });
                this.checkingRescheduling = false;
                let { status, message, exception } = data
                if (status === -1) {
                    throw new Error(JSON.parse(message));
                }
                else if (exception) {
                    throw new Error(message);
                }
                this.rescheduleMessage = JSON.parse(message).join('\n');
                this.showRescheduleDialog = true;
            }
            catch (error) {
                console.error(error)
                this.errorMessage = error.message;
                this.showErrorDialog = true;
                this.checkingRescheduling = false;
            }
        },
        async rescheduleActivity() {
            if (!this.validateRescheduleInputs()) {
                return;
            }
            this.rescheduling = true;
            try {
                let { data } = await window.axios.get(wsurl, { params: this.rescheduleActivityParameters });
                let { status, message, exception } = data
                if (status === -1) {
                    throw new Error(JSON.parse(message));
                }
                else if (exception) {
                    throw new Error(message);
                }
                this.returnToLastPage()
            }
            catch (error) {
                console.error(error)
                this.rescheduling = false;
                this.showRescheduleDialog = false;
                this.errorMessage = error.message;
                this.showErrorDialog = true;
            }

        },
        closeErrorDialog() {
            this.errorMessage = undefined;
            this.showErrorDialog = false;
        },
        closeRescheduleDialog() {
            this.rescheduleMessage = undefined;
            this.showRescheduleDialog = false;
        }
    },
    computed: {
        classInputs() {
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
        rescheduleInputs() {
            return [
                this.$refs.newDate,
                this.$refs.newInitTime,
                this.$refs.newEndTime,
            ]
        },
        validTimeRange() {
            return this.classData.initTime < this.classData.endTime
        },
        validRescheduleTimeRange() {
            return this.activityRescheduleData.activityProposedInitTime < this.activityRescheduleData.activityProposedEndTime
        },
        selectedClassTeacher() {
            return this.teachers[this.classData.teacherIndex]
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
        checkRescheduleActivityParameters() {
            return {
                ...wsDefaultParams,
                wsfunction: 'local_grupomakro_check_reschedule_conflicts',
                classId: this.classData.id,
                date: this.activityRescheduleData.activityProposedDate,
                initTime: this.activityRescheduleData.activityProposedInitTime,
                endTime: this.activityRescheduleData.activityProposedEndTime
            }
        },
        rescheduleActivityParameters() {
            return {
                ...wsDefaultParams,
                wsfunction: 'local_grupomakro_reschedule_activity',
                classId: this.classData.id,
                moduleId: this.activityRescheduleData.moduleId,
                date: this.activityRescheduleData.activityProposedDate,
                initTime: this.activityRescheduleData.activityProposedInitTime,
                endTime: this.activityRescheduleData.activityProposedEndTime,
                sessionId: this.activityRescheduleData.sessionId
            }
        },
        saveClassParameters() {
            const { id, name, type, learningPlanId, periodId, courseId, initTime, endTime } = this.classData
            return {
                ...wsDefaultParams,
                wsfunction: 'local_grupomakro_update_class',
                classId: id,
                name,
                type,
                learningPlanId,
                periodId,
                courseId,
                instructorId: this.selectedClassTeacher.id,
                initTime,
                endTime,
                initDate: this.classData.initDate,
                endDate: this.classData.endDate,
                classDays: this.classDaysString,
            }
        },
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
        reschedulingActivity() {
            return classData.reschedulingActivity;
        },
        reschedulingActivityTitle() {
            return `${this.classData.name} (${this.activityRescheduleData.activityInitDate} ${this.activityRescheduleData.activityInitTime}-${this.activityRescheduleData.activityEndTime})`
        },
        buttonLabel() {
            return this.reschedulingActivity ? this.lang.reschedule : this.lang.save
        },
        lang() {
            return window.strings;
        }
    },
    watch: {
        classDaysString: 'getPotentialTeachers',
        'classData.learningPlanId': function handler(newVal, oldVal) {
            if (!this.filledInputs) {
                return;
            }
            if (!newVal) {
                this.periods = [];
                this.classData.teacherIndex = undefined;
                this.teachers = [];
            }
            this.classData.periodId = ""
            this.classData.courseId = ""
            if (!oldVal || (newVal && oldVal)) {
                this.getPotentialTeachers()
            }
        },
        'classData.periodId': function handler(newVal) {
            if (!this.filledInputs) {
                return;
            }
            if (!newVal) {
                this.courses = [];
                this.getPotentialTeachers()
            }
            this.classData.courseId = ""
        }
    },
})