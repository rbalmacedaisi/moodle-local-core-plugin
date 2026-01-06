const QuizEditor = {
    template: `
         <v-card flat class="fill-height grey lighten-4">
            <!-- Global Header Replicated -->
            <v-app-bar color="white" elevate-on-scroll app clipped-left height="64" style="z-index: 100 !important;">
                <v-img src="https://lms.isi.edu.pa/pluginfile.php/1/theme_moove/logo/1698086745/Logo%20ISI%20-%20Slogan-01.png" max-height="50" max-width="150" contain class="mr-4"></v-img>
                <v-toolbar-title class="grey--text text--darken-2 font-weight-bold">ISI - Portal Docente</v-toolbar-title>
                <v-spacer></v-spacer>
                <v-btn text color="primary" href="/local/grupomakro_core/pages/teacher_dashboard.php"><v-icon left>mdi-view-dashboard</v-icon> Mi Inicio</v-btn>
                <v-btn text color="warning"><v-icon left>mdi-check-circle-outline</v-icon> Calificar</v-btn>
                <v-chip class="ml-2" color="primary" outlined pill>
                   <v-icon left>mdi-account-circle</v-icon> Mi Cuenta
                </v-chip>
            </v-app-bar>

            <!-- Main Content Area -->
            <v-main class="grey lighten-5">
                <v-container fluid class="pa-0 fill-height align-start">
                    <v-row no-gutters justify="center" class="fill-height w-100">
                        <v-col cols="12" md="10" lg="8" class="pa-4">
                            <!-- Question Manager Toolbar -->
                            <v-card flat class="rounded-lg mb-4">
                                <v-app-bar color="white" flat dense class="border-bottom">
                                    <v-icon left color="primary">mdi-format-list-checks</v-icon>
                                    <v-toolbar-title class="subtitle-1 font-weight-bold">Gestor de Preguntas del Cuestionario</v-toolbar-title>
                                    <v-spacer></v-spacer>
                                    <v-btn text small color="secondary" class="mr-2" disabled>
                                        <v-icon left small>mdi-bank</v-icon> Banco
                                    </v-btn>
                                    <v-btn color="primary" small depressed @click="showAddQuestionDialog = true">
                                        <v-icon left small>mdi-plus</v-icon> Nueva Pregunta
                                    </v-btn>
                                </v-app-bar>

            <v-card-text class="pt-4">                
                <v-skeleton-loader v-if="loading" type="list-item@3"></v-skeleton-loader>
                
                <div v-else-if="questions.length === 0" class="text-center py-10 grey--text">
                    <v-icon size="64" color="grey lighten-2">mdi-clipboard-text-outline</v-icon>
                    <div class="mt-2">No hay preguntas en este cuestionario.</div>
                    <v-btn text color="primary" class="mt-2" @click="showAddQuestionDialog = true">
                        Crear la primera pregunta
                    </v-btn>
                </div>
                
                <v-list v-else two-line class="pa-0">
                    <draggable v-model="questions" @end="updateOrder">
                        <v-list-item v-for="(q, index) in questions" :key="q.id" class="mb-1 white elevation-1 rounded-lg">
                            <v-list-item-avatar color="primary lighten-5" class="primary--text font-weight-bold">
                                {{ index + 1 }}
                            </v-list-item-avatar>
                            
                            <v-list-item-content>
                                <v-list-item-title class="font-weight-medium">{{ q.name }}</v-list-item-title>
                                <v-list-item-subtitle class="text--secondary caption">
                                    <v-chip x-small label outlined class="mr-2">{{ questionTypeLabel(q.qtype) }}</v-chip>
                                    {{ q.questiontext }}
                                </v-list-item-subtitle>
                            </v-list-item-content>
                            
                            <v-list-item-action>
                                <v-tooltip bottom>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn icon color="red lighten-2" small v-bind="attrs" v-on="on" @click="removeQuestion(q)">
                                            <v-icon small>mdi-delete</v-icon>
                                        </v-btn>
                                    </template>
                                    <span>Eliminar</span>
                                </v-tooltip>
                            </v-list-item-action>
                        </v-list-item>
                    </draggable>
                </v-list>
            </v-card-text>

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

                        <!-- MultiChoice / ShortAnswer -->
                        <div v-else-if="newQuestion.type === 'multichoice' || newQuestion.type === 'shortanswer'">
                            <div class="d-flex justify-space-between align-center mb-2">
                                <h3>Opciones de Respuesta</h3>
                                <v-btn small text color="primary" @click="addAnswerChoice"><v-icon left>mdi-plus</v-icon> Agregar Opción</v-btn>
                            </div>
                            
                            <v-card outlined v-for="(ans, i) in newQuestion.answers" :key="i" class="mb-2 pa-2">
                                <v-row dense align="center">
                                    <v-col cols="12" md="7">
                                        <v-text-field label="Respuesta" v-model="newQuestion.answers[i].text" hide-details dense></v-text-field>
                                    </v-col>
                                    <v-col cols="6" md="3">
                                        <v-select 
                                            label="Calificación" 
                                            :items="gradeOptions" 
                                            v-model="newQuestion.answers[i].fraction" 
                                            hide-details dense
                                        ></v-select>
                                    </v-col>
                                    <v-col cols="6" md="2" class="text-right">
                                        <v-btn icon color="red" small @click="removeAnswerChoice(i)"><v-icon>mdi-delete</v-icon></v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>
                            <v-checkbox v-if="newQuestion.type === 'multichoice'" v-model="newQuestion.single" label="Solo una respuesta correcta" dense></v-checkbox>
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
        </v-card>
    `,
    props: ['config'],
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
                { text: '', fraction: 1.0 },
                { text: '', fraction: 0.0 }
            ],
            single: true
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
        ]
    }),
    mounted() {
        this.fetchQuestions();
        // Aggressively hide Moodle sidebar
        setTimeout(() => {
            const selectors = [
                '#nav-drawer',
                '[data-region="drawer"]',
                '.drawer-option',
                '#page-header',
                '.secondary-navigation'
            ];
            selectors.forEach(s => {
                const els = document.querySelectorAll(s);
                els.forEach(el => el.style.setProperty('display', 'none', 'important'));
            });
            // Force full width
            const main = document.getElementById('region-main');
            if (main) {
                main.style.setProperty('width', '100%', 'important');
                main.style.setProperty('max-width', '100%', 'important');
            }
        }, 500);
    },
    methods: {
        async fetchQuestions() {
            this.loading = true;
            try {
                // Use Moodle wwwroot passed in config
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_quiz_questions');
                params.append('cmid', this.config.cmid);
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
            this.newQuestion.answers.push({ text: '', fraction: 0.0 });
        },
        removeAnswerChoice(index) {
            this.newQuestion.answers.splice(index, 1);
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
                params.append('cmid', this.config.cmid);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                params.append('question_data', JSON.stringify(this.newQuestion));

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.showAddQuestionDialog = false;
                    this.fetchQuestions();
                    // Reset
                    this.newQuestion.name = '';
                    this.newQuestion.text = '';
                    this.newQuestion.answers = [{ text: '', fraction: 1.0 }, { text: '', fraction: 0.0 }];
                } else {
                    alert('Error: ' + (response.data.message || 'Desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('Connection Error');
            } finally {
                this.saving = false;
            }
        },
        removeQuestion(q) {
            confirm('¿Eliminar pregunta del cuestionario? (No se borra del banco)');
        },
        updateOrder() {
            // Reorder logic
        }
    }
};

if (typeof window !== 'undefined') {
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
