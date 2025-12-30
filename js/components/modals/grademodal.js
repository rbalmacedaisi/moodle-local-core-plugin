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

                        <div class="grade-content">
                            <div v-for="(career, careerIndex) in dataStudent.carrers" :key="careerIndex" class="mb-6">
                                <div class="d-flex align-center mb-2 px-2 py-1 grey lighten-4 rounded">
                                    <v-icon small color="primary" class="mr-2">mdi-school</v-icon>
                                    <span class="font-weight-bold text-subtitle-1 primary--text">
                                        {{ career.career }}
                                    </span>
                                </div>
                                
                                <div v-if="!career.periods">
                                    <v-progress-linear indeterminate color="primary" class="mt-2"></v-progress-linear>
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
        };
    },
    props: {
        dataStudent: Object
    },
    created() {
        this.getpensum()
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
        async getpensum() {
            for (const element of this.dataStudent.carrers) {
                const data = await this.getcarrers(element.planid);
                // Data comes back grouped by period id in JSON string from PHP
                // But getcarrers (below) parses it. Let's make sure it groups by NAME for display.
                const groupedByPeriodName = {};

                // data is an object where keys are periodids
                Object.values(data).forEach(periodInfo => {
                    groupedByPeriodName[periodInfo.periodName] = periodInfo.courses;
                });

                this.$set(element, 'periods', groupedByPeriodName);
            }
            this.dialog = true;
        },
        async getcarrers(id) {
            try {
                const params = {
                    wstoken: this.token,
                    moodlewsrestformat: 'json',
                    wsfunction: 'local_grupomakro_get_student_learning_plan_pensum',
                    userId: this.dataStudent.id,
                    learningPlanId: id
                };

                const response = await window.axios.get(this.siteUrl, { params });
                const data = JSON.parse(response.data.pensum);
                return data; // Returns the grouped object
            } catch (error) {
                console.error(error);
                return {};
            }
        }
    },
    computed: {
        lang() { return window.strings },
        token() { return window.userToken; },
        siteUrl() { return window.location.origin + '/webservice/rest/server.php' }
    },
})