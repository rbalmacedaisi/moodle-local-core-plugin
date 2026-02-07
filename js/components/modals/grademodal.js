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
                                <div class="text-h6 font-weight-bold">{{ dataStudent.name }}</div>
                                <div class="text-caption grey--text text--darken-1">{{ dataStudent.email }}</div>
                            </div>
                        </div>

                        <!-- NEW: Detailed Course Activities (Contextual) -->
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
                                            <th class="text-right py-2">Calificación</th>
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

                        <div class="grade-content">
                            <div class="d-flex align-center mb-4 px-2 py-1 grey darken-3 rounded white--text" v-if="classId">
                                <v-icon small color="white" class="mr-2">mdi-history</v-icon>
                                <span class="font-weight-bold text-subtitle-2">Historial Académico General</span>
                            </div>

                            <div v-for="(career, careerIndex) in (dataStudent.carrers || dataStudent.careers)" :key="careerIndex" class="mb-6">
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
                                                    <th class="text-left text-overline" style="width: 70%">Asignatura</th>
                                                    <th class="text-center text-overline">Estado</th>
                                                    <th class="text-right text-overline">Nota</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(course, courseIndex) in courses" :key="courseIndex" 
                                                    class="course-row" @click="gradebook(course)" style="cursor: pointer;">
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
        </div>
    `,
    data() {
        return {
            dialog: false,
            courseActivities: [],
            loadingActivities: false
        };
    },
    props: {
        dataStudent: Object,
        classId: [Number, String]
    },
    created() {
        this.getpensum();
        if (this.classId) {
            this.fetchCourseActivities();
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
            this.dialog = false
            this.$emit('close-dialog')
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
            const careersList = this.dataStudent.carrers || this.dataStudent.careers || [];
            for (const element of careersList) {
                const data = await this.getcarrers(element.planid);
                const groupedByPeriodName = {};

                if (data && typeof data === 'object') {
                    Object.values(data).forEach(periodInfo => {
                        groupedByPeriodName[periodInfo.periodName] = periodInfo.courses;
                    });
                }

                this.$set(element, 'periods', groupedByPeriodName);
            }
            this.dialog = true;
        },
        async getcarrers(id) {
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
                console.error('Error fetching pensum:', error);
                return {};
            }
        }
    },
    computed: {
        lang() { return window.strings || {} },
        token() { return window.userToken; },
        siteUrl() { return window.location.origin + '/local/grupomakro_core/ajax.php' }
    },
})
