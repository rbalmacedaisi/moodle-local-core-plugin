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
                            <v-card-subtitle>Enviado el: {{ formatDate(currentTask.submissiontime, true) }}</v-card-subtitle>
                            <v-divider></v-divider>
                            <v-card-text class="pa-4">
                                <!-- PREVIEW AREA -->
                                <v-fade-transition>
                                    <div v-if="selectedFile" class="preview-panel mb-4">
                                        <div class="d-flex justify-space-between align-center pa-2 grey lighten-3 border-bottom">
                                            <span class="text-caption font-weight-bold text-truncate mr-2">{{ selectedFile.filename }}</span>
                                            <v-btn icon x-small @click="selectedFile = null">
                                                <v-icon>mdi-close</v-icon>
                                            </v-btn>
                                        </div>
                                        <div class="preview-content-wrapper">
                                            <!-- Image Preview -->
                                            <v-img v-if="isImage(selectedFile)" :src="selectedFile.fileurl" contain max-height="600" class="grey lighten-4"></v-img>
                                            
                                            <!-- PDF Preview -->
                                            <iframe v-else-if="isPDF(selectedFile)" :src="selectedFile.fileurl" class="preview-iframe"></iframe>
                                            
                                            <!-- Word Preview (.docx) -->
                                            <div v-else-if="isWord(selectedFile)" class="pa-4 white docx-preview" v-html="docxContent || 'Cargando documento...'"></div>
                                            
                                            <!-- Generic / Not supported -->
                                            <div v-else class="pa-10 text-center grey lighten-4">
                                                <v-icon x-large color="grey lighten-1">mdi-file-eye-off</v-icon>
                                                <div class="mt-2 grey--text">Vista previa no disponible para este formato</div>
                                                <v-btn small color="primary" class="mt-4" @click="openFile(selectedFile.fileurl)">
                                                    Descargar para ver
                                                </v-btn>
                                            </div>
                                        </div>
                                    </div>
                                </v-fade-transition>

                                <div v-if="currentTask.files && currentTask.files.length > 0">
                                    <h3 class="text-subtitle-1 font-weight-bold mb-2">Archivos Adjuntos:</h3>
                                    <v-row>
                                        <v-col v-for="(file, i) in currentTask.files" :key="i" cols="12" sm="6" md="4">
                                            <v-card outlined ripple @click="handleFileClick(file)" :color="selectedFile === file ? 'primary lighten-5' : ''" :class="selectedFile === file ? 'border-primary' : ''">
                                                <v-list-item dense>
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
            // File Preview specifics
            selectedFile: null,
            docxContent: '',
            mammothLoaded: false
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
                    if (val.modname === 'quiz') {
                        this.fetchQuizData();
                    }
                }
            }
        },
        selectedSlotIndex(newIdx) {
            if (this.currentTask.modname === 'quiz' && this.quizData.questions[newIdx]) {
                const q = this.quizData.questions[newIdx];
                this.grade = q.currentgrade || '';
                this.feedback = ''; // We don't usually fetch existing feedback for speed, but could.
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
                    height: 70vh;
                    border: none;
                }
                .docx-preview {
                    font-family: 'Times New Roman', Times, serif;
                    line-height: 1.5;
                }
                .border-primary {
                    border: 1px solid var(--v-primary-base) !important;
                }
                .border-bottom {
                    border-bottom: 1px solid #eee;
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
            this.selectedFile = null;
            this.docxContent = '';
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
                        this.selectedSlotIndex = firstNeeds !== -1 ? firstNeeds : 0;
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
        formatDate(timestamp, full = false) {
            if (!timestamp) return '-';
            const date = new Date(timestamp * 1000);
            return full ? date.toLocaleString() : date.toLocaleDateString();
        },
        openFile(url) {
            window.open(url, '_blank');
        },
        handleFileClick(file) {
            if (this.selectedFile === file) {
                this.selectedFile = null;
                return;
            }
            this.selectedFile = file;
            if (this.isWord(file)) {
                this.renderDocx(file);
            }
        },
        isPreviewable(file) {
            return this.isImage(file) || this.isPDF(file) || this.isWord(file);
        },
        isImage(file) {
            return (file.mimetype && file.mimetype.includes('image')) ||
                /\.(jpg|jpeg|png|gif|webp)$/i.test(file.filename);
        },
        isPDF(file) {
            return (file.mimetype === 'application/pdf') ||
                /\.pdf$/i.test(file.filename);
        },
        isWord(file) {
            return (file.mimetype && file.mimetype.includes('word')) ||
                /\.docx$/i.test(file.filename);
        },
        getFileIcon(file) {
            if (this.isImage(file)) return 'mdi-image-outline';
            if (this.isPDF(file)) return 'mdi-file-pdf-box';
            if (this.isWord(file)) return 'mdi-file-word-outline';
            if (/\.(xls|xlsx)$/i.test(file.filename)) return 'mdi-file-excel-outline';
            if (/\.(ppt|pptx)$/i.test(file.filename)) return 'mdi-file-powerpoint-outline';
            return 'mdi-file-document-outline';
        },
        getFileIconColor(file) {
            if (this.isImage(file)) return 'purple lighten-4';
            if (this.isPDF(file)) return 'red lighten-4';
            if (this.isWord(file)) return 'blue lighten-4';
            if (/\.(xls|xlsx)$/i.test(file.filename)) return 'green lighten-4';
            return 'primary lighten-4';
        },
        async renderDocx(file) {
            this.docxContent = '';
            if (!this.mammothLoaded) {
                try {
                    await this.loadScript('https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.21/mammoth.browser.min.js');
                    this.mammothLoaded = true;
                } catch (e) {
                    console.error("Mammoth load failed", e);
                    this.docxContent = '<div class="pa-4 text-center red--text">Error al cargar visor de Word.</div>';
                    return;
                }
            }

            try {
                // Fetch file as blob
                const response = await fetch(file.fileurl);
                const arrayBuffer = await response.arrayBuffer();
                const result = await mammoth.convertToHtml({ arrayBuffer: arrayBuffer });
                this.docxContent = result.value;
            } catch (e) {
                console.error("Docx conversion failed", e);
                this.docxContent = '<div class="pa-4 text-center red--text">No se pudo convertir el documento para la vista previa.</div>';
            }
        },
        loadScript(src) {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
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
                            // Finish attempt review
                            this.$emit('grade-saved', this.currentTask.id);
                            this.loadNext();
                        }
                    } else {
                        this.$emit('grade-saved', this.currentTask.id);
                        this.loadNext();
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
