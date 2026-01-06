/**
 * QuickGrader.js
 * Modal component to efficiently grade submissions.
 */

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
                        Calificando: {{ currentTask.studentname }} - {{ currentTask.assignmentname }}
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <div class="text-caption mr-4">
                        {{ currentIndex + 1 }} de {{ allTasks.length }}
                    </div>
                </v-toolbar>

                <div class="d-flex flex-grow-1 overflow-hidden" style="height: calc(100vh - 48px);">
                    <!-- Left Panel: Submission Content -->
                    <div class="flex-grow-1 overflow-y-auto pa-4" style="background: #e0e0e0;">
                        <v-card class="mx-auto" max-width="900" min-height="100%">
                            <v-card-title>Entrega</v-card-title>
                            <v-card-subtitle>
                                Enviado el: {{ formatDate(currentTask.submissiontime, true) }}
                            </v-card-subtitle>
                            <v-divider></v-divider>
                            <v-card-text class="pa-4">
                                <!-- Files -->
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
                                                        <v-list-item-title class="text-caption font-weight-medium">
                                                            {{ file.filename }}
                                                        </v-list-item-title>
                                                        <v-list-item-subtitle class="text-caption blue--text">
                                                            Click para descargar
                                                        </v-list-item-subtitle>
                                                    </v-list-item-content>
                                                </v-list-item>
                                            </v-card>
                                        </v-col>
                                    </v-row>
                                </div>
                                <div v-else class="text-center pa-4 grey--text">
                                    No hay archivos adjuntos.
                                </div>

                                <!-- If text submission logic exists, add here. For now assume file based. -->
                            </v-card-text>
                        </v-card>
                    </div>

                    <!-- Right Panel: Grading Form -->
                    <div class="white elevation-4 d-flex flex-column" style="width: 350px; z-index: 2;">
                         <v-card-title class="subtitle-1 font-weight-bold">Evaluar</v-card-title>
                         <v-divider></v-divider>
                         
                         <div class="pa-4 flex-grow-1 overflow-y-auto">
                             <v-form ref="form" v-model="valid">
                                 <v-text-field
                                     v-model.number="grade"
                                     label="Calificación (0-100)"
                                     type="number"
                                     outlined
                                     :rules="[v => (v >= 0 && v <= 100) || 'Debe ser entre 0 y 100', v => !!v || 'Requerido']"
                                     required
                                 ></v-text-field>

                                 <v-textarea
                                     v-model="feedback"
                                     label="Comentarios de Retroalimentación"
                                     outlined
                                     rows="6"
                                     placeholder="Escribe comentarios para el estudiante..."
                                 ></v-textarea>
                             </v-form>
                         </div>

                         <v-divider></v-divider>
                         <div class="pa-4 grey lighten-5">
                             <v-btn block color="primary" large :loading="saving" @click="saveAndNext" :disabled="!valid">
                                 Guardar y Siguiente
                                 <v-icon right>mdi-skip-next</v-icon>
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
            currentTask: {}, // Local copy
            grade: '',
            feedback: '',
            saving: false,
            valid: false
        }
    },
    computed: {
        currentIndex() {
            return this.allTasks.findIndex(t => t.id === this.currentTask.id);
        }
    },
    watch: {
        task: {
            immediate: true,
            handler(val) {
                if (val) {
                    this.currentTask = val;
                    this.resetForm();
                }
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
            // Reset validation state
            if (this.$refs.form) this.$refs.form.resetValidation();
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
            this.loadNext();
        },
        async saveAndNext() {
            if (!this.$refs.form.validate()) return;

            this.saving = true;
            try {
                const args = {
                    assignmentid: this.currentTask.assignmentid,
                    studentid: this.currentTask.studentid,
                    grade: this.grade,
                    feedback: this.feedback
                };

                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_save_grade',
                    args: JSON.stringify(args),
                    sesskey: M.cfg.sesskey
                });

                if (response.data.status === 'success') {
                    this.$emit('grade-saved', this.currentTask.id);
                    // Move to next
                    this.loadNext();
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
                this.currentTask = this.allTasks[nextIdx];
                this.resetForm();
            } else {
                alert('¡Felicidades! Has completado todas las calificaciones de la lista actual.');
                this.close();
            }
        }
    }
};

Vue.component('quick-grader', QuickGrader);
