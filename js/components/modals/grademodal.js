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
                                                    <th class="text-center text-overline">Acción</th>
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
                                                            v-if="canWithdrawFromCourse(course)"
                                                            x-small
                                                            color="error"
                                                            :loading="withdrawingCourseKey === getCourseKey(course)"
                                                            :disabled="!!withdrawingCourseKey"
                                                            @click.stop="withdrawFromCourse(course)"
                                                        >
                                                            Retirar
                                                        </v-btn>
                                                        <v-btn
                                                            v-else
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
                      <v-btn
                        v-if="showSchedulePdfButton"
                        color="secondary"
                        text
                        :loading="exportingSchedulePdf"
                        :disabled="exportingSchedulePdf || !(dataStudent && dataStudent.id)"
                        @click="downloadStudentSchedulePdf"
                      >
                        <v-icon left>mdi-file-pdf-box</v-icon>
                        Descargar horario PDF
                      </v-btn>
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
            selectedCourse: null,
            withdrawingCourseKey: null,
            exportingSchedulePdf: false
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
        loadExternalScript(src, options = {}) {
            return new Promise((resolve, reject) => {
                const isolateAmd = !!options.isolateAmd;
                let originalDefine = null;
                let originalRequire = null;
                const restoreAmd = () => {
                    if (isolateAmd && originalDefine) {
                        window.define = originalDefine;
                        if (originalRequire) {
                            window.require = originalRequire;
                        }
                    }
                };

                const selector = `script[data-gmk-src="${src}"]`;
                const existing = document.querySelector(selector);
                if (existing) {
                    if (existing.getAttribute('data-loaded') === '1') {
                        resolve();
                        return;
                    }
                    existing.addEventListener('load', () => resolve(), { once: true });
                    existing.addEventListener('error', () => reject(new Error('Script load error: ' + src)), { once: true });
                    return;
                }

                if (isolateAmd && typeof window.define === 'function' && window.define.amd) {
                    originalDefine = window.define;
                    originalRequire = window.require;
                    window.define = undefined;
                }

                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.setAttribute('data-gmk-src', src);
                script.addEventListener('load', () => {
                    script.setAttribute('data-loaded', '1');
                    restoreAmd();
                    resolve();
                }, { once: true });
                script.addEventListener('error', () => {
                    restoreAmd();
                    script.remove();
                    reject(new Error('Script load error: ' + src));
                }, { once: true });
                document.head.appendChild(script);
            });
        },
        async ensurePdfLibrary() {
            if ((window.jspdf && window.jspdf.jsPDF) || window.jsPDF) {
                return;
            }
            const sources = [
                'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            ];
            let lasterror = null;
            for (const src of sources) {
                try {
                    await this.loadExternalScript(src, { isolateAmd: true });
                    if ((window.jspdf && window.jspdf.jsPDF) || window.jsPDF) {
                        return;
                    }
                } catch (error) {
                    lasterror = error;
                }
            }
            if (lasterror) {
                throw lasterror;
            }
            throw new Error('No se pudo inicializar jsPDF.');
        },
        sanitizeFileToken(value) {
            const raw = String(value || '');
            const normalized = raw
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-zA-Z0-9_-]+/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '');
            return normalized || 'estudiante';
        },
        async downloadStudentSchedulePdf() {
            if (this.exportingSchedulePdf || !(this.dataStudent && this.dataStudent.id)) {
                return;
            }

            this.exportingSchedulePdf = true;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action: 'local_grupomakro_get_student_schedule_pdf_data',
                    sesskey: M.cfg.sesskey,
                    userId: Number(this.dataStudent.id),
                };

                const response = await window.axios.get(url, { params });
                const payload = response && response.data ? response.data : {};
                if (payload.status !== 'success') {
                    this.showMessage('error', payload.message || 'No se pudo obtener el horario del estudiante.');
                    return;
                }

                const classes = Array.isArray(payload.classes) ? payload.classes : [];
                if (!classes.length) {
                    this.showMessage('info', 'El estudiante no tiene clases activas o pendientes para exportar.');
                    return;
                }

                await this.ensurePdfLibrary();
                const jsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
                const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

                const student = payload.student || {};
                const generatedAt = String(payload.generatedat || '');
                const pageW = doc.internal.pageSize.getWidth();
                const pageH = doc.internal.pageSize.getHeight();
                const margin = 10;
                const colGap = 4;
                const rowGap = 4;
                const cardW = (pageW - (margin * 2) - colGap) / 2;
                const cardH = 52;

                const drawHeader = (continued) => {
                    let y = margin;
                    doc.setFillColor(25, 118, 210);
                    doc.roundedRect(margin, y, pageW - (margin * 2), 16, 2, 2, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(14);
                    doc.text(continued ? 'Horario del estudiante (continuacion)' : 'Horario del estudiante', margin + 3, y + 6.5);
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(9);
                    doc.text('Generado: ' + (generatedAt || '--'), margin + 3, y + 12);

                    doc.setTextColor(0, 0, 0);
                    y += 20;
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(10);
                    doc.text('Estudiante:', margin, y);
                    doc.setFont('helvetica', 'normal');
                    doc.text(String(student.name || this.studentName || '--'), margin + 22, y);
                    y += 5;
                    doc.setFont('helvetica', 'bold');
                    doc.text('ID:', margin, y);
                    doc.setFont('helvetica', 'normal');
                    doc.text(String(student.idnumber || this.dataStudent.documentnumber || '--'), margin + 22, y);
                    y += 5;
                    doc.setFont('helvetica', 'bold');
                    doc.text('Email:', margin, y);
                    doc.setFont('helvetica', 'normal');
                    doc.text(String(student.email || this.studentEmail || '--'), margin + 22, y);
                    y += 4;
                    return y;
                };

                const statusColors = {
                    'Inscrito': [46, 125, 50],
                    'Pendiente': [245, 124, 0],
                    'Pre-registrado': [2, 136, 209],
                    'Relacionado': [97, 97, 97],
                };

                const drawCard = (x, y, item, index) => {
                    doc.setDrawColor(210, 210, 210);
                    doc.setLineWidth(0.2);
                    doc.roundedRect(x, y, cardW, cardH, 2, 2, 'S');

                    const status = String(item.enrollmentstatus || 'Relacionado');
                    const color = statusColors[status] || statusColors['Relacionado'];
                    doc.setFillColor(color[0], color[1], color[2]);
                    doc.roundedRect(x, y, cardW, 6, 2, 2, 'F');

                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(8);
                    doc.text('#' + String(index + 1) + ' - ' + status, x + 2, y + 4.2);

                    doc.setTextColor(0, 0, 0);
                    let cy = y + 9;
                    const contentW = cardW - 4;

                    const title = String(item.subjectname || item.name || '--');
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(9);
                    const titleLines = doc.splitTextToSize(title, contentW);
                    const limitedTitle = titleLines.slice(0, 2);
                    doc.text(limitedTitle, x + 2, cy);
                    cy += (limitedTitle.length * 3.7) + 1;

                    const pushLine = (label, value, maxLines = 2) => {
                        doc.setFont('helvetica', 'bold');
                        doc.setFontSize(7.5);
                        doc.text(label + ':', x + 2, cy);
                        doc.setFont('helvetica', 'normal');
                        const textLines = doc.splitTextToSize(String(value || '--'), contentW - 15).slice(0, maxLines);
                        doc.text(textLines, x + 17, cy);
                        cy += (textLines.length * 3.3) + 0.8;
                    };

                    pushLine('Curso', item.name || '--', 2);
                    pushLine('Docente', item.instructorname || '--', 2);
                    pushLine('Horario', item.schedulelabel || '--', 3);
                    pushLine('Aula', item.classroomname || 'Sin aula', 1);
                    pushLine('Modalidad', item.typelabel || '--', 1);
                    pushLine('Periodo', item.periodname || ('ID ' + String(item.periodid || '--')), 1);
                    pushLine('Rango', (item.initdateformatted || '--') + ' - ' + (item.enddateformatted || '--'), 1);
                };

                let y = drawHeader(false) + 2;
                let col = 0;
                let rowY = y;

                classes.forEach((item, idx) => {
                    if (col === 0 && (rowY + cardH > (pageH - margin))) {
                        doc.addPage();
                        y = drawHeader(true) + 2;
                        rowY = y;
                        col = 0;
                    }

                    const x = margin + (col * (cardW + colGap));
                    drawCard(x, rowY, item, idx);

                    if (col === 0) {
                        col = 1;
                    } else {
                        col = 0;
                        rowY += cardH + rowGap;
                    }
                });

                const token = this.sanitizeFileToken(student.name || this.studentName || 'estudiante');
                const dateToken = new Date().toISOString().slice(0, 10).replace(/-/g, '');
                const filename = `horario_${token}_${dateToken}.pdf`;
                doc.save(filename);
            } catch (error) {
                console.error('Error generating student schedule pdf:', error);
                this.showMessage('error', 'Error al generar el PDF del horario.');
            } finally {
                this.exportingSchedulePdf = false;
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
                    learningPlanId: Number(this.selectedCourse && this.selectedCourse.learningplanid ? this.selectedCourse.learningplanid : 0),
                };

                const response = await window.axios.get(url, { params });
                const payload = response && response.data ? response.data : {};
                const result = (payload.status === 'success' && payload.data) ? payload.data : payload;

                if (result.status === 'ok' || result.status === 'warning') {
                    item.alreadyenrolled = true;
                    item.enrolled = Number(item.enrolled || 0) + (result.status === 'ok' ? 1 : 0);
                    this.showMessage(result.status === 'ok' ? 'success' : 'warning', result.message || 'Operacion finalizada.');
                    // Refresh pensum immediately so status labels reflect "Cursando" without reopening the modal.
                    await this.getpensum();
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
        },
        canWithdrawFromCourse(course) {
            const label = String((course && course.statusLabel) ? course.statusLabel : '').trim().toLowerCase();
            return label === 'cursando' && Number(course && course.progressclassid ? course.progressclassid : 0) > 0;
        },
        getCourseKey(course) {
            return String(course && course.progressclassid ? course.progressclassid : 0) + '_' + String(course && course.courseid ? course.courseid : 0);
        },
        async withdrawFromCourse(course) {
            const classId = Number(course && course.progressclassid ? course.progressclassid : 0);
            if (!classId || this.withdrawingCourseKey) return;

            const courseName = course.coursename || 'esta asignatura';
            const studentName = this.studentName;

            const confirmed = await (async () => {
                if (window.Swal) {
                    const result = await window.Swal.fire({
                        icon: 'warning',
                        title: '¿Retirar estudiante?',
                        html: `<b>${studentName}</b> será <b>retirado</b> de <b>${courseName}</b>.<br><br>` +
                              `Se eliminará su inscripción en el grupo, se des-matriculará del curso en Moodle ` +
                              `y su estado volverá a <em>Disponible</em> para poder inscribirse nuevamente.`,
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Sí, retirar',
                        cancelButtonText: 'Cancelar',
                    });
                    return result.isConfirmed;
                }
                return window.confirm(
                    `¿Retirar a ${studentName} de ${courseName}?\n\n` +
                    `Se eliminará su inscripción. El estado volverá a Disponible para re-inscripción.`
                );
            })();

            if (!confirmed) return;

            this.withdrawingCourseKey = this.getCourseKey(course);
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action:  'local_grupomakro_withdraw_student',
                    sesskey: M.cfg.sesskey,
                    classId: classId,
                    userId:  Number(this.dataStudent.id),
                    learningPlanId: Number(course && course.learningplanid ? course.learningplanid : 0),
                };
                const response = await window.axios.get(url, { params });
                const payload  = response && response.data ? response.data : {};
                const result   = (payload.status === 'success' && payload.data) ? payload.data : payload;

                if (result.status === 'ok') {
                    this.showMessage('success', result.message || 'Estudiante retirado correctamente.');
                    // Reload pensum to reflect new status.
                    await this.getpensum();
                } else {
                    this.showMessage('error', result.message || 'No se pudo retirar al estudiante.');
                }
            } catch (error) {
                console.error('Error withdrawing student:', error);
                this.showMessage('error', 'Error al retirar al estudiante.');
            } finally {
                this.withdrawingCourseKey = null;
            }
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
        showSchedulePdfButton() {
            return !this.classId;
        },
        selectedCourseName() {
            return this.selectedCourse && this.selectedCourse.coursename ? this.selectedCourse.coursename : '--';
        },
        enrollableClasses() {
            return Array.isArray(this.enrollClasses) ? this.enrollClasses : [];
        }
    },
});
