const QuickGrader = {
    props: {
        task: { type: Object, required: true },
        allTasks: { type: Array, required: true }
    },
    template: `
        <v-dialog v-model="visible" fullscreen hide-overlay transition="dialog-bottom-transition">
            <v-card class="d-flex flex-column h-100 grey lighten-5">
                <!-- Toolbar -->
                <v-toolbar dark color="primary" dense class="quick-grader-toolbar">
                    <v-btn icon dark @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                    <v-toolbar-title class="ml-2">
                        {{ currentTask.modname === 'quiz' ? 'Calificando Cuestionario' : 'Calificando Tarea' }}: 
                        {{ currentTask.studentname }} - {{ currentTask.assignmentname }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <div class="text-caption mr-4">
                        {{ currentIndex + 1 }} de {{ allTasks.length }}
                    </div>
                </v-toolbar>

                <div class="d-flex flex-grow-1 overflow-hidden" style="height: calc(100vh - 48px);">
                    <!-- Left Panel: Content -->
                    <div class="flex-grow-1 overflow-y-auto pa-4" style="background: #e0e0e0;">
                        
                        <!-- ASSIGNMENT VIEW -->
                        <v-card v-if="currentTask.modname === 'assign'" class="mx-auto" max-width="900" min-height="100%">
                            <v-card-title>Entrega de Tarea</v-card-title>
                            <v-card-subtitle>
                                <template v-if="currentTask.submissionstatus === 'reopened'">
                                    <v-icon small color="orange darken-1">mdi-lock-open-variant-outline</v-icon>
                                    <span class="orange--text text--darken-1 font-weight-medium ml-1">Reabierta — esperando nueva entrega del estudiante</span>
                                </template>
                                <template v-else>Enviado el: {{ formatDate(currentTask.submissiontime, true) }}</template>
                            </v-card-subtitle>
                            <v-divider></v-divider>
                            <v-card-text class="pa-4">
                                <div v-if="loadingAssignDetails" class="mb-3 text-caption grey--text">
                                    Cargando detalle de la entrega...
                                </div>
                                <div class="mb-4">
                                    <h3 class="text-subtitle-1 font-weight-bold mb-2">Texto en linea:</h3>
                                    <div
                                        v-if="currentTask.submissiontexthtml && currentTask.submissiontexthtml.trim() !== ''"
                                        class="pa-3 grey lighten-5 rounded submission-onlinetext"
                                        v-html="currentTask.submissiontexthtml"
                                    ></div>
                                    <div
                                        v-else-if="currentTask.submissiontextplain && currentTask.submissiontextplain.trim() !== ''"
                                        class="pa-3 grey lighten-5 rounded submission-onlinetext"
                                        style="white-space: pre-wrap;"
                                    >{{ currentTask.submissiontextplain }}</div>
                                    <div v-else class="text-caption grey--text">
                                        No hay texto en linea en esta entrega.
                                    </div>
                                </div>

                                <!-- PREVIEW AREA -->
                                <v-fade-transition>
                                    <div v-if="selectedFile" class="preview-panel mb-4">
                                        <div class="d-flex justify-space-between align-center pa-2 grey lighten-3 border-bottom">
                                            <span class="text-caption font-weight-bold text-truncate mr-2">{{ selectedFile.filename }}</span>
                                            <div class="d-flex align-center" style="gap:4px;">
                                                <v-tooltip bottom>
                                                    <template v-slot:activator="{ on }">
                                                        <v-btn icon x-small color="primary" v-on="on" @click="downloadFile(selectedFile)">
                                                            <v-icon small>mdi-download</v-icon>
                                                        </v-btn>
                                                    </template>
                                                    <span>Descargar archivo</span>
                                                </v-tooltip>
                                                <v-btn icon x-small @click="selectedFile = null">
                                                    <v-icon>mdi-close</v-icon>
                                                </v-btn>
                                            </div>
                                        </div>
                                        <div class="preview-content-wrapper">

                                            <!-- Image Preview -->
                                            <v-img v-if="isImage(selectedFile)" :src="selectedFile.fileurl" contain max-height="600" class="grey lighten-4"></v-img>

                                            <!-- PDF Preview — via proxy (inline disposition, no forced download) -->
                                            <iframe
                                                v-else-if="isPDF(selectedFile)"
                                                :src="proxyUrl(selectedFile) + '#toolbar=1&navpanes=0'"
                                                class="preview-iframe"
                                            ></iframe>

                                            <!-- Office files: DOCX, XLSX, PPTX, PPTM, etc. — server-side PHP converter -->
                                            <div v-else-if="isOffice(selectedFile)" class="preview-office-wrapper">
                                                <div v-if="previewLoading" class="preview-loading-overlay">
                                                    <v-progress-circular indeterminate color="primary" size="40"></v-progress-circular>
                                                    <div class="mt-2 text-caption grey--text">Convirtiendo documento...</div>
                                                </div>
                                                <div v-else-if="previewError" class="pa-6 text-center grey lighten-4">
                                                    <v-icon large :color="getOfficeErrorColor(selectedFile)">{{ getFileIcon(selectedFile) }}</v-icon>
                                                    <div class="mt-2 grey--text">No se pudo convertir el documento.</div>
                                                    <v-btn small color="primary" class="mt-3" @click="downloadFile(selectedFile)">
                                                        <v-icon left small>mdi-download</v-icon> Descargar para ver
                                                    </v-btn>
                                                </div>
                                                <div v-else class="pa-3 office-preview" v-html="officeContent"></div>
                                            </div>

                                            <!-- Generic / Not supported -->
                                            <div v-else class="pa-10 text-center grey lighten-4">
                                                <v-icon x-large color="grey lighten-1">mdi-file-eye-off</v-icon>
                                                <div class="mt-2 grey--text">Vista previa no disponible para este formato</div>
                                                <v-btn small color="primary" class="mt-4" @click="openFile(selectedFile.fileurl)">
                                                    <v-icon left small>mdi-open-in-new</v-icon> Abrir archivo
                                                </v-btn>
                                            </div>

                                        </div>
                                    </div>
                                </v-fade-transition>

                                <div v-if="currentTask.files && currentTask.files.length > 0">
                                    <h3 class="text-subtitle-1 font-weight-bold mb-2">Archivos Adjuntos:</h3>
                                    <v-row>
                                        <v-col v-for="(file, i) in currentTask.files" :key="i" cols="12" sm="6" md="4">
                                            <v-card outlined :color="selectedFile === file ? 'primary lighten-5' : ''" :class="selectedFile === file ? 'border-primary' : ''">
                                                <v-list-item dense @click="handleFileClick(file)" style="cursor:pointer;">
                                                    <v-list-item-avatar tile size="32" :color="getFileIconColor(file)">
                                                        <v-icon dark small>{{ getFileIcon(file) }}</v-icon>
                                                    </v-list-item-avatar>
                                                    <v-list-item-content>
                                                        <v-list-item-title class="text-caption font-weight-medium text-truncate">
                                                            {{ file.filename }}
                                                        </v-list-item-title>
                                                        <v-list-item-subtitle class="text-overline blue--text" style="font-size: 0.6rem !important;">
                                                            {{ isPreviewable(file) ? 'Ver' : 'Descargar' }}
                                                        </v-list-item-subtitle>
                                                    </v-list-item-content>
                                                    <v-list-item-action>
                                                        <v-tooltip bottom>
                                                            <template v-slot:activator="{ on }">
                                                                <v-btn icon x-small v-on="on" @click.stop="downloadFile(file)">
                                                                    <v-icon small color="grey darken-1">mdi-download</v-icon>
                                                                </v-btn>
                                                            </template>
                                                            <span>Descargar</span>
                                                        </v-tooltip>
                                                    </v-list-item-action>
                                                </v-list-item>
                                            </v-card>
                                        </v-col>
                                    </v-row>
                                </div>
                                <div v-else class="text-center pa-4 grey--text">No hay archivos adjuntos.</div>
                            </v-card-text>
                        </v-card>

                        <div v-if="currentTask.modname === 'quiz'" class="h-100 d-flex flex-column">
                            <v-skeleton-loader v-if="loadingQuiz" type="article, actions"></v-skeleton-loader>
                            
                            <v-alert v-else-if="quizError" type="error" outlined class="ma-4">
                                {{ quizError }}
                            </v-alert>

                            <template v-else-if="quizData && quizData.questions && quizData.questions.length > 0">
                                <div class="mb-4 d-flex align-center">
                                    <v-btn-toggle v-model="selectedSlotIndex" mandatory color="primary" class="flex-wrap">
                                        <v-btn v-for="(q, i) in quizData.questions" :key="i" small 
                                               :color="q.needsgrading ? 'orange lighten-4' : ''">
                                            P{{ q.slot }}
                                            <v-icon x-small v-if="q.needsgrading" class="ml-1" color="orange">mdi-alert-circle</v-icon>
                                        </v-btn>
                                    </v-btn-toggle>
                                </div>

                                <v-card class="flex-grow-1 overflow-y-auto pa-4 q-container">
                                    <div class="d-flex justify-space-between align-center mb-2">
                                        <h3 class="text-h6">{{ currentQuestion.name }}</h3>
                                        <v-chip small :color="currentQuestion.needsgrading ? 'orange' : 'success'" dark>
                                            {{ currentQuestion.needsgrading ? 'Requiere Calificación' : 'Calificada' }}
                                        </v-chip>
                                    </div>
                                    <v-divider class="mb-4"></v-divider>
                                    <div class="rendered-question" v-html="currentQuestion.html"></div>
                                </v-card>
                            </template>
                        </div>
                    </div>

                    <!-- Right Panel: Grading Form -->
                    <div class="white elevation-4 d-flex flex-column" style="width: 350px; z-index: 2;">
                         <v-card-title class="subtitle-1 font-weight-bold">
                             {{ currentTask.modname === 'quiz' ? 'Calificar Pregunta' : 'Evaluar Tarea' }}
                         </v-card-title>
                         <v-divider></v-divider>
                         
                         <div class="pa-4 flex-grow-1 overflow-y-auto">
                             <v-form ref="form" v-model="valid">
                                 <div class="d-flex align-center mb-1">
                                    <v-text-field
                                        v-model.number="grade"
                                        :label="currentTask.modname === 'quiz' ? 'Puntos' : 'Calificación (0-100)'"
                                        type="number"
                                        outlined
                                        dense
                                        :rules="gradeRules"
                                        required
                                        class="mr-2"
                                    ></v-text-field>
                                    <span class="text-caption grey--text mb-6">/ {{ currentMaxGrade }}</span>
                                 </div>

                                 <v-textarea
                                     v-model="feedback"
                                     label="Comentarios / Retroalimentación"
                                     outlined
                                     rows="8"
                                     placeholder="Escribe comentarios para el estudiante..."
                                 ></v-textarea>
                             </v-form>
                         </div>

                         <v-divider></v-divider>
                         <div class="pa-4 grey lighten-5">
                             <v-btn block color="primary" large :loading="saving" @click="saveAndNext" :disabled="!valid">
                                 {{ isLastQuestion ? 'Guardar y Finalizar' : 'Guardar y Siguiente' }}
                                 <v-icon right>{{ isLastQuestion ? 'mdi-check-all' : 'mdi-skip-next' }}</v-icon>
                             </v-btn>
                             <v-btn block text class="mt-2" @click="skip">
                                 Saltar
                              </v-btn>
                              <v-btn block text color="grey darken-1" class="mt-1" @click="close">
                                  Cerrar
                             </v-btn>
                             <template v-if="currentTask.modname === 'assign'">
                                 <v-divider class="my-3"></v-divider>
                                 <v-alert v-if="reopenError" type="error" dense text class="mb-2 text-caption">{{ reopenError }}</v-alert>
                                 <v-alert v-if="reopenSuccess" type="success" dense text class="mb-2 text-caption">{{ reopenSuccess }}</v-alert>
                                 <v-btn
                                     block
                                     outlined
                                     color="orange darken-2"
                                     :loading="reopening"
                                     :disabled="reopening || !!reopenSuccess"
                                     @click="reopenSubmission"
                                 >
                                     <v-icon left small>mdi-lock-open-variant-outline</v-icon>
                                     Habilitar reenvío
                                 </v-btn>
                             </template>
                         </div>
                    </div>
                </div>
            </v-card>
            
        </v-dialog>
    `,
    data() {
        return {
            visible: true,
            currentTask: {},
            grade: '',
            feedback: '',
            saving: false,
            valid: false,
            // Quiz specifics
            quizData: { questions: [] },
            loadingQuiz: false,
            selectedSlotIndex: 0,
            quizError: null,
            saveError: null,
            loadingAssignDetails: false,
            // File Preview specifics
            selectedFile: null,
            officeContent: '',
            previewLoading: false,
            previewError: false,
            mammothLoaded: false,
            xlsxLoaded: false,
            // Reopen submission
            reopening: false,
            reopenError: '',
            reopenSuccess: ''
        }
    },
    computed: {
        currentIndex() {
            return this.allTasks.findIndex(t => t.id === this.currentTask.id);
        },
        currentQuestion() {
            return this.quizData.questions[this.selectedSlotIndex] || {};
        },
        currentMaxGrade() {
            if (this.currentTask.modname === 'quiz') {
                return this.currentQuestion.maxgrade || 0;
            }
            return 100;
        },
        gradeRules() {
            const max = this.currentMaxGrade;
            return [
                v => (v !== null && v !== undefined && v !== '') || 'Requerido',
                v => (v >= 0 && v <= max) || `Debe ser entre 0 y ${max}`
            ];
        },
        isLastQuestion() {
            if (this.currentTask.modname === 'quiz') {
                return this.selectedSlotIndex === this.quizData.questions.length - 1;
            }
            return true;
        }
    },
    watch: {
        task: {
            immediate: true,
            handler(val) {
                if (val) {
                    this.currentTask = val;
                    this.resetForm();
                    // If submission was already reopened, disable the reopen button accordingly.
                    if (val.submissionstatus === 'reopened') {
                        this.reopenSuccess = 'El reenvío ya está habilitado. Esperando nueva entrega del estudiante.';
                    }
                    if (val.modname === 'quiz') {
                        this.fetchQuizData();
                    } else if (val.modname === 'assign') {
                        this.fetchAssignDetails();
                    }
                }
            }
        },
        selectedSlotIndex(newIdx) {
            if (this.currentTask.modname === 'quiz' && this.quizData.questions[newIdx]) {
                const q = this.quizData.questions[newIdx];
                // Use != null so that a 0-point auto-graded answer shows "0" and not ""
                this.grade = (q.currentgrade != null) ? q.currentgrade : '';
                this.feedback = q.currentcomment || '';
            }
        }
    },
    created() {
        this.injectStyles();
    },
    methods: {
        injectStyles() {
            const styleId = 'quick-grader-styles';
            if (document.getElementById(styleId)) return;
            const style = document.createElement('style');
            style.id = styleId;
            style.textContent = `
                .v-dialog--fullscreen {
                    z-index: 99999 !important;
                    margin-top: 0px !important;
                }
                .quick-grader-toolbar {
                    z-index: 100000 !important;
                    position: sticky !important;
                    top: 0 !important;
                }
                .v-dialog__content {
                    z-index: 99998 !important;
                }
                .preview-panel {
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    overflow: hidden;
                    background: white;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                .preview-content-wrapper {
                    min-height: 200px;
                    max-height: 70vh;
                    overflow-y: auto;
                    position: relative;
                }
                .preview-iframe {
                    width: 100%;
                    height: 75vh;
                    border: none;
                    display: block;
                }
                .preview-office-wrapper {
                    position: relative;
                    min-height: 200px;
                }
                .preview-loading-overlay {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    min-height: 200px;
                    padding: 32px;
                }
                .docx-preview {
                    font-family: 'Times New Roman', Times, serif;
                    line-height: 1.6;
                    max-width: 860px;
                    margin: 0 auto;
                }
                .docx-preview img { max-width: 100%; height: auto; }
                .excel-table-wrapper { overflow-x: auto; }
                .excel-table-wrapper table {
                    border-collapse: collapse;
                    width: 100%;
                    font-size: 0.8rem;
                }
                .excel-table-wrapper th, .excel-table-wrapper td {
                    border: 1px solid #ddd;
                    padding: 4px 8px;
                    white-space: nowrap;
                }
                .excel-table-wrapper tr:nth-child(even) { background-color: #f9f9f9; }
                .excel-table-wrapper th { background-color: #f2f2f2; font-weight: bold; }
                .border-primary {
                    border: 1px solid var(--v-primary-base) !important;
                }
                .border-bottom {
                    border-bottom: 1px solid #eee;
                }
                .submission-onlinetext img {
                    max-width: 100%;
                    height: auto;
                }
                .submission-onlinetext table {
                    max-width: 100%;
                    display: block;
                    overflow-x: auto;
                }
            `;
            document.head.appendChild(style);
        },
        close() {
            this.visible = false;
            this.$emit('close');
        },
        resetForm() {
            this.grade = '';
            this.feedback = '';
            this.saveError = null;
            this.loadingAssignDetails = false;
            this.selectedFile = null;
            this.officeContent = '';
            this.previewLoading = false;
            this.previewError = false;
            this.reopening = false;
            this.reopenError = '';
            this.reopenSuccess = '';
            this.quizData = { questions: [] };
            this.selectedSlotIndex = 0;
            if (this.$refs.form) this.$refs.form.resetValidation();
        },
        async fetchQuizData() {
            this.loadingQuiz = true;
            this.quizError = null;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_quiz_attempt_data',
                    attemptid: this.currentTask.id,
                    sesskey: M.cfg.sesskey
                });

                if (response.data.status === 'success') {
                    this.quizData = response.data.data;
                    if (!this.quizData.questions || this.quizData.questions.length === 0) {
                        this.quizError = "No se encontraron preguntas en este intento.";
                    } else {
                        const firstNeeds = this.quizData.questions.findIndex(q => q.needsgrading);
                        const firstIdx = firstNeeds !== -1 ? firstNeeds : 0;
                        this.selectedSlotIndex = firstIdx;
                        // Pre-populate grade and comment — watcher may not fire if index was already 0
                        const q = this.quizData.questions[firstIdx];
                        this.grade = (q && q.currentgrade != null) ? q.currentgrade : '';
                        this.feedback = (q && q.currentcomment) ? q.currentcomment : '';
                    }
                } else {
                    this.quizError = response.data.message || "Error al cargar datos del cuestionario.";
                    console.error("Quiz API Error:", response.data);
                }
            } catch (e) {
                this.quizError = "Error de conexión al servidor.";
                console.error("Quiz Connection Error:", e);
            } finally {
                this.loadingQuiz = false;
            }
        },
        async fetchAssignDetails() {
            if (!this.currentTask || this.currentTask.modname !== 'assign') {
                return;
            }
            if (!this.currentTask.assignmentid || !this.currentTask.studentid) {
                return;
            }
            this.loadingAssignDetails = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_assign_submission_details',
                    assignmentid: this.currentTask.assignmentid,
                    studentid: this.currentTask.studentid,
                    courseid: this.currentTask.courseid || 0,
                    submissionid: this.currentTask.id || 0,
                    sesskey: M.cfg.sesskey
                });

                if (response.data && response.data.status === 'success' && response.data.data) {
                    const detail = response.data.data;
                    this.currentTask = Object.assign({}, this.currentTask, {
                        submissiontext: detail.submissiontext || '',
                        submissiontexthtml: detail.submissiontexthtml || '',
                        submissiontextplain: detail.submissiontextplain || '',
                        files: Array.isArray(detail.files) ? detail.files : []
                    });
                    // Pre-populate grade and feedback if Moodle already has them saved.
                    if (detail.currentgrade != null) {
                        this.grade = detail.currentgrade;
                    }
                    if (detail.currentfeedback) {
                        this.feedback = detail.currentfeedback;
                    }
                } else {
                    console.warn('[GMK] assign detail not loaded', response.data);
                }
            } catch (e) {
                console.error('[GMK] assign detail error', e);
            } finally {
                this.loadingAssignDetails = false;
            }
        },
        formatDate(timestamp, full = false) {
            if (!timestamp) return '-';
            const date = new Date(timestamp * 1000);
            return full ? date.toLocaleString() : date.toLocaleDateString();
        },
        openFile(url) {
            window.open(url, '_blank');
        },
        downloadFile(file) {
            const a = document.createElement('a');
            a.href = file.fileurl;
            a.download = file.filename || 'archivo';
            a.target = '_blank';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        },
        handleFileClick(file) {
            if (this.selectedFile === file) {
                this.selectedFile = null;
                return;
            }
            this.previewLoading = false;
            this.previewError = false;
            this.officeContent = '';
            this.selectedFile = file;
            if (this.isOffice(file)) {
                this.renderOffice(file);
            }
        },
        isPreviewable(file) {
            return this.isImage(file) || this.isPDF(file) || this.isOffice(file);
        },
        isImage(file) {
            return (file.mimetype && file.mimetype.includes('image')) ||
                /\.(jpg|jpeg|png|gif|webp)$/i.test(file.filename);
        },
        isPDF(file) {
            return (file.mimetype === 'application/pdf') ||
                /\.pdf$/i.test(file.filename);
        },
        proxyUrl(file) {
            if (!file || !file.fileurl) return null;
            const base = (window.wwwroot || '') + '/local/grupomakro_core/pages/file_proxy.php';
            return base + '?url=' + encodeURIComponent(file.fileurl);
        },
        isWord(file) {
            return (file.mimetype && (file.mimetype.includes('word') || file.mimetype.includes('officedocument.wordprocessing'))) ||
                /\.docx?m?$/i.test(file.filename);
        },
        isExcel(file) {
            return (file.mimetype && (file.mimetype.includes('spreadsheet') || file.mimetype.includes('excel') || file.mimetype.includes('officedocument.spreadsheet'))) ||
                /\.xlsx?m?$/i.test(file.filename);
        },
        isPowerPoint(file) {
            return (file.mimetype && (file.mimetype.includes('powerpoint') || file.mimetype.includes('presentation') || file.mimetype.includes('officedocument.presentation'))) ||
                /\.pptx?m?$/i.test(file.filename);
        },
        isOffice(file) {
            return this.isWord(file) || this.isExcel(file) || this.isPowerPoint(file);
        },
        // All Office formats use the PHP server-side converter — no CDN, no CSP issues.
        async renderOffice(file) {
            this.previewLoading = true;
            this.previewError = false;
            this.officeContent = '';
            try {
                const url = this.proxyUrl(file) + '&convert=html';
                const response = await fetch(url);
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const html = await response.text();
                this.officeContent = html || '<p class="grey--text">El documento está vacío.</p>';
            } catch (e) {
                console.error('[GMK] office render failed', e);
                this.previewError = true;
            } finally {
                this.previewLoading = false;
            }
        },
        getOfficeErrorColor(file) {
            if (this.isWord(file))       return 'blue lighten-2';
            if (this.isExcel(file))      return 'green lighten-2';
            if (this.isPowerPoint(file)) return 'orange lighten-2';
            return 'grey lighten-2';
        },
        getFileIcon(file) {
            if (this.isImage(file))       return 'mdi-image-outline';
            if (this.isPDF(file))         return 'mdi-file-pdf-box';
            if (this.isWord(file))        return 'mdi-file-word-outline';
            if (this.isExcel(file))       return 'mdi-file-excel-outline';
            if (this.isPowerPoint(file))  return 'mdi-file-powerpoint-outline';
            return 'mdi-file-document-outline';
        },
        getFileIconColor(file) {
            if (this.isImage(file))       return 'purple lighten-4';
            if (this.isPDF(file))         return 'red lighten-4';
            if (this.isWord(file))        return 'blue lighten-4';
            if (this.isExcel(file))       return 'green lighten-4';
            if (this.isPowerPoint(file))  return 'orange lighten-4';
            return 'primary lighten-4';
        },
        skip() {
            if (this.currentTask.modname === 'quiz' && !this.isLastQuestion) {
                this.selectedSlotIndex++;
            } else {
                this.loadNext();
            }
        },
        async saveAndNext() {
            if (!this.$refs.form.validate()) return;

            this.saving = true;
            this.saveError = null;
            try {
                let action = 'local_grupomakro_save_grade';
                let args = {};

                if (this.currentTask.modname === 'quiz') {
                    action = 'local_grupomakro_save_quiz_grading';
                    args = {
                        attemptid: this.currentTask.id,
                        slot: this.currentQuestion.slot,
                        mark: this.grade,
                        comment: this.feedback
                    };
                } else {
                    args = {
                        assignmentid: this.currentTask.assignmentid,
                        studentid: this.currentTask.studentid,
                        grade: this.grade,
                        feedback: this.feedback
                    };
                }

                const response = await axios.post(window.wsUrl, {
                    action: action,
                    args: JSON.stringify(args),
                    sesskey: M.cfg.sesskey
                });

                if (response.data.status === 'success') {
                    if (this.currentTask.modname === 'quiz') {
                        // Update local question state
                        this.currentQuestion.needsgrading = false;
                        this.currentQuestion.currentgrade = this.grade;

                        if (!this.isLastQuestion) {
                            this.selectedSlotIndex++;
                            this.grade = this.currentQuestion.currentgrade || '';
                            this.feedback = '';
                        } else {
                            // Capture next task BEFORE emitting grade-saved, so the parent's
                            // optimistic filter doesn't affect allTasks before loadNext reads it.
                            const savedId   = this.currentTask.id;
                            const nextIdx   = this.currentIndex + 1;
                            const nextTask  = this.allTasks[nextIdx] || null;
                            this.$emit('grade-saved', savedId);
                            if (nextTask) {
                                this.$emit('update:task', nextTask);
                            } else {
                                this.close();
                            }
                        }
                    } else {
                        const savedId   = this.currentTask.id;
                        const nextIdx   = this.currentIndex + 1;
                        const nextTask  = this.allTasks[nextIdx] || null;
                        this.$emit('grade-saved', savedId);
                        if (nextTask) {
                            this.$emit('update:task', nextTask);
                        } else {
                            this.close();
                        }
                    }
                } else {
                    this.saveError = response.data.message || 'Error desconocido del servidor.';
                    console.error("Save Error Response:", response.data);
                }
            } catch (error) {
                console.error(error);
                this.saveError = error.message || 'Error de conexión con el servidor.';
            } finally {
                this.saving = false;
            }
        },
        async reopenSubmission() {
            if (!this.currentTask.assignmentid || !this.currentTask.studentid) {
                this.reopenError = 'No se pudo identificar la tarea o el estudiante.';
                return;
            }
            this.reopening = true;
            this.reopenError = '';
            this.reopenSuccess = '';
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_reopen_assignment',
                    args: JSON.stringify({
                        assignmentid: this.currentTask.assignmentid,
                        studentid: this.currentTask.studentid,
                    }),
                    sesskey: M.cfg.sesskey,
                });
                if (response.data && response.data.status === 'success') {
                    this.reopenSuccess = response.data.message || 'Reenvío habilitado correctamente.';
                } else {
                    this.reopenError = (response.data && response.data.message) || 'No se pudo reabrir la entrega.';
                }
            } catch (e) {
                this.reopenError = 'Error de conexión al servidor.';
                console.error('[GMK] reopen error', e);
            } finally {
                this.reopening = false;
            }
        },
        loadNext() {
            const nextIdx = this.currentIndex + 1;
            if (nextIdx < this.allTasks.length) {
                // The watch on 'task' will trigger everything
                this.$emit('update:task', this.allTasks[nextIdx]);
            } else {
                this.close();
            }
        }
    }
};

Vue.component('quick-grader', QuickGrader);
