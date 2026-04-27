Vue.component('grades-grid', {
    template: `
        <v-card flat class="gradebook-card rounded-lg border">
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

                <div v-else class="grade-container" style="overflow-x: auto !important; overflow-y: auto !important; width: 100% !important; display: block !important;">
                    <table :style="tableStyles">
                        <thead>
                            <tr>
                                <th class="sticky-col text-left">Estudiante</th>
                                <th v-for="col in columns" :key="col.id" 
                                    class="grade-header"
                                    :class="{'grade-total': col.is_total, 'grade-course-total': col.itemtype === 'course'}"
                                    :style="{ width: col.itemtype === 'course' ? '160px' : '140px' }">
                                    <div style="width: 100%;">
                                        <div class="font-weight-bold" :title="col.title">
                                            {{ col.title }}
                                        </div>
                                        <div class="caption font-weight-regular opacity-70 mt-1">
                                            ({{ col.max_grade }})
                                        </div>
                                        <div v-if="!col.is_total && col.weight_pct > 0" class="caption blue--text font-weight-medium mt-1">
                                            {{ col.weight_pct }}%
                                        </div>
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
                                        'red--text font-weight-bold': !isEditing(student.id, col.id) && isFailing(col.is_total ? weightedTotals[student.id] : student.grades[col.id], col.max_grade),
                                        'grade-editable': isManualEditable(student, col)
                                    }"
                                    @click="isManualEditable(student, col) ? startEdit(student, col) : null">

                                    <!-- Editing: inline number input -->
                                    <template v-if="isEditing(student.id, col.id)">
                                        <input
                                            v-focus
                                            class="grade-inline-input"
                                            type="number"
                                            :min="0"
                                            :max="col.max_grade"
                                            :step="0.1"
                                            v-model.number="editingValue"
                                            @keyup.enter="commitEdit(student, col)"
                                            @keyup.esc="cancelEdit"
                                            @blur="commitEdit(student, col)"
                                            @click.stop
                                        />
                                    </template>

                                    <!-- Saving: spinner -->
                                    <template v-else-if="isSaving(student.id, col.id)">
                                        <v-progress-circular indeterminate size="16" width="2" color="primary"></v-progress-circular>
                                    </template>

                                    <!-- Normal display -->
                                    <template v-else-if="col.is_total">
                                        {{ weightedTotals[student.id] !== undefined ? formatGrade(weightedTotals[student.id]) : '-' }}
                                    </template>
                                    <template v-else>
                                        {{ formatGrade(student.grades[col.id]) }}
                                        <v-icon v-if="isManualEditable(student, col)" x-small class="grade-edit-icon ml-1" color="grey lighten-1">mdi-pencil</v-icon>
                                    </template>
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
            showGradebookManager: false,
            editingCell: null,   // { studentId, colId }
            editingValue: '',
            savingCell: null,    // { studentId, colId }
        };
    },
    directives: {
        focus: {
            inserted(el) { el.focus(); el.select(); }
        }
    },
    computed: {
        tableStyles() {
            // Calculated width to force horizontal scroll
            let totalWidth = 250; // Student column
            this.columns.forEach(col => {
                totalWidth += (col.itemtype === 'course' ? 160 : 140);
            });
            return {
                width: totalWidth + 'px',
                minWidth: '100%'
            };
        },
        // Compute weighted total per student based on ponderaciones.
        // Missing grades count as 0 (unlike Moodle which may exclude them and renormalize).
        weightedTotals() {
            const gradeableCols = this.columns.filter(c => !c.is_total && c.weight_pct > 0);
            const totals = {};
            this.students.forEach(student => {
                let sum = 0;
                gradeableCols.forEach(col => {
                    const raw = student.grades[col.id];
                    const grade = (raw === '-' || raw === null || raw === undefined) ? 0 : parseFloat(raw);
                    const max   = parseFloat(col.max_grade) || 100;
                    sum += (grade / max) * col.weight_pct;
                });
                totals[student.id] = Math.round(sum * 10) / 10;
            });
            return totals;
        }
    },
    created() {
        this.injectStyles();
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
        injectStyles() {
            const styleId = 'grades-grid-styles';
            if (document.getElementById(styleId)) return;

            const style = document.createElement('style');
            style.id = styleId;
            style.textContent = `
                .gradebook-card .grade-container {
                    overflow-x: auto !important;
                    overflow-y: auto;
                    max-height: calc(100vh - 250px);
                    border-radius: 8px;
                    border: 1px solid rgba(0,0,0,0.12);
                    background: white;
                    width: 100% !important;
                    display: block !important;
                    position: relative;
                }
                .theme--dark.gradebook-card .grade-container {
                    background: #1e1e1e;
                }
                .gradebook-card table {
                    border-spacing: 0;
                    border-collapse: separate;
                    table-layout: fixed;
                }
                .gradebook-card th, .gradebook-card td {
                    padding: 12px 16px;
                    border-bottom: 1px solid rgba(0,0,0,0.08);
                    border-right: 1px solid rgba(0,0,0,0.08);
                    box-sizing: border-box;
                    word-wrap: break-word;
                    overflow: hidden;
                }
                .gradebook-card .sticky-col {
                    position: sticky;
                    left: 0;
                    z-index: 10;
                    background: white;
                    width: 250px;
                    min-width: 250px;
                    max-width: 250px;
                    box-shadow: 2px 0 5px rgba(0,0,0,0.05);
                }
                .theme--dark.gradebook-card .sticky-col {
                    background: #1e1e1e;
                }
                .gradebook-card thead th.sticky-col {
                    z-index: 20;
                }
                .gradebook-card thead th {
                    position: sticky;
                    top: 0;
                    z-index: 5;
                    background: #f8f9fa;
                    font-size: 0.72rem;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: rgba(0,0,0,0.73);
                    vertical-align: top;
                    padding: 15px 10px;
                    white-space: normal; 
                    line-height: 1.3;
                    border-top: 1px solid rgba(0,0,0,0.05);
                }
                .theme--dark.gradebook-card thead th {
                    background: #2c2c2c;
                    color: rgba(255,255,255,0.85);
                }
                .grade-header {
                    width: 140px;
                    min-width: 140px;
                    max-width: 140px;
                    text-align: center;
                }
                .grade-cell {
                    text-align: center;
                    font-size: 0.95rem;
                    white-space: nowrap;
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
                    width: 160px;
                    min-width: 160px;
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
                .grade-editable {
                    cursor: pointer;
                    position: relative;
                }
                .grade-editable:hover {
                    background-color: #e8f0fe !important;
                }
                .grade-editable .grade-edit-icon {
                    opacity: 0;
                    transition: opacity 0.15s;
                    vertical-align: middle;
                }
                .grade-editable:hover .grade-edit-icon {
                    opacity: 1;
                }
                .grade-inline-input {
                    width: 70px;
                    text-align: center;
                    border: 2px solid #1976d2;
                    border-radius: 4px;
                    padding: 2px 6px;
                    font-size: 0.95rem;
                    outline: none;
                    background: white;
                }
                .grade-inline-input::-webkit-inner-spin-button,
                .grade-inline-input::-webkit-outer-spin-button {
                    opacity: 1;
                }
                .theme--dark.gradebook-card .grade-editable:hover {
                    background-color: rgba(25, 118, 210, 0.2) !important;
                }
                .theme--dark .grade-inline-input {
                    background: #2c2c2c;
                    color: rgba(255,255,255,0.87);
                    border-color: #90caf9;
                }
                .theme--dark.gradebook-card .grade-course-total {
                    background: rgba(25, 118, 210, 0.15) !important;
                    color: #90caf9 !important;
                    border-left: 2px solid #90caf9;
                }
                .theme--dark.gradebook-card tbody tr:hover td {
                    background-color: rgba(255,255,255,0.05);
                }
            `;
            document.head.appendChild(style);
        },
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
                    const apiStudents = response.data.data.students;
                    if (Array.isArray(apiStudents)) {
                        this.students = apiStudents;
                    } else if (apiStudents && typeof apiStudents === 'object') {
                        this.students = Object.values(apiStudents);
                    } else {
                        this.students = [];
                    }

                    // Fallback parity with Student tab:
                    // if grade endpoint has columns but no roster rows, fetch roster from student_info.
                    if (this.columns.length > 0 && this.students.length === 0 && this.classId) {
                        const fallbackStudents = await this.fetchStudentsFallback();
                        if (fallbackStudents.length > 0) {
                            this.students = fallbackStudents;
                        }
                    }
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
        async fetchStudentsFallback() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_student_info',
                    args: {
                        page: 1,
                        resultsperpage: 5000,
                        search: '',
                        planid: '',
                        periodid: '',
                        status: '',
                        classid: this.classId,
                        financial_status: ''
                    },
                    sesskey: window.Y.config.sesskey
                });

                if (!response.data || response.data.status !== 'success' || !response.data.data) {
                    return [];
                }

                let users = response.data.data.dataUsers || [];
                if (typeof users === 'string') {
                    try {
                        users = JSON.parse(users);
                    } catch (e) {
                        users = [];
                    }
                }
                if (!Array.isArray(users)) {
                    return [];
                }

                return users.map(u => {
                    const grades = {};
                    this.columns.forEach(col => {
                        grades[col.id] = '-';
                    });
                    return {
                        id: u.userid,
                        fullname: u.nameuser || '',
                        email: u.email || '',
                        grades: grades
                    };
                });
            } catch (e) {
                console.warn('Grades fallback students failed', e);
                return [];
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
        },
        isManualEditable(student, col) {
            if (col.itemtype !== 'manual' || col.is_total) return false;
            const grade = student.grades[col.id];
            return grade === '-' || grade === null || grade === undefined;
        },
        isEditing(studentId, colId) {
            return this.editingCell !== null &&
                   this.editingCell.studentId === studentId &&
                   this.editingCell.colId === colId;
        },
        isSaving(studentId, colId) {
            return this.savingCell !== null &&
                   this.savingCell.studentId === studentId &&
                   this.savingCell.colId === colId;
        },
        startEdit(student, col) {
            if (this.editingCell) this.cancelEdit();
            const current = student.grades[col.id];
            this.editingValue = (current === '-' || current === null || current === undefined)
                ? ''
                : parseFloat(current);
            this.editingCell = { studentId: student.id, colId: col.id };
        },
        cancelEdit() {
            this.editingCell = null;
            this.editingValue = '';
        },
        async commitEdit(student, col) {
            if (!this.editingCell) return;
            if (this.editingCell.studentId !== student.id || this.editingCell.colId !== col.id) return;

            const value = this.editingValue;
            // Clear editing state immediately to prevent double-fire (Enter + blur)
            this.editingCell = null;
            this.editingValue = '';

            if (value === '' || value === null || value === undefined || isNaN(parseFloat(value))) return;

            const numVal = parseFloat(value);
            if (numVal < 0 || numVal > col.max_grade) {
                alert(`La nota debe estar entre 0 y ${col.max_grade}.`);
                return;
            }

            this.savingCell = { studentId: student.id, colId: col.id };
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_save_manual_grade',
                    args: { gradeitemid: col.id, studentid: student.id, grade: numVal },
                    sesskey: window.Y.config.sesskey
                });
                if (response.data && response.data.status === 'success') {
                    const studentObj = this.students.find(s => s.id === student.id);
                    if (studentObj) this.$set(studentObj.grades, col.id, numVal);
                } else {
                    throw new Error(response.data?.message || 'Error desconocido');
                }
            } catch (err) {
                console.error('[GradesGrid] Error guardando nota manual:', err);
                alert('No se pudo guardar la nota: ' + err.message);
            } finally {
                this.savingCell = null;
            }
        }
    }
});
