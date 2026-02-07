const QuizCreationWizard = {
    template: `
    <v-dialog v-model="dialog" persistent max-width="900px">
        <v-card class="d-flex flex-column" style="height: 80vh;">
            <v-toolbar color="primary" dark flat>
                <v-toolbar-title>Creador de Cuestionarios Avanzado</v-toolbar-title>
                <v-spacer></v-spacer>
                <v-btn icon @click="closeDialog">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-toolbar>

            <v-stepper v-model="step" vertical class="flex-grow-1 overflow-y-auto">
                <!-- STEP 1: General Info -->
                <v-stepper-step :complete="step > 1" step="1">
                    Información General
                    <small>Nombre y descripción del cuestionario</small>
                </v-stepper-step>

                <v-stepper-content step="1">
                    <v-form ref="form1" v-model="valid1">
                        <v-text-field
                            v-model="quiz.name"
                            label="Título del Cuestionario"
                            :rules="[v => !!v || 'El nombre es requerido']"
                            variant="outlined"
                            prepend-inner-icon="mdi-format-title"
                        ></v-text-field>
                        
                        <v-textarea
                            v-model="quiz.intro"
                            label="Instrucciones / Descripción"
                            variant="outlined"
                            rows="3"
                        ></v-textarea>

                        <v-combobox
                            v-model="quiz.tags"
                            :items="courseTags"
                            label="Etiquetas (opcional)"
                            multiple
                            chips
                            small-chips
                            variant="outlined"
                        ></v-combobox>

                        <div class="d-flex justify-end mt-4">
                            <v-btn color="primary" @click="nextStep(1)" :disabled="!valid1">
                                Siguiente
                                <v-icon right>mdi-arrow-right</v-icon>
                            </v-btn>
                        </div>
                    </v-form>
                </v-stepper-content>

                <!-- STEP 2: Timing & Limits -->
                <v-stepper-step :complete="step > 2" step="2">
                    Disponibilidad y Tiempo
                    <small>Fechas de apertura, cierre y límite de tiempo</small>
                </v-stepper-step>

                <v-stepper-content step="2">
                     <v-row>
                        <v-col cols="12" md="6">
                            <v-menu
                                v-model="menuOpenDate"
                                :close-on-content-click="false"
                                transition="scale-transition"
                                offset-y
                                min-width="auto">
                                <template v-slot:activator="{ on, attrs }">
                                    <v-text-field
                                        v-model="quiz.dateOpen"
                                        label="Fecha de Apertura"
                                        prepend-icon="mdi-calendar-start"
                                        readonly
                                        v-bind="attrs"
                                        v-on="on"
                                    ></v-text-field>
                                </template>
                                <v-date-picker v-model="quiz.dateOpen" @input="menuOpenDate = false"></v-date-picker>
                            </v-menu>
                        </v-col>
                        <v-col cols="12" md="6">
                             <v-text-field
                                v-model="quiz.timeOpen"
                                label="Hora de Apertura"
                                type="time"
                                prepend-icon="mdi-clock-start"
                             ></v-text-field>
                        </v-col>
                     </v-row>

                     <v-row>
                        <v-col cols="12" md="6">
                            <v-menu
                                v-model="menuCloseDate"
                                :close-on-content-click="false"
                                transition="scale-transition"
                                offset-y
                                min-width="auto">
                                <template v-slot:activator="{ on, attrs }">
                                    <v-text-field
                                        v-model="quiz.dateClose"
                                        label="Fecha de Cierre"
                                        prepend-icon="mdi-calendar-end"
                                        readonly
                                        v-bind="attrs"
                                        v-on="on"
                                    ></v-text-field>
                                </template>
                                <v-date-picker v-model="quiz.dateClose" @input="menuCloseDate = false"></v-date-picker>
                            </v-menu>
                        </v-col>
                        <v-col cols="12" md="6">
                             <v-text-field
                                v-model="quiz.timeClose"
                                label="Hora de Cierre"
                                type="time"
                                prepend-icon="mdi-clock-end"
                             ></v-text-field>
                        </v-col>
                     </v-row>

                     <v-divider class="my-3"></v-divider>

                     <v-row>
                        <v-col cols="12" md="6">
                             <v-switch
                                v-model="quiz.enableTimeLimit"
                                label="Habilitar Límite de Tiempo"
                                color="primary"
                             ></v-switch>
                        </v-col>
                        <v-col cols="12" md="6" v-if="quiz.enableTimeLimit">
                             <v-text-field
                                v-model.number="quiz.timeLimitMinutes"
                                label="Límite en Minutos"
                                type="number"
                                suffix="min"
                                min="1"
                             ></v-text-field>
                        </v-col>
                     </v-row>

                     <div class="d-flex justify-space-between mt-4">
                        <v-btn text @click="step = 1">Atrás</v-btn>
                        <v-btn color="primary" @click="nextStep(2)">
                            Siguiente
                            <v-icon right>mdi-arrow-right</v-icon>
                        </v-btn>
                     </div>
                </v-stepper-content>

                <!-- STEP 3: Attempts & Grading -->
                <v-stepper-step :complete="step > 3" step="3">
                    Calificación e Intentos
                    <small>Cómo se evaluará al estudiante</small>
                </v-stepper-step>

                <v-stepper-content step="3">
                    <v-row>
                        <v-col cols="12" md="6">
                             <v-select
                                v-model="quiz.attempts"
                                :items="attemptOptions"
                                label="Intentos Permitidos"
                                prepend-icon="mdi-counter"
                             ></v-select>
                        </v-col>
                        <v-col cols="12" md="6">
                             <v-select
                                v-model="quiz.grademethod"
                                :items="gradingMethods"
                                label="Método de Calificación"
                                prepend-icon="mdi-school"
                             ></v-select>
                        </v-col>
                    </v-row>

                     <div class="d-flex justify-space-between mt-4">
                        <v-btn text @click="step = 2">Atrás</v-btn>
                        <v-btn color="success" @click="createQuiz" :loading="saving">
                            Crear Cuestionario
                            <v-icon right>mdi-check</v-icon>
                        </v-btn>
                     </div>
                </v-stepper-content>
                <v-dialog v-model="showErrorDialog" max-width="600px">
                    <v-card>
                        <v-card-title class="headline error--text">Error</v-card-title>
                        <v-card-text>
                            <p>Ha ocurrido un error al crear el cuestionario. Detalle técnico:</p>
                            <v-textarea
                                v-model="errorDetails"
                                readonly
                                outlined
                                rows="5"
                                auto-grow
                            ></v-textarea>
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer></v-spacer>
                            <v-btn color="primary" text @click="showErrorDialog = false">Cerrar</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
            </v-stepper>
        </v-card>
    </v-dialog>
    `,
    props: {
        visible: { type: Boolean, default: false },
        classId: { type: Number, required: true }
    },
    data() {
        return {
            dialog: this.visible,
            step: 1,
            valid1: false,
            saving: false,
            menuOpenDate: false,
            menuCloseDate: false,
            showErrorDialog: false,
            errorDetails: '',
            quiz: {
                name: '',
                intro: '',
                tags: [],
                dateOpen: new Date().toISOString().substr(0, 10),
                timeOpen: '00:00',
                dateClose: new Date(Date.now() + 7 * 86400000).toISOString().substr(0, 10), // +7 days
                timeClose: '23:59',
                enableTimeLimit: false,
                timeLimitMinutes: 60,
                attempts: 1,
                grademethod: 1 // Highest grade
            },
            courseTags: [],
            attemptOptions: [
                { text: '1 Intento', value: 1 },
                { text: '2 Intentos', value: 2 },
                { text: '3 Intentos', value: 3 },
                { text: 'Ilimitado', value: 0 }
            ],
            gradingMethods: [
                { text: 'Calificación más alta', value: 1 },
                { text: 'Promedio de calificaciones', value: 2 },
                { text: 'Primer intento', value: 3 },
                { text: 'Último intento', value: 4 }
            ]
        };
    },
    watch: {
        visible(val) {
            this.dialog = val;
            if (val) this.step = 1;
        },
        dialog(val) {
            if (!val) this.$emit('close');
        }
    },
    created() {
        this.fetchCourseTags();
    },
    methods: {
        closeDialog() {
            this.dialog = false;
        },
        nextStep(currentStep) {
            if (currentStep === 1) {
                if (this.$refs.form1.validate()) this.step = 2;
            } else if (currentStep === 2) {
                this.step = 3;
            }
        },
        async createQuiz() {
            this.saving = true;
            try {
                // Prepare timestamps
                const startDT = new Date(`${this.quiz.dateOpen}T${this.quiz.timeOpen}`);
                const endDT = new Date(`${this.quiz.dateClose}T${this.quiz.timeClose}`);

                const payload = {
                    classid: this.classId,
                    type: 'quiz',
                    name: this.quiz.name,
                    intro: this.quiz.intro,
                    tags: this.quiz.tags,
                    // Extra params mapped to backend expectations
                    timeopen: Math.floor(startDT.getTime() / 1000),
                    timeclose: Math.floor(endDT.getTime() / 1000),
                    timelimit: this.quiz.enableTimeLimit ? (this.quiz.timeLimitMinutes * 60) : 0,
                    attempts: this.quiz.attempts,
                    grademethod: this.quiz.grademethod,
                    save_as_template: false,
                    duedate: 0, // Not used for quiz directly usually, but can fail check if missing
                    gradecat: 0 // Optional
                };

                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_create_express_activity',
                    args: payload,
                    ...window.wsStaticParams
                });

                if (response.data.status === 'success') {
                    this.$emit('success');
                    this.closeDialog();
                } else {
                    this.errorDetails = 'Error: ' + response.data.message;
                    if (response.data.debuginfo) {
                        this.errorDetails += '\n\nDebug Info:\n' + response.data.debuginfo;
                    }
                    this.showErrorDialog = true;
                }
            } catch (error) {
                console.error(error);
                this.errorDetails = 'Error de conexión o servidor: ' + error.message;
                this.showErrorDialog = true;
            } finally {
                this.saving = false;
            }
        },
        async fetchCourseTags() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_course_tags',
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.courseTags = response.data.tags;
                }
            } catch (error) {
                console.error('Error fetching tags:', error);
            }
        }
    }
};

Vue.component('quiz-creation-wizard', QuizCreationWizard);
window.QuizCreationWizard = QuizCreationWizard;
