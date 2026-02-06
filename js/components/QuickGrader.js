const QuickGrader = {
    props: {
        task: { type: Object, required: true },
        allTasks: { type: Array, required: true }
    },
    template: `
        <v-dialog v-model="visible" fullscreen hide-overlay transition="dialog-bottom-transition">
            <v-card class="d-flex flex-column h-100 grey lighten-5">
                <!-- Toolbar -->
                <v-toolbar dark color="primary" dense>
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
                                <div v-if="currentTask.files && currentTask.files.length > 0">
                                    <h3 class="text-subtitle-1 font-weight-bold mb-2">Archivos Adjuntos:</h3>
                                    <v-row>
                                        <v-col v-for="(file, i) in currentTask.files" :key="i" cols="12" sm="6" md="4">
                                            <v-card outlined ripple @click="openFile(file.fileurl)">
                                                <v-list-item>
                                                    <v-list-item-avatar tile color="primary lighten-4">
                                                        <v-icon color="primary">mdi-file-document-outline</v-icon>
                                                    </v-list-item-avatar>
                                                    <v-list-item-content>
                                                        <v-list-item-title class="text-caption font-weight-medium text-truncate">
                                                            {{ file.filename }}
                                                        </v-list-item-title>
                                                        <v-list-item-subtitle class="text-caption blue--text">Descargar</v-list-item-subtitle>
                                                    </v-list-item-content>
                                                </v-list-item>
                                            </v-card>
                                        </v-col>
                                    </v-row>
                                </div>
                                <div v-else class="text-center pa-4 grey--text">No hay archivos adjuntos.</div>
                            </v-card-text>
                        </v-card>

                        <!-- QUIZ VIEW -->
                        <div v-if="currentTask.modname === 'quiz'" class="h-100 d-flex flex-column">
                            <v-skeleton-loader v-if="loadingQuiz" type="article, actions"></v-skeleton-loader>
                            
                            <template v-else>
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
            selectedSlotIndex: 0
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
    methods: {
        close() {
            this.visible = false;
            this.$emit('close');
        },
        resetForm() {
            this.grade = '';
            this.feedback = '';
            if (this.$refs.form) this.$refs.form.resetValidation();
        },
        async fetchQuizData() {
            this.loadingQuiz = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_quiz_attempt_data',
                    attemptid: this.currentTask.id,
                    sesskey: M.cfg.sesskey
                });

                if (response.data.status === 'success') {
                    this.quizData = response.data.data;
                    // Find first question that needs grading
                    const firstNeeds = this.quizData.questions.findIndex(q => q.needsgrading);
                    this.selectedSlotIndex = firstNeeds !== -1 ? firstNeeds : 0;
                }
            } catch (e) {
                console.error(e);
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
                    alert('Error guardando: ' + (response.data.message || 'Desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('Error de conexión');
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
