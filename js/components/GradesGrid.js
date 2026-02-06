Vue.component('grades-grid', {
    template: `
        <v-card flat class="gradebook-card rounded-lg border">
            <style>
                .gradebook-card .grade-container {
                    overflow: auto;
                    max-height: calc(100vh - 250px);
                    border-radius: 8px;
                }
                .gradebook-card table {
                    border-spacing: 0;
                    width: 100%;
                }
                .gradebook-card th, .gradebook-card td {
                    padding: 8px 12px;
                    border-bottom: 1px solid rgba(0,0,0,0.08);
                    border-right: 1px solid rgba(0,0,0,0.05);
                    white-space: nowrap;
                }
                /* Sticky Column: Student Info */
                .gradebook-card .sticky-col {
                    position: sticky;
                    left: 0;
                    z-index: 10;
                    background: white;
                    min-width: 250px;
                    box-shadow: 2px 0 5px rgba(0,0,0,0.05);
                }
                .theme--dark.gradebook-card .sticky-col {
                    background: #1e1e1e;
                }
                .gradebook-card thead th.sticky-col {
                    z-index: 20;
                }
                /* Sticky Headers */
                .gradebook-card thead th {
                    position: sticky;
                    top: 0;
                    z-index: 5;
                    background: #f8f9fa;
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: rgba(0,0,0,0.6);
                    height: 60px;
                }
                .theme--dark.gradebook-card thead th {
                    background: #2c2c2c;
                    color: rgba(255,255,255,0.7);
                }
                /* Column Specifics */
                .grade-header {
                    min-width: 110px;
                    text-align: center;
                }
                .grade-cell {
                    text-align: center;
                    font-size: 0.9rem;
                }
                .grade-total {
                    background: #f1f8ff !important;
                    font-weight: bold;
                }
                .theme--dark.gradebook-card .grade-total {
                    background: rgba(255,255,255,0.05) !important;
                }
                .grade-course-total {
                    background: #e7f3ff !important;
                    font-weight: 900 !important;
                    color: #1976d2 !important;
                    border-left: 2px solid #1976d2;
                }
                .gradebook-card tbody tr:hover td {
                    background-color: rgba(0,0,0,0.02);
                }
                .gradebook-card tbody tr:hover td.sticky-col {
                    background-color: #fcfcfc;
                }
                .theme--dark.gradebook-card tbody tr:hover td.sticky-col {
                    background-color: #252525;
                }
            </style>

            <v-card-title class="d-flex align-center py-2 px-4">
                <div class="text-h6 font-weight-bold">
                    <v-icon left>mdi-table-edit</v-icon>
                    Libro de Calificaciones
                </div>
                <v-spacer></v-spacer>
                <v-btn icon small @click="fetchGrades" :loading="loading" class="mr-2">
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
                <v-btn color="primary" small class="rounded-lg" elevation="0" @click="showGradebookManager = true">
                    <v-icon left small>mdi-cog</v-icon>
                    Configurar
                </v-btn>
            </v-card-title>
            
            <v-divider></v-divider>

            <v-card-text class="pa-0">
                <div v-if="loading && students.length === 0" class="text-center pa-10">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <p class="mt-2 grey--text">Cargando registros del curso...</p>
                </div>
                
                <div v-else-if="error" class="red--text text-center pa-10">
                    <v-icon color="red" size="48">mdi-alert-circle-outline</v-icon>
                    <p class="mt-2 text-body-1">{{ error }}</p>
                    <v-btn depressed color="primary" @click="fetchGrades">Reintentar</v-btn>
                </div>

                <div v-else class="grade-container">
                    <table>
                        <thead>
                            <tr>
                                <th class="sticky-col text-left">Estudiante</th>
                                <th v-for="col in columns" :key="col.id" 
                                    class="grade-header"
                                    :class="{'grade-total': col.is_total, 'grade-course-total': col.itemtype === 'course'}">
                                    <div class="truncate" style="max-width: 150px;" :title="col.title">
                                        {{ col.title }}
                                    </div>
                                    <div class="caption font-weight-regular opacity-70">
                                        Max: {{ col.max_grade }}
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="student in students" :key="student.id">
                                <td class="sticky-col">
                                    <div class="d-flex align-center">
                                        <v-avatar size="30" color="primary lighten-4" class="mr-3">
                                            <span class="primary--text font-weight-bold text-caption">{{ student.fullname.charAt(0) }}</span>
                                        </v-avatar>
                                        <div style="line-height: 1.2">
                                            <div class="text-body-2 font-weight-medium">{{ student.fullname }}</div>
                                            <div class="text-caption grey--text">{{ student.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td v-for="col in columns" :key="col.id" 
                                    class="grade-cell"
                                    :class="{
                                        'grade-total': col.is_total, 
                                        'grade-course-total': col.itemtype === 'course',
                                        'red--text font-weight-bold': isFailing(student.grades[col.id], col.max_grade)
                                    }">
                                    {{ formatGrade(student.grades[col.id]) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </v-card-text>
            
            <!-- Gradebook Manager Modal -->
            <gradebook-manager
                v-if="showGradebookManager"
                v-model="showGradebookManager"
                :class-id="classId"
                @closed="fetchGrades"
            ></gradebook-manager>
        </v-card>
    `,
    props: {
        classId: {
            type: [Number, String],
            required: true
        }
    },
    data() {
        return {
            loading: false,
            error: null,
            students: [],
            columns: [],
            showGradebookManager: false
        };
    },
    watch: {
        classId: {
            immediate: true,
            handler(newVal) {
                if (newVal) {
                    this.fetchGrades();
                }
            }
        }
    },
    methods: {
        async fetchGrades() {
            this.loading = true;
            this.error = null;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_class_grades',
                    args: {
                        classid: this.classId
                    },
                    sesskey: window.Y.config.sesskey
                });

                if (response.data && response.data.status === 'success') {
                    this.columns = response.data.data.columns;
                    this.students = response.data.data.students;
                } else {
                    throw new Error(response.data.message || 'Error al cargar calificaciones');
                }
            } catch (err) {
                console.error(err);
                this.error = 'No se pudieron cargar las notas correctamente.';
            } finally {
                this.loading = false;
            }
        },
        formatGrade(grade) {
            if (grade === '-' || grade === null || grade === undefined) return '-';
            const val = parseFloat(grade);
            if (isNaN(val)) return grade;
            return val % 1 === 0 ? val.toString() : val.toFixed(1);
        },
        isFailing(grade, maxGrade) {
            if (grade === '-' || grade === null || grade === undefined) return false;
            const val = parseFloat(grade);
            const max = parseFloat(maxGrade) || 100;
            // 71% threshold (standard for many institutions)
            return (val < (max * 0.71));
        }
    }
});
