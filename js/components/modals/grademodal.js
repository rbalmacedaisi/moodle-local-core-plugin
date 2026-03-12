Vue.component('grademodal', {
    template: `
        <div>
            <v-dialog
              v-model="dialog"
              persistent
              max-width="800"
            >
                <v-card class="rounded-lg overflow-hidden">
                    <v-card-title class="headline primary white--text d-flex align-center py-3 px-4">
                        <span>{{ lang.grades }}</span>
                        <v-spacer></v-spacer>
                        <v-btn icon dark @click="close">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>

                    <v-card-text class="pa-4">
                        <div class="d-flex align-center mb-4">
                            <v-avatar color="primary lighten-4" size="48" class="mr-3">
                                <v-icon color="primary">mdi-account</v-icon>
                            </v-avatar>
                            <div>
                                <div class="text-h6 font-weight-bold">{{ studentName }}</div>
                                <div class="text-caption grey--text text--darken-1">{{ studentEmail }}</div>
                            </div>
                        </div>

                        <div v-if="classId && (loadingActivities || courseActivities.length > 0)" class="mb-6">
                            <div class="d-flex align-center mb-2 px-2 py-1 blue darken-4 rounded white--text">
                                <v-icon small color="white" class="mr-2">mdi-book-open-variant</v-icon>
                                <span class="font-weight-bold text-subtitle-1">
                                    Detalle del Curso Actual
                                </span>
                            </div>

                            <div v-if="loadingActivities" class="text-center py-4">
                                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                <div class="caption grey--text mt-2">Cargando actividades...</div>
                            </div>

                            <v-simple-table v-else dense class="elevation-1 rounded mb-4">
                                <template v-slot:default>
                                    <thead>
                                        <tr class="blue-grey lighten-5">
                                            <th class="text-left py-2" style="width: 70%">Actividad</th>
                                            <th class="text-center py-2">Estado</th>
                                            <th class="text-right py-2">Calificacion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(act, idx) in courseActivities" :key="idx">
                                            <td class="text-body-2 py-2">{{ act.name }}</td>
                                            <td class="text-center py-2">
                                                <v-chip x-small :color="act.completed ? 'success' : 'grey'" dark label>
                                                    {{ act.completed ? 'Completado' : 'Pendiente' }}
                                                </v-chip>
                                            </td>
                                            <td class="text-right font-weight-bold py-2" :class="getGradeColor(act.grade)">
                                                {{ act.grade }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </template>
                            </v-simple-table>
                        </div>

                        <div class="grade-content" v-if="!classId">
                            <div v-if="loadingPensum" class="text-center py-4">
                                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                <div class="caption grey--text mt-2">Cargando pensum...</div>
                            </div>

                            <div v-else-if="careersList.length === 0" class="text-center py-6 grey--text">
                                <v-icon large color="grey lighten-2">mdi-database-off</v-icon>
                                <div class="mt-2 text-body-2 font-italic">No se encontraron planes de estudio para este estudiante.</div>
                            </div>

                            <div v-for="(career, careerIndex) in careersList" :key="careerIndex" class="mb-6">
                                <div class="d-flex align-center mb-2 px-2 py-1 grey lighten-4 rounded">
                                    <v-icon small color="primary" class="mr-2">mdi-school</v-icon>
                                    <span class="font-weight-bold text-subtitle-1 primary--text">
                                        {{ career.career }}
                                    </span>
                                </div>

                                <div v-if="!career.periods">
                                    <v-progress-linear indeterminate color="primary" class="mt-2"></v-progress-linear>
                                </div>

                                <div v-else-if="Object.keys(career.periods).length === 0" class="text-center py-6 grey--text">
                                    <v-icon large color="grey lighten-2">mdi-database-off</v-icon>
                                    <div class="mt-2 text-body-2 font-italic">No se encontraron asignaturas asociadas a este plan de estudios.</div>
                                </div>

                                <div v-else v-for="(courses, periodName) in career.periods" :key="periodName" class="period-group mb-4 ml-2">
                                    <div class="period-header d-flex align-center mb-2">
                                        <div class="period-line border-left pl-3" style="border-left: 3px solid #1976D2 !important;">
                                            <span class="text-subtitle-2 font-weight-bold text-uppercase grey--text text--darken-2">
                                                {{ periodName }}
                                            </span>
                                        </div>
                                    </div>

                                    <v-simple-table dense class="elevation-0 transparent">
                                        <template v-slot:default>
                                            <thead>
                                                <tr>
                                                    <th class="text-left text-overline" style="width: 52%">Asignatura</th>
                                                    <th class="text-center text-overline">Estado</th>
                                                    <th class="text-right text-overline">Nota</th>
                                                    <th class="text-center text-overline">Inscribir</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(course, courseIndex) in courses" :key="courseIndex" class="course-row" @click="gradebook(course)" style="cursor: pointer;">
                                                    <td class="py-2">
                                                        <div class="text-body-2 font-weight-medium text-wrap pr-2" style="line-height: 1.2;">
                                                            {{ course.coursename }}
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <v-chip x-small :color="course.statusColor" dark label class="text-caption font-weight-bold">
                                                            {{ course.statusLabel }}
                                                        </v-chip>
                                                    </td>
                                                    <td class="text-right font-weight-bold" :class="getGradeColor(course.grade)">
                                                        {{ course.grade }}
                                                    </td>
                                                    <td class="text-center py-1">
                                                        <v-btn
                                                            x-small
                                                            color="primary"
                                                            :disabled="!canEnrollInCourse(course)"
                                                            @click.stop="openEnrollDialog(course)"
                                                        >
                                                            Inscribir
                                                        </v-btn>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </template>
                                    </v-simple-table>
                                </div>
                            </div>
                        </div>
                    </v-card-text>

                    <v-divider class="my-0"></v-divider>

                    <v-card-actions class="pa-3">
                      <v-spacer></v-spacer>
                      <v-btn color="primary" text font-weight-bold @click="close">
                        <v-icon left>mdi-check</v-icon>
                        {{ lang.close }}
                      </v-btn>
                    </v-card-actions>
                  </v-card>
            </v-dialog>

            <v-dialog v-model="enrollDialog" max-width="780">
                <v-card class="rounded-lg overflow-hidden">
                    <v-card-title class="headline primary white--text d-flex align-center py-3 px-4">
                        <span>Inscribir en curso activo</span>
                        <v-spacer></v-spacer>
                        <v-btn icon dark @click="closeEnrollDialog">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>

                    <v-card-text class="pa-4">
                        <div class="mb-3">
                            <div class="text-body-1 font-weight-bold">{{ selectedCourseName }}</div>
                            <div class="text-caption grey--text text--darken-1">Seleccione el curso activo en el que desea inscribir al estudiante.</div>
                        </div>

                        <div v-if="loadingEnrollClasses" class="text-center py-4">
                            <v-progress-circular indeterminate color="primary"></v-progress-circular>
                            <div class="caption grey--text mt-2">Cargando cursos activos...</div>
                        </div>

                        <v-alert v-else-if="enrollClassesError" type="error" dense outlined class="mb-0">
                            {{ enrollClassesError }}
                        </v-alert>

                        <v-alert v-else-if="enrollableClasses.length === 0" type="info" dense outlined class="mb-0">
                            No hay cursos activos disponibles para esta asignatura.
                        </v-alert>

                        <v-simple-table v-else dense class="elevation-1 rounded">
                            <template v-slot:default>
                                <thead>
                                    <tr class="blue-grey lighten-5">
                                        <th class="text-left py-2">Curso</th>
                                        <th class="text-left py-2">Docente</th>
                                        <th class="text-left py-2">Horario</th>
                                        <th class="text-center py-2">Cupo</th>
                                        <th class="text-center py-2">Estado</th>
                                        <th class="text-center py-2">Accion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in enrollableClasses" :key="item.id">
                                        <td class="py-2">
                                            <div class="text-body-2 font-weight-medium">{{ item.name }}</div>
                                            <div class="caption grey--text">{{ item.typelabel }}</div>
                                        </td>
                                        <td class="py-2">{{ item.instructorname || '--' }}</td>
                                        <td class="py-2">
                                            <div>{{ getClassDaysLabel(item.classdays) }}</div>
                                            <div class="caption grey--text">{{ item.inithourformatted || '--' }} - {{ item.endhourformatted || '--' }}</div>
                                            <div class="caption grey--text">{{ item.initdateformatted || '--' }} / {{ item.enddateformatted || '--' }}</div>
                                        </td>
                                        <td class="text-center py-2">
                                            <span :class="isOverCapacity(item) ? 'error--text font-weight-bold' : ''">
                                                {{ item.enrolled }} / {{ item.classroomcapacity || 0 }}
                                            </span>
                                        </td>
                                        <td class="text-center py-2">
                                            <v-chip
                                                x-small
                                                :color="item.alreadyenrolled ? 'info' : (isOverCapacity(item) ? 'warning' : 'success')"
                                                dark
                                                label
                                            >
                                                {{ item.alreadyenrolled ? 'Ya inscrito' : (isOverCapacity(item) ? 'Sobre cupo' : 'Disponible') }}
                                            </v-chip>
                                        </td>
                                        <td class="text-center py-2">
                                            <v-btn
                                                x-small
                                                color="primary"
                                                :loading="enrollingClassId === item.id"
                                                :disabled="item.alreadyenrolled || !!enrollingClassId"
                                                @click="enrollStudentInClass(item)"
                                            >
                                                Inscribir
                                            </v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </template>
                        </v-simple-table>
                    </v-card-text>

                    <v-divider></v-divider>

                    <v-card-actions class="pa-3">
                        <v-spacer></v-spacer>
                        <v-btn color="primary" text @click="closeEnrollDialog">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `,
    data() {
        return {
            dialog: false,
            courseActivities: [],
            loadingActivities: false,
            loadingPensum: false,
            enrollDialog: false,
            loadingEnrollClasses: false,
            enrollingClassId: null,
            enrollClasses: [],
            enrollClassesError: '',
            selectedCourse: null
        };
    },
    props: {
        dataStudent: Object,
        classId: [Number, String]
    },
    created() {
        this.dialog = true;
        if (this.classId) {
            this.fetchCourseActivities();
        } else {
            this.getpensum();
        }
    },
    methods: {
        getGradeColor(grade) {
            const val = parseFloat(grade);
            if (isNaN(val)) return 'grey--text';
            return val >= 70 ? 'success--text' : 'error--text';
        },
        gradebook(item) {
            const gradebookUrl = `/grade/report/grader/index.php?id=${item.courseid}`;
            window.location = gradebookUrl;
        },
        close() {
            this.enrollDialog = false;
            this.dialog = false;
            this.$emit('close-dialog');
        },
        async fetchCourseActivities() {
            this.loadingActivities = true;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action: 'local_grupomakro_get_student_course_pensum_activities',
                    sesskey: M.cfg.sesskey,
                    userId: this.dataStudent.id,
                    classId: this.classId
                };

                const response = await window.axios.get(url, { params });
                if (response.data && response.data.status === 'success' && response.data.data) {
                    const activitiesJson = response.data.data.activities;
                    this.courseActivities = typeof activitiesJson === 'string'
                        ? JSON.parse(activitiesJson)
                        : (activitiesJson || []);
                }
            } catch (error) {
                console.error('Error fetching course activities:', error);
            } finally {
                this.loadingActivities = false;
            }
        },
        async getpensum() {
            const careersList = this.careersList;
            this.loadingPensum = true;
            try {
                for (const element of careersList) {
                    this.$set(element, 'periods', null);
                    const data = await this.getcarrers(element.planid, 0);
                    const groupedByPeriodName = {};

                    if (data && typeof data === 'object') {
                        Object.values(data).forEach(periodInfo => {
                            if (periodInfo && periodInfo.periodName) {
                                groupedByPeriodName[periodInfo.periodName] = periodInfo.courses || [];
                            }
                        });
                    }

                    this.$set(element, 'periods', groupedByPeriodName);
                }
            } finally {
                this.loadingPensum = false;
            }
        },
        async getcarrers(id, attempt = 0) {
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');

                const params = {
                    action: 'local_grupomakro_get_student_learning_plan_pensum',
                    sesskey: M.cfg.sesskey,
                    userId: this.dataStudent.id,
                    learningPlanId: id
                };

                const response = await window.axios.get(url, { params });

                if (!response.data || response.data.status !== 'success' || !response.data.data) {
                    return {};
                }

                const result = response.data.data;
                const pensumStr = result.pensum;

                const data = typeof pensumStr === 'string'
                    ? JSON.parse(pensumStr)
                    : pensumStr;

                return data || {};
            } catch (error) {
                const statusCode = error && error.response ? Number(error.response.status || 0) : 0;
                if (statusCode === 503 && attempt < 1) {
                    await new Promise(resolve => setTimeout(resolve, 900));
                    return this.getcarrers(id, attempt + 1);
                }
                console.error('Error fetching pensum:', error);
                return {};
            }
        },
        hasActiveClasses(course) {
            return Number(course && course.activeclasscount ? course.activeclasscount : 0) > 0;
        },
        hasAllowedStatusForEnroll(course) {
            const statusLabel = String((course && course.statusLabel) ? course.statusLabel : '').trim().toLowerCase();
            return statusLabel === 'disponible' || statusLabel === 'no disponible' || statusLabel === 'reprobada';
        },
        canEnrollInCourse(course) {
            return this.hasActiveClasses(course) && this.hasAllowedStatusForEnroll(course);
        },
        async openEnrollDialog(course) {
            if (!this.canEnrollInCourse(course)) {
                return;
            }

            this.selectedCourse = course;
            this.enrollDialog = true;
            this.loadingEnrollClasses = true;
            this.enrollClasses = [];
            this.enrollClassesError = '';

            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action: 'local_grupomakro_get_active_classes_for_course',
                    sesskey: M.cfg.sesskey,
                    userId: this.dataStudent.id,
                    coreCourseId: Number(course.courseid || 0),
                    learningCourseId: Number(course.learningcourseid || 0),
                    learningPlanId: Number(course.learningplanid || 0),
                };

                const response = await window.axios.get(url, { params });
                const payload = response && response.data ? response.data : {};

                if (payload.status === 'success' && Array.isArray(payload.classes)) {
                    this.enrollClasses = payload.classes;
                } else {
                    this.enrollClassesError = payload.message || 'No se pudieron cargar los cursos activos.';
                }
            } catch (error) {
                console.error('Error loading active classes for enrollment:', error);
                this.enrollClassesError = 'Error consultando cursos activos.';
            } finally {
                this.loadingEnrollClasses = false;
            }
        },
        closeEnrollDialog() {
            this.enrollDialog = false;
            this.selectedCourse = null;
            this.enrollClasses = [];
            this.enrollClassesError = '';
            this.enrollingClassId = null;
        },
        async enrollStudentInClass(item) {
            if (!item || !item.id || this.enrollingClassId) {
                return;
            }

            this.enrollingClassId = item.id;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action: 'local_grupomakro_manual_enroll',
                    sesskey: M.cfg.sesskey,
                    classId: Number(item.id),
                    userId: Number(this.dataStudent.id),
                };

                const response = await window.axios.get(url, { params });
                const payload = response && response.data ? response.data : {};
                const result = (payload.status === 'success' && payload.data) ? payload.data : payload;

                if (result.status === 'ok' || result.status === 'warning') {
                    item.alreadyenrolled = true;
                    item.enrolled = Number(item.enrolled || 0) + (result.status === 'ok' ? 1 : 0);
                    this.showMessage(result.status === 'ok' ? 'success' : 'warning', result.message || 'Operacion finalizada.');
                } else {
                    this.showMessage('error', result.message || 'No se pudo inscribir al estudiante.');
                }
            } catch (error) {
                console.error('Error enrolling student in class:', error);
                this.showMessage('error', 'Error inscribiendo al estudiante.');
            } finally {
                this.enrollingClassId = null;
            }
        },
        showMessage(type, message) {
            if (window.Swal) {
                window.Swal.fire({
                    icon: type,
                    text: message,
                    confirmButtonText: 'Aceptar'
                });
                return;
            }
            window.alert(message);
        },
        getClassDaysLabel(days) {
            if (!days) {
                return '--';
            }
            const map = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
            const pieces = String(days).split('/');
            const labels = [];
            pieces.forEach((flag, idx) => {
                if (String(flag) === '1' && map[idx]) {
                    labels.push(map[idx]);
                }
            });
            return labels.length ? labels.join(', ') : '--';
        },
        isOverCapacity(item) {
            const cap = Number(item && item.classroomcapacity ? item.classroomcapacity : 0);
            if (cap <= 0) {
                return false;
            }
            return Number(item.enrolled || 0) > cap;
        }
    },
    computed: {
        lang() { return window.strings || {}; },
        token() { return window.userToken; },
        siteUrl() { return window.location.origin + '/local/grupomakro_core/ajax.php'; },
        careersList() {
            const list = this.dataStudent && (this.dataStudent.carrers || this.dataStudent.careers)
                ? (this.dataStudent.carrers || this.dataStudent.careers)
                : [];
            return Array.isArray(list) ? list : [];
        },
        studentName() {
            return (this.dataStudent && this.dataStudent.name) ? this.dataStudent.name : '--';
        },
        studentEmail() {
            return (this.dataStudent && this.dataStudent.email) ? this.dataStudent.email : '--';
        },
        selectedCourseName() {
            return this.selectedCourse && this.selectedCourse.coursename ? this.selectedCourse.coursename : '--';
        },
        enrollableClasses() {
            return Array.isArray(this.enrollClasses) ? this.enrollClasses : [];
        }
    },
});
