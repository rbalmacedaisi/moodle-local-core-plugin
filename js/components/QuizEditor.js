const QuizEditor = {
    template: `
        <v-app style="background: transparent; min-height: auto;">
            <!-- Main Content Area -->
            <v-main class="pa-0" style="background: transparent;">
                <v-container class="pa-4 mx-auto" style="max-width: 1200px;">
                    
                    <!-- Editor Card -->
                    <v-card class="rounded-lg elevation-1 mb-4">
                        <v-toolbar flat color="white" class="border-bottom px-4">
                            <v-btn icon class="mr-2" @click="$emit('back')">
                                <v-icon>mdi-arrow-left</v-icon>
                            </v-btn>
                            <v-icon color="primary" class="mr-3" large>mdi-file-document-edit-outline</v-icon>
                            <div>
                                <v-toolbar-title class="subtitle-1 font-weight-bold text-uppercase grey--text text--darken-2">
                                    Gestor de Preguntas
                                </v-toolbar-title>
                                <div class="caption grey--text">Administre las preguntas de este cuestionario</div>
                            </div>
                            <v-spacer></v-spacer>
                            
                            <v-btn small text color="secondary" class="mr-2" disabled>
                                <v-icon left small>mdi-bank</v-icon> Banco (Pronto)
                            </v-btn>
                            <v-btn color="primary" depressed @click="showAddQuestionDialog = true">
                                <v-icon left>mdi-plus</v-icon> Nueva Pregunta
                            </v-btn>
                        </v-toolbar>

                        <v-card-text class="pa-0">
                            <v-skeleton-loader v-if="loading" type="list-item@3" class="pa-4"></v-skeleton-loader>
                            
                            <div v-else-if="questions.length === 0" class="text-center py-10 grey--text">
                                <v-icon size="64" color="grey lighten-3">mdi-clipboard-text-outline</v-icon>
                                <div class="mt-2 body-1">No hay preguntas en este cuestionario.</div>
                                <v-btn text color="primary" class="mt-2 font-weight-bold" @click="showAddQuestionDialog = true">
                                    Comenzar ahora
                                </v-btn>
                            </div>
                            
                            <v-list v-else two-line class="pa-0">
                                <draggable v-model="questions" @end="updateOrder">
                                    <v-list-item v-for="(q, index) in questions" :key="q.id" class="px-4 py-2 border-bottom hover-bg">
                                        <v-list-item-avatar color="blue lighten-5" class="blue--text font-weight-bold rounded-lg" size="40">
                                            {{ index + 1 }}
                                        </v-list-item-avatar>
                                        
                                        <v-list-item-content>
                                            <v-list-item-title class="font-weight-bold text-subtitle-1 mb-1">{{ q.name }}</v-list-item-title>
                                            <v-list-item-subtitle class="grey--text text--darken-1">
                                                <v-chip x-small label color="blue lighten-5" text-color="blue" class="mr-2 font-weight-bold">{{ questionTypeLabel(q.qtype) }}</v-chip>
                                                <span v-html="q.questiontext" class="text-truncate d-inline-block" style="max-width: 600px; vertical-align: middle;"></span>
                                            </v-list-item-subtitle>
                                        </v-list-item-content>
                                        
                                        <v-list-item-action class="flex-row">
                                            <v-tooltip bottom>
                                                <template v-slot:activator="{ on, attrs }">
                                                    <v-btn icon color="grey" class="mr-1" v-bind="attrs" v-on="on" disabled>
                                                        <v-icon>mdi-pencil</v-icon>
                                                    </v-btn>
                                                </template>
                                                <span>Editar (Pronto)</span>
                                            </v-tooltip>
                                            <v-tooltip bottom>
                                                <template v-slot:activator="{ on, attrs }">
                                                    <v-btn icon color="red lighten-2" v-bind="attrs" v-on="on" @click="removeQuestion(q)">
                                                        <v-icon>mdi-delete</v-icon>
                                                    </v-btn>
                                                </template>
                                                <span>Eliminar</span>
                                            </v-tooltip>
                                        </v-list-item-action>
                                    </v-list-item>
                                </draggable>
                            </v-list>
                        </v-card-text>
                    </v-card>
                </v-container>
            </v-main>
            
            <!-- Add Question Dialog -->
            <v-dialog v-model="showAddQuestionDialog" max-width="600px">
                <v-card>
                    <v-card-title>Crear Nueva Pregunta</v-card-title>
                    <v-card-text>
                        <v-select label="Tipo de Pregunta" :items="questionTypes" v-model="newQuestion.type" outlined></v-select>
                        
                        <v-text-field label="Nombre de la Pregunta" v-model="newQuestion.name" outlined dense></v-text-field>
                        <v-textarea label="Enunciado de la Pregunta" v-model="newQuestion.text" outlined rows="3"></v-textarea>
                        
                        <v-row>
                            <v-col cols="6">
                                <v-text-field label="Puntuación por defecto" v-model="newQuestion.defaultmark" type="number" outlined dense></v-text-field>
                            </v-col>
                        </v-row>

                        <v-divider class="mb-4"></v-divider>

                        <!-- True/False Specific -->
                        <div v-if="newQuestion.type === 'truefalse'">
                           <h3>Respuesta Correcta</h3>
                           <v-radio-group v-model="newQuestion.correctAnswer" row>
                                <v-radio label="Verdadero" value="1"></v-radio>
                                <v-radio label="Falso" value="0"></v-radio>
                           </v-radio-group>
                        </div>
                        
                        <!-- Essay / Description -->
                        <div v-else-if="newQuestion.type === 'essay' || newQuestion.type === 'description'">
                            <v-alert type="info" text dense v-if="newQuestion.type === 'essay'">
                                El alumno deberá escribir una respuesta libre. Se calificará manualmente.
                            </v-alert>
                             <v-alert type="info" text dense v-if="newQuestion.type === 'description'">
                                Solo muestra texto/imagen. No requiere respuesta.
                            </v-alert>
                        </div>

                        <!-- Match Specific -->
                        <div v-else-if="newQuestion.type === 'match'">
                            <v-alert type="info" dense text small>
                                Ingrese al menos dos pares de preguntas y respuestas.
                            </v-alert>
                            <div v-for="(subq, i) in newQuestion.subquestions" :key="i" class="mb-4">
                                <v-card outlined class="pa-3">
                                    <div class="d-flex justify-space-between caption grey--text text-uppercase font-weight-bold mb-1">
                                        Par {{ i + 1 }}
                                        <v-btn icon x-small color="red" @click="removeSubQuestion(i)" v-if="newQuestion.subquestions.length > 2">
                                            <v-icon>mdi-close</v-icon>
                                        </v-btn>
                                    </div>
                                    <v-text-field label="Pregunta" v-model="subq.text" outlined dense hide-details class="mb-2"></v-text-field>
                                    <v-text-field label="Respuesta correspondiente" v-model="subq.answer" outlined dense hide-details></v-text-field>
                                </v-card>
                            </div>
                            <v-btn small text color="primary" @click="addSubQuestion" class="mt-1">
                                <v-icon left>mdi-plus</v-icon> Agregar otro par
                            </v-btn>
                            <v-divider class="my-4"></v-divider>
                            <v-checkbox v-model="newQuestion.shuffleanswers" label="Barajar respuestas" dense></v-checkbox>
                        </div>

                        <!-- Numerical Specific -->
                        <div v-else-if="newQuestion.type === 'numerical'">
                            <v-alert type="info" dense text small>
                                La respuesta correcta debe tener una calificación del 100%. Puede añadir rangos de tolerancia.
                            </v-alert>
                            <div class="d-flex justify-space-between align-center mb-2">
                                <h3>Respuestas Numéricas</h3>
                                <v-btn small text color="primary" @click="addAnswerChoice"><v-icon left>mdi-plus</v-icon> Agregar Respuesta</v-btn>
                            </div>
                            <v-card outlined v-for="(ans, i) in newQuestion.answers" :key="i" class="mb-2 pa-2">
                                <v-row dense align="center">
                                    <v-col cols="12" md="4">
                                        <v-text-field label="Valor Correcto" v-model="newQuestion.answers[i].text" type="number" step="any" hide-details dense></v-text-field>
                                    </v-col>
                                    <v-col cols="6" md="3">
                                        <v-text-field label="Tolerancia (±)" v-model="newQuestion.answers[i].tolerance" type="number" step="any" hide-details dense></v-text-field>
                                    </v-col>
                                    <v-col cols="6" md="3">
                                        <v-select label="Calificación" :items="gradeOptions" v-model="newQuestion.answers[i].fraction" hide-details dense></v-select>
                                    </v-col>
                                    <v-col cols="6" md="2" class="text-right">
                                        <v-btn icon color="red" small @click="removeAnswerChoice(i)"><v-icon>mdi-delete</v-icon></v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>
                            <div class="mt-4">
                                <h4>Unidades (Opcional)</h4>
                                <v-row dense>
                                    <v-col cols="6">
                                        <v-text-field label="Unidad (ej: kg)" v-model="newQuestion.unit" dense outlined></v-text-field>
                                    </v-col>
                                    <v-col cols="6">
                                        <v-select label="Penalización por unidad" v-model="newQuestion.unitpenalty" :items="[0, 0.1, 0.2, 0.5, 1]" dense outlined></v-select>
                                    </v-col>
                                </v-row>
                            </div>
                        </div>

                         <!-- Gap Select / DD to Text -->
                        <div v-else-if="newQuestion.type === 'gapselect' || newQuestion.type === 'ddwtos'">
                            <v-alert type="info" dense text small class="mb-2">
                                En el enunciado, use <code>[[1]]</code>, <code>[[2]]</code>, etc. para indicar dónde van las opciones.
                            </v-alert>
                            <div class="d-flex justify-space-between align-center mb-2">
                                <h3>Opciones (Markers)</h3>
                                <v-btn small text color="primary" @click="addAnswerChoice"><v-icon left>mdi-plus</v-icon> Agregar Opción</v-btn>
                            </div>
                            <v-card outlined v-for="(ans, i) in newQuestion.answers" :key="i" class="mb-2 pa-2">
                                <v-row dense align="center">
                                    <v-col cols="1" class="text-center font-weight-bold grey--text">
                                        [[{{ i + 1 }}]]
                                    </v-col>
                                    <v-col cols="12" md="8">
                                        <v-text-field label="Texto de la opción" v-model="newQuestion.answers[i].text" hide-details dense></v-text-field>
                                    </v-col>
                                    <v-col cols="6" md="2">
                                        <v-select label="Grupo" v-model="newQuestion.answers[i].group" :items="[1,2,3,4,5]" hide-details dense></v-select>
                                    </v-col>
                                    <v-col cols="6" md="1" class="text-right">
                                        <v-btn icon color="red" small @click="removeAnswerChoice(i)"><v-icon>mdi-delete</v-icon></v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>
                            <v-checkbox v-model="newQuestion.shuffleanswers" label="Barajar opciones" dense></v-checkbox>
                        </div>

                        <!-- Fallback for complex types -->
                        <div v-else>
                            <v-alert type="warning" text border="left">
                                La configuración visual avanzada para <b>{{ questionTypeLabel(newQuestion.type) }}</b> está en desarrollo.<br>
                                Se creará con configuración básica por defecto.
                            </v-alert>
                        </div>

                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="showAddQuestionDialog = false">Cancelar</v-btn>
                        <v-btn color="primary" @click="saveQuestion" :loading="saving">Guardar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
            <!-- Error Dialog -->
            <v-dialog v-model="errorDialog" max-width="600px">
                <v-card>
                    <v-card-title class="headline error--text">Error al Guardar</v-card-title>
                    <v-card-text>
                        <p>Ocurrió un error al procesar su solicitud. Por favor, copie el siguiente detalle y repórtelo al soporte técnico:</p>
                        <v-textarea
                            v-model="errorDetails"
                            outlined
                            readonly
                            rows="10"
                            class="font-family-monospace"
                            style="font-family: monospace; font-size: 12px;"
                        ></v-textarea>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn color="primary" text @click="errorDialog = false">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-app>
    `,
    props: ['config', 'cmid'],
    data: () => ({
        loading: false,
        saving: false,
        questions: [],
        showAddQuestionDialog: false,
        questionTypes: [
            { text: 'Opción múltiple', value: 'multichoice' },
            { text: 'Verdadero/Falso', value: 'truefalse' },
            { text: 'Emparejamiento', value: 'match' },
            { text: 'Respuesta corta', value: 'shortanswer' },
            { text: 'Numérica', value: 'numerical' },
            { text: 'Ensayo', value: 'essay' },
            { text: 'Arrastrar y soltar marcadores', value: 'ddmarker' },
            { text: 'Arrastrar y soltar sobre texto', value: 'ddwtos' },
            { text: 'Arrastrar y soltar sobre una imagen', value: 'ddimageortext' },
            { text: 'Calculada', value: 'calculated' },
            { text: 'Calculada opción múltiple', value: 'calculatedmulti' },
            { text: 'Calculada simple', value: 'calculatedsimple' },
            { text: 'Elige la palabra perdida', value: 'gapselect' },
            { text: 'Emparejamiento aleatorio', value: 'randomsamatch' },
            { text: 'Respuestas anidadas (Cloze)', value: 'multianswer' },
            { text: 'Descripción', value: 'description' }
        ],
        newQuestion: {
            type: 'truefalse',
            name: '',
            text: '',
            defaultmark: 1,
            correctAnswer: '1',
            answers: [
                { text: '', fraction: 1.0, tolerance: 0, group: 1 },
                { text: '', fraction: 0.0, tolerance: 0, group: 1 }
            ],
            single: true,
            subquestions: [
                { text: '', answer: '' },
                { text: '', answer: '' }
            ],
            shuffleanswers: true,
            unit: '',
            unitpenalty: 0.1
        },
        gradeOptions: [
            { text: 'Ninguna (0%)', value: 0.0 },
            { text: '100%', value: 1.0 },
            { text: '90%', value: 0.9 },
            { text: '80%', value: 0.8 },
            { text: '75%', value: 0.75 },
            { text: '70%', value: 0.7 },
            { text: '66.66667%', value: 0.6666667 },
            { text: '60%', value: 0.6 },
            { text: '50%', value: 0.5 },
            { text: '40%', value: 0.4 },
            { text: '33.33333%', value: 0.3333333 },
            { text: '25%', value: 0.25 },
            { text: '20%', value: 0.2 },
            { text: '-50%', value: -0.5 },
            { text: '-100%', value: -1.0 }
        ],
        errorDialog: false,
        errorDetails: ''
    }),
    mounted() {
        this.fetchQuestions();
    },
    methods: {
        goHome() {
            window.location.href = '/local/grupomakro_core/pages/teacher_dashboard.php';
        },
        async fetchQuestions() {
            this.loading = true;
            try {
                // Use Moodle wwwroot passed in config
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_quiz_questions');

                // Prioritize prop cmid, fallback to config if exists (for standalone usage)
                const activeCmid = this.cmid || this.config.cmid;

                if (!activeCmid) {
                    // console.error('QuizEditor: No CMID provided');
                    return;
                }

                params.append('cmid', activeCmid);
                // Moodle requires sesskey usually for ajax, pass it if needed, though ajax.php seems open-ish or uses require_login
                if (this.config.sesskey) {
                    params.append('sesskey', this.config.sesskey);
                }

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.questions = response.data.questions;
                } else {
                    console.error('Error fetching questions:', response.data);
                }
            } catch (error) {
                console.error(error);
            } finally {
                this.loading = false;
            }
        },
        addAnswerChoice() {
            this.newQuestion.answers.push({ text: '', fraction: 0.0, tolerance: 0, group: 1 });
        },
        removeAnswerChoice(index) {
            this.newQuestion.answers.splice(index, 1);
        },
        addSubQuestion() {
            this.newQuestion.subquestions.push({ text: '', answer: '' });
        },
        removeSubQuestion(index) {
            this.newQuestion.subquestions.splice(index, 1);
        },
        resetNewQuestion() {
            this.newQuestion = {
                type: 'truefalse',
                name: '',
                text: '',
                defaultmark: 1,
                correctAnswer: '1',
                answers: [
                    { text: '', fraction: 1.0, tolerance: 0, group: 1 },
                    { text: '', fraction: 0.0, tolerance: 0, group: 1 }
                ],
                single: true,
                subquestions: [
                    { text: '', answer: '' },
                    { text: '', answer: '' }
                ],
                shuffleanswers: true,
                unit: '',
                unitpenalty: 0.1
            };
        },
        questionTypeLabel(type) {
            const t = this.questionTypes.find(x => x.value === type);
            return t ? t.text : type;
        },
        async saveQuestion() {
            this.saving = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_add_question');
                params.append('cmid', this.cmid || this.config.cmid);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                params.append('question_data', JSON.stringify(this.newQuestion));

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.showAddQuestionDialog = false;
                    this.fetchQuestions();
                    this.resetNewQuestion();
                } else {
                    console.error('Save failed. Response:', response);
                    console.error('Data:', response.data);

                    let msg = (response.data && response.data.message) ? response.data.message : 'Unknown Error';
                    let debugInfo = (response.data && response.data.debug) ? response.data.debug : '';
                    let raw = '';

                    if (typeof response.data !== 'object') {
                        raw = String(response.data);
                        msg = 'Respuesta inesperada del servidor (no JSON)';
                    }

                    this.errorDetails = `Mensaje: ${msg}\n\nDebug Info:\n${debugInfo}\n\nRaw Response (inicio):\n${raw.substring(0, 500)}`;
                    this.errorDialog = true;
                }
            } catch (error) {
                console.error(error);
                this.errorDetails = `Connection/JS Error:\n${error.toString()}`;
                this.errorDialog = true;
            } finally {
                this.saving = false;
            }
        },
        removeQuestion(q) {
            if (confirm('¿Eliminar pregunta del cuestionario? (No se borra del banco)')) {
                // To implement
            }
        },
        updateOrder() {
            // Reorder logic
        }
    }
};

if (typeof window !== 'undefined') {
    window.QuizEditor = QuizEditor; // Export Component

    // Standalone intialization (legacy support)
    window.QuizEditorApp = {
        init: function (config) {
            new Vue({
                el: '#quiz-editor-app',
                vuetify: new Vuetify(),
                data: {
                    initialConfig: config
                },
                components: {
                    'quiz-editor': QuizEditor
                }
            });
        }
    };
}
