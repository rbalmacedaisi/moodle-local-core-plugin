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
                                                    <td class="text-center py-1" style="white-space:nowrap;">
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
                                                        <v-btn
                                                            v-if="Number(course.courseid || 0) > 0"
                                                            x-small
                                                            :color="moduleStatusMap[getCourseKey(course)] ? 'teal lighten-1' : 'teal darken-2'"
                                                            dark
                                                            :loading="enrollingModuleKey === getCourseKey(course)"
                                                            :disabled="!!enrollingModuleKey || !!withdrawingCourseKey"
                                                            @click.stop="enrollInModule(course)"
                                                            class="ml-1"
                                                            title="Inscribir en módulo independiente"
                                                        >
                                                            <v-icon x-small :left="!!moduleStatusMap[getCourseKey(course)]">mdi-book-education-outline</v-icon>
                                                            <span v-if="moduleStatusMap[getCourseKey(course)]">Módulo ✓</span>
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
            exportingSchedulePdf: false,
            enrollingModuleKey: null,
            moduleStatusMap: {}
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
        getSchedulePdfLogoUrl() {
            const raw = (typeof window.schedulePdfLogoUrl === 'string') ? window.schedulePdfLogoUrl.trim() : '';
            if (!raw) {
                return '';
            }
            try {
                const parsed = new URL(raw, window.location.origin);
                if (parsed.origin !== window.location.origin) {
                    return '';
                }
                return parsed.href;
            } catch (e) {
                return '';
            }
        },
        loadImageForPdf(url) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = () => reject(new Error('Image load error: ' + url));
                const hasQuery = url.indexOf('?') !== -1;
                img.src = `${url}${hasQuery ? '&' : '?'}v=${Date.now()}`;
            });
        },
        toDayIndex(dayValue) {
            if (typeof dayValue === 'number' && Number.isFinite(dayValue)) {
                const n = Math.trunc(dayValue);
                if (n >= 1 && n <= 7) return n;
                if (n === 0) return 7;
            }
            const raw = String(dayValue || '').trim();
            if (!raw) return 0;
            if (/^\d+$/.test(raw)) {
                const n = Number(raw);
                if (n >= 1 && n <= 7) return n;
                if (n === 0) return 7;
            }
            const key = raw
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '');
            const map = {
                lun: 1, lunes: 1, monday: 1,
                mar: 2, martes: 2, tuesday: 2,
                mie: 3, miercoles: 3, wednesday: 3,
                jue: 4, jueves: 4, thursday: 4,
                vie: 5, viernes: 5, friday: 5,
                sab: 6, sabado: 6, saturday: 6,
                dom: 7, domingo: 7, sunday: 7,
            };
            return map[key] || 0;
        },
        toMinutes(timeValue) {
            const raw = String(timeValue || '').trim();
            if (!raw || raw === '--') {
                return null;
            }
            const normalized = raw
                .toLowerCase()
                .replace(/\./g, '')
                .replace(/\s+/g, '');

            let match = normalized.match(/^(\d{1,2}):(\d{2})(?::\d{2})?(am|pm)?$/);
            if (!match) {
                match = normalized.match(/^(\d{1,2})(am|pm)$/);
                if (match) {
                    match = [normalized, match[1], '00', match[2]];
                }
            }
            if (!match) {
                return null;
            }

            let hours = Number(match[1]);
            const minutes = Number(match[2]);
            const meridiem = match[3] || '';

            if (!Number.isFinite(hours) || !Number.isFinite(minutes) || minutes < 0 || minutes > 59) {
                return null;
            }

            if (meridiem === 'pm' && hours < 12) {
                hours += 12;
            } else if (meridiem === 'am' && hours === 12) {
                hours = 0;
            }

            if (hours < 0 || hours > 23) {
                return null;
            }
            return (hours * 60) + minutes;
        },
        formatMinutesLabel(totalMinutes) {
            const min = Math.max(0, Math.min(24 * 60, Number(totalMinutes) || 0));
            const h = Math.floor(min / 60);
            const m = min % 60;
            const hh = String(h).padStart(2, '0');
            const mm = String(m).padStart(2, '0');
            return `${hh}:${mm}`;
        },
        getCalendarColor(seed) {
            const palette = [
                [232, 245, 233],
                [227, 242, 253],
                [255, 243, 224],
                [243, 229, 245],
                [232, 234, 246],
                [252, 228, 236],
                [225, 245, 254],
                [255, 249, 196],
            ];
            const source = String(seed || '0');
            let hash = 0;
            for (let i = 0; i < source.length; i += 1) {
                hash = ((hash * 31) + source.charCodeAt(i)) % 2147483647;
            }
            return palette[Math.abs(hash) % palette.length];
        },
        extractCalendarEntries(classes) {
            const entries = [];
            const withoutSchedule = [];
            const unique = new Set();

            const pushEntry = (item, dayValue, startValue, endValue) => {
                const dayIndex = this.toDayIndex(dayValue);
                const startMin = this.toMinutes(startValue);
                let endMin = this.toMinutes(endValue);
                if (dayIndex < 1 || dayIndex > 7 || startMin === null || endMin === null) {
                    return false;
                }
                if (endMin <= startMin) {
                    endMin = startMin + 60;
                }
                endMin = Math.min(endMin, 24 * 60);
                const classId = Number(item && item.id ? item.id : 0);
                const key = `${classId}|${dayIndex}|${startMin}|${endMin}`;
                if (unique.has(key)) {
                    return false;
                }
                unique.add(key);
                entries.push({
                    classid: classId,
                    name: String(item && item.name ? item.name : '--'),
                    subjectname: String(item && item.subjectname ? item.subjectname : (item && item.name ? item.name : '--')),
                    instructorname: String(item && item.instructorname ? item.instructorname : ''),
                    classroomname: String(item && item.classroomname ? item.classroomname : 'Sin aula'),
                    enrollmentstatus: String(item && item.enrollmentstatus ? item.enrollmentstatus : 'Relacionado'),
                    dayIndex: dayIndex,
                    startMin: startMin,
                    endMin: endMin,
                });
                return true;
            };

            classes.forEach((item) => {
                let addedAny = false;
                const structured = Array.isArray(item && item.schedules) ? item.schedules : [];

                structured.forEach((schedule) => {
                    const dayValue = (schedule && schedule.dayindex) ? schedule.dayindex : (schedule ? schedule.day : '');
                    const startValue = schedule ? schedule.start : '';
                    const endValue = schedule ? schedule.end : '';
                    if (pushEntry(item, dayValue, startValue, endValue)) {
                        addedAny = true;
                    }
                });

                if (!addedAny) {
                    const pieces = Array.isArray(item && item.schedulepieces) ? item.schedulepieces : [];
                    pieces.forEach((piece) => {
                        const text = String(piece || '').trim();
                        if (!text) {
                            return;
                        }
                        const match = text.match(/^(.+?)\s+([0-9]{1,2}:[0-9]{2}(?:\s*[ap]\.?m\.?)?)-([0-9]{1,2}:[0-9]{2}(?:\s*[ap]\.?m\.?)?)$/i);
                        if (!match) {
                            return;
                        }
                        const daysPart = String(match[1] || '');
                        const startValue = String(match[2] || '');
                        const endValue = String(match[3] || '');
                        const dayTokens = daysPart
                            .split(/[,/|]+/)
                            .map((d) => String(d || '').trim())
                            .filter(Boolean);

                        let pieceAdded = false;
                        dayTokens.forEach((dayToken) => {
                            if (pushEntry(item, dayToken, startValue, endValue)) {
                                pieceAdded = true;
                            }
                        });

                        if (!pieceAdded && pushEntry(item, daysPart, startValue, endValue)) {
                            pieceAdded = true;
                        }
                        if (pieceAdded) {
                            addedAny = true;
                        }
                    });
                }

                if (!addedAny) {
                    withoutSchedule.push(item);
                }
            });

            entries.sort((a, b) => {
                if (a.dayIndex !== b.dayIndex) return a.dayIndex - b.dayIndex;
                if (a.startMin !== b.startMin) return a.startMin - b.startMin;
                if (a.endMin !== b.endMin) return a.endMin - b.endMin;
                return a.classid - b.classid;
            });

            return { entries, withoutSchedule };
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
                const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

                const student = payload.student || {};
                const generatedAt = String(payload.generatedat || '');
                const studentIdentification = String(
                    (this.dataStudent && this.dataStudent.documentnumber) ||
                    student.documentnumber ||
                    (this.dataStudent && this.dataStudent.idnumber) ||
                    student.idnumber ||
                    '--'
                );
                const calendarData = this.extractCalendarEntries(classes);
                const entries = calendarData.entries;
                const withoutSchedule = calendarData.withoutSchedule;
                let logoImage = null;
                let logoRatio = 1;

                const logoUrl = this.getSchedulePdfLogoUrl();
                if (logoUrl) {
                    try {
                        logoImage = await this.loadImageForPdf(logoUrl);
                        if (logoImage && logoImage.naturalWidth > 0 && logoImage.naturalHeight > 0) {
                            logoRatio = logoImage.naturalWidth / logoImage.naturalHeight;
                        }
                    } catch (logoError) {
                        console.warn('Schedule PDF logo could not be loaded:', logoError);
                    }
                }

                if (!entries.length && !withoutSchedule.length) {
                    this.showMessage('info', 'No hay datos de horario para exportar.');
                    return;
                }

                const pageW = doc.internal.pageSize.getWidth();
                const pageH = doc.internal.pageSize.getHeight();
                const margin = 8;

                doc.setFillColor(25, 118, 210);
                doc.roundedRect(margin, margin, pageW - (margin * 2), 16, 2, 2, 'F');
                doc.setTextColor(255, 255, 255);
                let headerTextX = margin + 3;
                if (logoImage) {
                    const logoH = 12;
                    const logoW = Math.max(10, Math.min(36, logoH * logoRatio));
                    const logoX = margin + 2;
                    const logoY = margin + 2;
                    try {
                        doc.addImage(logoImage, 'PNG', logoX, logoY, logoW, logoH);
                        headerTextX = logoX + logoW + 2.5;
                    } catch (e1) {
                        try {
                            doc.addImage(logoImage, 'JPEG', logoX, logoY, logoW, logoH);
                            headerTextX = logoX + logoW + 2.5;
                        } catch (e2) {
                            // Continue without logo.
                        }
                    }
                }
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(14);
                doc.text('Horario semanal del estudiante', headerTextX, margin + 6.5);
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(9);
                doc.text('Generado: ' + (generatedAt || '--'), headerTextX, margin + 12);

                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9);
                doc.text('Estudiante:', margin, margin + 22);
                doc.setFont('helvetica', 'normal');
                doc.text(String(student.name || this.studentName || '--'), margin + 20, margin + 22);
                doc.setFont('helvetica', 'bold');
                doc.text('ID:', margin + 110, margin + 22);
                doc.setFont('helvetica', 'normal');
                doc.text(studentIdentification, margin + 117, margin + 22);
                doc.setFont('helvetica', 'bold');
                doc.text('Email:', margin + 170, margin + 22);
                doc.setFont('helvetica', 'normal');
                doc.text(String(student.email || this.studentEmail || '--'), margin + 182, margin + 22);

                const statusColors = {
                    'Inscrito': [46, 125, 50],
                    'Pendiente': [245, 124, 0],
                    'Pre-registrado': [2, 136, 209],
                    'Relacionado': [97, 97, 97],
                };

                let minMinutes = 7 * 60;
                let maxMinutes = 22 * 60;
                if (entries.length > 0) {
                    minMinutes = Math.min(...entries.map((e) => e.startMin));
                    maxMinutes = Math.max(...entries.map((e) => e.endMin));
                    minMinutes = Math.max(0, (Math.floor(minMinutes / 30) * 30) - 30);
                    maxMinutes = Math.min(24 * 60, (Math.ceil(maxMinutes / 30) * 30) + 30);
                    if ((maxMinutes - minMinutes) < (5 * 60)) {
                        maxMinutes = Math.min(24 * 60, minMinutes + (5 * 60));
                    }
                }

                const interval = 30;
                const rows = Math.max(1, Math.round((maxMinutes - minMinutes) / interval));
                const dayLabels = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
                const calendarX = margin;
                const calendarY = margin + 26;
                const bottomReserve = withoutSchedule.length > 0 ? 20 : 12;
                const calendarW = pageW - (margin * 2);
                const calendarH = Math.max(90, pageH - calendarY - bottomReserve);
                const timeColumnW = 17;
                const dayHeaderH = 8;
                const dayW = (calendarW - timeColumnW) / 7;
                const rowH = (calendarH - dayHeaderH) / rows;

                doc.setDrawColor(180, 180, 180);
                doc.setLineWidth(0.2);
                doc.rect(calendarX, calendarY, calendarW, calendarH);
                doc.setFillColor(240, 244, 248);
                doc.rect(calendarX, calendarY, calendarW, dayHeaderH, 'F');
                doc.rect(calendarX, calendarY, timeColumnW, calendarH, 'F');

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8);
                doc.setTextColor(40, 40, 40);
                for (let d = 0; d < dayLabels.length; d += 1) {
                    const x = calendarX + timeColumnW + (d * dayW);
                    doc.line(x, calendarY, x, calendarY + calendarH);
                    const centerX = x + (dayW / 2);
                    doc.text(dayLabels[d], centerX, calendarY + 5.2, { align: 'center' });
                }

                for (let r = 0; r <= rows; r += 1) {
                    const yLine = calendarY + dayHeaderH + (r * rowH);
                    doc.line(calendarX, yLine, calendarX + calendarW, yLine);
                    if (r % 2 === 0) {
                        const mins = minMinutes + (r * interval);
                        doc.setFont('helvetica', 'normal');
                        doc.setFontSize(7);
                        doc.text(this.formatMinutesLabel(mins), calendarX + 1.5, yLine + 2.5);
                    }
                }

                entries.forEach((entry) => {
                    const dayPos = Math.min(6, Math.max(0, entry.dayIndex - 1));
                    const startOffset = (entry.startMin - minMinutes) / interval;
                    const duration = Math.max(interval, entry.endMin - entry.startMin);
                    const x = calendarX + timeColumnW + (dayPos * dayW) + 0.7;
                    const y = calendarY + dayHeaderH + (startOffset * rowH) + 0.5;
                    const w = dayW - 1.4;
                    let h = Math.max(8, (duration / interval) * rowH - 1);
                    const maxH = (calendarY + calendarH - 0.7) - y;
                    if (maxH <= 1) {
                        return;
                    }
                    h = Math.min(h, maxH);

                    const status = String(entry.enrollmentstatus || 'Relacionado');
                    const bg = statusColors[status] || statusColors['Relacionado'];
                    doc.setFillColor(bg[0], bg[1], bg[2]);
                    doc.setDrawColor(Math.max(0, bg[0] - 35), Math.max(0, bg[1] - 35), Math.max(0, bg[2] - 35));
                    doc.roundedRect(x, y, w, h, 1, 1, 'FD');

                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(7);
                    const contentLines = [
                        String(entry.subjectname || entry.name || '--'),
                        `${this.formatMinutesLabel(entry.startMin)}-${this.formatMinutesLabel(entry.endMin)} | ${status}`,
                        `Docente: ${entry.instructorname || '--'}`,
                        `Aula: ${entry.classroomname || 'Sin aula'}`,
                    ];
                    let wrapped = [];
                    contentLines.forEach((line) => {
                        const part = doc.splitTextToSize(String(line || ''), w - 1.2);
                        wrapped = wrapped.concat(part);
                    });
                    const maxLines = Math.max(1, Math.floor((h - 1.4) / 2.9));
                    doc.text(wrapped.slice(0, maxLines), x + 0.6, y + 2.7);
                });

                let legendY = calendarY + calendarH + 4;
                let legendX = calendarX;
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8);
                doc.setTextColor(30, 30, 30);
                doc.text('Estado:', legendX, legendY);
                legendX += 14;

                Object.keys(statusColors).forEach((label) => {
                    const color = statusColors[label];
                    doc.setFillColor(color[0], color[1], color[2]);
                    doc.rect(legendX, legendY - 3.3, 4, 3.2, 'F');
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(7.5);
                    doc.setTextColor(30, 30, 30);
                    doc.text(label, legendX + 5.5, legendY - 0.4);
                    legendX += (18 + (label.length * 1.4));
                });

                if (withoutSchedule.length > 0) {
                    const sample = withoutSchedule
                        .slice(0, 6)
                        .map((item) => String(item && (item.subjectname || item.name) ? (item.subjectname || item.name) : '--'))
                        .join(' | ');
                    const rest = withoutSchedule.length > 6 ? ` | +${withoutSchedule.length - 6} mas` : '';
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(7.5);
                    doc.setTextColor(70, 70, 70);
                    const text = 'Sin horario estructurado: ' + sample + rest;
                    doc.text(doc.splitTextToSize(text, calendarW), calendarX, legendY + 4.8);
                }

                if (entries.length === 0) {
                    doc.setTextColor(120, 30, 30);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(10);
                    doc.text('No hay horarios con dia/hora para pintar en el calendario.', calendarX + timeColumnW + 5, calendarY + dayHeaderH + 10);
                }

                const token = this.sanitizeFileToken(student.name || this.studentName || 'estudiante');
                const dateToken = new Date().toISOString().slice(0, 10).replace(/-/g, '');
                const filename = `horario_semanal_${token}_${dateToken}.pdf`;
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
            const map = ['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'];
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
        async enrollInModule(course) {
            const key = this.getCourseKey(course);
            const existing = this.moduleStatusMap[key];
            if (existing) {
                const date = existing.duedate
                    ? new Date(existing.duedate * 1000).toLocaleDateString('es-PA', { day: '2-digit', month: 'short', year: 'numeric' })
                    : '—';
                const period = existing.periodname ? ' | Período: ' + existing.periodname : '';
                this.showMessage('info', 'Ya inscrito en módulo' + period + '. Plazo: ' + date);
                return;
            }

            // Pre-fetch the student period name to show in the confirmation dialog
            let periodLabel = '';
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const preRes = await window.axios.get(url, { params: {
                    action: 'local_grupomakro_get_student_period',
                    sesskey: M.cfg.sesskey,
                    userId: this.dataStudent.id,
                }});
                const preData = ((preRes.data || {}).data) || {};
                if (preData.periodname) periodLabel = '<br><small><b>Período:</b> ' + preData.periodname + '</small>';
            } catch (_) {}

            const swResult = await window.Swal.fire({
                title: 'Inscribir en Módulo',
                html: '¿Inscribir a <b>' + this.studentName + '</b> en el módulo de <b>' + (course.coursename || '') + '</b>?'
                    + periodLabel
                    + '<br><small class="grey--text">El estudiante tendrá <b>25 días</b> para completar las actividades.</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Inscribir',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#00796B',
            });
            if (!swResult.isConfirmed) return;

            this.enrollingModuleKey = key;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const response = await window.axios.get(url, {
                    params: {
                        action: 'local_grupomakro_enroll_module',
                        sesskey: M.cfg.sesskey,
                        userId: this.dataStudent.id,
                        coreCourseId: Number(course.courseid || 0),
                        learningPlanId: Number(course.learningplanid || 0),
                    }
                });
                const payload = response.data || {};
                const data = (payload.data) ? payload.data : payload;

                if (data.status === 'ok') {
                    this.$set(this.moduleStatusMap, key, { enrolled: true, duedate: data.duedate || 0, periodname: data.periodname || '' });
                    this.showMessage('success', data.message || 'Inscrito en módulo correctamente.');
                } else if (data.status === 'warning') {
                    this.$set(this.moduleStatusMap, key, { enrolled: true, duedate: data.duedate || 0, periodname: data.periodname || '' });
                    this.showMessage('warning', data.message || 'Ya estaba inscrito en este módulo.');
                } else {
                    this.showMessage('error', data.message || 'No se pudo inscribir en el módulo.');
                }
            } catch (e) {
                console.error('Error enrolling in module:', e);
                this.showMessage('error', 'Error al inscribir en módulo.');
            } finally {
                this.enrollingModuleKey = null;
            }
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
